import { __ } from '@wordpress/i18n';
import { useState, lazy, Suspense } from '@wordpress/element';
import { Spinner } from '@wordpress/components';

import ImportFromUrl from './import-from-url';

const BrowsePanel = lazy( () => import( './browse-panel' ) );

function hasAnyConnection( settings ) {
	return !! (
		settings?.token_set ||
		settings?.username ||
		settings?.gitlab_token_set ||
		settings?.bitbucket_api_token_set
	);
}

/**
 * Add repository page — Browse and Import from URL sub-tabs.
 *
 * @param {Object}   props                Component props.
 * @param {Object}   props.installed      Map of installed repositories.
 * @param {Object}   props.settings       Plugin settings.
 * @param {Object}   props.connection     Live connection state per provider.
 * @param {Function} props.onPostInstall  Called after a successful install.
 * @param {Function} props.onGoToSettings Navigates to the Settings tab.
 * @return {JSX.Element} The rendered page.
 */
export default function AddRepositoryPanel( {
	installed,
	settings,
	connection,
	onPostInstall,
	onGoToSettings,
} ) {
	const [ subTab, setSubTab ] = useState(
		hasAnyConnection( settings ) ? 'browse' : 'url'
	);

	const panelFallback = (
		<div className="gitwire-page-loading">
			<Spinner />
		</div>
	);

	return (
		<div className="gitwire-add-repo-page">
			<nav
				aria-label={ __( 'Add repository options', 'gitwire' ) }
				className="gitwire-add-repo-page__sub-nav"
			>
				<button
					aria-current={ subTab === 'browse' ? 'page' : undefined }
					className={ `gitwire-add-repo-tab${
						subTab === 'browse' ? ' is-active' : ''
					}` }
					onClick={ () => setSubTab( 'browse' ) }
				>
					{ __( 'Browse My Repositories', 'gitwire' ) }
				</button>
				<button
					aria-current={ subTab === 'url' ? 'page' : undefined }
					className={ `gitwire-add-repo-tab${
						subTab === 'url' ? ' is-active' : ''
					}` }
					onClick={ () => setSubTab( 'url' ) }
				>
					{ __( 'Import from URL', 'gitwire' ) }
				</button>
			</nav>

			<div className="gitwire-add-repo-page__content">
				{ subTab === 'url' && (
					<ImportFromUrl
						connection={ connection }
						installed={ installed }
						settings={ settings }
						onPostInstall={ onPostInstall }
					/>
				) }
				{ subTab === 'browse' && (
					<Suspense fallback={ panelFallback }>
						<BrowsePanel
							installed={ installed }
							settings={ settings }
							onGoToSettings={ onGoToSettings }
							onPostInstall={ onPostInstall }
						/>
					</Suspense>
				) }
			</div>
		</div>
	);
}
