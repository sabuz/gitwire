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
 * Settings panel — one card per provider plus a Smart Install card.
 *
 * @param {Object}   props                    Component props.
 * @param {Object}   props.settings           Saved plugin settings.
 * @param {Object}   props.connection         Per-provider connection cache: { github, gitlab }.
 * @param {Function} props.onSave             Called with updated settings after save.
 * @param {Function} props.onConnectionUpdate Called with (provider, data) after a test/disconnect.
 * @return {JSX.Element} The rendered settings panel.
 */
export default function SettingsPanel( {
	settings,
	connection,
	onSave,
	onConnectionUpdate,
} ) {
	const [ smartInstall, setSmartInstall ] = useState(
		settings.smart_install !== false
	);
	const [ savingSi, setSavingSi ] = useState( false );

	const handleSmartInstallChange = async ( newVal ) => {
		setSmartInstall( newVal );
		setSavingSi( true );
		try {
			await api.saveSettings( {
				token: settings.token,
				username: settings.username,
				gitlab_token: settings.gitlab_token,
				gitlab_url: settings.gitlab_url,
				smart_install: newVal,
			} );
			onSave( { ...settings, smart_install: newVal } );
			toast.success( __( 'Settings saved.', 'git' ) );
		} catch ( e ) {
			toast.error( e.message || __( 'Save failed.', 'git' ) );
			setSmartInstall( ! newVal );
		} finally {
			setSavingSi( false );
		}
	};

	return (
		<div
			className="gwp-settings-panels"
			style={ { maxWidth: 540, margin: '0 auto' } }
		>
			<GitHubCard
				connection={ connection?.github ?? null }
				settings={ settings }
				smartInstall={ smartInstall }
				onConnectionUpdate={ ( data ) =>
					onConnectionUpdate( 'github', data )
				}
				onSave={ onSave }
			/>

			<Spacer marginTop={ 4 } />

			<GitLabCard
				connection={ connection?.gitlab ?? null }
				settings={ settings }
				smartInstall={ smartInstall }
				onConnectionUpdate={ ( data ) =>
					onConnectionUpdate( 'gitlab', data )
				}
				onSave={ onSave }
			/>

			<Spacer marginTop={ 4 } />

			<Card>
				<CardHeader>
					<Heading level={ 4 }>
						{ __( 'Miscellaneous', 'git' ) }
					</Heading>
				</CardHeader>
				<CardBody>
					<ToggleControl
						__nextHasNoMarginBottom
						checked={ smartInstall }
						disabled={ savingSi }
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
						onChange={ handleSmartInstallChange }
					/>
				</CardBody>
			</Card>
		</div>
	);
}

/**
 * GitHub provider card — shows a connect form when disconnected, profile when connected.
 *
 * @param {Object}   props                    Component props.
 * @param {Object}   props.settings           Saved plugin settings.
 * @param {Object}   props.connection         Cached GitHub connection data, or null.
 * @param {boolean}  props.smartInstall       Current smart install value (preserved on save).
 * @param {Function} props.onSave             Called with updated settings after save.
 * @param {Function} props.onConnectionUpdate Called with connection data (or null) after test.
 * @return {JSX.Element} The rendered card.
 */
