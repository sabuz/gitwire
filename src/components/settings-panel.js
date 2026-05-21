import { toast } from 'sonner';

import { __, sprintf } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Flex,
	FlexBlock,
	FlexItem,
	Notice,
	Spinner,
	TextControl,
	ToggleControl,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalHeading as Heading,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalSpacer as Spacer,
} from '@wordpress/components';

import * as api from '../api';

/**
 * Settings panel — GitHub connection credentials and smart install toggle.
 *
 * @param {Object}   props                    Component props.
 * @param {Object}   props.settings           Current plugin settings.
 * @param {Object}   props.connection         Cached connection status, if any.
 * @param {Function} props.onSave             Callback fired after settings are saved.
 * @param {Function} props.onConnectionUpdate Callback fired after a connection test.
 * @return {JSX.Element} The rendered settings panel.
 */
export default function SettingsPanel( {
	settings,
	connection,
	onSave,
	onConnectionUpdate,
} ) {
	const [ provider, setProvider ] = useState( settings.provider || 'github' );
	const [ token, setToken ] = useState( settings.token || '' );
	const [ username, setUsername ] = useState( settings.username || '' );
	const [ gitlabToken, setGitlabToken ] = useState(
		settings.gitlab_token || ''
	);
	const [ gitlabUrl, setGitlabUrl ] = useState( settings.gitlab_url || '' );
	const [ smartInstall, setSmartInstall ] = useState(
		settings.smart_install !== false
	);
	const [ saving, setSaving ] = useState( false );
	const [ testing, setTesting ] = useState( false );

	const runTest = async ( overrides = {} ) => {
		setTesting( true );
		try {
			const result = await api.testConnection( overrides );
			onConnectionUpdate( result );
		} catch ( e ) {
			onConnectionUpdate( {
				error: e.message || __( 'Connection failed.', 'git' ),
			} );
		} finally {
			setTesting( false );
		}
	};

	const handleSave = async () => {
		if ( provider === 'gitlab' && ! gitlabToken.trim() ) {
			toast.error(
				__( 'A GitLab Personal Access Token is required.', 'git' )
			);
			return;
		}
		if ( provider === 'github' && ! username.trim() && ! token.trim() ) {
			toast.error(
				__( 'GitHub Username is required when no token is set.', 'git' )
			);
			return;
		}
		setSaving( true );
		try {
			await api.saveSettings( {
				token,
				username,
				smart_install: smartInstall,
				provider,
				gitlab_token: gitlabToken,
				gitlab_url: gitlabUrl,
			} );
			onSave( {
				token,
				username,
				smart_install: smartInstall,
				provider,
				gitlab_token: gitlabToken,
				gitlab_url: gitlabUrl,
			} );
			toast.success( __( 'Settings saved.', 'git' ) );
			if ( provider === 'gitlab' ) {
				await runTest( {
					provider: 'gitlab',
					gitlab_token: gitlabToken,
					gitlab_url: gitlabUrl,
				} );
			} else {
				await runTest( { username, token } );
			}
		} catch ( e ) {
			toast.error( e.message || __( 'Save failed.', 'git' ) );
		} finally {
			setSaving( false );
		}
	};

	const handleDisconnect = async () => {
		setSaving( true );
		try {
			await api.saveSettings( {
				token: '',
				username: '',
				smart_install: smartInstall,
				provider,
				gitlab_token: '',
				gitlab_url: '',
			} );
			setUsername( '' );
			setToken( '' );
			setGitlabToken( '' );
			setGitlabUrl( '' );
			onSave( {
				token: '',
				username: '',
				smart_install: smartInstall,
				provider,
				gitlab_token: '',
				gitlab_url: '',
			} );
			onConnectionUpdate( null );
			toast.success( __( 'Connection removed.', 'git' ) );
		} catch ( e ) {
			toast.error( e.message || __( 'Disconnect failed.', 'git' ) );
		} finally {
			setSaving( false );
		}
	};

	const isConnected =
		provider === 'gitlab'
			? !! settings.gitlab_token
			: !! ( settings.username || settings.token );

	return (
		<Flex
			align="flex-start"
			className="gwp-settings-row"
			gap={ 6 }
			justify="center"
			wrap
		>
			<FlexBlock style={ { minWidth: 300, maxWidth: 540 } }>
				<Card>
					<CardHeader>
						<Heading level={ 4 }>
							{ __( 'Connection', 'git' ) }
						</Heading>
					</CardHeader>
					<CardBody>
						<Flex gap={ 2 } justify="flex-start">
							<Button
								isPressed={ provider === 'github' }
								size="compact"
								variant="secondary"
								onClick={ () => setProvider( 'github' ) }
							>
								{ __( 'GitHub', 'git' ) }
							</Button>
							<Button
								isPressed={ provider === 'gitlab' }
								size="compact"
								variant="secondary"
								onClick={ () => setProvider( 'gitlab' ) }
							>
								{ __( 'GitLab', 'git' ) }
							</Button>
						</Flex>

						<Spacer marginTop={ 4 } />

						{ provider === 'github' && (
							<>
								<TextControl
									__nextHasNoMarginBottom
									help={ __(
										'Your GitHub username or organization. Not required when a token is set.',
										'git'
									) }
									label={ __( 'GitHub Username', 'git' ) }
									placeholder="your-github-username"
									value={ username }
									onChange={ setUsername }
								/>

								<Spacer marginTop={ 4 } />

								<TextControl
									__nextHasNoMarginBottom
									autoComplete="new-password"
									help={
										<>
											{ __(
												'For private repos or to limit which repos appear here.',
												'git'
											) }{ ' ' }
											<a
												href="https://github.com/settings/personal-access-tokens/new"
												rel="noopener noreferrer"
												target="_blank"
											>
												{ __( 'Create token', 'git' ) }
											</a>{ ' ' }
											{ __(
												'— select specific repositories, then grant Metadata: Read-only and Contents: Read-only.',
												'git'
											) }
										</>
									}
									label={
										<>
											{ __(
												'Fine-grained Access Token',
												'git'
											) }{ ' ' }
											<span className="gwp-label-optional">
												{ __( '(Optional)', 'git' ) }
											</span>
										</>
									}
									placeholder="github_pat_xxxxxxxxxxxxxxxxxxxx"
									type="password"
									value={ token }
									onChange={ setToken }
								/>
							</>
						) }

						{ provider === 'gitlab' && (
							<>
								<TextControl
									__nextHasNoMarginBottom
									autoComplete="new-password"
									help={
										<>
											{ __( 'Required.', 'git' ) }{ ' ' }
											<a
												href="https://gitlab.com/-/user_settings/personal_access_tokens"
												rel="noopener noreferrer"
												target="_blank"
											>
												{ __( 'Create token', 'git' ) }
											</a>{ ' ' }
											{ __(
												'— enable Projects: Read and Repository: Read permissions.',
												'git'
											) }
										</>
									}
									label={ __(
										'Personal Access Token',
										'git'
									) }
									placeholder="glpat-xxxxxxxxxxxxxxxxxxxx"
									type="password"
									value={ gitlabToken }
									onChange={ setGitlabToken }
								/>

								<Spacer marginTop={ 4 } />

								<TextControl
									__nextHasNoMarginBottom
									help={ __(
										'Leave blank for gitlab.com. Enter your instance URL for self-hosted GitLab (e.g. https://gitlab.example.com).',
										'git'
									) }
									label={
										<>
											{ __(
												'GitLab Instance URL',
												'git'
											) }{ ' ' }
											<span className="gwp-label-optional">
												{ __( '(Optional)', 'git' ) }
											</span>
										</>
									}
									placeholder="https://gitlab.com"
									value={ gitlabUrl }
									onChange={ setGitlabUrl }
								/>
							</>
						) }

						<Spacer marginTop={ 5 } />

						<div className="gwp-smart-install-wrap">
							<ToggleControl
								__nextHasNoMarginBottom
								checked={ smartInstall }
								help={ __(
									'Only allow installing repositories detected as a WordPress plugin or theme.',
									'git'
								) }
								label={
									<>
										<strong>
											{ __( 'Smart Install', 'git' ) }
										</strong>{ ' ' }
										<span className="gwp-badge-recommended">
											{ __( 'Recommended', 'git' ) }
										</span>
									</>
								}
								onChange={ setSmartInstall }
							/>
						</div>

						<Spacer marginTop={ 5 } />

						<Flex gap={ 3 } justify="flex-start">
							<Button
								disabled={ saving || testing }
								isBusy={ saving }
								variant="primary"
								onClick={ handleSave }
							>
								{ __( 'Save Settings', 'git' ) }
							</Button>
							<Button
								disabled={ saving || testing }
								isBusy={ testing }
								variant="secondary"
								onClick={ () =>
									provider === 'gitlab'
										? runTest( {
												provider: 'gitlab',
												gitlab_token: gitlabToken,
												gitlab_url: gitlabUrl,
										  } )
										: runTest( { username, token } )
								}
							>
								{ __( 'Test Connection', 'git' ) }
							</Button>
							{ isConnected && (
								<Button
									disabled={ saving || testing }
									isDestructive
									variant="secondary"
									onClick={ handleDisconnect }
								>
									{ __( 'Disconnect', 'git' ) }
								</Button>
							) }
						</Flex>
					</CardBody>
				</Card>
			</FlexBlock>

			<FlexItem style={ { width: 260, flexShrink: 0 } }>
				<ConnectionStatus
					connection={ connection }
					testing={ testing }
				/>
			</FlexItem>
		</Flex>
	);
}

