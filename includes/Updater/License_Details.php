<?php
/**
 * License fields shown on the License tab.
 *
 * @package updatebee
 */

namespace UpdateBee\Updater;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use UpdateBee\Functions;

/**
 * Shape a stored license into display fields.
 *
 * The license key is never included.
 */
class License_Details {

	/**
	 * Store URL opened by the lifetime upsell.
	 */
	public const UPSELL_URL = 'https://updatebee.com/';

	/**
	 * Whether the updater was registered with a product id and store URL.
	 *
	 * @return bool True when activate and check can call FluentCart.
	 */
	public static function is_configured(): bool {
		try {
			$licensing = FluentLicensing::getInstance();
		} catch ( \Exception $exception ) {
			unset( $exception );
			return false;
		}

		return '' !== (string) $licensing->getConfig( 'item_id' ) && '' !== (string) $licensing->getConfig( 'api_url' );
	}

	/**
	 * Display fields for the license stored on this site.
	 *
	 * @return array<string, mixed>
	 */
	public static function from_stored(): array {
		try {
			$licensing = FluentLicensing::getInstance();
		} catch ( \Exception $exception ) {
			unset( $exception );
			return self::empty_view();
		}

		$status = $licensing->getStatus();
		if ( ! is_array( $status ) ) {
			return self::empty_view();
		}

		return self::present( $status );
	}

	/**
	 * Empty license table.
	 *
	 * @return array<string, mixed>
	 */
	public static function empty_view(): array {
		return self::present(
			array(
				'license_key' => '',
				'status'      => 'unregistered',
			)
		);
	}

	/**
	 * Build the license table payload.
	 *
	 * @param array<string, mixed> $license Stored or remote license record.
	 * @return array<string, mixed>
	 */
	public static function present( array $license ): array {
		$has_license = isset( $license['license_key'] ) && '' !== (string) $license['license_key'];
		$status      = isset( $license['status'] ) ? sanitize_key( (string) $license['status'] ) : 'unregistered';
		if ( '' === $status ) {
			$status = 'unregistered';
		}

		$expires_raw = isset( $license['expires'] ) ? trim( (string) $license['expires'] ) : '';
		$is_lifetime = 'lifetime' === strtolower( $expires_raw );
		$timestamp   = self::expiration_timestamp( $expires_raw, $is_lifetime );
		$is_past     = null !== $timestamp && $timestamp < time();
		$is_expired  = 'expired' === $status || $is_past;

		$item_name      = self::item_name( $license, $has_license );
		$variation_name = self::variation_name( $license, $has_license, $item_name );

		return array(
			'has_license'    => $has_license,
			'item_name'      => $item_name,
			'variation_name' => $variation_name,
			'status'         => $has_license ? $status : 'unregistered',
			'status_label'   => self::status_label( $has_license ? $status : 'unregistered' ),
			'status_tone'    => self::status_tone( $has_license ? $status : 'unregistered' ),
			'is_lifetime'    => $has_license && $is_lifetime,
			'activations'    => self::activations_label( $license, $has_license ),
			'expires_on'     => self::expires_on_label( $expires_raw, $is_lifetime, $timestamp, $has_license ),
			'days_left'      => self::days_left_label( $expires_raw, $is_lifetime, $is_expired, $timestamp, $has_license ),
			'show_upsell'    => $has_license && '' !== $expires_raw && ! $is_lifetime,
			'upsell_url'     => self::UPSELL_URL,
			'renewal_url'    => self::renewal_url( $license, $has_license ),
		);
	}

	/**
	 * Product title, variation title, or the plugin name.
	 *
	 * @param array<string, mixed> $license     License record.
	 * @param bool                 $has_license Whether a key is stored.
	 * @return string
	 */
	private static function item_name( array $license, bool $has_license ): string {
		if ( ! $has_license ) {
			return self::dash();
		}

		if ( ! empty( $license['product_title'] ) ) {
			return (string) $license['product_title'];
		}

		if ( ! empty( $license['variation_title'] ) ) {
			return (string) $license['variation_title'];
		}

		return Functions::get_plugin_name();
	}

	/**
	 * Variation title shown under the item name.
	 *
	 * @param array<string, mixed> $license     License record.
	 * @param bool                 $has_license Whether a key is stored.
	 * @param string               $item_name   Item name already chosen for the row.
	 * @return string
	 */
	private static function variation_name( array $license, bool $has_license, string $item_name ): string {
		if ( ! $has_license || empty( $license['variation_title'] ) ) {
			return '';
		}

		$variation_name = (string) $license['variation_title'];
		if ( $variation_name === $item_name ) {
			return '';
		}

		return $variation_name;
	}

