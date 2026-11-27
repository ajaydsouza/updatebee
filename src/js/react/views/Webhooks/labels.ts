/**
 * Labels for webhook reports, types, and delivery status.
 */
import { __ } from '@wordpress/i18n';
import type {
	WebhookDelivery,
	WebhookReportKey,
	WebhookTypeKey,
} from './types';

export const reportLabel = ( report: string ): string => {
	switch ( report ) {
		case 'success':
			return __( 'Successful updates', 'updatebee' );
		case 'failure':
			return __( 'Failed updates', 'updatebee' );
		case 'fatal':
			return __( 'Fatal errors', 'updatebee' );
		case 'available':
			return __( 'Available updates', 'updatebee' );
		default:
			return report;
	}
};

export const REPORT_FIELDS: WebhookReportKey[] = [
	'success',
	'failure',
	'fatal',
	'available',
];

export const TYPE_FIELDS: { key: WebhookTypeKey; label: string }[] = [
	{ key: 'core', label: __( 'WordPress core', 'updatebee' ) },
	{ key: 'plugins', label: __( 'Plugins', 'updatebee' ) },
	{ key: 'themes', label: __( 'Themes', 'updatebee' ) },
	{ key: 'translations', label: __( 'Translations', 'updatebee' ) },
];

/**
 * Short text for the last delivery column.
 *
 * @param delivery Stored delivery, without the response body.
 */
export const deliveryLabel = (
	delivery: WebhookDelivery | undefined
): string => {
	if (
		! delivery ||
		( ! delivery.time && ! delivery.status && ! delivery.error )
	) {
		return '—';
	}

	if ( delivery.status >= 200 && delivery.status < 300 ) {
		return delivery.time
			? `${ delivery.status } · ${ delivery.time }`
			: String( delivery.status );
	}

	if ( delivery.error ) {
		return delivery.error;
	}

	if ( delivery.status ) {
		return delivery.time
			? `${ delivery.status } · ${ delivery.time }`
			: String( delivery.status );
	}

	return delivery.time || '—';
};

/**
 * Edit screen URL for a webhook id.
 *
 * @param id Webhook id.
 */
export const webhookEditUrl = ( id: string ): string => {
	try {
		const url = new URL( updatebeeWebhooks.listUrl, window.location.href );
		url.searchParams.set( 'webhook', id );
		return url.toString();
	} catch {
		return updatebeeWebhooks.newUrl;
	}
};
