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
 * Read a delay the user typed.
 *
 * Blank, negative, and non-numeric values stay invalid. An entered 0 is allowed.
 *
 * @param value Field value.
 * @return Whole days, or null when the value is not 0 or greater.
 */
const parseEnteredDays = ( value: string ): number | null => {
	const trimmed = value.trim();
	if ( '' === trimmed ) {
		return null;
	}

	const days = Number( trimmed );
	if ( ! Number.isFinite( days ) || 0 > days ) {
		return null;
	}

	return Math.floor( days );
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
	const [ validationError, setValidationError ] = useState( '' );

	useEffect( () => {
		if ( isOpen ) {
			setDays( String( delayDays ) );
			setValidationError( '' );
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
			{ '' !== ( validationError || errorMessage ) && (
				<Notice status="error" isDismissible={ false }>
					{ validationError || errorMessage }
				</Notice>
			) }
			<TextControl
				label={ __( 'Delay Automatic Updates', 'updatebee' ) }
				type="number"
				min={ 0 }
				value={ days }
				onChange={ ( value ) => {
					setDays( value );
					setValidationError( '' );
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
						const nextDays = parseEnteredDays( days );
						if ( null === nextDays ) {
							setValidationError(
								__(
									'Enter a whole number of 0 or more days.',
									'updatebee'
								)
							);
							return;
						}

						setValidationError( '' );
						onSave( nextDays );
					} }
				>
					{ __( 'Save delay', 'updatebee' ) }
				</Button>
			</div>
		</Modal>
	);
};

export default UpcomingDelayModal;
