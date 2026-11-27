<?php
/**
 * Tests for outgoing webhooks.
 *
 * @package updatebee
 */

namespace UpdateBee\Tests;

use Brain\Monkey\Functions as MonkeyFunctions;
use UpdateBee\Webhooks\Dispatcher;
use UpdateBee\Webhooks\Rest;
use UpdateBee\Webhooks\Store;

/**
 * Webhook storage, dispatch, and admin access tests.
 */
class WebhooksTest extends TestCase {

	/**
	 * Posted request bodies.
	 *
	 * @var array<int, string>
	 */
	private array $posted_bodies = array();

	/**
	 * Stored site options.
	 *
	 * @var array<string, mixed>
	 */
	private array $site_options = array();

	/**
	 * Stub text helpers used by the sanitizer.
	 */
	protected function setUp(): void {
		parent::setUp();

		MonkeyFunctions\when( 'sanitize_text_field' )->alias(
			static function ( $value ) {
				return is_string( $value ) ? trim( $value ) : '';
			}
		);
		MonkeyFunctions\when( 'esc_url_raw' )->returnArg();
	}

	/**
	 * An http URL is rejected.
	 */
	public function test_sanitize_rejects_non_https_url(): void {
		$result = Store::sanitize(
			array(
				'name'   => 'Ops',
				'url'    => 'http://example.com/hook',
				'method' => 'POST',
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'updatebee_webhook_url', $result->get_error_code() );
	}

	/**
	 * Percent-encoding in a URL or header value survives sanitizing.
	 *
	 * The text-field double removes %XX the way production sanitize_text_field() does.
	 */
	public function test_sanitize_preserves_percent_encoded_url_and_header_value(): void {
		MonkeyFunctions\when( 'sanitize_text_field' )->alias(
			static function ( $value ) {
				$value = is_string( $value ) ? trim( $value ) : '';
				$value = preg_replace( '/%[a-f0-9]{2}/i', '', $value );

				return is_string( $value ) ? $value : '';
			}
		);

		$result = Store::sanitize(
			array(
				'name'    => 'Ops',
				'url'     => "https://example.com/hook%2Fpath\r\n",
				'method'  => 'POST',
				'headers' => array(
					array(
						'name'  => 'Authorization',
						'value' => "Bearer abc%2Fdef\n",
					),
				),
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'https://example.com/hook%2Fpath', $result['url'] );
		$this->assertSame( 'Bearer abc%2Fdef', $result['headers'][0]['value'] );
	}

	/**
	 * Methods other than POST, PUT, and PATCH are rejected.
	 */
	public function test_sanitize_rejects_unknown_method(): void {
		$result = Store::sanitize(
			array(
				'name'   => 'Ops',
				'url'    => 'https://example.com/hook',
				'method' => 'GET',
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'updatebee_webhook_method', $result->get_error_code() );
	}

	/**
	 * Unknown report keys are rejected.
	 */
	public function test_sanitize_rejects_unknown_report_key(): void {
		$result = Store::sanitize(
			array(
				'name'    => 'Ops',
				'url'     => 'https://example.com/hook',
				'method'  => 'POST',
				'reports' => array(
					'nope' => true,
				),
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'updatebee_webhook_flag', $result->get_error_code() );
	}

	/**
	 * Disabled webhooks and reports with no matching rows are not posted.
	 */
	public function test_dispatcher_skips_disabled_webhooks_and_empty_reports(): void {
		$this->stub_transport(
			array(
				$this->webhook(
					array(
						'id'      => 'wh_disabled0001',
						'enabled' => false,
						'reports' => array(
							'success'   => true,
							'failure'   => true,
							'fatal'     => true,
							'available' => true,
						),
					)
				),
				$this->webhook(
					array(
						'types'   => array(
							'core'         => false,
							'plugins'      => true,
							'themes'       => false,
							'translations' => false,
						),
						'reports' => array(
							'success'   => true,
							'failure'   => false,
							'fatal'     => false,
							'available' => false,
						),
					)
				),
			)
		);

		$dispatcher = new Dispatcher();
		$dispatcher->send_completion(
			array(
				$this->row( 'failure' ),
				$this->row( 'success', 'theme' ),
			),
			'automatic'
		);

		$this->assertCount( 0, $this->posted_bodies );
	}

	/**
	 * One request is posted for each report that has matching rows.
	 */
	public function test_dispatcher_posts_one_body_per_matching_report(): void {
		$this->stub_transport(
			array(
				$this->webhook(
					array(
						'types'   => array(
							'core'         => true,
							'plugins'      => true,
							'themes'       => false,
							'translations' => false,
						),
						'reports' => array(
							'success'   => true,
							'failure'   => true,
							'fatal'     => false,
							'available' => false,
						),
					)
				),
			)
		);

		$dispatcher = new Dispatcher();
		$dispatcher->send_completion(
			array(
				$this->row( 'success', 'plugin' ),
				$this->row( 'security', 'plugin' ),
				$this->row( 'failure', 'plugin' ),
				$this->row( 'success', 'theme' ),
				$this->row( 'fatal', 'plugin' ),
			),
			'manual'
		);

		$this->assertCount( 2, $this->posted_bodies );

		$decoded = array_map(
			static function ( $body ) {
				return json_decode( $body, true );
			},
			$this->posted_bodies
		);

		$this->assertSame( 'success', $decoded[0]['report'] );
		$this->assertSame( 'manual', $decoded[0]['trigger'] );
		$this->assertSame( 2, $decoded[0]['counts']['items'] );
		$this->assertSame( 'success', $decoded[0]['items'][0]['status'] );
		$this->assertSame( 'security', $decoded[0]['items'][1]['status'] );
		$this->assertStringNotContainsString( 'SECRET STACK', $this->posted_bodies[0] );

		$this->assertSame( 'failure', $decoded[1]['report'] );
		$this->assertSame( 1, $decoded[1]['counts']['items'] );
		$this->assertSame( 'plugin', $decoded[1]['items'][0]['type'] );
	}

	/**
	 * A user who cannot manage settings is rejected before the nonce check.
	 */
	public function test_ajax_rejects_user_without_settings_access(): void {
		MonkeyFunctions\when( 'is_multisite' )->justReturn( false );
		MonkeyFunctions\when( 'current_user_can' )->justReturn( false );
		MonkeyFunctions\when( 'wp_send_json_error' )->alias(
			static function (): void {
				throw new \RuntimeException( 'json-error' );
			}
		);

		$rest = new Rest();

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'json-error' );
		$rest->ajax_get_webhooks();
	}

	/**
	 * Stub the HTTP transport and the webhook option.
	 *
	 * @param array<int, array<string, mixed>> $webhooks Stored webhooks.
	 */
	private function stub_transport( array $webhooks ): void {
		$this->posted_bodies = array();
		$this->site_options  = array(
			Store::OPTION_KEY => $webhooks,
		);

		MonkeyFunctions\when( 'get_site_option' )->alias(
			function ( $key, $fallback = array() ) {
				return $this->site_options[ $key ] ?? $fallback;
			}
		);
		MonkeyFunctions\when( 'update_site_option' )->alias(
			function ( $key, $value ) {
				$this->site_options[ $key ] = $value;
				return true;
			}
		);
		MonkeyFunctions\when( 'apply_filters' )->returnArg( 2 );
		MonkeyFunctions\when( 'get_bloginfo' )->justReturn( 'Example' );
		MonkeyFunctions\when( 'home_url' )->justReturn( 'https://example.com/' );
		MonkeyFunctions\when( 'wp_strip_all_tags' )->alias(
			static function ( $text ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Test double for wp_strip_all_tags().
				return strip_tags( (string) $text );
			}
		);
		MonkeyFunctions\when( 'wp_json_encode' )->alias(
			static function ( $data ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Test double for wp_json_encode().
				return (string) json_encode( $data );
			}
		);
		MonkeyFunctions\when( 'current_time' )->justReturn( '2026-11-26 12:00:00' );
		MonkeyFunctions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		MonkeyFunctions\when( 'wp_safe_remote_request' )->alias(
			function ( $url, $args = array() ) {
				unset( $url );
				$this->posted_bodies[] = isset( $args['body'] ) ? (string) $args['body'] : '';
				return array( 'response' => array( 'code' => 200 ) );
			}
		);
	}

	/**
	 * A stored webhook with overridable flags.
	 *
	 * @param array<string, mixed> $overrides Fields to replace.
	 * @return array<string, mixed>
	 */
	private function webhook( array $overrides = array() ): array {
		$webhook = array(
			'id'            => 'wh_abcdefghij12',
			'name'          => 'Ops',
			'url'           => 'https://example.com/hook',
			'enabled'       => true,
			'method'        => 'POST',
			'headers'       => array(),
			'reports'       => array(
				'success'   => true,
				'failure'   => false,
				'fatal'     => false,
				'available' => false,
			),
			'types'         => array(
				'core'         => true,
				'plugins'      => true,
				'themes'       => true,
				'translations' => true,
			),
			'last_delivery' => array(
				'time'   => '',
				'status' => 0,
				'error'  => '',
			),
		);

		return array_merge( $webhook, $overrides );
	}

	/**
	 * One log-shaped row.
	 *
	 * @param string $status      Update status.
	 * @param string $update_type plugin|theme|core|translation.
	 * @return array<string, string>
	 */
	private function row( string $status, string $update_type = 'plugin' ): array {
		return array(
			'name'          => 'Akismet',
			'slug'          => 'akismet/akismet.php',
			'update_type'   => $update_type,
			'from_version'  => '5.3',
			'to_version'    => '5.4',
			'update_status' => $status,
			'raw'           => 'Installed cleanly',
			'stacktrace'    => 'SECRET STACK',
		);
	}
}
