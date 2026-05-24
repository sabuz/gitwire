import * as api from './api';

const POLL_INTERVAL = 250;
const POLL_TIMEOUT = 20000;

/**
 * @param {Object}   options
 * @param {string}   options.verifyUrl   Admin URL that bootstraps the active theme.
 * @param {Function} [options.onSuccess]
 * @param {Function} [options.onFatal]
 * @param {Function} [options.onTimeout]
 */
export function verifyThemeActivation( {
	verifyUrl,
	onSuccess,
	onFatal,
	onTimeout,
} ) {
	const iframeEl = document.createElement( 'iframe' );
	iframeEl.hidden = true;
	iframeEl.setAttribute( 'aria-hidden', 'true' );
	iframeEl.style.cssText =
		'position:absolute;width:0;height:0;border:0;visibility:hidden';
	iframeEl.src = verifyUrl;
	document.body.appendChild( iframeEl );

	const startedAt = Date.now();
	let sawPending = false;

	const cleanup = () => {
		iframeEl.remove();
		clearInterval( pollTimer );
	};

	const pollTimer = setInterval( async () => {
		if ( Date.now() - startedAt > POLL_TIMEOUT ) {
			cleanup();
			onTimeout?.();
			return;
		}

		try {
			const result = await api.getActivationStatus();

			if ( result.status === 'pending' ) {
				sawPending = true;
				return;
			}

			if ( result.status === 'idle' && ! sawPending ) {
				return;
			}

			cleanup();

			if ( result.status === 'fatal' ) {
				onFatal?.( result.notice );
				return;
			}

			if ( result.status === 'success' ) {
				onSuccess?.( result );
				return;
			}

			onTimeout?.();
		} catch ( _e ) {
			// Keep polling while the verify request is in flight.
		}
	}, POLL_INTERVAL );
}
