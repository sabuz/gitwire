import { toast } from '../../toast';

import { __, sprintf } from '@wordpress/i18n';
import { useState, useEffect } from '@wordpress/element';
import { Button, Flex, Modal, Spinner } from '@wordpress/components';

import * as api from '../../api';

/**
 * Modal for selecting a replacement connection when the stored connection is unavailable.
 *
 * @param {Object}   props             Component props.
 * @param {Object}   props.item        Installed repository record.
 * @param {Array}    props.connections All stored connections.
 * @param {Function} props.onClose     Fired when the modal is dismissed.
 * @param {Function} props.onRefresh   Fired after a successful pull.
 * @return {JSX.Element} The rendered modal.
 */
export default function ReconnectModal( {
	item,
	connections,
	onClose,
	onRefresh,
} ) {
	const providerConns = connections.filter(
		( c ) => c.provider === ( item.provider ?? 'github' )
	);

	const [ results, setResults ] = useState( {} );
	const [ pulling, setPulling ] = useState( null );

	useEffect( () => {
		providerConns.forEach( ( conn ) => {
			api.detectRepo(
				item.owner,
				item.repo,
				item.branch || 'HEAD',
				item.provider ?? 'github',
				conn.id
			)
				.then( ( d ) => {
					setResults( ( prev ) => ( {
						...prev,
						[ conn.id ]: d.type !== 'unknown',
					} ) );
				} )
				.catch( () => {
					setResults( ( prev ) => ( {
						...prev,
						[ conn.id ]: false,
					} ) );
				} );
		} );
	}, [] ); // eslint-disable-line react-hooks/exhaustive-deps

	const handlePull = async ( connId ) => {
		setPulling( connId );
		try {
			await api.switchBranch( item.id, item.branch, connId );
			toast.success(
				sprintf(
					/* translators: %s: repository full name */
					__( '%s updated to latest.', 'gitwire' ),
					item.full_name
				)
			);
			onRefresh();
			onClose();
		} catch ( e ) {
			toast.error( e.message || __( 'Pull failed.', 'gitwire' ) );
		} finally {
			setPulling( null );
		}
	};

	return (
		<Modal
			className="gitwire-modal"
			style={ { width: 480 } }
			title={
				<span className="gitwire-modal__title">
					{ __( 'Reconnect', 'gitwire' ) }{ ' ' }
					<span style={ { color: 'var(--gitwire-color-accent)' } }>
						{ item.full_name }
					</span>
				</span>
			}
			onRequestClose={ onClose }
		>
			<p style={ { marginTop: 0 } }>
				{ __(
					'The original connection for this repository was removed. Choose an account that has access to pull the latest code.',
					'gitwire'
				) }
			</p>

			{ providerConns.length === 0 && (
				<p style={ { color: '#57606a', fontSize: 13 } }>
					{ __(
						'No connections found for this provider. Go to Settings to add one.',
						'gitwire'
					) }
				</p>
			) }

			{ providerConns.map( ( conn ) => {
				const name =
					conn.username ||
					conn.identifier ||
					conn.email ||
					conn.label ||
					conn.id;
				const status = results[ conn.id ];
				const isTesting = status === undefined;
				const canPull = status === true;

				return (
					<Flex
						key={ conn.id }
						align="center"
						gap={ 2 }
						style={ { marginBottom: 12 } }
					>
						<span style={ { flex: 1, fontWeight: 500 } }>
							@{ name }
						</span>

						{ isTesting && <Spinner /> }

						{ ! isTesting && (
							<span
								className={ `gitwire-badge gitwire-badge--${
									canPull ? 'success' : 'warning'
								}` }
							>
								{ canPull
									? __( 'Accessible', 'gitwire' )
									: __( 'No Access', 'gitwire' ) }
							</span>
						) }

						{ canPull && (
							<Button
								disabled={ pulling !== null }
								isBusy={ pulling === conn.id }
								size="small"
								variant="primary"
								onClick={ () => handlePull( conn.id ) }
							>
								{ __( 'Pull with This Account', 'gitwire' ) }
							</Button>
						) }
					</Flex>
				);
			} ) }
		</Modal>
	);
}
