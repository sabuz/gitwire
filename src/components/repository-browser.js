import { toast } from '../toast';

import { __, sprintf } from '@wordpress/i18n';
import {
	useState,
	useEffect,
	useCallback,
	useMemo,
	useRef,
	memo,
} from '@wordpress/element';
import { chevronDown } from '@wordpress/icons';
import {
	Button,
	Dropdown,
	DropdownMenu,
	MenuGroup,
	MenuItem,
	Spinner,
	Flex,
	FlexBlock,
	FlexItem,
	Card,
	CardBody,
	SearchControl,
	Tooltip,
} from '@wordpress/components';

import * as api from '../api';
import ExternalLinkIcon from './external-link-icon';
import {
	detectionKey,
	useRepositoryDetection,
} from '../hooks/use-repository-detection';
import InstallModal from './install-modal';
import { ProviderIcon, providerLabel } from './provider';
import { relativeTimeFromDate } from '../relative-time';

const ListFilterIcon = () => (
	<svg
		fill="none"
		height="16"
		stroke="currentColor"
		strokeLinecap="round"
		strokeLinejoin="round"
		strokeWidth="2"
		viewBox="0 0 24 24"
		width="16"
		xmlns="http://www.w3.org/2000/svg"
	>
		<path d="M2 5h20" />
		<path d="M6 12h12" />
		<path d="M9 19h6" />
	</svg>
);

const SelectAllIcon = () => (
	<svg
		fill="none"
		height="14"
		viewBox="0 0 14 14"
		width="14"
		xmlns="http://www.w3.org/2000/svg"
	>
		<path
			d="M11.5 2a.5.5 0 000 1h2a.5.5 0 000-1h-2zM9.3 2.6a.5.5 0 01.1.7l-5.995 7.993a.505.505 0 01-.37.206.5.5 0 01-.395-.152L.146 8.854a.5.5 0 11.708-.708l2.092 2.093L8.6 2.7a.5.5 0 01.7-.1zM11 7a.5.5 0 01.5-.5h2a.5.5 0 010 1h-2A.5.5 0 0111 7zM11.5 11a.5.5 0 000 1h2a.5.5 0 000-1h-2z"
			fill="currentColor"
		/>
	</svg>
);

const ClearAllIcon = () => (
	<svg
		fill="none"
		height="14"
		viewBox="0 0 14 14"
		width="14"
		xmlns="http://www.w3.org/2000/svg"
	>
		<path
			clipRule="evenodd"
			d="M9.621 3.914l.379.379 3.146-3.147a.5.5 0 01.708.708L10.707 5l.379.379a3 3 0 010 4.242l-.707.707-.005.005-.008.008-.012.013-1.733 1.732a3 3 0 01-4.242 0L.146 7.854a.5.5 0 01.708-.707.915.915 0 001.292 0L4.64 4.654a.52.52 0 01.007-.008l.733-.732a3 3 0 014.242 0zm-4.26 1.432l.139-.139 3.146 3.147a.5.5 0 10.708-.707L6.212 4.505a2 2 0 012.702.116l.731.731.001.002h.002l.73.732a2 2 0 010 2.828l-.706.707-.012.013a.503.503 0 00-.014.013l-1.732 1.732a2 2 0 01-2.828 0L3.354 9.647a2.489 2.489 0 001.414-.708l1.086-1.085a.5.5 0 10-.708-.707L4.061 8.232a1.5 1.5 0 01-2.01.102c.294-.088.57-.248.803-.48l2.5-2.5a.475.475 0 00.007-.008z"
			fill="currentColor"
			fillRule="evenodd"
		/>
		<path
			d="M2 5.004a1 1 0 11-2 0 1 1 0 012 0zM4 3.25a.75.75 0 11-1.5 0 .75.75 0 011.5 0z"
			fill="currentColor"
		/>
	</svg>
);

function FilterOption( { label, checked, onChange } ) {
	return (
		// eslint-disable-next-line jsx-a11y/label-has-associated-control
		<label className="gitwire-filter-option">
			<input checked={ checked } type="checkbox" onChange={ onChange } />
			<span className="gitwire-filter-option__label">{ label }</span>
		</label>
	);
}

/**
 * @param {Object} installed  Installed repositories map from app state.
 * @param {Object} repository Browse repository record.
 * @return {Object|null} Matching installed record, if any.
 */
