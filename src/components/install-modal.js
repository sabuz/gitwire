import { toast } from '../toast';

import { __, sprintf } from '@wordpress/i18n';
import { useState, useEffect, useRef, useMemo } from '@wordpress/element';
import {
	Button,
	CheckboxControl,
	ComboboxControl,
	Flex,
	Modal,
	SelectControl,
	Spinner,
	TextControl,
} from '@wordpress/components';

import * as api from '../api';

/**
 * Normalizes a string into a safe directory slug matching WordPress conventions:
 * lowercase, only a-z / 0-9 / hyphens / underscores, no consecutive separators,
 * no leading or trailing separator.
 *
 * Both `-` and `_` are kept - WordPress plugins/themes use both. Any unsafe char
 * (including spaces) is replaced with `-`. Mixed runs like `_-` collapse to `-`.
 *
 * @param {string} value Raw input.
 * @return {string} Normalized slug.
 */
function normalizeSlug( value ) {
	return value
		.toLowerCase()
		.replace( /[^a-z0-9_-]+/g, '-' ) // unsafe chars → hyphen
		.replace( /[-_]*-[-_]*/g, '-' ) // any run containing a hyphen → single hyphen
		.replace( /__+/g, '_' ); // consecutive underscores → single underscore
	// No leading/trailing trim here - trimming while typing blocks adding separators at the end.
}

function finalizeSlug( value ) {
	return normalizeSlug( value ).replace( /^[-_]+|[-_]+$/g, '' );
}

/**
 * Install modal - lets the user choose a branch and confirms the install.
 *
 * @param {Object}      props              Component props.
 * @param {Object}      props.repo         Repository data object.
 * @param {boolean}     props.smartInstall Whether smart install is enabled.
 * @param {Function}    props.onClose      Callback fired when the modal is closed.
 * @param {Function}    props.onInstalled  Callback fired after a successful install.
 * @param {string}      props.provider     Git provider: 'github' or 'gitlab'.
 * @param {Object|null} props.detection    Pre-fetched detection result, if any.
 * @return {JSX.Element} The rendered install modal.
 */
export default function InstallModal( {
	repo,
	provider = 'github',
	smartInstall,
	onClose,
	onInstalled,
	detection: initialDetection = null,
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

	// Filter all fetched branches by the current search term, show at most 10.
	// Always keep the selected branch visible when no search is active.
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
		api.getBranches( repo.owner, repo.name, provider )
			.then( ( b ) => setAllBranches( b ) )
			.catch( () => {} );

		if ( ! initialDetection ) {
			api.detectRepo(
				repo.owner,
				repo.name,
				repo.default_branch,
				provider
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
			api.checkSlug(
				finalizeSlug( slug ),
				type,
				repo.owner,
				repo.name,
				provider
			)
				.then( ( r ) => {
					if ( cancelled ) {
						return;
					}
					setSlugConflict( r.conflict );
					setSlugChecking( false );
				} )
				.catch( () => {
					if ( cancelled ) {
						return;
					}
					setSlugConflict( false );
					setSlugChecking( false );
				} );
		}, 400 );
		return () => {
			cancelled = true;
			clearTimeout( debounceRef.current );
		};
	}, [ slug, type ] ); // eslint-disable-line react-hooks/exhaustive-deps

	const canInstall =
		!! slug &&
		! slugChecking &&
		detection &&
		( detection.type !== 'unknown' || ! smartInstall ) &&
		( ! slugConflict || replace );

	const handleInstall = async () => {
		setInstalling( true );
		try {
			// Re-check conflict at install time to guard against stale debounce state.
			const installType =
				detection?.type !== 'unknown' ? detection.type : type;
			const finalSlug = finalizeSlug( slug );

			const check = await api.checkSlug(
				finalSlug,
				installType,
				repo.owner,
				repo.name,
				provider
			);
			if ( check.conflict && ! replace ) {
				setSlugConflict( true );
				setInstalling( false );
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
			} );
			onInstalled( result );
		} catch ( e ) {
			toast.error( e.message || __( 'Installation failed.', 'gitwire' ) );
			setInstalling( false );
		}
	};

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
			<DetectionBadge
				detection={ detection }
				smartInstall={ smartInstall }
			/>

			{ detection && detection.type === 'unknown' && ! smartInstall && (
				<div style={ { marginTop: 16 } }>
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						disabled={ installing }
						label={ __( 'Install as', 'gitwire' ) }
						options={ [
							{ label: __( 'Plugin', 'gitwire' ), value: 'plugin' },
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
					label={ __( 'Directory name', 'gitwire' ) }
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
				{ ! installing && (
					<Button variant="tertiary" onClick={ onClose }>
						{ __( 'Cancel', 'gitwire' ) }
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
		</Modal>
	);
}

/**
 * Install modal primary button label.
 *
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

/**
 * Detection result badge shown inside the install modal.
 *
 * @param {Object}      props              Component props.
 * @param {Object|null} props.detection    Type detection result.
 * @param {boolean}     props.smartInstall Whether smart install is enabled.
 * @return {JSX.Element} The rendered detection badge.
 */
function DetectionBadge( { detection, smartInstall } ) {
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
						name ? ` - ${ name }` : ''
				  )
				: __( 'Likely a WordPress Plugin', 'gitwire' );
	} else if ( type === 'theme' && subtype === 'block' ) {
		badgeClass = 'gitwire-detect-theme';
		label =
			confidence === 'high'
				? sprintf(
						/* translators: %s: theme name */
						__( 'Block Theme%s', 'gitwire' ),
						name ? ` - ${ name }` : ''
				  )
				: __( 'Likely a Block Theme', 'gitwire' );
	} else if ( type === 'theme' ) {
		badgeClass = 'gitwire-detect-theme';
		label =
			confidence === 'high'
				? sprintf(
						/* translators: %s: theme name */
						__( 'Classic Theme%s', 'gitwire' ),
						name ? ` - ${ name }` : ''
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
						'Smart Install is enabled - only verified plugins and themes can be installed. Disable it in Settings to override.',
						'gitwire'
					) }
				</p>
			) }
			{ type === 'unknown' && ! smartInstall && (
				<p className="gitwire-detect-note gitwire-detect-warn">
					{ __(
						'This repo was not recognised as a WordPress plugin or theme. You can still install it - choose a type below.',
						'gitwire'
					) }
				</p>
			) }
		</div>
	);
}
