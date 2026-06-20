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
import {
	Button,
	Dropdown,
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
import { detectionKey, useRepoDetection } from '../hooks/use-repo-detection';
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
 * @param {Object} installed Installed repositories map from app state.
 * @param {Object} repo      Browse repository record.
 * @return {Object|null} Matching installed record, if any.
 */
function lookupInstalled( installed, repo ) {
	return installed[ `${ repo.provider }:${ repo.full_name }` ] ?? null;
}

/**
 * Browse panel — lists GitHub and GitLab repositories with detection and install actions.
 *
 * @param {Object}   props                    Component props.
 * @param {Array}    props.connections        Connection records array.
 * @param {Object}   props.settings           Plugin settings.
 * @param {Object}   props.installed          Map of installed repositories.
 * @param {Function} [props.onPostInstall]    Standalone mode: called after install completes.
 * @param {Function} [props.onInstallRequest] Modal mode: called with (repo, detection) instead of opening InstallModal.
 * @param {Function} [props.onGoToSettings]   Navigates to the Settings tab.
 * @param {Function} [props.onOpenUrlImport]  Opens the Import from URL modal.
 * @return {JSX.Element} The rendered browse panel.
 */
export default function BrowsePanel( {
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

	const { detections, runBatch, seedFromRepos, reset } = useRepoDetection();

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
	const smartInstall = settings?.smart_install !== false;
	const autoDetectType = settings?.auto_detect_type !== false;

	const loadRepos = useCallback(
		async ( offset, append = false, searchTerm = '' ) => {
			setLoading( true );
			try {
				const result = await api.getRepos( {
					offset,
					search: searchTerm,
				} );
				const repos = result.repositories ?? [];
				setRepositories( ( prev ) =>
					append ? [ ...prev, ...repos ] : repos
				);
				setHasMore( result.has_more ?? false );
				seedFromRepos( repos );
				if ( autoDetectType ) {
					runBatch(
						repos.filter(
							( repo ) => ! lookupInstalled( installed, repo )
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
		[ autoDetectType, installed, runBatch, seedFromRepos ]
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
		loadRepos( 0, false, '' );
	}, [ connIds ] ); // eslint-disable-line react-hooks/exhaustive-deps

	const handleRefresh = useCallback( async () => {
		setLoading( true );
		setRepositories( [] );
		setHasMore( false );
		try {
			await api.clearCache();
			reset();
			await loadRepos( 0, false, search );
		} catch ( e ) {
			toast.error(
				e.message || __( 'Failed to refresh repositories.', 'gitwire' )
			);
			setLoading( false );
		}
	}, [ loadRepos, reset, search ] );

	const handleLoadMore = () => {
		loadRepos( repositories.length, true, search );
	};

	// Debounced server reload when search term changes (skips initial mount).
	useEffect( () => {
		if ( isFirstSearchRef.current ) {
			isFirstSearchRef.current = false;
			return;
		}
		clearTimeout( searchTimerRef.current );
		searchTimerRef.current = setTimeout( () => {
			setRepositories( [] );
			setHasMore( false );
			reset();
			loadRepos( 0, false, search );
		}, 350 );
		return () => clearTimeout( searchTimerRef.current );
	}, [ search ] ); // eslint-disable-line react-hooks/exhaustive-deps

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

	const activeFilterCount =
		activeTypeFilters.length + activeSourceFilters.length;

	const allTypeOptions = [ 'plugin', 'theme', 'unknown' ];
	const allSourceOptions = [
		hasGitHub && 'github',
		hasGitLab && 'gitlab',
		hasBitbucket && 'bitbucket',
	].filter( Boolean );
	const totalOptions =
		allTypeOptions.length +
		( showSourceBadge ? allSourceOptions.length : 0 );
	const allSelected = activeFilterCount === totalOptions;

	const handleSelectAll = () => {
		if ( activeFilterCount > 0 ) {
			setActiveTypeFilters( [] );
			setActiveSourceFilters( [] );
		} else {
			setActiveTypeFilters( [ ...allTypeOptions ] );
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
		if ( activeTypeFilters.length === 0 ) {
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
											? __( 'Clear Filters', 'gitwire' )
											: __( 'Select All', 'gitwire' ) }
									</Button>
								</div>

								<ul className="gitwire-filter-popover__list">
									{ [
										{
											id: 'plugin',
											label: __( 'Plugin', 'gitwire' ),
										},
										{
											id: 'theme',
											label: __( 'Theme', 'gitwire' ),
										},
										{
											id: 'unknown',
											label: __( 'Unknown', 'gitwire' ),
										},
									].map( ( { id, label } ) => (
										<li key={ id }>
											<FilterOption
												checked={ activeTypeFilters.includes(
													id
												) }
												label={ label }
												onChange={ () =>
													toggleTypeFilter( id )
												}
											/>
										</li>
									) ) }
								</ul>

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
				<FlexItem>
					<Button
						disabled={ loading }
						isBusy={ loading }
						variant="secondary"
						onClick={ handleRefresh }
					>
						{ __( 'Refresh', 'gitwire' ) }
					</Button>
				</FlexItem>
				{ onOpenUrlImport && (
					<FlexItem>
						<Button variant="secondary" onClick={ onOpenUrlImport }>
							{ __( 'Import from URL', 'gitwire' ) }
						</Button>
					</FlexItem>
				) }
			</Flex>

			{ repositories.length === 0 && loading && (
				<div style={ { textAlign: 'center', padding: 48 } }>
					<Spinner />
				</div>
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
				<div className="gitwire-repo-grid">
					{ filtered.map( ( repo ) => (
						<RepoCard
							key={ `${ repo.provider }:${ repo.full_name }` }
							autoDetectType={ autoDetectType }
							detection={ detections[ detectionKey( repo ) ] }
							installed={ lookupInstalled( installed, repo ) }
							repo={ repo }
							showSourceBadge={ showSourceBadge }
							smartInstall={ smartInstall }
							onInstall={
								onInstallRequest
									? () =>
											onInstallRequest(
												repo,
												detections[
													detectionKey( repo )
												]
											)
									: () => setModal( repo )
							}
						/>
					) ) }
				</div>
			) }

			{ hasMore && ! search && (
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
					connectionId={ modal.connection_id }
					detection={ detections[ detectionKey( modal ) ] }
					provider={ modal.provider }
					repo={ modal }
					smartInstall={ smartInstall }
					onClose={ () => setModal( null ) }
					onInstalled={ ( result ) => {
						const repoFullName = modal.full_name;
						setModal( null );
						onPostInstall( result, repoFullName );
					} }
				/>
			) }
		</div>
	);
}

const RepoCard = memo( function RepoCard( {
	repo,
	detection,
	installed,
	smartInstall,
	showSourceBadge,
	autoDetectType,
	onInstall,
} ) {
	const isInstalled = !! installed;
	const detecting = autoDetectType && ! detection && ! isInstalled;

	const canInstall =
		! isInstalled &&
		( ! autoDetectType ||
			( detection
				? detection.type !== 'unknown' || ! smartInstall
				: ! smartInstall ) );

	const blockedBySmartInstall =
		! isInstalled && detection?.type === 'unknown' && smartInstall;

	return (
		<Card className="gitwire-repo-card" size="small">
			<CardBody>
				<Flex align="flex-start" gap={ 2 } justify="space-between">
					<FlexBlock>
						<a
							className="gitwire-repo-name"
							href={ repo.html_url }
							rel="noopener noreferrer"
							target="_blank"
						>
							{ repo.full_name }
						</a>
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
				<div className="gitwire-repo-badges">
					{ showSourceBadge && (
						<span
							className={ `gitwire-badge gitwire-badge--${
								repo.provider ?? 'github'
							}` }
						>
							<ProviderIcon provider={ repo.provider } />
							{ providerLabel( repo.provider ?? 'github' ) }
						</span>
					) }
					<span
						className={ `gitwire-badge gitwire-badge--${
							repo.private ? 'warning' : 'success'
						}` }
					>
						{ repo.private
							? __( 'Private', 'gitwire' )
							: __( 'Public', 'gitwire' ) }
					</span>
					<TypeBadge
						detection={ detection }
						installed={ installed }
					/>
					{ repo.last_activity_at && (
						<Tooltip
							text={ `${ __(
								'Last Updated',
								'gitwire'
							) }: ${ new Date(
								repo.last_activity_at
							).toLocaleString( undefined, {
								dateStyle: 'medium',
								timeStyle: 'short',
							} ) }` }
						>
							<span className="gitwire-repo-updated">
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
									repo.last_activity_at
								) }
							</span>
						</Tooltip>
					) }
				</div>
			</CardBody>
		</Card>
	);
} );

function TypeBadge( { detection, installed } ) {
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
					{ __( 'Theme', 'gitwire' ) }
				</span>
			);
		}
		return (
			<span className="gitwire-badge gitwire-badge--info">
				{ __( 'Plugin', 'gitwire' ) }
			</span>
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
				{ __( 'Theme', 'gitwire' ) }
			</span>
		);
	}
	return (
		<span className="gitwire-badge gitwire-badge--draft">
			{ __( 'Unknown', 'gitwire' ) }
		</span>
	);
}