function lookupInstalled( installed, repository ) {
	return (
		installed[ `${ repository.provider }:${ repository.full_name }` ] ??
		null
	);
}

/**
 * Browse panel listing supported provider repositories with detection and install actions.
 *
 * @param {Object}   props                    Component props.
 * @param {Array}    props.connections        Connection records array.
 * @param {Object}   props.settings           Plugin settings.
 * @param {Object}   props.installed          Map of installed repositories.
 * @param {Function} [props.onPostInstall]    Standalone mode: called after install completes.
 * @param {Function} [props.onInstallRequest] Modal mode callback with the repository and detection result.
 * @param {Function} [props.onGoToSettings]   Navigates to the Settings tab.
 * @param {Function} [props.onOpenUrlImport]  Opens the Import from URL modal.
 * @return {JSX.Element} The rendered browse panel.
 */
export default function RepositoryBrowser( {
	connections,
	settings,
	installed,
	onPostInstall,
	onInstallRequest,
	onGoToSettings,
	onOpenUrlImport,
} ) {
	const connectionsByProvider = useMemo( () => {
		const map = {};
		( connections ?? [] ).forEach( ( c ) => {
			if ( ! map[ c.provider ] ) {
				map[ c.provider ] = [];
			}
			map[ c.provider ].push( c );
		} );
		return map;
	}, [ connections ] );

	const hasGitHub = !! connectionsByProvider.github?.length;
	const hasGitLab = !! connectionsByProvider.gitlab?.length;
	const hasBitbucket = !! connectionsByProvider.bitbucket?.length;
	const showSourceBadge =
		[ hasGitHub, hasGitLab, hasBitbucket ].filter( Boolean ).length > 1;

	const { detections, paused, runBatch, seedFromRepositories, reset } =
		useRepositoryDetection();

	const [ repositories, setRepositories ] = useState( [] );
	const [ hasMore, setHasMore ] = useState( false );
	const [ loading, setLoading ] = useState( false );
	const [ modal, setModal ] = useState( null );
	const [ search, setSearch ] = useState( '' );
	const [ activeTypeFilters, setActiveTypeFilters ] = useState( [] );
	const [ activeSourceFilters, setActiveSourceFilters ] = useState( [] );
	const prevConnIdsRef = useRef( null );
	const searchTimerRef = useRef( null );
	const isFirstSearchRef = useRef( true );
	const isFirstSourceRef = useRef( true );
	// Keep the source-filter ref current so the search debounce uses the latest filters.
	const activeSourceFiltersRef = useRef( activeSourceFilters );
	activeSourceFiltersRef.current = activeSourceFilters;
	const smartInstall = settings?.smart_install !== false;
	/*
	 * This flag controls whether type-related controls appear in the UI. The server
	 * already accounts for Smart Install when returning this setting.
	 */
	const autoDetectType = settings?.auto_detect_type !== false;
	// Show type filters only when type detection is enabled; source filters remain independent.
	const hasAnyFilters = autoDetectType || showSourceBadge;

	const loadRepositories = useCallback(
		async (
			offset,
			append = false,
			searchTerm = '',
			sourceFilters = []
		) => {
			const connectionIds = sourceFilters.length
				? ( connections ?? [] )
						.filter( ( c ) => sourceFilters.includes( c.provider ) )
						.map( ( c ) => c.id )
				: [];
			setLoading( true );
			try {
				const result = await api.getRepositories( {
					offset,
					search: searchTerm,
					connectionIds,
				} );
				const fetchedRepositories = result.repositories ?? [];
				setRepositories( ( prev ) =>
					append
						? [ ...prev, ...fetchedRepositories ]
						: fetchedRepositories
				);
				setHasMore( result.has_more ?? false );
				seedFromRepositories( fetchedRepositories );
				( result.connection_errors ?? [] ).forEach( ( err ) => {
					toast.error(
						sprintf(
							/* translators: 1: provider name (e.g. GitLab), 2: error message */
							__( '%1$s: %2$s', 'gitwire' ),
							providerLabel( err.provider ),
							err.message
						)
					);
				} );
				( result.connection_warnings ?? [] ).forEach( ( warn ) => {
					toast.warning(
						sprintf(
							/* translators: 1: provider name (e.g. GitLab), 2: notice message */
							__( '%1$s: %2$s', 'gitwire' ),
							providerLabel( warn.provider ),
							warn.message
						)
					);
				} );
				if ( autoDetectType ) {
					runBatch(
						fetchedRepositories.filter(
							( repository ) =>
								! lookupInstalled( installed, repository )
						)
					);
				}
			} catch ( e ) {
				toast.error(
					e.message || __( 'Failed to load repositories.', 'gitwire' )
				);
			} finally {
				setLoading( false );
			}
		},
		[
			autoDetectType,
			connections,
			installed,
			runBatch,
			seedFromRepositories,
		]
	);

	const connIds = ( connections ?? [] ).map( ( c ) => c.id ).join( ',' );

	useEffect( () => {
		if (
			prevConnIdsRef.current === connIds &&
			prevConnIdsRef.current !== null
		) {
			return;
		}
		prevConnIdsRef.current = connIds;
		setRepositories( [] );
		setHasMore( false );
		reset();
		loadRepositories( 0, false, '' );
	}, [ connIds ] ); // eslint-disable-line react-hooks/exhaustive-deps

	// Keep the current list visible until the refetch completes so failures preserve it.
	const handleRefresh = useCallback(
		async ( mode = 'repositories' ) => {
			setLoading( true );
			try {
				const result = await api.clearCache( mode );
				( result?.connection_errors ?? [] ).forEach( ( err ) => {
					toast.error(
						sprintf(
							/* translators: 1: provider name (e.g. GitLab), 2: error message */
							__( '%1$s: %2$s', 'gitwire' ),
							providerLabel( err.provider ),
							err.message
						)
					);
				} );
				reset();
				await loadRepositories( 0, false, search, activeSourceFilters );
			} catch ( e ) {
				toast.error(
					e.message ||
						__( 'Failed to refresh repositories.', 'gitwire' )
				);
				setLoading( false );
			}
		},
		[ loadRepositories, reset, search, activeSourceFilters ]
	);

	const handleLoadMore = () => {
		loadRepositories(
			repositories.length,
			true,
			search,
			activeSourceFilters
		);
	};

	// Reload the server results after the search term changes, except on initial mount.
	useEffect( () => {
		if ( isFirstSearchRef.current ) {
			isFirstSearchRef.current = false;
			return;
		}
		clearTimeout( searchTimerRef.current );
		searchTimerRef.current = setTimeout( () => {
			loadRepositories(
				0,
				false,
				search,
				activeSourceFiltersRef.current
			);
		}, 350 );
		return () => clearTimeout( searchTimerRef.current );
	}, [ search ] ); // eslint-disable-line react-hooks/exhaustive-deps

	// Reload the results when source filters change, except on initial mount.
	useEffect( () => {
		if ( isFirstSourceRef.current ) {
			isFirstSourceRef.current = false;
			return;
		}
		setRepositories( [] );
		setHasMore( false );
		loadRepositories( 0, false, search, activeSourceFilters );
	}, [ activeSourceFilters ] ); // eslint-disable-line react-hooks/exhaustive-deps

	const toggleTypeFilter = ( value ) => {
		setActiveTypeFilters( ( prev ) =>
			prev.includes( value )
				? prev.filter( ( v ) => v !== value )
				: [ ...prev, value ]
		);
	};

	const toggleSourceFilter = ( value ) => {
		setActiveSourceFilters( ( prev ) =>
			prev.includes( value )
				? prev.filter( ( v ) => v !== value )
				: [ ...prev, value ]
		);
	};

	// Exclude hidden type filters from the active-filter count and Clear Filters label.
	const activeFilterCount =
		( autoDetectType ? activeTypeFilters.length : 0 ) +
		activeSourceFilters.length;

	const allTypeOptions = useMemo( () => {
		const seen = new Set();
		repositories.forEach( ( r ) => {
			const installedRec = lookupInstalled( installed, r );
			const type =
				installedRec?.type ?? detections[ detectionKey( r ) ]?.type;
			if ( type ) {
				seen.add( type );
			}
		} );
		return [ 'plugin', 'block-theme', 'classic-theme', 'unknown' ].filter(
			( t ) => seen.has( t )
		);
	}, [ repositories, installed, detections ] );
	const allSourceOptions = [
		hasGitHub && 'github',
		hasGitLab && 'gitlab',
		hasBitbucket && 'bitbucket',
	].filter( Boolean );
	const totalOptions =
		( autoDetectType ? allTypeOptions.length : 0 ) +
		( showSourceBadge ? allSourceOptions.length : 0 );
	const allSelected = activeFilterCount === totalOptions;

	const handleSelectAll = () => {
		if ( activeFilterCount > 0 ) {
			setActiveTypeFilters( [] );
			setActiveSourceFilters( [] );
		} else {
			if ( autoDetectType ) {
				setActiveTypeFilters( [ ...allTypeOptions ] );
			}
			if ( showSourceBadge ) {
				setActiveSourceFilters( [ ...allSourceOptions ] );
			}
		}
	};

	const matchesSearch = ( r ) => {
		if ( ! search.trim() ) {
			return true;
		}
		return r.full_name.toLowerCase().includes( search.toLowerCase() );
	};

	const matchesType = ( r ) => {
		// Ignore type filters when automatic type detection is disabled because those controls are hidden.
		if ( ! autoDetectType || activeTypeFilters.length === 0 ) {
			return true;
		}
		const installedRec = lookupInstalled( installed, r );
		const type =
			installedRec?.type ?? detections[ detectionKey( r ) ]?.type;
		if ( ! type ) {
			return true;
		}
		return activeTypeFilters.includes( type );
	};

	const matchesSource = ( r ) => {
		if ( activeSourceFilters.length === 0 ) {
			return true;
		}
		return activeSourceFilters.includes( r.provider ?? 'github' );
	};

	const filtered = repositories.filter(
		( r ) => matchesSearch( r ) && matchesType( r ) && matchesSource( r )
	);

	if ( ! hasGitHub && ! hasGitLab && ! hasBitbucket ) {
		return (
			<div className="gitwire-browse-no-connection">
				<img
					alt=""
					aria-hidden="true"
					src={ window.gitwire?.disconnected_url }
				/>
				<h2>{ __( 'No Account Added', 'gitwire' ) }</h2>
				<p>
					{ __(
						'Add a GitHub, GitLab, or Bitbucket account in Settings to browse and install from its public repositories.',
						'gitwire'
					) }
				</p>
				<Flex align="center" gap={ 2 } justify="center">
					{ onGoToSettings && (
						<Button variant="primary" onClick={ onGoToSettings }>
							{ __( 'Go to Settings', 'gitwire' ) }
						</Button>
					) }
					{ onOpenUrlImport && (
						<Button variant="secondary" onClick={ onOpenUrlImport }>
							{ __( 'Import from URL', 'gitwire' ) }
						</Button>
					) }
				</Flex>
			</div>
		);
	}

	return (
		<div className="gitwire-browse">
			<Flex
				align="center"
				className="gitwire-browse-toolbar"
				gap={ 2 }
				justify="flex-start"
				style={ { marginBottom: 24 } }
			>
				<FlexBlock style={ { maxWidth: 280 } }>
					<SearchControl
						__nextHasNoMarginBottom
						size="__unstable-large"
						onChange={ setSearch }
						placeholder={ __( 'Search repositories…', 'gitwire' ) }
						value={ search }
					/>
				</FlexBlock>
				{ hasAnyFilters && (
					<>
						<FlexItem>
							<Dropdown
								popoverProps={ {
									placement: 'bottom-start',
									className: 'gitwire-filter-dropdown',
									focusOnMount: 'container',
								} }
								renderToggle={ ( { isOpen, onToggle } ) => (
									<div
										style={ {
											position: 'relative',
											display: 'inline-flex',
										} }
									>
										<Button
											aria-expanded={ isOpen }
											className={
												activeFilterCount > 0
													? 'gitwire-filter-btn is-active'
													: 'gitwire-filter-btn'
											}
											icon={ ListFilterIcon }
											label={ __( 'Filter', 'gitwire' ) }
											variant="secondary"
											onClick={ onToggle }
										/>
										{ activeFilterCount > 0 && (
											<span
												aria-hidden="true"
												className="gitwire-filter-dot"
											/>
										) }
									</div>
								) }
								renderContent={ () => (
									<div className="gitwire-filter-popover">
										<div className="gitwire-filter-popover__header">
											<Button
												icon={
													activeFilterCount > 0
														? ClearAllIcon
														: SelectAllIcon
												}
												size="compact"
												variant="tertiary"
												onClick={ handleSelectAll }
											>
												{ activeFilterCount > 0
													? __(
															'Clear Filters',
															'gitwire'
													  )
													: __(
															'Select All',
															'gitwire'
													  ) }
											</Button>
										</div>

										{ autoDetectType && (
											<ul className="gitwire-filter-popover__list">
												{ [
													{
														id: 'plugin',
														label: __(
															'Plugin',
															'gitwire'
														),
													},
													{
														id: 'block-theme',
														label: __(
															'Block Theme',
															'gitwire'
														),
													},
													{
														id: 'classic-theme',
														label: __(
															'Classic Theme',
															'gitwire'
														),
													},
													{
														id: 'unknown',
														label: __(
															'Unknown',
															'gitwire'
														),
													},
												].map( ( { id, label } ) => (
													<li key={ id }>
														<FilterOption
															checked={ activeTypeFilters.includes(
																id
															) }
															label={ label }
															onChange={ () =>
																toggleTypeFilter(
																	id
																)
															}
														/>
													</li>
												) ) }
											</ul>
										) }

										{ showSourceBadge && (
											<ul className="gitwire-filter-popover__list">
												{ hasGitHub && (
													<li>
														<FilterOption
															checked={ activeSourceFilters.includes(
																'github'
															) }
															label="GitHub"
															onChange={ () =>
																toggleSourceFilter(
																	'github'
																)
															}
														/>
													</li>
												) }
												{ hasGitLab && (
													<li>
														<FilterOption
															checked={ activeSourceFilters.includes(
																'gitlab'
															) }
															label="GitLab"
															onChange={ () =>
																toggleSourceFilter(
																	'gitlab'
																)
															}
														/>
													</li>
												) }
												{ hasBitbucket && (
													<li>
														<FilterOption
															checked={ activeSourceFilters.includes(
																'bitbucket'
															) }
															label="Bitbucket"
															onChange={ () =>
																toggleSourceFilter(
																	'bitbucket'
																)
															}
														/>
													</li>
												) }
											</ul>
										) }
									</div>
								) }
							/>
						</FlexItem>
						<FlexItem>
							<div
								className="gitwire-toolbar-divider"
								aria-hidden="true"
							/>
						</FlexItem>
					</>
				) }
				<FlexItem>
					<div className="gitwire-refresh">
						<Button
							className={
								autoDetectType
									? 'gitwire-refresh__main'
									: undefined
							}
							disabled={ loading }
							isBusy={ loading }
							variant="secondary"
							onClick={ () => handleRefresh( 'repositories' ) }
						>
							{ __( 'Refresh Repositories', 'gitwire' ) }
						</Button>
						{ autoDetectType && (
							<DropdownMenu
								icon={ chevronDown }
								label={ __( 'Refresh Options', 'gitwire' ) }
								popoverProps={ {
									placement: 'bottom-end',
									className: 'gitwire-refresh-dropdown',
									focusOnMount: 'container',
								} }
								toggleProps={ {
									className: 'gitwire-refresh__toggle',
									disabled: loading,
									variant: 'secondary',
								} }
							>
								{ ( { onClose } ) => (
									<MenuGroup>
										<MenuItem
											info={ __(
												'Re-detects every type. Uses more API calls.',
												'gitwire'
											) }
											onClick={ () => {
												onClose();
												handleRefresh(
													'repositories_and_types'
												);
											} }
										>
											{ __(
												'Refresh Repositories & Types',
												'gitwire'
											) }
										</MenuItem>
										<MenuItem
											info={ __(
												'Keeps the list, re-detects types only.',
												'gitwire'
											) }
											onClick={ () => {
												onClose();
												handleRefresh( 'types' );
											} }
										>
											{ __(
												'Refresh Types Only',
												'gitwire'
											) }
										</MenuItem>
									</MenuGroup>
								) }
							</DropdownMenu>
						) }
					</div>
				</FlexItem>
				{ onOpenUrlImport && (
					<FlexItem>
						<Button variant="secondary" onClick={ onOpenUrlImport }>
							{ __( 'Import from URL', 'gitwire' ) }
						</Button>
					</FlexItem>
				) }
			</Flex>

			{ paused.reason && (
				<p className="gitwire-browse-notice">
					{ 'rate_limit' === paused.reason
						? __(
								'Type detection paused: this provider has few API requests left this hour. Everything is still installable, and detection resumes once the limit resets.',
								'gitwire'
						  )
						: __(
								'Type detection stopped early to keep the page responsive. Refresh to carry on where it left off.',
								'gitwire'
						  ) }
				</p>
			) }

			{ repositories.length === 0 && loading && (
				<div style={ { textAlign: 'center', padding: 48 } }>
					<Spinner />
				</div>
			) }

			{ repositories.length === 0 && ! loading && (
				<p style={ { color: '#57606a', marginTop: 8 } }>
					{ __( 'No repositories found.', 'gitwire' ) }
				</p>
			) }

			{ repositories.length > 0 && filtered.length === 0 && (
				<p style={ { color: '#57606a', marginTop: 8 } }>
					{ search.trim() ? (
						<>
							{ __( 'No repositories match', 'gitwire' ) }{ ' ' }
							<strong>{ search }</strong>.
						</>
					) : (
						__(
							'No repositories match the selected filter.',
							'gitwire'
						)
					) }
				</p>
			) }

			{ filtered.length > 0 && (
				<div className="gitwire-repository-grid">
					{ filtered.map( ( repository ) => (
						<RepositoryCard
							key={ `${ repository.provider }:${ repository.full_name }` }
							autoDetectType={ autoDetectType }
							detection={
								detections[ detectionKey( repository ) ]
							}
							detectionPaused={ paused.keys.has(
								detectionKey( repository )
							) }
							installed={ lookupInstalled(
								installed,
								repository
							) }
							repository={ repository }
							showSourceBadge={ showSourceBadge }
							smartInstall={ smartInstall }
							onInstall={
								onInstallRequest
									? () =>
											onInstallRequest(
												repository,
												detections[
													detectionKey( repository )
												]
											)
									: () => setModal( repository )
							}
						/>
					) ) }
				</div>
			) }

			{ hasMore && (
				<div style={ { textAlign: 'center', marginTop: 24 } }>
					<Button
						disabled={ loading }
						isBusy={ loading }
						variant="secondary"
						onClick={ handleLoadMore }
					>
						{ __( 'Load More', 'gitwire' ) }
					</Button>
				</div>
			) }

			{ ! onInstallRequest && modal && (
				<InstallModal
					autoDetectType={ autoDetectType }
					connectionId={ modal.connection_id }
					detection={ detections[ detectionKey( modal ) ] }
					provider={ modal.provider }
					repository={ modal }
					smartInstall={ smartInstall }
					onClose={ () => setModal( null ) }
					onInstalled={ ( result ) => {
						const repositoryFullName = modal.full_name;
						setModal( null );
						onPostInstall( result, repositoryFullName );
					} }
				/>
			) }
		</div>
	);
}

