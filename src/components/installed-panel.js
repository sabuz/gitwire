import { toast } from 'sonner';

import { __, sprintf } from '@wordpress/i18n';
import { useState, useEffect, useMemo } from '@wordpress/element';
import {
	Button,
	Card,
	CardBody,
	ComboboxControl,
	Flex,
	Modal,
} from '@wordpress/components';
import { Badge } from '@wordpress/ui';
import { DataViews, filterSortAndPaginate } from '@wordpress/dataviews';

import * as api from '../api';

const DEFAULT_VIEW = {
	type: 'table',
	search: '',
	page: 1,
	perPage: 10,
	fields: [ 'name', 'type', 'status', 'branch', 'last_updated', 'actions' ],
	sort: { field: 'name', direction: 'asc' },
	layout: {
		styles: {
			name: { minWidth: 220 },
		},
	},
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
				label: __( 'Repository', 'git' ),
				getValue: ( { item } ) => item.full_name,
				render: ( { item } ) => <strong>{ item.full_name }</strong>,
				enableSorting: true,
				enableGlobalSearch: true,
			},
			{
				id: 'type',
				label: __( 'Type', 'git' ),
				getValue: ( { item } ) => item.type,
				render: ( { item } ) => (
					<Badge
						intent={
							item.type === 'theme' ? 'none' : 'informational'
						}
					>
						{ item.type === 'theme'
							? __( 'Theme', 'git' )
							: __( 'Plugin', 'git' ) }
					</Badge>
				),
				enableSorting: true,
			},
			{
				id: 'status',
				label: __( 'Status', 'git' ),
				getValue: ( { item } ) =>
					item.active ? 'active' : 'inactive',
				render: ( { item } ) => (
					<Badge intent={ item.active ? 'stable' : 'draft' }>
						{ item.active
							? __( 'Active', 'git' )
							: __( 'Inactive', 'git' ) }
					</Badge>
				),
				enableSorting: true,
			},
			{
				id: 'branch',
				label: __( 'Branch', 'git' ),
				getValue: ( { item } ) => item.branch,
				render: ( { item } ) => (
					<Badge intent="informational">{ item.branch }</Badge>
				),
				enableSorting: true,
			},
			{
				id: 'last_updated',
				label: __( 'Last Updated', 'git' ),
				getValue: ( { item } ) => item.updated_at ?? 0,
				render: ( { item } ) => (
					<span style={ { fontSize: 12, color: '#57606a' } }>
						{ item.updated_at
							? new Date(
									item.updated_at * 1000
							  ).toLocaleDateString( undefined, {
									year: 'numeric',
									month: 'short',
									day: 'numeric',
							  } )
							: '—' }
					</span>
				),
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
					<img
						alt=""
						aria-hidden="true"
						src={ window.GWP?.disconnected_url }
						style={ {
							width: 36,
							height: 36,
							display: 'block',
							margin: '0 auto 12px',
							opacity: 0.3,
						} }
					/>
					{ isConfigured ? (
						<p style={ { margin: 0 } }>
							{ __( 'No repositories installed yet.', 'git' ) }{ ' ' }
							<Button variant="link" onClick={ onGoToBrowse }>
								{ __( 'Browse', 'git' ) }
							</Button>{ ' ' }
							{ __( 'to install one.', 'git' ) }
						</p>
					) : (
						<>
							<p style={ { margin: '0 0 12px' } }>
								{ __(
									'Connect your GitHub account to get started.',
									'git'
								) }
							</p>
							<Button
								variant="primary"
								onClick={ onGoToSettings }
							>
								{ __( 'Set up GitHub connection', 'git' ) }
							</Button>
						</>
					) }
				</CardBody>
			</Card>
		);
	}

	return (
		<div className="gwp-installed-panel">
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
			toast.success(
				sprintf(
					/* translators: %s: repository full name */
					__( '%s: updated to latest commit.', 'git' ),
					full_name
				)
			);
			onRefresh();
		} catch ( e ) {
			toast.error( e.message || __( 'Update failed.', 'git' ) );
		} finally {
			setUpdating( false );
		}
	};

	const handleActivate = async () => {
		setActivating( true );
		try {
			await api.activateInstalled( owner, repo );
			toast.success(
				sprintf(
					/* translators: %s: repository full name */
					__( '%s activated.', 'git' ),
					full_name
				)
			);
			onRefresh();
		} catch ( e ) {
			toast.error( e.message || __( 'Activation failed.', 'git' ) );
		} finally {
			setActivating( false );
		}
	};

	const handleDeactivate = async () => {
		setDeactivating( true );
		try {
			await api.deactivateInstalled( owner, repo );
			toast.success(
				sprintf(
					/* translators: %s: repository full name */
					__( '%s deactivated.', 'git' ),
					full_name
				)
			);
			onRefresh();
		} catch ( e ) {
			toast.error( e.message || __( 'Deactivation failed.', 'git' ) );
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
				{ updating
					? __( 'Updating…', 'git' )
					: __( 'Pull latest', 'git' ) }
			</Button>

			<Button
				disabled={ busy }
				size="compact"
				variant="secondary"
				onClick={ () => setSwitchOpen( true ) }
			>
				{ __( 'Switch branch', 'git' ) }
			</Button>

			{ ! active && (
				<Button
					disabled={ busy }
					isBusy={ activating }
					size="compact"
					variant="secondary"
					onClick={ handleActivate }
				>
					{ activating
						? __( 'Activating…', 'git' )
						: __( 'Activate', 'git' ) }
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
					{ deactivating
						? __( 'Deactivating…', 'git' )
						: __( 'Deactivate', 'git' ) }
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
					{ __( 'Delete', 'git' ) }
				</Button>
			) }

			{ switchOpen && (
				<BranchSwitcherModal
					item={ item }
					onClose={ () => setSwitchOpen( false ) }
					onSwitched={ ( newBranch ) => {
						toast.success(
							sprintf(
								/* translators: %s: branch name */
								__( 'Switched to %s.', 'git' ),
								newBranch
							)
						);
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
						toast.success(
							sprintf(
								/* translators: %s: repository full name */
								__( '%s deleted.', 'git' ),
								full_name
							)
						);
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
			title={ sprintf(
				/* translators: %s: repository name */
				__( 'Switch branch — %s', 'git' ),
				item.repo
			) }
			onRequestClose={ onClose }
		>
			<ComboboxControl
				__nextHasNoMarginBottom
				label={ __( 'Branch', 'git' ) }
				options={ branchOptions }
				value={ selectedBranch }
				onChange={ ( val ) => val && setSelectedBranch( val ) }
				onFilterValueChange={ setBranchFilter }
			/>
			<Flex gap={ 3 } justify="flex-end" style={ { marginTop: 16 } }>
				<Button variant="tertiary" onClick={ onClose }>
					{ __( 'Cancel', 'git' ) }
				</Button>
				<Button
					isBusy={ switching }
					variant="primary"
					onClick={ handleSwitch }
				>
					{ switching
						? __( 'Switching…', 'git' )
						: __( 'Switch', 'git' ) }
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
			title={ sprintf(
				/* translators: %s: repository full name */
				__( 'Delete %s?', 'git' ),
				item.full_name
			) }
			onRequestClose={ onClose }
		>
			<p>
				{ sprintf(
					/* translators: %s: repository full name */
					__(
						'Permanently delete %s? This will remove all files from the server and cannot be undone.',
						'git'
					),
					item.full_name
				) }
			</p>
			<Flex gap={ 3 } justify="flex-end">
				<Button variant="tertiary" onClick={ onClose }>
					{ __( 'Cancel', 'git' ) }
				</Button>
				<Button
					isDestructive
					isBusy={ deleting }
					variant="primary"
					onClick={ handleDelete }
				>
					{ deleting
						? __( 'Deleting…', 'git' )
						: __( 'Delete', 'git' ) }
				</Button>
			</Flex>
		</Modal>
	);
}
