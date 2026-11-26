/**
 * Logs export drawer with CSV filters and date range.
 */
import {
	CheckboxControl,
	SelectControl,
	TextControl,
} from '@wordpress/components';
import { useCallback, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button, Drawer } from '@wordpress/ui';

type LogsExportDrawerProps = {
	disabled: boolean;
};

type UpdateOfferFilter = 'all' | 'automatic' | 'manual';
type UpdateAssetType = 'plugin' | 'theme' | 'translation' | 'core';
type UpdateStatusFilter =
	| 'all'
	| 'success'
	| 'security'
	| 'failure'
	| 'blocked'
	| 'skipped'
	| 'delayed';

type ExportFilters = {
	updateOffer: UpdateOfferFilter;
	updateTypes: Record< UpdateAssetType, boolean >;
	updateStatus: UpdateStatusFilter;
	dateFrom: string;
	dateTo: string;
};

const assetTypeOrder: UpdateAssetType[] = [
	'plugin',
	'theme',
	'translation',
	'core',
];

const defaultUpdateTypes: Record< UpdateAssetType, boolean > = {
	plugin: true,
	theme: true,
	translation: true,
	core: true,
};

/**
 * Format a Date as a Y-m-d string for date inputs.
 *
 * @param date Date to format.
 * @return Date string in Y-m-d format.
 */
const formatDateInput = ( date: Date ): string => {
	const year = date.getFullYear();
	const month = String( date.getMonth() + 1 ).padStart( 2, '0' );
	const day = String( date.getDate() ).padStart( 2, '0' );

	return `${ year }-${ month }-${ day }`;
};

const today = new Date();
const dateFromDefault = new Date( today );
dateFromDefault.setDate( dateFromDefault.getDate() - 60 );

const defaultFilters: ExportFilters = {
	updateOffer: 'all',
	updateTypes: defaultUpdateTypes,
	updateStatus: 'all',
	dateFrom: formatDateInput( dateFromDefault ),
	dateTo: formatDateInput( today ),
};

/**
 * Build the admin-ajax CSV download URL.
 *
 * @param filters Selected export filters.
 * @return Download URL.
 */
const getSelectedAssetTypes = (
	updateTypes: Record< UpdateAssetType, boolean >
): UpdateAssetType[] => {
	return assetTypeOrder.filter( ( type ) => updateTypes[ type ] );
};

const getExportUrl = ( filters: ExportFilters ): string => {
	const params = new URLSearchParams( {
		action: 'updatebee_export_logs',
		nonce: updatebeeLogs.exportNonce,
		update_offer: filters.updateOffer,
		update_status: filters.updateStatus,
		date_from: filters.dateFrom,
		date_to: filters.dateTo,
	} );

	const selected = getSelectedAssetTypes( filters.updateTypes );
	if ( selected.length < assetTypeOrder.length ) {
		selected.forEach( ( type ) => {
			params.append( 'update_type[]', type );
		} );
	}

	return `${ ajaxurl }?${ params.toString() }`;
};

/**
 * Export logs drawer and trigger button.
 *
 * @param props Drawer availability.
 */
