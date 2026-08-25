import { toast } from '../../toast';
import { linkifyGitwirePro } from '../../linkify-gitwire-pro';

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

		return __( 'Pull Latest', 'gitwire' );
	}, [ isKnownFatalUpdate, item ] );

	const handlePull = useCallback( async () => {
		setPulling( true );
		try {
			const result = await api.switchBranch( item.id, item.branch );
			toast.success(
				sprintf(
					/* translators: %s: repository full name */
					__( '%s updated to latest.', 'gitwire' ),
					item.full_name
				)
			);
			if ( result?.warning ) {
				toast.warning( result.warning );
			}
			onRefresh();
		} catch ( e ) {
			clearCommitsCache( item );
			toast.error(
				linkifyGitwirePro( e.message ) ||
					__( 'Pull failed.', 'gitwire' )
			);
			onRefresh();
		} finally {
			setPulling( false );
		}
	}, [ item, onRefresh ] );

	const shortSha = item.head ? item.head.slice( 0, 7 ) : null;
	const displaySha = item.activation_pending
		? shortSha ?? '···'
		: shortSha ?? '—';

	return (
		<Flex align="center" gap={ 1 } justify="flex-start">
			<Button
				size="compact"
				variant="link"
				onClick={ () => onOpenCommits( item ) }
			>
				{ displaySha }
			</Button>
			<Tooltip
				className={
					isKnownFatalUpdate ? 'gitwire-tooltip-wrap' : undefined
				}
				text={ pullTooltip }
			>
				<Button
					className={ pulling ? 'gitwire-spin' : '' }
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
