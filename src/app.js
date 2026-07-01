import { toast, Toaster } from './toast';

import { __, sprintf } from '@wordpress/i18n';
import {
	useState,
	useEffect,
	useCallback,
	useMemo,
	Component,
	lazy,
	Suspense,
} from '@wordpress/element';
import { Button, Spinner } from '@wordpress/components';
import { applyFilters, addAction, removeAction } from '@wordpress/hooks';

import * as api from './api';
import { showFatalNotice } from './fatal-notice';
import {
	showPendingToast,
	queuePendingToast,
	clearPendingToast,
} from './pending-toast';
import SettingsPanel from './components/settings-panel';
import AddRepositoryPanel from './components/add-repository-panel';
import InstalledPanel from './components/installed-panel';
import ExternalLinkIcon from './components/external-link-icon';

const LogsPanel = lazy( () => import( './components/logs-panel' ) );
const ToolsPanel = lazy( () => import( './components/tools-panel' ) );

class ChunkErrorBoundary extends Component {
	constructor( props ) {
		super( props );
		this.state = { failed: false };
	}
	static getDerivedStateFromError( error ) {
		if ( error.name === 'ChunkLoadError' ) {
			return { failed: true };
		}
		return null;
	}
	render() {
		if ( this.state.failed ) {
			return (
				<div style={ { padding: '24px', textAlign: 'center' } }>
					<p style={ { marginBottom: 12 } }>
						{ __(
							'A resource failed to load. Please reload the page.',
							'gitwire'
						) }
					</p>
					<Button
						variant="primary"
						onClick={ () => window.location.reload() }
					>
						{ __( 'Reload', 'gitwire' ) }
					</Button>
				</div>
			);
		}
		return this.props.children;
	}
}

const BASE_TABS = [
	{ name: 'repositories', label: __( 'Repositories', 'gitwire' ) },
	{ name: 'add-repository', label: __( 'Add Repository', 'gitwire' ) },
	{ name: 'settings', label: __( 'Settings', 'gitwire' ) },
	{ name: 'tools', label: __( 'Tools', 'gitwire' ) },
	{ name: 'logs', label: __( 'Logs', 'gitwire' ) },
];

/**
 * @param {Object} item Orphaned repository record from sync.
 */
function showOrphanedNotice( item ) {
	toast.warning(
		sprintf(
			/* translators: %s: repository full name */
			__(
				'"%s" was removed from tracking. Its directory no longer exists.',
				'gitwire'
			),
			item.full_name
		)
	);
}

function tabUrl( tabName ) {
	const url = new URL( window.location.href );
	url.searchParams.set( 'page', 'gitwire' );
	if ( tabName === 'repositories' ) {
		url.searchParams.delete( 'path' );
	} else {
		url.searchParams.set( 'path', tabName );
	}
	return url.toString();
}

function updateSidebarActive( tabName ) {
	const submenu = document.querySelector(
		'#toplevel_page_gitwire .wp-submenu'
	);
	if ( ! submenu ) {
		return;
	}
	const expectedPath = tabName === 'repositories' ? '' : tabName;
	submenu.querySelectorAll( 'li' ).forEach( ( li ) => {
		const a = li.querySelector( 'a' );
		if ( ! a ) {
			return;
		}
		try {
			const params = new URL( a.href ).searchParams;
			const isActive = ( params.get( 'path' ) || '' ) === expectedPath;
			li.classList.toggle( 'current', isActive );
			a.classList.toggle( 'current', isActive );
		} catch ( _ ) {
			// Ignore malformed hrefs.
		}
	} );
}

function syncUrl( tabName ) {
	const url = new URL( window.location.href );
	url.searchParams.set( 'page', 'gitwire' );
	if ( tabName === 'repositories' ) {
		url.searchParams.delete( 'path' );
	} else {
		url.searchParams.set( 'path', tabName );
	}
	history.replaceState( null, '', url.toString() );
	updateSidebarActive( tabName );
}

