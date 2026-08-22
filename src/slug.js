/**
 * @param {string} value Raw user input.
 * @return {string} Slug-safe value. Leading and trailing separators are preserved
 *                  during editing; call finalizeSlug before submission.
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
