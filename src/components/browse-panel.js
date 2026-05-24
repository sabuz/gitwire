import { toast } from 'sonner';

import { __, sprintf } from '@wordpress/i18n';
import { useState, useEffect, useCallback, memo } from '@wordpress/element';
import {
	Button,
	Spinner,
	Flex,
	FlexBlock,
	FlexItem,
	Card,
	CardBody,
	SearchControl,
} from '@wordpress/components';

import * as api from '../api';
import { detectionKey, useRepoDetection } from '../hooks/use-repo-detection';
import ConnectPrompt from './connect-prompt';
import InstallModal from './install-modal';
import { GitHubIcon, GitLabIcon } from './provider-icons';

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
 * @param {Object}   props                Component props.
 * @param {Object}   props.settings       Plugin settings.
 * @param {Object}   props.installed      Map of installed repositories.
 * @param {Function} props.onPostInstall  Switches to Installed, refreshes, then toasts.
 * @param {Function} props.onGoToSettings Callback to navigate to the Settings tab.
 * @return {JSX.Element} The rendered browse panel.
 */
export default function BrowsePanel( {
	settings,
	installed,
	onPostInstall,
	onGoToSettings,
} ) {
	const hasGitHub = !! ( settings?.token_set || settings?.username );
	const hasGitLab = !! settings?.gitlab_token_set;
	const showSourceBadge = hasGitHub && hasGitLab;

	const { detections, runBatch, seedFromRepos, reset } = useRepoDetection();

	const [ repos, setRepos ] = useState( [] );
	const [ pagesLoaded, setPagesLoaded ] = useState( {
		github: 0,
		gitlab: 0,
	} );
	const [ hasMore, setHasMore ] = useState( {
		github: false,
		gitlab: false,
	} );
	const [ loading, setLoading ] = useState( false );
	const [ modal, setModal ] = useState( null );
	const [ search, setSearch ] = useState( '' );
	const [ typeFilter, setTypeFilter ] = useState( 'all' );

	const loadRepos = useCallback(
		async ( ghPage, glPage, append = false ) => {
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
						duration: 6000,
						action: {
							label: __( 'Retry', 'git' ),
							onClick: handleRefresh,
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
					e.message || __( 'Failed to load repositories.', 'git' ),
					{
						duration: 6000,
						action: {
							label: __( 'Retry', 'git' ),
							onClick: handleRefresh,
						},
					}
				);
			} finally {
				setLoading( false );
			}
		},
		[ installed, runBatch, seedFromRepos ] // eslint-disable-line react-hooks/exhaustive-deps
	);

	useEffect( () => {
		loadRepos( hasGitHub ? 1 : 0, hasGitLab ? 1 : 0 );
	}, [] ); // eslint-disable-line react-hooks/exhaustive-deps

	const handleRefresh = async () => {
		await api.clearCache();
		reset();
		setRepos( [] );
		setHasMore( { github: false, gitlab: false } );
		setPagesLoaded( { github: 0, gitlab: 0 } );
		loadRepos( hasGitHub ? 1 : 0, hasGitLab ? 1 : 0 );
	};

	const handleLoadMore = () => {
		const ghPage = hasMore.github ? pagesLoaded.github + 1 : 0;
		const glPage = hasMore.gitlab ? pagesLoaded.gitlab + 1 : 0;
		loadRepos( ghPage, glPage, true );
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
		{ id: 'all', label: __( 'All', 'git' ) },
		{ id: 'plugin', label: __( 'Plugin', 'git' ) },
		{ id: 'theme', label: __( 'Theme', 'git' ) },
		{ id: 'unknown', label: __( 'Unknown', 'git' ) },
	];

	if ( ! hasGitHub && ! hasGitLab ) {
		return <ConnectPrompt onConnect={ onGoToSettings } />;
	}

	return (
		<div className="gwp-browse">
			<Flex
				align="center"
				className="gwp-browse-toolbar"
				gap={ 2 }
				justify="flex-start"
				style={ { marginBottom: 24 } }
			>
				<FlexBlock style={ { maxWidth: 280 } }>
					<SearchControl
						__nextHasNoMarginBottom
						// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
						size="__unstable-large"
						onChange={ setSearch }
						placeholder={ __( 'Search repositories…', 'git' ) }
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
						className={ loading ? 'gwp-spin' : '' }
						disabled={ loading }
						icon="update"
						label={ __( 'Refresh repositories', 'git' ) }
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
							{ __( 'No repositories match', 'git' ) }{ ' ' }
							<strong>{ search }</strong>.
						</>
					) : (
						__(
							'No repositories match the selected filter.',
							'git'
						)
					) }
				</p>
			) }

			{ filtered.length > 0 && (
				<div className="gwp-repo-grid">
					{ filtered.map( ( repo ) => (
						<RepoCard
							key={ `${ repo.provider }:${ repo.id }` }
							detection={ detections[ detectionKey( repo ) ] }
							installed={ lookupInstalled( installed, repo ) }
							repo={ repo }
							showSourceBadge={ showSourceBadge }
							smartInstall={ smartInstall }
							onInstall={ () => setModal( repo ) }
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
						{ __( 'Load more', 'git' ) }
					</Button>
				</div>
			) }

			{ modal && (
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
		<Card className="gwp-repo-card" size="small">
			<CardBody>
				<Flex align="flex-start" gap={ 2 } justify="space-between">
					<FlexBlock>
						<a
							className="gwp-repo-name"
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
								{ __( 'Installed', 'git' ) }
							</Button>
						) : (
							<Button
								disabled={ ! canInstall || detecting }
								size="compact"
								title={
									blockedBySmartInstall
										? __(
												'Smart Install is on. Only verified WordPress plugins and themes can be installed.',
												'git'
										  )
										: undefined
								}
								variant="secondary"
								onClick={ onInstall }
							>
								{ __( 'Install', 'git' ) }
							</Button>
						) }
					</FlexItem>
				</Flex>
				<div className="gwp-repo-badges">
					{ showSourceBadge &&
						( 'github' === repo.provider ? (
							<span className="gwp-badge gwp-badge--github">
								<GitHubIcon />
								{ __( 'GitHub', 'git' ) }
							</span>
						) : (
							<span className="gwp-badge gwp-badge--gitlab">
								<GitLabIcon />
								{ __( 'GitLab', 'git' ) }
							</span>
						) ) }
					<span
						className={ `gwp-badge gwp-badge--${
							repo.private ? 'warning' : 'success'
						}` }
					>
						{ repo.private
							? __( 'Private', 'git' )
							: __( 'Public', 'git' ) }
					</span>
					<TypeBadge
						detection={ detection }
						installed={ installed }
					/>
				</div>
				{ repo.updated_at && (
					<p className="gwp-repo-updated">
						{ __( 'Updated', 'git' ) }{ ' ' }
						{ timeAgo( repo.updated_at ) }
					</p>
				) }
			</CardBody>
		</Card>
	);
} );

