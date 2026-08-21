import { toast } from '../toast';

import { __ } from '@wordpress/i18n';
import { useState, useEffect, useRef, useMemo } from '@wordpress/element';
import {
	Button,
	CheckboxControl,
	ComboboxControl,
	Flex,
	Modal,
	SelectControl,
	TextControl,
} from '@wordpress/components';

import * as api from '../api';
import { normalizeSlug, finalizeSlug } from '../slug';
import DetectionBadge from './detection-badge';

/**
 * Inline install form — branch picker, slug, detection badge, Install button.
 * Renders without any Modal wrapper so it can be embedded inside a parent modal.
 *
 * @param {Object}      props                      Component props.
 * @param {Object}      props.repo                 Repository data object.
 * @param {string}      props.provider             Git provider.
 * @param {string}      [props.connectionId]       Connection ID used to fetch this repo.
 * @param {boolean}     props.smartInstall         Whether smart install is enabled.
 * @param {boolean}     [props.autoDetectType]     Whether type detection runs at all.
 * @param {Object|null} props.detection            Pre-fetched detection result, if any.
 * @param {Function}    props.onInstalled          Callback fired after a successful install.
 * @param {Function}    [props.onBack]             Optional cancel/back button callback.
 * @param {string}      [props.backLabel]          Label for the back button (default: Cancel).
 * @param {Function}    [props.onInstallingChange] Called with true/false as install runs.
 * @return {JSX.Element} The rendered install form.
 */
