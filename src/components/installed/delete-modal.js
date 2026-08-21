import { toast } from '../../toast';

import { __, sprintf } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { Button, Flex } from '@wordpress/components';

import * as api from '../../api';

/**
 * Modal body rendered by DataViews for the delete action.
 *
 * Offers two choices:
 *   - "Unlink from Gitwire" removes the tracking record only, files stay on disk.
 *   - "Delete files" removes the record AND deletes files from the server.
 *
 * @param {Object}   props            Component props supplied by DataViews.
 * @param {Array}    props.items      Selected items.
 * @param {Function} props.closeModal Callback to close the DataViews modal.
 * @param {Function} props.onRefresh  Callback to refresh the installed list.
 * @return {JSX.Element} The rendered delete confirmation body.
 */
export default function DeleteModal( { items, closeModal, onRefresh } ) {
	const [ item ] = items;
	const [ busy, setBusy ] = useState( null ); // 'untrack' | 'delete' | null

	const handleUntrack = async () => {
		setBusy( 'untrack' );
		try {
			await api.untrackInstalled(
				item.owner,
				item.repo,
				item.provider ?? 'github'
			);
			toast.success(
				sprintf(
					/* translators: %s: repository full name */
					__(
						'%s unlinked from Gitwire. Files remain on disk.',
						'gitwire'
					),
					item.full_name
				)
			);
			onRefresh();
			closeModal();
		} catch ( e ) {
			toast.error(
				e.message ||
					__( 'Failed to remove tracking record.', 'gitwire' )
			);
			setBusy( null );
		}
	};

	const handleDelete = async () => {
		setBusy( 'delete' );
		try {
			await api.removeInstalled(
				item.owner,
				item.repo,
				item.provider ?? 'github'
			);
			toast.success(
				sprintf(
					/* translators: %s: repository full name */
					__( '%s deleted.', 'gitwire' ),
					item.full_name
				)
			);
			onRefresh();
			closeModal();
		} catch ( e ) {
			toast.error( e.message || __( 'Delete failed.', 'gitwire' ) );
			setBusy( null );
		}
	};

	return (
		<>
			<p style={ { margin: '0 0 16px', fontSize: 13, color: '#57606a' } }>
				{ sprintf(
					/* translators: %s: repository full name */
					__( 'What would you like to do with %s?', 'gitwire' ),
					item.full_name
				) }
			</p>
			<Flex direction="column" gap={ 4 }>
				<div>
					<p
						style={ {
							margin: '0 0 4px',
							fontWeight: 600,
							fontSize: 13,
						} }
					>
						{ __( 'Unlink from Gitwire', 'gitwire' ) }
					</p>
					<p style={ { margin: 0, fontSize: 13, color: '#57606a' } }>
						{ __(
							'Stops tracking this repository. The plugin or theme files stay on the server and remain usable.',
							'gitwire'
						) }
					</p>
				</div>
				<div>
					<p
						style={ {
							margin: '0 0 4px',
							fontWeight: 600,
							fontSize: 13,
							color: '#cf222e',
						} }
					>
						{ __( 'Delete Files', 'gitwire' ) }
					</p>
					<p style={ { margin: 0, fontSize: 13, color: '#57606a' } }>
						{ __(
							'Removes the tracking record and permanently deletes all files from the server. This cannot be undone.',
							'gitwire'
						) }
					</p>
				</div>
			</Flex>
			<Flex gap={ 3 } justify="flex-end" style={ { marginTop: 16 } }>
				<Button
					disabled={ !! busy }
					variant="tertiary"
					onClick={ closeModal }
				>
					{ __( 'Cancel', 'gitwire' ) }
				</Button>
				<Button
					disabled={ !! busy }
					isBusy={ busy === 'untrack' }
					variant="secondary"
					onClick={ handleUntrack }
				>
					{ __( 'Unlink from Gitwire', 'gitwire' ) }
				</Button>
				<Button
					disabled={ !! busy }
					isDestructive
					isBusy={ busy === 'delete' }
					variant="primary"
					onClick={ handleDelete }
				>
					{ __( 'Delete Files', 'gitwire' ) }
				</Button>
			</Flex>
		</>
	);
}
