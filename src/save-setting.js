import { __ } from '@wordpress/i18n';

import { toast } from './toast';
import * as api from './api';

/**
 * Saves settings, re-reads the canonical state, and shows a save toast.
 *
 * @param {Object}   payload    Settings keys to persist.
 * @param {Function} onSave     Called with the re-read settings on success.
 * @param {Function} [rollback] Called to revert optimistic UI on failure.
 * @return {Promise} Resolves after the save round-trip.
 */
export function persistSetting( payload, onSave, rollback ) {
	const p = api
		.saveSettings( payload )
		.then( () => api.getSettings() )
		.then( ( saved ) => onSave( saved ) )
		.catch( ( e ) => {
			rollback?.();
			throw e;
		} );

	toast.promise( p, {
		id: 'settings-save',
		loading: __( 'Saving…', 'gitwire' ),
		success: __( 'Saved.', 'gitwire' ),
		error: ( e ) => e?.message || __( 'Save failed.', 'gitwire' ),
	} );

	return p;
}
