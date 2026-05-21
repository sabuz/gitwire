import { useState, useEffect } from '@wordpress/element';
import {
	Button,
	Card,
	CardBody,
	Flex,
	FlexBlock,
	FlexItem,
	Notice,
	SelectControl,
	Spinner,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalConfirmDialog as ConfirmDialog,
} from '@wordpress/components';

import * as api from '../api';

/**
 * Installed panel — lists repositories installed from GitHub.
 *
 * @param {Object}   props                Component props.
 * @param {Object}   props.installed      Map of installed repository records.
 * @param {Object}   props.settings       Plugin settings.
 * @param {Function} props.onRefresh      Callback to refresh the installed list.
 * @param {Function} props.onGoToSettings Callback to navigate to the Settings tab.
 * @param {Function} props.onGoToBrowse   Callback to navigate to the Browse tab.
 * @return {JSX.Element} The rendered installed panel.
 */
export default function InstalledPanel( {
	installed,
	settings,
	onRefresh,
	onGoToSettings,
	onGoToBrowse,
} ) {
	const entries = Object.values( installed );
	const isConfigured = !! settings?.username;

	if ( entries.length === 0 ) {
		return (
			<Card>
				<CardBody
					style={ {
						textAlign: 'center',
						padding: '48px 24px',
						color: '#8c959f',
					} }
				>
					<span
						className="dashicons dashicons-randomize"
						style={ {
							fontSize: 36,
							display: 'block',
							margin: '0 auto 12px',
							opacity: 0.3,
						} }
					/>
					{ isConfigured ? (
						<>
							<p style={ { margin: '0 0 12px' } }>
								No repositories installed yet.
							</p>
							<Button variant="primary" onClick={ onGoToBrowse }>
								Browse GitHub to install one
							</Button>
						</>
					) : (
						<>
							<p style={ { margin: '0 0 12px' } }>
								Connect your GitHub account to get started.
							</p>
							<Button
								variant="primary"
								onClick={ onGoToSettings }
							>
								Set up GitHub connection
							</Button>
						</>
					) }
				</CardBody>
			</Card>
		);
	}

	return (
		<div className="ghwp-installed-list">
			{ entries.map( ( record ) => (
				<InstalledRow
					key={ record.full_name }
					record={ record }
					onRefresh={ onRefresh }
				/>
			) ) }
		</div>
	);
}

/**
 * Single row in the installed list with branch switcher and action buttons.
 *
 * @param {Object}   props           Component props.
 * @param {Object}   props.record    Installed repository record.
 * @param {Function} props.onRefresh Callback to refresh the installed list.
 * @return {JSX.Element} The rendered installed row.
 */
function InstalledRow( { record, onRefresh } ) {
	const { full_name, owner, repo, type, branch } = record;

	const [ activeBranch, setActiveBranch ] = useState( branch );
	const [ branches, setBranches ] = useState( null );
	const [ switching, setSwitching ] = useState( false );
	const [ updating, setUpdating ] = useState( false );
	const [ removing, setRemoving ] = useState( false );
	const [ notice, setNotice ] = useState( null );
	const [ confirmOpen, setConfirmOpen ] = useState( false );

	useEffect( () => {
		api.getBranches( owner, repo )
			.then( ( b ) => setBranches( b ) )
			.catch( () => setBranches( [] ) );
	}, [] ); // eslint-disable-line react-hooks/exhaustive-deps

	const branchOptions = branches
		? branches.map( ( b ) => ( { label: b, value: b } ) )
		: [ { label: activeBranch, value: activeBranch } ];

	const handleSwitch = async ( newBranch ) => {
		setActiveBranch( newBranch );
		if ( newBranch === activeBranch ) {
			return;
		}
		setSwitching( true );
		setNotice( null );
		try {
			await api.switchBranch( owner, repo, newBranch );
			setNotice( {
				status: 'success',
				message: `Switched to ${ newBranch }.`,
			} );
			onRefresh();
		} catch ( e ) {
			setNotice( {
				status: 'error',
				message: e.message || 'Branch switch failed.',
			} );
			setActiveBranch( branch );
		} finally {
			setSwitching( false );
		}
	};

	const handleUpdate = async () => {
		setUpdating( true );
		setNotice( null );
		try {
			await api.switchBranch( owner, repo, activeBranch );
			setNotice( {
				status: 'success',
				message: 'Updated to latest commit.',
			} );
			onRefresh();
		} catch ( e ) {
			setNotice( {
				status: 'error',
				message: e.message || 'Update failed.',
			} );
		} finally {
			setUpdating( false );
		}
	};

	const handleRemove = async () => {
		setConfirmOpen( false );
		setRemoving( true );
		setNotice( null );
		try {
			await api.removeInstalled( owner, repo );
			onRefresh();
		} catch ( e ) {
			setNotice( {
				status: 'error',
				message: e.message || 'Remove failed.',
			} );
			setRemoving( false );
		}
	};

	const busy = switching || updating || removing;

	return (
		<Card className="ghwp-installed-row">
			<CardBody>
				<Flex align="flex-start" gap={ 4 } wrap>
					<FlexBlock style={ { minWidth: 220 } }>
						<div className="ghwp-installed-name">{ full_name }</div>
						<div style={ { marginTop: 4 } }>
							<span
								className={ `ghwp-type-badge ${
									type === 'theme'
										? 'ghwp-type-theme'
										: 'ghwp-type-plugin'
								}` }
							>
								{ type === 'theme' ? 'Theme' : 'Plugin' }
							</span>
						</div>
					</FlexBlock>

					<FlexItem style={ { minWidth: 180 } }>
						<SelectControl
							__nextHasNoMarginBottom
							disabled={ busy || branchOptions.length <= 1 }
							label="Branch"
							options={ branchOptions }
							value={ activeBranch }
							onChange={ handleSwitch }
						/>
					</FlexItem>

					<FlexItem>
						<Flex gap={ 2 } style={ { marginTop: 22 } }>
							<Button
								disabled={ busy }
								isBusy={ updating }
								size="small"
								variant="secondary"
								onClick={ handleUpdate }
							>
								{ updating ? 'Updating…' : 'Pull latest' }
							</Button>
							<Button
								disabled={ busy }
								isBusy={ removing }
								isDestructive
								size="small"
								variant="tertiary"
								onClick={ () => setConfirmOpen( true ) }
							>
								Remove
							</Button>
						</Flex>
					</FlexItem>
				</Flex>

				{ notice && (
					<div style={ { marginTop: 10 } }>
						<Notice
							isDismissible
							status={ notice.status }
							onRemove={ () => setNotice( null ) }
						>
							{ notice.message }
						</Notice>
					</div>
				) }

				{ ( switching || removing ) && (
					<div
						style={ {
							marginTop: 8,
							display: 'flex',
							alignItems: 'center',
							gap: 6,
							color: '#57606a',
							fontSize: 13,
						} }
					>
						<Spinner />
						{ switching ? 'Switching branch…' : 'Removing…' }
					</div>
				) }
			</CardBody>

			{ confirmOpen && (
				<ConfirmDialog
					onCancel={ () => setConfirmOpen( false ) }
					onConfirm={ handleRemove }
				>
					Remove <strong>{ full_name }</strong> from WordPress? The
					files will be deleted.
				</ConfirmDialog>
			) }
		</Card>
	);
}
