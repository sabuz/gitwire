import { useReducer, useRef, useCallback } from '@wordpress/element';

import * as api from '../api';

const BATCH_SIZE = 10;

/**
 * @param {string} repo Repository object with provider and full_name.
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

/**
 * Manages batched repository type detection with React state updates per batch.
 *
 * @return {Object} Detection state and helpers.
 */
export function useRepositoryDetection() {
	const [ detections, dispatch ] = useReducer( detectionsReducer, {} );
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
				const { detections: batch } = await api.detectBatch(
					chunk.map( ( repo ) => ( {
						owner: repo.owner,
						repo: repo.name,
						branch: repo.default_branch,
						provider: repo.provider,
						connection_id: repo.connection_id || '',
					} ) )
				);
				dispatch( { type: 'set_batch', payload: batch } );
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
	}, [] );

	return { detections, runBatch, seedFromRepos, reset };
}
