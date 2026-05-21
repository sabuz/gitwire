import { toast } from 'sonner';

import { useState, useEffect, useMemo } from '@wordpress/element';
import {
	Button,
	Card,
	CardBody,
	ComboboxControl,
	Flex,
	Modal,
} from '@wordpress/components';
import { DataViews, filterSortAndPaginate } from '@wordpress/dataviews';

import * as api from '../api';

const DEFAULT_VIEW = {
	type: 'table',
	search: '',
	page: 1,
	perPage: 10,
	fields: [ 'name', 'type', 'status', 'branch', 'actions' ],
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
			{
				id: 'actions',
				label: '',
				getValue: () => '',
				render: ( { item } ) => (
					<RowActions item={ item } onRefresh={ onRefresh } />
				),
				enableSorting: false,
				enableHiding: false,
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
			<DataViews
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
 * Inline action buttons rendered inside each DataViews table row.
 *
 * @param {Object}   props           Component props.
 * @param {Object}   props.item      Installed repository record.
 * @param {Function} props.onRefresh Callback to refresh the installed list.
 * @return {JSX.Element} The rendered row actions.
 */
function RowActions( { item, onRefresh } ) {
	const { full_name, owner, repo, type, branch, active } = item;

	const [ updating, setUpdating ] = useState( false );
	const [ activating, setActivating ] = useState( false );
	const [ deactivating, setDeactivating ] = useState( false );
	const [ switchOpen, setSwitchOpen ] = useState( false );
	const [ deleteOpen, setDeleteOpen ] = useState( false );

	const busy = updating || activating || deactivating;

	const handleUpdate = async () => {
		setUpdating( true );
		try {
			await api.switchBranch( owner, repo, branch );
			toast.success( `${ full_name }: updated to latest commit.` );
			onRefresh();
		} catch ( e ) {
			toast.error( e.message || 'Update failed.' );
		} finally {
			setUpdating( false );
		}
	};

	const handleActivate = async () => {
		setActivating( true );
		try {
			await api.activateInstalled( owner, repo );
			toast.success( `${ full_name } activated.` );
			onRefresh();
		} catch ( e ) {
			toast.error( e.message || 'Activation failed.' );
		} finally {
			setActivating( false );
		}
	};

	const handleDeactivate = async () => {
		setDeactivating( true );
		try {
			await api.deactivateInstalled( owner, repo );
			toast.success( `${ full_name } deactivated.` );
			onRefresh();
		} catch ( e ) {
			toast.error( e.message || 'Deactivation failed.' );
		} finally {
			setDeactivating( false );
		}
	};

	return (
		<Flex gap={ 2 } justify="flex-start" wrap>
			<Button
				disabled={ busy }
				isBusy={ updating }
				size="compact"
				variant="secondary"
				onClick={ handleUpdate }
			>
				{ updating ? 'Updating…' : 'Pull latest' }
			</Button>

			<Button
				disabled={ busy }
				size="compact"
				variant="secondary"
				onClick={ () => setSwitchOpen( true ) }
			>
				Switch branch
			</Button>

			{ ! active && (
				<Button
					disabled={ busy }
					isBusy={ activating }
					size="compact"
					variant="secondary"
					onClick={ handleActivate }
				>
					{ activating ? 'Activating…' : 'Activate' }
				</Button>
			) }

			{ active && type === 'plugin' && (
				<Button
					disabled={ busy }
					isBusy={ deactivating }
					size="compact"
					variant="secondary"
					onClick={ handleDeactivate }
				>
					{ deactivating ? 'Deactivating…' : 'Deactivate' }
				</Button>
			) }

			{ ! active && (
				<Button
					disabled={ busy }
					isDestructive
					size="compact"
					variant="secondary"
					onClick={ () => setDeleteOpen( true ) }
				>
					Delete
				</Button>
			) }

			{ switchOpen && (
				<BranchSwitcherModal
					item={ item }
					onClose={ () => setSwitchOpen( false ) }
					onSwitched={ ( newBranch ) => {
						toast.success( `Switched to ${ newBranch }.` );
						onRefresh();
					} }
					onError={ ( msg ) => toast.error( msg ) }
				/>
			) }

			{ deleteOpen && (
				<DeleteConfirmModal
					item={ item }
					onClose={ () => setDeleteOpen( false ) }
					onDeleted={ () => {
						toast.success( `${ full_name } deleted.` );
						onRefresh();
					} }
					onError={ ( msg ) => toast.error( msg ) }
				/>
			) }
		</Flex>
	);
}

/**
 * Modal for switching the active branch of an installed repository.
 *
 * @param {Object}   props            Component props.
 * @param {Object}   props.item       Installed repository record.
 * @param {Function} props.onClose    Callback to close the modal.
 * @param {Function} props.onSwitched Callback fired with the new branch name on success.
 * @param {Function} props.onError    Callback fired with an error message on failure.
 * @return {JSX.Element} The rendered modal.
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
		<Modal
			title={ `Switch branch — ${ item.repo }` }
			onRequestClose={ onClose }
		>
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
		</Modal>
	);
}

/**
 * Confirmation modal for permanently deleting an installed repository.
 *
 * @param {Object}   props           Component props.
 * @param {Object}   props.item      Installed repository record.
 * @param {Function} props.onClose   Callback to close the modal.
 * @param {Function} props.onDeleted Callback fired after successful deletion.
 * @param {Function} props.onError   Callback fired with an error message on failure.
 * @return {JSX.Element} The rendered modal.
 */
function DeleteConfirmModal( { item, onClose, onDeleted, onError } ) {
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
			onClose();
		} else {
			onDeleted();
		}
	};

	return (
		<Modal
			title={ `Delete ${ item.full_name }?` }
			onRequestClose={ onClose }
		>
			<p>
				Permanently delete <strong>{ item.full_name }</strong>? This
				will remove all files from the server and cannot be undone.
			</p>
			<Flex gap={ 3 } justify="flex-end">
				<Button variant="tertiary" onClick={ onClose }>
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
		</Modal>
	);
}
