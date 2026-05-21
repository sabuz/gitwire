import { useState, useEffect, useRef, useCallback } from '@wordpress/element';
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

import * as api from '../api';
import InstallModal from './install-modal';

const CONCURRENT = 3;

/**
 * Browse panel — lists the user's GitHub repositories with detection and install actions.
 *
 * @param {Object}   props             Component props.
 * @param {Object}   props.settings    Plugin settings.
 * @param {Object}   props.installed   Map of installed repositories.
 * @param {Function} props.onInstalled Callback fired after a successful install.
 * @return {JSX.Element} The rendered browse panel.
 */
export default function BrowsePanel( { settings, installed, onInstalled } ) {
	const [ repos, setRepos ] = useState( [] );
	const [ page, setPage ] = useState( 1 );
	const [ hasMore, setHasMore ] = useState( false );
	const [ loading, setLoading ] = useState( false );
	const [ error, setError ] = useState( null );
	const [ modal, setModal ] = useState( null );
	const [ search, setSearch ] = useState( '' );
	const [ typeFilter, setTypeFilter ] = useState( 'all' );

	const detectionsRef = useRef( {} );
	const queueRef = useRef( [] );
	const activeRef = useRef( 0 );
	const [ , forceRender ] = useState( 0 );

	const loadRepos = useCallback( async ( pageNum ) => {
		setLoading( true );
		setError( null );
		try {
			const data = await api.getRepos( pageNum );
			setRepos( ( prev ) =>
				pageNum === 1 ? data.repos : [ ...prev, ...data.repos ]
			);
			setHasMore( data.has_more );
			setPage( pageNum );
			enqueueDetections( data.repos );
		} catch ( e ) {
			setError( e.message || 'Failed to load repositories.' );
		} finally {
			setLoading( false );
		}
	}, [] ); // eslint-disable-line react-hooks/exhaustive-deps

	useEffect( () => {
		loadRepos( 1 );
	}, [] ); // eslint-disable-line react-hooks/exhaustive-deps

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
			api.detectRepo( repo.owner, repo.name, repo.default_branch )
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
		loadRepos( 1 );
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
		{ id: 'all', label: 'All' },
		{ id: 'plugin', label: 'Plugin' },
		{ id: 'theme', label: 'Theme' },
		{ id: 'unknown', label: 'Unknown' },
	];

	if ( ! settings?.username && ! settings?.token ) {
		return (
			<Notice isDismissible={ false } status="warning">
				Configure your GitHub username in Settings before browsing
				repositories.
			</Notice>
		);
	}

	return (
		<div className="ghwp-browse">
			<Flex
				align="center"
				className="ghwp-browse-toolbar"
				gap={ 3 }
				justify="flex-start"
				style={ { marginBottom: 24 } }
			>
				<FlexBlock style={ { maxWidth: 340 } }>
					<SearchControl
						__nextHasNoMarginBottom
						onChange={ setSearch }
						placeholder="Filter repositories…"
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
						Refresh
					</Button>
				</FlexItem>
			</Flex>

			<Flex
				className="ghwp-type-filter"
				gap={ 2 }
				justify="flex-start"
				style={ { marginBottom: 24 } }
			>
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
			</Flex>

			{ error && (
				<Notice
					isDismissible={ false }
					status="error"
					style={ { marginBottom: 16 } }
				>
					{ error }{ ' ' }
					<Button variant="link" onClick={ handleRefresh }>
						Retry
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
							No repositories match <strong>{ search }</strong>.
						</>
					) : (
						'No repositories match the selected filter.'
					) }
				</p>
			) }

			{ filtered.length > 0 && (
				<div className="ghwp-repo-grid">
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
						onClick={ () => loadRepos( page + 1 ) }
					>
						Load more
					</Button>
				</div>
			) }

			{ modal && (
				<InstallModal
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
		<Card className="ghwp-repo-card" size="small">
			<CardHeader>
				<Flex align="center" gap={ 2 } style={ { width: '100%' } }>
					<FlexBlock>
						<a
							className="ghwp-repo-name"
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
								<span
									className={ `ghwp-visibility-badge ${
										repo.private
											? 'ghwp-private'
											: 'ghwp-public'
									}` }
								>
									{ repo.private ? 'Private' : 'Public' }
								</span>
							</FlexItem>
						</Flex>
					</FlexItem>
				</Flex>
			</CardHeader>

			<CardBody>
				{ repo.description && (
					<p className="ghwp-repo-desc">{ repo.description }</p>
				) }
			</CardBody>

			<CardFooter>
				<FlexBlock>
					{ repo.updated_at && (
						<span className="ghwp-repo-updated">
							Updated { timeAgo( repo.updated_at ) }
						</span>
					) }
				</FlexBlock>
				<FlexItem>
					{ isInstalled ? (
						<span className="ghwp-installed-chip">
							<span className="dashicons dashicons-yes-alt" />
							Installed
						</span>
					) : (
						<Button
							disabled={ ! canInstall }
							isBusy={ detecting && ! smartInstall }
							size="compact"
							title={
								blockedBySmartInstall
									? 'Smart Install is on — only verified WordPress plugins and themes can be installed.'
									: undefined
							}
							variant="primary"
							onClick={ onInstall }
						>
							Install
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
		const cls = t === 'theme' ? 'ghwp-type-theme' : 'ghwp-type-plugin';
		return (
			<span className={ `ghwp-type-badge ${ cls }` }>
				{ t === 'theme' ? 'Theme' : 'Plugin' }
			</span>
		);
	}
	if ( ! detection ) {
		return (
			<span className="ghwp-type-badge ghwp-type-detecting">
				<Spinner /> Detecting…
			</span>
		);
	}
	const { type, subtype } = detection;
	if ( type === 'plugin' ) {
		return <span className="ghwp-type-badge ghwp-type-plugin">Plugin</span>;
	}
	if ( type === 'theme' && subtype === 'block' ) {
		return (
			<span className="ghwp-type-badge ghwp-type-theme">Block Theme</span>
		);
	}
	if ( type === 'theme' ) {
		return <span className="ghwp-type-badge ghwp-type-theme">Theme</span>;
	}
	return <span className="ghwp-type-badge ghwp-type-unknown">Unknown</span>;
}