const RepositoryCard = memo( function ( {
	repository,
	detection,
	detectionPaused,
	installed,
	smartInstall,
	showSourceBadge,
	autoDetectType,
	onInstall,
} ) {
	const isInstalled = !! installed;
	const detecting =
		autoDetectType && ! detection && ! detectionPaused && ! isInstalled;

	// Handle both boolean and string values because "0" is truthy in JavaScript.
	const isPrivate =
		repository.private === true ||
		repository.private === 1 ||
		repository.private === '1';

	const canInstall =
		! isInstalled &&
		( ! autoDetectType ||
			detectionPaused ||
			( detection
				? detection.type !== 'unknown' || ! smartInstall
				: ! smartInstall ) );

	const blockedBySmartInstall =
		! isInstalled && detection?.type === 'unknown' && smartInstall;

	return (
		<Card className="gitwire-repository-card" size="small">
			<CardBody>
				<Flex align="flex-start" gap={ 2 } justify="space-between">
					<FlexBlock>
						{ repository.full_name &&
							( repository.html_url ? (
								<a
									className="gitwire-repository-name"
									href={ repository.html_url }
									rel="noopener noreferrer"
									target="_blank"
								>
									{ repository.full_name }
									<ExternalLinkIcon />
								</a>
							) : (
								<span className="gitwire-repository-name">
									{ repository.full_name }
								</span>
							) ) }
					</FlexBlock>
					<FlexItem>
						{ isInstalled ? (
							<Button disabled size="compact" variant="secondary">
								{ __( 'Installed', 'gitwire' ) }
							</Button>
						) : (
							<Button
								disabled={ ! canInstall || detecting }
								size="compact"
								title={
									blockedBySmartInstall
										? __(
												'Smart Install is on. Only verified WordPress plugins and themes can be installed.',
												'gitwire'
										  )
										: undefined
								}
								variant="secondary"
								onClick={ onInstall }
							>
								{ __( 'Install', 'gitwire' ) }
							</Button>
						) }
					</FlexItem>
				</Flex>
				<div className="gitwire-repository-badges">
					{ showSourceBadge && (
						<span
							className={ `gitwire-badge gitwire-badge--${
								repository.provider ?? 'github'
							}` }
						>
							<ProviderIcon provider={ repository.provider } />
							{ providerLabel( repository.provider ?? 'github' ) }
						</span>
					) }
					<span
						className={ `gitwire-badge gitwire-badge--${
							isPrivate ? 'warning' : 'success'
						}` }
					>
						{ isPrivate
							? __( 'Private', 'gitwire' )
							: __( 'Public', 'gitwire' ) }
					</span>
					<TypeBadge
						autoDetectType={ autoDetectType }
						detection={ detection }
						installed={ installed }
						paused={ detectionPaused }
					/>
					{ repository.last_activity_at && (
						<Tooltip
							text={ `${ __(
								'Last Updated',
								'gitwire'
							) }: ${ new Date(
								repository.last_activity_at
							).toLocaleString( undefined, {
								dateStyle: 'medium',
								timeStyle: 'short',
							} ) }` }
						>
							<span className="gitwire-repository-updated">
								<svg
									aria-hidden="true"
									fill="none"
									height="11"
									stroke="currentColor"
									strokeLinecap="round"
									strokeLinejoin="round"
									strokeWidth="1.5"
									viewBox="0 0 16 16"
									width="11"
								>
									<circle cx="8" cy="8" r="6.25" />
									<polyline points="8,4.5 8,8 10.5,10" />
								</svg>
								{ relativeTimeFromDate(
									repository.last_activity_at
								) }
							</span>
						</Tooltip>
					) }
				</div>
			</CardBody>
		</Card>
	);
} );
RepositoryCard.displayName = 'RepositoryCard';

