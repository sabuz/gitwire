import { toast } from '../toast';

import { __ } from '@wordpress/i18n';
import { useState, useEffect, useCallback } from '@wordpress/element';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Flex,
	FlexBlock,
	FlexItem,
	Spinner,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalHeading as Heading,
} from '@wordpress/components';

import * as api from '../api';

/**
 * @param {Object}   props            Component props.
 * @param {Object}   props.settings   Current plugin settings (used to check enable_logging).
 * @param {Function} props.onGoToSettings Called when user clicks the Settings link.
 */
export default function LogsPanel( { settings, onGoToSettings } ) {
	const [ logs, setLogs ] = useState( null );
	const [ loading, setLoading ] = useState( true );
	const [ clearing, setClearing ] = useState( false );

	const fetchLogs = useCallback( async () => {
		setLoading( true );
		try {
			const result = await api.getLogs();
			setLogs( result.logs ?? '' );
		} catch ( e ) {
			toast.error( e.message || __( 'Could not load logs.', 'gitwire' ) );
		} finally {
			setLoading( false );
		}
	}, [] );

	useEffect( () => {
		fetchLogs();
	}, [ fetchLogs ] );

	const handleClear = async () => {
		setClearing( true );
		try {
			await api.clearLogs();
			setLogs( '' );
			toast.success( __( 'Logs cleared.', 'gitwire' ) );
		} catch ( e ) {
			toast.error( e.message || __( 'Could not clear logs.', 'gitwire' ) );
		} finally {
			setClearing( false );
		}
	};

	const loggingEnabled = settings?.enable_logging !== false && !! settings?.enable_logging;

	return (
		<div style={ { maxWidth: 720, margin: '0 auto' } }>
			<Card>
				<CardHeader>
					<Flex align="center" gap={ 2 }>
						<FlexBlock>
							<Heading level={ 4 }>
								{ __( 'Activity Log', 'gitwire' ) }
							</Heading>
						</FlexBlock>
						{ logs && (
							<FlexItem>
								<Button
									disabled={ clearing }
									isBusy={ clearing }
									isDestructive
									size="compact"
									variant="secondary"
									onClick={ handleClear }
								>
									{ __( 'Clear log', 'gitwire' ) }
								</Button>
							</FlexItem>
						) }
					</Flex>
				</CardHeader>
				<CardBody>
					{ ! loggingEnabled && (
						<p style={ { color: '#757575', margin: 0 } }>
							{ __( 'Logging is disabled.', 'gitwire' ) }{ ' ' }
							<button
								className="button-link"
								style={ {
									color: 'var(--wp-admin-theme-color, #3858e9)',
									cursor: 'pointer',
									background: 'none',
									border: 0,
									padding: 0,
									font: 'inherit',
								} }
								type="button"
								onClick={ onGoToSettings }
							>
								{ __( 'Enable it in Settings', 'gitwire' ) }
							</button>{ ' ' }
							{ __( 'to start recording activity.', 'gitwire' ) }
						</p>
					) }

					{ loggingEnabled && loading && (
						<div style={ { display: 'flex', justifyContent: 'center', padding: '24px 0' } }>
							<Spinner />
						</div>
					) }

					{ loggingEnabled && ! loading && ! logs && (
						<p style={ { color: '#757575', margin: 0 } }>
							{ __( 'No activity logged yet.', 'gitwire' ) }
						</p>
					) }

					{ loggingEnabled && ! loading && !! logs && (
						<textarea
							readOnly
							style={ {
								width: '100%',
								minHeight: 360,
								fontFamily: 'monospace',
								fontSize: 12,
								lineHeight: 1.6,
								padding: '10px 12px',
								border: '1px solid #ddd',
								borderRadius: 2,
								resize: 'vertical',
								background: '#f6f7f7',
								color: '#1d2327',
								boxSizing: 'border-box',
							} }
							value={ logs }
						/>
					) }
				</CardBody>
			</Card>
		</div>
	);
}
