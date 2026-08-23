import { useReducer, useRef, useCallback } from '@wordpress/element';

import * as api from '../api';

const BATCH_SIZE = 10;

/**
 * @param {Object} repository Repository object with provider and full_name.
 * @return {string} Detection cache key.
 */
export function detectionKey( repository ) {
	return `${ repository.provider }:${ repository.full_name }`;
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
 * Do not store paused repositories in the detection map. A placeholder would appear as
 * a valid result and could prevent on-demand detection.
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
		const toDetect = repositories.filter( ( repository ) => {
			const key = detectionKey( repository );
			return (
				! repository.installed &&
				! pendingRef.current.has( key ) &&
				! detectionsRef.current[ key ]
			);
		} );

		if ( ! toDetect.length ) {
			return;
		}

		for ( let i = 0; i < toDetect.length; i += BATCH_SIZE ) {
			const chunk = toDetect.slice( i, i + BATCH_SIZE );
			chunk.forEach( ( repository ) =>
				pendingRef.current.add( detectionKey( repository ) )
			);

			try {
				const response = await api.detectBatch(
					chunk.map( ( repository ) => ( {
						owner: repository.owner,
						repository: repository.name,
						branch: repository.default_branch,
						provider: repository.provider,
						connection_id: repository.connection_id || '',
					} ) )
				);
				dispatch( {
					type: 'set_batch',
					payload: response.detections ?? {},
				} );

				if ( response.paused?.length ) {
					/*
					 * Mark the remaining queued repositories as paused because the same
					 * rate-limit condition applies to them.
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
				chunk.forEach( ( repository ) => {
					fallback[ detectionKey( repository ) ] = {
						type: 'unknown',
						confidence: 'none',
					};
				} );
				dispatch( { type: 'set_batch', payload: fallback } );
			} finally {
				chunk.forEach( ( repository ) =>
					pendingRef.current.delete( detectionKey( repository ) )
				);
			}
		}
	}, [] );

	const seedFromRepositories = useCallback( ( repositories ) => {
		const seeded = {};
		repositories.forEach( ( repository ) => {
			if ( repository.detection ) {
				seeded[ detectionKey( repository ) ] = repository.detection;
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

	return { detections, paused, runBatch, seedFromRepositories, reset };
}
