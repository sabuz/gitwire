import { __ } from '@wordpress/i18n';
import { useState, useEffect } from '@wordpress/element';
import { Flex, Modal, Spinner, Tooltip } from '@wordpress/components';

import * as api from '../../api';

const commitsCache = new Map();
const COMMITS_CACHE_TTL = 5 * 60 * 1000;

/**
 * Read-only modal showing the last 10 commits for an installed repository.
 *
 * @param {Object}   props         Component props.
 * @param {Object}   props.item    Installed repository record.
 * @param {Function} props.onClose Callback fired when the modal is closed.
 * @return {JSX.Element|null} The rendered commits modal.
 */
export default function CommitsModal( { item, onClose } ) {
	const cacheKey = item
		? ( item.provider ?? 'github' ) + ':' + item.full_name
		: '';
	const [ commits, setCommits ] = useState( null );
	const [ fetchError, setFetchError ] = useState( false );

	useEffect( () => {
		if ( ! item || ! cacheKey ) {
			return;
		}
		const cached = commitsCache.get( cacheKey );
		if ( cached && Date.now() < cached.expiresAt ) {
			setCommits( cached.data );
			setFetchError( false );
			return;
		}
		setCommits( null );
		setFetchError( false );
		let cancelled = false;
		api.getCommits( item.id )
			.then( ( data ) => {
				if ( cancelled ) {
					return;
				}
				commitsCache.set( cacheKey, {
					data,
					expiresAt: Date.now() + COMMITS_CACHE_TTL,
				} );
				setCommits( data );
			} )
			.catch( () => {
				if ( ! cancelled ) {
					setFetchError( true );
					setCommits( [] );
				}
			} );
		return () => {
			cancelled = true;
		};
	}, [ cacheKey, item ] );

	if ( ! item ) {
		return null;
	}

	const fatalSha = item.known_fatal_head || '';

	return (
		<Modal
			className="gitwire-modal"
			style={ { width: 560 } }
			title={
				<span className="gitwire-modal__title">
					{ __( 'Commits', 'gitwire' ) }{ ' ' }
					<span style={ { color: 'var(--gitwire-color-accent)' } }>
						{ item.full_name }
					</span>
				</span>
			}
			onRequestClose={ onClose }
		>
			{ commits === null && (
				<Flex justify="center" style={ { padding: '24px 0' } }>
					<Spinner />
				</Flex>
			) }
			{ commits !== null && commits.length === 0 && (
				<p style={ { color: '#57606a', fontSize: 13 } }>
					{ fetchError
						? __( 'Could not load commits.', 'gitwire' )
						: __( 'No commits found.', 'gitwire' ) }
				</p>
			) }
			{ commits !== null && commits.length > 0 && (
				<div style={ { marginBottom: 8 } }>
					{ commits.map( ( commit ) => {
						const hasFatalError =
							commit.has_fatal_error ||
							( fatalSha && commit.sha === fatalSha );

						return (
							<div
								key={ commit.sha }
								className="gitwire-commit-row"
							>
								{ hasFatalError ? (
									<Tooltip
										text={ __(
											'This commit caused a fatal error.',
											'gitwire'
										) }
									>
										<code className="gitwire-commit-sha is-fatal">
											{ commit.sha.slice( 0, 7 ) }
										</code>
									</Tooltip>
								) : (
									<code className="gitwire-commit-sha">
										{ commit.sha.slice( 0, 7 ) }
									</code>
								) }
								<div className="gitwire-commit-body">
									<p className="gitwire-commit-message">
										{ commit.message }
									</p>
									<p className="gitwire-commit-meta">
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
						);
					} ) }
				</div>
			) }
		</Modal>
	);
}

export function clearCommitsCache( item ) {
	const cacheKey = ( item.provider ?? 'github' ) + ':' + item.full_name;
	commitsCache.delete( cacheKey );
}
