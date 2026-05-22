import { __ } from '@wordpress/i18n';
import {
	useState,
	useEffect,
	useRef,
	useCallback,
	useMemo,
} from '@wordpress/element';
import {
	Button,
	Spinner,
	Notice,
	Flex,
	FlexBlock,
	FlexItem,
	Card,
	CardBody,
	CardHeader,
	CardFooter,
	SearchControl,
} from '@wordpress/components';
import { Badge } from '@wordpress/ui';

import * as api from '../api';
import ConnectPrompt from './connect-prompt';
import InstallModal from './install-modal';

const CONCURRENT = 3;

/**
 * Browse panel — lists the user's GitHub repositories with detection and install actions.
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
	const defaultProvider = useMemo(
		() => ( hasGitHub ? 'github' : 'gitlab' ),
		[] // eslint-disable-line react-hooks/exhaustive-deps
	);

	const [ repos, setRepos ] = useState( [] );
	const [ page, setPage ] = useState( 1 );
	const [ hasMore, setHasMore ] = useState( false );
	const [ loading, setLoading ] = useState( false );
	const [ error, setError ] = useState( null );
	const [ modal, setModal ] = useState( null );
	const [ search, setSearch ] = useState( '' );
	const [ typeFilter, setTypeFilter ] = useState( 'all' );
	const [ provider, setProvider ] = useState( defaultProvider );

	const detectionsRef = useRef( {} );
	const queueRef = useRef( [] );
	const activeRef = useRef( 0 );
	const providerRef = useRef( provider );
	const [ , forceRender ] = useState( 0 );

	const loadRepos = useCallback( async ( pageNum, activeProvider ) => {
		setLoading( true );
		setError( null );
		try {
			const data = await api.getRepos( pageNum, activeProvider );
			setRepos( ( prev ) =>
				pageNum === 1 ? data.repos : [ ...prev, ...data.repos ]
			);
			setHasMore( data.has_more );
			setPage( pageNum );
			enqueueDetections( data.repos );
		} catch ( e ) {
			setError(
				e.message || __( 'Failed to load repositories.', 'git' )
			);
		} finally {
			setLoading( false );
		}
	}, [] ); // eslint-disable-line react-hooks/exhaustive-deps

	useEffect( () => {
		providerRef.current = provider;
		setRepos( [] );
		setPage( 1 );
		setHasMore( false );
		detectionsRef.current = {};
		queueRef.current = [];
		loadRepos( 1, provider );
	}, [ provider ] ); // eslint-disable-line react-hooks/exhaustive-deps

	const enqueueDetections = ( newRepos ) => {
		const toDetect = newRepos.filter(
			( r ) => ! r.installed && ! detectionsRef.current[ r.full_name ]
		);
		queueRef.current.push( ...toDetect );
		drain();
	};

	const drain = () => {
		while (
			activeRef.current < CONCURRENT &&
			queueRef.current.length > 0
		) {
			const repo = queueRef.current.shift();
			activeRef.current++;
			api.detectRepo(
				repo.owner,
				repo.name,
				repo.default_branch,
				providerRef.current
			)
				.then( ( d ) => {
					detectionsRef.current[ repo.full_name ] = d;
				} )
				.catch( () => {
					detectionsRef.current[ repo.full_name ] = {
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

	const handleRefresh = () => {
		setRepos( [] );
		setPage( 1 );
		setHasMore( false );
		detectionsRef.current = {};
		queueRef.current = [];
		loadRepos( 1, provider );
	};

	const smartInstall = settings?.smart_install !== false;

	const matchesSearch = ( r ) => {
		if ( ! search.trim() ) {
			return true;
		}
		const q = search.toLowerCase();
		return (
			r.full_name.toLowerCase().includes( q ) ||
			( r.description || '' ).toLowerCase().includes( q )
		);
	};

	const matchesType = ( r ) => {
		if ( typeFilter === 'all' ) {
			return true;
		}
		const installedRec = installed[ r.full_name ] || r.installed;
		const type =
			installedRec?.type ?? detectionsRef.current[ r.full_name ]?.type;
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
				gap={ 3 }
				justify="flex-start"
				style={ { marginBottom: 16 } }
			>
				<FlexBlock style={ { maxWidth: 340 } }>
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
					<Button
						disabled={ loading }
						icon="update"
						isBusy={ loading }
						variant="secondary"
						onClick={ handleRefresh }
					>
						{ __( 'Refresh', 'git' ) }
					</Button>
				</FlexItem>
			</Flex>

			<Flex
				className="gwp-browse-filters"
				gap={ 2 }
				justify="flex-start"
				style={ { marginBottom: 24 } }
				wrap
			>
				{ hasGitHub && hasGitLab && (
					<>
						<Button
							isPressed={ provider === 'github' }
							size="compact"
							variant="secondary"
							onClick={ () => setProvider( 'github' ) }
						>
							{ __( 'GitHub', 'git' ) }
						</Button>
						<Button
							isPressed={ provider === 'gitlab' }
							size="compact"
							variant="secondary"
							onClick={ () => setProvider( 'gitlab' ) }
						>
							{ __( 'GitLab', 'git' ) }
						</Button>
						<span
							aria-hidden="true"
							style={ {
								borderLeft: '1px solid #ddd',
								margin: '0 4px',
							} }
						/>
					</>
				) }
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

			{ error && (
				<Notice
					isDismissible={ false }
					status="error"
					style={ { marginBottom: 16 } }
				>
					{ error }{ ' ' }
					<Button variant="link" onClick={ handleRefresh }>
						{ __( 'Retry', 'git' ) }
					</Button>
				</Notice>
			) }

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
							key={ repo.id }
							detection={
								detectionsRef.current[ repo.full_name ]
							}
							installed={
								installed[ repo.full_name ] || repo.installed
							}
							repo={ repo }
							smartInstall={ smartInstall }
							onInstall={ () => setModal( repo ) }
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
						onClick={ () => loadRepos( page + 1, provider ) }
					>
						{ __( 'Load more', 'git' ) }
					</Button>
				</div>
			) }

			{ modal && (
				<InstallModal
					provider={ provider }
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
 * Repository card displaying repo info, type badge, and install button.
 *
 * @param {Object}      props              Component props.
 * @param {Object}      props.repo         Repository data object.
 * @param {Object|null} props.detection    Type detection result.
 * @param {Object|null} props.installed    Installed record, if any.
 * @param {boolean}     props.smartInstall Whether smart install is enabled.
 * @param {Function}    props.onInstall    Callback fired when Install is clicked.
 * @return {JSX.Element} The rendered repo card.
 */
