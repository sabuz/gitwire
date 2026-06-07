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
	SelectControl,
	Spinner,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalHeading as Heading,
} from '@wordpress/components';

import * as api from '../api';

const LEVEL_LABELS = {
	activity: __( 'Activity', 'gitwire' ),
	error: __( 'Error', 'gitwire' ),
};

const LEVEL_COLORS = {
	activity: { background: '#e8f5e9', color: '#2e7d32' },
	error: { background: '#fdecea', color: '#c62828' },
};

function fromDateForRange( range ) {
	if ( 'all' === range ) return '';
	const d = new Date();
	if ( 'today' === range ) return d.toISOString().slice( 0, 10 );
	if ( '7d' === range ) {
		d.setDate( d.getDate() - 7 );
		return d.toISOString().slice( 0, 10 );
	}
	if ( '30d' === range ) {
		d.setDate( d.getDate() - 30 );
		return d.toISOString().slice( 0, 10 );
	}
	return '';
}

function LevelBadge( { level } ) {
	const style = LEVEL_COLORS[ level ] ?? LEVEL_COLORS.activity;
	return (
		<span
			style={ {
				...style,
				display: 'inline-block',
				padding: '1px 7px',
				borderRadius: 10,
				fontSize: 11,
				fontWeight: 600,
				lineHeight: '18px',
				letterSpacing: '0.02em',
				textTransform: 'uppercase',
				flexShrink: 0,
			} }
		>
			{ LEVEL_LABELS[ level ] ?? level }
		</span>
	);
}

/**
 * @param {Object}   props              Component props.
 * @param {Object}   props.settings     Current plugin settings.
 * @param {Function} props.onGoToSettings Called when user clicks the Settings link.
 */
export default function LogsPanel( { settings, onGoToSettings } ) {
	const [ entries, setEntries ] = useState( null );
	const [ loading, setLoading ] = useState( true );
	const [ clearing, setClearing ] = useState( false );
	const [ levelFilter, setLevelFilter ] = useState( '' );
	const [ dateRange, setDateRange ] = useState( 'all' );

	const fetchLogs = useCallback(
		async ( level, range ) => {
			setLoading( true );
			try {
				const result = await api.getLogs( {
					from: fromDateForRange( range ),
					level,
				} );
				setEntries( result.entries ?? [] );
			} catch ( e ) {
				toast.error( e.message || __( 'Could not load logs.', 'gitwire' ) );
			} finally {
				setLoading( false );
			}
		},
		[]
	);

	useEffect( () => {
		fetchLogs( levelFilter, dateRange );
	}, [ fetchLogs, levelFilter, dateRange ] );

	const handleClear = async () => {
		setClearing( true );
		try {
			await api.clearLogs();
			setEntries( [] );
			toast.success( __( 'Logs cleared.', 'gitwire' ) );
		} catch ( e ) {
			toast.error( e.message || __( 'Could not clear logs.', 'gitwire' ) );
		} finally {
			setClearing( false );
		}
	};

	const loggingEnabled =
		settings?.enable_logging !== false && !! settings?.enable_logging;
	const hasEntries = entries && entries.length > 0;

	return (
		<div style={ { maxWidth: 760, margin: '0 auto' } }>
			<Card>
				<CardHeader>
					<Flex align="center" gap={ 2 }>
						<FlexBlock>
							<Heading level={ 4 }>
								{ __( 'Activity Log', 'gitwire' ) }
							</Heading>
						</FlexBlock>
						{ hasEntries && (
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

					{ loggingEnabled && (
						<>
							<Flex
								align="flex-end"
								gap={ 3 }
								style={ { marginBottom: 16 } }
								wrap
							>
								<FlexItem>
									<SelectControl
										__nextHasNoMarginBottom
										label={ __( 'Level', 'gitwire' ) }
										options={ [
											{
												label: __( 'All levels', 'gitwire' ),
												value: '',
											},
											{
												label: __( 'Activity', 'gitwire' ),
												value: 'activity',
											},
											{
												label: __( 'Errors', 'gitwire' ),
												value: 'error',
											},
										] }
										value={ levelFilter }
										onChange={ setLevelFilter }
									/>
								</FlexItem>
								<FlexItem>
									<SelectControl
										__nextHasNoMarginBottom
										label={ __( 'Date range', 'gitwire' ) }
										options={ [
											{
												label: __( 'All time', 'gitwire' ),
												value: 'all',
											},
											{
												label: __( 'Today', 'gitwire' ),
												value: 'today',
											},
											{
												label: __( 'Last 7 days', 'gitwire' ),
												value: '7d',
											},
											{
												label: __(
													'Last 30 days',
													'gitwire'
												),
												value: '30d',
											},
										] }
										value={ dateRange }
										onChange={ setDateRange }
									/>
								</FlexItem>
							</Flex>

							{ loading && (
								<div
									style={ {
										display: 'flex',
										justifyContent: 'center',
										padding: '24px 0',
									} }
								>
									<Spinner />
								</div>
							) }

							{ ! loading && ! hasEntries && (
								<p style={ { color: '#757575', margin: 0 } }>
									{ __( 'No entries found.', 'gitwire' ) }
								</p>
							) }

							{ ! loading && hasEntries && (
								<div
									style={ {
										border: '1px solid #ddd',
										borderRadius: 2,
										overflow: 'hidden',
									} }
								>
									{ entries.map( ( entry, i ) => (
										<div
											key={ i }
											style={ {
												display: 'flex',
												alignItems: 'flex-start',
												gap: 10,
												padding: '8px 12px',
												borderBottom:
													i < entries.length - 1
														? '1px solid #f0f0f0'
														: 'none',
												background:
													i % 2 === 0
														? '#fff'
														: '#fafafa',
											} }
										>
											<span
												style={ {
													fontFamily: 'monospace',
													fontSize: 12,
													color: '#888',
													flexShrink: 0,
													lineHeight: '20px',
													whiteSpace: 'nowrap',
												} }
											>
												{ entry.timestamp }
											</span>
											<LevelBadge level={ entry.level } />
											<span
												style={ {
													fontFamily: 'monospace',
													fontSize: 12,
													color: '#1d2327',
													lineHeight: '20px',
													wordBreak: 'break-all',
												} }
											>
												{ entry.message }
											</span>
										</div>
									) ) }
								</div>
							) }
						</>
					) }
				</CardBody>
			</Card>
		</div>
	);
}
