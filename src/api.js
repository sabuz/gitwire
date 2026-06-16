import apiFetch from '@wordpress/api-fetch';

const BASE = '/gitwire/v1';

export const getPublicConnections = () =>
	apiFetch( { path: `${ BASE }/public-connections` } );
export const addPublicConnection = ( data ) =>
	apiFetch( { path: `${ BASE }/public-connections`, method: 'POST', data } );
export const deletePublicConnection = ( id ) =>
	apiFetch( {
		path: `${ BASE }/public-connections/${ encodeURIComponent( id ) }`,
		method: 'DELETE',
	} );
export const getPublicConnectionRateLimit = ( id ) =>
	apiFetch( {
		path: `${ BASE }/public-connections/${ encodeURIComponent(
			id
		) }/rate-limit`,
	} );

export const getSettings = () => apiFetch( { path: `${ BASE }/settings` } );
export const saveSettings = ( data ) =>
	apiFetch( { path: `${ BASE }/settings`, method: 'POST', data } );
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
			`${ BASE }/repos/${ encodeURIComponent(
				owner
			) }/${ encodeURIComponent(
				repo
			) }/branches?provider=${ provider }` +
			( connectionId
				? `&connection_id=${ encodeURIComponent( connectionId ) }`
				: '' ),
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
			`${ BASE }/repos/${ encodeURIComponent(
				owner
			) }/${ encodeURIComponent(
				repo
			) }/detect?branch=${ encodeURIComponent(
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
		path: `${ BASE }/installed/${ encodeURIComponent(
			owner
		) }/${ encodeURIComponent( repo ) }/commits?provider=${ provider }`,
	} );

export const switchBranch = (
	owner,
	repo,
	branch,
	provider = 'github',
	connectionId = ''
) =>
	apiFetch( {
		path: `${ BASE }/installed/${ encodeURIComponent(
			owner
		) }/${ encodeURIComponent( repo ) }/branch`,
		method: 'POST',
		data: {
			branch,
			provider,
			...( connectionId ? { connection_id: connectionId } : {} ),
		},
	} );

export const activateInstalled = ( owner, repo, provider = 'github' ) =>
	apiFetch( {
		path: `${ BASE }/installed/${ encodeURIComponent(
			owner
		) }/${ encodeURIComponent( repo ) }/activate`,
		method: 'POST',
		data: { provider },
	} );

export const deactivateInstalled = ( owner, repo, provider = 'github' ) =>
	apiFetch( {
		path: `${ BASE }/installed/${ encodeURIComponent(
			owner
		) }/${ encodeURIComponent( repo ) }/deactivate`,
		method: 'POST',
		data: { provider },
	} );

export const removeInstalled = ( owner, repo, provider = 'github' ) =>
	apiFetch( {
		path: `${ BASE }/installed/${ encodeURIComponent(
			owner
		) }/${ encodeURIComponent( repo ) }?provider=${ encodeURIComponent(
			provider
		) }`,
		method: 'DELETE',
	} );

export const untrackInstalled = ( owner, repo, provider = 'github' ) =>
	apiFetch( {
		path: `${ BASE }/installed/${ encodeURIComponent(
			owner
		) }/${ encodeURIComponent(
			repo
		) }/untrack?provider=${ encodeURIComponent( provider ) }`,
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
