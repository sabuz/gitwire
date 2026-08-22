/**
 * @param {Object} props           Component props.
 * @param {number} [props.size]    Icon width/height in px.
 * @param {string} [props.variant] 'brand' uses provider colors; 'inherit' uses currentColor.
 * @return {JSX.Element} GitHub mark.
 */
export function GitHubIcon( { size = 13, variant = 'inherit' } ) {
	const fill = variant === 'brand' ? '#24292f' : 'currentColor';

	return (
		<svg
			aria-hidden="true"
			fill={ fill }
			height={ size }
			style={ { display: 'block', flexShrink: 0 } }
			viewBox="0 0 16 16"
			width={ size }
		>
			<path d="M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 5.47 7.59.4.07.55-.17.55-.38 0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13-.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.51-1.07-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.82-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82.64-.18 1.32-.27 2-.27.68 0 1.36.09 2 .27 1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.51.56.82 1.27.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A8.013 8.013 0 0016 8c0-4.42-3.58-8-8-8z" />
		</svg>
	);
}

/**
 * @param {Object} props           Component props.
 * @param {number} [props.size]    Icon width/height in px.
 * @param {string} [props.variant] 'brand' uses provider colors; 'inherit' uses currentColor.
 * @return {JSX.Element} Bitbucket mark.
 */
export function BitbucketIcon( { size = 13, variant = 'inherit' } ) {
	const fill = variant === 'brand' ? '#0052cc' : 'currentColor';

	return (
		<svg
			aria-hidden="true"
			fill={ fill }
			height={ size }
			style={ { display: 'block', flexShrink: 0 } }
			viewBox="0 0 16 16"
			width={ size }
		>
			<path d="M.778 1.213a.768.768 0 00-.768.892l2.04 12.58a1.044 1.044 0 001.028.87h9.925a.768.768 0 00.766-.646l2.04-12.81a.768.768 0 00-.768-.892L.778 1.213zM9.6 10.27H6.4l-.862-4.53h4.932L9.6 10.27z" />
		</svg>
	);
}

/**
 * @param {Object} props           Component props.
 * @param {number} [props.size]    Icon width/height in px.
 * @param {string} [props.variant] 'brand' uses provider colors; 'inherit' uses currentColor.
 * @return {JSX.Element} GitLab mark.
 */
export function GitLabIcon( { size = 13, variant = 'inherit' } ) {
	const fill = variant === 'brand' ? '#e24329' : 'currentColor';

	return (
		<svg
			aria-hidden="true"
			fill={ fill }
			height={ size }
			style={ { display: 'block', flexShrink: 0 } }
			viewBox="0 0 16 16"
			width={ size }
		>
			<path d="M15.97 9.058l-.895-2.756L13.3.842a.382.382 0 0 0-.724 0L10.8 6.302H5.2L3.424.842a.382.382 0 0 0-.724 0L.925 6.302.03 9.058a.762.762 0 0 0 .277.852L8 15.37l7.693-5.46a.762.762 0 0 0 .277-.852z" />
		</svg>
	);
}