function TypeBadge( { detection, installed, autoDetectType, paused } ) {
	if ( installed ) {
		if ( installed.type === 'block-theme' ) {
			return (
				<span className="gitwire-badge gitwire-badge--block-theme">
					{ __( 'Block Theme', 'gitwire' ) }
				</span>
			);
		}
		if ( installed.type === 'classic-theme' ) {
			return (
				<span className="gitwire-badge gitwire-badge--theme">
					{ __( 'Classic Theme', 'gitwire' ) }
				</span>
			);
		}
		return (
			<span className="gitwire-badge gitwire-badge--info">
				{ __( 'Plugin', 'gitwire' ) }
			</span>
		);
	}
	// Treat detection as complete when auto-detection is disabled.
	if ( ! autoDetectType ) {
		return null;
	}
	if ( paused ) {
		return (
			<Tooltip
				text={ __(
					'Not enough API requests left this hour to check this one. It is still installable.',
					'gitwire'
				) }
			>
				<span className="gitwire-badge gitwire-badge--draft">
					{ __( 'Detection Paused', 'gitwire' ) }
				</span>
			</Tooltip>
		);
	}
	if ( ! detection ) {
		return (
			<span className="gitwire-type-detecting">
				<Spinner /> { __( 'Detecting…', 'gitwire' ) }
			</span>
		);
	}
	const { type } = detection;
	if ( type === 'plugin' ) {
		return (
			<span className="gitwire-badge gitwire-badge--info">
				{ __( 'Plugin', 'gitwire' ) }
			</span>
		);
	}
	if ( type === 'block-theme' ) {
		return (
			<span className="gitwire-badge gitwire-badge--block-theme">
				{ __( 'Block Theme', 'gitwire' ) }
			</span>
		);
	}
	if ( type === 'classic-theme' ) {
		return (
			<span className="gitwire-badge gitwire-badge--theme">
				{ __( 'Classic Theme', 'gitwire' ) }
			</span>
		);
	}
	return (
		<span className="gitwire-badge gitwire-badge--draft">
			{ __( 'Unknown', 'gitwire' ) }
		</span>
	);
}
