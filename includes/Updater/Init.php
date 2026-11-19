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
