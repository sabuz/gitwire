import { GitHubIcon, GitLabIcon, BitbucketIcon } from './provider-icons';

export const PROVIDER_LABELS = {
	github: 'GitHub',
	gitlab: 'GitLab',
	bitbucket: 'Bitbucket',
};

/**
 * @param {string} provider Provider key.
 * @return {string} Display label, or the raw key when unknown.
 */
export function providerLabel( provider ) {
	return PROVIDER_LABELS[ provider ] ?? provider;
}

/**
 * @param {Object} props           Component props.
 * @param {string} props.provider  Provider key.
 * @param {number} [props.size]    Icon size in px.
 * @param {string} [props.variant] 'brand' for provider colors; 'inherit' for currentColor.
 * @return {JSX.Element} The provider mark. Unknown provider keys use GitHub.
 */
export function ProviderIcon( { provider, size = 13, variant = 'inherit' } ) {
	if ( 'gitlab' === provider ) {
		return <GitLabIcon size={ size } variant={ variant } />;
	}
	if ( 'bitbucket' === provider ) {
		return <BitbucketIcon size={ size } variant={ variant } />;
	}
	return <GitHubIcon size={ size } variant={ variant } />;
}
