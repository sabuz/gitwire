import * as api from './api';

const POLL_INTERVAL = 250;
const POLL_TIMEOUT = 30000;

const sleep = ( ms ) =>
	new Promise( ( resolve ) => {
		setTimeout( resolve, ms );
	} );

/** @type {{ abort: () => void } | null} */
let activeSession = null;

export function isVerifyRunning() {
	return activeSession !== null;
}

/**
 * @param {Object}   options
 * @param {Function} [options.onSuccess]
 * @param {Function} [options.onFatal]
 * @param {Function} [options.onTimeout]
 * @param {Function} [options.onSettled]
 */
export function verifyThemeActivation( {
	onSuccess,
	onFatal,
	onTimeout,
	onSettled,
} ) {
	if ( activeSession ) {
		activeSession.abort();
	}

	let isSettled = false;

	const cleanup = () => {
		if ( activeSession === session ) {
			activeSession = null;
		}
	};

	const finish = async ( callback, arg ) => {
		if ( isSettled ) {
			return;
		}
		isSettled = true;
		cleanup();
		try {
			await callback?.( arg );
		} finally {
			onSettled?.();
		}
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

	const run = async () => {
		const deadline = Date.now() + POLL_TIMEOUT;
		let sawPending = false;

		while ( Date.now() < deadline && ! isSettled ) {
			try {
				const verified = await api.verifyBootstrap();

				if ( verified.status === 'fatal' ) {
					await finish( onFatal, verified.notice );
					return;
				}

				if ( verified.status === 'bootstrap_verified' ) {
					await finish( onSuccess, verified );
					return;
				}

				if ( verified.status === 'pending' ) {
					sawPending = true;
				}

				const result = await api.getActivationStatus();

				if ( result.status === 'fatal' ) {
					await finish( onFatal, result.notice );
					return;
				}

				if ( result.status === 'pending' ) {
					sawPending = true;
				}

				if ( result.status === 'bootstrap_verified' ) {
					await finish( onSuccess, result );
					return;
				}

				if ( result.status === 'idle' && sawPending ) {
					await finish( onTimeout );
					return;
				}
			} catch ( _e ) {
				// Keep trying until the deadline.
			}

			await sleep( POLL_INTERVAL );
		}

		await finish( onTimeout );
	};

	run();
}
