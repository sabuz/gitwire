import { toast } from 'sonner';

import { __, sprintf } from '@wordpress/i18n';
import { useState, useMemo, useCallback } from '@wordpress/element';
import { Button, Flex, Icon } from '@wordpress/components';
import { DataViews, filterSortAndPaginate } from '@wordpress/dataviews';

import * as api from '../api';
import { queuePendingToastAndReload } from '../pending-toast';
import ConnectPrompt from './connect-prompt';
import BranchCell from './installed/branch-cell';
import BranchModal from './installed/branch-modal';
import CommitsModal, { clearCommitsCache } from './installed/commits-modal';
import DeleteModal from './installed/delete-modal';
import HeadCell from './installed/head-cell';
import SourceCell from './installed/source-cell';
import TypeBadge from './installed/type-badge';

const DEFAULT_VIEW = {
	type: 'table',
	search: '',
	page: 1,
	perPage: 10,
	fields: [
		'name',
		'source',
		'type',
		'status',
		'branch',
		'head',
		'last_updated',
	],
	sort: { field: 'name', direction: 'asc' },
	layout: {
		styles: {
			name: { minWidth: 220 },
		},
	},
};

export default function InstalledPanel( {
	installed,
	settings,
	onRefresh,
	onGoToSettings,
	onGoToBrowse,
} ) {
	const entries = Object.values( installed );
	const isConfigured = !! (
		settings?.username ||
		settings?.token_set ||
		settings?.gitlab_token_set
	);
	const [ view, setView ] = useState( DEFAULT_VIEW );
	const [ branchModalItem, setBranchModalItem ] = useState( null );
	const [ commitsModalItem, setCommitsModalItem ] = useState( null );

	const handleOpenBranch = useCallback( ( item ) => {
		setBranchModalItem( item );
	}, [] );

	const handleOpenCommits = useCallback( ( item ) => {
		setCommitsModalItem( item );
	}, [] );

	const handleBranchSwitched = useCallback(
		( newBranch ) => {
			if ( branchModalItem ) {
				clearCommitsCache( branchModalItem );
			}
			toast.success(
				sprintf(
					/* translators: %s: branch name */
					__( 'Switched to %s.', 'git' ),
					newBranch
				)
			);
			onRefresh();
		},
		[ branchModalItem, onRefresh ]
	);

	const fields = useMemo(
		() => [
			{
				id: 'name',
				label: __( 'Repository', 'git' ),
				getValue: ( { item } ) => item.full_name,
				render: ( { item } ) => (
					<span className="gwp-installed-name">
						{ item.full_name }
					</span>
				),
				enableSorting: true,
				enableGlobalSearch: true,
			},
			{
				id: 'source',
				label: __( 'Source', 'git' ),
				getValue: ( { item } ) => item.provider ?? 'github',
				render: ( { item } ) => <SourceCell item={ item } />,
				enableSorting: true,
			},
			{
				id: 'type',
				label: __( 'Type', 'git' ),
				getValue: ( { item } ) => item.type,
				render: ( { item } ) => <TypeBadge item={ item } />,
				enableSorting: true,
			},
			{
				id: 'status',
				label: __( 'Status', 'git' ),
				getValue: ( { item } ) =>
					item.active ? 'active' : 'inactive',
				render: ( { item } ) => (
					<Flex align="center" gap={ 1 }>
						<span
							className={ `gwp-badge gwp-badge--${
								item.active ? 'success' : 'draft'
							}` }
						>
							{ item.active
								? __( 'Active', 'git' )
								: __( 'Inactive', 'git' ) }
						</span>
						{ item.update_available && (
							<span className="gwp-badge gwp-badge--warning is-update">
								{ __( 'Update available', 'git' ) }
							</span>
						) }
					</Flex>
				),
				enableSorting: true,
			},
			{
				id: 'branch',
				label: __( 'Branch', 'git' ),
				getValue: ( { item } ) => item.branch,
				render: ( { item } ) => (
					<BranchCell
						item={ item }
						onOpenBranch={ handleOpenBranch }
					/>
				),
				enableSorting: true,
			},
			{
				id: 'head',
				label: __( 'Current Head', 'git' ),
				getValue: () => '',
				render: ( { item } ) => (
					<HeadCell
						item={ item }
						onOpenCommits={ handleOpenCommits }
						onRefresh={ onRefresh }
					/>
				),
				enableSorting: false,
			},
			{
				id: 'last_updated',
				label: __( 'Last Updated', 'git' ),
				getValue: ( { item } ) => item.updated_at ?? 0,
				render: ( { item } ) => (
					<span className="gwp-installed-date">
						{ item.updated_at
							? new Date( item.updated_at * 1000 ).toLocaleString(
									undefined,
									{
										year: 'numeric',
										month: 'short',
										day: 'numeric',
										hour: 'numeric',
										minute: '2-digit',
									}
							  )
							: '—' }
					</span>
				),
				enableSorting: true,
			},
		],
		[ handleOpenBranch, handleOpenCommits, onRefresh ]
	);

	const actions = useMemo(
		() => [
			{
				id: 'activate',
				label: __( 'Activate', 'git' ),
				icon: <Icon icon="yes-alt" />,
				isEligible: ( item ) => ! item.active,
				callback: async ( [ item ] ) => {
					try {
						await api.activateInstalled(
							item.owner,
							item.repo,
							item.provider ?? 'github'
						);
						queuePendingToastAndReload(
							sprintf(
								/* translators: %s: repository full name */
								__( '%s activated.', 'git' ),
								item.full_name
							)
						);
					} catch ( e ) {
						toast.error(
							e.message || __( 'Activation failed.', 'git' )
						);
					}
				},
			},
			{
				id: 'deactivate',
				label: __( 'Deactivate', 'git' ),
				icon: <Icon icon="no-alt" />,
				isEligible: ( item ) => item.active && item.type === 'plugin',
				callback: async ( [ item ] ) => {
					try {
						await api.deactivateInstalled(
							item.owner,
							item.repo,
							item.provider ?? 'github'
						);
						queuePendingToastAndReload(
							sprintf(
								/* translators: %s: repository full name */
								__( '%s deactivated.', 'git' ),
								item.full_name
							)
						);
					} catch ( e ) {
						toast.error(
							e.message || __( 'Deactivation failed.', 'git' )
						);
					}
				},
			},
			{
				id: 'delete',
				label: __( 'Delete', 'git' ),
				icon: <Icon icon="trash" />,
				isDestructive: true,
				isEligible: ( item ) => ! item.active,
				RenderModal: ( props ) => (
					<DeleteModal { ...props } onRefresh={ onRefresh } />
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
		if ( ! isConfigured ) {
			return <ConnectPrompt onConnect={ onGoToSettings } />;
		}
		return (
			<div className="gwp-installed-empty">
				<img
					alt=""
					aria-hidden="true"
					src={ window.GWP?.not_found_url }
				/>
				<h2>{ __( 'No repositories installed yet.', 'git' ) }</h2>
				<Button variant="primary" onClick={ onGoToBrowse }>
					{ __( 'Browse repositories', 'git' ) }
				</Button>
			</div>
		);
	}

	return (
		<div className="gwp-installed-panel">
			<DataViews
				actions={ actions }
				data={ shownData }
				defaultLayouts={ { table: {} } }
				fields={ fields }
				getItemId={ ( item ) => item.provider + ':' + item.full_name }
				paginationInfo={ paginationInfo }
				view={ view }
				onChangeView={ setView }
			/>
			<BranchModal
				item={ branchModalItem }
				onClose={ () => setBranchModalItem( null ) }
				onSwitched={ handleBranchSwitched }
			/>
			<CommitsModal
				item={ commitsModalItem }
				onClose={ () => setCommitsModalItem( null ) }
			/>
		</div>
	);
}