function GitHubCard( {
	settings,
	connection,
	smartInstall,
	onSave,
	onConnectionUpdate,
} ) {
	const [ token, setToken ] = useState( settings.token || '' );
	const [ username, setUsername ] = useState( settings.username || '' );
	const [ saving, setSaving ] = useState( false );
	const [ testing, setTesting ] = useState( false );

	const isConnected = !! ( settings.token || settings.username );

	const handleConnect = async () => {
		if ( ! token.trim() && ! username.trim() ) {
			toast.error( __( 'Enter a username or access token.', 'git' ) );
			return;
		}
		setSaving( true );
		try {
			await api.saveSettings( {
				token,
				username,
				gitlab_token: settings.gitlab_token,
				gitlab_url: settings.gitlab_url,
				smart_install: smartInstall,
			} );
			const newSettings = { ...settings, token, username };
			onSave( newSettings );
			setTesting( true );
			try {
				const result = await api.testConnection( {
					provider: 'github',
					token,
					username,
				} );
				onConnectionUpdate( result );
				toast.success( __( 'GitHub connected.', 'git' ) );
			} catch ( e ) {
				onConnectionUpdate( {
					provider: 'github',
					error: e.message || __( 'Connection test failed.', 'git' ),
				} );
				toast.error(
					e.message || __( 'Connection test failed.', 'git' )
				);
			} finally {
				setTesting( false );
			}
		} catch ( e ) {
			toast.error( e.message || __( 'Save failed.', 'git' ) );
		} finally {
			setSaving( false );
		}
	};

	const handleSignOut = async () => {
		setSaving( true );
		try {
			await api.saveSettings( {
				token: '',
				username: '',
				gitlab_token: settings.gitlab_token,
				gitlab_url: settings.gitlab_url,
				smart_install: smartInstall,
			} );
			setToken( '' );
			setUsername( '' );
			onSave( { ...settings, token: '', username: '' } );
			onConnectionUpdate( null );
			toast.success( __( 'GitHub disconnected.', 'git' ) );
		} catch ( e ) {
			toast.error( e.message || __( 'Disconnect failed.', 'git' ) );
		} finally {
			setSaving( false );
		}
	};

	return (
		<Card>
			<CardHeader>
				<Flex align="center" gap={ 2 }>
					<FlexItem>
						<svg
							aria-hidden="true"
							fill="currentColor"
							height="20"
							viewBox="0 0 16 16"
							width="20"
						>
							<path d="M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 5.47 7.59.4.07.55-.17.55-.38 0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13-.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.51-1.07-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.82-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82.64-.18 1.32-.27 2-.27.68 0 1.36.09 2 .27 1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.51.56.82 1.27.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A8.013 8.013 0 0016 8c0-4.42-3.58-8-8-8z" />
						</svg>
					</FlexItem>
					<FlexBlock>
						<Heading level={ 4 }>{ __( 'GitHub', 'git' ) }</Heading>
					</FlexBlock>
					{ isConnected && connection && ! connection.error && (
						<FlexItem>
							<span className="gwp-conn-badge gwp-conn-badge--ok">
								<span className="dashicons dashicons-yes-alt" />
								{ connection.authenticated
									? __( 'Connected', 'git' )
									: __( 'Public only', 'git' ) }
							</span>
						</FlexItem>
					) }
				</Flex>
			</CardHeader>
			<CardBody>
				{ testing ? (
					<div style={ { textAlign: 'center', padding: '24px 0' } }>
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
					</div>
				) : isConnected ? (
					<ConnectedProfile
						connection={ connection }
						isBusy={ saving }
						signOutLabel={ __( 'Sign Out', 'git' ) }
						onSignOut={ handleSignOut }
					/>
				) : (
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
										'For private repos or to raise the rate limit.',
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
									{ __( 'Fine-grained Access Token', 'git' ) }{ ' ' }
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

						<Spacer marginTop={ 5 } />

						<Button
							disabled={ saving }
							isBusy={ saving }
							variant="primary"
							onClick={ handleConnect }
						>
							{ __( 'Connect GitHub', 'git' ) }
						</Button>
					</>
				) }
			</CardBody>
		</Card>
	);
}

/**
 * GitLab provider card — shows a connect form when disconnected, profile when connected.
 *
 * @param {Object}   props                    Component props.
 * @param {Object}   props.settings           Saved plugin settings.
 * @param {Object}   props.connection         Cached GitLab connection data, or null.
 * @param {boolean}  props.smartInstall       Current smart install value (preserved on save).
 * @param {Function} props.onSave             Called with updated settings after save.
 * @param {Function} props.onConnectionUpdate Called with connection data (or null) after test.
 * @return {JSX.Element} The rendered card.
 */
