import { useState, useEffect, useRef, useCallback } from '@wordpress/element';
import { Button, Spinner, Notice, Flex, FlexBlock, FlexItem, Badge } from '@wordpress/components';
import InstallModal from './InstallModal';
import * as api from '../api';

const CONCURRENT = 3;

export default function BrowsePanel( { settings, installed, onInstalled } ) {
	const [ repos,    setRepos    ] = useState( [] );
	const [ page,     setPage     ] = useState( 1 );
	const [ hasMore,  setHasMore  ] = useState( false );
	const [ loading,  setLoading  ] = useState( false );
	const [ error,    setError    ] = useState( null );
	const [ modal,    setModal    ] = useState( null ); // repo object

	const detectionsRef = useRef( {} );    // full_name → detection result
	const queueRef      = useRef( [] );    // pending full_names
	const activeRef     = useRef( 0 );
	const [ , forceRender ] = useState( 0 );

	const loadRepos = useCallback( async ( pageNum ) => {
		setLoading( true );
		setError( null );
		try {
			const data = await api.getRepos( pageNum );
			setRepos( ( prev ) => pageNum === 1 ? data.repos : [ ...prev, ...data.repos ] );
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
		const toDetect = newRepos.filter( ( r ) => {
			if ( r.installed ) return false;
			if ( detectionsRef.current[ r.full_name ] ) return false;
			return true;
		} );
		queueRef.current.push( ...toDetect );
		drain();
	};

	const drain = () => {
		while ( activeRef.current < CONCURRENT && queueRef.current.length > 0 ) {
			const repo = queueRef.current.shift();
			activeRef.current++;
			api.detectRepo( repo.owner, repo.name, repo.default_branch )
				.then( ( d ) => {
					detectionsRef.current[ repo.full_name ] = d;
				} )
				.catch( () => {
					detectionsRef.current[ repo.full_name ] = { type: 'unknown', confidence: 'none' };
				} )
				.finally( () => {
					activeRef.current--;
					forceRender( ( n ) => n + 1 );
					drain();
				} );
		}
	};

	const smartInstall = settings?.smart_install !== false;

	const handleInstalled = ( result ) => {
		onInstalled( result );
	};

	if ( ! settings?.username && ! settings?.token ) {
		return (
			<Notice status="warning" isDismissible={ false }>
				Configure your GitHub username in Settings before browsing repositories.
			</Notice>
		);
	}

	return (
		<div className="ghwp-browse">
			{ error && (
				<Notice status="error" isDismissible={ false } style={ { marginBottom: 16 } }>
					{ error }{ ' ' }
					<Button variant="link" onClick={ () => loadRepos( 1 ) }>Retry</Button>
				</Notice>
			) }

			{ repos.length === 0 && loading && (
				<div style={ { textAlign: 'center', padding: 40 } }><Spinner /></div>
			) }

			{ repos.length > 0 && (
				<div className="ghwp-repo-grid">
					{ repos.map( ( repo ) => (
						<RepoCard
							key={ repo.id }
							repo={ repo }
							detection={ detectionsRef.current[ repo.full_name ] }
							installed={ installed[ repo.full_name ] || repo.installed }
							smartInstall={ smartInstall }
							onInstall={ () => setModal( repo ) }
						/>
					) ) }
				</div>
			) }

			{ hasMore && (
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
						handleInstalled( result );
					} }
				/>
			) }
		</div>
	);
}

// ---------------------------------------------------------------------------

function RepoCard( { repo, detection, installed, smartInstall, onInstall } ) {
	const isInstalled = !! installed;
	const canInstall  = ! isInstalled && (
		! detection
			? ! smartInstall
			: detection.type !== 'unknown' || ! smartInstall
	);

	return (
		<div className="ghwp-repo-card">
			<Flex align="flex-start" gap={ 2 } style={ { marginBottom: 6 } }>
				<FlexBlock>
					<div className="ghwp-repo-name">
						<a href={ repo.html_url } target="_blank" rel="noopener noreferrer">
							{ repo.full_name }
						</a>
					</div>
				</FlexBlock>
				<FlexItem>
					<span className={ `ghwp-visibility-badge ${ repo.private ? 'ghwp-private' : 'ghwp-public' }` }>
						{ repo.private ? 'Private' : 'Public' }
					</span>
				</FlexItem>
			</Flex>

			{ repo.description && (
				<p className="ghwp-repo-desc">{ repo.description }</p>
			) }

			<Flex align="center" gap={ 2 } style={ { marginTop: 10 } }>
				<FlexBlock>
					<TypeBadge detection={ detection } installed={ installed } />
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
							size="small"
							onClick={ onInstall }
							disabled={ ! canInstall && !! detection }
							title={
								detection?.type === 'unknown' && smartInstall
									? 'Smart Install is enabled — only verified WordPress projects can be installed.'
									: undefined
							}
						>
							Install
						</Button>
					) }
				</FlexItem>
			</Flex>
		</div>
	);
}

function TypeBadge( { detection, installed } ) {
	if ( installed ) {
		const t = installed.type;
		const cls = t === 'theme' ? 'ghwp-type-theme' : 'ghwp-type-plugin';
		const label = t === 'theme' ? 'Theme' : 'Plugin';
		return <span className={ `ghwp-type-badge ${ cls }` }>{ label }</span>;
	}
	if ( ! detection ) {
		return <span className="ghwp-type-badge ghwp-type-detecting"><Spinner /></span>;
	}
	const { type, subtype } = detection;
	if ( type === 'plugin' ) {
		return <span className="ghwp-type-badge ghwp-type-plugin">Plugin</span>;
	}
	if ( type === 'theme' && subtype === 'block' ) {
		return <span className="ghwp-type-badge ghwp-type-theme">Block Theme</span>;
	}
	if ( type === 'theme' ) {
		return <span className="ghwp-type-badge ghwp-type-theme">Theme</span>;
	}
	return <span className="ghwp-type-badge ghwp-type-unknown">Unknown</span>;
}
