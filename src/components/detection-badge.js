import { __, sprintf } from '@wordpress/i18n';
import { Flex, Spinner } from '@wordpress/components';

/**
 * Detection result badge shown above an install form.
 *
 * @param {Object}      props              Component props.
 * @param {Object|null} props.detection    Type detection result, or null while loading.
 * @param {boolean}     props.smartInstall Whether smart install is enabled.
 * @return {JSX.Element} The rendered detection badge.
 */
export default function DetectionBadge( { detection, smartInstall } ) {
	if ( ! detection ) {
		return (
			<Flex
				gap={ 2 }
				align="center"
				className="gitwire-detect-row gitwire-detect-loading"
			>
				<Spinner />
				{ __( 'Detecting project type…', 'gitwire' ) }
			</Flex>
		);
	}

	const { type, confidence, name } = detection;
	let badgeClass, label;

	if ( type === 'plugin' ) {
		badgeClass = 'gitwire-detect-plugin';
		label =
			confidence === 'high'
				? sprintf(
						/* translators: %s: plugin name */
						__( 'WordPress Plugin%s', 'gitwire' ),
						name ? `: ${ name }` : ''
				  )
				: __( 'Likely a WordPress Plugin', 'gitwire' );
	} else if ( type === 'block-theme' ) {
		badgeClass = 'gitwire-detect-theme';
		label =
			confidence === 'high'
				? sprintf(
						/* translators: %s: theme name */
						__( 'Block Theme%s', 'gitwire' ),
						name ? `: ${ name }` : ''
				  )
				: __( 'Likely a Block Theme', 'gitwire' );
	} else if ( type === 'classic-theme' ) {
		badgeClass = 'gitwire-detect-theme';
		label =
			confidence === 'high'
				? sprintf(
						/* translators: %s: theme name */
						__( 'Classic Theme%s', 'gitwire' ),
						name ? `: ${ name }` : ''
				  )
				: __( 'Likely a Classic Theme', 'gitwire' );
	} else {
		badgeClass = 'gitwire-detect-unknown';
		label = __( 'Not Recognised as a WordPress Project', 'gitwire' );
	}

	return (
		<div className="gitwire-detect-row">
			<span className={ `gitwire-detect-badge ${ badgeClass }` }>
				{ label }
			</span>
			{ type === 'unknown' && smartInstall && (
				<p className="gitwire-detect-note gitwire-detect-blocked">
					{ __(
						'Smart Install is enabled. Only verified plugins and themes can be installed. Disable it in Settings to override.',
						'gitwire'
					) }
				</p>
			) }
			{ type === 'unknown' && ! smartInstall && (
				<p className="gitwire-detect-note gitwire-detect-warn">
					{ __(
						'This repository was not recognised as a WordPress plugin or theme. You can still install it. Choose a type below.',
						'gitwire'
					) }
				</p>
			) }
		</div>
	);
}
