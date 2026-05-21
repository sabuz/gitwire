import apiFetch from '@wordpress/api-fetch';

const BASE = '/gwp/v1';

export const getSettings = () => apiFetch( { path: `${ BASE }/settings` } );
export const saveSettings = ( data ) =>
	apiFetch( { path: `${ BASE }/settings`, method: 'POST', data } );
export const testConnection = ( data = {} ) =>
	apiFetch( { path: `${ BASE }/connection`, method: 'POST', data } );
export const getRepos = ( page = 1 ) =>
	apiFetch( { path: `${ BASE }/repos?page=${ page }` } );
export const getInstalled = () => apiFetch( { path: `${ BASE }/installed` } );

export const getBranches = ( owner, repo ) =>
	apiFetch( { path: `${ BASE }/repos/${ owner }/${ repo }/branches` } );

export const detectRepo = ( owner, repo, branch ) =>
	apiFetch( {
		path: `${ BASE }/repos/${ owner }/${ repo }/detect?branch=${ encodeURIComponent(
			branch
		) }`,
	} );

export const install = ( data ) =>
	apiFetch( { path: `${ BASE }/install`, method: 'POST', data } );

export const switchBranch = ( owner, repo, branch ) =>
	apiFetch( {
		path: `${ BASE }/installed/${ owner }/${ repo }/branch`,
		method: 'POST',
		data: { branch },
	} );

export const activateInstalled = ( owner, repo ) =>
	apiFetch( {
		path: `${ BASE }/installed/${ owner }/${ repo }/activate`,
		method: 'POST',
	} );

export const deactivateInstalled = ( owner, repo ) =>
	apiFetch( {
		path: `${ BASE }/installed/${ owner }/${ repo }/deactivate`,
		method: 'POST',
	} );

export const removeInstalled = ( owner, repo ) =>
	apiFetch( {
		path: `${ BASE }/installed/${ owner }/${ repo }`,
		method: 'DELETE',
	} );
