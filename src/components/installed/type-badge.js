import { __ } from '@wordpress/i18n';

/**
 * Type badge for an installed repository row.
 *
 * @param {Object} props      Component props.
 * @param {Object} props.item Installed repository record.
 * @return {JSX.Element} The rendered type badge.
 */
export default function TypeBadge( { item } ) {
	const isBlockTheme = item.type === 'theme' && item.subtype === 'block';
	const isTheme = item.type === 'theme';

	let badgeMod = 'info';
	if ( isBlockTheme ) {
		badgeMod = 'block-theme';
	} else if ( isTheme ) {
		badgeMod = 'theme';
	}

	return (
		<span className={ `gwp-badge gwp-badge--${ badgeMod }` }>
			<TypeBadgeLabel isBlockTheme={ isBlockTheme } isTheme={ isTheme } />
		</span>
	);
}

/**
 * @param {Object}  props              Component props.
 * @param {boolean} props.isBlockTheme Whether the item is a block theme.
 * @param {boolean} props.isTheme      Whether the item is a classic theme.
 * @return {string} Translated type label.
 */
function TypeBadgeLabel( { isBlockTheme, isTheme } ) {
	if ( isBlockTheme ) {
		return __( 'Block Theme', 'git' );
	}
	if ( isTheme ) {
		return __( 'Theme', 'git' );
	}
	return __( 'Plugin', 'git' );
}
