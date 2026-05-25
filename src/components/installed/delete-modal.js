import { toast } from '../../toast';

import { __, sprintf } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { Button, Flex } from '@wordpress/components';

import * as api from '../../api';

/**
 * Modal body rendered by DataViews for the delete action.
 *
 * @param {Object}   props            Component props supplied by DataViews.
 * @param {Array}    props.items      Selected items.
 * @param {Function} props.closeModal Callback to close the DataViews modal.
 * @param {Function} props.onRefresh  Callback to refresh the installed list.
 * @return {JSX.Element} The rendered delete confirmation body.
 */
export default function DeleteModal( { items, closeModal, onRefresh } ) {
	const [ item ] = items;
	const [ deleting, setDeleting ] = useState( false );

	const handleDelete = async () => {
		setDeleting( true );
		try {
			await api.removeInstalled(
				item.owner,
				item.repo,
				item.provider ?? 'github'
			);
			toast.success(
				sprintf(
					/* translators: %s: repository full name */
					__( '%s deleted.', 'git' ),
					item.full_name
				)
			);
			onRefresh();
			closeModal();
		} catch ( e ) {
			toast.error( e.message || __( 'Delete failed.', 'git' ) );
			setDeleting( false );
		}
	};

	return (
		<>
			<p style={ { margin: 0 } }>
				{ sprintf(
					/* translators: %s: repository full name */
					__(
						'Permanently delete %s? This will remove all files from the server and cannot be undone.',
						'git'
					),
					item.full_name
				) }
			</p>
			<Flex gap={ 3 } justify="flex-end" style={ { marginTop: 16 } }>
				<Button variant="tertiary" onClick={ closeModal }>
					{ __( 'Cancel', 'git' ) }
				</Button>
				<Button
					disabled={ deleting }
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
		</>
	);
}
