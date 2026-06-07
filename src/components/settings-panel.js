import { toast } from '../toast';

import { __, sprintf } from '@wordpress/i18n';
import { useState, useCallback } from '@wordpress/element';
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
	Popover,
	SelectControl,
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
import { BitbucketIcon, GitHubIcon, GitLabIcon } from './provider-icons';

const PROVIDER_LABELS = {
	github: 'GitHub',
	gitlab: 'GitLab',
	bitbucket: 'Bitbucket',
};

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
	const [ selectedId, setSelectedId ] = useState( null );
	const [ smartInstall, setSmartInstall ] = useState(
		settings.smart_install !== false
	);
	const [ savingSi, setSavingSi ] = useState( false );
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

	const handleEnableLoggingChange = async ( newVal ) => {
		setEnableLogging( newVal );
		setSavingLog( true );
		try {
			await api.saveSettings( { enable_logging: newVal } );
			// Reload so the WP admin sidebar reflects the updated Logs menu.
			window.location.reload();
		} catch ( e ) {
			toast.error( e.message || __( 'Save failed.', 'gitwire' ) );
			setEnableLogging( ! newVal );
			setSavingLog( false );
		}
	};

	const handleLogRetentionChange = async ( newVal ) => {
		setLogRetentionDays( newVal );
		try {
			await api.saveSettings( { log_retention_days: parseInt( newVal, 10 ) } );
			const saved = await api.getSettings();
			onSave( saved );
			toast.success( __( 'Settings saved.', 'gitwire' ) );
		} catch ( e ) {
			toast.error( e.message || __( 'Save failed.', 'gitwire' ) );
		}
	};

	const handleLogLevelChange = async ( newVal ) => {
		setLogLevel( newVal );
		try {
			await api.saveSettings( { log_level: newVal } );
			const saved = await api.getSettings();
			onSave( saved );
			toast.success( __( 'Settings saved.', 'gitwire' ) );
		} catch ( e ) {
			toast.error( e.message || __( 'Save failed.', 'gitwire' ) );
		}
	};

	if ( selectedId ) {
		const rec = connections.find( ( c ) => c.id === selectedId );

		return (
			<div
				className="gitwire-settings-panels"
				style={ { maxWidth: 540, margin: '0 auto' } }
			>
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
					<ConnectionCard
						connection={ connection }
						rec={ rec }
						onConnectionsChange={ onConnectionsChange }
						onConnectionUpdate={ onConnectionUpdate }
						onDisconnected={ () => setSelectedId( null ) }
					/>
				) }
			</div>
		);
	}

	return (
		<div
			className="gitwire-settings-panels"
			style={ { maxWidth: 540, margin: '0 auto' } }
		>
			<ConnectionsSummary
				connection={ connection }
				connections={ connections }
				onConnectionsChange={ onConnectionsChange }
				onConnectionUpdate={ onConnectionUpdate }
				onSelect={ setSelectedId }
			/>

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
						label={
							<strong>
								{ __( 'Enable Logging', 'gitwire' ) }
							</strong>
						}
						onChange={ handleEnableLoggingChange }
					/>
					{ enableLogging && (
						<div
							style={ {
								marginTop: 16,
								paddingLeft: 16,
								borderLeft: '3px solid #e0e0e0',
							} }
						>
							<SelectControl
								__next40pxDefaultSize
								__nextHasNoMarginBottom
								label={ __( 'Log level', 'gitwire' ) }
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
								label={ __( 'Log retention', 'gitwire' ) }
								help={ __(
									'Entries older than this are automatically removed.',
									'gitwire'
								) }
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
						</div>
					) }
				</CardBody>
			</Card>
		</div>
	);
}

/**
 * Returns the small inline icon for a provider, used inside badges.
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
 * Popover showing full connection profile details (API usage, verified time, etc.).
 *
 * @param {Object}   props         Component props.
 * @param {Object}   props.profile Cached profile data for this connection.
 * @param {Function} props.onClose Callback to close the popover.
 * @return {JSX.Element} The rendered popover.
 */
