import { toast as sonnerToast, Toaster as SonnerToaster } from 'sonner';

const BASE_MS = {
	success: 3000,
	error: 5000,
	warning: 6000,
};

const MAX_MS = {
	success: 5000,
	error: 14000,
	warning: 12000,
};

const MS_PER_EXTRA_CHAR = 35;
const FREE_CHARS = 50;

/**
 * @param {string}                      message           Toast message text.
 * @param {'success'|'error'|'warning'} [variant='error'] Toast variant.
 * @return {number} Duration in milliseconds.
 */
export function toastDuration( message, variant = 'error' ) {
	const text = typeof message === 'string' ? message : '';
	const base = BASE_MS[ variant ] ?? BASE_MS.error;
	const max = MAX_MS[ variant ] ?? MAX_MS.error;
	const extra = Math.max( 0, text.length - FREE_CHARS ) * MS_PER_EXTRA_CHAR;

	return Math.min( max, base + extra );
}

/**
 * @param {'success'|'error'|'warning'}    variant   Toast variant.
 * @param {string}                         message   Toast message.
 * @param {import('sonner').ExternalToast} [options] Sonner options.
 * @return {string|number} Toast id.
 */
function showToast( variant, message, options = {} ) {
	const { duration, ...rest } = options;

	return sonnerToast[ variant ]( message, {
		closeButton: false,
		...rest,
		duration: duration ?? toastDuration( message, variant ),
	} );
}

export const toast = {
	success: ( message, options ) => showToast( 'success', message, options ),
	error: ( message, options ) => showToast( 'error', message, options ),
	warning: ( message, options ) => showToast( 'warning', message, options ),
	promise: sonnerToast.promise,
	dismiss: sonnerToast.dismiss,
};

export function Toaster( props ) {
	return (
		<SonnerToaster
			closeButton={ false }
			expand
			richColors
			position="top-right"
			{ ...props }
		/>
	);
}
