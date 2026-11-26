/**
 * Set the site-wide plugin and theme update delay.
 */
import { Button, Modal, Notice, TextControl } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

type UpcomingDelayModalProps = {
	isOpen: boolean;
	delayDays: number;
	isSaving: boolean;
	errorMessage: string;
	onClose: () => void;
	onSave: ( days: number ) => void;
};

/**
 * Normalize a delay-days value.
 *
 * @param value Incoming days value.
 * @return Non-negative integer days.
 */
const normalizeDays = ( value: unknown ): number => {
	const days = Number( value );
	if ( Number.isFinite( days ) && days >= 0 ) {
		return Math.floor( days );
	}

	return 0;
};

/**
 * Ask for a new site-wide update delay.
 *
 * @param props Modal state and save handler.
 */
const UpcomingDelayModal = ( props: UpcomingDelayModalProps ) => {
	const { isOpen, delayDays, isSaving, errorMessage, onClose, onSave } =
		props;
	const [ days, setDays ] = useState( String( delayDays ) );

	useEffect( () => {
		if ( isOpen ) {
			setDays( String( delayDays ) );
		}
	}, [ delayDays, isOpen ] );

	if ( ! isOpen ) {
		return null;
	}

	return (
		<Modal
			className="updatebee-upcoming-delay-modal"
			title={ __( 'Change update delay', 'updatebee' ) }
			onRequestClose={ onClose }
			shouldCloseOnClickOutside={ ! isSaving }
			shouldCloseOnEsc={ ! isSaving }
			isDismissible={ ! isSaving }
		>
			{ '' !== errorMessage && (
				<Notice status="error" isDismissible={ false }>
					{ errorMessage }
				</Notice>
			) }
			<TextControl
				label={ __( 'Delay Automatic Updates', 'updatebee' ) }
				type="number"
				min={ 0 }
				value={ days }
				onChange={ ( value ) => {
					setDays( value );
				} }
				help={ __(
					'Time in days for how long to delay an update. Use 0 for no delay. Individual plugins and themes can override this from their own settings.',
					'updatebee'
				) }
				disabled={ isSaving }
			/>
			<div className="updatebee-upcoming-delay-modal-footer">
				<Button
					variant="secondary"
					disabled={ isSaving }
					onClick={ onClose }
				>
					{ __( 'Cancel', 'updatebee' ) }
				</Button>
				<Button
					variant="primary"
					isBusy={ isSaving }
					disabled={ isSaving }
					onClick={ () => {
						onSave( normalizeDays( days ) );
					} }
				>
					{ __( 'Save delay', 'updatebee' ) }
				</Button>
			</div>
		</Modal>
	);
};

export default UpcomingDelayModal;
