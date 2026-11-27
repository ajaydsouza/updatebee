/**
 * Header names offered in the webhook editor.
 *
 * Keep this list aligned with UpdateBee\Webhooks\Store::HEADER_NAMES.
 */
import { __ } from '@wordpress/i18n';

export const HEADER_NAME_OPTIONS = [
	{ label: __( 'Select a header', 'updatebee' ), value: 'none' },
	{ label: 'Accept', value: 'Accept' },
	{ label: 'Accept-Charset', value: 'Accept-Charset' },
	{ label: 'Accept-Encoding', value: 'Accept-Encoding' },
	{ label: 'Accept-Language', value: 'Accept-Language' },
	{ label: 'Authorization', value: 'Authorization' },
	{ label: 'Cache-Control', value: 'Cache-Control' },
	{ label: 'From', value: 'From' },
	{ label: 'Pragma', value: 'Pragma' },
	{ label: 'User-Agent', value: 'User-Agent' },
	{ label: 'X-Requested-With', value: 'X-Requested-With' },
	{ label: __( 'Custom name', 'updatebee' ), value: 'custom' },
];
