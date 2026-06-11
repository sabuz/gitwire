import apiFetch from '@wordpress/api-fetch';

const BASE = '/gitwire/v1';

export const getSettings = () => apiFetch( { path: `${ BASE }/settings` } );
export const saveSettings = ( data ) =>
	apiFetch( { path: `${ BASE }/settings`, method: 'POST', data } );
export const getConnections = () =>
	apiFetch( { path: `${ BASE }/connections` } );
export const createConnection = ( data = {} ) =>
	apiFetch( { path: `${ BASE }/connections`, method: 'POST', data } );
export const deleteConnection = ( id ) =>
	apiFetch( { path: `${ BASE }/connections/${ id }`, method: 'DELETE' } );

export const testConnection = ( id ) =>
	apiFetch( { path: `${ BASE }/connections/${ id }/test`, method: 'POST' } );
export const getRepos = ( page = 1, provider = 'github', connectionId = '' ) =>
	apiFetch( {
		path:
			`${ BASE }/repos?page=${ page }&provider=${ provider }` +
			( connectionId
				? `&connection_id=${ encodeURIComponent( connectionId ) }`
				: '' ),
	} );
export const clearCache = () =>
	apiFetch( { path: `${ BASE }/repos/cache`, method: 'DELETE' } );
export const getInstalled = () => apiFetch( { path: `${ BASE }/installed` } );
export const syncInstalled = () =>
	apiFetch( { path: `${ BASE }/installed/sync`, method: 'POST' } );

export const detectBatch = ( repos ) =>
	apiFetch( {
		path: `${ BASE }/repos/detect-batch`,
		method: 'POST',
		data: { repos },
	} );

export const getBranches = (
	owner,
	repo,
	provider = 'github',
	connectionId = ''
) =>
	apiFetch( {
		path:
			`${ BASE }/repos/${ owner }/${ repo }/branches?provider=${ provider }` +
			( connectionId ? `&connection_id=${ connectionId }` : '' ),
	} );

export const detectRepo = (
	owner,
	repo,
	branch,
	provider = 'github',
	connectionId = ''
) =>
	apiFetch( {
		path:
			`${ BASE }/repos/${ owner }/${ repo }/detect?branch=${ encodeURIComponent(
				branch
			) }&provider=${ provider }` +
			( connectionId
				? `&connection_id=${ encodeURIComponent( connectionId ) }`
				: '' ),
	} );

export const install = ( data ) =>
	apiFetch( { path: `${ BASE }/install`, method: 'POST', data } );

export const checkSlug = ( slug, type = 'plugin' ) =>
	apiFetch( {
		path: `${ BASE }/check-slug?slug=${ encodeURIComponent(
			slug
		) }&type=${ type }`,
	} );

export const getCommits = ( owner, repo, provider = 'github' ) =>
	apiFetch( {
		path: `${ BASE }/installed/${ owner }/${ repo }/commits?provider=${ provider }`,
	} );

export const switchBranch = (
	owner,
	repo,
	branch,
	provider = 'github',
	connectionId = ''
) =>
	apiFetch( {
		path: `${ BASE }/installed/${ owner }/${ repo }/branch`,
		method: 'POST',
		data: {
			branch,
			provider,
			...( connectionId ? { connection_id: connectionId } : {} ),
		},
	} );

export const activateInstalled = ( owner, repo, provider = 'github' ) =>
	apiFetch( {
		path: `${ BASE }/installed/${ owner }/${ repo }/activate`,
		method: 'POST',
		data: { provider },
	} );

export const deactivateInstalled = ( owner, repo, provider = 'github' ) =>
	apiFetch( {
		path: `${ BASE }/installed/${ owner }/${ repo }/deactivate`,
		method: 'POST',
		data: { provider },
	} );

export const getActivationStatus = () =>
	apiFetch( { path: `${ BASE }/activation-status` } );

export const abortActivationGuard = () =>
	apiFetch( { path: `${ BASE }/activation-status`, method: 'DELETE' } );

export const verifyBootstrap = () =>
	apiFetch( { path: `${ BASE }/verify-bootstrap`, method: 'POST' } );

export const removeInstalled = ( owner, repo, provider = 'github' ) =>
	apiFetch( {
		path: `${ BASE }/installed/${ owner }/${ repo }?provider=${ encodeURIComponent(
			provider
		) }`,
		method: 'DELETE',
	} );

export const untrackInstalled = ( owner, repo, provider = 'github' ) =>
	apiFetch( {
		path: `${ BASE }/installed/${ owner }/${ repo }/untrack?provider=${ encodeURIComponent(
			provider
		) }`,
		method: 'DELETE',
	} );

export const resolveRepo = ( url ) =>
	apiFetch( {
		path: `${ BASE }/repos/resolve`,
		method: 'POST',
		data: { url },
	} );

export const getLogs = ( {
	from = '',
	to = '',
	level = '',
	actors = [],
} = {} ) => {
	const params = new URLSearchParams();
	if ( from ) {
		params.set( 'from', from );
	}
	if ( to ) {
		params.set( 'to', to );
	}
	if ( level ) {
		params.set( 'level', level );
	}
	actors.forEach( ( a ) => params.append( 'actors[]', a ) );
	const query = params.toString();
	return apiFetch( { path: `${ BASE }/logs${ query ? '?' + query : '' }` } );
};

export const clearLogs = () =>
	apiFetch( { path: `${ BASE }/logs`, method: 'DELETE' } );

export const getLogActors = ( search = '' ) =>
	apiFetch( {
		path: `${ BASE }/log-actors${
			search ? '?search=' + encodeURIComponent( search ) : ''
		}`,
	} );
