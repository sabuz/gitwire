import { useState, useEffect, useCallback } from '@wordpress/element';
import { Spinner } from '@wordpress/components';
import SettingsPanel  from './components/SettingsPanel';
import BrowsePanel    from './components/BrowsePanel';
import InstalledPanel from './components/InstalledPanel';
import * as api from './api';

const TABS = [
	{ name: 'installed', label: 'Installed' },
	{ name: 'browse',    label: 'Browse GitHub' },
	{ name: 'settings',  label: 'Settings' },
];

// Keep the WP sidebar submenu .current class in sync with the active tab.
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
			// ignore malformed hrefs
		}
	} );
}

// Sync active tab with the URL so the WP sidebar submenu stays highlighted.
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

export default function App( { initialData } ) {
	const [ settings,   setSettings   ] = useState( initialData.settings   || null );
	const [ connection, setConnection ] = useState( initialData.connection  || null );
	const [ installed,  setInstalled  ] = useState( initialData.installed   || {} );
	const [ loading,    setLoading    ] = useState( ! initialData.settings );
	const [ activeTab,  setActiveTab  ] = useState( initialData.initial_tab || 'installed' );

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
	}, [] );

	// Intercept WP sidebar submenu clicks so tab switches stay client-side.
	useEffect( () => {
		const submenu = document.querySelector( '#toplevel_page_ghwp .wp-submenu' );
		if ( ! submenu ) {
			return;
		}
		const PATH_TO_TAB = { '': 'installed', browse: 'browse', settings: 'settings' };
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
				// ignore malformed hrefs
			}
		}
		submenu.addEventListener( 'click', handleClick );
		return () => submenu.removeEventListener( 'click', handleClick );
	}, [] );

	useEffect( () => {
		if ( ! initialData.settings ) {
			Promise.all( [ api.getSettings(), api.getInstalled() ] )
				.then( ( [ s, i ] ) => { setSettings( s ); setInstalled( i ); } )
				.finally( () => setLoading( false ) );
		}
	}, [] ); // eslint-disable-line

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
				<div style={ { padding: 48, textAlign: 'center' } }><Spinner /></div>
			</div>
		);
	}

	const installedCount = Object.keys( installed ).length;

	return (
		<div className="ghwp-page">
			<div className="ghwp-page-header">
				<h1 className="ghwp-page-title">GitHub for WordPress</h1>
			</div>

			<nav className="ghwp-page-nav" aria-label="Plugin navigation">
				{ TABS.map( ( tab ) => {
					const label = tab.name === 'installed' && installedCount > 0
						? `Installed (${ installedCount })`
						: tab.label;
					return (
						<button
							key={ tab.name }
							className={ `ghwp-nav-tab${ activeTab === tab.name ? ' is-active' : '' }` }
							onClick={ () => goToTab( tab.name ) }
							aria-selected={ activeTab === tab.name }
						>
							{ label }
						</button>
					);
				} ) }
			</nav>

			<div className="ghwp-page-content">
				{ activeTab === 'settings' && (
					<SettingsPanel
						settings={ settings }
						connection={ connection }
						onSave={ ( s ) => setSettings( s ) }
						onConnectionUpdate={ ( c ) => setConnection( c ) }
					/>
				) }
				{ activeTab === 'browse' && (
					<BrowsePanel
						settings={ settings }
						installed={ installed }
						onInstalled={ refreshInstalled }
					/>
				) }
				{ activeTab === 'installed' && (
					<InstalledPanel
						installed={ installed }
						settings={ settings }
						onRefresh={ refreshInstalled }
						onGoToSettings={ () => goToTab( 'settings' ) }
						onGoToBrowse={ () => goToTab( 'browse' ) }
					/>
				) }
			</div>
		</div>
	);
}
