/**
 * Post an admin-ajax request.
 */
import axios, { type AxiosResponse } from 'axios';
import qs from 'qs';

export type SendCommandData = Record< string, unknown >;

export type SendCommandOptions = {
	/**
	 * Keep empty arrays in this request body.
	 *
	 * Off by default. Other admin-ajax calls still omit empty arrays.
	 */
	allowEmptyArrays?: boolean;
};

export interface WpAjaxSuccess< T > {
	success: true;
	data: T;
}

export interface WpAjaxError {
	success: false;
	data?: unknown;
}

export type WpAjaxResponse< T > = WpAjaxSuccess< T > | WpAjaxError;

/**
 * Extract a user-facing message from a failed admin-ajax JSON payload.
 *
 * @param data WP `data` field from a `success: false` response.
 * @return Message string when one is present.
 */
/**
 * Admin-ajax failure that still carries the JSON `data` payload.
 */
export class WpCommandError extends Error {
	readonly data?: unknown;

	/**
	 * @param message User-facing message.
	 * @param data    WP `data` field, when the response included one.
	 */
	constructor( message: string, data?: unknown ) {
		super( message );
		this.name = 'WpCommandError';
		this.data = data;
	}
}

export function getWpAjaxErrorMessage( data: unknown ): string | undefined {
	if ( typeof data === 'string' && '' !== data.trim() ) {
		return data;
	}

	if ( data && typeof data === 'object' && 'message' in data ) {
		const message = ( data as { message: unknown } ).message;
		if ( typeof message === 'string' && '' !== message.trim() ) {
			return message;
		}
	}

	return undefined;
}

/**
 * Send a WordPress admin-ajax command.
 *
 * Non-success WP JSON (`success: false`) rejects so callers can use `.catch`.
 *
 * @param action  Admin-ajax action name.
 * @param data    POST body fields.
 * @param options Per-request encoding options. Empty arrays stay omitted unless asked for.
 * @return Axios response promise.
 */
export default function sendCommand< T = unknown >(
	action: string,
	data: SendCommandData = {},
	options: SendCommandOptions = {}
): Promise< AxiosResponse< WpAjaxSuccess< T > > > {
	const payload: SendCommandData = {
		nonce: false,
		action,
		...data,
	};

	return axios< WpAjaxResponse< T > >( {
		method: 'post',
		url: ajaxurl,
		params: {
			action,
		},
		paramsSerializer( params: Record< string, string > ) {
			return qs.stringify( params, { arrayFormat: 'brackets' } );
		},
		data: qs.stringify(
			payload,
			options.allowEmptyArrays ? { allowEmptyArrays: true } : undefined
		),
	} ).then( ( response ) => {
		if ( ! response.data || true !== response.data.success ) {
			const message =
				getWpAjaxErrorMessage( response.data?.data ) ??
				'Request failed.';
			return Promise.reject(
				new WpCommandError( message, response.data?.data )
			);
		}

		return response as AxiosResponse< WpAjaxSuccess< T > >;
	} );
}
