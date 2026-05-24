import { toast } from 'sonner';

import { __, sprintf } from '@wordpress/i18n';

import { showFatalNotice } from './fatal-notice';
import { queuePendingToastAndReload } from './pending-toast';
import { verifyThemeActivation } from './verify-theme-activation';

/**
 * @param {Object}   options
 * @param {boolean}  options.needsVerify      Whether the server armed a bootstrap guard.
 * @param {Object}   options.item             Installed repository record.
 * @param {Function} [options.onRefresh]      Refreshes installed data after a failed verify.
 * @param {string}   [options.successMessage] Toast message after a verified reload.
 * @param {Function} [options.onSettled]      Called when polling finishes in any outcome.
 * @return {boolean} True when verification was started.
 */
export function verifyActiveUpdate( {
	needsVerify,
	item,
	onRefresh,
	successMessage,
	onSettled,
} ) {
	const verifyUrl = window.GWP?.verify_activation_url;
	if ( ! needsVerify || ! verifyUrl ) {
		return false;
	}

	verifyThemeActivation( {
		verifyUrl,
		onSuccess: ( result ) => {
			queuePendingToastAndReload(
				successMessage ||
					sprintf(
						/* translators: %s: repository full name */
						__( '%s updated to latest.', 'git' ),
						result.full_name || item.full_name
					)
			);
		},
		onFatal: ( notice ) => {
			showFatalNotice( notice );
			onRefresh?.();
		},
		onTimeout: () => {
			toast.error(
				__(
					'Update could not be verified. Please reload the page.',
					'git'
				)
			);
			onRefresh?.();
		},
		onSettled,
	} );

	return true;
}
