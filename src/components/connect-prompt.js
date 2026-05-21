import { __ } from '@wordpress/i18n';
import { Button } from '@wordpress/components';

/**
 * Full-area empty state shown when no Git account is connected.
 *
 * @param {Object}   props           Component props.
 * @param {Function} props.onConnect Callback fired when the Connect button is clicked.
 * @return {JSX.Element} The rendered prompt.
 */
export default function ConnectPrompt( { onConnect } ) {
	return (
		<div
			style={ {
				textAlign: 'center',
				padding: '72px 24px',
			} }
		>
			<img
				alt=""
				aria-hidden="true"
				src={ window.GWP?.disconnected_url }
				style={ {
					width: 64,
					height: 64,
					display: 'block',
					margin: '0 auto 20px',
					opacity: 0.2,
				} }
			/>
			<p
				style={ {
					margin: '0 0 8px',
					fontSize: 16,
					fontWeight: 600,
					color: '#1d2327',
				} }
			>
				{ __( 'Connect your Git account', 'git' ) }
			</p>
			<p
				style={ {
					margin: '0 auto 20px',
					maxWidth: 420,
					color: '#8c959f',
					lineHeight: 1.6,
				} }
			>
				{ __(
					'Connect your GitHub or GitLab account to browse, install, and manage plugins and themes directly from your repositories.',
					'git'
				) }
			</p>
			<Button variant="primary" onClick={ onConnect }>
				{ __( 'Connect', 'git' ) }
			</Button>
		</div>
	);
}
