/**
 * License key, status, and lifetime upsell.
 */
import { TextControl } from '@wordpress/components';
import { useCallback, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button, Card, useEnableWpCompatOverlaySlot } from '@wordpress/ui';
import type { SnackbarType } from '../Advanced/AdvancedFooter';
import sendCommand, { WpCommandError } from '../../../utils/SendCommand';
import LicenseFooter from './LicenseFooter';

type LicenseView = {
	has_license: boolean;
	item_name: string;
	variation_name: string;
	status: string;
	status_label: string;
	status_tone: string;
	is_lifetime: boolean;
	is_active: boolean;
	activations: string;
	expires_on: string;
	days_left: string;
	show_upsell: boolean;
	upsell_url: string;
	renewal_url: string;
};

type LicensePayload = {
	message?: string;
	license?: LicenseView;
};

type SnackbarState = {
	isVisible: boolean;
	message: string;
	type: SnackbarType;
};

/**
 * Read the localized license record.
 *
 * @return License table fields.
 */
const initialLicense = (): LicenseView => {
	const license = updatebeeLicense.license;
	return {
		has_license: Boolean( license.has_license ),
		item_name: license.item_name,
		variation_name:
			'string' === typeof license.variation_name
				? license.variation_name
				: '',
		status: license.status,
		status_label: license.status_label,
		status_tone: license.status_tone,
		is_lifetime: Boolean( license.is_lifetime ),
		is_active: Boolean( license.is_active ),
		activations: license.activations,
		expires_on: license.expires_on,
		days_left: license.days_left,
		show_upsell: Boolean( license.show_upsell ),
		upsell_url:
			'string' === typeof license.upsell_url && '' !== license.upsell_url
				? license.upsell_url
				: updatebeeLicense.upsellUrl,
		renewal_url:
			'string' === typeof license.renewal_url ? license.renewal_url : '',
	};
};

