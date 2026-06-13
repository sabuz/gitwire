import { toast } from '../toast';

import { __ } from '@wordpress/i18n';
import { useState, useCallback, useEffect } from '@wordpress/element';
import { applyFilters } from '@wordpress/hooks';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalConfirmDialog as ConfirmDialog,
	Flex,
	FlexBlock,
	FlexItem,
	SelectControl,
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
import { BitbucketIcon, GitHubIcon, GitLabIcon } from './provider-icons';

/**
 * Settings panel — Browse accounts card + Smart Install card + Logging card.
 *
 * @param {Object}   props                     Component props.
 * @param {Array}    props.publicConnections   Current public connections list.
 * @param {Object}   props.settings            Saved plugin settings.
 * @param {Function} props.onConnectionsChange Called with updated connections array.
 * @param {Function} props.onSave              Called with updated settings after save.
 * @return {JSX.Element} The rendered settings panel.
 */
export default function SettingsPanel( {
	publicConnections,
	settings,
	onConnectionsChange,
	onSave,
} ) {
	const [ smartInstall, setSmartInstall ] = useState(
		settings.smart_install !== false
	);
	const [ savingSi, setSavingSi ] = useState( false );
	const [ showRepoLabel, setShowRepoLabel ] = useState(
		settings.show_repo_label !== false
	);
	const [ enableLogging, setEnableLogging ] = useState(
		!! settings.enable_logging
	);
	const [ savingLog, setSavingLog ] = useState( false );
	const [ logRetentionDays, setLogRetentionDays ] = useState(
		String( settings.log_retention_days ?? 30 )
	);
	const [ logLevel, setLogLevel ] = useState(
		settings.log_level ?? 'activity'
	);
	const [ clearingLogs, setClearingLogs ] = useState( false );

	const saveSetting = ( payload, rollback ) => {
		const p = api
			.saveSettings( payload )
			.then( () => api.getSettings() )
			.then( ( saved ) => onSave( saved ) )
			.catch( ( e ) => {
				rollback?.();
				throw e;
			} );

		toast.promise( p, {
			id: 'settings-save',
			loading: __( 'Saving…', 'gitwire' ),
			success: __( 'Saved.', 'gitwire' ),
			error: ( e ) => e?.message || __( 'Save failed.', 'gitwire' ),
		} );

		return p;
	};

	const handleSmartInstallChange = ( newVal ) => {
		setSmartInstall( newVal );
		setSavingSi( true );
		saveSetting( { smart_install: newVal }, () =>
			setSmartInstall( ! newVal )
		)
			.finally( () => setSavingSi( false ) )
			.catch( () => {} );
	};

	const handleShowRepoLabelChange = ( newVal ) => {
		setShowRepoLabel( newVal );
		saveSetting( { show_repo_label: newVal }, () =>
			setShowRepoLabel( ! newVal )
		).catch( () => {} );
	};

	const handleEnableLoggingChange = ( newVal ) => {
		setEnableLogging( newVal );
		setSavingLog( true );
		// Reload on success so the WP admin sidebar reflects the updated Logs menu.
		const p = api
			.saveSettings( { enable_logging: newVal } )
			.then( () => window.location.reload() )
			.catch( ( e ) => {
				setEnableLogging( ! newVal );
				setSavingLog( false );
				throw e;
			} );
		toast.promise( p, {
			id: 'settings-save',
			loading: __( 'Saving…', 'gitwire' ),
			success: __( 'Saved.', 'gitwire' ),
			error: ( e ) => e?.message || __( 'Save failed.', 'gitwire' ),
		} );
	};

	const handleLogRetentionChange = ( newVal ) => {
		setLogRetentionDays( newVal );
		saveSetting( { log_retention_days: parseInt( newVal, 10 ) } ).catch(
			() => {}
		);
	};

	const handleClearLogs = () => {
		setClearingLogs( true );
		toast.promise(
			api.clearLogs().finally( () => setClearingLogs( false ) ),
			{
				loading: __( 'Clearing logs…', 'gitwire' ),
				success: __( 'Logs cleared.', 'gitwire' ),
				error: ( e ) =>
					e?.message || __( 'Could not clear logs.', 'gitwire' ),
			}
		);
	};

	const handleLogLevelChange = ( newVal ) => {
		setLogLevel( newVal );
		saveSetting( { log_level: newVal } ).catch( () => {} );
	};

	// Pro replaces the public connections card with its token connections UI
	const accountsSection = applyFilters(
		'gitwire.settings.accountsSection',
		<PublicConnectionsCard
			connections={ publicConnections }
			onChange={ onConnectionsChange }
		/>,
		{ publicConnections, settings, onConnectionsChange, onSave }
	);

	return (
		<div
			className="gitwire-settings-panels"
			style={ { maxWidth: 580, margin: '0 auto' } }
		>
			{ accountsSection }

			<Spacer marginTop={ 4 } />

			<Card>
				<CardHeader>
					<Heading level={ 4 }>
						{ __( 'Install', 'gitwire' ) }
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
								{ __( 'Smart install', 'gitwire' ) }{ ' ' }
								<span
									className="gitwire-badge gitwire-badge--success"
									style={ { marginLeft: 4 } }
								>
									{ __( 'Recommended', 'gitwire' ) }
								</span>
							</>
						}
						onChange={ handleSmartInstallChange }
					/>
					<Spacer marginTop={ 4 } />
					<ToggleControl
						__nextHasNoMarginBottom
						checked={ showRepoLabel }
						help={ __(
							'Shows a [Gitwire] label next to managed plugin and theme names on the Plugins and Themes screens.',
							'gitwire'
						) }
						label={ __( 'Repo label', 'gitwire' ) }
						onChange={ handleShowRepoLabelChange }
					/>
				</CardBody>
			</Card>

			<Spacer marginTop={ 4 } />

			<Card>
				<CardHeader>
					<Heading level={ 4 }>
						{ __( 'Logging', 'gitwire' ) }
					</Heading>
				</CardHeader>
				<CardBody>
					<ToggleControl
						__nextHasNoMarginBottom
						checked={ enableLogging }
						disabled={ savingLog }
						help={ __(
							'Record installs, removals, activations, and connection changes to the Logs page.',
							'gitwire'
						) }
						label={ __( 'Enable logging', 'gitwire' ) }
						onChange={ handleEnableLoggingChange }
					/>
					{ enableLogging && (
						<>
							<Spacer marginTop={ 4 } />
							<SelectControl
								__next40pxDefaultSize
								__nextHasNoMarginBottom
								label={ __( 'Log Level', 'gitwire' ) }
								help={ __(
									'Errors only records failed operations. All activity includes installs, activations, and connections.',
									'gitwire'
								) }
								options={ [
									{
										label: __( 'All activity', 'gitwire' ),
										value: 'activity',
									},
									{
										label: __( 'Errors only', 'gitwire' ),
										value: 'error',
									},
								] }
								value={ logLevel }
								onChange={ handleLogLevelChange }
							/>
							<Spacer marginTop={ 4 } />
							<SelectControl
								__next40pxDefaultSize
								__nextHasNoMarginBottom
								label={ __( 'Log Retention', 'gitwire' ) }
								help={
									<>
										{ __(
											'Entries older than this are automatically removed.',
											'gitwire'
										) }{ ' ' }
										<Button
											disabled={ clearingLogs }
											isDestructive
											style={ { fontSize: 12 } }
											variant="link"
											onClick={ handleClearLogs }
										>
											{ __( 'Click here', 'gitwire' ) }
										</Button>{ ' ' }
										{ __(
											'to clear all logs now.',
											'gitwire'
										) }
									</>
								}
								options={ [
									{
										label: __( '7 days', 'gitwire' ),
										value: '7',
									},
									{
										label: __( '15 days', 'gitwire' ),
										value: '15',
									},
									{
										label: __( '30 days', 'gitwire' ),
										value: '30',
									},
								] }
								value={ logRetentionDays }
								onChange={ handleLogRetentionChange }
							/>
						</>
					) }
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

function PublicConnectionsCard( { connections, onChange } ) {
	const [ selectedId, setSelectedId ] = useState( null );
	const [ githubRateLimit, setGithubRateLimit ] = useState( null );

	// Start the rate-limit fetch as soon as the list renders, not when the
	// detail card opens, so the bar is ready by the time the user clicks in.
	useEffect( () => {
		const githubConn = connections.find( ( c ) => 'github' === c.provider );
		if ( ! githubConn ) {
			return;
		}
		api.getPublicConnectionRateLimit( githubConn.id )
			.then( ( data ) => data && setGithubRateLimit( data ) )
			.catch( () => {} );
	}, [] ); // eslint-disable-line react-hooks/exhaustive-deps

	const handleCreated = useCallback(
		( conn ) => onChange( [ ...connections, conn ] ),
		[ connections, onChange ]
	);

	const handleRemoved = useCallback(
		( id ) => {
			onChange( connections.filter( ( c ) => c.id !== id ) );
			setSelectedId( null );
		},
		[ connections, onChange ]
	);

	if ( selectedId ) {
		const rec = connections.find( ( c ) => c.id === selectedId );
		return (
			<>
				<Flex align="center" gap={ 2 } style={ { marginBottom: 16 } }>
					<FlexItem>
						<Button
							icon="arrow-left-alt2"
							label={ __( 'Back to Connections', 'gitwire' ) }
							variant="tertiary"
							onClick={ () => setSelectedId( null ) }
						/>
					</FlexItem>
					<FlexBlock>
						<Heading level={ 4 } style={ { margin: 0 } }>
							{ __( 'Connections', 'gitwire' ) }
						</Heading>
					</FlexBlock>
				</Flex>
				{ rec && (
					<PublicConnectionDetail
						rateData={
							'github' === rec.provider ? githubRateLimit : null
						}
						rec={ rec }
						onRemoved={ handleRemoved }
					/>
				) }
			</>
		);
	}

	return (
		<PublicConnectionsSummary
			connections={ connections }
			onCreated={ handleCreated }
			onSelect={ setSelectedId }
		/>
	);
}

function PublicConnectionsSummary( { connections, onCreated, onSelect } ) {
	const [ adding, setAdding ] = useState( false );

	const handleCreated = ( conn ) => {
		onCreated( conn );
		setAdding( false );
	};

	return (
		<Card>
			<CardHeader>
				<Flex align="center" gap={ 2 }>
					<FlexBlock>
						<Heading level={ 4 }>
							{ adding
								? __( 'New Connection', 'gitwire' )
								: __( 'Connections', 'gitwire' ) }
						</Heading>
					</FlexBlock>
					{ ! adding && (
						<FlexItem>
							<Button
								size="compact"
								variant="secondary"
								onClick={ () => setAdding( true ) }
							>
								{ __( 'Add New', 'gitwire' ) }
							</Button>
						</FlexItem>
					) }
				</Flex>
			</CardHeader>

			{ ! adding && connections.length > 0 && (
				<CardBody>
					<Flex
						direction="column"
						gap={ 3 }
						className="gitwire-connections-summary"
					>
						{ connections.map( ( conn ) => {
							const provLabel =
								PROVIDER_LABELS[ conn.provider ] ??
								conn.provider;
							return (
								<button
									key={ conn.id }
									className="gitwire-connection-summary-row"
									type="button"
									onClick={ () => onSelect( conn.id ) }
								>
									{ conn.avatar_url ? (
										<img
											alt=""
											aria-hidden="true"
											height={ 24 }
											src={ conn.avatar_url }
											style={ {
												borderRadius: '50%',
												display: 'block',
												flexShrink: 0,
											} }
											width={ 24 }
										/>
									) : (
										<span
											className="gitwire-connection-avatar is-placeholder"
											style={ { width: 24, height: 24 } }
										/>
									) }
									<span
										style={ {
											flex: 1,
											fontSize: 13,
											fontWeight: 500,
											minWidth: 0,
											overflow: 'hidden',
											textOverflow: 'ellipsis',
											whiteSpace: 'nowrap',
										} }
									>
										@{ conn.username }
										{ conn.gitlab_url && (
											<span
												style={ {
													fontWeight: 400,
													color: '#757575',
													marginLeft: 4,
												} }
											>
												({ conn.gitlab_url })
											</span>
										) }
									</span>
									<span
										className={ `gitwire-badge gitwire-badge--${ conn.provider }` }
									>
										<ProviderIcon
											provider={ conn.provider }
										/>
										{ provLabel }
									</span>
									<span className="gitwire-badge gitwire-badge--warning">
										{ __( 'Public only', 'gitwire' ) }
									</span>
								</button>
							);
						} ) }
					</Flex>
				</CardBody>
			) }

			{ adding && (
				<CardBody>
					<AddPublicConnectionForm
						onCancel={ () => setAdding( false ) }
						onCreated={ handleCreated }
					/>
				</CardBody>
			) }
		</Card>
	);
}

function unixTimeAgo( ts ) {
	const s = Math.floor( Date.now() / 1000 ) - ts;
	if ( s < 60 ) {
		return __( 'just now', 'gitwire' );
	}
	const m = Math.floor( s / 60 );
	if ( m < 60 ) {
		return m + 'm ago';
	}
	const h = Math.floor( m / 60 );
	if ( h < 24 ) {
		return h + 'h ago';
	}
	return Math.floor( h / 24 ) + 'd ago';
}

function PublicConnectionDetail( { rec, rateData, onRemoved } ) {
	const [ busy, setBusy ] = useState( false );
	const [ confirming, setConfirming ] = useState( false );
	const provLabel = PROVIDER_LABELS[ rec.provider ] ?? rec.provider;
	const displayName = rateData?.name || rec.name || null;
	const checkedAt = rateData?.checked_at ?? null;

	const hasRateLimit = rateData && rateData.rate_limit > 0;
	const pct = hasRateLimit
		? Math.round( ( rateData.rate_remaining / rateData.rate_limit ) * 100 )
		: 0;
	let barColor = '#cf222e';
	if ( pct > 50 ) {
		barColor = '#4ac26b';
	} else if ( pct > 20 ) {
		barColor = '#e3b341';
	}

	const handleRemove = async () => {
		setConfirming( false );
		setBusy( true );
		try {
			await api.deletePublicConnection( rec.id );
			toast.success( __( 'Disconnected.', 'gitwire' ) );
			onRemoved( rec.id );
		} catch ( e ) {
			setBusy( false );
			toast.error( e?.message || __( 'Disconnect failed.', 'gitwire' ) );
		}
	};

	return (
		<Card>
			<CardHeader>
				<Flex align="center" gap={ 2 }>
					<FlexItem>
						<ProviderIcon provider={ rec.provider } />
					</FlexItem>
					<FlexBlock>
						<strong>{ provLabel }</strong>
					</FlexBlock>
					<FlexItem>
						<span className="gitwire-badge gitwire-badge--warning">
							{ __( 'Public only', 'gitwire' ) }
						</span>
					</FlexItem>
				</Flex>
			</CardHeader>
			<CardBody>
				<Flex align="center" gap={ 3 }>
					{ rec.avatar_url ? (
						<img
							alt={ rec.username }
							height={ 44 }
							src={ rec.avatar_url }
							style={ {
								borderRadius: '50%',
								display: 'block',
								flexShrink: 0,
							} }
							width={ 44 }
						/>
					) : (
						<span
							className="gitwire-connection-avatar is-placeholder"
							style={ { width: 44, height: 44 } }
						/>
					) }
					<FlexBlock>
						{ displayName && (
							<div style={ { fontWeight: 700, fontSize: 14 } }>
								{ displayName }
							</div>
						) }
						<div
							style={
								displayName
									? { fontSize: 12, color: '#57606a' }
									: { fontWeight: 700, fontSize: 14 }
							}
						>
							@{ rec.username }
						</div>
						{ rec.gitlab_url && (
							<div style={ { fontSize: 12, color: '#57606a' } }>
								{ rec.gitlab_url }
							</div>
						) }
						{ checkedAt && (
							<div
								style={ {
									fontSize: 11,
									color: '#8c959f',
									marginTop: 2,
								} }
							>
								{ __( 'Connection verified', 'gitwire' ) }{ ' ' }
								{ unixTimeAgo( checkedAt ) }
							</div>
						) }
					</FlexBlock>
					<FlexItem>
						<Button
							disabled={ busy }
							isBusy={ busy }
							isDestructive
							variant="secondary"
							onClick={ () => setConfirming( true ) }
						>
							{ __( 'Disconnect', 'gitwire' ) }
						</Button>
						{ confirming && (
							<ConfirmDialog
								onCancel={ () => setConfirming( false ) }
								onConfirm={ handleRemove }
							>
								{ __(
									'Disconnect this account? Gitwire will no longer browse its public repositories.',
									'gitwire'
								) }
							</ConfirmDialog>
						) }
					</FlexItem>
				</Flex>

				<hr
					className="gitwire-divider"
					style={ { margin: '12px 0' } }
				/>

				<div style={ { fontSize: 12 } }>
					{ hasRateLimit && (
						<>
							<Flex
								justify="space-between"
								style={ { marginBottom: 6 } }
							>
								<span style={ { color: '#50575e' } }>
									{ __( 'API Usage', 'gitwire' ) }
								</span>
								<strong>
									{ rateData.rate_remaining?.toLocaleString() }
									{ ' / ' }
									{ rateData.rate_limit?.toLocaleString() }
								</strong>
							</Flex>
							<div className="gitwire-rate-track">
								<div
									className="gitwire-rate-fill"
									style={ {
										width: `${ pct }%`,
										background: barColor,
									} }
								/>
							</div>
						</>
					) }
					<p
						className="gitwire-rate-note"
						style={ {
							margin: hasRateLimit ? '6px 0 0' : 0,
							color: '#757575',
						} }
					>
						{ 'github' === rec.provider ? (
							<>
								{ __(
									"Unauthenticated limit is shared by your server's IP. Connect with a token via",
									'gitwire'
								) }{ ' ' }
								<a
									href="https://gitwire.app/pro"
									rel="noopener noreferrer"
									target="_blank"
								>
									{ __( 'Gitwire Pro', 'gitwire' ) }
								</a>{ ' ' }
								{ __(
									'for 5,000 requests/hour.',
									'gitwire'
								) }
							</>
						) : (
							<>
								{ __( 'Public access only.', 'gitwire' ) }{ ' ' }
								<a
									href="https://gitwire.app/pro"
									rel="noopener noreferrer"
									target="_blank"
								>
									{ __( 'Gitwire Pro', 'gitwire' ) }
								</a>{ ' ' }
								{ __(
									'adds credentials for private repositories and higher rate limits.',
									'gitwire'
								) }
							</>
						) }
					</p>
				</div>
			</CardBody>
		</Card>
	);
}

/**
 * Returns the small inline icon for a provider.
 *
 * @param {Object} props          Component props.
 * @param {string} props.provider Provider key.
 * @return {JSX.Element|null} The provider icon.
 */
function ProviderIcon( { provider } ) {
	if ( 'github' === provider ) {
		return <GitHubIcon />;
	}
	if ( 'gitlab' === provider ) {
		return <GitLabIcon />;
	}
	if ( 'bitbucket' === provider ) {
		return <BitbucketIcon />;
	}
	return null;
}

/**
 * Inline add-account form for public (no-token) connections.
 * Mirrors the Pro AddConnectionForm layout with token fields omitted.
 *
 * @param {Object}   props           Component props.
 * @param {Function} props.onCreated Called with the new connection record after success.
 * @param {Function} props.onCancel  Hides the form.
 * @return {JSX.Element} The rendered form.
 */
function AddPublicConnectionForm( { onCreated, onCancel } ) {
	const [ provider, setProvider ] = useState( 'github' );
	const [ username, setUsername ] = useState( '' );
	const [ gitlabUrl, setGitlabUrl ] = useState( '' );
	const [ saving, setSaving ] = useState( false );
	const [ usernameError, setUsernameError ] = useState( false );

	const handleProviderChange = ( val ) => {
		setProvider( val );
		setUsername( '' );
		setGitlabUrl( '' );
		setUsernameError( false );
	};

	const handleSubmit = async () => {
		const trimmed = username.trim();
		if ( ! trimmed ) {
			setUsernameError( true );
			toast.error(
				'bitbucket' === provider
					? __( 'Workspace is required.', 'gitwire' )
					: __( 'Username is required.', 'gitwire' )
			);
			return;
		}
		setSaving( true );
		setUsernameError( false );
		try {
			const result = await api.addPublicConnection( {
				provider,
				username: trimmed,
				...( 'gitlab' === provider
					? { gitlab_url: gitlabUrl.trim() }
					: {} ),
			} );
			toast.success( __( 'Connection added.', 'gitwire' ) );
			onCreated( result.connection );
		} catch ( e ) {
			setUsernameError( true );
			toast.error(
				e?.message || __( 'Could not add connection.', 'gitwire' )
			);
		} finally {
			setSaving( false );
		}
	};

	const usernamePlaceholder =
		'bitbucket' === provider ? 'your-workspace' : 'your-username';
	const usernameLabel =
		'bitbucket' === provider
			? __( 'Workspace Slug', 'gitwire' )
			: __( 'Username', 'gitwire' );

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
							<GitHubIcon size={ 14 } variant="brand" />
							<span>GitHub</span>
						</Flex>
					}
					value="github"
				/>
				<ToggleGroupControlOption
					label={
						<Flex align="center" gap={ 1 } justify="center">
							<GitLabIcon size={ 14 } variant="brand" />
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

			<TextControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				className={ usernameError ? 'gitwire-input-error' : undefined }
				label={ usernameLabel }
				placeholder={ usernamePlaceholder }
				value={ username }
				onChange={ ( v ) => {
					setUsername( v );
					setUsernameError( false );
				} }
			/>

			{ 'gitlab' === provider && (
				<>
					<Spacer marginTop={ 4 } />
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						help={ __(
							'Leave blank for gitlab.com. Enter your instance URL for self-hosted GitLab.',
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
				</>
			) }

			<Spacer marginTop={ 4 } />

			<p style={ { margin: '0 0 16px', color: '#757575', fontSize: 13 } }>
				{ __(
					'Public repositories only. No token needed.',
					'gitwire'
				) }{ ' ' }
				<a
					href="https://gitwire.app/pro"
					rel="noopener noreferrer"
					target="_blank"
				>
					{ __( 'Gitwire Pro', 'gitwire' ) }
				</a>{ ' ' }
				{ __( 'adds private repository access.', 'gitwire' ) }
			</p>

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
