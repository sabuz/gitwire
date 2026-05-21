import { useState, useEffect, useMemo } from '@wordpress/element';
import {
	Button,
	Card,
	CardBody,
	ComboboxControl,
	Flex,
	Notice,
} from '@wordpress/components';
import { DataViews, filterSortAndPaginate } from '@wordpress/dataviews';

import * as api from '../api';

const DEFAULT_VIEW = {
	type: 'table',
	search: '',
	page: 1,
	perPage: 10,
	fields: [ 'name', 'type', 'status', 'branch' ],
	sort: { field: 'name', direction: 'asc' },
};

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
	const [ view, setView ] = useState( DEFAULT_VIEW );
	const [ notice, setNotice ] = useState( null );

	const fields = useMemo(
		() => [
			{
				id: 'name',
				label: 'Repository',
				getValue: ( { item } ) => item.full_name,
				enableSorting: true,
				enableGlobalSearch: true,
			},
			{
				id: 'type',
				label: 'Type',
				getValue: ( { item } ) => item.type,
				render: ( { item } ) => (
					<span
						className={ `ghwp-type-badge ghwp-type-${ item.type }` }
					>
						{ item.type === 'theme' ? 'Theme' : 'Plugin' }
					</span>
				),
				enableSorting: true,
			},
			{
				id: 'status',
				label: 'Status',
				getValue: ( { item } ) =>
					item.active ? 'active' : 'inactive',
				render: ( { item } ) => (
					<span
						className={ `ghwp-status-badge ghwp-status-badge--${
							item.active ? 'active' : 'inactive'
						}` }
					>
						{ item.active ? 'Active' : 'Inactive' }
					</span>
				),
				enableSorting: true,
			},
			{
				id: 'branch',
				label: 'Branch',
				getValue: ( { item } ) => item.branch,
				enableSorting: true,
			},
		],
		[]
	);

	const actions = useMemo(
		() => [
			{
				id: 'pull_latest',
				isPrimary: true,
				label: 'Pull latest',
				callback: async ( [ item ] ) => {
					setNotice( null );
					try {
						await api.switchBranch(
							item.owner,
							item.repo,
							item.branch
						);
						setNotice( {
							status: 'success',
							message: `${ item.full_name }: updated to latest commit.`,
						} );
						onRefresh();
					} catch ( e ) {
						setNotice( {
							status: 'error',
							message: e.message || 'Update failed.',
						} );
					}
				},
			},
			{
				id: 'switch_branch',
				label: 'Switch branch',
				RenderModal: ( { items, closeModal } ) => (
					<BranchSwitcherModal
						item={ items[ 0 ] }
						onClose={ closeModal }
						onSwitched={ ( newBranch ) => {
							setNotice( {
								status: 'success',
								message: `Switched to ${ newBranch }.`,
							} );
							onRefresh();
						} }
						onError={ ( msg ) =>
							setNotice( { status: 'error', message: msg } )
						}
					/>
				),
			},
			{
				id: 'activate',
				label: 'Activate',
				isEligible: ( item ) => ! item.active,
				callback: async ( [ item ] ) => {
					setNotice( null );
					try {
						await api.activateInstalled( item.owner, item.repo );
						setNotice( {
							status: 'success',
							message: `${ item.full_name } activated.`,
						} );
						onRefresh();
					} catch ( e ) {
						setNotice( {
							status: 'error',
							message: e.message || 'Activation failed.',
						} );
					}
				},
			},
			{
				id: 'deactivate',
				label: 'Deactivate',
				isEligible: ( item ) => item.active && item.type === 'plugin',
				callback: async ( [ item ] ) => {
					setNotice( null );
					try {
						await api.deactivateInstalled( item.owner, item.repo );
						setNotice( {
							status: 'success',
							message: `${ item.full_name } deactivated.`,
						} );
						onRefresh();
					} catch ( e ) {
						setNotice( {
							status: 'error',
							message: e.message || 'Deactivation failed.',
						} );
					}
				},
			},
			{
				id: 'delete',
				isDestructive: true,
				isEligible: ( item ) => ! item.active,
				label: 'Delete',
				RenderModal: ( { items, closeModal } ) => (
					<DeleteConfirmModal
						item={ items[ 0 ] }
						closeModal={ closeModal }
						onDeleted={ () => {
							setNotice( {
								status: 'success',
								message: `${ items[ 0 ].full_name } deleted.`,
							} );
							onRefresh();
						} }
						onError={ ( msg ) =>
							setNotice( { status: 'error', message: msg } )
						}
					/>
				),
			},
		],
		[ onRefresh ]
	);

	const { data: shownData, paginationInfo } = useMemo(
		() => filterSortAndPaginate( entries, view, fields ),
		[ entries, view, fields ]
	);

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
		<div className="ghwp-installed-panel">
			{ notice && (
				<div style={ { marginBottom: 16 } }>
					<Notice
						isDismissible
						status={ notice.status }
						onRemove={ () => setNotice( null ) }
					>
						{ notice.message }
					</Notice>
				</div>
			) }
			<DataViews
				actions={ actions }
				data={ shownData }
				defaultLayouts={ { table: {} } }
				fields={ fields }
				getItemId={ ( item ) => item.full_name }
				paginationInfo={ paginationInfo }
				view={ view }
				onChangeView={ setView }
			/>
		</div>
	);
}

