import { __ } from '@wordpress/i18n';
import { Flex } from '@wordpress/components';

import { GitHubIcon, GitLabIcon, BitbucketIcon } from '../provider-icons';

/**
 * Installed table cell showing the Git provider logo and label.
 *
 * @param {Object} props      Component props.
 * @param {Object} props.item Installed repository record.
 * @return {JSX.Element} The rendered source cell.
 */
export default function SourceCell( { item } ) {
	const provider = item.provider ?? 'github';

	function ProviderIcon() {
		if ( provider === 'gitlab' ) {
			return <GitLabIcon size={ 14 } variant="brand" />;
		}
		if ( provider === 'bitbucket' ) {
			return <BitbucketIcon size={ 14 } variant="brand" />;
		}
		return <GitHubIcon size={ 14 } variant="brand" />;
	}

	function providerLabel() {
		if ( provider === 'gitlab' ) {
			return __( 'GitLab', 'gitwire' );
		}
		if ( provider === 'bitbucket' ) {
			return __( 'Bitbucket', 'gitwire' );
		}
		return __( 'GitHub', 'gitwire' );
	}

	return (
		<Flex
			align="center"
			className="gitwire-installed-source"
			gap={ 1 }
			justify="flex-start"
		>
			<ProviderIcon />
			<span>{ providerLabel() }</span>
		</Flex>
	);
}
