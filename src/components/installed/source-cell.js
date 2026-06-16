import { Flex } from '@wordpress/components';

import { ProviderIcon, providerLabel } from '../provider';

/**
 * Installed table cell showing the Git provider logo and label.
 *
 * @param {Object} props      Component props.
 * @param {Object} props.item Installed repository record.
 * @return {JSX.Element} The rendered source cell.
 */
export default function SourceCell( { item } ) {
	const provider = item.provider ?? 'github';

	return (
		<Flex
			align="center"
			className="gitwire-installed-source"
			gap={ 1 }
			justify="flex-start"
		>
			<ProviderIcon provider={ provider } size={ 14 } variant="brand" />
			<span>{ providerLabel( provider ) }</span>
		</Flex>
	);
}
