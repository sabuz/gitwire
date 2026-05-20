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

// Sync active tab with the URL so the WP sidebar submenu stays highlighted.
function syncUrl( tabName ) {
	const url = new URL( window.location.href );
	if ( tabName === 'installed' ) {
		url.searchParams.delete( 'path' );
	} else {
		url.searchParams.set( 'path', tabName );
	}
	history.replaceState( null, '', url.toString() );
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
		}
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
