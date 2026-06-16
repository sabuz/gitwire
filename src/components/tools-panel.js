import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import {
	Card,
	CardBody,
	CardHeader,
	ToggleControl,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalHeading as Heading,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalSpacer as Spacer,
} from '@wordpress/components';

import { persistSetting } from '../save-setting';

/**
 * Tools panel — data management and advanced operations.
 *
 * @param {Object}   props          Component props.
 * @param {Object}   props.settings Saved plugin settings.
 * @param {Function} props.onSave   Called with updated settings after save.
 * @return {JSX.Element} The rendered tools panel.
 */
export default function ToolsPanel( { settings, onSave } ) {
	const [ removeDataOnUninstall, setRemoveDataOnUninstall ] = useState(
		!! settings.remove_data_on_uninstall
	);

	const saveSetting = ( payload, rollback ) =>
		persistSetting( payload, onSave, rollback );

	const handleRemoveDataOnUninstallChange = ( newVal ) => {
		setRemoveDataOnUninstall( newVal );
		saveSetting( { remove_data_on_uninstall: newVal }, () =>
			setRemoveDataOnUninstall( ! newVal )
		).catch( () => {} );
	};

	return (
		<div
			className="gitwire-settings-panels"
			style={ { maxWidth: 580, margin: '0 auto' } }
		>
			<Card>
				<CardHeader>
					<Heading level={ 4 }>{ __( 'Data', 'gitwire' ) }</Heading>
				</CardHeader>
				<CardBody>
					<ToggleControl
						__nextHasNoMarginBottom
						checked={ removeDataOnUninstall }
						help={ __(
							'When enabled, all connections and credentials are permanently deleted when the plugin is uninstalled.',
							'gitwire'
						) }
						label={ __(
							'Remove all data on uninstall',
							'gitwire'
						) }
						onChange={ handleRemoveDataOnUninstallChange }
					/>
				</CardBody>
			</Card>

			<Spacer marginTop={ 4 } />
		</div>
	);
}
