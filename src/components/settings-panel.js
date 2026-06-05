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
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalToggleGroupControl as ToggleGroupControl,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalToggleGroupControlOption as ToggleGroupControlOption,
} from '@wordpress/components';

import * as api from '../api';
import { BitbucketIcon } from './provider-icons';

const GitHubIcon = () => (
	<svg
		aria-hidden="true"
		fill="currentColor"
		height="16"
		viewBox="0 0 16 16"
		width="16"
	>
		<path d="M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 5.47 7.59.4.07.55-.17.55-.38 0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13-.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.51-1.07-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.82-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82.64-.18 1.32-.27 2-.27.68 0 1.36.09 2 .27 1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.51.56.82 1.27.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A8.013 8.013 0 0016 8c0-4.42-3.58-8-8-8z" />
	</svg>
);

const GitLabIcon = () => (
	<svg
		aria-hidden="true"
		fill="#e24329"
		height="16"
		viewBox="0 0 16 16"
		width="16"
	>
		<path d="M15.97 9.058l-.895-2.756L13.3.842a.382.382 0 0 0-.724 0L10.8 6.302H5.2L3.424.842a.382.382 0 0 0-.724 0L.925 6.302.03 9.058a.762.762 0 0 0 .277.852L8 15.37l7.693-5.46a.762.762 0 0 0 .277-.852z" />
	</svg>
);

/**
 * Settings panel — unified Connections card + Smart Install card.
 *
 * @param {Object}   props                     Component props.
 * @param {Object}   props.settings            Saved plugin settings.
 * @param {Array}    props.connections         Connection records.
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
			<ConnectionsCard
				connection={ connection }
				connections={ connections }
				onConnectionsChange={ onConnectionsChange }
				onConnectionUpdate={ onConnectionUpdate }
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

const PROVIDER_LABELS = {
	github: 'GitHub',
	gitlab: 'GitLab',
	bitbucket: 'Bitbucket',
};

/**
 * Renders all saved connections with provider badges, disconnect, and set-default actions.
 *
 * @param {Array}    props.connections         All connection records.
 * @param {Object}   props.connectionCache     Per-provider profile cache: { github, gitlab, bitbucket }.
 * @param {Function} props.onConnectionsChange Called with new connections array after create/delete.
 * @param {Function} props.onConnectionUpdate  Called with (provider, data|null) after disconnect.
 * @param            root0
 * @param            root0.connections
 * @param            root0.connectionCache
 * @param            root0.onConnectionsChange
 * @param            root0.onConnectionUpdate
 * @return {JSX.Element|null}
 */
