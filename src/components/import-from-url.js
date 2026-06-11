import { toast } from '../toast';

import { __, sprintf } from '@wordpress/i18n';
import {
	useState,
	useEffect,
	useRef,
	useMemo,
	useCallback,
} from '@wordpress/element';
import { applyFilters } from '@wordpress/hooks';
import {
	Button,
	CheckboxControl,
	ComboboxControl,
	Flex,
	FlexBlock,
	SelectControl,
	Spinner,
	TextControl,
} from '@wordpress/components';

import * as api from '../api';

function normalizeSlug( value ) {
	return value
		.toLowerCase()
		.replace( /[^a-z0-9_-]+/g, '-' )
		.replace( /[-_]*-[-_]*/g, '-' )
		.replace( /__+/g, '_' );
}

function finalizeSlug( value ) {
	return normalizeSlug( value ).replace( /^[-_]+|[-_]+$/g, '' );
}

/**
 * @param {string} provider 'github' | 'gitlab' | 'bitbucket'
 * @return {string} Display label.
 */
function providerLabel( provider ) {
	return (
		{ github: 'GitHub', gitlab: 'GitLab', bitbucket: 'Bitbucket' }[
			provider
		] ?? provider
	);
}

/**
 * @param {boolean} installing   Install request in flight.
 * @param {boolean} slugChecking Slug validation in progress.
 * @return {string} Button label.
 */
function installButtonLabel( installing, slugChecking ) {
	if ( installing ) {
		return __( 'Installing…', 'gitwire' );
	}
	if ( slugChecking ) {
		return __( 'Checking…', 'gitwire' );
	}
	return __( 'Install', 'gitwire' );
}

/**
 * Full Import from URL flow — URL input → Check → install form (inline, no modal).
 *
 * @param {Object}   props                  Component props.
 * @param {Object}   props.settings         Plugin settings.
 * @param {Function} props.onPostInstall    Called after a successful install.
 * @param {Function} [props.onGoToSettings] Navigates to the Settings tab.
 * @param {Object}   props.installed        Map of installed repos keyed by provider:full_name.
 * @return {JSX.Element} The rendered import form.
 */
