/**
 * DataViews row actions for outgoing webhooks.
 */
import type { Action } from '@wordpress/dataviews';
import { __ } from '@wordpress/i18n';
import { pencil, trash } from '@wordpress/icons';
import type { WebhookListItem } from './types';
import { webhookEditUrl } from './labels';

type GetWebhookActionsArgs = {
	onToggle: ( item: WebhookListItem, enabled: boolean ) => void;
	onDelete: ( item: WebhookListItem ) => void;
};

/**
 * Build edit, enable, disable, and delete actions.
 *
 * @param args Action callbacks.
 */
export const getWebhookActions = (
	args: GetWebhookActionsArgs
): Action< WebhookListItem >[] => {
	const { onToggle, onDelete } = args;

	return [
		{
			id: 'edit-webhook',
			label: __( 'Edit', 'updatebee' ),
			icon: pencil,
			supportsBulk: false,
			callback: ( items ) => {
				const item = items[ 0 ];
				if ( item ) {
					window.location.assign( webhookEditUrl( item.id ) );
				}
			},
		},
		{
			id: 'enable-webhook',
			label: __( 'Enable', 'updatebee' ),
			supportsBulk: false,
			isEligible: ( item ) => ! item.enabled,
			callback: ( items ) => {
				const item = items[ 0 ];
				if ( item ) {
					onToggle( item, true );
				}
			},
		},
		{
			id: 'disable-webhook',
			label: __( 'Disable', 'updatebee' ),
			supportsBulk: false,
			isEligible: ( item ) => item.enabled,
			callback: ( items ) => {
				const item = items[ 0 ];
				if ( item ) {
					onToggle( item, false );
				}
			},
		},
		{
			id: 'delete-webhook',
			label: __( 'Delete', 'updatebee' ),
			icon: trash,
			supportsBulk: false,
			callback: ( items ) => {
				const item = items[ 0 ];
				if ( item ) {
					onDelete( item );
				}
			},
		},
	];
};