function ConnectionList( {
	connections,
	connectionCache,
	onConnectionsChange,
	onConnectionUpdate,
} ) {
	const [ busyId, setBusyId ] = useState( null );

	const handleDisconnect = useCallback(
		async ( id, provider ) => {
			setBusyId( id );
			try {
				const result = await api.deleteConnection( id );
				onConnectionsChange( result.connections );
				onConnectionUpdate( provider, null );
				toast.success( __( 'Disconnected.', 'gitwire' ) );
			} catch ( e ) {
				toast.error(
					e.message || __( 'Disconnect failed.', 'gitwire' )
				);
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
				toast.error(
					e.message || __( 'Failed to set default.', 'gitwire' )
				);
			} finally {
				setBusyId( null );
			}
		},
		[ onConnectionsChange ]
	);

	if ( ! connections.length ) {
		return null;
	}

	const providerCounts = {};
	connections.forEach( ( c ) => {
		providerCounts[ c.provider ] =
			( providerCounts[ c.provider ] || 0 ) + 1;
	} );

	return (
		<div className="gitwire-connection-list">
			{ connections.map( ( rec ) => {
				const isBusy = busyId === rec.id;
				const profile = connectionCache?.[ rec.provider ] ?? null;
				const label = rec.username
					? `@${ rec.username }`
					: rec.email || rec.label || rec.id;
				const providerLabel =
					PROVIDER_LABELS[ rec.provider ] ?? rec.provider;

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
								<span
									className="gitwire-badge gitwire-badge--draft"
									style={ { marginLeft: 6 } }
								>
									{ providerLabel }
								</span>
								{ rec.is_default &&
									providerCounts[ rec.provider ] > 1 && (
										<span
											className="gitwire-badge gitwire-badge--info"
											style={ { marginLeft: 4 } }
										>
											{ __( 'Default', 'gitwire' ) }
										</span>
									) }
							</div>
							{ ! rec.is_default &&
								providerCounts[ rec.provider ] > 1 && (
									<Button
										disabled={ !! busyId }
										isBusy={ isBusy }
										size="compact"
										variant="tertiary"
										onClick={ () =>
											handleSetDefault( rec.id )
										}
									>
										{ __( 'Set default', 'gitwire' ) }
									</Button>
								) }
							<Button
								disabled={ !! busyId }
								isBusy={ isBusy }
								isDestructive
								size="compact"
								variant="tertiary"
								onClick={ () =>
									handleDisconnect( rec.id, rec.provider )
								}
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
 * Unified connections card — lists all connections and exposes a provider picker + connect forms.
 *
 * @param {Object}   props                     Component props.
 * @param {Array}    props.connections         All connection records.
 * @param {Object}   props.connection          Per-provider connection cache.
 * @param {Function} props.onConnectionsChange Called with new connections array after create/delete.
 * @param {Function} props.onConnectionUpdate  Called with (provider, data) after change.
 * @return {JSX.Element} The rendered card.
 */
function ConnectionsCard( {
	connections,
	connection,
	onConnectionsChange,
	onConnectionUpdate,
} ) {
	const [ adding, setAdding ] = useState( false );

	const handleCreated = useCallback(
		( provider, conns, profile ) => {
			onConnectionsChange( conns );
			onConnectionUpdate( provider, profile );
			setAdding( false );
		},
		[ onConnectionsChange, onConnectionUpdate ]
	);

	return (
		<Card>
			<CardHeader>
				<Flex align="center" gap={ 2 }>
					<FlexBlock>
						<Heading level={ 4 }>
							{ __( 'Connections', 'gitwire' ) }
						</Heading>
					</FlexBlock>
					{ ! adding && (
						<FlexItem>
							<Button
								size="compact"
								variant="secondary"
								onClick={ () => setAdding( true ) }
							>
								{ __( 'Add account', 'gitwire' ) }
							</Button>
						</FlexItem>
					) }
				</Flex>
			</CardHeader>
			<CardBody>
				{ connections.length > 0 && (
					<ConnectionList
						connections={ connections }
						connectionCache={ connection }
						onConnectionsChange={ onConnectionsChange }
						onConnectionUpdate={ onConnectionUpdate }
					/>
				) }

				{ ! adding && connections.length === 0 && (
					<p style={ { margin: 0, color: '#757575', fontSize: 13 } }>
						{ __(
							'No accounts connected. Click "Add account" to connect GitHub, GitLab, or Bitbucket.',
							'gitwire'
						) }
					</p>
				) }

				{ adding && (
					<>
						{ connections.length > 0 && <Spacer marginTop={ 4 } /> }
						<AddConnectionForm
							onCreated={ handleCreated }
							onCancel={ () => setAdding( false ) }
						/>
					</>
				) }
			</CardBody>
		</Card>
	);
}

/**
 * Inline add-connection form: radio to pick a provider, fields appear below.
 *
 * @param {Function} props.onCreated Called with (provider, connections, profile) after success.
 * @param {Function} props.onCancel  Hides the form.
 * @param            root0
 * @param            root0.onCreated
 * @param            root0.onCancel
 * @return {JSX.Element}
 */
function AddConnectionForm( { onCreated, onCancel } ) {
	const [ provider, setProvider ] = useState( 'github' );
	const [ saving, setSaving ] = useState( false );
	const [ testing, setTesting ] = useState( false );

	// GitHub fields
	const [ ghToken, setGhToken ] = useState( '' );
	const [ ghUsername, setGhUsername ] = useState( '' );
	const [ ghTokenError, setGhTokenError ] = useState( false );
	const [ ghUsernameError, setGhUsernameError ] = useState( false );

	// GitLab fields
	const [ glToken, setGlToken ] = useState( '' );
	const [ glUrl, setGlUrl ] = useState( '' );
	const [ glTokenError, setGlTokenError ] = useState( false );

	// Bitbucket fields
	const [ bbEmail, setBbEmail ] = useState( '' );
	const [ bbToken, setBbToken ] = useState( '' );
	const [ bbError, setBbError ] = useState( false );

	const resetErrors = () => {
		setGhTokenError( false );
		setGhUsernameError( false );
		setGlTokenError( false );
		setBbError( false );
	};

	const handleProviderChange = ( val ) => {
		setProvider( val );
		resetErrors();
	};

	const handleSubmit = async () => {
		let data;
		if ( 'github' === provider ) {
			if ( ! ghToken.trim() && ! ghUsername.trim() ) {
				toast.error(
					__( 'Enter a username or access token.', 'gitwire' )
				);
				return;
			}
			data = { provider: 'github', token: ghToken, username: ghUsername };
		} else if ( 'gitlab' === provider ) {
			if ( ! glToken.trim() ) {
				toast.error(
					__(
						'A GitLab Personal Access Token is required.',
						'gitwire'
					)
				);
				return;
			}
			data = {
				provider: 'gitlab',
				gitlab_token: glToken,
				gitlab_url: glUrl,
			};
		} else {
			if ( ! bbEmail.trim() || ! bbToken.trim() ) {
				toast.error(
					__(
						'An Atlassian email and API token are required.',
						'gitwire'
					)
				);
				return;
			}
			data = {
				provider: 'bitbucket',
				bitbucket_email: bbEmail,
				bitbucket_api_token: bbToken,
			};
		}

		setSaving( true );
		setTesting( true );
		resetErrors();

		try {
			const result = await api.createConnection( data );
			toast.success(
				( PROVIDER_LABELS[ provider ] ?? provider ) +
					' ' +
					__( 'connected.', 'gitwire' )
			);
			onCreated( provider, result.connection, result.profile );
		} catch ( e ) {
			if ( 'github' === provider ) {
				ghToken.trim()
					? setGhTokenError( true )
					: setGhUsernameError( true );
			} else if ( 'gitlab' === provider ) {
				setGlTokenError( true );
			} else {
				setBbError( true );
			}
			toast.error(
				e.message || __( 'Connection test failed.', 'gitwire' )
			);
		} finally {
			setTesting( false );
			setSaving( false );
		}
	};

	if ( testing ) {
		return (
			<div style={ { textAlign: 'center', padding: '24px 0' } }>
				<Spinner />
				<p style={ { marginTop: 8, color: '#757575', fontSize: 13 } }>
					{ __( 'Checking connection…', 'gitwire' ) }
				</p>
			</div>
		);
	}

	return (
		<div className="gitwire-add-connection-form">
			<ToggleGroupControl
				__nextHasNoMarginBottom
				isBlock
				label={ __( 'Provider', 'gitwire' ) }
				value={ provider }
				onChange={ handleProviderChange }
			>
				<ToggleGroupControlOption
					label={
						<Flex align="center" gap={ 1 } justify="center">
							<GitHubIcon />
							<span>GitHub</span>
						</Flex>
					}
					value="github"
				/>
				<ToggleGroupControlOption
					label={
						<Flex align="center" gap={ 1 } justify="center">
							<GitLabIcon />
							<span>GitLab</span>
						</Flex>
					}
					value="gitlab"
				/>
				<ToggleGroupControlOption
					label={
						<Flex align="center" gap={ 1 } justify="center">
							<BitbucketIcon size={ 14 } variant="brand" />
							<span>Bitbucket</span>
						</Flex>
					}
					value="bitbucket"
				/>
			</ToggleGroupControl>

			<Spacer marginTop={ 4 } />

			{ 'github' === provider && (
				<>
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						className={
							ghUsernameError ? 'gitwire-input-error' : undefined
						}
						help={ __(
							'Your GitHub username or organization. Not required when a token is set.',
							'gitwire'
						) }
						label={ __( 'GitHub Username', 'gitwire' ) }
						placeholder="your-github-username"
						value={ ghUsername }
						onChange={ ( v ) => {
							setGhUsername( v );
							setGhUsernameError( false );
						} }
					/>
					<Spacer marginTop={ 4 } />
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						autoComplete="new-password"
						className={
							ghTokenError ? 'gitwire-input-error' : undefined
						}
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
						value={ ghToken }
						onChange={ ( v ) => {
							setGhToken( v );
							setGhTokenError( false );
						} }
					/>
				</>
			) }

			{ 'gitlab' === provider && (
				<>
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						autoComplete="new-password"
						className={
							glTokenError ? 'gitwire-input-error' : undefined
						}
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
						value={ glToken }
						onChange={ ( v ) => {
							setGlToken( v );
							setGlTokenError( false );
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
						value={ glUrl }
						onChange={ setGlUrl }
					/>
				</>
			) }

			{ 'bitbucket' === provider && (
				<>
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						className={
							bbError ? 'gitwire-input-error' : undefined
						}
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
							setBbError( false );
						} }
					/>
					<Spacer marginTop={ 4 } />
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						autoComplete="new-password"
						className={
							bbError ? 'gitwire-input-error' : undefined
						}
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
						value={ bbToken }
						onChange={ ( v ) => {
							setBbToken( v );
							setBbError( false );
						} }
					/>
				</>
			) }

			<Spacer marginTop={ 5 } />

			<Flex gap={ 2 } justify="flex-end">
				<Button variant="tertiary" onClick={ onCancel }>
					{ __( 'Cancel', 'gitwire' ) }
				</Button>
				<Button
					disabled={ saving }
					isBusy={ saving }
					variant="primary"
					onClick={ handleSubmit }
				>
					{ __( 'Connect', 'gitwire' ) }
				</Button>
			</Flex>
		</div>
	);
}
