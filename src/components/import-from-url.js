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
	TextControl,
} from '@wordpress/components';

import * as api from '../api';
import { normalizeSlug, finalizeSlug } from '../slug';
import { providerLabel } from './provider';
import DetectionBadge from './detection-badge';

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
 * Full Import from URL flow: URL input, check, and inline install form.
 *
 * @param {Object}   props                  Component props.
 * @param {Object}   props.settings         Plugin settings.
 * @param {Function} props.onPostInstall    Called after a successful install.
 * @param {Function} [props.onGoToSettings] Navigates to the Settings tab.
 * @param {Object}   props.installed        Map of installed repositories keyed by provider:full_name.
 * @return {JSX.Element} The rendered import form.
 */
export default function ImportFromUrl( {
	settings,
	installed,
	onPostInstall,
	onGoToSettings,
} ) {
	const [ url, setUrl ] = useState( '' );
	/* Install states: idle, checking, error, resolved, private, verifying connection, or installing. */
	const [ step, setStep ] = useState( 'idle' );
	const [ checkError, setCheckError ] = useState( null );
	const [ resolved, setResolved ] = useState( null );

	// State used by the install form after resolving a repository or verifying access.
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
	const autoDetectType = settings?.auto_detect_type !== false;

	const initInstallForm = useCallback(
		( info ) => {
			const defaultBranch = info.branch || 'main';
			setBranch( defaultBranch );
			/*
			 * Resolve and connection verification still perform detection because they
			 * confirm repository access. When auto-detection is disabled, the result is
			 * not used for type selection or display.
			 */
			setDetection( autoDetectType ? info.detection : null );
			setType(
				autoDetectType &&
					[
						'plugin',
						'theme',
						'block-theme',
						'classic-theme',
					].includes( info.detection?.type )
					? info.detection.type
					: 'plugin'
			);
			setSlug( normalizeSlug( info.repository ) );
			setSlugConflict( false );
			setSlugChecking( false );
			setReplace( false );
			setAllBranches( [] );
			setBranchFilter( '' );

			// Skip branch loading when Smart Install blocks the repository.
			if ( smartInstall && 'unknown' === info.detection?.type ) {
				return;
			}

			api.getBranches(
				info.owner,
				info.repository,
				info.provider,
				info.connection_id || ''
			)
				.then( ( b ) => {
					setAllBranches( b );
					// Use the first available branch when the current branch is unavailable.
					setBranch( ( current ) =>
						b.length > 0 && ! b.includes( current )
							? b[ 0 ]
							: current
					);
				} )
				.catch( () => {} );
		},
		[ autoDetectType, smartInstall ]
	);

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
			const result = await api.resolveRepository( trimmed );
			setResolved( result );
			const installedKey = `${ result.provider }:${ result.owner }/${ result.repository }`;
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
					repository: result.repository,
					branch: result.branch || 'main',
					detection: result.detection,
				} );
				setStep( 'resolved' );
			} else if ( result.error && ! result.error.is_access ) {
				// Treat rate limits and provider outages separately from access errors.
				setCheckError( result.error.message );
				setStep( 'error' );
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
				const d = await api.detectRepository(
					resolved.owner,
					resolved.repository,
					detectBranch,
					resolved.provider,
					selectedConnId
				);
				const installedKey = `${ resolved.provider }:${ resolved.owner }/${ resolved.repository }`;
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
					repository: resolved.repository,
					branch: resolved.branch || 'main',
					detection: d,
					connection_id: selectedConnId,
				} );
				setStep( 'resolved' );
			} catch ( e ) {
				/*
				 * Only 401 and 404 indicate that this connection cannot access the
				 * repository.
				 */
				const status = e?.data?.status ?? 0;
				if ( 401 !== status && 404 !== status ) {
					setCheckError(
						e?.message ||
							__( 'Could not check repository.', 'gitwire' )
					);
					setStep( 'error' );
					return;
				}
				setStep( 'private' );
			}
		},
		[ resolved, initInstallForm, installed ]
	);

	// Check for slug conflicts only while the install form is visible.
	useEffect( () => {
		if (
			step !== 'resolved' ||
			! slug ||
			! resolved ||
			blockedBySmartInstall
		) {
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
				detection && 'unknown' !== detection.type
					? detection.type
					: type;
			const finalSlug = finalizeSlug( slug );

			const check = await api.checkSlug( finalSlug, installType );
			if ( check.conflict && ! replace ) {
				setSlugConflict( true );
				setStep( 'resolved' );
				return;
			}

			const result = await api.install( {
				owner: resolved.owner,
				repository: resolved.repository,
				branch,
				type: installType,
				provider: resolved.provider,
				slug: finalSlug,
				replace,
				force_type: true,
				connection_id: connId || undefined,
			} );
			onPostInstall(
				result,
				`${ resolved.owner }/${ resolved.repository }`
			);
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

	/*
	 * Ask for a type when automatic type detection is disabled or Smart Install
	 * allows unknown types.
	 */
	const askForType =
		! autoDetectType || ( 'unknown' === detection?.type && ! smartInstall );

	// Hide installation options when Smart Install blocks the repository.
	const blockedBySmartInstall = smartInstall && 'unknown' === detection?.type;

	const canInstall =
		!! slug &&
		! slugChecking &&
		( ! autoDetectType || !! detection ) &&
		( ! detection || detection.type !== 'unknown' || ! smartInstall ) &&
		( ! slugConflict || replace );

	const isInstalling = step === 'installing';
	const showInstallForm = step === 'resolved' || step === 'installing';
	const isBusy =
		step === 'checking' || step === 'verifying-conn' || isInstalling;

	// Pro renders a connection picker; the free version renders an informational notice.
	const privatePicker = resolved
		? applyFilters( 'gitwire.importUrl.privatePicker', null, {
				resolved,
				onPick: handleConnectAndContinue,
				onGoToSettings,
		  } )
		: null;

	return (
		<div className="gitwire-import-url">
			{ /* URL input row. */ }
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
							placeholder="https://github.com/owner/repository"
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

			{ /* Private or not-found state. */ }
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
										href="https://gitwire.app/pricing/"
										rel="noopener noreferrer"
										target="_blank"
									>
										{ __( 'Gitwire Pro', 'gitwire' ) }
									</a>{ ' ' }
									{ __(
										'supports private repositories.',
										'gitwire'
									) }
								</p>
							) }
						</div>
					</>
				) }

			{ /* Inline install form. */ }
			{ showInstallForm && resolved && (
				<div className="gitwire-import-url__install-form">
					{ autoDetectType && (
						<DetectionBadge
							detection={ detection }
							smartInstall={ smartInstall }
						/>
					) }

					{ askForType && (
						<div style={ { marginTop: 16 } }>
							<SelectControl
								__next40pxDefaultSize
								__nextHasNoMarginBottom
								disabled={ isInstalling }
								label={ __( 'Install As', 'gitwire' ) }
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

					{ ! blockedBySmartInstall && (
						<>
							<div style={ { marginTop: 16 } }>
								<ComboboxControl
									__next40pxDefaultSize
									__nextHasNoMarginBottom
									disabled={ isInstalling }
									label={ __( 'Branch', 'gitwire' ) }
									options={ branchOptions }
									value={ branch }
									onChange={ ( val ) =>
										val && setBranch( val )
									}
									onFilterValueChange={ setBranchFilter }
								/>
							</div>

							<div
								className={
									slugConflict
										? 'gitwire-input-error'
										: undefined
								}
								style={ { marginTop: 16 } }
							>
								<TextControl
									__nextHasNoMarginBottom
									disabled={ isInstalling }
									label={ __( 'Directory Name', 'gitwire' ) }
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
						</>
					) }

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
						{ ! blockedBySmartInstall && (
							<Button
								disabled={ ! canInstall || isInstalling }
								isBusy={ isInstalling || slugChecking }
								variant="primary"
								onClick={ handleInstall }
							>
								{ installButtonLabel(
									isInstalling,
									slugChecking
								) }
							</Button>
						) }
					</Flex>
				</div>
			) }
		</div>
	);
}
