import { toast } from '../toast';

import { __, sprintf } from '@wordpress/i18n';
import {
	useState,
	useEffect,
	useCallback,
	useRef,
	memo,
} from '@wordpress/element';
import {
	Button,
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
import { GitHubIcon, GitLabIcon, BitbucketIcon } from './provider-icons';

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
 * @param {Object}   props.settings           Plugin settings.
 * @param {Object}   props.installed          Map of installed repositories.
 * @param {Function} [props.onPostInstall]    Standalone mode: called after install completes.
 * @param {Function} [props.onInstallRequest] Modal mode: called with (repo, detection) instead of opening InstallModal.
 * @return {JSX.Element} The rendered browse panel.
 */
export default function BrowsePanel( {
	settings,
	installed,
	onPostInstall,
	onInstallRequest,
} ) {
	const hasGitHub = !! ( settings?.token_set || settings?.username );
	const hasGitLab = !! settings?.gitlab_token_set;
	const hasBitbucket = !! settings?.bitbucket_api_token_set;
	const showSourceBadge =
		[ hasGitHub, hasGitLab, hasBitbucket ].filter( Boolean ).length > 1;

	const { detections, runBatch, seedFromRepos, reset } = useRepoDetection();

	const [ repos, setRepos ] = useState( [] );
	const [ pagesLoaded, setPagesLoaded ] = useState( {
		github: 0,
		gitlab: 0,
		bitbucket: 0,
	} );
	const [ hasMore, setHasMore ] = useState( {
		github: false,
		gitlab: false,
		bitbucket: false,
	} );
	const [ loading, setLoading ] = useState( false );
	const [ modal, setModal ] = useState( null );
	const [ search, setSearch ] = useState( '' );
	const [ typeFilter, setTypeFilter ] = useState( 'all' );
	const handleRefreshRef = useRef( null );

	const loadRepos = useCallback(
		async ( ghPage, glPage, bbPage, append = false ) => {
			setLoading( true );
			try {
				const fetches = [];
				if ( ghPage > 0 ) {
					fetches.push(
						api
							.getRepos( ghPage, 'github' )
							.then( ( d ) => ( {
								...d,
								provider: 'github',
								page: ghPage,
							} ) )
							.catch( ( e ) => ( {
								error: e.message,
								provider: 'github',
							} ) )
					);
				}
				if ( glPage > 0 ) {
					fetches.push(
						api
							.getRepos( glPage, 'gitlab' )
							.then( ( d ) => ( {
								...d,
								provider: 'gitlab',
								page: glPage,
							} ) )
							.catch( ( e ) => ( {
								error: e.message,
								provider: 'gitlab',
							} ) )
					);
				}
				if ( bbPage > 0 ) {
					fetches.push(
						api
							.getRepos( bbPage, 'bitbucket' )
							.then( ( d ) => ( {
								...d,
								provider: 'bitbucket',
								page: bbPage,
							} ) )
							.catch( ( e ) => ( {
								error: e.message,
								provider: 'bitbucket',
							} ) )
					);
				}

				const results = await Promise.all( fetches );

				let newRepos = [];
				const errors = [];

				for ( const result of results ) {
					if ( result.error ) {
						errors.push( result.error );
						continue;
					}
					const tagged = result.repos.map( ( r ) => ( {
						...r,
						provider: result.provider,
					} ) );
					newRepos = [ ...newRepos, ...tagged ];
					setHasMore( ( prev ) => ( {
						...prev,
						[ result.provider ]: result.has_more,
					} ) );
					setPagesLoaded( ( prev ) => ( {
						...prev,
						[ result.provider ]: result.page,
					} ) );
				}

				newRepos.sort(
					( a, b ) =>
						new Date( b.updated_at ) - new Date( a.updated_at )
				);

				setRepos( ( prev ) => {
					if ( ! append ) {
						return newRepos;
					}
					const merged = [ ...prev, ...newRepos ];
					merged.sort(
						( a, b ) =>
							new Date( b.updated_at ) - new Date( a.updated_at )
					);
					return merged;
				} );

				if ( errors.length ) {
					toast.error( errors.join( ' · ' ), {
						action: {
							label: __( 'Retry', 'gitwire' ),
							onClick: () => handleRefreshRef.current?.(),
						},
					} );
				}

				seedFromRepos( newRepos );
				runBatch(
					newRepos.filter(
						( repo ) => ! lookupInstalled( installed, repo )
					)
				);
			} catch ( e ) {
				toast.error(
					e.message ||
						__( 'Failed to load repositories.', 'gitwire' ),
					{
						action: {
							label: __( 'Retry', 'gitwire' ),
							onClick: () => handleRefreshRef.current?.(),
						},
					}
				);
			} finally {
				setLoading( false );
			}
		},
		[ installed, runBatch, seedFromRepos ]
	);

	useEffect( () => {
		loadRepos( hasGitHub ? 1 : 0, hasGitLab ? 1 : 0, hasBitbucket ? 1 : 0 );
	}, [] ); // eslint-disable-line react-hooks/exhaustive-deps

	const handleRefresh = useCallback( async () => {
		setLoading( true );
		setRepos( [] );
		setHasMore( { github: false, gitlab: false, bitbucket: false } );
		setPagesLoaded( { github: 0, gitlab: 0, bitbucket: 0 } );
		try {
			await api.clearCache();
			reset();
			await loadRepos(
				hasGitHub ? 1 : 0,
				hasGitLab ? 1 : 0,
				hasBitbucket ? 1 : 0
			);
		} catch ( e ) {
			toast.error(
				e.message || __( 'Failed to refresh repositories.', 'gitwire' )
			);
			setLoading( false );
		}
	}, [ hasGitHub, hasGitLab, hasBitbucket, loadRepos, reset ] );

	handleRefreshRef.current = handleRefresh;

	const handleLoadMore = () => {
		const ghPage = hasMore.github ? pagesLoaded.github + 1 : 0;
		const glPage = hasMore.gitlab ? pagesLoaded.gitlab + 1 : 0;
		const bbPage = hasMore.bitbucket ? pagesLoaded.bitbucket + 1 : 0;
		loadRepos( ghPage, glPage, bbPage, true );
	};

	const smartInstall = settings?.smart_install !== false;

	const matchesSearch = ( r ) => {
		if ( ! search.trim() ) {
			return true;
		}
		return r.full_name.toLowerCase().includes( search.toLowerCase() );
	};

	const matchesType = ( r ) => {
		if ( typeFilter === 'all' ) {
			return true;
		}
		const installedRec = lookupInstalled( installed, r );
		const type =
			installedRec?.type ?? detections[ detectionKey( r ) ]?.type;
		if ( ! type ) {
			return false;
		}
		return type === typeFilter;
	};

	const filtered = repos.filter(
		( r ) => matchesSearch( r ) && matchesType( r )
	);

	const typeFilters = [
		{ id: 'all', label: __( 'All', 'gitwire' ) },
		{ id: 'plugin', label: __( 'Plugin', 'gitwire' ) },
		{ id: 'theme', label: __( 'Theme', 'gitwire' ) },
		{ id: 'unknown', label: __( 'Unknown', 'gitwire' ) },
	];

	if ( ! hasGitHub && ! hasGitLab && ! hasBitbucket ) {
		return (
			<div className="gitwire-browse-no-connection">
				<p>
					{ __(
						'Connect a GitHub, GitLab, or Bitbucket account in Settings to browse your repositories.',
						'gitwire'
					) }
				</p>
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
					<Flex align="center" gap={ 1 }>
						{ typeFilters.map( ( f ) => (
							<Button
								key={ f.id }
								isPressed={ typeFilter === f.id }
								size="compact"
								onClick={ () => setTypeFilter( f.id ) }
							>
								{ f.label }
							</Button>
						) ) }
					</Flex>
				</FlexItem>
				<FlexItem style={ { marginLeft: 'auto' } }>
					<Button
						className={ loading ? 'gitwire-spin' : '' }
						disabled={ loading }
						icon="update"
						isBusy={ loading }
						label={ __( 'Refresh repositories', 'gitwire' ) }
						variant="tertiary"
						onClick={ handleRefresh }
					/>
				</FlexItem>
			</Flex>

			{ repos.length === 0 && loading && (
				<div style={ { textAlign: 'center', padding: 48 } }>
					<Spinner />
				</div>
			) }

			{ repos.length > 0 && filtered.length === 0 && (
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
							key={ `${ repo.provider }:${ repo.id }` }
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

			{ ( hasMore.github || hasMore.gitlab ) && ! search && (
				<div style={ { textAlign: 'center', marginTop: 24 } }>
					<Button
						disabled={ loading }
						isBusy={ loading }
						variant="secondary"
						onClick={ handleLoadMore }
					>
						{ __( 'Load more', 'gitwire' ) }
					</Button>
				</div>
			) }

			{ ! onInstallRequest && modal && (
				<InstallModal
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
	onInstall,
} ) {
	const isInstalled = !! installed;
	const detecting = ! detection && ! isInstalled;

	const canInstall =
		! isInstalled &&
		( detection
			? detection.type !== 'unknown' || ! smartInstall
			: ! smartInstall );

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
					{ showSourceBadge && 'gitlab' === repo.provider && (
						<span className="gitwire-badge gitwire-badge--gitlab">
							<GitLabIcon />
							{ __( 'GitLab', 'gitwire' ) }
						</span>
					) }
					{ showSourceBadge && 'bitbucket' === repo.provider && (
						<span className="gitwire-badge gitwire-badge--bitbucket">
							<BitbucketIcon />
							{ __( 'Bitbucket', 'gitwire' ) }
						</span>
					) }
					{ showSourceBadge && 'github' === repo.provider && (
						<span className="gitwire-badge gitwire-badge--github">
							<GitHubIcon />
							{ __( 'GitHub', 'gitwire' ) }
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
					{ repo.updated_at && (
						<Tooltip
							text={ `${ __(
								'Last Updated',
								'gitwire'
							) }: ${ new Date( repo.updated_at ).toLocaleString(
								undefined,
								{
									dateStyle: 'medium',
									timeStyle: 'short',
								}
							) }` }
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
								{ timeAgo( repo.updated_at ) }
							</span>
						</Tooltip>
					) }
				</div>
			</CardBody>
		</Card>
	);
} );

function timeAgo( dateStr ) {
	const s = Math.floor( ( Date.now() - new Date( dateStr ) ) / 1000 );
	if ( s < 60 ) {
		return __( 'just now', 'gitwire' );
	}
	const m = Math.floor( s / 60 );
	if ( m < 60 ) {
		return sprintf(
			/* translators: %d: number of minutes */
			__( '%dm ago', 'gitwire' ),
			m
		);
	}
	const h = Math.floor( m / 60 );
	if ( h < 24 ) {
		return sprintf(
			/* translators: %d: number of hours */
			__( '%dh ago', 'gitwire' ),
			h
		);
	}
	const d = Math.floor( h / 24 );
	if ( d < 30 ) {
		return sprintf(
			/* translators: %d: number of days */
			__( '%dd ago', 'gitwire' ),
			d
		);
	}
	const mo = Math.floor( d / 30 );
	if ( mo < 12 ) {
		return sprintf(
			/* translators: %d: number of months */
			__( '%dmo ago', 'gitwire' ),
			mo
		);
	}
	return sprintf(
		/* translators: %d: number of years */
		__( '%dy ago', 'gitwire' ),
		Math.floor( mo / 12 )
	);
}

function TypeBadge( { detection, installed } ) {
	if ( installed ) {
		const isBlockTheme =
			installed.type === 'theme' && installed.subtype === 'block';
		if ( isBlockTheme ) {
			return (
				<span className="gitwire-badge gitwire-badge--block-theme">
					{ __( 'Block Theme', 'gitwire' ) }
				</span>
			);
		}
		if ( installed.type === 'theme' ) {
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
	const { type, subtype } = detection;
	if ( type === 'plugin' ) {
		return (
			<span className="gitwire-badge gitwire-badge--info">
				{ __( 'Plugin', 'gitwire' ) }
			</span>
		);
	}
	if ( type === 'theme' && subtype === 'block' ) {
		return (
			<span className="gitwire-badge gitwire-badge--block-theme">
				{ __( 'Block Theme', 'gitwire' ) }
			</span>
		);
	}
	if ( type === 'theme' ) {
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
