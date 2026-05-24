const PENDING_TOAST_KEY = 'gwp_pending_toast';

/**
 * @param {Object}              options                     Toast options.
 * @param {string}              options.message             User-facing message.
 * @param {'success'|'warning'} [options.variant='success'] Toast variant.
 * @param {number}              [options.duration]          Toast duration in ms (warnings only).
 */
export function queuePendingToast( {
	message,
	variant = 'success',
	duration,
} ) {
	sessionStorage.setItem(
		PENDING_TOAST_KEY,
		JSON.stringify( { message, variant, duration } )
	);
}

/**
 * @param {string} message Success message to show after the page reloads.
 */
export function queuePendingToastAndReload( message ) {
	queuePendingToast( { message, variant: 'success' } );
	window.location.reload();
}

/**
 * @param {import('sonner').toast} toast Sonner toast API.
 */
export function showPendingToast( toast ) {
	const raw = sessionStorage.getItem( PENDING_TOAST_KEY );
	if ( ! raw ) {
		return;
	}
	sessionStorage.removeItem( PENDING_TOAST_KEY );
	try {
		const { message, variant, duration } = JSON.parse( raw );
		if ( variant === 'warning' ) {
			toast.warning( message, { duration: duration ?? 8000 } );
			return;
		}
		toast.success( message );
	} catch ( _ ) {
		toast.success( raw );
	}
}
