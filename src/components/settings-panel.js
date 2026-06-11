import { toast } from '../toast';

import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { applyFilters } from '@wordpress/hooks';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Flex,
	FlexBlock,
	FlexItem,
	SelectControl,
	TextControl,
	ToggleControl,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalHeading as Heading,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalSpacer as Spacer,
} from '@wordpress/components';

import * as api from '../api';

/**
 * Settings panel — Browse accounts card + Smart Install card + Logging card.
 *
 * @param {Object}   props          Component props.
 * @param {Object}   props.settings Saved plugin settings.
 * @param {Function} props.onSave   Called with updated settings after save.
 * @return {JSX.Element} The rendered settings panel.
 */
export default function SettingsPanel( { settings, onSave } ) {
	const [ smartInstall, setSmartInstall ] = useState(
		settings.smart_install !== false
	);
	const [ savingSi, setSavingSi ] = useState( false );
	const [ showRepoLabel, setShowRepoLabel ] = useState(
		settings.show_repo_label !== false
	);
	const [ enableLogging, setEnableLogging ] = useState(
		!! settings.enable_logging
	);
	const [ savingLog, setSavingLog ] = useState( false );
	const [ logRetentionDays, setLogRetentionDays ] = useState(
		String( settings.log_retention_days ?? 30 )
	);
	const [ logLevel, setLogLevel ] = useState(
		settings.log_level ?? 'activity'
	);
	const [ clearingLogs, setClearingLogs ] = useState( false );

	const saveSetting = ( payload, rollback ) => {
		const p = api
			.saveSettings( payload )
			.then( () => api.getSettings() )
			.then( ( saved ) => onSave( saved ) )
			.catch( ( e ) => {
				rollback?.();
				throw e;
			} );

		toast.promise( p, {
			id: 'settings-save',
			loading: __( 'Saving…', 'gitwire' ),
			success: __( 'Saved.', 'gitwire' ),
			error: ( e ) => e?.message || __( 'Save failed.', 'gitwire' ),
		} );

		return p;
	};

	const handleSmartInstallChange = ( newVal ) => {
		setSmartInstall( newVal );
		setSavingSi( true );
		saveSetting( { smart_install: newVal }, () =>
			setSmartInstall( ! newVal )
		)
			.finally( () => setSavingSi( false ) )
			.catch( () => {} );
	};

	const handleShowRepoLabelChange = ( newVal ) => {
		setShowRepoLabel( newVal );
		saveSetting( { show_repo_label: newVal }, () =>
			setShowRepoLabel( ! newVal )
		).catch( () => {} );
	};

	const handleEnableLoggingChange = ( newVal ) => {
		setEnableLogging( newVal );
		setSavingLog( true );
		// Reload on success so the WP admin sidebar reflects the updated Logs menu.
		const p = api
			.saveSettings( { enable_logging: newVal } )
			.then( () => window.location.reload() )
			.catch( ( e ) => {
				setEnableLogging( ! newVal );
				setSavingLog( false );
				throw e;
			} );
		toast.promise( p, {
			id: 'settings-save',
			loading: __( 'Saving…', 'gitwire' ),
			success: __( 'Saved.', 'gitwire' ),
			error: ( e ) => e?.message || __( 'Save failed.', 'gitwire' ),
		} );
	};

	const handleLogRetentionChange = ( newVal ) => {
		setLogRetentionDays( newVal );
		saveSetting( { log_retention_days: parseInt( newVal, 10 ) } ).catch(
			() => {}
		);
	};

	const handleClearLogs = () => {
		setClearingLogs( true );
		toast.promise(
			api.clearLogs().finally( () => setClearingLogs( false ) ),
			{
				loading: __( 'Clearing logs…', 'gitwire' ),
				success: __( 'Logs cleared.', 'gitwire' ),
				error: ( e ) =>
					e?.message || __( 'Could not clear logs.', 'gitwire' ),
			}
		);
	};

	const handleLogLevelChange = ( newVal ) => {
		setLogLevel( newVal );
		saveSetting( { log_level: newVal } ).catch( () => {} );
	};

	// Pro replaces the username-based Browse accounts with token connections
	const accountsSection = applyFilters(
		'gitwire.settings.accountsSection',
		<BrowseAccounts settings={ settings } onSave={ onSave } />,
		{ settings, onSave }
	);

	return (
		<div
			className="gitwire-settings-panels"
			style={ { maxWidth: 580, margin: '0 auto' } }
		>
			{ accountsSection }

			<Spacer marginTop={ 4 } />

			<Card>
				<CardHeader>
					<Heading level={ 4 }>
						{ __( 'Install', 'gitwire' ) }
					</Heading>
				</CardHeader>
				<CardBody>
					<ToggleControl
						__nextHasNoMarginBottom
						checked={ smartInstall }
						disabled={ savingSi }
						help={ __(
							'Only allow installing repositories detected as a WordPress plugin or theme.',
							'gitwire'
						) }
						label={
							<>
								{ __( 'Smart install', 'gitwire' ) }{ ' ' }
								<span
									className="gitwire-badge gitwire-badge--success"
									style={ { marginLeft: 4 } }
								>
									{ __( 'Recommended', 'gitwire' ) }
								</span>
							</>
						}
						onChange={ handleSmartInstallChange }
					/>
					<Spacer marginTop={ 4 } />
					<ToggleControl
						__nextHasNoMarginBottom
						checked={ showRepoLabel }
						help={ __(
							'Shows a [Gitwire] label next to managed plugin and theme names on the Plugins and Themes screens.',
							'gitwire'
						) }
						label={ __( 'Repo label', 'gitwire' ) }
						onChange={ handleShowRepoLabelChange }
					/>
				</CardBody>
			</Card>

			<Spacer marginTop={ 4 } />

			<Card>
				<CardHeader>
					<Heading level={ 4 }>
						{ __( 'Logging', 'gitwire' ) }
					</Heading>
				</CardHeader>
				<CardBody>
					<ToggleControl
						__nextHasNoMarginBottom
						checked={ enableLogging }
						disabled={ savingLog }
						help={ __(
							'Record installs, removals, activations, and connection changes to the Logs page.',
							'gitwire'
						) }
						label={ __( 'Enable logging', 'gitwire' ) }
						onChange={ handleEnableLoggingChange }
					/>
					{ enableLogging && (
						<>
							<Spacer marginTop={ 4 } />
							<SelectControl
								__next40pxDefaultSize
								__nextHasNoMarginBottom
								label={ __( 'Log Level', 'gitwire' ) }
								help={ __(
									'Errors only records failed operations. All activity includes installs, activations, and connections.',
									'gitwire'
								) }
								options={ [
									{
										label: __( 'All activity', 'gitwire' ),
										value: 'activity',
									},
									{
										label: __( 'Errors only', 'gitwire' ),
										value: 'error',
									},
								] }
								value={ logLevel }
								onChange={ handleLogLevelChange }
							/>
							<Spacer marginTop={ 4 } />
							<SelectControl
								__next40pxDefaultSize
								__nextHasNoMarginBottom
								label={ __( 'Log Retention', 'gitwire' ) }
								help={
									<>
										{ __(
											'Entries older than this are automatically removed.',
											'gitwire'
										) }{ ' ' }
										<Button
											disabled={ clearingLogs }
											isDestructive
											style={ { fontSize: 12 } }
											variant="link"
											onClick={ handleClearLogs }
										>
											{ __( 'Click here', 'gitwire' ) }
										</Button>{ ' ' }
										{ __(
											'to clear all logs now.',
											'gitwire'
										) }
									</>
								}
								options={ [
									{
										label: __( '7 days', 'gitwire' ),
										value: '7',
									},
									{
										label: __( '15 days', 'gitwire' ),
										value: '15',
									},
									{
										label: __( '30 days', 'gitwire' ),
										value: '30',
									},
								] }
								value={ logRetentionDays }
								onChange={ handleLogRetentionChange }
							/>
						</>
					) }
				</CardBody>
			</Card>
		</div>
	);
}

