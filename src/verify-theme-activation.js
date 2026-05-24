import * as api from './api';

const POLL_INTERVAL = 250;
const POLL_TIMEOUT = 20000;

/** @type {{ abort: () => void } | null} */
let activeSession = null;

/**
 * @param {Object}   options
 * @param {string}   options.verifyUrl   Admin URL that bootstraps the active theme.
 * @param {Function} [options.onSuccess]
 * @param {Function} [options.onFatal]
 * @param {Function} [options.onTimeout]
 * @param {Function} [options.onSettled]
 */
export function verifyThemeActivation( {
	verifyUrl,
	onSuccess,
	onFatal,
	onTimeout,
	onSettled,
} ) {
	if ( activeSession ) {
		activeSession.abort();
	}

	let isSettled = false;
	let pollTimer = null;
	const iframeEl = document.createElement( 'iframe' );
	iframeEl.hidden = true;
	iframeEl.setAttribute( 'aria-hidden', 'true' );
	iframeEl.style.cssText =
		'position:absolute;width:0;height:0;border:0;visibility:hidden';

	const startedAt = Date.now();
	let sawPending = false;

	const cleanup = () => {
		iframeEl.remove();
		if ( pollTimer ) {
			clearInterval( pollTimer );
			pollTimer = null;
		}
		if ( activeSession === session ) {
			activeSession = null;
		}
	};

	const finish = ( callback, arg ) => {
		if ( isSettled ) {
			return;
		}
		isSettled = true;
		cleanup();
		callback?.( arg );
		onSettled?.();
	};

	const session = {
		abort: () => {
			if ( isSettled ) {
				return;
			}
			isSettled = true;
			cleanup();
			onSettled?.();
		},
	};

	activeSession = session;

	const verifyTarget = new URL( verifyUrl, window.location.origin );
	verifyTarget.searchParams.set( '_gwp_verify', String( startedAt ) );
	iframeEl.src = verifyTarget.toString();
	document.body.appendChild( iframeEl );

	pollTimer = setInterval( async () => {
		if ( isSettled ) {
			return;
		}

		if ( Date.now() - startedAt > POLL_TIMEOUT ) {
			finish( onTimeout );
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

			if ( result.status === 'fatal' ) {
				finish( onFatal, result.notice );
				return;
			}

			if (
				result.status === 'bootstrap_verified' ||
				result.status === 'success'
			) {
				if ( ! sawPending ) {
					return;
				}
				finish( onSuccess, result );
				return;
			}

			if ( sawPending ) {
				finish( onTimeout );
			}
		} catch ( _e ) {
			// Keep polling while the verify request is in flight.
		}
	}, POLL_INTERVAL );
}