const LicenseMain = () => {
	useEnableWpCompatOverlaySlot();
	const [ licenseKey, setLicenseKey ] = useState( '' );
	const [ license, setLicense ] = useState< LicenseView >( initialLicense );
	const [ isSaving, setIsSaving ] = useState( false );
	const [ isRevoking, setIsRevoking ] = useState( false );
	const [ snackbar, setSnackbar ] = useState< SnackbarState >( {
		isVisible: false,
		message: '',
		type: 'success',
	} );

	const isBusy = isSaving || isRevoking;
	const keyDefined = Boolean( updatebeeLicense.keyDefined );

	const showSnackbar = useCallback(
		( message: string, type: SnackbarType ) => {
			setSnackbar( {
				isVisible: true,
				message,
				type,
			} );
		},
		[]
	);

	const handleSnackbarClose = useCallback( () => {
		setSnackbar( ( current ) => ( {
			...current,
			isVisible: false,
		} ) );
	}, [] );

	/**
	 * Apply a license payload from admin-ajax.
	 *
	 * @param payload Response data.
	 */
	const applyLicense = ( payload: LicensePayload | undefined ) => {
		if ( ! payload?.license ) {
			return;
		}

		setLicense( {
			...payload.license,
			has_license: Boolean( payload.license.has_license ),
			is_lifetime: Boolean( payload.license.is_lifetime ),
			is_active: Boolean( payload.license.is_active ),
			show_upsell: Boolean( payload.license.show_upsell ),
			upsell_url:
				payload.license.upsell_url || updatebeeLicense.upsellUrl,
			renewal_url: payload.license.renewal_url || '',
		} );
	};

	/**
	 * Save a new key, or recheck the stored key when the field is empty.
	 */
	const handleSave = useCallback( () => {
		if ( isBusy ) {
			return;
		}

		setIsSaving( true );
		sendCommand< LicensePayload >( 'updatebee_activate_license', {
			nonce: updatebeeLicense.activateNonce,
			license_key: keyDefined ? '' : licenseKey.trim(),
		} )
			.then( ( response ) => {
				const payload = response.data.data;
				applyLicense( payload );
				setLicenseKey( '' );
				showSnackbar(
					payload.message ||
						__( 'License activated successfully.', 'updatebee' ),
					'expired' === payload.license?.status
						? 'warning'
						: 'success'
				);
			} )
			.catch( ( error: unknown ) => {
				if ( error instanceof WpCommandError ) {
					applyLicense( error.data as LicensePayload | undefined );
				}
				const message = error instanceof Error ? error.message : '';
				showSnackbar(
					message ||
						__(
							'License activation failed. Please check your license key.',
							'updatebee'
						),
					'error'
				);
			} )
			.finally( () => {
				setIsSaving( false );
			} );
	}, [ isBusy, keyDefined, licenseKey, showSnackbar ] );

	/**
	 * Remove the license from this site.
	 */
	const handleRevoke = useCallback( () => {
		if ( isBusy || ! license.has_license ) {
			return;
		}

		setIsRevoking( true );
		sendCommand< LicensePayload >( 'updatebee_revoke_license', {
			nonce: updatebeeLicense.revokeNonce,
		} )
			.then( ( response ) => {
				const payload = response.data.data;
				applyLicense( payload );
				setLicenseKey( '' );
				showSnackbar(
					payload.message || __( 'License revoked.', 'updatebee' ),
					'success'
				);
			} )
			.catch( ( error: unknown ) => {
				const message = error instanceof Error ? error.message : '';
				showSnackbar(
					message ||
						__( 'The license could not be revoked.', 'updatebee' ),
					'error'
				);
			} )
			.finally( () => {
				setIsRevoking( false );
			} );
	}, [ isBusy, license.has_license, showSnackbar ] );

	const statusClass = `updatebee-license-status is-${ license.status_tone }`;

	return (
		<div className="updatebee-license-main">
			<Card.Root className="updatebee-license-card">
				<Card.Header>
					<Card.Title>
						{ __( 'License Key', 'updatebee' ) }
					</Card.Title>
				</Card.Header>
				<Card.Content>
					<p className="updatebee-license-card-description">
						{ keyDefined
							? __(
									'This license key is set in wp-config.php or an mu-plugin.',
									'updatebee'
								)
							: __(
									'A valid license key is required for access to automatic plugin upgrades and product support.',
									'updatebee'
								) }
					</p>
					{ ! keyDefined && (
						<TextControl
							label={ __(
								'License Key (Required)',
								'updatebee'
							) }
							type="password"
							value={ licenseKey }
							placeholder={
								license.has_license
									? '••••••••••••••••'
									: __( 'Your license key', 'updatebee' )
							}
							onChange={ ( value ) => {
								setLicenseKey( value );
							} }
							help={ __(
								'The saved key stays hidden. Leave this field blank to check the key already stored on this site.',
								'updatebee'
							) }
							disabled={ isBusy || ! updatebeeLicense.configured }
							autoComplete="off"
						/>
					) }
					<p className="updatebee-license-actions">
						<Button
							variant="solid"
							loading={ isSaving }
							loadingAnnouncement={ __(
								'Checking license…',
								'updatebee'
							) }
							disabled={ isBusy || ! updatebeeLicense.configured }
							onClick={ handleSave }
						>
							{ keyDefined
								? __( 'Check License', 'updatebee' )
								: __( 'Save and Check License', 'updatebee' ) }
						</Button>
						{ ! keyDefined && (
							<span className="updatebee-license-revoke">
								<Button
									loading={ isRevoking }
									loadingAnnouncement={ __(
										'Revoking license…',
										'updatebee'
									) }
									disabled={
										isBusy ||
										! license.has_license ||
										! updatebeeLicense.configured
									}
									onClick={ handleRevoke }
								>
									{ __( 'Revoke License', 'updatebee' ) }
								</Button>
							</span>
						) }
					</p>
				</Card.Content>
			</Card.Root>

			{ ! license.is_active && (
				<Card.Root className="updatebee-license-card">
					<Card.Content>
						<div className="updatebee-license-get-started">
							<a
								className="updatebee-license-upsell-link"
								href="https://updatebee.com/purchase/updatebee/"
								target="_blank"
								rel="noopener noreferrer"
							>
								{ __( 'Purchase a License', 'updatebee' ) }
							</a>
							<a
								className="updatebee-license-account-link"
								href="https://updatebee.com/account/"
								target="_blank"
								rel="noopener noreferrer"
							>
								{ __( 'Log in to Your Account', 'updatebee' ) }
							</a>
						</div>
					</Card.Content>
				</Card.Root>
			) }

			{ license.has_license && (
				<Card.Root className="updatebee-license-card">
					<Card.Header>
						<Card.Title>
							{ __( 'Your License Details', 'updatebee' ) }
						</Card.Title>
					</Card.Header>
					<Card.Content>
						<table className="updatebee-license-details">
							<thead>
								<tr>
									<th>{ __( 'Item Name', 'updatebee' ) }</th>
									<th>
										{ __( 'License Status', 'updatebee' ) }
									</th>
									<th>
										{ __(
											'License Activations',
											'updatebee'
										) }
									</th>
									<th>{ __( 'Expires On', 'updatebee' ) }</th>
									{ ! license.is_lifetime && (
										<th>
											{ __( 'Days Left', 'updatebee' ) }
										</th>
									) }
								</tr>
							</thead>
							<tbody>
								<tr>
									<td>
										<span className="updatebee-license-item-name">
											{ license.item_name }
										</span>
										{ '' !== license.variation_name && (
											<span className="updatebee-license-variation-name">
												{ license.variation_name }
											</span>
										) }
									</td>
									<td>
										<span className="updatebee-license-pills">
											<span className={ statusClass }>
												{ license.status_label }
											</span>
											{ license.is_lifetime && (
												<span className="updatebee-license-plan">
													{ __(
														'Lifetime',
														'updatebee'
													) }
												</span>
											) }
										</span>
									</td>
									<td>{ license.activations }</td>
									<td>{ license.expires_on }</td>
									{ ! license.is_lifetime && (
										<td>{ license.days_left }</td>
									) }
								</tr>
							</tbody>
						</table>
						{ '' !== license.renewal_url &&
							( 'expired' === license.status ||
								'warning' === license.status_tone ) && (
								<p className="updatebee-license-renewal">
									<a
										href={ license.renewal_url }
										target="_blank"
										rel="noopener noreferrer"
									>
										{ __( 'Renew License', 'updatebee' ) }
									</a>
								</p>
							) }
					</Card.Content>
				</Card.Root>
			) }

			{ license.show_upsell && (
				<Card.Root className="updatebee-license-card">
					<Card.Header>
						<Card.Title>
							{ __(
								'Upgrade to a lifetime license and get up to 250 sites',
								'updatebee'
							) }
						</Card.Title>
					</Card.Header>
					<Card.Content>
						<p className="updatebee-license-card-description">
							{ __(
								'Your license will never expire and you will never need to renew it.',
								'updatebee'
							) }
						</p>
						<a
							className="updatebee-license-upsell-link"
							href={ 'https://updatebee.com/checkout/lifetime/' }
							target="_blank"
							rel="noopener noreferrer"
						>
							{ __( 'Upgrade To Lifetime Today', 'updatebee' ) }
						</a>
					</Card.Content>
				</Card.Root>
			) }

			<LicenseFooter
				snackbar={ snackbar }
				onSnackbarClose={ handleSnackbarClose }
			/>
		</div>
	);
};

export default LicenseMain;
