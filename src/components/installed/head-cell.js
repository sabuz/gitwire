import { toast } from '../../toast';

import { __, sprintf } from '@wordpress/i18n';
import { useState, useCallback } from '@wordpress/element';
import { Button, Flex } from '@wordpress/components';

import * as api from '../../api';
import { clearCommitsCache } from './commits-modal';

/**
 * Table cell showing the locally installed HEAD SHA with a Pull Latest icon.
 *
 * @param {Object}   props               Component props.
 * @param {Object}   props.item          Installed repository record.
 * @param {Function} props.onRefresh     Callback to refresh the installed list.
 * @param {Function} props.onOpenCommits Callback to open the commits modal.
 * @return {JSX.Element} The rendered head cell.
 */
export default function HeadCell( { item, onRefresh, onOpenCommits } ) {
	const [ pulling, setPulling ] = useState( false );

	const handlePull = useCallback( async () => {
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
			onRefresh();
		} catch ( e ) {
			clearCommitsCache( item );
			toast.error( e.message || __( 'Pull failed.', 'git' ) );
			onRefresh();
		} finally {
			setPulling( false );
		}
	}, [ item, onRefresh ] );

	const displaySha = item.activation_pending
		? item.head || '···'
		: item.head || '—';

	return (
		<Flex align="center" gap={ 1 } justify="flex-start">
			<Button
				size="compact"
				variant="link"
				onClick={ () => onOpenCommits( item ) }
			>
				{ displaySha }
			</Button>
			<Button
				className={ pulling ? 'gwp-spin' : '' }
				disabled={ pulling || item.activation_pending }
				icon="update"
				label={ __( 'Pull Latest', 'git' ) }
				size="compact"
				variant="tertiary"
				onClick={ handlePull }
			/>
		</Flex>
	);
}
