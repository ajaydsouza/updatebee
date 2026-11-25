/**
 * Advanced card and drawer for plugin branding.
 */
import {
	TextareaControl,
	TextControl,
	ToggleControl,
} from '@wordpress/components';
import { useCallback, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { cog } from '@wordpress/icons';
import { Button, Card, Drawer, IconButton } from '@wordpress/ui';
import sendCommand from '../../../utils/SendCommand';
import type { SnackbarType } from './AdvancedFooter';

type BrandingDrawerProps = {
	disabled: boolean;
	onSavingChange: ( isSaving: boolean ) => void;
	onSnackbar: ( message: string, type: SnackbarType ) => void;
};

type BrandingPayload = {
	message?: string;
	name?: string;
	description?: string;
	author?: string;
	url?: string;
	hideLicenseTab?: boolean;
};

/**
 * Read a localized branding string.
 *
 * @param value Incoming value.
 * @return String value, or an empty string.
 */
const normalizeText = ( value: unknown ): string => {
	return 'string' === typeof value ? value : '';
};

/**
 * Plugin branding card with a gear that opens the settings drawer.
 *
 * @param props Card availability and save callbacks.
 */
const BrandingDrawer = ( props: BrandingDrawerProps ) => {
	const { disabled, onSavingChange, onSnackbar } = props;
	const defaults = updatebeeAdvanced.brandingDefaults ?? {
		name: '',
		description: '',
		author: '',
		url: '',
	};
	const [ isOpen, setIsOpen ] = useState( false );
	const [ isSaving, setIsSaving ] = useState( false );
	const [ savedName, setSavedName ] = useState(
		normalizeText( updatebeeAdvanced.brandingName )
	);
	const [ savedDescription, setSavedDescription ] = useState(
		normalizeText( updatebeeAdvanced.brandingDescription )
	);
	const [ savedAuthor, setSavedAuthor ] = useState(
		normalizeText( updatebeeAdvanced.brandingAuthor )
	);
	const [ savedUrl, setSavedUrl ] = useState(
		normalizeText( updatebeeAdvanced.brandingUrl )
	);
	const canHideLicenseTab = Boolean( updatebeeAdvanced.canHideLicenseTab );
	const [ savedHideLicenseTab, setSavedHideLicenseTab ] = useState(
		Boolean( updatebeeAdvanced.brandingHideLicenseTab )
	);
	const [ draftName, setDraftName ] = useState( savedName );
	const [ draftDescription, setDraftDescription ] =
		useState( savedDescription );
	const [ draftAuthor, setDraftAuthor ] = useState( savedAuthor );
	const [ draftUrl, setDraftUrl ] = useState( savedUrl );
	const [ draftHideLicenseTab, setDraftHideLicenseTab ] =
		useState( savedHideLicenseTab );

	/**
	 * Revert drawer drafts to the last saved values.
	 */
	const resetDraft = useCallback( () => {
		setDraftName( savedName );
		setDraftDescription( savedDescription );
		setDraftAuthor( savedAuthor );
		setDraftUrl( savedUrl );
		setDraftHideLicenseTab( savedHideLicenseTab );
	}, [
		savedAuthor,
		savedDescription,
		savedHideLicenseTab,
		savedName,
		savedUrl,
	] );

	/**
	 * Open or close the drawer and revert unsaved edits.
	 *
	 * @param nextOpen Next open state.
	 */
	const handleOpenChange = useCallback(
		( nextOpen: boolean ) => {
			if ( isSaving ) {
				return;
			}

			setIsOpen( nextOpen );
			if ( ! nextOpen ) {
				resetDraft();
			}
		},
		[ isSaving, resetDraft ]
	);

	/**
	 * Persist branding fields.
	 */
	const handleSave = useCallback( () => {
		if ( isSaving || disabled ) {
			return;
		}

		setIsSaving( true );
		onSavingChange( true );

		sendCommand< BrandingPayload >( 'updatebee_save_branding_settings', {
			nonce: updatebeeAdvanced.brandingSaveNonce ?? '',
			name: draftName.trim(),
			description: draftDescription.trim(),
			author: draftAuthor.trim(),
			url: draftUrl.trim(),
			hide_license_tab: canHideLicenseTab && draftHideLicenseTab,
		} )
			.then( ( response ) => {
				const payload = response.data.data;
				const nextName = normalizeText( payload.name );
				const nextDescription = normalizeText( payload.description );
				const nextAuthor = normalizeText( payload.author );
				const nextUrl = normalizeText( payload.url );
				setSavedName( nextName );
				setDraftName( nextName );
				setSavedDescription( nextDescription );
				setDraftDescription( nextDescription );
				setSavedAuthor( nextAuthor );
				setDraftAuthor( nextAuthor );
				setSavedUrl( nextUrl );
				setDraftUrl( nextUrl );
				const nextHide = Boolean( payload.hideLicenseTab );
				setSavedHideLicenseTab( nextHide );
				setDraftHideLicenseTab( nextHide );
				onSnackbar(
					payload.message ||
						__( 'Branding settings have been saved.', 'updatebee' ),
					'success'
				);
				setIsOpen( false );
			} )
			.catch( () => {
				onSnackbar(
					__( 'Branding settings could not be saved.', 'updatebee' ),
					'error'
				);
			} )
			.finally( () => {
				setIsSaving( false );
				onSavingChange( false );
			} );
	}, [
		disabled,
		draftAuthor,
		draftDescription,
		canHideLicenseTab,
		draftHideLicenseTab,
		draftName,
		draftUrl,
		isSaving,
		onSavingChange,
		onSnackbar,
	] );

	return (
		<Drawer.Root
			open={ isOpen }
			onOpenChange={ handleOpenChange }
			swipeDirection="right"
			disablePointerDismissal={ true }
		>
			<Card.Root className="updatebee-advanced-card">
				<Card.Header>
					<div className="updatebee-advanced-card-header">
						<Card.Title>
							{ __( 'Plugin branding', 'updatebee' ) }
						</Card.Title>
						<div className="updatebee-advanced-keywords-controls">
							<IconButton
								variant="outline"
								icon={ cog }
								label={ __( 'Plugin branding', 'updatebee' ) }
								disabled={ disabled || isSaving }
								onClick={ () => {
									if ( ! isSaving && ! disabled ) {
										resetDraft();
										setIsOpen( true );
									}
								} }
							/>
						</div>
					</div>
				</Card.Header>
				<Card.Content className="updatebee-advanced-card-content">
					<p className="updatebee-advanced-card-description">
						{ __(
							'Change how this plugin appears on the Plugins screen, in the admin header, and in emails and Slack alerts. Leave a field empty to use the default.',
							'updatebee'
						) }
					</p>
					{ '' !== savedName && (
						<p className="updatebee-advanced-keywords-preview">
							<strong>{ __( 'Shown as:', 'updatebee' ) }</strong>{ ' ' }
							{ savedName }
						</p>
					) }
					{ canHideLicenseTab && savedHideLicenseTab && (
						<p className="updatebee-advanced-keywords-preview">
							{ __( 'License tab hidden', 'updatebee' ) }
						</p>
					) }
				</Card.Content>
			</Card.Root>
			<Drawer.Popup
				size="medium"
				portal={
					<Drawer.Portal className="updatebee-advanced-branding-drawer-portal" />
				}
			>
				<Drawer.Header>
					<Drawer.Title>
						{ __( 'Plugin branding', 'updatebee' ) }
					</Drawer.Title>
				</Drawer.Header>
				<Drawer.Content>
					<Drawer.Description>
						{ __(
							'Change how this plugin appears on the Plugins screen, in the admin header, and in emails and Slack alerts. Leave a field empty to use the default.',
							'updatebee'
						) }
					</Drawer.Description>
					<div className="updatebee-advanced-keywords-fields">
						<TextControl
							label={ __( 'Plugin name', 'updatebee' ) }
							value={ draftName }
							placeholder={ defaults.name }
							onChange={ setDraftName }
							disabled={ isSaving }
						/>
						<TextareaControl
							label={ __( 'Description', 'updatebee' ) }
							value={ draftDescription }
							placeholder={ defaults.description }
							onChange={ setDraftDescription }
							disabled={ isSaving }
							rows={ 4 }
						/>
						<TextControl
							label={ __( 'Plugin author', 'updatebee' ) }
							value={ draftAuthor }
							placeholder={ defaults.author }
							onChange={ setDraftAuthor }
							disabled={ isSaving }
						/>
						<TextControl
							label={ __( 'Plugin URL', 'updatebee' ) }
							type="url"
							value={ draftUrl }
							placeholder={ defaults.url }
							onChange={ setDraftUrl }
							disabled={ isSaving }
						/>
						<ToggleControl
							label={ __(
								'Hide the License tab shown in the admin',
								'updatebee'
							) }
							checked={ canHideLicenseTab && draftHideLicenseTab }
							disabled={ isSaving || ! canHideLicenseTab }
							onChange={ ( checked: boolean ) => {
								if ( canHideLicenseTab ) {
									setDraftHideLicenseTab( checked );
								}
							} }
							help={
								canHideLicenseTab
									? __(
											'Removes the License tab shown in the admin while this license is active.',
											'updatebee'
										)
									: __(
											'Activate a license to remove the License tab shown in the admin.',
											'updatebee'
										)
							}
						/>
					</div>
				</Drawer.Content>
				<Drawer.Footer className="updatebee-advanced-keywords-footer">
					<Drawer.Action variant="outline" disabled={ isSaving }>
						{ __( 'Cancel', 'updatebee' ) }
					</Drawer.Action>
					<Button
						variant="solid"
						disabled={ isSaving || disabled }
						loading={ isSaving }
						loadingAnnouncement={ __(
							'Saving branding settings…',
							'updatebee'
						) }
						onClick={ handleSave }
					>
						{ __( 'Save', 'updatebee' ) }
					</Button>
				</Drawer.Footer>
			</Drawer.Popup>
		</Drawer.Root>
	);
};

export default BrandingDrawer;
