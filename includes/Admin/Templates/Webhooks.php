<?php
/**
 * Register the Webhooks tab.
 *
 * @package updatebee
 */

namespace UpdateBee\Admin\Templates;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use UpdateBee\Functions;
use UpdateBee\Webhooks\Rest;
use UpdateBee\Webhooks\Store;

/**
 * Output the webhooks tab and content.
 */
class Webhooks {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_filter( 'updatebee_admin_tabs', array( $this, 'add_webhooks_tab' ), 6 );
		add_action( 'updatebee_output_webhooks', array( $this, 'output_webhooks_content' ), 1, 2 );
	}

	/**
	 * Add the webhooks tab.
	 *
	 * @param array<int, array<string, string>> $tabs Array of tabs.
	 * @return array<int, array<string, string>> Array of tabs.
	 */
	public function add_webhooks_tab( array $tabs ): array {
		$tabs[] = array(
			'get'    => 'webhooks',
			'action' => 'updatebee_output_webhooks',
			'url'    => Functions::get_settings_url( 'webhooks' ),
			'label'  => _x( 'Webhooks', 'Tab label as Webhooks', 'updatebee' ),
			'icon'   => 'icon-webhook',
		);

		return $tabs;
	}

	/**
	 * Output webhooks screen content.
	 *
	 * @param string      $tab     Current tab.
	 * @param string|null $sub_tab Current sub-tab.
	 */
	public function output_webhooks_content( string $tab, $sub_tab = '' ): void {
		if ( 'webhooks' !== $tab ) {
			return;
		}

		$asset_file = Functions::get_plugin_dir( 'build/webhooks.asset.php' );
		$deps       = array(
			'dependencies' => array(),
			'version'      => Functions::get_plugin_version(),
		);
		if ( file_exists( $asset_file ) ) {
			$loaded = require $asset_file;
			if ( is_array( $loaded ) ) {
				$deps = wp_parse_args( $loaded, $deps );
			}
		}

		$style_deps = array( 'updatebee-admin' );
		if ( wp_style_is( 'wp-dataviews', 'registered' ) ) {
			$style_deps[] = 'wp-dataviews';
		}

		$list_url  = Functions::get_settings_url( 'webhooks' );
		$screen    = 'list';
		$id        = '';
		$requested = filter_input( INPUT_GET, 'webhook', FILTER_DEFAULT );
		if ( is_string( $requested ) && '' !== $requested ) {
			$requested = sanitize_text_field( $requested );
			if ( 'new' === $requested || Store::is_id( $requested ) ) {
				$screen = 'edit';
				$id     = $requested;
			}
		}

		wp_enqueue_script(
			'updatebee-webhooks',
			Functions::get_plugin_url( 'build/webhooks.js' ),
			$deps['dependencies'],
			$deps['version'],
			true
		);
		Functions::set_script_translations( 'updatebee-webhooks' );
		wp_enqueue_style(
			'updatebee-webhooks',
			Functions::get_plugin_url( 'build/webhooks.css' ),
			$style_deps,
			is_string( $deps['version'] ) ? $deps['version'] : Functions::get_plugin_version()
		);

		wp_localize_script(
			'updatebee-webhooks',
			'updatebeeWebhooks',
			array(
				'screen'        => $screen,
				'webhookId'     => $id,
				'listUrl'       => esc_url_raw( $list_url ),
				'newUrl'        => esc_url_raw( add_query_arg( 'webhook', 'new', $list_url ) ),
				'listNonce'     => wp_create_nonce( Rest::NONCE_LIST ),
				'retrieveNonce' => wp_create_nonce( Rest::NONCE_GET ),
				'saveNonce'     => wp_create_nonce( Rest::NONCE_SAVE ),
				'testNonce'     => wp_create_nonce( Rest::NONCE_TEST ),
			)
		);

		unset( $sub_tab );
		?>
		<div id="updatebee-webhooks-container"></div>
		<?php
	}
}