	/**
	 * Activation count label.
	 *
	 * A limit of 0 means unlimited.
	 *
	 * @param array<string, mixed> $license     License record.
	 * @param bool                 $has_license Whether a key is stored.
	 * @return string
	 */
	private static function activations_label( array $license, bool $has_license ): string {
		if ( ! $has_license ) {
			return self::dash();
		}

		$count = isset( $license['activations_count'] ) ? absint( $license['activations_count'] ) : 0;
		$limit = isset( $license['activation_limit'] ) ? absint( $license['activation_limit'] ) : 0;

		if ( 0 === $limit ) {
			return sprintf(
				/* translators: %d: number of site activations. */
				__( '%d of Unlimited', 'updatebee' ),
				$count
			);
		}

		return sprintf(
			/* translators: 1: activation count, 2: activation limit. */
			__( '%1$d of %2$d', 'updatebee' ),
			$count,
			$limit
		);
	}

	/**
	 * Formatted expiration date.
	 *
	 * @param string   $expires_raw Raw expiration value.
	 * @param bool     $is_lifetime Whether the license never expires.
	 * @param int|null $timestamp   UTC timestamp, when the value is a date.
	 * @param bool     $has_license Whether a key is stored.
	 * @return string
	 */
	private static function expires_on_label( string $expires_raw, bool $is_lifetime, ?int $timestamp, bool $has_license ): string {
		if ( ! $has_license || '' === $expires_raw ) {
			return self::dash();
		}

		if ( $is_lifetime ) {
			return __( 'Never', 'updatebee' );
		}

		if ( null === $timestamp ) {
			return $expires_raw;
		}

		return date_i18n( 'F jS, Y', $timestamp );
	}

	/**
	 * Remaining time, Lifetime, or Expired.
	 *
	 * @param string   $expires_raw Raw expiration value.
	 * @param bool     $is_lifetime Whether the license never expires.
	 * @param bool     $is_expired  Whether the license is already expired.
	 * @param int|null $timestamp   UTC timestamp, when the value is a date.
	 * @param bool     $has_license Whether a key is stored.
	 * @return string
	 */
	private static function days_left_label( string $expires_raw, bool $is_lifetime, bool $is_expired, ?int $timestamp, bool $has_license ): string {
		if ( ! $has_license || '' === $expires_raw ) {
			return self::dash();
		}

		if ( $is_lifetime ) {
			return __( 'Lifetime', 'updatebee' );
		}

		if ( $is_expired || null === $timestamp ) {
			return __( 'Expired', 'updatebee' );
		}

		return human_time_diff( time(), $timestamp );
	}

	/**
	 * UTC timestamp for a dated expiration.
	 *
	 * @param string $expires_raw Raw expiration value.
	 * @param bool   $is_lifetime Whether the license never expires.
	 * @return int|null Timestamp, or null when the value is not a date.
	 */
	private static function expiration_timestamp( string $expires_raw, bool $is_lifetime ): ?int {
		if ( $is_lifetime || '' === $expires_raw ) {
			return null;
		}

		$timestamp = strtotime( $expires_raw . ' UTC' );
		if ( false === $timestamp ) {
			return null;
		}

		return $timestamp;
	}

	/**
	 * Human label for a license status.
	 *
	 * @param string $status License status.
	 * @return string
	 */
	private static function status_label( string $status ): string {
		switch ( $status ) {
			case 'valid':
				return __( 'Active', 'updatebee' );
			case 'expired':
				return __( 'Expired', 'updatebee' );
			case 'invalid':
				return __( 'Invalid', 'updatebee' );
			case 'disabled':
				return __( 'Disabled', 'updatebee' );
			case 'error':
				return __( 'Error', 'updatebee' );
			default:
				return __( 'Unregistered', 'updatebee' );
		}
	}

	/**
	 * Visual tone for the status pill.
	 *
	 * @param string $status License status.
	 * @return string active, warning, error, or muted.
	 */
	private static function status_tone( string $status ): string {
		switch ( $status ) {
			case 'valid':
				return 'active';
			case 'expired':
				return 'warning';
			case 'invalid':
			case 'disabled':
			case 'error':
				return 'error';
			default:
				return 'muted';
		}
	}

	/**
	 * Renewal link when the store provided one.
	 *
	 * @param array<string, mixed> $license     License record.
	 * @param bool                 $has_license Whether a key is stored.
	 * @return string
	 */
	private static function renewal_url( array $license, bool $has_license ): string {
		if ( ! $has_license ) {
			return '';
		}

		$renewal = '';
		if ( ! empty( $license['renewal_url'] ) ) {
			$renewal = (string) $license['renewal_url'];
		} elseif ( ! empty( $license['renew_url'] ) ) {
			$renewal = (string) $license['renew_url'];
		}

		if ( '' === $renewal ) {
			return '';
		}

		return esc_url_raw( $renewal );
	}

	/**
	 * Empty-cell placeholder.
	 *
	 * @return string
	 */
	private static function dash(): string {
		return "\u{2014}";
	}
}
