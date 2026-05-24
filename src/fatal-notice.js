import { toast } from 'sonner';

import { __, sprintf } from '@wordpress/i18n';

/**
 * @param {Object} notice Fatal install/update notice from PHP.
 */
export function showFatalNotice( notice ) {
	const name = notice.full_name || __( 'Unknown', 'git' );
	let message;

	if ( notice.context === 'update' ) {
		if ( notice.type === 'theme' ) {
			message = sprintf(
				/* translators: %s: theme full name */
				__(
					'%s could not be updated. It triggered a fatal error. Your previous version has been restored.',
					'git'
				),
				name
			);
		} else {
			message = sprintf(
				/* translators: %s: plugin full name */
				__(
					'%s could not be updated. It triggered a fatal error. Your previous version has been restored. It has been deactivated.',
					'git'
				),
				name
			);
		}
	} else if ( notice.context === 'activation' ) {
		if ( notice.type === 'theme' ) {
			message = sprintf(
				/* translators: %s: theme full name */
				__(
					'%s could not be activated. It triggered a fatal error. Your previous theme has been restored.',
					'git'
				),
				name
			);
		} else {
			message = sprintf(
				/* translators: %s: plugin full name */
				__(
					'%s could not be activated. It triggered a fatal error. It has been deactivated.',
					'git'
				),
				name
			);
		}
	} else if ( notice.restored ) {
		message = sprintf(
			/* translators: %s: plugin or theme full name */
			__(
				'A fatal PHP error was detected after updating %s. The previous version has been restored. The plugin was deactivated.',
				'git'
			),
			name
		);
	} else {
		message = sprintf(
			/* translators: %s: plugin or theme full name */
			__(
				'A fatal PHP error was detected after installing %s. The broken files have been removed.',
				'git'
			),
			name
		);
	}

	toast.error( message, {
		duration: Infinity,
		action: {
			label: __( 'Dismiss', 'git' ),
			onClick: () => {},
		},
	} );
}
