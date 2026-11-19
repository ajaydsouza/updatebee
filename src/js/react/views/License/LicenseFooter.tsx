/**
 * License footer snackbar.
 */
import { Fill } from '@wordpress/components';
import Snackbar from '../../components/Snackbar';
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
				<Snackbar
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
