import { __ } from '@wordpress/i18n';
import { useState, useEffect } from '@wordpress/element';
import { Modal, Spinner } from '@wordpress/components';

import * as api from '../../api';

const commitsCache = new Map();

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

	useEffect( () => {
		if ( ! item || ! cacheKey ) {
			return;
		}
		if ( commitsCache.has( cacheKey ) ) {
			setCommits( commitsCache.get( cacheKey ) );
			return;
		}
		setCommits( null );
		let cancelled = false;
		api.getCommits( item.owner, item.repo, item.provider ?? 'github' )
			.then( ( data ) => {
				if ( cancelled ) {
					return;
				}
				commitsCache.set( cacheKey, data );
				setCommits( data );
			} )
			.catch( () => {
				if ( ! cancelled ) {
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

	return (
		<Modal
			className="gwp-modal"
			style={ { width: 560 } }
			title={
				<span className="gwp-modal__title">
					{ __( 'Commits', 'git' ) }{ ' ' }
					<span style={ { color: 'var(--gwp-color-accent)' } }>
						{ item.full_name }
					</span>
				</span>
			}
			onRequestClose={ onClose }
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
						<div key={ commit.sha } className="gwp-commit-row">
							<code className="gwp-commit-sha">
								{ commit.sha }
							</code>
							<div className="gwp-commit-body">
								<p className="gwp-commit-message">
									{ commit.message }
								</p>
								<p className="gwp-commit-meta">
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

export function clearCommitsCache( item ) {
	const cacheKey = ( item.provider ?? 'github' ) + ':' + item.full_name;
	commitsCache.delete( cacheKey );
}