function ConnectionProfilePopover( { profile, onClose } ) {
	const hasRateLimit = profile.rate_limit > 0;
	const pct = hasRateLimit
		? Math.round( ( profile.rate_remaining / profile.rate_limit ) * 100 )
		: 0;
	let barColor = '#cf222e';
	if ( pct > 50 ) {
		barColor = '#4ac26b';
	} else if ( pct > 20 ) {
		barColor = '#e3b341';
	}

	return (
		<Popover
			placement="bottom-start"
			onClose={ onClose }
			onFocusOutside={ onClose }
		>
			<div
				className="gitwire-connection-popover"
				role="presentation"
				style={ { padding: 16, minWidth: 260 } }
				onClick={ ( ev ) => ev.stopPropagation() }
				onKeyDown={ ( ev ) => ev.stopPropagation() }
			>
				{ profile.error ? (
					<p style={ { color: '#cf222e', margin: 0, fontSize: 13 } }>
						{ profile.error }
					</p>
				) : (
					<>
						<Flex
							align="center"
							gap={ 3 }
							style={ { marginBottom: 12 } }
						>
							{ profile.avatar_url && (
								<img
									alt={ profile.login }
									src={ profile.avatar_url }
									style={ {
										width: 44,
										height: 44,
										borderRadius: '50%',
										display: 'block',
										flexShrink: 0,
									} }
								/>
							) }
							<div>
								{ profile.name && (
									<div
										style={ {
											fontWeight: 700,
											fontSize: 14,
										} }
									>
										{ profile.name }
									</div>
								) }
								{ profile.login && (
									<div
										style={ {
											fontSize: 12,
											color: '#57606a',
										} }
									>
										@{ profile.login }
									</div>
								) }
								{ profile.checked_at && (
									<div
										style={ {
											fontSize: 11,
											color: '#8c959f',
											marginTop: 2,
										} }
									>
										{ __( 'Verified', 'gitwire' ) }{ ' ' }
										{ unixTimeAgo( profile.checked_at ) }
									</div>
								) }
							</div>
						</Flex>
						<Flex gap={ 1 }>
							<span
								className={ `gitwire-badge gitwire-badge--${
									profile.authenticated
										? 'success'
										: 'warning'
								}` }
							>
								{ profile.authenticated
									? __( 'Connected', 'gitwire' )
									: __( 'Public only', 'gitwire' ) }
							</span>
						</Flex>
						{ hasRateLimit && (
							<>
								<hr
									className="gitwire-divider"
									style={ { margin: '12px 0' } }
								/>
								<div style={ { fontSize: 12 } }>
									<Flex
										justify="space-between"
										style={ { marginBottom: 6 } }
									>
										<span style={ { color: '#50575e' } }>
											{ __( 'API Usage', 'gitwire' ) }
										</span>
										<strong>
											{ profile.rate_remaining?.toLocaleString() }
											{ ' / ' }
											{ profile.rate_limit?.toLocaleString() }
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
								</div>
							</>
						) }
					</>
				) }
			</div>
		</Popover>
	);
}

/**
 * Renders all saved connections as compact rows with an expandable profile popover.
 *
 * @param {Object}   props                     Component props.
 * @param {Array}    props.connections         All connection records.
 * @param {Object}   props.connectionCache     Per-connection-ID profile cache.
 * @param {Function} props.onConnectionsChange Called with new connections array after create/delete.
 * @param {Function} props.onConnectionUpdate  Called with (provider, data|null) after disconnect.
 * @return {JSX.Element|null} The rendered list, or null when empty.
 */