const LogsExportDrawer = ( props: LogsExportDrawerProps ) => {
	const { disabled } = props;
	const [ isOpen, setIsOpen ] = useState( false );
	const [ filters, setFilters ] = useState< ExportFilters >( defaultFilters );
	const [ dateError, setDateError ] = useState( '' );
	const [ typeError, setTypeError ] = useState( '' );

	/**
	 * Reset filters when the drawer closes.
	 *
	 * @param nextOpen Next open state.
	 */
	const handleOpenChange = useCallback( ( nextOpen: boolean ) => {
		setIsOpen( nextOpen );
		if ( ! nextOpen ) {
			setFilters( defaultFilters );
			setDateError( '' );
			setTypeError( '' );
		}
	}, [] );

	/**
	 * Update a single export filter.
	 *
	 * @param next Partial filter values.
	 */
	const handleFilterChange = useCallback(
		( next: Partial< ExportFilters > ) => {
			setFilters( ( current ) => ( {
				...current,
				...next,
			} ) );
			setDateError( '' );
			setTypeError( '' );
		},
		[]
	);

	/**
	 * Toggle one asset type checkbox.
	 *
	 * @param type    Asset type key.
	 * @param checked Whether the type is included.
	 */
	const handleTypeChange = useCallback(
		( type: UpdateAssetType, checked: boolean ) => {
			setFilters( ( current ) => ( {
				...current,
				updateTypes: {
					...current.updateTypes,
					[ type ]: checked,
				},
			} ) );
			setDateError( '' );
			setTypeError( '' );
		},
		[]
	);

	/**
	 * Validate dates and start the CSV download.
	 */
	const handleExport = useCallback( () => {
		if ( 0 === getSelectedAssetTypes( filters.updateTypes ).length ) {
			setTypeError(
				__( 'Select at least one update type.', 'updatebee' )
			);
			return;
		}

		if (
			'' !== filters.dateFrom &&
			'' !== filters.dateTo &&
			filters.dateFrom > filters.dateTo
		) {
			setDateError(
				__(
					'The start date must be on or before the end date.',
					'updatebee'
				)
			);
			return;
		}

		window.location.assign( getExportUrl( filters ) );
		handleOpenChange( false );
	}, [ filters, handleOpenChange ] );

	return (
		<Drawer.Root
			open={ isOpen }
			onOpenChange={ handleOpenChange }
			swipeDirection="right"
			disablePointerDismissal={ false }
		>
			<Button
				variant="outline"
				disabled={ disabled }
				onClick={ () => {
					setIsOpen( true );
				} }
			>
				{ __( 'Export', 'updatebee' ) }
			</Button>
			<Drawer.Popup
				size="medium"
				portal={
					<Drawer.Portal className="updatebee-logs-export-drawer-portal" />
				}
			>
				<Drawer.Header>
					<Drawer.Title>
						{ __( 'Export Logs', 'updatebee' ) }
					</Drawer.Title>
				</Drawer.Header>
				<Drawer.Content>
					<Drawer.Description>
						{ __(
							'Choose filters and an optional date range, then download the matching logs as a CSV file.',
							'updatebee'
						) }
					</Drawer.Description>
					<div className="updatebee-logs-export-fields">
						<SelectControl
							label={ __( 'Update Offer', 'updatebee' ) }
							value={ filters.updateOffer }
							options={ [
								{
									label: __(
										'All Update Offers',
										'updatebee'
									),
									value: 'all',
								},
								{
									label: __( 'Automatic', 'updatebee' ),
									value: 'automatic',
								},
								{
									label: __( 'Manual', 'updatebee' ),
									value: 'manual',
								},
							] }
							onChange={ ( value ) => {
								if ( 'string' === typeof value ) {
									handleFilterChange( {
										updateOffer: value,
									} );
								}
							} }
						/>
						<fieldset className="updatebee-logs-export-types">
							<legend>
								{ __( 'Update type', 'updatebee' ) }
							</legend>
							<div className="updatebee-logs-export-type-options">
								<CheckboxControl
									label={ __( 'Plugins', 'updatebee' ) }
									checked={ filters.updateTypes.plugin }
									onChange={ ( checked ) => {
										handleTypeChange( 'plugin', checked );
									} }
								/>
								<CheckboxControl
									label={ __( 'Themes', 'updatebee' ) }
									checked={ filters.updateTypes.theme }
									onChange={ ( checked ) => {
										handleTypeChange( 'theme', checked );
									} }
								/>
								<CheckboxControl
									label={ __(
										'Translations',
										'updatebee'
									) }
									checked={ filters.updateTypes.translation }
									onChange={ ( checked ) => {
										handleTypeChange(
											'translation',
											checked
										);
									} }
								/>
								<CheckboxControl
									label={ __( 'Core', 'updatebee' ) }
									checked={ filters.updateTypes.core }
									onChange={ ( checked ) => {
										handleTypeChange( 'core', checked );
									} }
								/>
								{ '' !== typeError && (
									<p
										className="updatebee-logs-export-error"
										role="alert"
									>
										{ typeError }
									</p>
								) }
							</div>
						</fieldset>
						<SelectControl
							label={ __( 'Update Status', 'updatebee' ) }
							value={ filters.updateStatus }
							options={ [
								{
									label: __(
										'All Update Statuses',
										'updatebee'
									),
									value: 'all',
								},
								{
									label: __( 'Success', 'updatebee' ),
									value: 'success',
								},
								{
									label: __(
										'Security Release',
										'updatebee'
									),
									value: 'security',
								},
								{
									label: __( 'Failure', 'updatebee' ),
									value: 'failure',
								},
								{
									label: __( 'Blocked', 'updatebee' ),
									value: 'blocked',
								},
								{
									label: __( 'Skipped', 'updatebee' ),
									value: 'skipped',
								},
								{
									label: __( 'Delayed', 'updatebee' ),
									value: 'delayed',
								},
							] }
							onChange={ ( value ) => {
								if ( 'string' === typeof value ) {
									handleFilterChange( {
										updateStatus: value,
									} );
								}
							} }
						/>
						<TextControl
							label={ __( 'Date from', 'updatebee' ) }
							type="date"
							value={ filters.dateFrom }
							onChange={ ( value ) => {
								handleFilterChange( { dateFrom: value } );
							} }
						/>
						<TextControl
							label={ __( 'Date to', 'updatebee' ) }
							type="date"
							value={ filters.dateTo }
							onChange={ ( value ) => {
								handleFilterChange( { dateTo: value } );
							} }
						/>
						<p className="description">
							{ __(
								'Leave dates blank for all dates.',
								'updatebee'
							) }
						</p>
						{ '' !== dateError && (
							<p
								className="updatebee-logs-export-error"
								role="alert"
							>
								{ dateError }
							</p>
						) }
					</div>
				</Drawer.Content>
				<Drawer.Footer className="updatebee-logs-export-footer">
					<Drawer.Action variant="outline">
						{ __( 'Cancel', 'updatebee' ) }
					</Drawer.Action>
					<Button variant="solid" onClick={ handleExport }>
						{ __( 'Export as CSV', 'updatebee' ) }
					</Button>
				</Drawer.Footer>
			</Drawer.Popup>
		</Drawer.Root>
	);
};

export default LogsExportDrawer;
