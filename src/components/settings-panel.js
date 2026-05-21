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
	const [ token, setToken ] = useState( settings.token || '' );
	const [ username, setUsername ] = useState( settings.username || '' );
	const [ smartInstall, setSmartInstall ] = useState(
		settings.smart_install !== false
	);
	const [ saving, setSaving ] = useState( false );
	const [ testing, setTesting ] = useState( false );
	const [ notice, setNotice ] = useState( null );

	const runTest = async () => {
		setTesting( true );
		try {
			const result = await api.testConnection();
			onConnectionUpdate( result );
		} catch ( e ) {
			onConnectionUpdate( { error: e.message || 'Connection failed.' } );
		} finally {
			setTesting( false );
		}
	};

	const handleSave = async () => {
		if ( ! username.trim() ) {
			setNotice( {
				status: 'error',
				message: 'GitHub Username is required.',
			} );
			return;
		}
		setSaving( true );
		try {
			await api.saveSettings( {
				token,
				username,
				smart_install: smartInstall,
			} );
			onSave( { token, username, smart_install: smartInstall } );
			setNotice( { status: 'success', message: 'Settings saved.' } );
			await runTest();
		} catch ( e ) {
			setNotice( {
				status: 'error',
				message: e.message || 'Save failed.',
			} );
		} finally {
			setSaving( false );
		}
	};

	return (
		<Flex
			align="flex-start"
			className="ghwp-settings-row"
			gap={ 6 }
			justify="center"
			wrap
		>
			<FlexBlock style={ { minWidth: 300, maxWidth: 540 } }>
				<Card>
					<CardHeader>
						<Heading level={ 4 }>GitHub Connection</Heading>
					</CardHeader>
					<CardBody>
						{ notice && (
							<Notice
								isDismissible
								status={ notice.status }
								onRemove={ () => setNotice( null ) }
							>
								{ notice.message }
							</Notice>
						) }

						<TextControl
							__nextHasNoMarginBottom
							help="Your GitHub username or organization name."
							label="GitHub Username"
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
									Required only for private repositories.
									Create one at{ ' ' }
									<a
										href="https://github.com/settings/tokens/new"
										rel="noopener noreferrer"
										target="_blank"
									>
										github.com/settings/tokens
									</a>{ ' ' }
									with the <code>repo</code> scope.
								</>
							}
							label={
								<>
									Personal Access Token{ ' ' }
									<span className="ghwp-label-optional">
										(Optional)
									</span>
								</>
							}
							placeholder="ghp_xxxxxxxxxxxxxxxxxxxx"
							type="password"
							value={ token }
							onChange={ setToken }
						/>

						<Spacer marginTop={ 5 } />

						<div className="ghwp-smart-install-wrap">
							<ToggleControl
								__nextHasNoMarginBottom
								checked={ smartInstall }
								help="Only allow installing repositories detected as a WordPress plugin or theme."
								label={
									<>
										<strong>Smart Install</strong>{ ' ' }
										<span className="ghwp-badge-recommended">
											Recommended
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
								Save Settings
							</Button>
							<Button
								disabled={ saving || testing }
								isBusy={ testing }
								variant="secondary"
								onClick={ runTest }
							>
								Test Connection
							</Button>
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
						Checking connection…
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
					<span
						className="dashicons dashicons-randomize"
						style={ {
							fontSize: 32,
							display: 'block',
							margin: '0 auto 8px',
							opacity: 0.35,
						} }
					/>
					<p style={ { margin: 0, fontSize: 12, lineHeight: 1.5 } }>
						Save your settings and click &quot;Test Connection&quot;
						to verify.
					</p>
				</CardBody>
			</Card>
		);
	}

	if ( connection.error ) {
		return (
			<Card>
				<CardBody>
					<Notice isDismissible={ false } status="error">
						{ connection.error }
					</Notice>
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
	} = connection;
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

	let rateNote = 'Resets in about an hour.';
	if ( rate_limit === 60 ) {
		rateNote =
			"Unauthenticated limit — shared by your server's IP. Add a token for 5,000/hour.";
	} else if ( rate_reset ) {
		const countdown = humanDiff( rate_reset );
		if ( countdown ) {
			rateNote = `Resets in ${ countdown }.`;
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
						<span className="ghwp-conn-badge ghwp-conn-badge--ok">
							<span className="dashicons dashicons-yes-alt" />
							Authenticated
						</span>
					</div>
				) }

				{ ! authenticated && (
					<div style={ { textAlign: 'center', marginBottom: 14 } }>
						<span className="ghwp-conn-badge ghwp-conn-badge--warn">
							<span className="dashicons dashicons-warning" />
							No token — public only
						</span>
					</div>
				) }

				<hr className="ghwp-divider" />

				<div style={ { fontSize: 12 } }>
					<Flex justify="space-between" style={ { marginBottom: 6 } }>
						<span style={ { color: '#24292f' } }>API Usage</span>
						<strong>
							{ rate_remaining?.toLocaleString() } /{ ' ' }
							{ rate_limit?.toLocaleString() }
						</strong>
					</Flex>
					<div className="ghwp-rate-track">
						<div
							className="ghwp-rate-fill"
							style={ {
								width: `${ pct }%`,
								background: barColor,
							} }
						/>
					</div>
					<p className="ghwp-rate-note">{ rateNote }</p>
					{ checked_at && (
						<p className="ghwp-rate-note ghwp-rate-note--checked">
							Last checked { unixTimeAgo( checked_at ) }
						</p>
					) }
				</div>
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