function GitLabCard( {
	settings,
	connection,
	smartInstall,
	onSave,
	onConnectionUpdate,
} ) {
	const [ gitlabToken, setGitlabToken ] = useState(
		settings.gitlab_token || ''
	);
	const [ gitlabUrl, setGitlabUrl ] = useState( settings.gitlab_url || '' );
	const [ saving, setSaving ] = useState( false );
	const [ testing, setTesting ] = useState( false );

	const isConnected = !! settings.gitlab_token;

	const handleConnect = async () => {
		if ( ! gitlabToken.trim() ) {
			toast.error(
				__( 'A GitLab Personal Access Token is required.', 'git' )
			);
			return;
		}
		setSaving( true );
		try {
			await api.saveSettings( {
				token: settings.token,
				username: settings.username,
				gitlab_token: gitlabToken,
				gitlab_url: gitlabUrl,
				smart_install: smartInstall,
			} );
			onSave( {
				...settings,
				gitlab_token: gitlabToken,
				gitlab_url: gitlabUrl,
			} );
			setTesting( true );
			try {
				const result = await api.testConnection( {
					provider: 'gitlab',
					gitlab_token: gitlabToken,
					gitlab_url: gitlabUrl,
				} );
				onConnectionUpdate( result );
				toast.success( __( 'GitLab connected.', 'git' ) );
			} catch ( e ) {
				onConnectionUpdate( {
					provider: 'gitlab',
					error: e.message || __( 'Connection test failed.', 'git' ),
				} );
				toast.error(
					e.message || __( 'Connection test failed.', 'git' )
				);
			} finally {
				setTesting( false );
			}
		} catch ( e ) {
			toast.error( e.message || __( 'Save failed.', 'git' ) );
		} finally {
			setSaving( false );
		}
	};

	const handleSignOut = async () => {
		setSaving( true );
		try {
			await api.saveSettings( {
				token: settings.token,
				username: settings.username,
				gitlab_token: '',
				gitlab_url: '',
				smart_install: smartInstall,
			} );
			setGitlabToken( '' );
			setGitlabUrl( '' );
			onSave( { ...settings, gitlab_token: '', gitlab_url: '' } );
			onConnectionUpdate( null );
			toast.success( __( 'GitLab disconnected.', 'git' ) );
		} catch ( e ) {
			toast.error( e.message || __( 'Disconnect failed.', 'git' ) );
		} finally {
			setSaving( false );
		}
	};

	return (
		<Card>
			<CardHeader>
				<Flex align="center" gap={ 2 }>
					<FlexItem>
						<svg
							aria-hidden="true"
							fill="#e24329"
							height="20"
							viewBox="0 0 16 16"
							width="20"
						>
							<path d="M15.97 9.058l-.895-2.756L13.3.842a.382.382 0 0 0-.724 0L10.8 6.302H5.2L3.424.842a.382.382 0 0 0-.724 0L.925 6.302.03 9.058a.762.762 0 0 0 .277.852L8 15.37l7.693-5.46a.762.762 0 0 0 .277-.852z" />
						</svg>
					</FlexItem>
					<FlexBlock>
						<Heading level={ 4 }>{ __( 'GitLab', 'git' ) }</Heading>
					</FlexBlock>
					{ isConnected && connection && ! connection.error && (
						<FlexItem>
							<span className="gwp-conn-badge gwp-conn-badge--ok">
								<span className="dashicons dashicons-yes-alt" />
								{ __( 'Connected', 'git' ) }
							</span>
						</FlexItem>
					) }
				</Flex>
			</CardHeader>
			<CardBody>
				{ testing ? (
					<div style={ { textAlign: 'center', padding: '24px 0' } }>
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
					</div>
				) : isConnected ? (
					<ConnectedProfile
						connection={ connection }
						isBusy={ saving }
						signOutLabel={ __( 'Sign Out', 'git' ) }
						onSignOut={ handleSignOut }
					/>
				) : (
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
										'— enable read_api and read_repository.',
										'git'
									) }
								</>
							}
							label={ __( 'Personal Access Token', 'git' ) }
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
									{ __( 'GitLab Instance URL', 'git' ) }{ ' ' }
									<span className="gwp-label-optional">
										{ __( '(Optional)', 'git' ) }
									</span>
								</>
							}
							placeholder="https://gitlab.com"
							value={ gitlabUrl }
							onChange={ setGitlabUrl }
						/>

						<Spacer marginTop={ 5 } />

						<Button
							disabled={ saving }
							isBusy={ saving }
							variant="primary"
							onClick={ handleConnect }
						>
							{ __( 'Connect GitLab', 'git' ) }
						</Button>
					</>
				) }
			</CardBody>
		</Card>
	);
}

/**
 * Shared connected-state profile block used by both provider cards.
 *
 * @param {Object}   props              Component props.
 * @param {Object}   props.connection   Connection cache data for this provider.
 * @param {boolean}  props.isBusy       Whether a sign-out request is in progress.
 * @param {string}   props.signOutLabel Label for the sign-out button.
 * @param {Function} props.onSignOut    Sign-out callback.
 * @return {JSX.Element} The rendered profile block.
 */
