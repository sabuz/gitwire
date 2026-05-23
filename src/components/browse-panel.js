import { toast } from 'sonner';

import { __ } from '@wordpress/i18n';
import { useState, useEffect, useRef, useCallback } from '@wordpress/element';
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
import ConnectPrompt from './connect-prompt';
import InstallModal from './install-modal';

const CONCURRENT = 3;

/**
 * Browse panel — lists GitHub and GitLab repositories with detection and install actions.
 *
 * @param {Object}   props                Component props.
 * @param {Object}   props.settings       Plugin settings.
 * @param {Object}   props.installed      Map of installed repositories.
 * @param {Function} props.onInstalled    Callback fired after a successful install.
 * @param {Function} props.onGoToSettings Callback to navigate to the Settings tab.
 * @return {JSX.Element} The rendered browse panel.
 */
export default function BrowsePanel( {
	settings,
	installed,
	onInstalled,
	onGoToSettings,
} ) {
	const hasGitHub = !! ( settings?.token || settings?.username );
	const hasGitLab = !! settings?.gitlab_token;
	const showSourceBadge = hasGitHub && hasGitLab;

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

	const detectionsRef = useRef( {} );
	const queueRef = useRef( [] );
	const activeRef = useRef( 0 );
	const [ , forceRender ] = useState( 0 );

	const detectionKey = ( repo ) => `${ repo.provider }:${ repo.full_name }`;

	const drain = () => {
		while (
			activeRef.current < CONCURRENT &&
			queueRef.current.length > 0
		) {
			const repo = queueRef.current.shift();
			const key = detectionKey( repo );
			activeRef.current++;
			api.detectRepo(
				repo.owner,
				repo.name,
				repo.default_branch,
				repo.provider
			)
				.then( ( d ) => {
					detectionsRef.current[ key ] = d;
				} )
				.catch( () => {
					detectionsRef.current[ key ] = {
						type: 'unknown',
						confidence: 'none',
					};
				} )
				.finally( () => {
					activeRef.current--;
					forceRender( ( n ) => n + 1 );
					drain();
				} );
		}
	};

	const enqueueDetections = ( newRepos ) => {
		const toDetect = newRepos.filter(
			( r ) =>
				! r.installed && ! detectionsRef.current[ detectionKey( r ) ]
		);
		queueRef.current.push( ...toDetect );
		drain();
	};

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

				newRepos.forEach( ( r ) => {
					if ( r.detection ) {
						const key = detectionKey( r );
						if ( ! detectionsRef.current[ key ] ) {
							detectionsRef.current[ key ] = r.detection;
						}
					}
				} );

				enqueueDetections( newRepos );
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
		[] // eslint-disable-line react-hooks/exhaustive-deps
	);

	useEffect( () => {
		loadRepos( hasGitHub ? 1 : 0, hasGitLab ? 1 : 0 );
	}, [] ); // eslint-disable-line react-hooks/exhaustive-deps

	const handleRefresh = async () => {
		await api.clearCache();
		detectionsRef.current = {};
		queueRef.current = [];
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
		const installedRec = installed[ r.full_name ] || r.installed;
		const type =
			installedRec?.type ??
			detectionsRef.current[ detectionKey( r ) ]?.type;
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
				{ typeFilters.map( ( f ) => (
					<FlexItem key={ f.id }>
						<Button
							isPressed={ typeFilter === f.id }
							size="compact"
							onClick={ () => setTypeFilter( f.id ) }
						>
							{ f.label }
						</Button>
					</FlexItem>
				) ) }
				<FlexItem style={ { marginLeft: 'auto' } }>
					<Button
						disabled={ loading }
						icon="update"
						isBusy={ loading }
						variant="secondary"
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
							detection={
								detectionsRef.current[ detectionKey( repo ) ]
							}
							installed={
								installed[ repo.full_name ] || repo.installed
							}
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
					detection={ detectionsRef.current[ detectionKey( modal ) ] }
					provider={ modal.provider }
					repo={ modal }
					smartInstall={ smartInstall }
					onClose={ () => setModal( null ) }
					onInstalled={ ( result ) => {
						setModal( null );
						onInstalled( result );
					} }
				/>
			) }
		</div>
	);
}

