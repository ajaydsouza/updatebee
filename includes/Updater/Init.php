<?php
/**
 * Boot the FluentCart updater.
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
 * Register licensing and plugin update checks.
 */
class Init {

	/**
	 * Option key for the stored license record.
	 */
	public const SETTINGS_KEY = '__updatebee_sl_info';

	/**
	 * Transient that backs off a failed activation of a code-defined key.
	 */
	private const DEFINED_LICENSE_SYNC = 'updatebee_defined_license_sync';

	/**
	 * Class runner.
	 */
	public function run(): void {
		$updater_dir = dirname( UPDATEBEE_FILE ) . '/updater/';

		if ( ! class_exists( FluentLicensing::class ) ) {
			require_once $updater_dir . 'FluentLicensing.php';
		}

		if ( ! class_exists( PluginUpdater::class ) ) {
			require_once $updater_dir . 'PluginUpdater.php';
		}

		$licensing = new FluentLicensing();
		$licensing->register( self::get_config() );
		self::maybe_activate_defined_key( $licensing );
	}

	/**
	 * Activate a license key set in wp-config.php or an mu-plugin.
	 *
	 * Skips front-end requests. A stored record for this key with status
	 * valid, expired, or disabled is left alone. A failed request waits an
	 * hour before trying that same key again. The previous site activation
	 * is not revoked at the store.
	 *
	 * @param FluentLicensing $licensing Registered licensing client.
	 */
	private static function maybe_activate_defined_key( FluentLicensing $licensing ): void {
		$defined = License_Key::defined_key();
		if ( '' === $defined || ! self::in_sync_context() ) {
			return;
		}

		if ( License_Key::record_matches( $licensing->getStatus(), $defined ) ) {
			return;
		}

		$lock = get_transient( self::DEFINED_LICENSE_SYNC );
		if ( is_string( $lock ) && hash_equals( wp_hash( $defined ), $lock ) ) {
			return;
		}

		$activated = $licensing->activate( $defined );
		if ( ! is_array( $activated ) || ! License_Key::record_matches( $activated, $defined ) ) {
			set_transient( self::DEFINED_LICENSE_SYNC, wp_hash( $defined ), HOUR_IN_SECONDS );
		}
	}

	/**
	 * Whether the defined key may be activated on this request.
	 *
	 * @return bool
	 */
	private static function in_sync_context(): bool {
		$is_admin = function_exists( 'is_admin' ) && is_admin();
		$is_cron  = function_exists( 'wp_doing_cron' ) && wp_doing_cron();
		$is_cli   = defined( 'WP_CLI' ) && WP_CLI;

		return License_Key::should_sync( $is_admin, $is_cron, $is_cli );
	}

	/**
	 * Licensing configuration for FluentCart.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_config(): array {
		$store_url = 'https://updatebee.com/';
		if ( defined( 'UPDATEBEE_STORE_URL' ) && is_string( UPDATEBEE_STORE_URL ) && '' !== UPDATEBEE_STORE_URL ) {
			$store_url = UPDATEBEE_STORE_URL;
		}

		$item_id = '43033';
		if ( defined( 'UPDATEBEE_LICENSE_ITEM_ID' ) && '' !== (string) UPDATEBEE_LICENSE_ITEM_ID ) {
			$item_id = (string) UPDATEBEE_LICENSE_ITEM_ID;
		}

		$config = array(
			'version'      => Functions::get_plugin_version(),
			'item_id'      => $item_id,
			'basename'     => Functions::get_plugin_file(),
			'api_url'      => $store_url,
			'store_url'    => $store_url,
			'plugin_title' => 'UpdateBee',
			'purchase_url' => 'https://updatebee.com/',
			'activate_url' => Functions::get_settings_url( 'license' ),
			'settings_key' => self::SETTINGS_KEY,
		);

		/**
		 * Filter the FluentCart licensing configuration.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, mixed> $config Licensing configuration.
		 */
		$filtered = apply_filters( 'updatebee_license_config', $config );
		if ( ! is_array( $filtered ) ) {
			return $config;
		}

		return $filtered;
	}
}