function timeAgo( dateStr ) {
	const s = Math.floor( ( Date.now() - new Date( dateStr ) ) / 1000 );
	if ( s < 60 ) {
		return __( 'just now', 'git' );
	}
	const m = Math.floor( s / 60 );
	if ( m < 60 ) {
		return sprintf(
			/* translators: %d: number of minutes */
			__( '%dm ago', 'git' ),
			m
		);
	}
	const h = Math.floor( m / 60 );
	if ( h < 24 ) {
		return sprintf(
			/* translators: %d: number of hours */
			__( '%dh ago', 'git' ),
			h
		);
	}
	const d = Math.floor( h / 24 );
	if ( d < 30 ) {
		return sprintf(
			/* translators: %d: number of days */
			__( '%dd ago', 'git' ),
			d
		);
	}
	const mo = Math.floor( d / 30 );
	if ( mo < 12 ) {
		return sprintf(
			/* translators: %d: number of months */
			__( '%dmo ago', 'git' ),
			mo
		);
	}
	return sprintf(
		/* translators: %d: number of years */
		__( '%dy ago', 'git' ),
		Math.floor( mo / 12 )
	);
}

function TypeBadge( { detection, installed } ) {
	if ( installed ) {
		const isBlockTheme =
			installed.type === 'theme' && installed.subtype === 'block';
		if ( isBlockTheme ) {
			return (
				<span className="gwp-badge gwp-badge--block-theme">
					{ __( 'Block Theme', 'git' ) }
				</span>
			);
		}
		if ( installed.type === 'theme' ) {
			return (
				<span className="gwp-badge gwp-badge--theme">
					{ __( 'Theme', 'git' ) }
				</span>
			);
		}
		return (
			<span className="gwp-badge gwp-badge--info">
				{ __( 'Plugin', 'git' ) }
			</span>
		);
	}
	if ( ! detection ) {
		return (
			<span className="gwp-type-detecting">
				<Spinner /> { __( 'Detecting…', 'git' ) }
			</span>
		);
	}
	const { type, subtype } = detection;
	if ( type === 'plugin' ) {
		return (
			<span className="gwp-badge gwp-badge--info">
				{ __( 'Plugin', 'git' ) }
			</span>
		);
	}
	if ( type === 'theme' && subtype === 'block' ) {
		return (
			<span className="gwp-badge gwp-badge--block-theme">
				{ __( 'Block Theme', 'git' ) }
			</span>
		);
	}
	if ( type === 'theme' ) {
		return (
			<span className="gwp-badge gwp-badge--theme">
				{ __( 'Theme', 'git' ) }
			</span>
		);
	}
	return (
		<span className="gwp-badge gwp-badge--draft">
			{ __( 'Unknown', 'git' ) }
		</span>
	);
}
