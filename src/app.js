import { toast, Toaster } from './toast';

import { __, sprintf } from '@wordpress/i18n';
import {
	useState,
	useEffect,
	useCallback,
	lazy,
	Suspense,
} from '@wordpress/element';
import { Spinner } from '@wordpress/components';

import * as api from './api';
import { showFatalNotice } from './fatal-notice';
import {
	showPendingToast,
	queuePendingToast,
	clearPendingToast,
} from './pending-toast';
import SettingsPanel from './components/settings-panel';

const AddRepositoryPanel = lazy( () =>
	import( './components/add-repository-panel' )
);
const InstalledPanel = lazy( () => import( './components/installed-panel' ) );

const TABS = [
	{ name: 'repositories', label: __( 'Repositories', 'gitwire' ) },
	{ name: 'add-repository', label: __( 'Add Repository', 'gitwire' ) },
	{ name: 'settings', label: __( 'Settings', 'gitwire' ) },
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
	const submenu = document.querySelector( '#toplevel_page_git .wp-submenu' );
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
	const [ connection, setConnection ] = useState(
		initialData.connection || {
			github: null,
			gitlab: null,
			bitbucket: null,
		}
	);
	const [ installed, setInstalled ] = useState( initialData.installed || {} );
	const [ loading, setLoading ] = useState( ! initialData.settings );
	const [ activeTab, setActiveTab ] = useState(
		initialData.initial_tab || 'repositories'
	);

	useEffect( () => {
		if ( activeTab !== 'repositories' ) {
			return;
		}
		if ( initialData.fatal_notice ) {
			return;
		}

		if ( initialData.update_success?.full_name ) {
			clearPendingToast();
			toast.success(
				sprintf(
					/* translators: %s: repository full name */
					__( '%s updated to latest.', 'gitwire' ),
					initialData.update_success.full_name
				)
			);
			return;
		}

		if ( initialData.activation_success?.full_name ) {
			clearPendingToast();
			toast.success(
				sprintf(
					/* translators: %s: repository full name */
					__( '%s activated.', 'gitwire' ),
					initialData.activation_success.full_name
				)
			);
			return;
		}

		showPendingToast( toast );
	}, [ activeTab ] ); // eslint-disable-line react-hooks/exhaustive-deps

	useEffect( () => {
		if ( initialData.fatal_notice ) {
			clearPendingToast();
			showFatalNotice( initialData.fatal_notice );
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
			'#toplevel_page_git .wp-submenu'
		);
		if ( ! submenu ) {
			return;
		}
		const PATH_TO_TAB = {
			'': 'repositories',
			'add-repository': 'add-repository',
			browse: 'add-repository', // back-compat
			settings: 'settings',
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
				.finally( () => setLoading( false ) );
		}
	}, [] ); // eslint-disable-line react-hooks/exhaustive-deps

	const applyInstalled = useCallback( ( result ) => {
		setInstalled( result.installed || {} );
		( result.orphaned || [] ).forEach( showOrphanedNotice );
	}, [] );

	const refreshInstalled = useCallback( async () => {
		const result = await api.syncInstalled();
		applyInstalled( result );
	}, [ applyInstalled ] );

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
						result.slug
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

	const handleConnectionUpdate = useCallback( ( provider, data ) => {
		setConnection( ( prev ) => ( {
			...( prev || { github: null, gitlab: null, bitbucket: null } ),
			[ provider ]: data,
		} ) );
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
	const panelFallback = (
		<div className="gitwire-page-loading">
			<Spinner />
		</div>
	);

	return (
		<div className="gitwire-page">
			<Toaster />
			<div className="gitwire-page-header">
				<h1 className="gitwire-page-title">
					{ initialData.icon_url && (
						<img
							alt=""
							aria-hidden="true"
							className="gitwire-page-title__icon"
							src={ initialData.icon_url }
						/>
					) }
					{ __( 'Gitwire', 'gitwire' ) }
				</h1>

				<nav
					aria-label={ __( 'Plugin navigation', 'gitwire' ) }
					className="gitwire-page-nav"
				>
					{ TABS.map( ( tab ) => (
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
						connection={ connection }
						settings={ settings }
						onConnectionUpdate={ handleConnectionUpdate }
						onSave={ handleSettingsSave }
					/>
				) }
				{ activeTab === 'repositories' && (
					<Suspense fallback={ panelFallback }>
						<InstalledPanel
							installed={ installed }
							settings={ settings }
							onGoToSettings={ () => handleGoToTab( 'settings' ) }
							onOpenAddRepo={ () =>
								handleGoToTab( 'add-repository' )
							}
							onRefresh={ refreshInstalled }
						/>
					</Suspense>
				) }
				{ activeTab === 'add-repository' && (
					<Suspense fallback={ panelFallback }>
						<AddRepositoryPanel
							connection={ connection }
							installed={ installed }
							settings={ settings }
							onGoToSettings={ () => handleGoToTab( 'settings' ) }
							onPostInstall={ handlePostInstall }
						/>
					</Suspense>
				) }
			</div>
		</div>
	);
}
