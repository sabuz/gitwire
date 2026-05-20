import { useState, useEffect, useCallback } from '@wordpress/element';
import { TabPanel, Spinner } from '@wordpress/components';
import SettingsPanel  from './components/SettingsPanel';
import BrowsePanel    from './components/BrowsePanel';
import InstalledPanel from './components/InstalledPanel';
import * as api from './api';

export default function App( { initialData } ) {
	const [ settings,    setSettings    ] = useState( initialData.settings   || null );
	const [ connection,  setConnection  ] = useState( initialData.connection || null );
	const [ installed,   setInstalled   ] = useState( initialData.installed  || {} );
	const [ loading,     setLoading     ] = useState( ! initialData.settings );
	const [ initialTab,  setInitialTab  ] = useState( 'settings' );

	// Post-install redirect via sessionStorage
	useEffect( () => {
		const tab = sessionStorage.getItem( 'ghwp_goto_tab' );
		if ( tab ) {
			sessionStorage.removeItem( 'ghwp_goto_tab' );
			setInitialTab( tab );
		}
	}, [] );

	// Fetch initial data if not server-rendered
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

	if ( loading || ! settings ) {
		return <div style={ { padding: 32, textAlign: 'center' } }><Spinner /></div>;
	}

	const installedCount = Object.keys( installed ).length;

	const tabs = [
		{ name: 'settings',  title: 'Settings' },
		{ name: 'browse',    title: 'Browse GitHub' },
		{
			name:  'installed',
			title: installedCount > 0 ? `Installed (${ installedCount })` : 'Installed',
		},
	];

	return (
		<div className="ghwp-app">
			<h1 className="ghwp-page-title">
				<span className="dashicons dashicons-randomize" />
				GitHub for WordPress
			</h1>

			<TabPanel tabs={ tabs } initialTabName={ initialTab }>
				{ ( tab ) => {
					if ( tab.name === 'settings' ) {
						return (
							<SettingsPanel
								settings={ settings }
								connection={ connection }
								onSave={ ( s ) => setSettings( s ) }
								onConnectionUpdate={ ( c ) => setConnection( c ) }
							/>
						);
					}
					if ( tab.name === 'browse' ) {
						return (
							<BrowsePanel
								settings={ settings }
								installed={ installed }
								onInstalled={ refreshInstalled }
							/>
						);
					}
					if ( tab.name === 'installed' ) {
						return (
							<InstalledPanel
								installed={ installed }
								onRefresh={ refreshInstalled }
							/>
						);
					}
				} }
			</TabPanel>
		</div>
	);
}
