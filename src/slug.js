/**
 * @param {string} value Raw user input.
 * @return {string} Slug-safe value. Leading/trailing separators are kept so the
 *                  user can type them mid-edit; call finalizeSlug before submit.
 */
export function normalizeSlug( value ) {
	return value
		.toLowerCase()
		.replace( /[^a-z0-9_-]+/g, '-' )
		.replace( /[-_]*-[-_]*/g, '-' )
		.replace( /__+/g, '_' );
}

/**
 * @param {string} value Raw user input.
 * @return {string} Slug with leading/trailing separators trimmed.
 */
export function finalizeSlug( value ) {
	return normalizeSlug( value ).replace( /^[-_]+|[-_]+$/g, '' );
}
