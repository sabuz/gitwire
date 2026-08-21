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
export const getRepos = ( {
	offset = 0,
	search = '',
	connectionIds = [],
} = {} ) => {
	const params = new URLSearchParams( { offset } );
	if ( search ) {
		params.set( 'search', search );
	}
	connectionIds.forEach( ( id ) => params.append( 'connection_ids[]', id ) );
	return apiFetch( { path: `${ BASE }/repos?${ params }` } );
};
export const clearCache = ( mode = 'repos' ) =>
	apiFetch( {
		path: `${ BASE }/repos/cache?mode=${ mode }`,
		method: 'DELETE',
	} );
export const getInstalled = () => apiFetch( { path: `${ BASE }/installed` } );
export const syncInstalled = () =>
	apiFetch( { path: `${ BASE }/installed/sync`, method: 'POST' } );

export const detectBatch = ( repositories ) =>
	apiFetch( {
		path: `${ BASE }/repos/detect-batch`,
		method: 'POST',
		data: { repositories },
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

export const getCommits = ( id ) =>
	apiFetch( { path: `${ BASE }/installed/${ id }/commits` } );

export const switchBranch = ( id, branch, connectionId = '' ) =>
	apiFetch( {
		path: `${ BASE }/installed/${ id }/branch`,
		method: 'POST',
		data: {
			branch,
			...( connectionId ? { connection_id: connectionId } : {} ),
		},
	} );

export const activateInstalled = ( id ) =>
	apiFetch( {
		path: `${ BASE }/installed/${ id }/activate`,
		method: 'POST',
	} );

export const deactivateInstalled = ( id ) =>
	apiFetch( {
		path: `${ BASE }/installed/${ id }/deactivate`,
		method: 'POST',
	} );

export const removeInstalled = ( id ) =>
	apiFetch( { path: `${ BASE }/installed/${ id }`, method: 'DELETE' } );

export const untrackInstalled = ( id ) =>
	apiFetch( {
		path: `${ BASE }/installed/${ id }/untrack`,
		method: 'DELETE',
	} );

export const saveAutoUpdate = ( id, autoUpdate ) =>
	apiFetch( {
		path: `${ BASE }/installed/${ id }/auto-update`,
		method: 'POST',
		data: { auto_update: autoUpdate },
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
	perPage = 200,
	offset = 0,
} = {} ) => {
	const params = new URLSearchParams( { per_page: perPage, offset } );
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
