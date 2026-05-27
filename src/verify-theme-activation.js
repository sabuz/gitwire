import * as api from './api';

const POLL_INTERVAL = 250;
const POLL_TIMEOUT = 30000;
const BOOTSTRAP_SETTLE_MS = 100;

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
 * @param {string} verifyUrl Frontend URL that bootstraps the active theme.
 * @return {Promise<void>}
 */
async function triggerFrontendBootstrap( verifyUrl ) {
	const verifyTarget = new URL( verifyUrl, window.location.origin );
	verifyTarget.searchParams.set( '_gitwire_verify', String( Date.now() ) );

	await fetch( verifyTarget.toString(), {
		credentials: 'same-origin',
		cache: 'no-store',
	} ).catch( () => {} );

	await sleep( BOOTSTRAP_SETTLE_MS );
}

/**
 * @param {string} verifyUrl Admin URL that bootstraps the active theme.
 * @return {Promise<void>}
 */
async function triggerAdminBootstrap( verifyUrl ) {
	if ( ! verifyUrl ) {
		return;
	}

	const verifyTarget = new URL( verifyUrl, window.location.origin );
	verifyTarget.searchParams.set( '_gitwire_verify', String( Date.now() ) );

	await fetch( verifyTarget.toString(), {
		credentials: 'same-origin',
		cache: 'no-store',
	} ).catch( () => {} );

	await sleep( BOOTSTRAP_SETTLE_MS );
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

	const verifyUrl = window.Gitwire?.verify_activation_url;
	const adminVerifyUrl = window.Gitwire?.verify_admin_url;
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

	const handleStatus = async ( result ) => {
		if ( result.status === 'fatal' ) {
			await finish( onFatal, result.notice );
			return true;
		}

		if ( result.status === 'bootstrap_verified' ) {
			await finish( onSuccess, result );
			return true;
		}

		if ( result.status === 'idle' ) {
			await finish( onTimeout );
			return true;
		}

		return false;
	};

	const run = async () => {
		if ( ! verifyUrl ) {
			await finish( onTimeout );
			return;
		}

		const deadline = Date.now() + POLL_TIMEOUT;

		try {
			const initial = await api.getActivationStatus();
			if ( await handleStatus( initial ) ) {
				return;
			}
		} catch ( _e ) {
			// Fall through to the bootstrap loop.
		}

		while ( Date.now() < deadline && ! isSettled ) {
			try {
				await triggerFrontendBootstrap( verifyUrl );
				await triggerAdminBootstrap( adminVerifyUrl );

				const result = await api.getActivationStatus();
				if ( await handleStatus( result ) ) {
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
