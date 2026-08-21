import { useReducer, useRef, useCallback } from '@wordpress/element';

import * as api from '../api';

const BATCH_SIZE = 10;

/**
 * @param {Object} repo Repository object with provider and full_name.
 * @return {string} Detection cache key.
 */
export function detectionKey( repo ) {
	return `${ repo.provider }:${ repo.full_name }`;
}

function detectionsReducer( state, action ) {
	switch ( action.type ) {
		case 'set_batch':
			return { ...state, ...action.payload };
		case 'reset':
			return {};
		default:
			return state;
	}
}

/*
 * Paused repositories are kept out of the detections map on purpose. A placeholder in
 * there would read as a real result everywhere downstream, and the install modal would
 * take it as a reason not to detect the one repo the user actually asked about.
 */
function pausedReducer( state, action ) {
	switch ( action.type ) {
		case 'set_paused':
			return {
				reason: action.reason,
				keys: new Set( [ ...state.keys, ...action.keys ] ),
			};
		case 'reset':
			return { reason: null, keys: new Set() };
		default:
			return state;
	}
}

const NO_PAUSED = { reason: null, keys: new Set() };

/**
 * Manages batched repository type detection with React state updates per batch.
 *
 * @return {Object} Detection state and helpers.
 */
export function useRepositoryDetection() {
	const [ detections, dispatch ] = useReducer( detectionsReducer, {} );
	const [ paused, dispatchPaused ] = useReducer( pausedReducer, NO_PAUSED );
	const pendingRef = useRef( new Set() );
	const detectionsRef = useRef( detections );
	detectionsRef.current = detections;

	const runBatch = useCallback( async ( repositories ) => {
		const toDetect = repositories.filter( ( repo ) => {
			const key = detectionKey( repo );
			return (
				! repo.installed &&
				! pendingRef.current.has( key ) &&
				! detectionsRef.current[ key ]
			);
		} );

		if ( ! toDetect.length ) {
			return;
		}

		for ( let i = 0; i < toDetect.length; i += BATCH_SIZE ) {
			const chunk = toDetect.slice( i, i + BATCH_SIZE );
			chunk.forEach( ( repo ) =>
				pendingRef.current.add( detectionKey( repo ) )
			);

			try {
				const response = await api.detectBatch(
					chunk.map( ( repo ) => ( {
						owner: repo.owner,
						repo: repo.name,
						branch: repo.default_branch,
						provider: repo.provider,
						connection_id: repo.connection_id || '',
					} ) )
				);
				dispatch( {
					type: 'set_batch',
					payload: response.detections ?? {},
				} );

				if ( response.paused?.length ) {
					/*
					 * Every chunk still queued would be turned away on the same
					 * grounds, so they are marked here rather than asked for and
					 * left to sit on a spinner.
					 */
					const remaining = toDetect
						.slice( i + BATCH_SIZE )
						.map( detectionKey );

					dispatchPaused( {
						type: 'set_paused',
						keys: [ ...response.paused, ...remaining ],
						reason: response.paused_reason ?? 'rate_limit',
					} );
					return;
				}
			} catch {
				const fallback = {};
				chunk.forEach( ( repo ) => {
					fallback[ detectionKey( repo ) ] = {
						type: 'unknown',
						confidence: 'none',
					};
				} );
				dispatch( { type: 'set_batch', payload: fallback } );
			} finally {
				chunk.forEach( ( repo ) =>
					pendingRef.current.delete( detectionKey( repo ) )
				);
			}
		}
	}, [] );

	const seedFromRepos = useCallback( ( repositories ) => {
		const seeded = {};
		repositories.forEach( ( repo ) => {
			if ( repo.detection ) {
				seeded[ detectionKey( repo ) ] = repo.detection;
			}
		} );
		if ( Object.keys( seeded ).length ) {
			dispatch( { type: 'set_batch', payload: seeded } );
		}
	}, [] );

	const reset = useCallback( () => {
		pendingRef.current.clear();
		dispatch( { type: 'reset' } );
		dispatchPaused( { type: 'reset' } );
	}, [] );

	return { detections, paused, runBatch, seedFromRepos, reset };
}
