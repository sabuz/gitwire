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
	const [ token, setToken ] = useState( settings.token || '' );
	const [ username, setUsername ] = useState( settings.username || '' );
	const [ smartInstall, setSmartInstall ] = useState(
		settings.smart_install !== false
	);
	const [ saving, setSaving ] = useState( false );
	const [ testing, setTesting ] = useState( false );

	const runTest = async () => {
		setTesting( true );
		try {
			const result = await api.testConnection();
			onConnectionUpdate( result );
		} catch ( e ) {
			onConnectionUpdate( {
				error: e.message || __( 'Connection failed.', 'ghwp' ),
			} );
		} finally {
			setTesting( false );
		}
	};

	const handleSave = async () => {
		if ( ! username.trim() ) {
			toast.error( __( 'GitHub Username is required.', 'ghwp' ) );
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
			toast.success( __( 'Settings saved.', 'ghwp' ) );
			await runTest();
		} catch ( e ) {
			toast.error( e.message || __( 'Save failed.', 'ghwp' ) );
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
						<Heading level={ 4 }>
							{ __( 'GitHub Connection', 'ghwp' ) }
						</Heading>
					</CardHeader>
					<CardBody>
						<TextControl
							__nextHasNoMarginBottom
							help={ __(
								'Your GitHub username or organization name.',
								'ghwp'
							) }
							label={ __( 'GitHub Username', 'ghwp' ) }
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
										'Required only for private repositories. Create one at',
										'ghwp'
									) }{ ' ' }
									<a
										href="https://github.com/settings/tokens/new"
										rel="noopener noreferrer"
										target="_blank"
									>
										github.com/settings/tokens
									</a>{ ' ' }
									{ sprintf(
										/* translators: %s: code element showing "repo" */
										__( 'with the %s scope.', 'ghwp' ),
										'repo'
									) }
								</>
							}
							label={
								<>
									{ __( 'Personal Access Token', 'ghwp' ) }{ ' ' }
									<span className="ghwp-label-optional">
										{ __( '(Optional)', 'ghwp' ) }
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
								help={ __(
									'Only allow installing repositories detected as a WordPress plugin or theme.',
									'ghwp'
								) }
								label={
									<>
										<strong>
											{ __( 'Smart Install', 'ghwp' ) }
										</strong>{ ' ' }
										<span className="ghwp-badge-recommended">
											{ __( 'Recommended', 'ghwp' ) }
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
								{ __( 'Save Settings', 'ghwp' ) }
							</Button>
							<Button
								disabled={ saving || testing }
								isBusy={ testing }
								variant="secondary"
								onClick={ runTest }
							>
								{ __( 'Test Connection', 'ghwp' ) }
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
						{ __( 'Checking connection…', 'ghwp' ) }
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
						{ __(
							'Save your settings and click "Test Connection" to verify.',
							'ghwp'
						) }
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

	let rateNote = __( 'Resets in about an hour.', 'ghwp' );
	if ( rate_limit === 60 ) {
		rateNote = __(
			"Unauthenticated limit — shared by your server's IP. Add a token for 5,000/hour.",
			'ghwp'
		);
	} else if ( rate_reset ) {
		const countdown = humanDiff( rate_reset );
		if ( countdown ) {
			rateNote = sprintf(
				/* translators: %s: time until reset (e.g. "5m 30s") */
				__( 'Resets in %s.', 'ghwp' ),
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
						<span className="ghwp-conn-badge ghwp-conn-badge--ok">
							<span className="dashicons dashicons-yes-alt" />
							{ __( 'Authenticated', 'ghwp' ) }
						</span>
					</div>
				) }

				{ ! authenticated && (
					<div style={ { textAlign: 'center', marginBottom: 14 } }>
						<span className="ghwp-conn-badge ghwp-conn-badge--warn">
							<span className="dashicons dashicons-warning" />
							{ __( 'No token — public only', 'ghwp' ) }
						</span>
					</div>
				) }

				<hr className="ghwp-divider" />

				<div style={ { fontSize: 12 } }>
					<Flex justify="space-between" style={ { marginBottom: 6 } }>
						<span style={ { color: '#24292f' } }>
							{ __( 'API Usage', 'ghwp' ) }
						</span>
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
							{ sprintf(
								/* translators: %s: relative time (e.g. "5m ago") */
								__( 'Last checked %s', 'ghwp' ),
								unixTimeAgo( checked_at )
							) }
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
