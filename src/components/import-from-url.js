import { toast } from '../toast';

import { __, sprintf } from '@wordpress/i18n';
import {
	useState,
	useEffect,
	useRef,
	useMemo,
	useCallback,
	createInterpolateElement,
} from '@wordpress/element';
import {
	Button,
	CheckboxControl,
	ComboboxControl,
	Flex,
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
 * @param {Object}   props.connection       Live connection state per provider.
 * @param {Function} props.onPostInstall    Called after a successful install.
 * @param {Function} [props.onGoToSettings] Navigates to the Settings tab.
 * @return {JSX.Element} The rendered import form.
 */
export default function ImportFromUrl( {
	settings,
	connection,
	onPostInstall,
	onGoToSettings,
} ) {
	const [ url, setUrl ] = useState( '' );
	// step: idle | checking | error | resolved | private | verifying-conn | conn-error | installing
	const [ step, setStep ] = useState( 'idle' );
	const [ checkError, setCheckError ] = useState( null );
	const [ resolved, setResolved ] = useState( null );
	const [ connError, setConnError ] = useState( null );

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

		api.getBranches( info.owner, info.repo, info.provider )
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
			setConnError( null );
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
	}, [ url, initInstallForm ] );

	const handleConnectAndContinue = useCallback( async () => {
		if ( ! resolved ) {
			return;
		}
		setStep( 'verifying-conn' );
		setConnError( null );
		try {
			const detectBranch = resolved.branch || 'HEAD';
			const d = await api.detectRepo(
				resolved.owner,
				resolved.repo,
				detectBranch,
				resolved.provider
			);
			const updatedResolved = { ...resolved, detection: d };
			setResolved( updatedResolved );
			initInstallForm( {
				provider: resolved.provider,
				owner: resolved.owner,
				repo: resolved.repo,
				branch: resolved.branch || 'main',
				detection: d,
			} );
			setStep( 'resolved' );
		} catch ( e ) {
			setConnError(
				e.message ||
					__(
						"Repository not found or you don't have access.",
						'gitwire'
					)
			);
			setStep( 'conn-error' );
		}
	}, [ resolved, initInstallForm ] );

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
			api.checkSlug(
				finalizeSlug( slug ),
				type,
				resolved.owner,
				resolved.repo,
				resolved.provider
			)
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

			const check = await api.checkSlug(
				finalSlug,
				installType,
				resolved.owner,
				resolved.repo,
				resolved.provider
			);
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
			} );
			onPostInstall( result, `${ resolved.owner }/${ resolved.repo }` );
		} catch ( e ) {
			toast.error( e.message || __( 'Installation failed.', 'gitwire' ) );
			setStep( 'resolved' );
		}
	}, [ resolved, detection, type, slug, branch, replace, onPostInstall ] );

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

	// Connection info for the detected provider (private path, Phase 1: one per provider).
	const existingConn = resolved ? connection?.[ resolved.provider ] : null;
	const connLabel = existingConn?.authenticated
		? sprintf(
				/* translators: 1: provider name, 2: username */
				__( '%1$s (@%2$s)', 'gitwire' ),
				providerLabel( resolved?.provider ),
				existingConn.login
		  )
		: null;

	return (
		<div className="gitwire-import-url">
			{ /* URL input row — always shown unless actively installing */ }
			{ ! isInstalling && (
				<div className="gitwire-import-url__input-row">
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
					<Button
						__next40pxDefaultSize
						disabled={ ! url.trim() || isBusy }
						isBusy={ step === 'checking' }
						variant="primary"
						onClick={ handleCheck }
					>
						{ step === 'checking'
							? __( 'Checking…', 'gitwire' )
							: __( 'Check Repository', 'gitwire' ) }
					</Button>
				</div>
			) }

			{ /* Resolve error */ }
			{ step === 'error' && checkError && (
				<p className="gitwire-import-url__message is-error">
					{ checkError }
				</p>
			) }

			{ /* Private / not-found state — show connection picker */ }
			{ step === 'private' && resolved && (
				<div className="gitwire-import-url__private">
					<p className="gitwire-import-url__message">
						{ __(
							"Repository not found or you don't have access.",
							'gitwire'
						) }
					</p>
					{ connLabel ? (
						<Flex align="center" gap={ 2 } wrap>
							<span className="gitwire-import-url__conn-hint">
								{ sprintf(
									/* translators: %s: connection label */
									__(
										'Try with saved connection: %s',
										'gitwire'
									),
									connLabel
								) }
							</span>
							<Button
								variant="primary"
								onClick={ handleConnectAndContinue }
							>
								{ __( 'Connect & Continue', 'gitwire' ) }
							</Button>
						</Flex>
					) : (
						<p className="gitwire-import-url__conn-hint">
							{ createInterpolateElement(
								sprintf(
									/* translators: 1: provider name, 2: link to Settings */
									__(
										'Check the URL, or add a %s connection in <a>Settings</a> if this is a private repository.',
										'gitwire'
									),
									providerLabel( resolved.provider )
								),
								{
									a: onGoToSettings ? (
										<Button
											variant="link"
											onClick={ onGoToSettings }
										/>
									) : (
										<span />
									),
								}
							) }
						</p>
					) }
				</div>
			) }

			{ /* Verifying spinner */ }
			{ step === 'verifying-conn' && (
				<div className="gitwire-import-url__private">
					<Flex align="center" gap={ 2 }>
						<Spinner />
						<span>{ __( 'Verifying access…', 'gitwire' ) }</span>
					</Flex>
				</div>
			) }

			{ /* Connection error */ }
			{ step === 'conn-error' && (
				<div className="gitwire-import-url__private">
					<p className="gitwire-import-url__message is-error">
						{ connError }
					</p>
					<Button
						variant="secondary"
						onClick={ () => setStep( 'private' ) }
					>
						{ __( 'Back', 'gitwire' ) }
					</Button>
				</div>
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
			<div className="gitwire-detect-row gitwire-detect-loading">
				<Spinner /> { __( 'Detecting project type…', 'gitwire' ) }
			</div>
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
