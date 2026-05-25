import { toastDuration } from './toast';

const PENDING_TOAST_KEY = 'gwp_pending_toast';

/**
 * Clears any toast queued for the next page load.
 */
export function clearPendingToast() {
	sessionStorage.removeItem( PENDING_TOAST_KEY );
}

/**
 * @param {Object}              options                     Toast options.
 * @param {string}              options.message             User-facing message.
 * @param {'success'|'warning'} [options.variant='success'] Toast variant.
 * @param {number}              [options.duration]          Optional duration override in ms.
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
	const url = new URL( window.location.href );
	url.searchParams.set( 'page', 'git' );
	window.location.assign( url.toString() );
}

/**
 * Reloads the Git admin page so server-side finalize can complete the guard.
 */
export function queueGuardFinalizeReload() {
	clearPendingToast();
	const url = new URL( window.location.href );
	url.searchParams.set( 'page', 'git' );
	window.location.assign( url.toString() );
}

/**
 * @param {import('./toast').toast} toastApi Wrapped toast API.
 */
export function showPendingToast( toastApi ) {
	const raw = sessionStorage.getItem( PENDING_TOAST_KEY );
	if ( ! raw ) {
		return;
	}
	sessionStorage.removeItem( PENDING_TOAST_KEY );
	try {
		const { message, variant, duration } = JSON.parse( raw );
		if ( variant === 'warning' ) {
			toastApi.warning( message, {
				duration: duration ?? toastDuration( message, 'warning' ),
			} );
			return;
		}
		toastApi.success( message );
	} catch ( _ ) {
		toastApi.success( raw );
	}
}
