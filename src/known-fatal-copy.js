import { __, sprintf } from '@wordpress/i18n';

export function hasKnownFatalUpdate( item ) {
	return !! (
		item?.known_fatal_head &&
		item?.update_available &&
		item?.remote_head &&
		item.known_fatal_head === item.remote_head
	);
}

export function knownFatalTooltip( item ) {
	const shortSha = ( item.known_fatal_head || item.remote_head || '' ).slice(
		0,
		7
	);

	return sprintf(
		/* translators: %s: short commit SHA */
		__(
			'Commit %s is still the latest on this branch and caused a fatal error during a recent test. Pull was skipped to keep your site running. Push a new commit or wait a few minutes to retry the same one.',
			'gitwire'
		),
		shortSha
	);
}

export function knownFatalBadgeLabel( item ) {
	const shortSha = ( item.known_fatal_head || item.remote_head || '' ).slice(
		0,
		7
	);

	return sprintf(
		/* translators: %s: short commit SHA */
		__( 'Update blocked (%s)', 'gitwire' ),
		shortSha
	);
}
