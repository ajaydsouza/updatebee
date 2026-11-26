/**
 * Dashboard settings view.
 */
import { FormProvider, useForm } from 'react-hook-form';
import { __ } from '@wordpress/i18n';
import { useEnableWpCompatOverlaySlot } from '@wordpress/ui';
import ErrorBoundary from '../../components/ErrorBoundary';
import DashboardUpdateOptions, {
	dashboardSettingsDefaults,
	type DashboardSettingsForm,
} from './DashboardUpdateOptions';
import DashboardFooter from './DashboardFooter';

/**
 * Settings screen for automatic updates.
 */
const Dashboard = () => {
	const methods = useForm< DashboardSettingsForm >( {
		defaultValues: dashboardSettingsDefaults,
	} );
	useEnableWpCompatOverlaySlot();

	return (
		<FormProvider { ...methods }>
			<div className="updatebee-dashboard-main">
				<ErrorBoundary
					heading={ __( 'Automatic Updates', 'updatebee' ) }
					message={ __(
						'These settings could not be displayed. Try again or reload the page.',
						'updatebee'
					) }
				>
					<DashboardUpdateOptions />
				</ErrorBoundary>
				<DashboardFooter />
			</div>
		</FormProvider>
	);
};

export default Dashboard;
