<?php
/**
 * Outgoing webhook records.
 *
 * @package updatebee
 */

namespace UpdateBee\Webhooks;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read and write the updatebee_webhooks site option.
 */
class Store {

	public const OPTION_KEY = 'updatebee_webhooks';

	public const MAX_WEBHOOKS = 100;

	public const MAX_HEADERS = 20;

	/**
	 * Report keys a webhook can include.
	 *
	 * @var string[]
	 */
	public const REPORTS = array( 'success', 'failure', 'fatal', 'available' );

	/**
	 * Update types a webhook can include.
	 *
	 * @var string[]
	 */
	public const TYPES = array( 'core', 'plugins', 'themes', 'translations' );

	/**
	 * HTTP methods that can carry a JSON body.
	 *
	 * @var string[]
	 */
	public const METHODS = array( 'POST', 'PUT', 'PATCH' );

	/**
	 * Header names offered in the editor.
	 *
	 * Content-Type is omitted because every request sends JSON.
	 *
	 * @var string[]
	 */
	public const HEADER_NAMES = array(
		'Accept',
		'Accept-Charset',
		'Accept-Encoding',
		'Accept-Language',
		'Authorization',
		'Cache-Control',
		'From',
		'Pragma',
		'User-Agent',
		'X-Requested-With',
	);

	/**
	 * Header names that must not be stored or sent.
	 *
	 * @var string[]
	 */
	private const BLOCKED_HEADERS = array(
		'connection',
		'content-length',
		'content-type',
		'cookie',
		'expect',
		'host',
		'keep-alive',
		'proxy-authorization',
		'set-cookie',
		'te',
		'trailer',
		'transfer-encoding',
		'upgrade',
	);

	/**
	 * Every stored webhook.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function all(): array {
		$stored = get_site_option( self::OPTION_KEY, array() );
		if ( ! is_array( $stored ) ) {
			return array();
		}

		$webhooks = array();
		foreach ( $stored as $webhook ) {
			if ( is_array( $webhook ) && isset( $webhook['id'] ) && is_string( $webhook['id'] ) && self::is_id( $webhook['id'] ) ) {
				$webhooks[] = $webhook;
			}
		}

		return $webhooks;
	}

	/**
	 * Enabled webhooks only.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function enabled(): array {
		$enabled = array();
		foreach ( self::all() as $webhook ) {
			if ( ! empty( $webhook['enabled'] ) ) {
				$enabled[] = $webhook;
			}
		}

		return $enabled;
	}

	/**
	 * One webhook by id.
	 *
	 * @param string $id Webhook id.
	 * @return array<string, mixed>|null
	 */
	public static function find( string $id ): ?array {
		if ( ! self::is_id( $id ) ) {
			return null;
		}

		foreach ( self::all() as $webhook ) {
			if ( $webhook['id'] === $id ) {
				return $webhook;
			}
		}

		return null;
	}

	/**
	 * Whether the id matches ids this store generates.
	 *
	 * @param string $id Candidate id.
	 * @return bool
	 */
	public static function is_id( string $id ): bool {
		return 1 === preg_match( '/^wh_[a-z0-9]{8,32}$/', $id );
	}

