/**
 * License footer snackbar.
 */
import { Fill } from '@wordpress/components';
import FloatingNotice from '../../components/FloatingNotice';
import type { SnackbarType } from '../Advanced/AdvancedFooter';

type SnackbarState = {
	isVisible: boolean;
	message: string;
	type: SnackbarType;
};

type LicenseFooterProps = {
	snackbar: SnackbarState;
	onSnackbarClose: () => void;
};

const LicenseFooter = ( props: LicenseFooterProps ) => {
	const { snackbar, onSnackbarClose } = props;

	return (
		<Fill name="updatebeeFooter">
			<div className="updatebee-license-footer">
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

export default LicenseFooter;
