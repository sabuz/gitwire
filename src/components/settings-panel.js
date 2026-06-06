import { toast } from '../toast';

import { __, sprintf } from '@wordpress/i18n';
import { useState, useCallback } from '@wordpress/element';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Flex,
	FlexBlock,
	FlexItem,
	Popover,
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
	const [ view, setView ] = useState( 'overview' );
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

	if ( view === 'connections' ) {
		return (
			<div
				className="gitwire-settings-panels"
				style={ { maxWidth: 540, margin: '0 auto' } }
			>
				<ConnectionsManager
					connection={ connection }
					connections={ connections }
					onBack={ () => setView( 'overview' ) }
					onConnectionsChange={ onConnectionsChange }
					onConnectionUpdate={ onConnectionUpdate }
				/>
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
				onManage={ () => setView( 'connections' ) }
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
		return <BitbucketIcon size={ 12 } variant="brand" />;
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
								<span className="gitwire-badge gitwire-badge--draft gitwire-badge--provider">
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
 * Displays connection status at a glance with a Manage button to open the full screen.
 *
 * @param {Object}   props             Component props.
 * @param {Array}    props.connections All connection records.
 * @param {Object}   props.connection  Per-connection-ID profile cache.
 * @param {Function} props.onManage    Opens the connections management screen.
 * @return {JSX.Element} The rendered summary card.
 */
function ConnectionsSummary( { connections, connection, onManage } ) {
	return (
		<Card>
			<CardHeader>
				<Flex align="center" gap={ 2 }>
					<FlexBlock>
						<Heading level={ 4 }>
							{ __( 'Connections', 'gitwire' ) }
						</Heading>
					</FlexBlock>
					<FlexItem>
						<Button
							size="compact"
							variant="secondary"
							onClick={ onManage }
						>
							{ __( 'Manage', 'gitwire' ) }
						</Button>
					</FlexItem>
				</Flex>
			</CardHeader>
			{ connections.length > 0 && (
				<CardBody>
					<div className="gitwire-connections-summary">
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
								<Flex
									key={ rec.id }
									align="center"
									className="gitwire-connection-summary-row"
									gap={ 2 }
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
									<span className="gitwire-badge gitwire-badge--draft gitwire-badge--provider">
										<ProviderIcon
											provider={ rec.provider }
										/>
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
												: __(
														'Public only',
														'gitwire'
												  ) }
										</span>
									) }
								</Flex>
							);
						} ) }
					</div>
				</CardBody>
			) }
		</Card>
	);
}

/**
 * Full connections management screen — back button, connection list, add form.
 *
 * @param {Object}   props                     Component props.
 * @param {Array}    props.connections         All connection records.
 * @param {Object}   props.connection          Per-connection-ID profile cache.
 * @param {Function} props.onBack              Returns to the settings overview.
 * @param {Function} props.onConnectionsChange Called with new connections array after create/delete.
 * @param {Function} props.onConnectionUpdate  Called with (provider, data) after change.
 * @return {JSX.Element} The rendered management screen.
 */
function ConnectionsManager( {
	connections,
	connection,
	onBack,
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
		<div className="gitwire-connections-screen">
			{ /* Page-level header — sits outside any card */ }
			<Flex align="center" gap={ 2 } style={ { marginBottom: 16 } }>
				<FlexItem>
					<Button
						icon="arrow-left-alt2"
						label={ __( 'Back to Settings', 'gitwire' ) }
						variant="tertiary"
						onClick={ onBack }
					/>
				</FlexItem>
				<FlexBlock>
					<Heading level={ 4 } style={ { margin: 0 } }>
						{ __( 'Connections', 'gitwire' ) }
					</Heading>
				</FlexBlock>
				{ ! adding && (
					<FlexItem>
						<Button
							variant="secondary"
							onClick={ () => setAdding( true ) }
						>
							{ __( 'Add connection', 'gitwire' ) }
						</Button>
					</FlexItem>
				) }
			</Flex>

			{ ! adding && connections.length === 0 && (
				<p style={ { margin: 0, color: '#757575', fontSize: 13 } }>
					{ __(
						'No connections yet. Click "Add connection" to connect GitHub, GitLab, or Bitbucket.',
						'gitwire'
					) }
				</p>
			) }

			<div
				style={ { display: 'flex', flexDirection: 'column', gap: 16 } }
			>
				{ connections.map( ( rec ) => (
					<ConnectionCard
						key={ rec.id }
						connection={ connection }
						rec={ rec }
						onConnectionsChange={ onConnectionsChange }
						onConnectionUpdate={ onConnectionUpdate }
					/>
				) ) }
			</div>

			{ adding && (
				<>
					{ connections.length > 0 && <Spacer marginTop={ 4 } /> }
					<Card>
						<CardBody>
							<AddConnectionForm
								onCreated={ handleCreated }
								onCancel={ () => setAdding( false ) }
							/>
						</CardBody>
					</Card>
				</>
			) }
		</div>
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
 * @return {JSX.Element} The rendered connection card.
 */
function ConnectionCard( {
	rec,
	connection,
	onConnectionsChange,
	onConnectionUpdate,
} ) {
	const [ busy, setBusy ] = useState( false );
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
		setBusy( true );
		try {
			const result = await api.deleteConnection( rec.id );
			onConnectionsChange( result.connections );
			onConnectionUpdate( rec.provider, null );
			toast.success( __( 'Disconnected.', 'gitwire' ) );
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
							onClick={ handleDisconnect }
						>
							{ __( 'Sign Out', 'gitwire' ) }
						</Button>
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
