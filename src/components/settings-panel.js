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
	FormTokenField,
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
import { PROVIDER_LABELS, ProviderIcon } from './provider';
import { relativeTimeFromUnix } from '../relative-time';
import { persistSetting } from '../save-setting';

/**
 * Settings panel — connections, Browse & Detection, Installed & Updates, Logging.
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
	const [ enableLogging, setEnableLogging ] = useState(
		!! settings.enable_logging
	);
	const [ savingLog, setSavingLog ] = useState( false );
	const [ logRetentionDays, setLogRetentionDays ] = useState(
		String( settings.log_retention_days ?? 7 )
	);
	const [ logLevel, setLogLevel ] = useState(
		settings.log_level ?? 'activity'
	);
	const [ clearingLogs, setClearingLogs ] = useState( false );

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
		persistSetting(
			{ log_retention_days: parseInt( newVal, 10 ) },
			onSave
		).catch( () => {} );
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
		persistSetting( { log_level: newVal }, onSave ).catch( () => {} );
	};

	// Pro replaces the public connections card with its token connections UI.
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
			style={ { maxWidth: 630, margin: '0 auto' } }
		>
			{ accountsSection }

			<Spacer marginTop={ 4 } />

			<BrowseDetectionCard settings={ settings } onSave={ onSave } />

			<Spacer marginTop={ 4 } />

			<InstalledUpdatesCard settings={ settings } onSave={ onSave } />

			<Spacer marginTop={ 4 } />

			<Card>
				<CardHeader>
					<Heading level={ 4 }>{ __( 'Logs', 'gitwire' ) }</Heading>
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
						label={ __( 'Enable Logs', 'gitwire' ) }
						onChange={ handleEnableLoggingChange }
					/>
					{ enableLogging && (
						<>
							<Spacer marginTop={ 4 } />
							<ToggleGroupControl
								__nextHasNoMarginBottom
								isBlock
								label={ __( 'Log Level', 'gitwire' ) }
								help={ __(
									'Errors only records failed operations. All activity includes installs, activations, and connections.',
									'gitwire'
								) }
								value={ logLevel }
								onChange={ handleLogLevelChange }
							>
								<ToggleGroupControlOption
									label={ __( 'All Activity', 'gitwire' ) }
									value="activity"
								/>
								<ToggleGroupControlOption
									label={ __( 'Errors Only', 'gitwire' ) }
									value="error"
								/>
							</ToggleGroupControl>
							<Spacer marginTop={ 4 } />
							<ToggleGroupControl
								__nextHasNoMarginBottom
								isBlock
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
								value={ logRetentionDays }
								onChange={ handleLogRetentionChange }
							>
								<ToggleGroupControlOption
									label={ __( '7 days', 'gitwire' ) }
									value="7"
								/>
								<ToggleGroupControlOption
									label={ __( '15 days', 'gitwire' ) }
									value="15"
								/>
								<ToggleGroupControlOption
									label={ __( '30 days', 'gitwire' ) }
									value="30"
								/>
							</ToggleGroupControl>
						</>
					) }
				</CardBody>
			</Card>
		</div>
	);
}

function BrowseDetectionCard( { settings, onSave } ) {
	const [ smartInstall, setSmartInstall ] = useState(
		settings.smart_install !== false
	);
	const [ savingSi, setSavingSi ] = useState( false );
	const [ autoDetectType, setAutoDetectType ] = useState(
		settings.auto_detect_type !== false
	);
	const [ reposPerPage, setReposPerPage ] = useState(
		settings.repos_per_page ?? 50
	);
	const [ excludedRepos, setExcludedRepos ] = useState(
		settings.excluded_repos ?? []
	);
	const [ maxReposPerSource, setMaxReposPerSource ] = useState(
		String( settings.max_repos_per_source ?? 'unlimited' )
	);
	const [ repositoriesRefreshFrequency, setRepositoriesRefreshFrequency ] = useState(
		settings.repositories_refresh_frequency ?? 'daily'
	);
	const [ backgroundTypeDetection, setBackgroundTypeDetection ] = useState(
		!! settings.background_type_detection
	);
	const [ shallowDetection, setShallowDetection ] = useState(
		!! settings.shallow_detection
	);
	const [ repoSuggestions, setRepoSuggestions ] = useState( [] );
	const [ excludedRepoInput, setExcludedRepoInput ] = useState( '' );

	useEffect( () => {
		api.getRepos( { offset: 0 } )
			.then( ( result ) => {
				setRepoSuggestions(
					( result.repositories ?? [] ).map( ( r ) => r.full_name )
				);
			} )
			.catch( () => {} );
	}, [] );

	const save = ( payload, rollback ) =>
		persistSetting( payload, onSave, rollback );

	const detectionActive = autoDetectType || smartInstall;

	const handleSmartInstallChange = ( newVal ) => {
		setSmartInstall( newVal );
		const payload = { smart_install: newVal };
		if ( newVal && ! autoDetectType ) {
			setAutoDetectType( true );
			payload.auto_detect_type = true;
		}
		setSavingSi( true );
		save( payload, () => {
			setSmartInstall( ! newVal );
			if ( newVal && ! autoDetectType ) {
				setAutoDetectType( false );
			}
		} )
			.finally( () => setSavingSi( false ) )
			.catch( () => {} );
	};

	const handleAutoDetectTypeChange = ( newVal ) => {
		setAutoDetectType( newVal );
		const payload = { auto_detect_type: newVal };
		const prevBackground = backgroundTypeDetection;
		const prevShallow = shallowDetection;
		if ( ! newVal ) {
			if ( backgroundTypeDetection ) {
				setBackgroundTypeDetection( false );
				payload.background_type_detection = false;
			}
			if ( shallowDetection ) {
				setShallowDetection( false );
				payload.shallow_detection = false;
			}
		}
		save( payload, () => {
			setAutoDetectType( ! newVal );
			if ( ! newVal ) {
				setBackgroundTypeDetection( prevBackground );
				setShallowDetection( prevShallow );
			}
		} ).catch( () => {} );
	};

	const handleReposPerPageChange = ( newVal ) => {
		setReposPerPage( newVal );
		save( { repos_per_page: newVal } ).catch( () => {} );
	};

	const handleExcludedReposChange = ( tokens ) => {
		const valid = tokens.filter( ( t ) =>
			/^[a-zA-Z0-9._-]+\/[a-zA-Z0-9._-]+$/.test( t )
		);
		if ( valid.length < tokens.length ) {
			toast.error(
				__( 'Use owner/repo format, e.g. acme/my-plugin.', 'gitwire' )
			);
		}
		setExcludedRepos( valid );
		save( { excluded_repos: valid } ).catch( () => {} );
	};

	const handleMaxReposPerSourceChange = ( newVal ) => {
		setMaxReposPerSource( newVal );
		const parsed =
			'unlimited' === newVal ? 'unlimited' : parseInt( newVal, 10 );
		save( { max_repos_per_source: parsed } ).catch( () => {} );
	};

	const handleRepositoriesRefreshFrequencyChange = ( newVal ) => {
		setRepositoriesRefreshFrequency( newVal );
		save( { repositories_refresh_frequency: newVal }, () =>
			setRepositoriesRefreshFrequency( repositoriesRefreshFrequency )
		).catch( () => {} );
	};

	const handleBackgroundTypeDetectionChange = ( newVal ) => {
		setBackgroundTypeDetection( newVal );
		save( { background_type_detection: newVal }, () =>
			setBackgroundTypeDetection( ! newVal )
		).catch( () => {} );
	};

	const handleShallowDetectionChange = ( newVal ) => {
		setShallowDetection( newVal );
		save( { shallow_detection: newVal }, () =>
			setShallowDetection( ! newVal )
		).catch( () => {} );
	};

	return (
		<Card>
			<CardHeader>
				<Heading level={ 4 }>
					{ __( 'Browse & Detection', 'gitwire' ) }
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
							{ __( 'Smart Install', 'gitwire' ) }{ ' ' }
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
					checked={ detectionActive }
					disabled={ smartInstall }
					help={
						smartInstall
							? __(
									'Smart Install requires type detection.',
									'gitwire'
							  )
							: __(
									'When off, Gitwire asks whether to install as plugin or theme at install time.',
									'gitwire'
							  )
					}
					label={ __( 'Auto-Detect Repository Type', 'gitwire' ) }
					onChange={ handleAutoDetectTypeChange }
				/>

				<Spacer marginTop={ 4 } />

				<ToggleControl
					__nextHasNoMarginBottom
					checked={ backgroundTypeDetection }
					disabled={ ! detectionActive }
					help={ __(
						'Detect types for unscanned repos in the background each cron cycle. Best for large collections.',
						'gitwire'
					) }
					label={ __( 'Background Type Pre-Detection', 'gitwire' ) }
					onChange={ handleBackgroundTypeDetectionChange }
				/>

				<Spacer marginTop={ 4 } />

				<ToggleControl
					__nextHasNoMarginBottom
					checked={ shallowDetection }
					disabled={ ! detectionActive }
					help={ __(
						'Skip full file scans on re-detection when stored key files still match. Saves API calls on large collections.',
						'gitwire'
					) }
					label={ __( 'Shallow Detection', 'gitwire' ) }
					onChange={ handleShallowDetectionChange }
				/>

				<Spacer marginTop={ 4 } />

				<ToggleGroupControl
					__nextHasNoMarginBottom
					isBlock
					label={ __( 'Repositories per Page', 'gitwire' ) }
					help={ __(
						'Number of repositories shown per page in the Browse tab.',
						'gitwire'
					) }
					value={ reposPerPage }
					onChange={ handleReposPerPageChange }
				>
					<ToggleGroupControlOption label="10" value={ 10 } />
					<ToggleGroupControlOption label="20" value={ 20 } />
					<ToggleGroupControlOption label="50" value={ 50 } />
					<ToggleGroupControlOption label="100" value={ 100 } />
				</ToggleGroupControl>

				<Spacer marginTop={ 4 } />

				<FormTokenField
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ __( 'Excluded Repositories', 'gitwire' ) }
					help={ __(
						'Repositories never shown in Browse. Use owner/repo format, one per entry.',
						'gitwire'
					) }
					value={ excludedRepos }
					suggestions={
						excludedRepoInput.trim().length >= 2
							? repoSuggestions
							: []
					}
					onChange={ handleExcludedReposChange }
					onInputChange={ setExcludedRepoInput }
					tokenizeOnSpace={ false }
					__experimentalExpandOnFocus={
						excludedRepoInput.trim().length >= 2
					}
				/>

				<Spacer marginTop={ 4 } />

				<ToggleGroupControl
					__nextHasNoMarginBottom
					isBlock
					label={ __(
						'Repository Refresh Frequency',
						'gitwire'
					) }
					help={ __(
						'How often the repository list is refreshed in the background.',
						'gitwire'
					) }
					value={ repositoriesRefreshFrequency }
					onChange={ handleRepositoriesRefreshFrequencyChange }
				>
					<ToggleGroupControlOption
						label={ __( 'Hourly', 'gitwire' ) }
						value="hourly"
					/>
					<ToggleGroupControlOption
						label={ __( 'Twice Daily', 'gitwire' ) }
						value="twicedaily"
					/>
					<ToggleGroupControlOption
						label={ __( 'Daily', 'gitwire' ) }
						value="daily"
					/>
					<ToggleGroupControlOption
						label={ __( 'Weekly', 'gitwire' ) }
						value="weekly"
					/>
				</ToggleGroupControl>

				<Spacer marginTop={ 4 } />

				<ToggleGroupControl
					__nextHasNoMarginBottom
					isBlock
					label={ __( 'Max per Source', 'gitwire' ) }
					help={ __(
						'Cap total repos fetched per connection per cron cycle.',
						'gitwire'
					) }
					value={ maxReposPerSource }
					onChange={ handleMaxReposPerSourceChange }
				>
					<ToggleGroupControlOption label="100" value="100" />
					<ToggleGroupControlOption label="250" value="250" />
					<ToggleGroupControlOption label="500" value="500" />
					<ToggleGroupControlOption
						label={ __( 'No limit', 'gitwire' ) }
						value="unlimited"
					/>
				</ToggleGroupControl>
			</CardBody>
		</Card>
	);
}

function InstalledUpdatesCard( { settings, onSave } ) {
	const [ showRepoLabel, setShowRepoLabel ] = useState(
		settings.show_repo_label !== false
	);
	const [ blockOnFatal, setBlockOnFatal ] = useState(
		settings.block_on_fatal !== false
	);
	const [ updateCheckInterval, setUpdateCheckInterval ] = useState(
		settings.update_check_interval ?? 'halfhourly'
	);

	const save = ( payload, rollback ) =>
		persistSetting( payload, onSave, rollback );

	const handleShowRepoLabelChange = ( newVal ) => {
		setShowRepoLabel( newVal );
		save( { show_repo_label: newVal }, () =>
			setShowRepoLabel( ! newVal )
		).catch( () => {} );
	};

	const handleBlockOnFatalChange = ( newVal ) => {
		setBlockOnFatal( newVal );
		save( { block_on_fatal: newVal }, () =>
			setBlockOnFatal( ! newVal )
		).catch( () => {} );
	};

	const handleUpdateCheckIntervalChange = ( newVal ) => {
		setUpdateCheckInterval( newVal );
		save( { update_check_interval: newVal }, () =>
			setUpdateCheckInterval( updateCheckInterval )
		).catch( () => {} );
	};

	return (
		<Card>
			<CardHeader>
				<Heading level={ 4 }>
					{ __( 'Installed & Updates', 'gitwire' ) }
				</Heading>
			</CardHeader>
			<CardBody>
				<ToggleControl
					__nextHasNoMarginBottom
					checked={ showRepoLabel }
					help={ __(
						'Shows a [Gitwire] label next to managed plugin and theme names on the Plugins and Themes screens.',
						'gitwire'
					) }
					label={ __( 'Repo Label', 'gitwire' ) }
					onChange={ handleShowRepoLabelChange }
				/>

				<Spacer marginTop={ 4 } />

				<ToggleControl
					__nextHasNoMarginBottom
					checked={ blockOnFatal }
					help={
						blockOnFatal
							? __(
									'A fatal commit is blocked permanently until a new commit is detected on the branch.',
									'gitwire'
							  )
							: __(
									'A fatal commit will still be blocked for 5 minutes per retry due to an internal rate limit.',
									'gitwire'
							  )
					}
					label={ __( 'Block on Fatal Error', 'gitwire' ) }
					onChange={ handleBlockOnFatalChange }
				/>

				<Spacer marginTop={ 4 } />

				<ToggleGroupControl
					__nextHasNoMarginBottom
					isBlock
					label={ __( 'Update Check Frequency', 'gitwire' ) }
					help={ __(
						'How often Gitwire checks installed repositories for new commits. Applies to all installed repositories, independent of auto-update.',
						'gitwire'
					) }
					value={ updateCheckInterval }
					onChange={ handleUpdateCheckIntervalChange }
				>
					<ToggleGroupControlOption
						label={ __( '5 Minutes', 'gitwire' ) }
						value="everyfiveminutes"
					/>
					<ToggleGroupControlOption
						label={ __( 'Half Hourly', 'gitwire' ) }
						value="halfhourly"
					/>
					<ToggleGroupControlOption
						label={ __( 'Hourly', 'gitwire' ) }
						value="hourly"
					/>
					<ToggleGroupControlOption
						label={ __( 'Twice Daily', 'gitwire' ) }
						value="twicedaily"
					/>
					<ToggleGroupControlOption
						label={ __( 'Daily', 'gitwire' ) }
						value="daily"
					/>
					<ToggleGroupControlOption
						label={ __( 'Never', 'gitwire' ) }
						value="never"
					/>
				</ToggleGroupControl>
			</CardBody>
		</Card>
	);
}

function PublicConnectionsCard( { connections, onChange } ) {
	const [ selectedId, setSelectedId ] = useState( null );
	const [ rateCache, setRateCache ] = useState(
		() => window.gitwire?.connections_metadata ?? {}
	);

	useEffect( () => {
		const now = Math.floor( Date.now() / 1000 );
		const fifteenMin = 15 * 60;
		connections
			.filter( ( c ) => 'github' === c.provider )
			.filter( ( c ) => {
				const checkedAt = rateCache[ c.id ]?.checked_at;
				return ! checkedAt || now - checkedAt > fifteenMin;
			} )
			.forEach( ( conn ) => {
				api.getPublicConnectionRateLimit( conn.id )
					.then( ( data ) => {
						if ( data ) {
							setRateCache( ( prev ) => ( {
								...prev,
								[ conn.id ]: data,
							} ) );
						}
					} )
					.catch( () => {} );
			} );
	}, [] ); // eslint-disable-line react-hooks/exhaustive-deps

	const handleCreated = useCallback(
		( conn, metadata ) => {
			onChange( [ ...connections, conn ] );
			if ( metadata ) {
				setRateCache( ( prev ) => ( {
					...prev,
					[ conn.id ]: metadata,
				} ) );
			}
			if ( 'github' === conn.provider ) {
				api.getPublicConnectionRateLimit( conn.id )
					.then( ( data ) => {
						if ( data ) {
							setRateCache( ( prev ) => ( {
								...prev,
								[ conn.id ]: data,
							} ) );
						}
					} )
					.catch( () => {} );
			}
		},
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
		return rec ? (
			<PublicConnectionDetail
				rateData={ rateCache[ rec.id ] ?? null }
				rec={ rec }
				onBack={ () => setSelectedId( null ) }
				onRemoved={ handleRemoved }
			/>
		) : null;
	}

	return (
		<PublicConnectionsSummary
			connections={ connections }
			rateCache={ rateCache }
			onCreated={ handleCreated }
			onSelect={ setSelectedId }
		/>
	);
}

function PublicConnectionsSummary( {
	connections,
	rateCache,
	onCreated,
	onSelect,
} ) {
	const [ adding, setAdding ] = useState( false );

	const handleCreated = ( conn, metadata ) => {
		onCreated( conn, metadata );
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
									{ rateCache[ conn.id ]?.avatar_url ||
									conn.avatar_url ? (
										<img
											alt=""
											aria-hidden="true"
											height={ 24 }
											src={
												rateCache[ conn.id ]
													?.avatar_url ||
												conn.avatar_url
											}
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
										@
										{ rateCache[ conn.id ]?.username ||
											conn.identifier }
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

function gravatarFallback( identifier ) {
	let h = 5381;
	const s = String( identifier || '' )
		.toLowerCase()
		.trim();
	for ( let i = 0; i < s.length; i++ ) {
		h = ( Math.imul( 33, h ) ^ s.charCodeAt( i ) ) >>> 0; // eslint-disable-line no-bitwise
	}
	return `https://www.gravatar.com/avatar/${ h
		.toString( 16 )
		.padStart( 32, '0' ) }?d=identicon&s=96`;
}

function PublicConnectionDetail( { rec, rateData, onBack, onRemoved } ) {
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
					{ onBack && (
						<FlexItem>
							<Button
								icon="arrow-left-alt2"
								iconSize={ 18 }
								label={ __( 'Back to Connections', 'gitwire' ) }
								size="compact"
								variant="tertiary"
								onClick={ onBack }
							/>
						</FlexItem>
					) }
					<FlexItem>
						<ProviderIcon
							provider={ rec.provider }
							size={ 15 }
							variant="brand"
						/>
					</FlexItem>
					<FlexBlock>
						<strong style={ { fontSize: 15, lineHeight: 1.5 } }>
							{ provLabel }
						</strong>
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
					<img
						alt={ rateData?.username || rec.identifier }
						height={ 44 }
						src={
							rateData?.avatar_url ||
							rec.avatar_url ||
							gravatarFallback(
								rateData?.username || rec.identifier
							)
						}
						style={ {
							borderRadius: '50%',
							display: 'block',
							flexShrink: 0,
						} }
						width={ 44 }
					/>
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
							@{ rateData?.username || rec.identifier }
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
								{ relativeTimeFromUnix( checkedAt ) }
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
									"Unauthenticated requests share your server's IP limit.",
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
									'lets you connect with a token for 5,000 requests/hour.',
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
			onCreated( result.connection, result.metadata ?? null );
		} catch ( e ) {
			setUsernameError( true );
			toast.error(
				e?.message || __( 'Could not add connection.', 'gitwire' )
			);
		} finally {
			setSaving( false );
		}
	};

	const usernamePlaceholder = {
		github: 'your-github-username',
		gitlab: 'your-gitlab-username',
		bitbucket: 'your-workspace',
	}[ provider ];
	const usernameLabel =
		'bitbucket' === provider
			? __( 'Workspace', 'gitwire' )
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
								{ __( 'Instance URL', 'gitwire' ) }{ ' ' }
								<span className="gitwire-label-optional">
									{ __( '(Optional)', 'gitwire' ) }
								</span>
							</>
						}
						placeholder="https://git.yourdomain.com"
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