function RepoCard( { repo, detection, installed, smartInstall, onInstall } ) {
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
			<CardHeader>
				<Flex align="center" gap={ 2 } style={ { width: '100%' } }>
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
						<Flex align="center" gap={ 1 }>
							<FlexItem>
								<TypeBadge
									detection={ detection }
									installed={ installed }
								/>
							</FlexItem>
							<FlexItem>
								<Badge
									intent={
										repo.private ? 'medium' : 'stable'
									}
								>
									{ repo.private ? 'Private' : 'Public' }
								</Badge>
							</FlexItem>
						</Flex>
					</FlexItem>
				</Flex>
			</CardHeader>

			<CardBody>
				{ repo.description && (
					<p className="gwp-repo-desc">{ repo.description }</p>
				) }
			</CardBody>

			<CardFooter>
				<FlexBlock>
					{ repo.updated_at && (
						<span className="gwp-repo-updated">
							Updated { timeAgo( repo.updated_at ) }
						</span>
					) }
				</FlexBlock>
				<FlexItem>
					{ isInstalled ? (
						<span className="gwp-installed-chip">
							<span className="dashicons dashicons-yes-alt" />
							{ __( 'Installed', 'git' ) }
						</span>
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
							variant="primary"
							onClick={ onInstall }
						>
							{ __( 'Install', 'git' ) }
						</Button>
					) }
				</FlexItem>
			</CardFooter>
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
			<Badge intent={ t === 'theme' ? 'none' : 'informational' }>
				{ t === 'theme' ? __( 'Theme', 'git' ) : __( 'Plugin', 'git' ) }
			</Badge>
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
		return <Badge intent="informational">{ __( 'Plugin', 'git' ) }</Badge>;
	}
	if ( type === 'theme' && subtype === 'block' ) {
		return <Badge intent="none">{ __( 'Block Theme', 'git' ) }</Badge>;
	}
	if ( type === 'theme' ) {
		return <Badge intent="none">{ __( 'Theme', 'git' ) }</Badge>;
	}
	return <Badge intent="draft">{ __( 'Unknown', 'git' ) }</Badge>;
}
