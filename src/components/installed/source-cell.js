import { __ } from '@wordpress/i18n';
import { Flex } from '@wordpress/components';

import { GitHubIcon, GitLabIcon } from '../provider-icons';

/**
 * Installed table cell showing the Git provider logo and label.
 *
 * @param {Object} props      Component props.
 * @param {Object} props.item Installed repository record.
 * @return {JSX.Element} The rendered source cell.
 */
export default function SourceCell( { item } ) {
	const isGitLab = ( item.provider ?? 'github' ) === 'gitlab';

	return (
		<Flex
			align="center"
			className="gwp-installed-source"
			gap={ 1 }
			justify="flex-start"
		>
			{ isGitLab ? (
				<GitLabIcon size={ 14 } variant="brand" />
			) : (
				<GitHubIcon size={ 14 } variant="brand" />
			) }
			<span>
				{ isGitLab ? __( 'GitLab', 'git' ) : __( 'GitHub', 'git' ) }
			</span>
		</Flex>
	);
}
