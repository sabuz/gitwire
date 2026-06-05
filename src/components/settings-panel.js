import { toast } from '../toast';

import { __ } from '@wordpress/i18n';
import { useState, useCallback } from '@wordpress/element';
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
import { BitbucketIcon } from './provider-icons';

/** Returns all connection records for a provider. */
function getProviderConnections( connections, provider ) {
	return ( connections || [] ).filter( ( c ) => c.provider === provider );
}

/**
 * Settings panel — one card per provider plus a Smart Install card.
 *
 * @param {Object}   props                     Component props.
 * @param {Object}   props.settings            Saved plugin settings.
 * @param {Array}    props.connections         Connection records from the new model.
 * @param {Object}   props.connection          Per-provider connection cache: { github, gitlab, bitbucket }.
 * @param {Function} props.onSave              Called with updated settings after save.
 * @param {Function} props.onConnectionsChange Called with new connections array after create/delete.
 * @param {Function} props.onConnectionUpdate  Called with (provider, data) after a test/disconnect.
 * @return {JSX.Element} The rendered settings panel.
 */
export default function SettingsPanel( {
	settings,
	connections,
	connection,
	onSave,
	onConnectionsChange,
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
			await api.saveSettings( { smart_install: newVal } );
			const saved = await api.getSettings();
			onSave( saved );
			toast.success( __( 'Settings saved.', 'gitwire' ) );
		} catch ( e ) {
			toast.error( e.message || __( 'Save failed.', 'gitwire' ) );
			setSmartInstall( ! newVal );
		} finally {
			setSavingSi( false );
		}
	};

	return (
		<div
			className="gitwire-settings-panels"
			style={ { maxWidth: 540, margin: '0 auto' } }
		>
			<GitHubCard
				connection={ connection?.github ?? null }
				connRecords={ getProviderConnections( connections, 'github' ) }
				onConnectionsChange={ onConnectionsChange }
				onConnectionUpdate={ ( data ) =>
					onConnectionUpdate( 'github', data )
				}
			/>

			<Spacer marginTop={ 4 } />

			<GitLabCard
				connection={ connection?.gitlab ?? null }
				connRecords={ getProviderConnections( connections, 'gitlab' ) }
				onConnectionsChange={ onConnectionsChange }
				onConnectionUpdate={ ( data ) =>
					onConnectionUpdate( 'gitlab', data )
				}
			/>

			<Spacer marginTop={ 4 } />

			<BitbucketCard
				connection={ connection?.bitbucket ?? null }
				connRecords={ getProviderConnections( connections, 'bitbucket' ) }
				onConnectionsChange={ onConnectionsChange }
				onConnectionUpdate={ ( data ) =>
					onConnectionUpdate( 'bitbucket', data )
				}
			/>

			<Spacer marginTop={ 4 } />

			<Card>
				<CardHeader>
					<Heading level={ 4 }>
						{ __( 'Miscellaneous', 'gitwire' ) }
					</Heading>
				</CardHeader>
				<CardBody>
					<ToggleControl
						__nextHasNoMarginBottom
						checked={ smartInstall }
						disabled={ savingSi }
						help={ __(
							'Only allow installing repositories detected as a WordPress plugin or theme.',
							'gitwire'
						) }
						label={
							<>
								<strong>
									{ __( 'Smart Install', 'gitwire' ) }
								</strong>{ ' ' }
								<span className="gitwire-badge-recommended">
									{ __( 'Recommended', 'gitwire' ) }
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
 * Renders the list of saved connections for a provider with disconnect/set-default actions.
 *
 * @param {Object}   props                     Component props.
 * @param {Array}    props.connRecords          Connection records for the provider.
 * @param {Object}   props.connection          Cached connection profile data, or null.
 * @param {Function} props.onConnectionsChange Called with new connections array after create/delete.
 * @param {Function} props.onConnectionUpdate  Called with profile data (or null) after disconnect.
 * @return {JSX.Element|null}
 */
function ConnectionList( {
	connRecords,
	connection,
	onConnectionsChange,
	onConnectionUpdate,
} ) {
	const [ busyId, setBusyId ] = useState( null );

	const handleDisconnect = useCallback(
		async ( id ) => {
			setBusyId( id );
			try {
				const result = await api.deleteConnection( id );
				onConnectionsChange( result.connections );
				onConnectionUpdate( null );
				toast.success( __( 'Disconnected.', 'gitwire' ) );
			} catch ( e ) {
				toast.error( e.message || __( 'Disconnect failed.', 'gitwire' ) );
			} finally {
				setBusyId( null );
			}
		},
		[ onConnectionsChange, onConnectionUpdate ]
	);

	const handleSetDefault = useCallback(
		async ( id ) => {
			setBusyId( id );
			try {
				const result = await api.setDefaultConnection( id );
				onConnectionsChange( result.connections );
			} catch ( e ) {
				toast.error( e.message || __( 'Failed to set default.', 'gitwire' ) );
			} finally {
				setBusyId( null );
			}
		},
		[ onConnectionsChange ]
	);

	if ( ! connRecords.length ) {
		return null;
	}

	return (
		<div className="gitwire-connection-list">
			{ connRecords.map( ( rec ) => {
				const isBusy = busyId === rec.id;
				const profile = rec.is_default ? connection : null;
				const label = rec.username
					? `@${ rec.username }`
					: rec.email || rec.label || rec.id;

				return (
					<div key={ rec.id } className="gitwire-connection-item">
						<Flex align="center" gap={ 2 }>
							{ profile?.avatar_url && (
								<img
									alt={ label }
									src={ profile.avatar_url }
									style={ {
										width: 28,
										height: 28,
										borderRadius: '50%',
										display: 'block',
									} }
								/>
							) }
							<div style={ { flex: 1, minWidth: 0 } }>
								<span className="gitwire-connection-item__label">
									{ label }
								</span>
								{ rec.is_default && (
									<span
										className="gitwire-badge gitwire-badge--info"
										style={ { marginLeft: 6 } }
									>
										{ __( 'Default', 'gitwire' ) }
									</span>
								) }
							</div>
							{ ! rec.is_default && connRecords.length > 1 && (
								<Button
									disabled={ !! busyId }
									isBusy={ isBusy }
									size="compact"
									variant="secondary"
									onClick={ () => handleSetDefault( rec.id ) }
								>
									{ __( 'Set default', 'gitwire' ) }
								</Button>
							) }
							<Button
								disabled={ !! busyId }
								isBusy={ isBusy }
								isDestructive
								size="compact"
								variant="secondary"
								onClick={ () => handleDisconnect( rec.id ) }
							>
								{ __( 'Disconnect', 'gitwire' ) }
							</Button>
						</Flex>
					</div>
				);
			} ) }
		</div>
	);
}

/**
 * GitHub provider card — lists saved connections and shows an add-connection form.
 *
 * @param {Object}   props                     Component props.
 * @param {Object}   props.connection          Cached GitHub connection data, or null.
 * @param {Array}    props.connRecords          Connection records for GitHub.
 * @param {Function} props.onConnectionsChange Called with new connections array after create/delete.
 * @param {Function} props.onConnectionUpdate  Called with connection data (or null) after change.
 * @return {JSX.Element} The rendered card.
 */
function GitHubCard( {
	connection,
	connRecords,
	onConnectionsChange,
	onConnectionUpdate,
} ) {
	const [ token, setToken ] = useState( '' );
	const [ username, setUsername ] = useState( '' );
	const [ saving, setSaving ] = useState( false );
	const [ testing, setTesting ] = useState( false );
	const [ tokenError, setTokenError ] = useState( false );
	const [ usernameError, setUsernameError ] = useState( false );
	const [ showForm, setShowForm ] = useState( false );

	const hasConnections = connRecords.length > 0;

	const handleConnect = async () => {
		if ( ! token.trim() && ! username.trim() ) {
			toast.error( __( 'Enter a username or access token.', 'gitwire' ) );
			return;
		}
		setSaving( true );
		setTesting( true );
		setTokenError( false );
		setUsernameError( false );
		try {
			const result = await api.createConnection( {
				provider: 'github',
				token,
				username,
			} );
			onConnectionsChange( result.connection );
			onConnectionUpdate( result.profile );
			setToken( '' );
			setUsername( '' );
			setShowForm( false );
			toast.success( __( 'GitHub connected.', 'gitwire' ) );
		} catch ( e ) {
			if ( token.trim() ) {
				setTokenError( true );
			} else {
				setUsernameError( true );
			}
			toast.error(
				e.message || __( 'Connection test failed.', 'gitwire' )
			);
		} finally {
			setTesting( false );
			setSaving( false );
		}
	};

	const connectForm = ( ! hasConnections || showForm ) && (
		<>
			{ hasConnections && <Spacer marginTop={ 4 } /> }
			{ testing ? (
				<div style={ { textAlign: 'center', padding: '24px 0' } }>
					<Spinner />
					<p style={ { marginTop: 8, color: '#757575', fontSize: 13 } }>
						{ __( 'Checking connection…', 'gitwire' ) }
					</p>
				</div>
			) : (
				<>
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						className={
							usernameError ? 'gitwire-input-error' : undefined
						}
						help={ __(
							'Your GitHub username or organization. Not required when a token is set.',
							'gitwire'
						) }
						label={ __( 'GitHub Username', 'gitwire' ) }
						placeholder="your-github-username"
						value={ username }
						onChange={ ( v ) => {
							setUsername( v );
							setUsernameError( false );
						} }
					/>

					<Spacer marginTop={ 4 } />

					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						autoComplete="new-password"
						className={ tokenError ? 'gitwire-input-error' : undefined }
						help={
							<>
								{ __(
									'For private repos or to raise the rate limit.',
									'gitwire'
								) }{ ' ' }
								<a
									href="https://github.com/settings/personal-access-tokens/new"
									rel="noopener noreferrer"
									target="_blank"
								>
									{ __( 'Create token', 'gitwire' ) }
								</a>{ ' ' }
								{ __(
									'Select specific repositories, then grant Metadata: Read-only and Contents: Read-only.',
									'gitwire'
								) }
							</>
						}
						label={
							<>
								{ __( 'Fine-grained Access Token', 'gitwire' ) }{ ' ' }
								<span className="gitwire-label-optional">
									{ __( '(Optional)', 'gitwire' ) }
								</span>
							</>
						}
						placeholder="github_pat_xxxxxxxxxxxxxxxxxxxx"
						type="password"
						value={ token }
						onChange={ ( v ) => {
							setToken( v );
							setTokenError( false );
						} }
					/>

					<Spacer marginTop={ 5 } />

					<Flex gap={ 2 }>
						<Button
							disabled={ saving }
							isBusy={ saving }
							variant="primary"
							onClick={ handleConnect }
						>
							{ __( 'Connect GitHub', 'gitwire' ) }
						</Button>
						{ hasConnections && (
							<Button
								variant="tertiary"
								onClick={ () => setShowForm( false ) }
							>
								{ __( 'Cancel', 'gitwire' ) }
							</Button>
						) }
					</Flex>
				</>
			) }
		</>
	);

	return (
		<Card>
			<CardHeader>
				<Flex align="center" gap={ 2 }>
					<FlexItem>
						<svg
							aria-hidden="true"
							fill="currentColor"
							height="20"
							style={ { display: 'block' } }
							viewBox="0 0 16 16"
							width="20"
						>
							<path d="M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 5.47 7.59.4.07.55-.17.55-.38 0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13-.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.51-1.07-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.82-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82.64-.18 1.32-.27 2-.27.68 0 1.36.09 2 .27 1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.51.56.82 1.27.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A8.013 8.013 0 0016 8c0-4.42-3.58-8-8-8z" />
						</svg>
					</FlexItem>
					<FlexBlock>
						<Heading level={ 4 }>
							{ __( 'GitHub', 'gitwire' ) }
						</Heading>
					</FlexBlock>
					{ hasConnections && ! showForm && (
						<FlexItem>
							<Button
								size="compact"
								variant="secondary"
								onClick={ () => setShowForm( true ) }
							>
								{ __( 'Add account', 'gitwire' ) }
							</Button>
						</FlexItem>
					) }
				</Flex>
			</CardHeader>
			<CardBody>
				<ConnectionList
					connRecords={ connRecords }
					connection={ connection }
					onConnectionsChange={ onConnectionsChange }
					onConnectionUpdate={ onConnectionUpdate }
				/>
				{ connectForm }
			</CardBody>
		</Card>
	);
}

/**
 * GitLab provider card — lists saved connections and shows an add-connection form.
 *
 * @param {Object}   props                     Component props.
 * @param {Object}   props.connection          Cached GitLab connection data, or null.
 * @param {Array}    props.connRecords          Connection records for GitLab.
 * @param {Function} props.onConnectionsChange Called with new connections array after create/delete.
 * @param {Function} props.onConnectionUpdate  Called with connection data (or null) after change.
 * @return {JSX.Element} The rendered card.
 */
function GitLabCard( {
	connection,
	connRecords,
	onConnectionsChange,
	onConnectionUpdate,
} ) {
	const [ gitlabToken, setGitlabToken ] = useState( '' );
	const [ gitlabUrl, setGitlabUrl ] = useState( '' );
	const [ saving, setSaving ] = useState( false );
	const [ testing, setTesting ] = useState( false );
	const [ tokenError, setTokenError ] = useState( false );
	const [ showForm, setShowForm ] = useState( false );

	const hasConnections = connRecords.length > 0;

	const handleConnect = async () => {
		if ( ! gitlabToken.trim() ) {
			toast.error(
				__( 'A GitLab Personal Access Token is required.', 'gitwire' )
			);
			return;
		}
		setSaving( true );
		setTesting( true );
		setTokenError( false );
		try {
			const result = await api.createConnection( {
				provider: 'gitlab',
				gitlab_token: gitlabToken,
				gitlab_url: gitlabUrl,
			} );
			onConnectionsChange( result.connection );
			onConnectionUpdate( result.profile );
			setGitlabToken( '' );
			setGitlabUrl( '' );
			setShowForm( false );
			toast.success( __( 'GitLab connected.', 'gitwire' ) );
		} catch ( e ) {
			setTokenError( true );
			toast.error(
				e.message || __( 'Connection test failed.', 'gitwire' )
			);
		} finally {
			setTesting( false );
			setSaving( false );
		}
	};

	const connectForm = ( ! hasConnections || showForm ) && (
		<>
			{ hasConnections && <Spacer marginTop={ 4 } /> }
			{ testing ? (
				<div style={ { textAlign: 'center', padding: '24px 0' } }>
					<Spinner />
					<p style={ { marginTop: 8, color: '#757575', fontSize: 13 } }>
						{ __( 'Checking connection…', 'gitwire' ) }
					</p>
				</div>
			) : (
				<>
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						autoComplete="new-password"
						className={ tokenError ? 'gitwire-input-error' : undefined }
						help={
							<>
								{ __( 'Required.', 'gitwire' ) }{ ' ' }
								<a
									href="https://gitlab.com/-/user_settings/personal_access_tokens"
									rel="noopener noreferrer"
									target="_blank"
								>
									{ __( 'Create token', 'gitwire' ) }
								</a>{ ' ' }
								{ __(
									'- enable read_user, read_api and read_repository.',
									'gitwire'
								) }
							</>
						}
						label={ __( 'Personal Access Token', 'gitwire' ) }
						placeholder="glpat-xxxxxxxxxxxxxxxxxxxx"
						type="password"
						value={ gitlabToken }
						onChange={ ( v ) => {
							setGitlabToken( v );
							setTokenError( false );
						} }
					/>

					<Spacer marginTop={ 4 } />

					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						help={ __(
							'Leave blank for gitlab.com. Enter your instance URL for self-hosted GitLab (e.g. https://gitlab.example.com).',
							'gitwire'
						) }
						label={
							<>
								{ __( 'GitLab Instance URL', 'gitwire' ) }{ ' ' }
								<span className="gitwire-label-optional">
									{ __( '(Optional)', 'gitwire' ) }
								</span>
							</>
						}
						placeholder="https://gitlab.com"
						value={ gitlabUrl }
						onChange={ setGitlabUrl }
					/>

					<Spacer marginTop={ 5 } />

					<Flex gap={ 2 }>
						<Button
							disabled={ saving }
							isBusy={ saving }
							variant="primary"
							onClick={ handleConnect }
						>
							{ __( 'Connect GitLab', 'gitwire' ) }
						</Button>
						{ hasConnections && (
							<Button
								variant="tertiary"
								onClick={ () => setShowForm( false ) }
							>
								{ __( 'Cancel', 'gitwire' ) }
							</Button>
						) }
					</Flex>
				</>
			) }
		</>
	);

	return (
		<Card>
			<CardHeader>
				<Flex align="center" gap={ 2 }>
					<FlexItem>
						<svg
							aria-hidden="true"
							fill="#e24329"
							height="20"
							style={ { display: 'block' } }
							viewBox="0 0 16 16"
							width="20"
						>
							<path d="M15.97 9.058l-.895-2.756L13.3.842a.382.382 0 0 0-.724 0L10.8 6.302H5.2L3.424.842a.382.382 0 0 0-.724 0L.925 6.302.03 9.058a.762.762 0 0 0 .277.852L8 15.37l7.693-5.46a.762.762 0 0 0 .277-.852z" />
						</svg>
					</FlexItem>
					<FlexBlock>
						<Heading level={ 4 }>
							{ __( 'GitLab', 'gitwire' ) }
						</Heading>
					</FlexBlock>
					{ hasConnections && ! showForm && (
						<FlexItem>
							<Button
								size="compact"
								variant="secondary"
								onClick={ () => setShowForm( true ) }
							>
								{ __( 'Add account', 'gitwire' ) }
							</Button>
						</FlexItem>
					) }
				</Flex>
			</CardHeader>
			<CardBody>
				<ConnectionList
					connRecords={ connRecords }
					connection={ connection }
					onConnectionsChange={ onConnectionsChange }
					onConnectionUpdate={ onConnectionUpdate }
				/>
				{ connectForm }
			</CardBody>
		</Card>
	);
}

/**
 * Bitbucket provider card — lists saved connections and shows an add-connection form.
 *
 * @param {Object}   props                     Component props.
 * @param {Object}   props.connection          Cached Bitbucket connection data, or null.
 * @param {Array}    props.connRecords          Connection records for Bitbucket.
 * @param {Function} props.onConnectionsChange Called with new connections array after create/delete.
 * @param {Function} props.onConnectionUpdate  Called with connection data (or null) after change.
 * @return {JSX.Element} The rendered card.
 */
function BitbucketCard( {
	connection,
	connRecords,
	onConnectionsChange,
	onConnectionUpdate,
} ) {
	const [ bbEmail, setBbEmail ] = useState( '' );
	const [ bbApiToken, setBbApiToken ] = useState( '' );
	const [ saving, setSaving ] = useState( false );
	const [ testing, setTesting ] = useState( false );
	const [ credError, setCredError ] = useState( false );
	const [ showForm, setShowForm ] = useState( false );

	const hasConnections = connRecords.length > 0;

	const handleConnect = async () => {
		if ( ! bbEmail.trim() || ! bbApiToken.trim() ) {
			toast.error(
				__(
					'An Atlassian email and API token are required.',
					'gitwire'
				)
			);
			return;
		}
		setSaving( true );
		setTesting( true );
		setCredError( false );
		try {
			const result = await api.createConnection( {
				provider: 'bitbucket',
				bitbucket_email: bbEmail,
				bitbucket_api_token: bbApiToken,
			} );
			onConnectionsChange( result.connection );
			onConnectionUpdate( result.profile );
			setBbEmail( '' );
			setBbApiToken( '' );
			setShowForm( false );
			toast.success( __( 'Bitbucket connected.', 'gitwire' ) );
		} catch ( e ) {
			setCredError( true );
			toast.error(
				e.message || __( 'Connection test failed.', 'gitwire' )
			);
		} finally {
			setTesting( false );
			setSaving( false );
		}
	};

	const connectForm = ( ! hasConnections || showForm ) && (
		<>
			{ hasConnections && <Spacer marginTop={ 4 } /> }
			{ testing ? (
				<div style={ { textAlign: 'center', padding: '24px 0' } }>
					<Spinner />
					<p style={ { marginTop: 8, color: '#757575', fontSize: 13 } }>
						{ __( 'Checking connection…', 'gitwire' ) }
					</p>
				</div>
			) : (
				<>
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						className={ credError ? 'gitwire-input-error' : undefined }
						help={ __(
							'The email address for your Atlassian account.',
							'gitwire'
						) }
						label={ __( 'Atlassian Email', 'gitwire' ) }
						placeholder="you@example.com"
						type="email"
						value={ bbEmail }
						onChange={ ( v ) => {
							setBbEmail( v );
							setCredError( false );
						} }
					/>

					<Spacer marginTop={ 4 } />

					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						autoComplete="new-password"
						className={ credError ? 'gitwire-input-error' : undefined }
						help={
							<>
								{ __( 'Required.', 'gitwire' ) }{ ' ' }
								<a
									href="https://id.atlassian.com/manage-profile/security/api-tokens"
									rel="noopener noreferrer"
									target="_blank"
								>
									{ __( 'Create API token', 'gitwire' ) }
								</a>{ ' ' }
								{ __(
									'at id.atlassian.com → Security → API tokens.',
									'gitwire'
								) }
							</>
						}
						label={ __( 'API Token', 'gitwire' ) }
						placeholder="ATATxxxxxxxxxxxxxxxxxxxxxxxx"
						type="password"
						value={ bbApiToken }
						onChange={ ( v ) => {
							setBbApiToken( v );
							setCredError( false );
						} }
					/>

					<Spacer marginTop={ 5 } />

					<Flex gap={ 2 }>
						<Button
							disabled={ saving }
							isBusy={ saving }
							variant="primary"
							onClick={ handleConnect }
						>
							{ __( 'Connect Bitbucket', 'gitwire' ) }
						</Button>
						{ hasConnections && (
							<Button
								variant="tertiary"
								onClick={ () => setShowForm( false ) }
							>
								{ __( 'Cancel', 'gitwire' ) }
							</Button>
						) }
					</Flex>
				</>
			) }
		</>
	);

	return (
		<Card>
			<CardHeader>
				<Flex align="center" gap={ 2 }>
					<FlexItem>
						<BitbucketIcon size={ 20 } variant="brand" />
					</FlexItem>
					<FlexBlock>
						<Heading level={ 4 }>
							{ __( 'Bitbucket', 'gitwire' ) }
						</Heading>
					</FlexBlock>
					{ hasConnections && ! showForm && (
						<FlexItem>
							<Button
								size="compact"
								variant="secondary"
								onClick={ () => setShowForm( true ) }
							>
								{ __( 'Add account', 'gitwire' ) }
							</Button>
						</FlexItem>
					) }
				</Flex>
			</CardHeader>
			<CardBody>
				<ConnectionList
					connRecords={ connRecords }
					connection={ connection }
					onConnectionsChange={ onConnectionsChange }
					onConnectionUpdate={ onConnectionUpdate }
				/>
				{ connectForm }
			</CardBody>
		</Card>
	);
}

