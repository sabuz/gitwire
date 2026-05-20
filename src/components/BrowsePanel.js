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
import InstallModal from './InstallModal';
import * as api from '../api';

const CONCURRENT = 3;

export default function BrowsePanel( { settings, installed, onInstalled } ) {
	const [ repos, setRepos ] = useState( [] );
	const [ page, setPage ] = useState( 1 );
	const [ hasMore, setHasMore ] = useState( false );
	const [ loading, setLoading ] = useState( false );
	const [ error, setError ] = useState( null );
	const [ modal, setModal ] = useState( null );
	const [ search, setSearch ] = useState( '' );

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
	}, [] ); // eslint-disable-line

	useEffect( () => {
		loadRepos( 1 );
	}, [] ); // eslint-disable-line

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

	const filtered = search.trim()
		? repos.filter(
				( r ) =>
					r.full_name
						.toLowerCase()
						.includes( search.toLowerCase() ) ||
					( r.description || '' )
						.toLowerCase()
						.includes( search.toLowerCase() )
		  )
		: repos;

	if ( ! settings?.username && ! settings?.token ) {
		return (
			<Notice status="warning" isDismissible={ false }>
				Configure your GitHub username in Settings before browsing
				repositories.
			</Notice>
		);
	}

	return (
		<div className="ghwp-browse">
			<Flex
				className="ghwp-browse-toolbar"
				gap={ 3 }
				align="center"
				justify="flex-start"
				style={ { marginBottom: 24 } }
			>
				<FlexBlock style={ { maxWidth: 340 } }>
					<SearchControl
						value={ search }
						onChange={ setSearch }
						placeholder="Filter repositories…"
						__nextHasNoMarginBottom
					/>
				</FlexBlock>
				<FlexItem>
					<Button
						variant="secondary"
						onClick={ handleRefresh }
						isBusy={ loading }
						disabled={ loading }
						icon="update"
					>
						Refresh
					</Button>
				</FlexItem>
			</Flex>

			{ error && (
				<Notice
					status="error"
					isDismissible={ false }
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
					No repositories match <strong>{ search }</strong>.
				</p>
			) }

			{ filtered.length > 0 && (
				<div className="ghwp-repo-grid">
					{ filtered.map( ( repo ) => (
						<RepoCard
							key={ repo.id }
							repo={ repo }
							detection={
								detectionsRef.current[ repo.full_name ]
							}
							installed={
								installed[ repo.full_name ] || repo.installed
							}
							smartInstall={ smartInstall }
							onInstall={ () => setModal( repo ) }
						/>
					) ) }
				</div>
			) }

			{ hasMore && ! search && (
				<div style={ { textAlign: 'center', marginTop: 24 } }>
					<Button
						variant="secondary"
						onClick={ () => loadRepos( page + 1 ) }
						isBusy={ loading }
						disabled={ loading }
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

// ---------------------------------------------------------------------------

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
							target="_blank"
							rel="noopener noreferrer"
						>
							{ repo.full_name }
						</a>
					</FlexBlock>
					<FlexItem>
						<Flex gap={ 1 } align="center">
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
							variant="primary"
							size="compact"
							onClick={ onInstall }
							disabled={ ! canInstall }
							isBusy={ detecting && ! smartInstall }
							title={
								blockedBySmartInstall
									? 'Smart Install is on — only verified WordPress plugins and themes can be installed.'
									: undefined
							}
						>
							Install
						</Button>
					) }
				</FlexItem>
			</CardFooter>
		</Card>
	);
}

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
