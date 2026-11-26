/**
 * Translations footer snackbar and DataViews pagination/bulk bar.
 */
import { Fill } from '@wordpress/components';
import { DataViews } from '@wordpress/dataviews';
import FloatingNotice from '../../components/FloatingNotice';

export type SnackbarType = 'success' | 'error' | 'warning' | 'info';

export type SnackbarState = {
	isVisible: boolean;
	message: string;
	type: SnackbarType;
};

type TranslationsFooterProps = {
	snackbar: SnackbarState;
	onSnackbarClose: () => void;
};

const TranslationsFooter = ( props: TranslationsFooterProps ) => {
	const { snackbar, onSnackbarClose } = props;

	return (
		<Fill name="updatebeeFooter">
			<div className="updatebee-translations-footer">
				<FloatingNotice
					isVisible={ snackbar.isVisible }
					message={ snackbar.message }
					type={ snackbar.type }
					onClose={ onSnackbarClose }
				/>
				<DataViews.Footer />
			</div>
		</Fill>
	);
};

export default TranslationsFooter;
