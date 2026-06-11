import { toast } from '../toast';

import { __, sprintf } from '@wordpress/i18n';
import { useState, useMemo, useCallback } from '@wordpress/element';
import { Button, Flex, Icon, Tooltip } from '@wordpress/components';
import { DataViews, filterSortAndPaginate } from '@wordpress/dataviews';

import * as api from '../api';
import { queuePendingToastAndReload } from '../pending-toast';
import BranchCell from './installed/branch-cell';
import BranchModal from './installed/branch-modal';
import CommitsModal, { clearCommitsCache } from './installed/commits-modal';
import DeleteModal from './installed/delete-modal';
import HeadCell from './installed/head-cell';
import SourceCell from './installed/source-cell';
import TypeBadge from './installed/type-badge';
import {
	hasKnownFatalUpdate,
	knownFatalBadgeLabel,
	knownFatalTooltip,
} from '../known-fatal-copy';

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
	onOpenAddRepo,
} ) {
	const entries = Object.values( installed );
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
					__( 'Switched to %s.', 'gitwire' ),
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
				label: __( 'Repository', 'gitwire' ),
				getValue: ( { item } ) => item.full_name,
				render: ( { item } ) => (
					<span className="gitwire-installed-name">
						{ item.full_name }
					</span>
				),
				enableSorting: true,
				enableGlobalSearch: true,
			},
			{
				id: 'source',
				label: __( 'Source', 'gitwire' ),
				getValue: ( { item } ) => item.provider ?? 'github',
				render: ( { item } ) => <SourceCell item={ item } />,
				enableSorting: true,
			},
			{
				id: 'type',
				label: __( 'Type', 'gitwire' ),
				getValue: ( { item } ) => item.type,
				render: ( { item } ) => <TypeBadge item={ item } />,
				enableSorting: true,
			},
			{
				id: 'status',
				label: __( 'Status', 'gitwire' ),
				getValue: ( { item } ) => {
					if ( item.activation_pending ) {
						return 'pending';
					}
					return item.active ? 'active' : 'inactive';
				},
				render: ( { item } ) => (
					<Flex align="center" justify="flex-start" gap={ 1 }>
						{ item.activation_pending ? (
							<span className="gitwire-badge gitwire-badge--warning">
								{ __( 'Verifying…', 'gitwire' ) }
							</span>
						) : (
							<span
								className={ `gitwire-badge gitwire-badge--${
									item.active ? 'success' : 'draft'
								}` }
							>
								{ item.active
									? __( 'Active', 'gitwire' )
									: __( 'Inactive', 'gitwire' ) }
							</span>
						) }
						{ item.needs_reconnect && (
							<Tooltip
								text={ __(
									'The connection used to install this repository no longer exists. Go to Settings → Connections to reconnect.',
									'gitwire'
								) }
							>
								<span className="gitwire-badge gitwire-badge--warning is-needs-reconnect">
									{ __( 'Needs Connection', 'gitwire' ) }
								</span>
							</Tooltip>
						) }
						{ item.update_available &&
							( hasKnownFatalUpdate( item ) ? (
								<Tooltip text={ knownFatalTooltip( item ) }>
									<span className="gitwire-badge gitwire-badge--warning is-update-blocked">
										{ knownFatalBadgeLabel( item ) }
									</span>
								</Tooltip>
							) : (
								<span className="gitwire-badge gitwire-badge--warning is-update">
									{ __( 'Update Available', 'gitwire' ) }
								</span>
							) ) }
					</Flex>
				),
				enableSorting: true,
			},
			{
				id: 'branch',
				label: __( 'Branch', 'gitwire' ),
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
				label: __( 'Current Head', 'gitwire' ),
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
				label: __( 'Last Updated', 'gitwire' ),
				getValue: ( { item } ) => item.updated_at ?? 0,
				render: ( { item } ) => (
					<span className="gitwire-installed-date">
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
				label: __( 'Activate', 'gitwire' ),
				icon: <Icon icon="yes-alt" />,
				isEligible: ( item ) =>
					! item.active && ! item.activation_pending,
				callback: async ( [ item ] ) => {
					try {
						await api.activateInstalled(
							item.owner,
							item.repo,
							item.provider ?? 'github'
						);
						if ( item.type === 'theme' ) {
							queuePendingToastAndReload(
								sprintf(
									/* translators: %s: repository full name */
									__( '%s activated.', 'gitwire' ),
									item.full_name
								)
							);
							return;
						}
						queuePendingToastAndReload(
							sprintf(
								/* translators: %s: repository full name */
								__( '%s activated.', 'gitwire' ),
								item.full_name
							)
						);
					} catch ( e ) {
						toast.error(
							getActivationErrorMessage( e, item.type )
						);
					}
				},
			},
			{
				id: 'deactivate',
				label: __( 'Deactivate', 'gitwire' ),
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
								__( '%s deactivated.', 'gitwire' ),
								item.full_name
							)
						);
					} catch ( e ) {
						toast.error(
							e.message || __( 'Deactivation failed.', 'gitwire' )
						);
					}
				},
			},
			{
				id: 'switch-theme',
				label: __( 'Switch theme', 'gitwire' ),
				icon: <Icon icon="admin-appearance" />,
				isEligible: ( item ) => item.active && item.type === 'theme',
				callback: () => {
					const themesUrl = window.Gitwire?.themes_url;
					if ( themesUrl ) {
						window.location.href = themesUrl;
					}
				},
			},
			{
				id: 'delete',
				label: __( 'Delete', 'gitwire' ),
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
		return (
			<div className="gitwire-installed-empty">
				<img
					alt=""
					aria-hidden="true"
					src={ window.Gitwire?.not_found_url }
				/>
				<h2>{ __( 'No repositories yet.', 'gitwire' ) }</h2>
				<p className="gitwire-installed-empty__hint">
					{ __(
						'Install plugins and themes directly from GitHub, GitLab, or Bitbucket.',
						'gitwire'
					) }
				</p>
				<Button variant="primary" onClick={ () => onOpenAddRepo() }>
					{ __( 'Add Repository', 'gitwire' ) }
				</Button>
			</div>
		);
	}

	return (
		<div className="gitwire-installed-panel">
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
				onRefresh={ onRefresh }
				onSwitched={ handleBranchSwitched }
			/>
			<CommitsModal
				item={ commitsModalItem }
				onClose={ () => setCommitsModalItem( null ) }
			/>
		</div>
	);
}

/**
 * @param {Error}  error Activation request error.
 * @param {string} type  Installed item type: 'plugin' or 'theme'.
 * @return {string} User-facing activation error message.
 */
function getActivationErrorMessage( error, type ) {
	const message = error?.message || '';
	const isFatalResponse =
		error?.code === 'invalid_json' ||
		/not a valid JSON response/i.test( message );

	if ( isFatalResponse ) {
		if ( type === 'theme' ) {
			return __(
				'Theme could not be activated. It triggered a fatal error.',
				'gitwire'
			);
		}
		return __(
			'Plugin could not be activated. It triggered a fatal error.',
			'gitwire'
		);
	}

	return message || __( 'Activation failed.', 'gitwire' );
}