export function InstallForm( {
	repo,
	provider = 'github',
	connectionId = '',
	smartInstall,
	autoDetectType = true,
	detection: initialDetection = null,
	onInstalled,
	onBack,
	backLabel,
	onInstallingChange,
} ) {
	const [ allBranches, setAllBranches ] = useState( [] );
	const [ branch, setBranch ] = useState( repo.default_branch || 'main' );
	const [ branchFilter, setBranchFilter ] = useState( '' );
	const [ detection, setDetection ] = useState( initialDetection );
	const [ type, setType ] = useState(
		[ 'plugin', 'theme', 'block-theme', 'classic-theme' ].includes(
			initialDetection?.type
		)
			? initialDetection.type
			: 'plugin'
	);
	const [ slug, setSlug ] = useState( normalizeSlug( repo.name ) );
	const [ slugConflict, setSlugConflict ] = useState( false );
	const [ slugChecking, setSlugChecking ] = useState( true );
	const [ replace, setReplace ] = useState( false );
	const [ installing, setInstalling ] = useState( false );
	const debounceRef = useRef( null );

	/*
	 * Either detection is off, so the choice was always the installer's, or it ran and
	 * came back unrecognised with nothing left to enforce.
	 */
	const askForType =
		! autoDetectType || ( 'unknown' === detection?.type && ! smartInstall );

	// Nothing here can be installed, so the fields deciding how are noise.
	const blockedBySmartInstall = smartInstall && 'unknown' === detection?.type;

	const setInstallingState = ( val ) => {
		setInstalling( val );
		onInstallingChange?.( val );
	};

	const branchOptions = useMemo( () => {
		const filter = branchFilter.toLowerCase();
		const defaultBranch = repo.default_branch || 'main';
		const source = allBranches.length ? allBranches : [ defaultBranch ];
		const filtered = filter
			? source.filter( ( b ) => b.toLowerCase().includes( filter ) )
			: source;
		const top = filtered.slice( 0, 10 );
		if ( ! filter && branch && ! top.includes( branch ) ) {
			top.unshift( branch );
			top.splice( 10 );
		}
		return top.map( ( b ) => ( { label: b, value: b } ) );
	}, [ allBranches, branchFilter, branch, repo.default_branch ] );

	useEffect( () => {
		// A detection handed in already blocked means no branch will ever be picked.
		if ( ! blockedBySmartInstall ) {
			api.getBranches( repo.owner, repo.name, provider, connectionId )
				.then( ( b ) => setAllBranches( b ) )
				.catch( () => {} );
		}

		// With detection off the type is the installer's to choose, so asking a provider
		// for one would spend a call on an answer that gets ignored.
		if ( ! initialDetection && autoDetectType ) {
			api.detectRepo(
				repo.owner,
				repo.name,
				repo.default_branch,
				provider,
				connectionId
			)
				.then( ( d ) => {
					setDetection( d );
					if (
						[
							'plugin',
							'theme',
							'block-theme',
							'classic-theme',
						].includes( d.type )
					) {
						setType( d.type );
					}
				} )
				.catch( () =>
					setDetection( { type: 'unknown', confidence: 'none' } )
				);
		}
	}, [] ); // eslint-disable-line react-hooks/exhaustive-deps

	// Debounced slug conflict check.
	useEffect( () => {
		setSlugChecking( true );
		setReplace( false );
		clearTimeout( debounceRef.current );
		if ( ! slug || blockedBySmartInstall ) {
			setSlugConflict( false );
			setSlugChecking( false );
			return;
		}
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
	}, [ slug, type, blockedBySmartInstall ] );

	// Nothing resolves the detection when it is switched off, so waiting on one here
	// would leave the Install button disabled for good.
	const detectionSettled = ! autoDetectType || !! detection;

	const canInstall =
		!! slug &&
		! slugChecking &&
		detectionSettled &&
		( ! detection || detection.type !== 'unknown' || ! smartInstall ) &&
		( ! slugConflict || replace );

	const handleInstall = async () => {
		setInstallingState( true );
		try {
			const installType =
				detection && 'unknown' !== detection.type
					? detection.type
					: type;
			const finalSlug = finalizeSlug( slug );

			const check = await api.checkSlug( finalSlug, installType );
			if ( check.conflict && ! replace ) {
				setSlugConflict( true );
				setInstallingState( false );
				return;
			}

			const result = await api.install( {
				owner: repo.owner,
				repo: repo.name,
				branch,
				type: installType,
				provider,
				slug: finalSlug,
				replace,
				...( connectionId ? { connection_id: connectionId } : {} ),
			} );
			onInstalled( result );
		} catch ( e ) {
			toast.error( e.message || __( 'Installation failed.', 'gitwire' ) );
			setInstallingState( false );
		}
	};

	return (
		<>
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
						disabled={ installing }
						label={ __( 'Install As', 'gitwire' ) }
						options={ [
							{
								label: __( 'Plugin', 'gitwire' ),
								value: 'plugin',
							},
							{ label: __( 'Theme', 'gitwire' ), value: 'theme' },
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
							disabled={ installing }
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
							disabled={ installing }
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

			<Flex gap={ 3 } justify="flex-end" style={ { marginTop: 20 } }>
				{ ! installing && onBack && (
					<Button variant="tertiary" onClick={ onBack }>
						{ backLabel || __( 'Cancel', 'gitwire' ) }
					</Button>
				) }
				{ ! blockedBySmartInstall && (
					<Button
						disabled={ ! canInstall || installing }
						isBusy={ installing || slugChecking }
						variant="primary"
						onClick={ handleInstall }
					>
						<InstallButtonLabel
							installing={ installing }
							slugChecking={ slugChecking }
						/>
					</Button>
				) }
			</Flex>
		</>
	);
}

/**
 * Install modal — wraps InstallForm in a WordPress Modal.
 *
 * @param {Object}      props                  Component props.
 * @param {Object}      props.repo             Repository data object.
 * @param {boolean}     props.smartInstall     Whether smart install is enabled.
 * @param {boolean}     [props.autoDetectType] Whether type detection runs at all.
 * @param {string}      [props.connectionId]   Connection ID used to fetch this repo.
 * @param {Function}    props.onClose          Callback fired when the modal is closed.
 * @param {Function}    props.onInstalled      Callback fired after a successful install.
 * @param {string}      props.provider         Git provider: 'github' or 'gitlab'.
 * @param {Object|null} props.detection        Pre-fetched detection result, if any.
 * @return {JSX.Element} The rendered install modal.
 */
export default function InstallModal( {
	repo,
	provider = 'github',
	connectionId = '',
	smartInstall,
	autoDetectType = true,
	onClose,
	onInstalled,
	detection = null,
} ) {
	const [ installing, setInstalling ] = useState( false );

	return (
		<Modal
			className="gitwire-modal"
			shouldCloseOnClickOutside={ ! installing }
			shouldCloseOnEsc={ ! installing }
			style={ { width: 480 } }
			title={
				<span className="gitwire-modal__title">
					{ __( 'Install', 'gitwire' ) }{ ' ' }
					<span style={ { color: 'var(--gitwire-color-accent)' } }>
						{ repo.full_name }
					</span>
				</span>
			}
			onRequestClose={ installing ? undefined : onClose }
		>
			<InstallForm
				autoDetectType={ autoDetectType }
				connectionId={ connectionId }
				detection={ detection }
				provider={ provider }
				repo={ repo }
				smartInstall={ smartInstall }
				onBack={ onClose }
				onInstalled={ onInstalled }
				onInstallingChange={ setInstalling }
			/>
		</Modal>
	);
}

/**
 * @param {Object}  props              Component props.
 * @param {boolean} props.installing   Whether an install request is in flight.
 * @param {boolean} props.slugChecking Whether the directory slug is being validated.
 * @return {string} Translated button label.
 */
function InstallButtonLabel( { installing, slugChecking } ) {
	if ( installing ) {
		return __( 'Installing…', 'gitwire' );
	}
	if ( slugChecking ) {
		return __( 'Checking…', 'gitwire' );
	}
	return __( 'Install', 'gitwire' );
}
