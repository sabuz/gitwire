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
		initialDetection?.type === 'plugin' ||
			initialDetection?.type === 'theme'
			? initialDetection.type
			: 'plugin'
	);
	const [ slug, setSlug ] = useState( normalizeSlug( repo.name ) );
	const [ slugConflict, setSlugConflict ] = useState( false );
	const [ slugChecking, setSlugChecking ] = useState( true );
	const [ replace, setReplace ] = useState( false );
	const [ installing, setInstalling ] = useState( false );
	const debounceRef = useRef( null );

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
		api.getBranches( repo.owner, repo.name, provider, connectionId )
			.then( ( b ) => setAllBranches( b ) )
			.catch( () => {} );

		if ( ! initialDetection ) {
			api.detectRepo(
				repo.owner,
				repo.name,
				repo.default_branch,
				provider,
				connectionId
			)
				.then( ( d ) => {
					setDetection( d );
					if ( d.type === 'plugin' || d.type === 'theme' ) {
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
		if ( ! slug ) {
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
	}, [ slug, type ] );

	const canInstall =
		!! slug &&
		! slugChecking &&
		detection &&
		( detection.type !== 'unknown' || ! smartInstall ) &&
		( ! slugConflict || replace );

	const handleInstall = async () => {
		setInstallingState( true );
		try {
			const installType =
				detection?.type !== 'unknown' ? detection.type : type;
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
			<DetectionBadge
				detection={ detection }
				smartInstall={ smartInstall }
			/>

			{ detection?.type === 'unknown' && ! smartInstall && (
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
				className={ slugConflict ? 'gitwire-input-error' : undefined }
				style={ { marginTop: 16 } }
			>
				<TextControl
					__nextHasNoMarginBottom
					disabled={ installing }
					label={ __( 'Directory Name', 'gitwire' ) }
					value={ slug }
					onChange={ ( val ) => setSlug( normalizeSlug( val ) ) }
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

			<Flex gap={ 3 } justify="flex-end" style={ { marginTop: 20 } }>
				{ ! installing && onBack && (
					<Button variant="tertiary" onClick={ onBack }>
						{ backLabel || __( 'Cancel', 'gitwire' ) }
					</Button>
				) }
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
			</Flex>
		</>
	);
}

/**
 * Install modal — wraps InstallForm in a WordPress Modal.
 *
 * @param {Object}      props                Component props.
 * @param {Object}      props.repo           Repository data object.
 * @param {boolean}     props.smartInstall   Whether smart install is enabled.
 * @param {string}      [props.connectionId] Connection ID used to fetch this repo.
 * @param {Function}    props.onClose        Callback fired when the modal is closed.
 * @param {Function}    props.onInstalled    Callback fired after a successful install.
 * @param {string}      props.provider       Git provider: 'github' or 'gitlab'.
 * @param {Object|null} props.detection      Pre-fetched detection result, if any.
 * @return {JSX.Element} The rendered install modal.
 */
export default function InstallModal( {
	repo,
	provider = 'github',
	connectionId = '',
	smartInstall,
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
