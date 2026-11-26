/**
 * Tools footer snackbar.
 */
import { Fill } from '@wordpress/components';
import FloatingNotice from '../../components/FloatingNotice';

export type SnackbarType = 'success' | 'error' | 'warning' | 'info';

export type SnackbarState = {
	isVisible: boolean;
	message: string;
	type: SnackbarType;
};

type ToolsFooterProps = {
	snackbar: SnackbarState;
	onSnackbarClose: () => void;
};

const ToolsFooter = ( props: ToolsFooterProps ) => {
	const { snackbar, onSnackbarClose } = props;

	return (
		<Fill name="updatebeeFooter">
			<div className="updatebee-tools-footer">
				<FloatingNotice
					isVisible={ snackbar.isVisible }
					message={ snackbar.message }
					type={ snackbar.type }
					onClose={ onSnackbarClose }
				/>
			</div>
		</Fill>
	);
};

export default ToolsFooter;
