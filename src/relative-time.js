import { __, sprintf } from '@wordpress/i18n';

/**
 * @param {number} ts Unix timestamp in seconds.
 * @return {string} Localized relative time, e.g. "3h ago".
 */
export function relativeTimeFromUnix( ts ) {
	const s = Math.floor( Date.now() / 1000 ) - ts;
	if ( s < 60 ) {
		return __( 'just now', 'gitwire' );
	}
	const m = Math.floor( s / 60 );
	if ( m < 60 ) {
		/* translators: %d: number of minutes */
		return sprintf( __( '%dm ago', 'gitwire' ), m );
	}
	const h = Math.floor( m / 60 );
	if ( h < 24 ) {
		/* translators: %d: number of hours */
		return sprintf( __( '%dh ago', 'gitwire' ), h );
	}
	const d = Math.floor( h / 24 );
	if ( d < 30 ) {
		/* translators: %d: number of days */
		return sprintf( __( '%dd ago', 'gitwire' ), d );
	}
	const mo = Math.floor( d / 30 );
	if ( mo < 12 ) {
		/* translators: %d: number of months */
		return sprintf( __( '%dmo ago', 'gitwire' ), mo );
	}
	/* translators: %d: number of years */
	return sprintf( __( '%dy ago', 'gitwire' ), Math.floor( mo / 12 ) );
}

/**
 * @param {string|number|Date} dateStr A date string, ms timestamp, or Date.
 * @return {string} Localized relative time, e.g. "3h ago".
 */
export function relativeTimeFromDate( dateStr ) {
	return relativeTimeFromUnix(
		Math.floor( new Date( dateStr ).getTime() / 1000 )
	);
}
