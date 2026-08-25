/**
 * Some REST error messages mention "Gitwire Pro" as plain text (e.g. the
 * rate-limit message from class-github-api.php). Wrap that phrase in a link
 * to the pricing page wherever it shows up in a rendered (non-toast) notice.
 *
 * @param {string} text Text that may contain the phrase "Gitwire Pro".
 * @return {string|Array} Original text, or an array of nodes with the phrase linked.
 */
export function linkifyGitwirePro( text ) {
	if ( typeof text !== 'string' || ! text.includes( 'Gitwire Pro' ) ) {
		return text;
	}

	const parts = text.split( 'Gitwire Pro' );

	return parts.flatMap( ( part, i ) =>
		i === 0
			? [ part ]
			: [
					<a
						key={ i }
						href="https://gitwire.app/pricing/"
						rel="noopener noreferrer"
						target="_blank"
					>
						Gitwire Pro
					</a>,
					part,
			  ]
	);
}
