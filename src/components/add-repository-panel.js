import { __ } from '@wordpress/i18n';
import { useState, lazy, Suspense } from '@wordpress/element';
import { Modal, Spinner } from '@wordpress/components';

import ImportFromUrl from './import-from-url';

const RepositoryBrowser = lazy( () => import( './repository-browser' ) );

/**
 * Add Repository tab with a repository browser and Import from URL action.
 *
 * @param {Object}   props                Component props.
 * @param {Array}    props.connections    Browse source records array.
 * @param {Object}   props.installed      Map of installed repositories.
 * @param {Object}   props.settings       Plugin settings.
 * @param {Function} props.onPostInstall  Called after a successful install.
 * @param {Function} props.onGoToSettings Navigates to the Settings tab.
 * @return {JSX.Element} The rendered page.
 */
export default function AddRepositoryPanel( {
	connections,
	installed,
	settings,
	onPostInstall,
	onGoToSettings,
} ) {
	const [ urlImportOpen, setUrlImportOpen ] = useState( false );

	const handleUrlImportInstall = ( result, repoFullName ) => {
		setUrlImportOpen( false );
		onPostInstall( result, repoFullName );
	};

	const panelFallback = (
		<div className="gitwire-page-loading">
			<Spinner />
		</div>
	);

	return (
		<div className="gitwire-add-repo-page">
			<Suspense fallback={ panelFallback }>
				<RepositoryBrowser
					connections={ connections }
					installed={ installed }
					settings={ settings }
					onGoToSettings={ onGoToSettings }
					onOpenUrlImport={ () => setUrlImportOpen( true ) }
					onPostInstall={ onPostInstall }
				/>
			</Suspense>

			{ urlImportOpen && (
				<Modal
					className="gitwire-modal"
					style={ { width: 520 } }
					title={ __( 'Import from URL', 'gitwire' ) }
					onRequestClose={ () => setUrlImportOpen( false ) }
				>
					<ImportFromUrl
						installed={ installed }
						settings={ settings }
						onGoToSettings={ onGoToSettings }
						onPostInstall={ handleUrlImportInstall }
					/>
				</Modal>
			) }
		</div>
	);
}
