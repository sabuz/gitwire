import { __ } from '@wordpress/i18n';
import { useState, lazy, Suspense } from '@wordpress/element';
import { Button, Spinner } from '@wordpress/components';

import ImportFromUrl from './import-from-url';

const BrowsePanel = lazy( () => import( './browse-panel' ) );

function hasAnyConnection( connection ) {
	return !! (
		connection?.github?.authenticated ||
		connection?.gitlab?.authenticated ||
		connection?.bitbucket?.authenticated
	);
}

/**
 * Full-width Add repository panel with Browse and Import from URL sub-tabs.
 *
 * @param {Object}   props                Component props.
 * @param {Object}   props.installed      Map of installed repositories.
 * @param {Object}   props.settings       Plugin settings.
 * @param {Object}   props.connection     Live connection state per provider.
 * @param {string}   props.initialSubTab  'browse' | 'url' — overrides session storage on open.
 * @param {Function} props.onClose        Closes the panel (returns to Installed).
 * @param {Function} props.onPostInstall  Called after a successful install.
 * @param {Function} props.onGoToSettings Navigates to Settings tab.
 * @return {JSX.Element} The rendered panel.
 */
export default function AddRepositoryPanel( {
	installed,
	settings,
	connection,
	initialSubTab,
	onClose,
	onPostInstall,
	onGoToSettings,
} ) {
	const [ subTab, setSubTab ] = useState( () => {
		if ( initialSubTab === 'browse' || initialSubTab === 'url' ) {
			return initialSubTab;
		}
		const saved = sessionStorage.getItem( 'gitwire_add_repo_sub_tab' );
		if ( saved === 'browse' || saved === 'url' ) {
			return saved;
		}
		return hasAnyConnection( connection ) ? 'browse' : 'url';
	} );

	const handleSubTab = ( tab ) => {
		setSubTab( tab );
		sessionStorage.setItem( 'gitwire_add_repo_sub_tab', tab );
	};

	const panelFallback = (
		<div className="gitwire-page-loading">
			<Spinner />
		</div>
	);

	return (
		<div className="gitwire-add-repo-panel">
			<div className="gitwire-add-repo-panel__header">
				<Button
					icon="arrow-left-alt2"
					variant="tertiary"
					onClick={ onClose }
				>
					{ __( 'Installed', 'gitwire' ) }
				</Button>

				<h2 className="gitwire-add-repo-panel__title">
					{ __( 'Add repository', 'gitwire' ) }
				</h2>

				<nav
					aria-label={ __( 'Add repository options', 'gitwire' ) }
					className="gitwire-add-repo-panel__sub-nav"
				>
					<button
						aria-current={ subTab === 'url' ? 'page' : undefined }
						className={ `gitwire-add-repo-tab${
							subTab === 'url' ? ' is-active' : ''
						}` }
						onClick={ () => handleSubTab( 'url' ) }
					>
						{ __( 'Import from URL', 'gitwire' ) }
					</button>
					<button
						aria-current={
							subTab === 'browse' ? 'page' : undefined
						}
						className={ `gitwire-add-repo-tab${
							subTab === 'browse' ? ' is-active' : ''
						}` }
						onClick={ () => handleSubTab( 'browse' ) }
					>
						{ __( 'Browse my repositories', 'gitwire' ) }
					</button>
				</nav>
			</div>

			<div className="gitwire-add-repo-panel__content">
				{ subTab === 'url' && (
					<ImportFromUrl
						connection={ connection }
						installed={ installed }
						settings={ settings }
						onGoToSettings={ onGoToSettings }
						onPostInstall={ onPostInstall }
					/>
				) }
				{ subTab === 'browse' && (
					<Suspense fallback={ panelFallback }>
						<BrowsePanel
							installed={ installed }
							settings={ settings }
							onPostInstall={ onPostInstall }
						/>
					</Suspense>
				) }
			</div>
		</div>
	);
}
