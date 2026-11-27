<?php
/**
 * Tests for Discord webhook validation and saving.
 *
 * @package updatebee
 */

namespace UpdateBee\Tests;

use Brain\Monkey\Functions as MonkeyFunctions;
use UpdateBee\Admin\Ajax;
use UpdateBee\Discord\Discord_Webhook;
use UpdateBee\Options;

/**
 * Discord webhook test case.
 */
class DiscordWebhookTest extends TestCase {

	/**
	 * Valid Discord incoming webhook URL.
	 */
	private const WEBHOOK = 'https://discord.com/api/webhooks/123456789012345678/abcdefghijklmnopqrstuvwxyz';

	/**
	 * Options written by a successful save.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $saved_options = null;

	/**
	 * Reset the options cache.
	 */
	protected function tearDown(): void {
		Options::flush_cache();
		$_POST = array();
		parent::tearDown();
	}

	/**
	 * An empty value clears the webhook.
	 */
	public function test_sanitize_url_allows_empty(): void {
		$this->assertSame( '', Discord_Webhook::sanitize_url( '   ' ) );
	}

	/**
	 * A Discord incoming webhook URL is accepted.
	 */
	public function test_sanitize_url_accepts_discord_webhook(): void {
		$this->stub_url_helpers();

		$this->assertSame( self::WEBHOOK, Discord_Webhook::sanitize_url( self::WEBHOOK ) );
		$this->assertTrue(
			Discord_Webhook::is_valid_url( 'https://discordapp.com/api/webhooks/42/token_with-dash' )
		);
	}

	/**
	 * Other HTTPS URLs are rejected.
	 */
	public function test_sanitize_url_rejects_non_discord_url(): void {
		$this->stub_url_helpers();

		$result = Discord_Webhook::sanitize_url( 'https://hooks.slack.com/services/T/B/X' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'updatebee_discord_webhook_invalid', $result->get_error_code() );
		$this->assertFalse( Discord_Webhook::is_valid_url( 'http://discord.com/api/webhooks/42/token' ) );
		$this->assertFalse( Discord_Webhook::is_valid_url( self::WEBHOOK . '?wait=true' ) );
	}

	/**
	 * Saving a non-Discord URL does not write options.
	 */
	public function test_save_advanced_settings_rejects_non_discord_url(): void {
		$this->stub_ajax_save();
		MonkeyFunctions\expect( 'update_site_option' )->never();

		$_POST = array(
			'nonce'               => 'test',
			'discord_webhook_url' => 'https://example.com/hook',
		);

		$ajax = new Ajax();

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Enter a Discord incoming webhook URL.' );
		$ajax->save_advanced_settings();
	}

	/**
	 * Saving a Discord webhook stores it and confirms the save.
	 */
	public function test_save_advanced_settings_stores_discord_webhook(): void {
		$this->stub_ajax_save();

		$_POST = array(
			'nonce'               => 'test',
			'discord_webhook_url' => self::WEBHOOK,
		);

		$ajax = new Ajax();

		try {
			$ajax->save_advanced_settings();
			$this->fail( 'The save response was expected to end the request.' );
		} catch ( \RuntimeException $exception ) {
			$this->assertSame( 'Discord webhook has been saved.', $exception->getMessage() );
		}

		$this->assertIsArray( $this->saved_options );
		$this->assertSame( self::WEBHOOK, $this->saved_options['discord_webhook_url'] );
	}

	/**
	 * Stub URL helpers used by validation.
	 */
	private function stub_url_helpers(): void {
		MonkeyFunctions\when( 'esc_url_raw' )->returnArg();
		MonkeyFunctions\when( 'wp_parse_url' )->alias(
			static function ( $url ) {
				$parts = parse_url( (string) $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Stub for wp_parse_url().
				return false === $parts ? false : $parts;
			}
		);
	}

	/**
	 * Stub the admin-ajax save path.
	 */
	private function stub_ajax_save(): void {
		$this->saved_options = null;
		$this->stub_url_helpers();
		MonkeyFunctions\when( 'is_multisite' )->justReturn( false );
		MonkeyFunctions\when( 'current_user_can' )->justReturn( true );
		MonkeyFunctions\when( 'check_ajax_referer' )->justReturn( true );
		MonkeyFunctions\when( 'sanitize_text_field' )->returnArg( 1 );
		MonkeyFunctions\when( 'apply_filters' )->returnArg( 2 );
		MonkeyFunctions\when( 'wp_parse_args' )->alias(
			static function ( $args, $defaults ) {
				if ( ! is_array( $args ) ) {
					$args = array();
				}
				if ( ! is_array( $defaults ) ) {
					$defaults = array();
				}
				return array_merge( $defaults, $args );
			}
		);
		MonkeyFunctions\when( 'get_site_option' )->alias(
			static function ( $key, $fallback = array() ) {
				unset( $key );
				return $fallback;
			}
		);
		MonkeyFunctions\when( 'update_site_option' )->alias(
			function ( $key, $value ) {
				unset( $key );
				if ( is_array( $value ) ) {
					$this->saved_options = $value;
				}
				return true;
			}
		);
		MonkeyFunctions\when( 'wp_send_json_error' )->alias(
			static function ( $data = null ): void {
				$message = '';
				if ( is_array( $data ) && isset( $data['message'] ) ) {
					$message = (string) $data['message'];
				}

				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Test double, not rendered output.
				throw new \RuntimeException( $message );
			}
		);
		MonkeyFunctions\when( 'wp_send_json_success' )->alias(
			static function ( $data = null ): void {
				$message = '';
				if ( is_array( $data ) && isset( $data['message'] ) ) {
					$message = (string) $data['message'];
				}

				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Test double, not rendered output.
				throw new \RuntimeException( $message );
			}
		);
	}
}
