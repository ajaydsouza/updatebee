<?php
/**
 * License key defined in code.
 *
 * UPDATEBEE_LICENSE_KEY supplies the key from wp-config.php or an mu-plugin.
 * The updatebee_license_key filter can replace it. An empty result leaves the
 * key saved on the License screen in place.
 *
 * @package updatebee
 */

namespace UpdateBee\Updater;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolve a license key set outside the database.
 */
class License_Key {

	/**
	 * Statuses that mean the defined key is already activated.
	 *
	 * Other statuses are retried. Unregistered means activation has not finished.
	 *
	 * @var string[]
	 */
	private const SETTLED_STATUSES = array( 'valid', 'expired', 'disabled' );

	/**
	 * License key defined in code.
	 *
	 * @return string Sanitized key, or an empty string when the saved key should be used.
	 */
	public static function defined_key(): string {
		$constant = self::constant_key();

		/**
		 * Filter the license key set in code.
		 *
		 * Mu-plugins can return a key here instead of defining UPDATEBEE_LICENSE_KEY.
		 * A non-empty string replaces the constant. An empty string keeps the constant
		 * when one is set, and otherwise leaves the key saved on the License screen.
		 *
		 * @param string $constant Sanitized UPDATEBEE_LICENSE_KEY value, or an empty string.
		 */
		$filtered = apply_filters( 'updatebee_license_key', $constant );

		return self::resolve_defined_key( $constant, $filtered );
	}

	/**
	 * Whether a license key is set in code.
	 *
	 * @return bool
	 */
	public static function is_defined(): bool {
		return '' !== self::defined_key();
	}

	/**
	 * Choose the code-defined license key.
	 *
	 * A non-empty filter value wins. Otherwise the constant is used. An empty
	 * result means the key saved on the License screen is used.
	 *
	 * @param string $constant Sanitized constant value, or an empty string.
	 * @param mixed  $filtered Value returned by updatebee_license_key.
	 * @return string
	 */
	public static function resolve_defined_key( string $constant, $filtered ): string {
		if ( is_string( $filtered ) ) {
			$filtered = self::sanitize_key_value( $filtered );
			if ( '' !== $filtered ) {
				return $filtered;
			}
		}

		return $constant;
	}

	/**
	 * Error shown when the License screen posts a replacement key.
	 *
	 * An empty posted key is a recheck of the defined key and is allowed.
	 *
	 * @param string $posted_key Sanitized key from the request.
	 * @return string Empty when the request may continue.
	 */
	public static function posted_key_error( string $posted_key ): string {
		if ( '' === $posted_key || '' === self::defined_key() ) {
			return '';
		}

		return __( 'The license key is set in wp-config.php or an mu-plugin and cannot be changed here.', 'updatebee' );
	}

	/**
	 * Error shown when the License screen tries to revoke a code-defined key.
	 *
	 * @return string Empty when revoke may continue.
	 */
	public static function revoke_error(): string {
		if ( '' === self::defined_key() ) {
			return '';
		}

		return __( 'The license key is set in wp-config.php or an mu-plugin and cannot be revoked here.', 'updatebee' );
	}

	/**
	 * Whether a stored record already belongs to this defined key.
	 *
	 * @param mixed  $stored  License record from the site option or an activation response.
	 * @param string $defined Defined license key.
	 * @return bool
	 */
	public static function record_matches( $stored, string $defined ): bool {
		if ( '' === $defined || ! is_array( $stored ) ) {
			return false;
		}

		$stored_key = isset( $stored['license_key'] ) ? (string) $stored['license_key'] : '';
		if ( '' === $stored_key || ! hash_equals( $defined, $stored_key ) ) {
			return false;
		}

		$status = isset( $stored['status'] ) ? sanitize_key( (string) $stored['status'] ) : '';

		return in_array( $status, self::SETTLED_STATUSES, true );
	}

	/**
	 * Whether this request should activate a code-defined license key.
	 *
	 * Front-end requests skip the remote call.
	 *
	 * @param bool $is_admin Admin screen or admin-ajax.
	 * @param bool $is_cron  WP-Cron request.
	 * @param bool $is_cli   WP-CLI request.
	 * @return bool
	 */
	public static function should_sync( bool $is_admin, bool $is_cron, bool $is_cli ): bool {
		return $is_admin || $is_cron || $is_cli;
	}

	/**
	 * Sanitized UPDATEBEE_LICENSE_KEY value.
	 *
	 * @return string
	 */
	private static function constant_key(): string {
		if ( ! defined( 'UPDATEBEE_LICENSE_KEY' ) ) {
			return '';
		}

		$raw = constant( 'UPDATEBEE_LICENSE_KEY' );
		if ( ! is_string( $raw ) ) {
			return '';
		}

		return self::sanitize_key_value( $raw );
	}

	/**
	 * Trim a license key the same way the License screen does.
	 *
	 * @param string $value Raw key.
	 * @return string
	 */
	private static function sanitize_key_value( string $value ): string {
		$sanitized = sanitize_text_field( $value );
		if ( ! is_string( $sanitized ) ) {
			return '';
		}

		return $sanitized;
	}
}