export default function ImportFromUrl( {
	settings,
	installed,
	onPostInstall,
	onGoToSettings,
} ) {
	const [ url, setUrl ] = useState( '' );
	// step: idle | checking | error | resolved | private | verifying-conn | installing
	const [ step, setStep ] = useState( 'idle' );
	const [ checkError, setCheckError ] = useState( null );
	const [ resolved, setResolved ] = useState( null );

	// Install-form state (populated once resolve succeeds or connection verified).
	const [ allBranches, setAllBranches ] = useState( [] );
	const [ branch, setBranch ] = useState( 'main' );
	const [ branchFilter, setBranchFilter ] = useState( '' );
	const [ detection, setDetection ] = useState( null );
	const [ type, setType ] = useState( 'plugin' );
	const [ slug, setSlug ] = useState( '' );
	const [ slugConflict, setSlugConflict ] = useState( false );
	const [ slugChecking, setSlugChecking ] = useState( false );
	const [ replace, setReplace ] = useState( false );
	const [ connId, setConnId ] = useState( null );
	const debounceRef = useRef( null );

	const smartInstall = settings?.smart_install !== false;

	const initInstallForm = useCallback( ( info ) => {
		const defaultBranch = info.branch || 'main';
		setBranch( defaultBranch );
		setDetection( info.detection );
		setType(
			info.detection?.type === 'plugin' ||
				info.detection?.type === 'theme'
				? info.detection.type
				: 'plugin'
		);
		setSlug( normalizeSlug( info.repo ) );
		setSlugConflict( false );
		setSlugChecking( false );
		setReplace( false );
		setAllBranches( [] );
		setBranchFilter( '' );

		api.getBranches(
			info.owner,
			info.repo,
			info.provider,
			info.connection_id || ''
		)
			.then( ( b ) => {
				setAllBranches( b );
				// if the hardcoded fallback branch doesn't exist, use the repo's real default
				setBranch( ( current ) =>
					b.length > 0 && ! b.includes( current ) ? b[ 0 ] : current
				);
			} )
			.catch( () => {} );
	}, [] );

	const handleUrlChange = ( val ) => {
		setUrl( val );
		if ( step !== 'idle' && step !== 'checking' ) {
			setStep( 'idle' );
			setResolved( null );
			setCheckError( null );
		}
	};

	const handleCheck = useCallback( async () => {
		const trimmed = url.trim();
		if ( ! trimmed ) {
			return;
		}
		setStep( 'checking' );
		setCheckError( null );
		setResolved( null );
		try {
			const result = await api.resolveRepo( trimmed );
			setResolved( result );
			const installedKey = `${ result.provider }:${ result.owner }/${ result.repo }`;
			if ( installed?.[ installedKey ] ) {
				setCheckError(
					__( 'This repository is already installed.', 'gitwire' )
				);
				setStep( 'error' );
				return;
			}
			if ( result.is_public ) {
				initInstallForm( {
					provider: result.provider,
					owner: result.owner,
					repo: result.repo,
					branch: result.branch || 'main',
					detection: result.detection,
				} );
				setStep( 'resolved' );
			} else {
				setStep( 'private' );
			}
		} catch ( e ) {
			setCheckError(
				e.message || __( 'Could not check repository.', 'gitwire' )
			);
			setStep( 'error' );
		}
	}, [ url, initInstallForm, installed ] );

	const handleConnectAndContinue = useCallback(
		async ( passedConnId ) => {
			if ( ! resolved ) {
				return;
			}
			const selectedConnId =
				typeof passedConnId === 'string' ? passedConnId : null;
			setStep( 'verifying-conn' );
			try {
				const detectBranch = resolved.branch || 'HEAD';
				const d = await api.detectRepo(
					resolved.owner,
					resolved.repo,
					detectBranch,
					resolved.provider,
					selectedConnId
				);
				const installedKey = `${ resolved.provider }:${ resolved.owner }/${ resolved.repo }`;
				if ( installed?.[ installedKey ] ) {
					setCheckError(
						__( 'This repository is already installed.', 'gitwire' )
					);
					setStep( 'error' );
					return;
				}
				const updatedResolved = { ...resolved, detection: d };
				setResolved( updatedResolved );
				setConnId( selectedConnId );
				initInstallForm( {
					provider: resolved.provider,
					owner: resolved.owner,
					repo: resolved.repo,
					branch: resolved.branch || 'main',
					detection: d,
					connection_id: selectedConnId,
				} );
				setStep( 'resolved' );
			} catch ( e ) {
				setStep( 'private' );
			}
		},
		[ resolved, initInstallForm, installed ]
	);

	// Debounced slug conflict check — only active while showing the install form.
	useEffect( () => {
		if ( step !== 'resolved' || ! slug || ! resolved ) {
			setSlugConflict( false );
			setSlugChecking( false );
			return;
		}
		setSlugChecking( true );
		setReplace( false );
		clearTimeout( debounceRef.current );
		let cancelled = false;
		debounceRef.current = setTimeout( () => {
			api.checkSlug( finalizeSlug( slug ), type )
				.then( ( r ) => {
					if ( cancelled ) {
						return;
					}
					setSlugConflict( r.conflict );
					setSlugChecking( false );
				} )
				.catch( () => {
					if ( ! cancelled ) {
						setSlugConflict( false );
						setSlugChecking( false );
					}
				} );
		}, 400 );
		return () => {
			cancelled = true;
			clearTimeout( debounceRef.current );
		};
	}, [ slug, type, step ] ); // eslint-disable-line react-hooks/exhaustive-deps

	const handleInstall = useCallback( async () => {
		if ( ! resolved ) {
			return;
		}
		setStep( 'installing' );
		try {
			const installType =
				detection?.type !== 'unknown' ? detection?.type : type;
			const finalSlug = finalizeSlug( slug );

			const check = await api.checkSlug( finalSlug, installType );
			if ( check.conflict && ! replace ) {
				setSlugConflict( true );
				setStep( 'resolved' );
				return;
			}

			const result = await api.install( {
				owner: resolved.owner,
				repo: resolved.repo,
				branch,
				type: installType,
				provider: resolved.provider,
				slug: finalSlug,
				replace,
				force_type: true,
				connection_id: connId || undefined,
			} );
			onPostInstall( result, `${ resolved.owner }/${ resolved.repo }` );
		} catch ( e ) {
			toast.error( e.message || __( 'Installation failed.', 'gitwire' ) );
			setStep( 'resolved' );
		}
	}, [
		resolved,
		detection,
		type,
		slug,
		branch,
		replace,
		onPostInstall,
		connId,
	] );

	const branchOptions = useMemo( () => {
		const filter = branchFilter.toLowerCase();
		const source = allBranches.length ? allBranches : [ branch ];
		const filtered = filter
			? source.filter( ( b ) => b.toLowerCase().includes( filter ) )
			: source;
		const top = filtered.slice( 0, 10 );
		if ( ! filter && branch && ! top.includes( branch ) ) {
			top.unshift( branch );
			top.splice( 10 );
		}
		return top.map( ( b ) => ( { label: b, value: b } ) );
	}, [ allBranches, branchFilter, branch ] );

	const canInstall =
		!! slug &&
		! slugChecking &&
		detection &&
		( detection.type !== 'unknown' || ! smartInstall ) &&
		( ! slugConflict || replace );

	const isInstalling = step === 'installing';
	const showInstallForm = step === 'resolved' || step === 'installing';
	const isBusy =
		step === 'checking' || step === 'verifying-conn' || isInstalling;

	// Pro renders a connection picker here; free shows a passive notice
	const privatePicker = resolved
		? applyFilters( 'gitwire.importUrl.privatePicker', null, {
				resolved,
				onPick: handleConnectAndContinue,
				onGoToSettings,
		  } )
		: null;

	return (
		<div className="gitwire-import-url">
			{ /* URL input row — always shown unless actively installing */ }
			{ ! isInstalling && (
				<Flex
					gap={ 3 }
					align="flex-end"
					className="gitwire-import-url__input-row"
				>
					<FlexBlock style={ { minWidth: 0 } }>
						<TextControl
							__nextHasNoMarginBottom
							className="gitwire-import-url__input"
							disabled={ isBusy }
							label={ __( 'Repository URL', 'gitwire' ) }
							placeholder="https://github.com/owner/repo"
							type="url"
							value={ url }
							onChange={ handleUrlChange }
							onKeyDown={ ( ev ) => {
								if (
									ev.key === 'Enter' &&
									url.trim() &&
									! isBusy
								) {
									handleCheck();
								}
							} }
						/>
					</FlexBlock>
					<Button
						__next40pxDefaultSize
						disabled={ ! url.trim() || isBusy }
						isBusy={
							step === 'checking' || step === 'verifying-conn'
						}
						variant="primary"
						onClick={ handleCheck }
					>
						{ step === 'checking' || step === 'verifying-conn'
							? __( 'Checking…', 'gitwire' )
							: __( 'Check Repository', 'gitwire' ) }
					</Button>
				</Flex>
			) }

			{ /* Resolve error */ }
			{ step === 'error' && checkError && (
				<p className="gitwire-import-url__message is-error">
					{ checkError }
				</p>
			) }

			{ /* Private / not-found state */ }
			{ ( step === 'private' || step === 'verifying-conn' ) &&
				resolved && (
					<>
						<p className="gitwire-import-url__message is-error">
							{ __(
								"Repository not found or you don't have access.",
								'gitwire'
							) }
						</p>
						<div className="gitwire-import-url__private">
							{ privatePicker ?? (
								<p>
									{ sprintf(
										/* translators: %s: Git provider name (e.g. GitHub) */
										__(
											'If this is a private %s repository, you need an access token to install it.',
											'gitwire'
										),
										providerLabel( resolved.provider )
									) }{ ' ' }
									<a
										href="https://gitwire.app/pro"
										rel="noopener noreferrer"
										target="_blank"
									>
										{ __(
											'Gitwire Pro supports private repositories.',
											'gitwire'
										) }
									</a>
								</p>
							) }
						</div>
					</>
				) }

			{ /* Inline install form (public path or post-connection verify) */ }
			{ showInstallForm && resolved && (
				<div className="gitwire-import-url__install-form">
					<ResolvedBadge
						detection={ detection }
						smartInstall={ smartInstall }
					/>

					{ detection?.type === 'unknown' && ! smartInstall && (
						<div style={ { marginTop: 16 } }>
							<SelectControl
								__next40pxDefaultSize
								__nextHasNoMarginBottom
								disabled={ isInstalling }
								label={ __( 'Install as', 'gitwire' ) }
								options={ [
									{
										label: __( 'Plugin', 'gitwire' ),
										value: 'plugin',
									},
									{
										label: __( 'Theme', 'gitwire' ),
										value: 'theme',
									},
								] }
								value={ type }
								onChange={ setType }
							/>
						</div>
					) }

					<div style={ { marginTop: 16 } }>
						<ComboboxControl
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							disabled={ isInstalling }
							label={ __( 'Branch', 'gitwire' ) }
							options={ branchOptions }
							value={ branch }
							onChange={ ( val ) => val && setBranch( val ) }
							onFilterValueChange={ setBranchFilter }
						/>
					</div>

					<div
						className={
							slugConflict ? 'gitwire-input-error' : undefined
						}
						style={ { marginTop: 16 } }
					>
						<TextControl
							__nextHasNoMarginBottom
							disabled={ isInstalling }
							label={ __( 'Directory name', 'gitwire' ) }
							value={ slug }
							onChange={ ( val ) =>
								setSlug( normalizeSlug( val ) )
							}
						/>
						{ slugConflict && (
							<>
								<p
									className="gitwire-detect-note gitwire-detect-blocked"
									style={ { margin: '8px 0' } }
								>
									{ __(
										'A directory with this name already exists.',
										'gitwire'
									) }
								</p>
								<CheckboxControl
									__nextHasNoMarginBottom
									checked={ replace }
									label={ __(
										'Replace existing installation',
										'gitwire'
									) }
									onChange={ setReplace }
								/>
							</>
						) }
					</div>

					<Flex
						gap={ 3 }
						justify="flex-end"
						style={ { marginTop: 24 } }
					>
						<Button
							disabled={ isInstalling }
							variant="tertiary"
							onClick={ () => {
								setStep( 'idle' );
								setResolved( null );
							} }
						>
							{ __( 'Cancel', 'gitwire' ) }
						</Button>
						<Button
							disabled={ ! canInstall || isInstalling }
							isBusy={ isInstalling || slugChecking }
							variant="primary"
							onClick={ handleInstall }
						>
							{ installButtonLabel( isInstalling, slugChecking ) }
						</Button>
					</Flex>
				</div>
			) }
		</div>
	);
}

