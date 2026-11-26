/**
 * Plugins screen list.
 */
import '../../../../scss/plugins.scss';
import '../../../../scss/rollback.scss';
import '../../../../scss/changelog.scss';
import { commonFill } from '../../shared/index';
import PluginsMain from './PluginsMain';

commonFill( 'updatebee-plugins-container', <PluginsMain /> );