/**
 * Browse accounts card — public usernames per provider, no tokens.
 *
 * @param {Object}   props          Component props.
 * @param {Object}   props.settings Saved plugin settings.
 * @param {Function} props.onSave   Called with updated settings after save.
 * @return {JSX.Element} The rendered card.
 */
function BrowseAccounts( { settings, onSave } ) {
	const [ ghUsername, setGhUsername ] = useState(
		settings.github_username ?? ''
	);
	const [ glUsername, setGlUsername ] = useState(
		settings.gitlab_username ?? ''
	);
	const [ glUrl, setGlUrl ] = useState( settings.gitlab_url ?? '' );
	const [ bbWorkspace, setBbWorkspace ] = useState(
		settings.bitbucket_workspace ?? ''
	);
	const [ saving, setSaving ] = useState( false );

	const dirty =
		ghUsername !== ( settings.github_username ?? '' ) ||
		glUsername !== ( settings.gitlab_username ?? '' ) ||
		glUrl !== ( settings.gitlab_url ?? '' ) ||
		bbWorkspace !== ( settings.bitbucket_workspace ?? '' );

	const handleSave = () => {
		setSaving( true );
		const p = api
			.saveSettings( {
				github_username: ghUsername.trim(),
				gitlab_username: glUsername.trim(),
				gitlab_url: glUrl.trim(),
				bitbucket_workspace: bbWorkspace.trim(),
			} )
			.then( ( r ) => onSave( r.settings ) )
			.finally( () => setSaving( false ) );

		toast.promise( p, {
			id: 'settings-save',
			loading: __( 'Saving…', 'gitwire' ),
			success: __( 'Saved.', 'gitwire' ),
			error: ( e ) => e?.message || __( 'Save failed.', 'gitwire' ),
		} );
	};

	return (
		<Card>
			<CardHeader>
				<Flex align="center" gap={ 2 }>
					<FlexBlock>
						<Heading level={ 4 }>
							{ __( 'Browse Accounts', 'gitwire' ) }
						</Heading>
					</FlexBlock>
					<FlexItem>
						<Button
							disabled={ ! dirty || saving }
							isBusy={ saving }
							size="compact"
							variant="primary"
							onClick={ handleSave }
						>
							{ __( 'Save', 'gitwire' ) }
						</Button>
					</FlexItem>
				</Flex>
			</CardHeader>
			<CardBody>
				<p
					style={ {
						margin: '0 0 16px',
						color: '#757575',
						fontSize: 13,
					} }
				>
					{ __(
						'Public repositories only. No token needed.',
						'gitwire'
					) }{ ' ' }
					<a
						href="https://gitwire.app/pro"
						rel="noopener noreferrer"
						target="_blank"
					>
						{ __(
							'Gitwire Pro adds private repository access.',
							'gitwire'
						) }
					</a>
				</p>
				<TextControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					help={ __(
						'Your GitHub username or organization.',
						'gitwire'
					) }
					label={ __( 'GitHub Username', 'gitwire' ) }
					placeholder="your-github-username"
					value={ ghUsername }
					onChange={ setGhUsername }
				/>
				<Spacer marginTop={ 4 } />
				<TextControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					help={ __( 'Your GitLab username.', 'gitwire' ) }
					label={ __( 'GitLab Username', 'gitwire' ) }
					placeholder="your-gitlab-username"
					value={ glUsername }
					onChange={ setGlUsername }
				/>
				<Spacer marginTop={ 4 } />
				<TextControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					help={ __(
						'Leave blank for gitlab.com. Enter your instance URL for self-hosted GitLab.',
						'gitwire'
					) }
					label={
						<>
							{ __( 'GitLab Instance URL', 'gitwire' ) }{ ' ' }
							<span className="gitwire-label-optional">
								{ __( '(Optional)', 'gitwire' ) }
							</span>
						</>
					}
					placeholder="https://gitlab.com"
					value={ glUrl }
					onChange={ setGlUrl }
				/>
				<Spacer marginTop={ 4 } />
				<TextControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					help={ __(
						'The Bitbucket workspace slug to browse.',
						'gitwire'
					) }
					label={ __( 'Bitbucket Workspace', 'gitwire' ) }
					placeholder="your-workspace"
					value={ bbWorkspace }
					onChange={ setBbWorkspace }
				/>
			</CardBody>
		</Card>
	);
}
