import { toast } from 'sonner';

import { __, sprintf } from '@wordpress/i18n';
import { useState, useEffect, useMemo } from '@wordpress/element';
import {
	Button,
	ComboboxControl,
	Flex,
	Modal,
	Spinner,
} from '@wordpress/components';
import { DataViews, filterSortAndPaginate } from '@wordpress/dataviews';

import * as api from '../api';
import ConnectPrompt from './connect-prompt';

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
		'actions',
	],
	sort: { field: 'name', direction: 'asc' },
	layout: {
		styles: {
			name: { minWidth: 220 },
			actions: { textAlign: 'right' },
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
	const isConfigured = !! (
		settings?.username ||
		settings?.token ||
		settings?.gitlab_token
	);
	const [ view, setView ] = useState( DEFAULT_VIEW );

	const fields = useMemo(
		() => [
			{
				id: 'name',
				label: __( 'Repository', 'git' ),
				getValue: ( { item } ) => item.full_name,
				render: ( { item } ) => (
					<span style={ { color: '#1d2327', fontWeight: 500 } }>
						{ item.full_name }
					</span>
				),
				enableSorting: true,
				enableGlobalSearch: true,
			},
			{
				id: 'source',
				label: __( 'Source', 'git' ),
				getValue: ( { item } ) => item.provider,
				render: ( { item } ) => {
					const isGitLab = item.provider === 'gitlab';
					return (
						<Flex align="center" gap={ 1 } justify="flex-start">
							{ isGitLab ? (
								<svg
									aria-hidden="true"
									fill="#e24329"
									height="14"
									viewBox="0 0 16 16"
									width="14"
								>
									<path d="M15.97 9.058l-.895-2.756L13.3.842a.382.382 0 0 0-.724 0L10.8 6.302H5.2L3.424.842a.382.382 0 0 0-.724 0L.925 6.302.03 9.058a.762.762 0 0 0 .277.852L8 15.37l7.693-5.46a.762.762 0 0 0 .277-.852z" />
								</svg>
							) : (
								<svg
									aria-hidden="true"
									fill="#24292f"
									height="14"
									viewBox="0 0 16 16"
									width="14"
								>
									<path d="M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 5.47 7.59.4.07.55-.17.55-.38 0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13-.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.51-1.07-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.82-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82.64-.18 1.32-.27 2-.27.68 0 1.36.09 2 .27 1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.51.56.82 1.27.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A8.013 8.013 0 0016 8c0-4.42-3.58-8-8-8z" />
								</svg>
							) }
							<span style={ { fontSize: 12, color: '#57606a' } }>
								{ isGitLab ? 'GitLab' : 'GitHub' }
							</span>
						</Flex>
					);
				},
				enableSorting: true,
			},
			{
				id: 'type',
				label: __( 'Type', 'git' ),
				getValue: ( { item } ) => item.type,
				render: ( { item } ) => (
					<span
						className={ `gwp-badge gwp-badge--${
							item.type === 'theme' ? 'neutral' : 'info'
						}` }
					>
						{ item.type === 'theme'
							? __( 'Theme', 'git' )
							: __( 'Plugin', 'git' ) }
					</span>
				),
				enableSorting: true,
			},
			{
				id: 'status',
				label: __( 'Status', 'git' ),
				getValue: ( { item } ) =>
					item.active ? 'active' : 'inactive',
				render: ( { item } ) => (
					<span
						className={ `gwp-badge gwp-badge--${
							item.active ? 'success' : 'draft'
						}` }
					>
						{ item.active
							? __( 'Active', 'git' )
							: __( 'Inactive', 'git' ) }
					</span>
				),
				enableSorting: true,
			},
			{
				id: 'branch',
				label: __( 'Branch', 'git' ),
				getValue: ( { item } ) => item.branch,
				render: ( { item } ) => (
					<BranchCell item={ item } onRefresh={ onRefresh } />
				),
				enableSorting: true,
			},
			{
				id: 'head',
				label: __( 'Current Head', 'git' ),
				getValue: () => '',
				render: ( { item } ) => (
					<HeadCell item={ item } onRefresh={ onRefresh } />
				),
				enableSorting: false,
			},
			{
				id: 'last_updated',
				label: __( 'Last Updated', 'git' ),
				getValue: ( { item } ) => item.updated_at ?? 0,
				render: ( { item } ) => (
					<span style={ { fontSize: 12, color: '#57606a' } }>
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
		if ( ! isConfigured ) {
			return <ConnectPrompt onConnect={ onGoToSettings } />;
		}
		return (
			<div
				style={ {
					textAlign: 'center',
					padding: '72px 24px',
				} }
			>
				<img
					alt=""
					aria-hidden="true"
					src={ window.GWP?.not_found_url }
					style={ {
						width: 64,
						height: 64,
						display: 'block',
						margin: '0 auto 20px',
						opacity: 0.2,
					} }
				/>
				<p
					style={ {
						margin: '0 0 16px',
						fontSize: 16,
						fontWeight: 600,
						color: '#1d2327',
					} }
				>
					{ __( 'No repositories installed yet.', 'git' ) }
				</p>
				<Button variant="primary" onClick={ onGoToBrowse }>
					{ __( 'Browse repositories', 'git' ) }
				</Button>
			</div>
		);
	}

	return (
		<div className="gwp-installed-panel">
			<DataViews
				data={ shownData }
				defaultLayouts={ { table: {} } }
				fields={ fields }
				getItemId={ ( item ) => item.provider + ':' + item.full_name }
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
	const { full_name, owner, repo, type, branch, active, provider } = item;

	const [ updating, setUpdating ] = useState( false );
	const [ activating, setActivating ] = useState( false );
	const [ deactivating, setDeactivating ] = useState( false );
	const [ deleteOpen, setDeleteOpen ] = useState( false );

	const busy = updating || activating || deactivating;

	const handleUpdate = async () => {
		setUpdating( true );
		try {
			await api.switchBranch( owner, repo, branch, provider );
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
			await api.activateInstalled( owner, repo, provider );
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
			await api.deactivateInstalled( owner, repo, provider );
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
		<Flex gap={ 2 } justify="flex-end" wrap>
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
 * Clickable branch badge that opens the branch switcher modal inline.
 *
 * @param {Object}   props           Component props.
 * @param {Object}   props.item      Installed repository record.
 * @param {Function} props.onRefresh Callback to refresh the installed list.
 * @return {JSX.Element} The rendered branch cell.
 */
function BranchCell( { item, onRefresh } ) {
	const [ open, setOpen ] = useState( false );
	return (
		<>
			<Button
				className="gwp-branch-btn"
				size="compact"
				variant="link"
				onClick={ () => setOpen( true ) }
			>
				<span className="gwp-branch-btn__text">{ item.branch }</span>
			</Button>
			{ open && (
				<BranchSwitcherModal
					item={ item }
					onClose={ () => setOpen( false ) }
					onError={ ( msg ) => toast.error( msg ) }
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
				/>
			) }
		</>
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
		api.getBranches( owner, repo, item.provider ?? 'github' )
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
			await api.switchBranch(
				owner,
				repo,
				selectedBranch,
				item.provider ?? 'github'
			);
		} catch ( e ) {
			errorMsg = e.message || 'Branch switch failed.';
		}
		setSwitching( false );
		if ( errorMsg ) {
			onError( errorMsg );
		} else {
			commitsCache.delete(
				( item.provider ?? 'github' ) + ':' + item.full_name
			);
			onSwitched( selectedBranch );
		}
		onClose();
	};

	return (
		<Modal
			style={ { width: 480 } }
			title={
				<>
					{ __( 'Switch branch', 'git' ) }{ ' ' }
					<span style={ { color: 'var(--gwp-color-accent)' } }>
						{ item.repo }
					</span>
				</>
			}
			onRequestClose={ onClose }
		>
			<ComboboxControl
				__next40pxDefaultSize
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
			await api.removeInstalled(
				item.owner,
				item.repo,
				item.provider ?? 'github'
			);
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

const commitsCache = new Map();

/**
 * Table cell showing the locally installed HEAD SHA. Opens the commits modal on click.
 * If the record pre-dates SHA tracking, falls back to a one-time API fetch.
 *
 * @param {Object}   props           Component props.
 * @param {Object}   props.item      Installed repository record.
 * @param {Function} props.onRefresh Callback to refresh the installed list.
 * @return {JSX.Element} The rendered head cell.
 */
function HeadCell( { item, onRefresh } ) {
	const [ open, setOpen ] = useState( false );
	const [ fetchedSha, setFetchedSha ] = useState( null );
	const cacheKey = ( item.provider ?? 'github' ) + ':' + item.full_name;

	useEffect( () => {
		if ( item.head ) {
			return;
		}
		if ( commitsCache.has( cacheKey ) ) {
			setFetchedSha( commitsCache.get( cacheKey )?.[ 0 ]?.sha ?? null );
			return;
		}
		api.getCommits( item.owner, item.repo, item.provider ?? 'github' )
			.then( ( data ) => {
				commitsCache.set( cacheKey, data );
				setFetchedSha( data?.[ 0 ]?.sha ?? null );
			} )
			.catch( () => {} );
	}, [] ); // eslint-disable-line react-hooks/exhaustive-deps

	return (
		<>
			<Button
				size="compact"
				variant="link"
				onClick={ () => setOpen( true ) }
			>
				{ item.head ?? fetchedSha ?? '···' }
			</Button>
			{ open && (
				<CommitsModal
					item={ item }
					onClose={ () => setOpen( false ) }
					onRefresh={ onRefresh }
				/>
			) }
		</>
	);
}

/**
 * Modal showing the last 10 commits for an installed repository with a Pull Latest action.
 *
 * @param {Object}   props           Component props.
 * @param {Object}   props.item      Installed repository record.
 * @param {Function} props.onClose   Callback fired when the modal is closed.
 * @param {Function} props.onRefresh Callback to refresh the installed list after pulling.
 * @return {JSX.Element} The rendered commits modal.
 */
function CommitsModal( { item, onClose, onRefresh } ) {
	const cacheKey = ( item.provider ?? 'github' ) + ':' + item.full_name;
	const [ commits, setCommits ] = useState(
		commitsCache.get( cacheKey ) ?? null
	);
	const [ pulling, setPulling ] = useState( false );

	useEffect( () => {
		if ( commitsCache.has( cacheKey ) ) {
			return;
		}
		api.getCommits( item.owner, item.repo, item.provider ?? 'github' )
			.then( ( data ) => {
				commitsCache.set( cacheKey, data );
				setCommits( data );
			} )
			.catch( () => setCommits( [] ) );
	}, [] ); // eslint-disable-line react-hooks/exhaustive-deps

	const handlePull = async () => {
		setPulling( true );
		try {
			await api.switchBranch(
				item.owner,
				item.repo,
				item.branch,
				item.provider ?? 'github'
			);
			toast.success(
				sprintf(
					/* translators: %s: repository full name */
					__( '%s updated to latest.', 'git' ),
					item.full_name
				)
			);
			commitsCache.delete( cacheKey );
			onRefresh();
			onClose();
		} catch ( e ) {
			toast.error( e.message || __( 'Pull failed.', 'git' ) );
			setPulling( false );
		}
	};

	return (
		<Modal
			className="gwp-commits-modal"
			shouldCloseOnClickOutside={ ! pulling }
			shouldCloseOnEsc={ ! pulling }
			style={ { width: 560 } }
			title={
				<Flex align="center" justify="space-between">
					<span className="gwp-commits-modal__title">
						{ __( 'Commits', 'git' ) }{ ' ' }
						<span style={ { color: 'var(--gwp-color-accent)' } }>
							{ item.full_name }
						</span>
					</span>
					<Button
						disabled={ commits === null }
						icon="update"
						isBusy={ pulling }
						label={ __( 'Pull Latest', 'git' ) }
						size="compact"
						variant="tertiary"
						onClick={ handlePull }
					/>
				</Flex>
			}
			onRequestClose={ pulling ? undefined : onClose }
		>
			{ commits === null && (
				<div className="gwp-installed-spinner-row">
					<Spinner />
					{ __( 'Loading commits…', 'git' ) }
				</div>
			) }
			{ commits !== null && commits.length === 0 && (
				<p style={ { color: '#57606a', fontSize: 13 } }>
					{ __( 'No commits found.', 'git' ) }
				</p>
			) }
			{ commits !== null && commits.length > 0 && (
				<div style={ { marginBottom: 8 } }>
					{ commits.map( ( commit ) => (
						<div
							key={ commit.sha }
							style={ {
								display: 'flex',
								gap: 12,
								padding: '10px 0',
								borderBottom: '1px solid #f0f0f0',
							} }
						>
							<code
								style={ {
									flexShrink: 0,
									fontSize: 11,
									fontFamily: 'monospace',
									color: 'var(--gwp-color-accent)',
									paddingTop: 1,
								} }
							>
								{ commit.sha }
							</code>
							<div style={ { flex: 1, minWidth: 0 } }>
								<p
									style={ {
										margin: 0,
										fontSize: 13,
										color: '#1d2327',
										overflow: 'hidden',
										textOverflow: 'ellipsis',
										whiteSpace: 'nowrap',
									} }
								>
									{ commit.message }
								</p>
								<p
									style={ {
										margin: '2px 0 0',
										fontSize: 11,
										color: '#57606a',
									} }
								>
									{ commit.author } ·{ ' ' }
									{ new Date(
										commit.date
									).toLocaleDateString( undefined, {
										year: 'numeric',
										month: 'short',
										day: 'numeric',
									} ) }
								</p>
							</div>
						</div>
					) ) }
				</div>
			) }
		</Modal>
	);
}
