import { toast } from 'sonner';

import { __, sprintf } from '@wordpress/i18n';

import * as api from './api';
import { showFatalNotice } from './fatal-notice';
import { queuePendingToastAndReload } from './pending-toast';
import {
	isVerifyRunning,
	verifyThemeActivation,
} from './verify-theme-activation';

/**
 * @param {Object}              options
 * @param {Object}              options.result
 * @param {Object}              options.item
 * @param {string}              [options.successMessage]
 * @param {'activate'|'update'} options.context
 */
function queueVerifiedThemeGuardReload( {
	result,
	item,
	successMessage,
	context,
} ) {
	const message =
		successMessage ||
		( context === 'activate'
			? sprintf(
					/* translators: %s: repository full name */
					__( '%s activated.', 'git' ),
					result.full_name || item.full_name
			  )
			: sprintf(
					/* translators: %s: repository full name */
					__( '%s updated to latest.', 'git' ),
					result.full_name || item.full_name
			  ) );

	queuePendingToastAndReload( message );
}

/**
 * @param {Object}              options
 * @param {Object}              options.item               Installed repository record.
 * @param {Function}            [options.onRefresh]        Refreshes installed data after a failed verify.
 * @param {string}              [options.successMessage]   Toast message after a verified reload.
 * @param {Function}            [options.onSettled]        Called when polling finishes in any outcome.
 * @param {'activate'|'update'} [options.context='update'] User-facing timeout copy.
 * @return {boolean} True when verification was started.
 */
export function startThemeGuardVerification( {
	item,
	onRefresh,
	successMessage,
	onSettled,
	context = 'update',
} ) {
	const timeoutMessage =
		context === 'activate'
			? __(
					'Theme activation could not be verified. The previous theme was restored.',
					'git'
			  )
			: __(
					'Update could not be verified. The previous version was restored.',
					'git'
			  );

	onRefresh?.();

	verifyThemeActivation( {
		onSuccess: ( result ) => {
			queueVerifiedThemeGuardReload( {
				result,
				item,
				successMessage,
				context,
			} );
		},
		onFatal: ( notice ) => {
			showFatalNotice( notice );
			onRefresh?.();
		},
		onTimeout: async () => {
			try {
				await api.abortActivationGuard();
			} catch ( _e ) {
				// Best-effort cleanup so the UI is not stuck on "Verifying…".
			}
			toast.error( timeoutMessage );
			onRefresh?.();
		},
		onSettled,
	} );

	return true;
}

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
	if ( ! needsVerify ) {
		return false;
	}

	return startThemeGuardVerification( {
		item,
		onRefresh,
		successMessage,
		onSettled,
		context: 'update',
	} );
}

/**
 * Resumes theme guard verification when the server still has a pending record.
 *
 * @param {Object}            options
 * @param {Object<string, *>} options.installed Installed repository map.
 * @param {Function}          options.onRefresh Refreshes installed data.
 * @return {Promise<boolean>} True when verification was started or finalized.
 */
export async function resumePendingThemeVerification( {
	installed,
	onRefresh,
} ) {
	if ( isVerifyRunning() ) {
		return false;
	}

	const item = Object.values( installed || {} ).find(
		( record ) => record.activation_pending && record.type === 'theme'
	);
	if ( ! item ) {
		return false;
	}

	const context = item.active ? 'update' : 'activate';

	try {
		const status = await api.getActivationStatus();
		if ( status.status === 'fatal' ) {
			showFatalNotice( status.notice );
			onRefresh?.();
			return true;
		}
		if ( status.status === 'bootstrap_verified' ) {
			queueVerifiedThemeGuardReload( {
				result: status,
				item,
				context,
			} );
			return true;
		}
		if ( status.status !== 'pending' ) {
			// Guard was already finalized server-side — refresh to clear the stale badge.
			if ( status.status === 'idle' ) {
				onRefresh?.();
			}
			return false;
		}
	} catch ( _e ) {
		return false;
	}

	return startThemeGuardVerification( {
		item,
		onRefresh,
		context,
	} );
}