export default function App( { initialData } ) {
	const [ settings, setSettings ] = useState( initialData.settings || null );
	const [ installed, setInstalled ] = useState( initialData.installed || {} );
	const [ loading, setLoading ] = useState( ! initialData.settings );
	const [ activeTab, setActiveTab ] = useState(
		initialData.initial_tab || 'repositories'
	);
	const [ publicConnections, setPublicConnections ] = useState(
		initialData.public_connections || []
	);
	const [ sourcesVersion, setSourcesVersion ] = useState( 0 );

	// Free supplies public connections as the base; Pro merges its private
	// connections on top via the filter. sourcesVersion triggers a recompute
	// when Pro fires gitwire.sourcesChanged after its own connection changes.
	const connections = useMemo(
		() =>
			applyFilters(
				'gitwire.browse.sources',
				publicConnections,
				settings
			),
		[ publicConnections, settings, sourcesVersion ] // eslint-disable-line react-hooks/exhaustive-deps
	);

	useEffect( () => {
		if ( activeTab !== 'repositories' ) {
			return;
		}
		if ( initialData.pending_msg?.type === 'fatal' ) {
			return;
		}

		showPendingToast( toast );
	}, [ activeTab ] ); // eslint-disable-line react-hooks/exhaustive-deps

	useEffect( () => {
		if ( initialData.pending_msg?.type === 'fatal' ) {
			clearPendingToast();
			showFatalNotice( initialData.pending_msg.data );
		}
		( initialData.orphaned || [] ).forEach( showOrphanedNotice );
	}, [] ); // eslint-disable-line react-hooks/exhaustive-deps

	useEffect( () => {
		const tab = sessionStorage.getItem( 'gitwire_goto_tab' );
		if ( tab ) {
			sessionStorage.removeItem( 'gitwire_goto_tab' );
			// Migrate old tab names from previous sessions.
			const legacyMap = {
				installed: 'repositories',
				browse: 'add-repository',
			};
			const resolved = legacyMap[ tab ] ?? tab;
			setActiveTab( resolved );
			syncUrl( resolved );
		} else {
			updateSidebarActive( initialData.initial_tab || 'repositories' );
		}
	}, [] ); // eslint-disable-line react-hooks/exhaustive-deps

	useEffect( () => {
		const submenu = document.querySelector(
			'#toplevel_page_gitwire .wp-submenu'
		);
		if ( ! submenu ) {
			return;
		}
		const PATH_TO_TAB = {
			'': 'repositories',
			'add-repository': 'add-repository',
			browse: 'add-repository', // back-compat
			settings: 'settings',
			logs: 'logs',
			tools: 'tools',
		};
		function handleClick( ev ) {
			const a = ev.target.closest( 'a' );
			if ( ! a ) {
				return;
			}
			try {
				const params = new URL( a.href ).searchParams;
				if ( params.get( 'page' ) !== 'gitwire' ) {
					return;
				}
				const tab = PATH_TO_TAB[ params.get( 'path' ) || '' ];
				if ( tab === undefined ) {
					return;
				}
				ev.preventDefault();
				ev.stopPropagation();
				setActiveTab( tab );
				syncUrl( tab );
			} catch ( _ ) {
				// Ignore malformed hrefs.
			}
		}
		submenu.addEventListener( 'click', handleClick );
		return () => submenu.removeEventListener( 'click', handleClick );
	}, [] );

	useEffect( () => {
		if ( ! initialData.settings ) {
			Promise.all( [ api.getSettings(), api.syncInstalled() ] )
				.then( ( [ s, result ] ) => {
					setSettings( s );
					applyInstalled( result );
				} )
				.catch( ( e ) => {
					toast.error(
						e?.message ||
							__(
								'Failed to load. Please reload the page.',
								'gitwire'
							)
					);
					setSettings( {} );
				} )
				.finally( () => setLoading( false ) );
			return;
		}
		api.syncInstalled().then( applyInstalled );
	}, [] ); // eslint-disable-line react-hooks/exhaustive-deps

	const applyInstalled = useCallback( ( result ) => {
		setInstalled( result.installed || {} );
		( result.orphaned || [] ).forEach( showOrphanedNotice );
	}, [] );

	const refreshInstalled = useCallback( async () => {
		const result = await api.syncInstalled();
		applyInstalled( result );
	}, [ applyInstalled ] );

	useEffect( () => {
		const handleSourcesChanged = () => {
			setSourcesVersion( ( v ) => v + 1 );
			refreshInstalled();
		};
		addAction(
			'gitwire.sourcesChanged',
			'gitwire/app',
			handleSourcesChanged
		);
		return () => removeAction( 'gitwire.sourcesChanged', 'gitwire/app' );
	}, [ refreshInstalled ] );

	const handleGoToTab = useCallback( ( tabName ) => {
		setActiveTab( tabName );
		syncUrl( tabName );
	}, [] );

	const handlePostInstall = useCallback(
		( result, repoFullName ) => {
			if ( result.slug_renamed ) {
				queuePendingToast( {
					message: sprintf(
						/* translators: %s: renamed directory slug */
						__(
							'Installed as "%s" to avoid a directory conflict with an existing installation.',
							'gitwire'
						),
						result.name
					),
					variant: 'warning',
				} );
			} else {
				queuePendingToast( {
					message: sprintf(
						/* translators: %s: repository full name */
						__( '%s installed successfully.', 'gitwire' ),
						repoFullName
					),
					variant: 'success',
				} );
			}
			handleGoToTab( 'repositories' );
			refreshInstalled();
		},
		[ handleGoToTab, refreshInstalled ]
	);

	const handleTabClick = useCallback( ( ev, tabName ) => {
		ev.preventDefault();
		setActiveTab( tabName );
		syncUrl( tabName );
	}, [] );

	const handleSettingsSave = useCallback( ( s ) => {
		setSettings( s );
	}, [] );

	if ( loading || ! settings ) {
		return (
			<div className="gitwire-page">
				<div className="gitwire-page-loading">
					<Spinner />
				</div>
			</div>
		);
	}

	const installedCount = Object.keys( installed ).length;
	const tabs = settings?.enable_logging
		? BASE_TABS
		: BASE_TABS.filter( ( t ) => t.name !== 'logs' );
	// Pro registers this filter to add its own header dropdown; its presence means Pro is active.
	const headerActions = applyFilters( 'gitwire.header.actions', null );
	const panelFallback = (
		<div className="gitwire-page-loading">
			<Spinner />
		</div>
	);

	return (
		<div className="gitwire-page">
			<Toaster />
			<div className="gitwire-page-header">
				<div className="gitwire-page-header__top">
					<h1 className="gitwire-page-title">
						{ __( 'Gitwire', 'gitwire' ) }
					</h1>
					<div
						style={ {
							display: 'flex',
							alignItems: 'center',
							gap: 16,
						} }
					>
						{ ! headerActions && (
							<Button
								href="https://gitwire.app/docs"
								rel="noreferrer"
								target="_blank"
								variant="link"
							>
								{ __( 'Docs', 'gitwire' ) }
								<ExternalLinkIcon />
							</Button>
						) }
						{ headerActions }
					</div>
				</div>

				<nav
					aria-label={ __( 'Plugin navigation', 'gitwire' ) }
					className="gitwire-page-nav"
				>
					{ tabs.map( ( tab ) => (
						<a
							key={ tab.name }
							aria-current={
								activeTab === tab.name ? 'page' : undefined
							}
							className={ `gitwire-nav-tab${
								activeTab === tab.name ? ' is-active' : ''
							}` }
							href={ tabUrl( tab.name ) }
							onClick={ ( ev ) => handleTabClick( ev, tab.name ) }
						>
							{ tab.label }
							{ tab.name === 'repositories' &&
								installedCount > 0 && (
									<span className="gitwire-nav-badge">
										{ installedCount }
									</span>
								) }
						</a>
					) ) }
				</nav>
			</div>

			<div className="gitwire-page-content">
				{ activeTab === 'settings' && (
					<SettingsPanel
						publicConnections={ publicConnections }
						settings={ settings }
						onConnectionsChange={ setPublicConnections }
						onSave={ handleSettingsSave }
					/>
				) }
				{ activeTab === 'repositories' && (
					<InstalledPanel
						connections={ connections }
						installed={ installed }
						settings={ settings }
						onGoToSettings={ () => handleGoToTab( 'settings' ) }
						onOpenAddRepo={ () =>
							handleGoToTab( 'add-repository' )
						}
						onRefresh={ refreshInstalled }
					/>
				) }
				{ activeTab === 'add-repository' && (
					<AddRepositoryPanel
						connections={ connections }
						installed={ installed }
						settings={ settings }
						onGoToSettings={ () => handleGoToTab( 'settings' ) }
						onPostInstall={ handlePostInstall }
					/>
				) }
				{ activeTab === 'logs' && (
					<ChunkErrorBoundary>
						<Suspense fallback={ panelFallback }>
							<LogsPanel
								settings={ settings }
								onGoToSettings={ () =>
									handleGoToTab( 'settings' )
								}
							/>
						</Suspense>
					</ChunkErrorBoundary>
				) }
				{ activeTab === 'tools' && (
					<ChunkErrorBoundary>
						<Suspense fallback={ panelFallback }>
							<ToolsPanel
								settings={ settings }
								onSave={ handleSettingsSave }
							/>
						</Suspense>
					</ChunkErrorBoundary>
				) }
			</div>
		</div>
	);
}
