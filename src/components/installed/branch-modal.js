import { toast } from 'sonner';

import { __, sprintf } from '@wordpress/i18n';
import { useState, useEffect, useMemo } from '@wordpress/element';
import { Button, ComboboxControl, Flex, Modal } from '@wordpress/components';

import * as api from '../../api';

/**
 * Modal for switching the active branch of an installed repository.
 *
 * @param {Object}   props            Component props.
 * @param {Object}   props.item       Installed repository record.
 * @param {Function} props.onClose    Callback to close the modal.
 * @param {Function} props.onSwitched Callback fired with the new branch name on success.
 * @param {Function} props.onRefresh  Refreshes installed data after verify failures.
 * @return {JSX.Element|null} The rendered modal.
 */
export default function BranchModal( {
	item,
	onClose,
	onSwitched,
	onRefresh,
} ) {
	const { owner, repo, branch } = item || {};
	const [ allBranches, setAllBranches ] = useState( [] );
	const [ branchFilter, setBranchFilter ] = useState( '' );
	const [ selectedBranch, setSelectedBranch ] = useState( '' );
	const [ switching, setSwitching ] = useState( false );

	useEffect( () => {
		if ( ! item ) {
			return;
		}
		setSelectedBranch( item.branch || '' );
		setBranchFilter( '' );
		setAllBranches( [] );
		api.getBranches( owner, repo, item.provider ?? 'github' )
			.then( setAllBranches )
			.catch( () => setAllBranches( [] ) );
	}, [ item, owner, repo ] );

	const branchOptions = useMemo( () => {
		if ( ! item ) {
			return [];
		}
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
	}, [ allBranches, branchFilter, selectedBranch, branch, item ] );

	if ( ! item ) {
		return null;
	}

	const handleSwitch = async () => {
		if ( ! selectedBranch || selectedBranch === branch ) {
			onClose();
			return;
		}
		setSwitching( true );
		try {
			await api.switchBranch(
				owner,
				repo,
				selectedBranch,
				item.provider ?? 'github'
			);
			onSwitched( selectedBranch );
			onClose();
		} catch ( e ) {
			toast.error( e.message || __( 'Branch switch failed.', 'git' ) );
		} finally {
			setSwitching( false );
		}
	};

	return (
		<Modal
			className="gwp-modal"
			style={ { width: 480 } }
			title={
				<span className="gwp-modal__title">
					{ __( 'Switch branch', 'git' ) }{ ' ' }
					<span style={ { color: 'var(--gwp-color-accent)' } }>
						{ item.repo }
					</span>
				</span>
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
					disabled={ switching }
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
