import { useState, useEffect } from '@wordpress/element';
import {
	Button,
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
 * @param {Object}   props              Component props.
 * @param {Object}   props.repo         Repository data object.
 * @param {boolean}  props.smartInstall Whether smart install is enabled.
 * @param {Function} props.onClose      Callback fired when the modal is closed.
 * @param {Function} props.onInstalled  Callback fired after a successful install.
 * @return {JSX.Element} The rendered install modal.
 */
export default function InstallModal( {
	repo,
	smartInstall,
	onClose,
	onInstalled,
} ) {
	const [ branches, setBranches ] = useState( [] );
	const [ branch, setBranch ] = useState( repo.default_branch || 'main' );
	const [ detection, setDetection ] = useState( null );
	const [ type, setType ] = useState( 'plugin' );
	const [ installing, setInstalling ] = useState( false );
	const [ notice, setNotice ] = useState( null );
	const [ countdown, setCountdown ] = useState( null );

	// Fetch branches and detect repo type in parallel on open.
	useEffect( () => {
		api.getBranches( repo.owner, repo.name )
			.then( ( b ) =>
				setBranches( b.map( ( n ) => ( { label: n, value: n } ) ) )
			)
			.catch( () => {} );

		api.detectRepo( repo.owner, repo.name, repo.default_branch )
			.then( ( d ) => {
				setDetection( d );
				if ( d.type === 'plugin' || d.type === 'theme' ) {
					setType( d.type );
				}
			} )
			.catch( () =>
				setDetection( { type: 'unknown', confidence: 'none' } )
			);
	}, [] ); // eslint-disable-line react-hooks/exhaustive-deps

	const canInstall =
		detection && ( detection.type !== 'unknown' || ! smartInstall );

	const handleInstall = async () => {
		setInstalling( true );
		setNotice( null );
		try {
			const result = await api.install( {
				owner: repo.owner,
				repo: repo.name,
				branch,
				type: detection?.type !== 'unknown' ? detection.type : type,
			} );
			onInstalled( result );
			startCountdown();
		} catch ( e ) {
			setNotice( {
				status: 'error',
				message: e.message || 'Installation failed.',
			} );
			setInstalling( false );
		}
	};

	const startCountdown = () => {
		let secs = 3;
		setCountdown( secs );
		const tick = () => {
			secs--;
			if ( secs <= 0 ) {
				sessionStorage.setItem( 'ghwp_goto_tab', 'installed' );
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
			title={ `Install ${ repo.full_name }` }
			onRequestClose={ installing ? undefined : onClose }
		>
			<DetectionBadge
				detection={ detection }
				smartInstall={ smartInstall }
			/>

			{ detection && detection.type === 'unknown' && ! smartInstall && (
				<SelectControl
					__nextHasNoMarginBottom
					label="Install as"
					options={ [
						{ label: 'Plugin', value: 'plugin' },
						{ label: 'Theme', value: 'theme' },
					] }
					style={ { marginTop: 16 } }
					value={ type }
					onChange={ setType }
				/>
			) }

			<div style={ { marginTop: 16 } }>
				<SelectControl
					__nextHasNoMarginBottom
					disabled={ installing }
					label="Branch"
					options={
						branches.length
							? branches
							: [
									{
										label: repo.default_branch || 'main',
										value: repo.default_branch || 'main',
									},
							  ]
					}
					value={ branch }
					onChange={ setBranch }
				/>
			</div>

			{ notice && (
				<div style={ { marginTop: 12 } }>
					<Notice isDismissible={ false } status={ notice.status }>
						{ notice.message }
					</Notice>
				</div>
			) }

			{ countdown !== null && (
				<Notice
					isDismissible={ false }
					status="success"
					style={ { marginTop: 12 } }
				>
					Installation complete — opening Installed tab in{ ' ' }
					<strong>{ countdown }</strong>
				</Notice>
			) }

			<Flex gap={ 3 } style={ { marginTop: 20 } }>
				<Button
					disabled={
						! canInstall || installing || countdown !== null
					}
					isBusy={ installing }
					variant="primary"
					onClick={ handleInstall }
				>
					{ installing ? 'Installing…' : 'Install' }
				</Button>
				{ ! installing && countdown === null && (
					<Button variant="tertiary" onClick={ onClose }>
						Cancel
					</Button>
				) }
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
			<div className="ghwp-detect-row ghwp-detect-loading">
				<Spinner /> Detecting project type…
			</div>
		);
	}

	const { type, subtype, confidence, name } = detection;
	let badgeClass, label;

	if ( type === 'plugin' ) {
		badgeClass = 'ghwp-detect-plugin';
		label =
			confidence === 'high'
				? `WordPress Plugin${ name ? ` — ${ name }` : '' }`
				: 'Likely a WordPress Plugin';
	} else if ( type === 'theme' && subtype === 'block' ) {
		badgeClass = 'ghwp-detect-theme';
		label =
			confidence === 'high'
				? `Block Theme${ name ? ` — ${ name }` : '' }`
				: 'Likely a Block Theme';
	} else if ( type === 'theme' ) {
		badgeClass = 'ghwp-detect-theme';
		label =
			confidence === 'high'
				? `Classic Theme${ name ? ` — ${ name }` : '' }`
				: 'Likely a Classic Theme';
	} else {
		badgeClass = 'ghwp-detect-unknown';
		label = 'Not recognised as a WordPress project';
	}

	return (
		<div className="ghwp-detect-row">
			<span className={ `ghwp-detect-badge ${ badgeClass }` }>
				{ label }
			</span>
			{ type === 'unknown' && smartInstall && (
				<p className="ghwp-detect-note ghwp-detect-blocked">
					Smart Install is enabled — only verified plugins and themes
					can be installed. Disable it in Settings to override.
				</p>
			) }
			{ type === 'unknown' && ! smartInstall && (
				<p className="ghwp-detect-note ghwp-detect-warn">
					This repo was not recognised as a WordPress plugin or theme.
					You can still install it — choose a type below.
				</p>
			) }
		</div>
	);
}
