import { toast, Toaster } from 'sonner';

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

const BrowsePanel = lazy( () => import( './components/browse-panel' ) );
const InstalledPanel = lazy( () => import( './components/installed-panel' ) );

const TABS = [
	{ name: 'installed', label: __( 'Installed', 'git' ) },
	{ name: 'browse', label: __( 'Browse', 'git' ) },
	{ name: 'settings', label: __( 'Settings', 'git' ) },
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
				'git'
			),
			item.full_name
		),
		{
			duration: Infinity,
			action: {
				label: __( 'Dismiss', 'git' ),
				onClick: () => {},
			},
		}
	);
}

function tabUrl( tabName ) {
	const url = new URL( window.location.href );
	url.searchParams.set( 'page', 'git' );
	if ( tabName === 'installed' ) {
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
	const expectedPath = tabName === 'installed' ? '' : tabName;
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
	url.searchParams.set( 'page', 'git' );
	if ( tabName === 'installed' ) {
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
		initialData.connection || { github: null, gitlab: null }
	);
	const [ installed, setInstalled ] = useState( initialData.installed || {} );
	const [ loading, setLoading ] = useState( ! initialData.settings );
	const [ activeTab, setActiveTab ] = useState(
		initialData.initial_tab || 'installed'
	);

	useEffect( () => {
		if ( activeTab !== 'installed' ) {
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
					__( '%s updated to latest.', 'git' ),
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
					__( '%s activated.', 'git' ),
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
		const tab = sessionStorage.getItem( 'gwp_goto_tab' );
		if ( tab ) {
			sessionStorage.removeItem( 'gwp_goto_tab' );
			setActiveTab( tab );
			syncUrl( tab );
		} else {
			updateSidebarActive( initialData.initial_tab || 'installed' );
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
			'': 'installed',
			browse: 'browse',
			settings: 'settings',
		};
		function handleClick( ev ) {
			const a = ev.target.closest( 'a' );
			if ( ! a ) {
				return;
			}
			try {
				const params = new URL( a.href ).searchParams;
				if ( params.get( 'page' ) !== 'git' ) {
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
							'git'
						),
						result.slug
					),
					variant: 'warning',
					duration: 8000,
				} );
			} else {
				queuePendingToast( {
					message: sprintf(
						/* translators: %s: repository full name */
						__( '%s installed successfully.', 'git' ),
						repoFullName
					),
					variant: 'success',
				} );
			}
			handleGoToTab( 'installed' );
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
			...( prev || { github: null, gitlab: null } ),
			[ provider ]: data,
		} ) );
	}, [] );

	const handleSettingsSave = useCallback( ( s ) => {
		setSettings( s );
	}, [] );

	if ( loading || ! settings ) {
		return (
			<div className="gwp-page">
				<div className="gwp-page-loading">
					<Spinner />
				</div>
			</div>
		);
	}

	const installedCount = Object.keys( installed ).length;
	const panelFallback = (
		<div className="gwp-page-loading">
			<Spinner />
		</div>
	);

	return (
		<div className="gwp-page">
			<Toaster richColors position="top-right" />
			<div className="gwp-page-header">
				<h1 className="gwp-page-title">
					{ initialData.icon_url && (
						<img
							alt=""
							aria-hidden="true"
							className="gwp-page-title__icon"
							src={ initialData.icon_url }
						/>
					) }
					{ __( 'Git', 'git' ) }
				</h1>

				<nav
					aria-label={ __( 'Plugin navigation', 'git' ) }
					className="gwp-page-nav"
				>
					{ TABS.map( ( tab ) => (
						<a
							key={ tab.name }
							aria-current={
								activeTab === tab.name ? 'page' : undefined
							}
							className={ `gwp-nav-tab${
								activeTab === tab.name ? ' is-active' : ''
							}` }
							href={ tabUrl( tab.name ) }
							onClick={ ( ev ) => handleTabClick( ev, tab.name ) }
						>
							{ tab.label }
							{ tab.name === 'installed' &&
								installedCount > 0 && (
									<span className="gwp-nav-badge">
										{ installedCount }
									</span>
								) }
						</a>
					) ) }
				</nav>
			</div>

			<div className="gwp-page-content">
				{ activeTab === 'settings' && (
					<SettingsPanel
						connection={ connection }
						settings={ settings }
						onConnectionUpdate={ handleConnectionUpdate }
						onSave={ handleSettingsSave }
					/>
				) }
				{ activeTab === 'browse' && (
					<Suspense fallback={ panelFallback }>
						<BrowsePanel
							installed={ installed }
							settings={ settings }
							onGoToSettings={ () => handleGoToTab( 'settings' ) }
							onPostInstall={ handlePostInstall }
						/>
					</Suspense>
				) }
				{ activeTab === 'installed' && (
					<Suspense fallback={ panelFallback }>
						<InstalledPanel
							installed={ installed }
							settings={ settings }
							onGoToBrowse={ () => handleGoToTab( 'browse' ) }
							onGoToSettings={ () => handleGoToTab( 'settings' ) }
							onRefresh={ refreshInstalled }
						/>
					</Suspense>
				) }
			</div>
		</div>
	);
}