	/**
	 * Sanitize a webhook without writing it.
	 *
	 * @param array<string, mixed> $input Raw input.
	 * @param string               $id    Existing id, or empty when creating.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function sanitize( array $input, string $id = '' ) {
		if ( '' !== $id && ! self::is_id( $id ) ) {
			return new \WP_Error(
				'updatebee_webhook_id',
				__( 'Webhook could not be found.', 'updatebee' )
			);
		}

		$name = self::text( $input['name'] ?? '' );
		$name = self::truncate( $name, 191 );
		if ( '' === $name ) {
			return new \WP_Error(
				'updatebee_webhook_name',
				__( 'Enter a name for this webhook.', 'updatebee' )
			);
		}

		$url = self::literal( $input['url'] ?? '' );
		$url = esc_url_raw( $url );
		if ( ! self::is_https_url( $url ) ) {
			return new \WP_Error(
				'updatebee_webhook_url',
				__( 'Enter an HTTPS webhook URL.', 'updatebee' )
			);
		}

		$method = strtoupper( self::text( $input['method'] ?? 'POST' ) );
		if ( ! in_array( $method, self::METHODS, true ) ) {
			return new \WP_Error(
				'updatebee_webhook_method',
				__( 'Choose a POST, PUT, or PATCH request.', 'updatebee' )
			);
		}

		$reports = self::flags( $input['reports'] ?? array(), self::REPORTS );
		if ( is_wp_error( $reports ) ) {
			return $reports;
		}

		$types = self::flags( $input['types'] ?? array(), self::TYPES );
		if ( is_wp_error( $types ) ) {
			return $types;
		}

		$headers = self::headers( $input['headers'] ?? array() );
		if ( is_wp_error( $headers ) ) {
			return $headers;
		}

		$existing      = '' !== $id ? self::find( $id ) : null;
		$last_delivery = self::empty_delivery();
		if ( is_array( $existing ) && isset( $existing['last_delivery'] ) && is_array( $existing['last_delivery'] ) ) {
			$last_delivery = array(
				'time'   => self::text( $existing['last_delivery']['time'] ?? '' ),
				'status' => isset( $existing['last_delivery']['status'] ) ? (int) $existing['last_delivery']['status'] : 0,
				'error'  => self::truncate( self::text( $existing['last_delivery']['error'] ?? '' ), 180 ),
			);
		}

		return array(
			'id'            => $id,
			'name'          => $name,
			'url'           => $url,
			'enabled'       => self::to_bool( $input['enabled'] ?? true, true ),
			'method'        => $method,
			'headers'       => $headers,
			'reports'       => $reports,
			'types'         => $types,
			'last_delivery' => $last_delivery,
		);
	}

	/**
	 * Create or update a webhook.
	 *
	 * @param array<string, mixed> $input Raw input.
	 * @param string               $id    Existing id, or empty when creating.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function save( array $input, string $id = '' ) {
		$webhook = self::sanitize( $input, $id );
		if ( is_wp_error( $webhook ) ) {
			return $webhook;
		}

		$all    = self::all();
		$is_new = '' === $webhook['id'];
		if ( $is_new && count( $all ) >= self::MAX_WEBHOOKS ) {
			return new \WP_Error(
				'updatebee_webhook_limit',
				__( 'The webhook limit has been reached.', 'updatebee' )
			);
		}

		if ( $is_new ) {
			$webhook['id'] = self::generate_id( $all );
		} else {
			$found = false;
			foreach ( $all as $index => $existing ) {
				if ( $existing['id'] === $webhook['id'] ) {
					$all[ $index ] = $webhook;
					$found         = true;
					break;
				}
			}
			if ( ! $found ) {
				return new \WP_Error(
					'updatebee_webhook_id',
					__( 'Webhook could not be found.', 'updatebee' )
				);
			}
		}

		if ( $is_new ) {
			$all[] = $webhook;
		}

		$written = self::write( $all );
		if ( is_wp_error( $written ) ) {
			return $written;
		}

		return $webhook;
	}

	/**
	 * Delete a webhook.
	 *
	 * @param string $id Webhook id.
	 * @return bool True when a record was removed.
	 */
	public static function delete( string $id ): bool {
		if ( ! self::is_id( $id ) ) {
			return false;
		}

		$all   = self::all();
		$next  = array();
		$found = false;
		foreach ( $all as $webhook ) {
			if ( $webhook['id'] === $id ) {
				$found = true;
				continue;
			}
			$next[] = $webhook;
		}

		if ( ! $found ) {
			return false;
		}

		return ! is_wp_error( self::write( $next ) );
	}

	/**
	 * Turn a webhook on or off.
	 *
	 * @param string $id      Webhook id.
	 * @param bool   $enabled Whether the webhook should send.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function set_enabled( string $id, bool $enabled ) {
		$webhook = self::find( $id );
		if ( null === $webhook ) {
			return new \WP_Error(
				'updatebee_webhook_id',
				__( 'Webhook could not be found.', 'updatebee' )
			);
		}

		$webhook['enabled'] = $enabled;
		$all                = self::all();
		foreach ( $all as $index => $existing ) {
			if ( $existing['id'] === $id ) {
				$all[ $index ] = $webhook;
				break;
			}
		}

		$written = self::write( $all );
		if ( is_wp_error( $written ) ) {
			return $written;
		}

		return $webhook;
	}

	/**
	 * Store the outcome of one delivery attempt.
	 *
	 * The response body and webhook URL are not stored.
	 *
	 * @param string $id     Webhook id.
	 * @param int    $status HTTP status, or 0 when the request did not complete.
	 * @param string $error  Short transport error, already stripped of the URL.
	 */
	public static function record_delivery( string $id, int $status, string $error ): void {
		$webhook = self::find( $id );
		if ( null === $webhook ) {
			return;
		}

		$webhook['last_delivery'] = array(
			'time'   => current_time( 'mysql' ),
			'status' => $status,
			'error'  => self::truncate( self::text( $error ), 180 ),
		);

		$all = self::all();
		foreach ( $all as $index => $existing ) {
			if ( $existing['id'] === $id ) {
				$all[ $index ] = $webhook;
				break;
			}
		}

		self::write( $all );
	}