/**
 * Badge shown above the install form after a successful URL check.
 *
 * @param {Object}      props              Component props.
 * @param {Object|null} props.detection    Detection result from the resolve endpoint.
 * @param {boolean}     props.smartInstall Whether smart install is enabled.
 * @return {JSX.Element} The rendered detection badge.
 */
function ResolvedBadge( { detection, smartInstall } ) {
	if ( ! detection ) {
		return (
			<Flex
				gap={ 2 }
				align="center"
				className="gitwire-detect-row gitwire-detect-loading"
			>
				<Spinner />
				{ __( 'Detecting project type…', 'gitwire' ) }
			</Flex>
		);
	}

	const { type, subtype, confidence, name } = detection;
	let badgeClass, label;

	if ( type === 'plugin' ) {
		badgeClass = 'gitwire-detect-plugin';
		label =
			confidence === 'high'
				? sprintf(
						/* translators: %s: plugin name */
						__( 'WordPress Plugin%s', 'gitwire' ),
						name ? `: ${ name }` : ''
				  )
				: __( 'Likely a WordPress Plugin', 'gitwire' );
	} else if ( type === 'theme' && subtype === 'block' ) {
		badgeClass = 'gitwire-detect-theme';
		label =
			confidence === 'high'
				? sprintf(
						/* translators: %s: theme name */
						__( 'Block Theme%s', 'gitwire' ),
						name ? `: ${ name }` : ''
				  )
				: __( 'Likely a Block Theme', 'gitwire' );
	} else if ( type === 'theme' ) {
		badgeClass = 'gitwire-detect-theme';
		label =
			confidence === 'high'
				? sprintf(
						/* translators: %s: theme name */
						__( 'Classic Theme%s', 'gitwire' ),
						name ? `: ${ name }` : ''
				  )
				: __( 'Likely a Classic Theme', 'gitwire' );
	} else {
		badgeClass = 'gitwire-detect-unknown';
		label = __( 'Not recognised as a WordPress project', 'gitwire' );
	}

	return (
		<div className="gitwire-detect-row">
			<span className={ `gitwire-detect-badge ${ badgeClass }` }>
				{ label }
			</span>
			{ type === 'unknown' && smartInstall && (
				<p className="gitwire-detect-note gitwire-detect-blocked">
					{ __(
						'Smart Install is enabled. Only verified plugins and themes can be installed. Disable it in Settings to override.',
						'gitwire'
					) }
				</p>
			) }
			{ type === 'unknown' && ! smartInstall && (
				<p className="gitwire-detect-note gitwire-detect-warn">
					{ __(
						'This repository was not recognised as a WordPress plugin or theme. You can still install it. Choose a type below.',
						'gitwire'
					) }
				</p>
			) }
		</div>
	);
}
