import { Toaster } from 'sonner';

import { __ } from '@wordpress/i18n';
import { useState, useEffect, useCallback } from '@wordpress/element';
import { Spinner } from '@wordpress/components';

import * as api from './api';
import BrowsePanel from './components/browse-panel';
import InstalledPanel from './components/installed-panel';
import SettingsPanel from './components/settings-panel';

const TABS = [
	{ name: 'installed', label: __( 'Installed', 'ghwp' ) },
	{ name: 'browse', label: __( 'Browse GitHub', 'ghwp' ) },
	{ name: 'settings', label: __( 'Settings', 'ghwp' ) },
];

/**
 * Builds the URL for a given tab name.
 *
 * @param {string} tabName Tab identifier.
 * @return {string} Full URL with query parameters.
 */
function tabUrl( tabName ) {
	const url = new URL( window.location.href );
	url.searchParams.set( 'page', 'ghwp' );
	if ( tabName === 'installed' ) {
		url.searchParams.delete( 'path' );
	} else {
		url.searchParams.set( 'path', tabName );
	}
	return url.toString();
}

/**
 * Keeps the WP sidebar submenu .current class in sync with the active tab.
 *
 * @param {string} tabName Active tab identifier.
 */
function updateSidebarActive( tabName ) {
	const submenu = document.querySelector( '#toplevel_page_ghwp .wp-submenu' );
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

/**
 * Syncs the active tab with the URL so the WP sidebar submenu stays highlighted.
 *
 * @param {string} tabName Active tab identifier.
 */
function syncUrl( tabName ) {
	const url = new URL( window.location.href );
	url.searchParams.set( 'page', 'ghwp' );
	if ( tabName === 'installed' ) {
		url.searchParams.delete( 'path' );
	} else {
		url.searchParams.set( 'path', tabName );
	}
	history.replaceState( null, '', url.toString() );
	updateSidebarActive( tabName );
}

/**
 * Root application component.
 *
 * @param {Object} props             Component props.
 * @param {Object} props.initialData Server-side data injected via wp_add_inline_script.
 * @return {JSX.Element} The rendered app.
 */
export default function App( { initialData } ) {
	const [ settings, setSettings ] = useState( initialData.settings || null );
	const [ connection, setConnection ] = useState(
		initialData.connection || null
	);
	const [ installed, setInstalled ] = useState( initialData.installed || {} );
	const [ loading, setLoading ] = useState( ! initialData.settings );
	const [ activeTab, setActiveTab ] = useState(
		initialData.initial_tab || 'installed'
	);

	// Post-install redirect via sessionStorage overrides everything.
	useEffect( () => {
		const tab = sessionStorage.getItem( 'ghwp_goto_tab' );
		if ( tab ) {
			sessionStorage.removeItem( 'ghwp_goto_tab' );
			setActiveTab( tab );
			syncUrl( tab );
		} else {
			updateSidebarActive( initialData.initial_tab || 'installed' );
		}
	}, [] ); // eslint-disable-line react-hooks/exhaustive-deps

	// Intercept WP sidebar submenu clicks so tab switches stay client-side.
	useEffect( () => {
		const submenu = document.querySelector(
			'#toplevel_page_ghwp .wp-submenu'
		);
		if ( ! submenu ) {
			return;
		}
		const PATH_TO_TAB = {
			'': 'installed',
			browse: 'browse',
			settings: 'settings',
		};
		function handleClick( e ) {
			const a = e.target.closest( 'a' );
			if ( ! a ) {
				return;
			}
			try {
				const params = new URL( a.href ).searchParams;
				if ( params.get( 'page' ) !== 'ghwp' ) {
					return;
				}
				const tab = PATH_TO_TAB[ params.get( 'path' ) || '' ];
				if ( tab === undefined ) {
					return;
				}
				e.preventDefault();
				e.stopPropagation();
				setActiveTab( tab );
				syncUrl( tab );
			} catch ( _ ) {
				// Ignore malformed hrefs.
			}
		}
		submenu.addEventListener( 'click', handleClick );
		return () => submenu.removeEventListener( 'click', handleClick );
	}, [] ); // eslint-disable-line react-hooks/exhaustive-deps

	useEffect( () => {
		if ( ! initialData.settings ) {
			Promise.all( [ api.getSettings(), api.getInstalled() ] )
				.then( ( [ s, i ] ) => {
					setSettings( s );
					setInstalled( i );
				} )
				.finally( () => setLoading( false ) );
		}
	}, [] ); // eslint-disable-line react-hooks/exhaustive-deps

	const refreshInstalled = useCallback( async () => {
		const i = await api.getInstalled();
		setInstalled( i );
	}, [] );

	const goToTab = ( tabName ) => {
		setActiveTab( tabName );
		syncUrl( tabName );
	};

	if ( loading || ! settings ) {
		return (
			<div className="ghwp-page">
				<div style={ { padding: 48, textAlign: 'center' } }>
					<Spinner />
				</div>
			</div>
		);
	}

	const installedCount = Object.keys( installed ).length;

	return (
		<div className="ghwp-page">
			<Toaster richColors position="top-right" />
			<div className="ghwp-page-header">
				<h1 className="ghwp-page-title">
					{ __( 'GitHub for WordPress', 'ghwp' ) }
				</h1>

				<nav
					aria-label={ __( 'Plugin navigation', 'ghwp' ) }
					className="ghwp-page-nav"
				>
					{ TABS.map( ( tab ) => (
						<a
							key={ tab.name }
							aria-current={
								activeTab === tab.name ? 'page' : undefined
							}
							className={ `ghwp-nav-tab${
								activeTab === tab.name ? ' is-active' : ''
							}` }
							href={ tabUrl( tab.name ) }
							onClick={ ( e ) => {
								e.preventDefault();
								goToTab( tab.name );
							} }
						>
							{ tab.label }
							{ tab.name === 'installed' &&
								installedCount > 0 && (
									<span className="ghwp-nav-badge">
										{ installedCount }
									</span>
								) }
						</a>
					) ) }
				</nav>
			</div>

			<div className="ghwp-page-content">
				{ activeTab === 'settings' && (
					<SettingsPanel
						connection={ connection }
						settings={ settings }
						onConnectionUpdate={ ( c ) => setConnection( c ) }
						onSave={ ( s ) => setSettings( s ) }
					/>
				) }
				{ activeTab === 'browse' && (
					<BrowsePanel
						installed={ installed }
						settings={ settings }
						onInstalled={ refreshInstalled }
					/>
				) }
				{ activeTab === 'installed' && (
					<InstalledPanel
						installed={ installed }
						settings={ settings }
						onGoToBrowse={ () => goToTab( 'browse' ) }
						onGoToSettings={ () => goToTab( 'settings' ) }
						onRefresh={ refreshInstalled }
					/>
				) }
			</div>
		</div>
	);
}
