/**
 * Advanced footer snackbar.
 */
import { Fill } from '@wordpress/components';
import InlineNotice from '../../components/InlineNotice';

export type SnackbarType = 'success' | 'error' | 'warning' | 'info';

export type SnackbarState = {
	isVisible: boolean;
	message: string;
	type: SnackbarType;
};

type AdvancedFooterProps = {
	snackbar: SnackbarState;
	onSnackbarClose: () => void;
};

const AdvancedFooter = ( props: AdvancedFooterProps ) => {
	const { snackbar, onSnackbarClose } = props;

	return (
		<Fill name="updatebeeFooter">
			<div className="updatebee-advanced-footer">
				<InlineNotice
					isVisible={ snackbar.isVisible }
					message={ snackbar.message }
					type={ snackbar.type }
					onClose={ onSnackbarClose }
				/>
			</div>
		</Fill>
	);
};

export default AdvancedFooter;