/**
 * Connection status card showing authentication state and API rate limit.
 *
 * @param {Object}      props            Component props.
 * @param {Object|null} props.connection Cached connection data, if any.
 * @param {boolean}     props.testing    Whether a connection test is in progress.
 * @return {JSX.Element} The rendered connection status card.
 */
function ConnectionStatus( { connection, testing } ) {
	if ( testing ) {
		return (
			<Card>
				<CardBody
					style={ { textAlign: 'center', padding: '32px 16px' } }
				>
					<Spinner />
					<p
						style={ {
							marginTop: 8,
							color: '#757575',
							fontSize: 13,
						} }
					>
						{ __( 'Checking connection…', 'git' ) }
					</p>
				</CardBody>
			</Card>
		);
	}

	if ( ! connection ) {
		return (
			<Card>
				<CardBody
					style={ {
						textAlign: 'center',
						padding: '32px 16px',
						color: '#8c959f',
					} }
				>
					<img
						alt=""
						aria-hidden="true"
						src={ window.GWP?.disconnected_url }
						style={ {
							width: 32,
							height: 32,
							display: 'block',
							margin: '0 auto 8px',
							opacity: 0.35,
						} }
					/>
					<p style={ { margin: 0, fontSize: 12, lineHeight: 1.5 } }>
						{ __(
							'Save your settings and click "Test Connection" to verify.',
							'git'
						) }
					</p>
				</CardBody>
			</Card>
		);
	}

	if ( connection.error ) {
		return (
			<Card>
				<CardBody
					style={ {
						textAlign: 'center',
						padding: '32px 16px',
					} }
				>
					<span
						className="dashicons dashicons-warning"
						style={ {
							fontSize: 32,
							width: 'auto',
							height: 'auto',
							display: 'block',
							margin: '0 auto 8px',
							color: '#cf222e',
						} }
					/>
					<p
						style={ {
							margin: 0,
							fontSize: 12,
							lineHeight: 1.5,
							color: '#cf222e',
						} }
					>
						{ connection.error }
					</p>
				</CardBody>
			</Card>
		);
	}

	const {
		login,
		name,
		avatar_url,
		authenticated,
		rate_limit,
		rate_remaining,
		rate_reset,
		checked_at,
		provider: connectionProvider,
	} = connection;

	const isGitLab = connectionProvider === 'gitlab';

	const pct =
		rate_limit > 0
			? Math.round( ( rate_remaining / rate_limit ) * 100 )
			: 0;
	let barColor = '#cf222e';
	if ( pct > 50 ) {
		barColor = '#4ac26b';
	} else if ( pct > 20 ) {
		barColor = '#e3b341';
	}

	let rateNote = __( 'Resets in about an hour.', 'git' );
	if ( rate_limit === 60 ) {
		rateNote = __(
			"Unauthenticated limit — shared by your server's IP. Add a token for 5,000/hour.",
			'git'
		);
	} else if ( rate_reset ) {
		const countdown = humanDiff( rate_reset );
		if ( countdown ) {
			rateNote = sprintf(
				/* translators: %s: time until reset (e.g. "5m 30s") */
				__( 'Resets in %s.', 'git' ),
				countdown
			);
		}
	}

	return (
		<Card>
			<CardBody>
				{ authenticated && avatar_url && (
					<div style={ { textAlign: 'center', marginBottom: 14 } }>
						<img
							alt={ login }
							src={ avatar_url }
							style={ {
								width: 52,
								height: 52,
								borderRadius: '50%',
								display: 'block',
								margin: '0 auto 8px',
							} }
						/>
						<div style={ { fontWeight: 700 } }>
							{ name || login }
						</div>
						<div style={ { fontSize: 12, color: '#57606a' } }>
							@{ login }
						</div>
						<span className="gwp-conn-badge gwp-conn-badge--ok">
							<span className="dashicons dashicons-yes-alt" />
							{ __( 'Authenticated', 'git' ) }
						</span>
					</div>
				) }

				{ authenticated && ! avatar_url && (
					<div style={ { textAlign: 'center', marginBottom: 14 } }>
						<span className="gwp-conn-badge gwp-conn-badge--ok">
							<span className="dashicons dashicons-yes-alt" />
							{ __( 'Authenticated', 'git' ) }
						</span>
					</div>
				) }

				{ ! authenticated && (
					<div style={ { textAlign: 'center', marginBottom: 14 } }>
						<span className="gwp-conn-badge gwp-conn-badge--warn">
							<span className="dashicons dashicons-warning" />
							{ __( 'No token — public only', 'git' ) }
						</span>
					</div>
				) }

				<hr className="gwp-divider" />

				{ ! isGitLab && (
					<div style={ { fontSize: 12 } }>
						<Flex
							justify="space-between"
							style={ { marginBottom: 6 } }
						>
							<span style={ { color: '#24292f' } }>
								{ __( 'API Usage', 'git' ) }
							</span>
							<strong>
								{ rate_remaining?.toLocaleString() } /{ ' ' }
								{ rate_limit?.toLocaleString() }
							</strong>
						</Flex>
						<div className="gwp-rate-track">
							<div
								className="gwp-rate-fill"
								style={ {
									width: `${ pct }%`,
									background: barColor,
								} }
							/>
						</div>
						<p className="gwp-rate-note">{ rateNote }</p>
					</div>
				) }

				{ checked_at && (
					<p
						className="gwp-rate-note gwp-rate-note--checked"
						style={ { fontSize: 12 } }
					>
						{ sprintf(
							/* translators: %s: relative time (e.g. "5m ago") */
							__( 'Last checked %s', 'git' ),
							unixTimeAgo( checked_at )
						) }
					</p>
				) }
			</CardBody>
		</Card>
	);
}

/**
 * Formats a Unix timestamp as a human-readable countdown string.
 *
 * @param {number} ts Unix timestamp.
 * @return {string} Human-readable time string.
 */
function humanDiff( ts ) {
	const s = ts - Math.floor( Date.now() / 1000 );
	if ( s <= 0 ) {
		return null;
	}
	const m = Math.floor( s / 60 );
	return m > 0 ? `${ m }m ${ s % 60 }s` : `${ s }s`;
}

/**
 * Formats a past Unix timestamp as a human-readable "X ago" string.
 *
 * @param {number} ts Unix timestamp in seconds.
 * @return {string} Human-readable relative time.
 */
function unixTimeAgo( ts ) {
	const s = Math.floor( Date.now() / 1000 ) - ts;
	if ( s < 60 ) {
		return 'just now';
	}
	const m = Math.floor( s / 60 );
	if ( m < 60 ) {
		return `${ m }m ago`;
	}
	const h = Math.floor( m / 60 );
	if ( h < 24 ) {
		return `${ h }h ago`;
	}
	return `${ Math.floor( h / 24 ) }d ago`;
}
