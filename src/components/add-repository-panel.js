import { __ } from '@wordpress/i18n';
import { useState, lazy, Suspense } from '@wordpress/element';
import { Modal, Spinner } from '@wordpress/components';

import ImportFromUrl from './import-from-url';

const BrowsePanel = lazy( () => import( './browse-panel' ) );

/**
 * Add Repository page — Browse panel with an Import from URL modal action.
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
				<BrowsePanel
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
						connection={ connection }
						installed={ installed }
						settings={ settings }
						onPostInstall={ handleUrlImportInstall }
					/>
				</Modal>
			) }
		</div>
	);
}
