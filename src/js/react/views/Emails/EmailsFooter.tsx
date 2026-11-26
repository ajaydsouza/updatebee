/**
 * Emails tab footer snackbar.
 */
import { Fill } from '@wordpress/components';
import FloatingNotice from '../../components/FloatingNotice';
import type { SnackbarState } from '../Tools/ToolsFooter';

type EmailsFooterProps = {
	snackbar: SnackbarState;
	onSnackbarClose: () => void;
};

const EmailsFooter = ( props: EmailsFooterProps ) => {
	const { snackbar, onSnackbarClose } = props;

	return (
		<Fill name="updatebeeFooter">
			<div className="updatebee-emails-footer">
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

export default EmailsFooter;
