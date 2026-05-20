import { useState } from '@wordpress/element';
import {
	Card,
	CardBody,
	CardHeader,
	Flex,
	FlexBlock,
	FlexItem,
	TextControl,
	CheckboxControl,
	Button,
	Notice,
	Spinner,
	__experimentalHeading as Heading,
	__experimentalSpacer as Spacer,
} from '@wordpress/components';
import * as api from '../api';

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
			justify="center"
			gap={ 6 }
			wrap
			className="ghwp-settings-row"
		>
			<FlexBlock style={ { minWidth: 300, maxWidth: 540 } }>
				<Card>
					<CardHeader>
						<Heading level={ 4 }>GitHub Connection</Heading>
					</CardHeader>
					<CardBody>
						{ notice && (
							<Notice
								status={ notice.status }
								isDismissible
								onRemove={ () => setNotice( null ) }
							>
								{ notice.message }
							</Notice>
						) }

						<TextControl
							label="GitHub Username"
							value={ username }
							onChange={ setUsername }
							placeholder="your-github-username"
							help="Your GitHub username or organization name."
							__nextHasNoMarginBottom
						/>

						<Spacer marginTop={ 4 } />

						<TextControl
							label={
								<>
									Personal Access Token{ ' ' }
									<span className="ghwp-label-optional">
										(Optional)
									</span>
								</>
							}
							type="password"
							value={ token }
							onChange={ setToken }
							placeholder="ghp_xxxxxxxxxxxxxxxxxxxx"
							autoComplete="new-password"
							help={
								<>
									Required only for private repositories.
									Create one at{ ' ' }
									<a
										href="https://github.com/settings/tokens/new"
										target="_blank"
										rel="noopener noreferrer"
									>
										github.com/settings/tokens
									</a>{ ' ' }
									with the <code>repo</code> scope.
								</>
							}
							__nextHasNoMarginBottom
						/>

						<Spacer marginTop={ 4 } />

						<div className="ghwp-smart-install-wrap">
							<CheckboxControl
								label={
									<>
										<strong>Smart Install</strong>{ ' ' }
										<span className="ghwp-badge-recommended">
											Recommended
										</span>
									</>
								}
								checked={ smartInstall }
								onChange={ setSmartInstall }
								help="Only allow installing repositories detected as a WordPress plugin or theme."
								__nextHasNoMarginBottom
							/>
						</div>

						<Spacer marginTop={ 5 } />

						<Flex gap={ 3 }>
							<Button
								variant="primary"
								onClick={ handleSave }
								isBusy={ saving }
								disabled={ saving || testing }
							>
								Save Settings
							</Button>
							<Button
								variant="secondary"
								onClick={ runTest }
								isBusy={ testing }
								disabled={ saving || testing }
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

// ---------------------------------------------------------------------------

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
						Save your settings and click "Test Connection" to
						verify.
					</p>
				</CardBody>
			</Card>
		);
	}

	if ( connection.error ) {
		return (
			<Card>
				<CardBody>
					<Notice status="error" isDismissible={ false }>
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
	} = connection;
	const pct =
		rate_limit > 0
			? Math.round( ( rate_remaining / rate_limit ) * 100 )
			: 0;
	const barColor = pct > 50 ? '#4ac26b' : pct > 20 ? '#e3b341' : '#cf222e';

	return (
		<Card>
			<CardBody>
				{ authenticated && avatar_url && (
					<div style={ { textAlign: 'center', marginBottom: 14 } }>
						<img
							src={ avatar_url }
							alt={ login }
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
						<span style={ { color: '#24292f' } }>
							API requests this hour
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
					<p className="ghwp-rate-note">
						{ rate_limit === 60
							? "Unauthenticated limit — shared by your server's IP. Add a token for 5,000/hour."
							: rate_reset
							? `Resets in ${ humanDiff( rate_reset ) }.`
							: 'Resets in about an hour.' }
					</p>
				</div>
			</CardBody>
		</Card>
	);
}

function humanDiff( ts ) {
	const s = ts - Math.floor( Date.now() / 1000 );
	if ( s <= 0 ) {
		return 'moments';
	}
	const m = Math.floor( s / 60 );
	return m > 0 ? `${ m }m ${ s % 60 }s` : `${ s }s`;
}