	/**
	 * Whether a URL is an HTTPS URL WordPress will request.
	 *
	 * @param string $url Candidate URL.
	 * @return bool
	 */
	public static function is_https_url( string $url ): bool {
		if ( ! str_starts_with( $url, 'https://' ) ) {
			return false;
		}

		if ( false === filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return false;
		}

		if ( function_exists( 'wp_http_validate_url' ) && false === wp_http_validate_url( $url ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Persist the full list.
	 *
	 * @param array<int, array<string, mixed>> $webhooks Webhook records.
	 * @return true|\WP_Error
	 */
	private static function write( array $webhooks ) {
		$webhooks = array_values( $webhooks );
		$updated  = update_site_option( self::OPTION_KEY, $webhooks );
		if ( $updated ) {
			return true;
		}

		$stored = get_site_option( self::OPTION_KEY, array() );
		if ( $stored === $webhooks ) {
			return true;
		}

		return new \WP_Error(
			'updatebee_webhook_save',
			__( 'Webhook could not be saved.', 'updatebee' )
		);
	}

	/**
	 * Generate an id that is not already stored.
	 *
	 * @param array<int, array<string, mixed>> $existing Stored webhooks.
	 * @return string
	 */
	private static function generate_id( array $existing ): string {
		$used = array();
		foreach ( $existing as $webhook ) {
			if ( isset( $webhook['id'] ) && is_string( $webhook['id'] ) ) {
				$used[ $webhook['id'] ] = true;
			}
		}

		for ( $attempt = 0; $attempt < 5; $attempt++ ) {
			$raw = strtolower( (string) preg_replace( '/[^a-z0-9]/i', '', wp_generate_password( 16, false, false ) ) );
			$raw = substr( $raw, 0, 12 );
			if ( strlen( $raw ) < 8 ) {
				continue;
			}
			$id = 'wh_' . $raw;
			if ( ! isset( $used[ $id ] ) ) {
				return $id;
			}
		}

		$fallback = strtolower( (string) preg_replace( '/[^a-z0-9]/i', '', wp_generate_password( 16, false, false ) ) );
		$fallback = substr( $fallback . 'webhookid', 0, 12 );

		return 'wh_' . $fallback;
	}

	/**
	 * Boolean flags for an allowlist. Unknown keys are rejected.
	 *
	 * Missing keys default to true so a new webhook sends every report and type.
	 *
	 * @param mixed    $input   Posted flags.
	 * @param string[] $allowed Allowed keys.
	 * @return array<string, bool>|\WP_Error
	 */
	private static function flags( $input, array $allowed ) {
		if ( ! is_array( $input ) ) {
			$input = array();
		}

		foreach ( $input as $key => $value ) {
			unset( $value );
			if ( ! is_string( $key ) || ! in_array( $key, $allowed, true ) ) {
				return new \WP_Error(
					'updatebee_webhook_flag',
					__( 'Unknown webhook report or update type.', 'updatebee' )
				);
			}
		}

		$flags = array();
		foreach ( $allowed as $key ) {
			$flags[ $key ] = array_key_exists( $key, $input ) ? self::to_bool( $input[ $key ], true ) : true;
		}

		return $flags;
	}

	/**
	 * Sanitize header rows. Empty rows are dropped. Invalid names are rejected.
	 *
	 * @param mixed $input Posted headers.
	 * @return array<int, array{name: string, custom_name: string, value: string}>|\WP_Error
	 */
	private static function headers( $input ) {
		if ( ! is_array( $input ) ) {
			return array();
		}

		$headers = array();
		foreach ( $input as $row ) {
			if ( count( $headers ) >= self::MAX_HEADERS ) {
				break;
			}
			if ( ! is_array( $row ) ) {
				continue;
			}

			$value = self::literal( $row['value'] ?? '' );
			$value = self::truncate( $value, 1000 );
			if ( '' === $value ) {
				continue;
			}

			$name = self::text( $row['name'] ?? '' );
			if ( '' === $name || 'none' === $name ) {
				return new \WP_Error(
					'updatebee_webhook_header',
					__( 'Choose a supported header name.', 'updatebee' )
				);
			}

			$custom_name = '';
			if ( 'custom' === $name ) {
				$custom_name = self::header_token( self::text( $row['customName'] ?? $row['custom_name'] ?? '' ) );
				if ( '' === $custom_name || self::header_is_blocked( $custom_name ) ) {
					return new \WP_Error(
						'updatebee_webhook_header',
						__( 'Enter a valid custom header name.', 'updatebee' )
					);
				}
			} else {
				$canonical = self::canonical_header( $name );
				if ( '' === $canonical ) {
					return new \WP_Error(
						'updatebee_webhook_header',
						__( 'Choose a supported header name.', 'updatebee' )
					);
				}
				$name = $canonical;
			}

			$headers[] = array(
				'name'        => $name,
				'custom_name' => $custom_name,
				'value'       => $value,
			);
		}

		return $headers;
	}

	/**
	 * Match a preset header name, ignoring case.
	 *
	 * @param string $name Posted name.
	 * @return string Canonical name, or empty when it is not a preset.
	 */
	private static function canonical_header( string $name ): string {
		foreach ( self::HEADER_NAMES as $allowed ) {
			if ( 0 === strcasecmp( $name, $allowed ) ) {
				return $allowed;
			}
		}

		return '';
	}

	/**
	 * Whether a header name can change the request transport.
	 *
	 * @param string $name Header name.
	 * @return bool
	 */
	private static function header_is_blocked( string $name ): bool {
		return in_array( strtolower( $name ), self::BLOCKED_HEADERS, true );
	}

	/**
	 * Keep a header token and drop everything else.
	 *
	 * @param string $name Raw header name.
	 * @return string
	 */
	private static function header_token( string $name ): string {
		$name = str_replace( array( "\r", "\n" ), '', $name );
		if ( 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9-]{0,63}$/', $name ) ) {
			return '';
		}

		return $name;
	}

	/**
	 * Scalar text that keeps percent-encoding.
	 *
	 * sanitize_text_field() deletes %XX sequences. Webhook URLs and header
	 * values need those characters. Line breaks are still removed.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private static function literal( $value ): string {
		if ( ! is_string( $value ) && ! is_numeric( $value ) ) {
			return '';
		}

		$value = str_replace( array( "\r", "\n" ), '', (string) $value );

		return trim( $value );
	}

	/**
	 * Plain text from a posted scalar.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private static function text( $value ): string {
		if ( ! is_string( $value ) && ! is_numeric( $value ) ) {
			return '';
		}

		return sanitize_text_field( (string) $value );
	}

	/**
	 * Boolean from a form or JSON value.
	 *
	 * @param mixed $value    Raw value.
	 * @param bool  $fallback Value used when the input is not a boolean.
	 * @return bool
	 */
	private static function to_bool( $value, bool $fallback ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}

		if ( is_int( $value ) ) {
			return 1 === $value;
		}

		if ( ! is_string( $value ) ) {
			return $fallback;
		}

		$value = strtolower( $value );
		if ( in_array( $value, array( '1', 'true', 'on', 'yes' ), true ) ) {
			return true;
		}
		if ( in_array( $value, array( '0', 'false', 'off', 'no' ), true ) ) {
			return false;
		}

		return $fallback;
	}

	/**
	 * Shorten a string without splitting multibyte characters when possible.
	 *
	 * @param string $value  Text.
	 * @param int    $length Maximum length.
	 * @return string
	 */
	private static function truncate( string $value, int $length ): string {
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $value, 0, $length );
		}

		return substr( $value, 0, $length );
	}

	/**
	 * Empty delivery record.
	 *
	 * @return array{time: string, status: int, error: string}
	 */
	private static function empty_delivery(): array {
		return array(
			'time'   => '',
			'status' => 0,
			'error'  => '',
		);
	}
}
