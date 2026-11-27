/**
 * Outgoing webhook records used by the admin screens.
 */

export type WebhookMethod = 'POST' | 'PUT' | 'PATCH';

export type WebhookReportKey = 'success' | 'failure' | 'fatal' | 'available';

export type WebhookTypeKey = 'core' | 'plugins' | 'themes' | 'translations';

export type WebhookHeader = {
	name: string;
	customName: string;
	value: string;
};

export type WebhookFlags< T extends string > = Record< T, boolean >;

export type WebhookDelivery = {
	time: string;
	status: number;
	error: string;
};

export type WebhookListItem = {
	id: string;
	name: string;
	url: string;
	enabled: boolean;
	method: WebhookMethod;
	reports: WebhookReportKey[];
	lastDelivery: WebhookDelivery;
	deleteNonce: string;
	enableNonce: string;
	enabledLabel: string;
	reportsLabel: string;
	lastDeliveryLabel: string;
};

export type WebhookEditor = {
	id: string;
	name: string;
	url: string;
	enabled: boolean;
	method: WebhookMethod;
	headers: WebhookHeader[];
	reports: WebhookFlags< WebhookReportKey >;
	types: WebhookFlags< WebhookTypeKey >;
	lastDelivery: WebhookDelivery;
};

export type WebhookFormValues = {
	name: string;
	url: string;
	enabled: boolean;
	method: WebhookMethod;
	headers: WebhookHeader[];
	reports: WebhookFlags< WebhookReportKey >;
	types: WebhookFlags< WebhookTypeKey >;
};

export const emptyHeader = (): WebhookHeader => ( {
	name: 'Authorization',
	customName: '',
	value: '',
} );

export const defaultFormValues = (): WebhookFormValues => ( {
	name: '',
	url: '',
	enabled: true,
	method: 'POST',
	headers: [ emptyHeader() ],
	reports: {
		success: true,
		failure: true,
		fatal: true,
		available: true,
	},
	types: {
		core: true,
		plugins: true,
		themes: true,
		translations: true,
	},
} );
