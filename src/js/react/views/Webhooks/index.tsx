/**
 * Webhooks screen entry.
 */
import '../../../../scss/webhooks.scss';
import { commonFill } from '../../shared/index';
import WebhookEdit from './WebhookEdit';
import WebhooksList from './WebhooksList';

const WebhooksApp = () => {
	if ( 'edit' === updatebeeWebhooks.screen ) {
		return <WebhookEdit webhookId={ updatebeeWebhooks.webhookId } />;
	}

	return <WebhooksList />;
};

commonFill( 'updatebee-webhooks-container', <WebhooksApp /> );
