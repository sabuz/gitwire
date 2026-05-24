import apiFetch from '@wordpress/api-fetch';

const BASE = '/gwp/v1';

export const getSettings = () => apiFetch( { path: `${ BASE }/settings` } );
export const saveSettings = ( data ) =>
	apiFetch( { path: `${ BASE }/settings`, method: 'POST', data } );
export const testConnection = ( data = {} ) =>
	apiFetch( { path: `${ BASE }/connection`, method: 'POST', data } );
export const getRepos = ( page = 1, provider = 'github' ) =>
	apiFetch( {
		path: `${ BASE }/repos?page=${ page }&provider=${ provider }`,
	} );
export const clearCache = () =>
	apiFetch( { path: `${ BASE }/repos/cache`, method: 'DELETE' } );
export const getInstalled = () => apiFetch( { path: `${ BASE }/installed` } );

export const getBranches = ( owner, repo, provider = 'github' ) =>
	apiFetch( {
		path: `${ BASE }/repos/${ owner }/${ repo }/branches?provider=${ provider }`,
	} );

export const detectRepo = ( owner, repo, branch, provider = 'github' ) =>
	apiFetch( {
		path: `${ BASE }/repos/${ owner }/${ repo }/detect?branch=${ encodeURIComponent(
			branch
		) }&provider=${ provider }`,
	} );

export const install = ( data ) =>
	apiFetch( { path: `${ BASE }/install`, method: 'POST', data } );

export const checkSlug = (
	slug,
	type = 'plugin',
	owner = '',
	repo = '',
	provider = 'github'
) =>
	apiFetch( {
		path: `${ BASE }/check-slug?slug=${ encodeURIComponent(
			slug
		) }&type=${ type }&owner=${ encodeURIComponent(
			owner
		) }&repo=${ encodeURIComponent( repo ) }&provider=${ provider }`,
	} );

export const getCommits = ( owner, repo, provider = 'github' ) =>
	apiFetch( {
		path: `${ BASE }/installed/${ owner }/${ repo }/commits?provider=${ provider }`,
	} );

export const switchBranch = ( owner, repo, branch, provider = 'github' ) =>
	apiFetch( {
		path: `${ BASE }/installed/${ owner }/${ repo }/branch`,
		method: 'POST',
		data: { branch, provider },
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

export const removeInstalled = ( owner, repo, provider = 'github' ) =>
	apiFetch( {
		path: `${ BASE }/installed/${ owner }/${ repo }?provider=${ encodeURIComponent(
			provider
		) }`,
		method: 'DELETE',
	} );
