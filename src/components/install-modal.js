import { toast } from 'sonner';

import { __, sprintf } from '@wordpress/i18n';
import { useState, useEffect, useMemo } from '@wordpress/element';
import {
	Button,
	ComboboxControl,
	Flex,
	Modal,
	Notice,
	SelectControl,
	Spinner,
} from '@wordpress/components';

import * as api from '../api';

/**
 * Install modal — lets the user choose a branch and confirms the install.
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
	const [ installing, setInstalling ] = useState( false );
	const [ countdown, setCountdown ] = useState( null );

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

	const canInstall =
		detection && ( detection.type !== 'unknown' || ! smartInstall );

	const handleInstall = async () => {
		setInstalling( true );
		try {
			const result = await api.install( {
				owner: repo.owner,
				repo: repo.name,
				branch,
				type: detection?.type !== 'unknown' ? detection.type : type,
				provider,
			} );
			onInstalled( result );
			startCountdown();
		} catch ( e ) {
			toast.error( e.message || __( 'Installation failed.', 'git' ) );
			setInstalling( false );
		}
	};

	const startCountdown = () => {
		let secs = 3;
		setCountdown( secs );
		const tick = () => {
			secs--;
			if ( secs <= 0 ) {
				sessionStorage.setItem( 'gwp_goto_tab', 'installed' );
				window.location.reload();
				return;
			}
			setCountdown( secs );
			setTimeout( tick, 1000 );
		};
		setTimeout( tick, 1000 );
	};

	return (
		<Modal
			shouldCloseOnClickOutside={ ! installing }
			shouldCloseOnEsc={ ! installing }
			style={ { maxWidth: 480 } }
			title={ sprintf(
				/* translators: %s: repository full name */
				__( 'Install %s', 'git' ),
				repo.full_name
			) }
			onRequestClose={ installing ? undefined : onClose }
		>
			<DetectionBadge
				detection={ detection }
				smartInstall={ smartInstall }
			/>

			{ detection && detection.type === 'unknown' && ! smartInstall && (
				<SelectControl
					__nextHasNoMarginBottom
					label={ __( 'Install as', 'git' ) }
					options={ [
						{ label: __( 'Plugin', 'git' ), value: 'plugin' },
						{ label: __( 'Theme', 'git' ), value: 'theme' },
					] }
					style={ { marginTop: 16 } }
					value={ type }
					onChange={ setType }
				/>
			) }

			<div style={ { marginTop: 16 } }>
				<ComboboxControl
					__nextHasNoMarginBottom
					disabled={ installing }
					label={ __( 'Branch', 'git' ) }
					options={ branchOptions }
					value={ branch }
					onChange={ ( val ) => val && setBranch( val ) }
					onFilterValueChange={ setBranchFilter }
				/>
			</div>

			{ countdown !== null && (
				<Notice
					isDismissible={ false }
					status="success"
					style={ { marginTop: 12 } }
				>
					{ sprintf(
						/* translators: %d: seconds remaining */
						__(
							'Installation complete — opening Installed tab in %d',
							'git'
						),
						countdown
					) }
				</Notice>
			) }

			<Flex gap={ 3 } justify="flex-end" style={ { marginTop: 20 } }>
				{ ! installing && countdown === null && (
					<Button variant="tertiary" onClick={ onClose }>
						{ __( 'Cancel', 'git' ) }
					</Button>
				) }
				<Button
					disabled={
						! canInstall || installing || countdown !== null
					}
					isBusy={ installing }
					variant="primary"
					onClick={ handleInstall }
				>
					{ installing
						? __( 'Installing…', 'git' )
						: __( 'Install', 'git' ) }
				</Button>
			</Flex>
		</Modal>
	);
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
			<div className="gwp-detect-row gwp-detect-loading">
				<Spinner /> { __( 'Detecting project type…', 'git' ) }
			</div>
		);
	}

	const { type, subtype, confidence, name } = detection;
	let badgeClass, label;

	if ( type === 'plugin' ) {
		badgeClass = 'gwp-detect-plugin';
		label =
			confidence === 'high'
				? sprintf(
						/* translators: %s: plugin name */
						__( 'WordPress Plugin%s', 'git' ),
						name ? ` — ${ name }` : ''
				  )
				: __( 'Likely a WordPress Plugin', 'git' );
	} else if ( type === 'theme' && subtype === 'block' ) {
		badgeClass = 'gwp-detect-theme';
		label =
			confidence === 'high'
				? sprintf(
						/* translators: %s: theme name */
						__( 'Block Theme%s', 'git' ),
						name ? ` — ${ name }` : ''
				  )
				: __( 'Likely a Block Theme', 'git' );
	} else if ( type === 'theme' ) {
		badgeClass = 'gwp-detect-theme';
		label =
			confidence === 'high'
				? sprintf(
						/* translators: %s: theme name */
						__( 'Classic Theme%s', 'git' ),
						name ? ` — ${ name }` : ''
				  )
				: __( 'Likely a Classic Theme', 'git' );
	} else {
		badgeClass = 'gwp-detect-unknown';
		label = __( 'Not recognised as a WordPress project', 'git' );
	}

	return (
		<div className="gwp-detect-row">
			<span className={ `gwp-detect-badge ${ badgeClass }` }>
				{ label }
			</span>
			{ type === 'unknown' && smartInstall && (
				<p className="gwp-detect-note gwp-detect-blocked">
					{ __(
						'Smart Install is enabled — only verified plugins and themes can be installed. Disable it in Settings to override.',
						'git'
					) }
				</p>
			) }
			{ type === 'unknown' && ! smartInstall && (
				<p className="gwp-detect-note gwp-detect-warn">
					{ __(
						'This repo was not recognised as a WordPress plugin or theme. You can still install it — choose a type below.',
						'git'
					) }
				</p>
			) }
		</div>
	);
}