/**
 * @return {JSX.Element} GitHub mark icon.
 */
function GitHubIcon() {
	return (
		<svg
			aria-hidden="true"
			fill="currentColor"
			height="13"
			style={ { display: 'block', flexShrink: 0 } }
			viewBox="0 0 16 16"
			width="13"
		>
			<path d="M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 5.47 7.59.4.07.55-.17.55-.38 0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13-.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.51-1.07-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.82-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82.64-.18 1.32-.27 2-.27.68 0 1.36.09 2 .27 1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.51.56.82 1.27.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A8.013 8.013 0 0016 8c0-4.42-3.58-8-8-8z" />
		</svg>
	);
}

/**
 * @return {JSX.Element} GitLab fox icon.
 */
function GitLabIcon() {
	return (
		<svg
			aria-hidden="true"
			fill="currentColor"
			height="13"
			style={ { display: 'block', flexShrink: 0 } }
			viewBox="0 0 16 16"
			width="13"
		>
			<path d="M15.97 9.058l-.895-2.756L13.3.842a.382.382 0 0 0-.724 0L10.8 6.302H5.2L3.424.842a.382.382 0 0 0-.724 0L.925 6.302.03 9.058a.762.762 0 0 0 .277.852L8 15.37l7.693-5.46a.762.762 0 0 0 .277-.852z" />
		</svg>
	);
}

/**
 * Repository card displaying repo info, type badge, and install button.
 *
 * @param {Object}      props                 Component props.
 * @param {Object}      props.repo            Repository data object.
 * @param {Object|null} props.detection       Type detection result.
 * @param {Object|null} props.installed       Installed record, if any.
 * @param {boolean}     props.smartInstall    Whether smart install is enabled.
 * @param {boolean}     props.showSourceBadge Whether to show a GitHub/GitLab source badge.
 * @param {Function}    props.onInstall       Callback fired when Install is clicked.
 * @return {JSX.Element} The rendered repo card.
 */
function RepoCard( {
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
								disabled={ ! canInstall }
								isBusy={ detecting && ! smartInstall }
								size="compact"
								title={
									blockedBySmartInstall
										? __(
												'Smart Install is on — only verified WordPress plugins and themes can be installed.',
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
				{ repo.updated_at && (
					<p className="gwp-repo-updated">
						{ __( 'Updated', 'git' ) }{ ' ' }
						{ timeAgo( repo.updated_at ) }
					</p>
				) }
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
			</CardBody>
		</Card>
	);
}

/**
 * Returns a human-readable relative time string (e.g. "3d ago").
 *
 * @param {string} dateStr ISO date string.
 * @return {string} Human-readable relative time.
 */
function timeAgo( dateStr ) {
	const s = Math.floor( ( Date.now() - new Date( dateStr ) ) / 1000 );
	if ( s < 60 ) {
		return 'just now';
	}
	const m = Math.floor( s / 60 );
	if ( m < 60 ) {
		return `${ m }m ago`;
	}
	const h = Math.floor( m / 60 );
	if ( h < 24 ) {
		return `${ h }h ago`;
	}
	const d = Math.floor( h / 24 );
	if ( d < 30 ) {
		return `${ d }d ago`;
	}
	const mo = Math.floor( d / 30 );
	if ( mo < 12 ) {
		return `${ mo }mo ago`;
	}
	return `${ Math.floor( mo / 12 ) }y ago`;
}

/**
 * Badge showing the detected or installed type of a repository.
 *
 * @param {Object}      props           Component props.
 * @param {Object|null} props.detection Type detection result.
 * @param {Object|null} props.installed Installed record, if any.
 * @return {JSX.Element} The rendered type badge.
 */
function TypeBadge( { detection, installed } ) {
	if ( installed ) {
		const t = installed.type;
		return (
			<span
				className={ `gwp-badge gwp-badge--${
					t === 'theme' ? 'theme' : 'info'
				}` }
			>
				{ t === 'theme' ? __( 'Theme', 'git' ) : __( 'Plugin', 'git' ) }
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