/**
 * Modal body for switching the active branch of an installed repository.
 *
 * @param {Object}   props            Component props.
 * @param {Object}   props.item       Installed repository record.
 * @param {Function} props.onClose    Callback to close the modal.
 * @param {Function} props.onSwitched Callback fired with the new branch name on success.
 * @param {Function} props.onError    Callback fired with an error message on failure.
 * @return {JSX.Element} The rendered branch switcher modal body.
 */
function BranchSwitcherModal( { item, onClose, onSwitched, onError } ) {
	const { owner, repo, branch } = item;
	const [ allBranches, setAllBranches ] = useState( [] );
	const [ branchFilter, setBranchFilter ] = useState( '' );
	const [ selectedBranch, setSelectedBranch ] = useState( branch );
	const [ switching, setSwitching ] = useState( false );

	useEffect( () => {
		api.getBranches( owner, repo )
			.then( setAllBranches )
			.catch( () => setAllBranches( [] ) );
	}, [] ); // eslint-disable-line react-hooks/exhaustive-deps

	const branchOptions = useMemo( () => {
		const filter = branchFilter.toLowerCase();
		const source = allBranches.length ? allBranches : [ branch ];
		const filtered = filter
			? source.filter( ( b ) => b.toLowerCase().includes( filter ) )
			: source;
		const top = filtered.slice( 0, 10 );
		if ( ! filter && selectedBranch && ! top.includes( selectedBranch ) ) {
			top.unshift( selectedBranch );
			top.splice( 10 );
		}
		return top.map( ( b ) => ( { label: b, value: b } ) );
	}, [ allBranches, branchFilter, selectedBranch, branch ] );

	const handleSwitch = async () => {
		if ( ! selectedBranch || selectedBranch === branch ) {
			onClose();
			return;
		}
		setSwitching( true );
		let errorMsg = null;
		try {
			await api.switchBranch( owner, repo, selectedBranch );
		} catch ( e ) {
			errorMsg = e.message || 'Branch switch failed.';
		}
		setSwitching( false );
		if ( errorMsg ) {
			onError( errorMsg );
		} else {
			onSwitched( selectedBranch );
		}
		onClose();
	};

	return (
		<>
			<ComboboxControl
				__nextHasNoMarginBottom
				label="Branch"
				options={ branchOptions }
				value={ selectedBranch }
				onChange={ ( val ) => val && setSelectedBranch( val ) }
				onFilterValueChange={ setBranchFilter }
			/>
			<Flex gap={ 3 } justify="flex-end" style={ { marginTop: 16 } }>
				<Button variant="tertiary" onClick={ onClose }>
					Cancel
				</Button>
				<Button
					isBusy={ switching }
					variant="primary"
					onClick={ handleSwitch }
				>
					{ switching ? 'Switching…' : 'Switch' }
				</Button>
			</Flex>
		</>
	);
}

/**
 * Modal body confirming permanent deletion of an installed repository.
 *
 * @param {Object}   props            Component props.
 * @param {Object}   props.item       Installed repository record.
 * @param {Function} props.closeModal Callback to close the modal.
 * @param {Function} props.onDeleted  Callback fired after successful deletion.
 * @param {Function} props.onError    Callback fired with an error message on failure.
 * @return {JSX.Element} The rendered delete confirmation modal body.
 */
function DeleteConfirmModal( { item, closeModal, onDeleted, onError } ) {
	const [ deleting, setDeleting ] = useState( false );

	const handleDelete = async () => {
		setDeleting( true );
		let errorMsg = null;
		try {
			await api.removeInstalled( item.owner, item.repo );
		} catch ( e ) {
			errorMsg = e.message || 'Delete failed.';
		}
		if ( errorMsg ) {
			setDeleting( false );
			onError( errorMsg );
		} else {
			onDeleted();
		}
		closeModal();
	};

	return (
		<>
			<p>
				Permanently delete <strong>{ item.full_name }</strong>? This
				will remove all files from the server and cannot be undone.
			</p>
			<Flex gap={ 3 } justify="flex-end">
				<Button variant="tertiary" onClick={ closeModal }>
					Cancel
				</Button>
				<Button
					isDestructive
					isBusy={ deleting }
					variant="primary"
					onClick={ handleDelete }
				>
					{ deleting ? 'Deleting…' : 'Delete' }
				</Button>
			</Flex>
		</>
	);
}