function ConnectedProfile( { connection, isBusy, signOutLabel, onSignOut } ) {
	if ( ! connection ) {
		return (
			<Flex align="center" gap={ 3 } justify="space-between">
				<FlexItem>
					<span className="gwp-conn-badge gwp-conn-badge--ok">
						<span className="dashicons dashicons-yes-alt" />
						{ __( 'Credentials saved', 'git' ) }
					</span>
				</FlexItem>
				<FlexItem>
					<Button
						disabled={ isBusy }
						isBusy={ isBusy }
						isDestructive
						variant="secondary"
						onClick={ onSignOut }
					>
						{ signOutLabel }
					</Button>
				</FlexItem>
			</Flex>
		);
	}

	if ( connection.error ) {
		return (
			<>
				<p
					style={ {
						color: '#cf222e',
						fontSize: 13,
						margin: '0 0 12px',
					} }
				>
					<span
						className="dashicons dashicons-warning"
						style={ { verticalAlign: 'middle', marginRight: 4 } }
					/>
					{ connection.error }
				</p>
				<Button
					disabled={ isBusy }
					isBusy={ isBusy }
					isDestructive
					variant="secondary"
					onClick={ onSignOut }
				>
					{ signOutLabel }
				</Button>
			</>
		);
	}

	const isGitHub = connection.provider === 'github';
	const pct =
		isGitHub && connection.rate_limit > 0
			? Math.round(
					( connection.rate_remaining / connection.rate_limit ) * 100
			  )
			: 0;
	let barColor = '#cf222e';
	if ( pct > 50 ) {
		barColor = '#4ac26b';
	} else if ( pct > 20 ) {
		barColor = '#e3b341';
	}

	let rateNote = __( 'Resets in about an hour.', 'git' );
	if ( isGitHub ) {
		if ( connection.rate_limit === 60 ) {
			rateNote = __(
				"Unauthenticated limit — shared by your server's IP. Add a token for 5,000/hour.",
				'git'
			);
		} else if ( connection.rate_reset ) {
			const countdown = humanDiff( connection.rate_reset );
			if ( countdown ) {
				rateNote = sprintf(
					/* translators: %s: time until reset */
					__( 'Resets in %s.', 'git' ),
					countdown
				);
			}
		}
	}

	return (
		<>
			<Flex align="center" gap={ 3 }>
				{ connection.avatar_url && (
					<FlexItem>
						<img
							alt={ connection.login }
							src={ connection.avatar_url }
							style={ {
								width: 44,
								height: 44,
								borderRadius: '50%',
								display: 'block',
							} }
						/>
					</FlexItem>
				) }
				<FlexBlock>
					{ ( connection.name || connection.login ) && (
						<div style={ { fontWeight: 700, fontSize: 14 } }>
							{ connection.name || connection.login }
						</div>
					) }
					{ connection.login && (
						<div style={ { fontSize: 12, color: '#57606a' } }>
							@{ connection.login }
						</div>
					) }
					{ connection.checked_at && (
						<div
							style={ {
								fontSize: 11,
								color: '#8c959f',
								marginTop: 2,
							} }
						>
							{ sprintf(
								/* translators: %s: relative time */
								__( 'Last checked %s', 'git' ),
								unixTimeAgo( connection.checked_at )
							) }
						</div>
					) }
				</FlexBlock>
				<FlexItem>
					<Button
						disabled={ isBusy }
						isBusy={ isBusy }
						isDestructive
						variant="secondary"
						onClick={ onSignOut }
					>
						{ signOutLabel }
					</Button>
				</FlexItem>
			</Flex>

			{ isGitHub && (
				<>
					<hr
						className="gwp-divider"
						style={ { margin: '12px 0' } }
					/>
					<div style={ { fontSize: 12 } }>
						<Flex
							justify="space-between"
							style={ { marginBottom: 6 } }
						>
							<span style={ { color: '#24292f' } }>
								{ __( 'API Usage', 'git' ) }
							</span>
							<strong>
								{ connection.rate_remaining?.toLocaleString() }{ ' ' }
								/ { connection.rate_limit?.toLocaleString() }
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
				</>
			) }
		</>
	);
}

function humanDiff( ts ) {
	const s = ts - Math.floor( Date.now() / 1000 );
	if ( s <= 0 ) {
		return null;
	}
	const m = Math.floor( s / 60 );
	return m > 0 ? `${ m }m ${ s % 60 }s` : `${ s }s`;
}

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
