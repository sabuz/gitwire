import { toast } from '../toast';

import { __ } from '@wordpress/i18n';
import { useState, useEffect, useCallback, useRef } from '@wordpress/element';
import { cog } from '@wordpress/icons';
import {
	Button,
	Flex,
	FormTokenField,
	Popover,
	Spinner,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalSpacer as Spacer,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalToggleGroupControl as ToggleGroupControl,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalToggleGroupControlOption as ToggleGroupControlOption,
} from '@wordpress/components';

import * as api from '../api';

const LEVEL_LABELS = {
	activity: __( 'Activity', 'gitwire' ),
	error: __( 'Error', 'gitwire' ),
};

const LEVEL_BADGE_MOD = {
	activity: 'success',
	error: 'error',
};

const LEVEL_OPTIONS = [
	{ label: __( 'All', 'gitwire' ), value: '' },
	{ label: __( 'Activity', 'gitwire' ), value: 'activity' },
	{ label: __( 'Errors', 'gitwire' ), value: 'error' },
];

const DATE_OPTIONS = [
	{ label: __( 'Today', 'gitwire' ), value: 'today' },
	{ label: __( 'Last 7 days', 'gitwire' ), value: '7d' },
	{ label: __( 'Last 30 days', 'gitwire' ), value: '30d' },
];

function fromDateForRange( range ) {
	if ( 'all' === range ) {
		return '';
	}
	const d = new Date();
	if ( 'today' === range ) {
		return d.toISOString().slice( 0, 10 );
	}
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
	const mod = LEVEL_BADGE_MOD[ level ] ?? 'neutral';
	return (
		<span className={ `gitwire-badge gitwire-badge--${ mod }` }>
			{ LEVEL_LABELS[ level ] ?? level }
		</span>
	);
}

/**
 * @param {Object}   props                Component props.
 * @param {Object}   props.settings       Current plugin settings.
 * @param {Function} props.onGoToSettings Called when user clicks the Settings link.
 */
export default function LogsPanel( { settings, onGoToSettings } ) {
	const [ entries, setEntries ] = useState( null );
	const [ loading, setLoading ] = useState( true );
	const [ levelFilter, setLevelFilter ] = useState( '' );
	const [ dateRange, setDateRange ] = useState( 'today' );
	const [ isFilterOpen, setIsFilterOpen ] = useState( false );
	const skipCloseRef = useRef( false );
	const [ userFilter, setUserFilter ] = useState( [] );
	const [ actorSuggestions, setActorSuggestions ] = useState( [] );

	const loggingEnabled =
		settings?.enable_logging !== false && !! settings?.enable_logging;

	const fetchLogs = useCallback( async ( level, range, actors ) => {
		setLoading( true );
		try {
			const result = await api.getLogs( {
				from: fromDateForRange( range ),
				level,
				actors,
			} );
			setEntries( result.entries ?? [] );
		} catch ( e ) {
			toast.error( e.message || __( 'Could not load logs.', 'gitwire' ) );
		} finally {
			setLoading( false );
		}
	}, [] );

	useEffect( () => {
		fetchLogs( levelFilter, dateRange, userFilter );
	}, [ fetchLogs, levelFilter, dateRange, userFilter ] );

	useEffect( () => {
		if ( ! loggingEnabled ) {
			return;
		}
		api.getLogActors( '' )
			.then( setActorSuggestions )
			.catch( () => {} );
	}, [ loggingEnabled ] );

	const handleActorInputChange = useCallback( async ( text ) => {
		try {
			const logins = await api.getLogActors( text );
			setActorSuggestions( logins );
		} catch ( _ ) {}
	}, [] );

	const hasEntries = entries && entries.length > 0;
	const hasActiveFilter =
		'' !== levelFilter || 'today' !== dateRange || userFilter.length > 0;

	return (
		<div style={ { maxWidth: 1600, margin: '0 auto' } }>
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

			{ loggingEnabled && ( hasEntries || hasActiveFilter ) && (
				<div
					style={ {
						display: 'flex',
						justifyContent: 'flex-end',
						marginBottom: 16,
						position: 'relative',
					} }
				>
					<Button
						icon={ cog }
						label={ __( 'View options', 'gitwire' ) }
						showTooltip
						size="compact"
						onMouseDown={ () => {
							if ( isFilterOpen ) {
								skipCloseRef.current = true;
							}
						} }
						onClick={ () => setIsFilterOpen( ( v ) => ! v ) }
					/>
					{ isFilterOpen && (
						<Popover
							offset={ 8 }
							placement="bottom-end"
							onClose={ () => setIsFilterOpen( false ) }
							onFocusOutside={ () => {
								if ( skipCloseRef.current ) {
									skipCloseRef.current = false;
									return;
								}
								setIsFilterOpen( false );
							} }
						>
							<div style={ { padding: '16px', minWidth: 348 } }>
								<FormTokenField
									__nextHasNoMarginBottom
									__next40pxDefaultSize
									label={ __( 'User', 'gitwire' ) }
									placeholder={ __( 'All users', 'gitwire' ) }
									suggestions={ actorSuggestions }
									value={ userFilter }
									onChange={ setUserFilter }
									onInputChange={ handleActorInputChange }
								/>
								<Spacer marginTop={ 4 } />
								<ToggleGroupControl
									__nextHasNoMarginBottom
									isBlock
									label={ __( 'Level', 'gitwire' ) }
									value={ levelFilter }
									onChange={ setLevelFilter }
								>
									{ LEVEL_OPTIONS.map( ( opt ) => (
										<ToggleGroupControlOption
											key={ opt.value }
											label={ opt.label }
											value={ opt.value }
										/>
									) ) }
								</ToggleGroupControl>
								<Spacer marginTop={ 4 } />
								<ToggleGroupControl
									__nextHasNoMarginBottom
									isBlock
									label={ __( 'Date range', 'gitwire' ) }
									value={ dateRange }
									onChange={ setDateRange }
								>
									{ DATE_OPTIONS.map( ( opt ) => (
										<ToggleGroupControlOption
											key={ opt.value }
											label={ opt.label }
											value={ opt.value }
										/>
									) ) }
								</ToggleGroupControl>
							</div>
						</Popover>
					) }
				</div>
			) }

			{ loggingEnabled && loading && (
				<Flex justify="center" style={ { padding: '24px 0' } }>
					<Spinner />
				</Flex>
			) }

			{ loggingEnabled && ! loading && ! hasEntries && (
				<p style={ { color: '#757575', margin: 0 } }>
					{ __( 'No entries found.', 'gitwire' ) }
				</p>
			) }

			{ loggingEnabled && ! loading && hasEntries && (
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
								background: i % 2 === 0 ? '#fff' : '#fafafa',
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
							<span
								style={ {
									display: 'flex',
									alignItems: 'center',
									gap: 6,
									flexShrink: 0,
									lineHeight: '20px',
									whiteSpace: 'nowrap',
								} }
							>
								{ entry.actor && (
									<span
										style={ {
											fontFamily: 'monospace',
											fontSize: 12,
											color: '#555',
										} }
									>
										{ entry.actor }
									</span>
								) }
								{ entry.actor && (
									<span
										style={ {
											color: '#bbb',
											fontSize: 12,
										} }
									>
										~
									</span>
								) }
								<LevelBadge level={ entry.level } />
							</span>
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
		</div>
	);
}
