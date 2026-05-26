import { toast } from '../../toast';

import { __, sprintf } from '@wordpress/i18n';
import { useState, useCallback, useMemo } from '@wordpress/element';
import { Button, Flex, Tooltip } from '@wordpress/components';

import * as api from '../../api';
import { clearCommitsCache } from './commits-modal';
import { hasKnownFatalUpdate, knownFatalTooltip } from '../../known-fatal-copy';

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
	const isKnownFatalUpdate = useMemo(
		() => hasKnownFatalUpdate( item ),
		[ item ]
	);
	const pullTooltip = useMemo( () => {
		if ( isKnownFatalUpdate ) {
			return knownFatalTooltip( item );
		}

		return __( 'Pull Latest', 'git' );
	}, [ isKnownFatalUpdate, item ] );

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
			<Tooltip text={ pullTooltip }>
				<Button
					className={ pulling ? 'gwp-spin' : '' }
					disabled={ pulling || item.activation_pending }
					icon="update"
					label={ pullTooltip }
					size="compact"
					variant="tertiary"
					onClick={ handlePull }
				/>
			</Tooltip>
		</Flex>
	);
}