function ConnectionList( {
	connections,
	connectionCache,
	onConnectionsChange,
	onConnectionUpdate,
} ) {
	const [ busyId, setBusyId ] = useState( null );
	const [ openPopoverId, setOpenPopoverId ] = useState( null );

	const handleDisconnect = useCallback(
		async ( id, provider ) => {
			setBusyId( id );
			try {
				const result = await api.deleteConnection( id );
				onConnectionsChange( result.connections );
				onConnectionUpdate( id, null );
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
				const profile = connectionCache?.[ rec.id ] ?? null;
				const name =
					profile?.name || rec.username || rec.email || rec.label;
				const username = profile?.login || rec.username;
				const provLabel =
					PROVIDER_LABELS[ rec.provider ] ?? rec.provider;
				const isPopoverOpen = openPopoverId === rec.id;

				return (
					<div key={ rec.id } className="gitwire-connection-item">
						<Flex align="center" gap={ 2 }>
							{ /* Clickable identity section — opens profile popover */ }
							<div style={ { position: 'relative' } }>
								<button
									className="gitwire-connection-identity"
									type="button"
									onClick={ () =>
										setOpenPopoverId(
											isPopoverOpen ? null : rec.id
										)
									}
								>
									{ profile?.avatar_url ? (
										<img
											alt={ name }
											className="gitwire-connection-avatar"
											height={ 32 }
											src={ profile.avatar_url }
											style={ {
												borderRadius: '50%',
												display: 'block',
												flexShrink: 0,
											} }
											width={ 32 }
										/>
									) : (
										<span className="gitwire-connection-avatar is-placeholder" />
									) }
									<div className="gitwire-connection-identity__info">
										<span className="gitwire-connection-identity__name">
											{ name }
										</span>
										{ username && username !== name && (
											<span className="gitwire-connection-identity__username">
												@{ username }
											</span>
										) }
									</div>
								</button>
								{ isPopoverOpen && profile && (
									<ConnectionProfilePopover
										profile={ profile }
										onClose={ () =>
											setOpenPopoverId( null )
										}
									/>
								) }
							</div>

							{ /* Badges */ }
							<Flex
								align="center"
								gap={ 1 }
								style={ { flex: 1 } }
							>
								<span
									className={ `gitwire-badge gitwire-badge--${ rec.provider }` }
								>
									<ProviderIcon provider={ rec.provider } />
									{ provLabel }
								</span>
								{ profile && ! profile.error && (
									<span
										className={ `gitwire-badge gitwire-badge--${
											profile.authenticated
												? 'success'
												: 'warning'
										}` }
									>
										{ profile.authenticated
											? __( 'Connected', 'gitwire' )
											: __( 'Public only', 'gitwire' ) }
									</span>
								) }
								{ rec.is_default &&
									providerCounts[ rec.provider ] > 1 && (
										<span className="gitwire-badge gitwire-badge--info">
											{ __( 'Default', 'gitwire' ) }
										</span>
									) }
							</Flex>

							{ /* Actions */ }
							{ ! rec.is_default &&
								providerCounts[ rec.provider ] > 1 && (
									<Button
										disabled={ !! busyId }
										isBusy={ isBusy }
										size="small"
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
								size="small"
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
 * Compact connections summary shown on the Settings overview.
 * Handles inline add-connection form — no navigation needed.
 *
 * @param {Object}   props                     Component props.
 * @param {Array}    props.connections         All connection records.
 * @param {Object}   props.connection          Per-connection-ID profile cache.
 * @param {Function} props.onConnectionsChange Called with new connections array after create.
 * @param {Function} props.onConnectionUpdate  Called with (provider, data) after connect.
 * @param {Function} props.onSelect            Called with a connection ID when a row is clicked.
 * @return {JSX.Element} The rendered summary card.
 */
function ConnectionsSummary( {
	connections,
	connection,
	onConnectionsChange,
	onConnectionUpdate,
	onSelect,
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
					<div
						className="gitwire-connections-summary"
						style={ {
							display: 'flex',
							flexDirection: 'column',
							gap: 12,
						} }
					>
						{ connections.map( ( rec ) => {
							const profile = connection?.[ rec.id ] ?? null;
							const username =
								profile?.login ||
								rec.username ||
								rec.email ||
								rec.label;
							const name = username ? `@${ username }` : rec.id;
							const provLabel =
								PROVIDER_LABELS[ rec.provider ] ?? rec.provider;

							return (
								<button
									key={ rec.id }
									className="gitwire-connection-summary-row"
									type="button"
									onClick={ () => onSelect( rec.id ) }
								>
									{ profile?.avatar_url ? (
										<img
											alt={ name }
											height={ 24 }
											src={ profile.avatar_url }
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
										{ name }
									</span>
									<span
										className={ `gitwire-badge gitwire-badge--${ rec.provider }` }
									>
										<ProviderIcon
											provider={ rec.provider }
										/>
										{ provLabel }
									</span>
									{ 'user' === rec.scope && (
										<span className="gitwire-badge gitwire-badge--info">
											{ __( 'Personal', 'gitwire' ) }
										</span>
									) }
									{ profile && ! profile.error && (
										<span
											className={ `gitwire-badge gitwire-badge--${
												profile.authenticated
													? 'success'
													: 'warning'
											}` }
										>
											{ profile.authenticated
												? __( 'Connected', 'gitwire' )
												: __(
														'Public only',
														'gitwire'
												  ) }
										</span>
									) }
								</button>
							);
						} ) }
					</div>
				</CardBody>
			) }

			{ adding && (
				<CardBody>
					<AddConnectionForm
						onCreated={ handleCreated }
						onCancel={ () => setAdding( false ) }
					/>
				</CardBody>
			) }
		</Card>
	);
}

/**
 * Full-detail card for a single connection — provider header, profile body, API usage.
 *
 * @param {Object}   props                     Component props.
 * @param {Object}   props.rec                 Connection record.
 * @param {Object}   props.connection          Per-connection-ID profile cache.
 * @param {Function} props.onConnectionsChange Called with new connections array after disconnect.
 * @param {Function} props.onConnectionUpdate  Called with (provider, data|null) after disconnect.
 * @param {Function} [props.onDisconnected]    Called after a successful disconnect.
 * @return {JSX.Element} The rendered connection card.
 */
function ConnectionCard( {
	rec,
	connection,
	onConnectionsChange,
	onConnectionUpdate,
	onDisconnected,
} ) {
	const [ busy, setBusy ] = useState( false );
	const [ confirming, setConfirming ] = useState( false );
	const profile = connection?.[ rec.id ] ?? null;
	const provLabel = PROVIDER_LABELS[ rec.provider ] ?? rec.provider;

	const hasRateLimit = profile && profile.rate_limit > 0;
	const pct = hasRateLimit
		? Math.round( ( profile.rate_remaining / profile.rate_limit ) * 100 )
		: 0;
	let barColor = '#cf222e';
	if ( pct > 50 ) {
		barColor = '#4ac26b';
	} else if ( pct > 20 ) {
		barColor = '#e3b341';
	}

	const handleDisconnect = async () => {
		setConfirming( false );
		setBusy( true );
		try {
			const result = await api.deleteConnection( rec.id );
			onConnectionsChange( result.connections );
			onConnectionUpdate( rec.id, null );
			toast.success( __( 'Disconnected.', 'gitwire' ) );
			onDisconnected?.();
		} catch ( e ) {
			toast.error( e.message || __( 'Disconnect failed.', 'gitwire' ) );
		} finally {
			setBusy( false );
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
					{ 'user' === rec.scope && (
						<FlexItem>
							<span className="gitwire-badge gitwire-badge--info">
								{ __( 'Personal', 'gitwire' ) }
							</span>
						</FlexItem>
					) }
					{ profile && ! profile.error && (
						<FlexItem>
							<span
								className={ `gitwire-badge gitwire-badge--${
									profile.authenticated
										? 'success'
										: 'warning'
								}` }
							>
								{ profile.authenticated
									? __( 'Connected', 'gitwire' )
									: __( 'Public only', 'gitwire' ) }
							</span>
						</FlexItem>
					) }
				</Flex>
			</CardHeader>
			<CardBody>
				<Flex align="center" gap={ 3 }>
					{ profile?.avatar_url && (
						<img
							alt={ profile.login }
							height={ 44 }
							src={ profile.avatar_url }
							style={ {
								borderRadius: '50%',
								display: 'block',
								flexShrink: 0,
							} }
							width={ 44 }
						/>
					) }
					<FlexBlock>
						{ profile?.name && (
							<div style={ { fontWeight: 700, fontSize: 14 } }>
								{ profile.name }
							</div>
						) }
						{ profile?.login && (
							<div style={ { fontSize: 12, color: '#57606a' } }>
								@{ profile.login }
							</div>
						) }
						{ profile?.checked_at && (
							<div
								style={ {
									fontSize: 11,
									color: '#8c959f',
									marginTop: 2,
								} }
							>
								{ __( 'Connection verified', 'gitwire' ) }{ ' ' }
								{ unixTimeAgo( profile.checked_at ) }
							</div>
						) }
						{ profile?.error && (
							<div style={ { fontSize: 12, color: '#cf222e' } }>
								{ profile.error }
							</div>
						) }
					</FlexBlock>
					<FlexItem>
						<Button
							disabled={ busy }
							isDestructive
							isBusy={ busy }
							variant="secondary"
							onClick={ () => setConfirming( true ) }
						>
							{ __( 'Disconnect', 'gitwire' ) }
						</Button>
						{ confirming && (
							<ConfirmDialog
								onConfirm={ handleDisconnect }
								onCancel={ () => setConfirming( false ) }
							>
								{ __(
									'Disconnect this provider? Gitwire will remove its saved access.',
									'gitwire'
								) }
							</ConfirmDialog>
						) }
					</FlexItem>
				</Flex>

				{ hasRateLimit && (
					<>
						<hr
							className="gitwire-divider"
							style={ { margin: '12px 0' } }
						/>
						<div style={ { fontSize: 12 } }>
							<Flex
								justify="space-between"
								style={ { marginBottom: 6 } }
							>
								<span style={ { color: '#50575e' } }>
									{ __( 'API Usage', 'gitwire' ) }
								</span>
								<strong>
									{ profile.rate_remaining?.toLocaleString() }
									{ ' / ' }
									{ profile.rate_limit?.toLocaleString() }
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
							<p
								className="gitwire-rate-note"
								style={ {
									margin: '6px 0 0',
									color: '#757575',
								} }
							>
								{ rateNote(
									rec.provider,
									profile.rate_limit,
									profile.rate_reset
								) }
							</p>
						</div>
					</>
				) }
			</CardBody>
		</Card>
	);
}

/**
 * Returns a human-readable note about the current rate limit state.
 *
 * @param {string} provider  Provider key.
 * @param {number} rateLimit Total requests allowed.
 * @param {number} rateReset Unix timestamp when the limit resets.
 * @return {string} The note text.
 */
function rateNote( provider, rateLimit, rateReset ) {
	if ( 'github' === provider && rateLimit === 60 ) {
		return __(
			"Unauthenticated limit is shared by your server's IP. Add a token for 5,000/hour.",
			'gitwire'
		);
	}
	if ( rateReset ) {
		const s = rateReset - Math.floor( Date.now() / 1000 );
		if ( s > 0 ) {
			const m = Math.floor( s / 60 );
			const countdown = m > 0 ? `${ m }m ${ s % 60 }s` : `${ s }s`;
			return sprintf(
				/* translators: %s: time until rate limit resets, e.g. "4m 32s" */
				__( 'Resets in %s.', 'gitwire' ),
				countdown
			);
		}
	}
	return __( 'Resets in about an hour.', 'gitwire' );
}

/**
 * Inline add-connection form: radio to pick a provider, fields appear below.
 *
 * @param {Object}   props           Component props.
 * @param {Function} props.onCreated Called with (provider, connections, profile) after success.
 * @param {Function} props.onCancel  Hides the form.
 * @return {JSX.Element} The rendered form.
 */
function AddConnectionForm( { onCreated, onCancel } ) {
	const [ provider, setProvider ] = useState( 'github' );
	const [ personal, setPersonal ] = useState( false );
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
			const result = await api.createConnection( {
				...data,
				scope: personal ? 'user' : 'site',
			} );
			toast.success(
				( PROVIDER_LABELS[ provider ] ?? provider ) +
					' ' +
					__( 'connected.', 'gitwire' )
			);
			onCreated( provider, result.connection, result.profile );
		} catch ( e ) {
			if ( 'github' === provider ) {
				if ( ghToken.trim() ) {
					setGhTokenError( true );
				} else {
					setGhUsernameError( true );
				}
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

			<ToggleControl
				__nextHasNoMarginBottom
				checked={ personal }
				help={ __(
					'Only you can see and use this connection. Site connections are shared with all administrators.',
					'gitwire'
				) }
				label={ __( 'Personal connection', 'gitwire' ) }
				onChange={ setPersonal }
			/>

			<Spacer marginTop={ 4 } />

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
