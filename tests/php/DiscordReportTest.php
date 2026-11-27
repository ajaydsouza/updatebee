<?php
/**
 * Tests for Discord update reports.
 *
 * @package updatebee
 */

namespace UpdateBee\Tests;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions as MonkeyFunctions;
use UpdateBee\Discord\Discord_Embed;
use UpdateBee\Discord\Discord_Report;
use UpdateBee\Options;

/**
 * Discord report test case.
 */
class DiscordReportTest extends TestCase {

	/**
	 * Valid Discord incoming webhook URL.
	 */
	private const WEBHOOK = 'https://discord.com/api/webhooks/123456789012345678/abcdefghijklmnopqrstuvwxyz';

	/**
	 * Posted request bodies.
	 *
	 * @var array<int, string>
	 */
	private array $posted_bodies = array();

	/**
	 * The redirection argument of each posted request.
	 *
	 * @var array<int, mixed>
	 */
	private array $posted_redirection = array();

	/**
	 * In-memory site options.
	 *
	 * @var array<string, mixed>
	 */
	private array $site_options = array();

	/**
	 * Reset option state.
	 */
	protected function tearDown(): void {
		Options::flush_cache();
		parent::tearDown();
	}

	/**
	 * The payload is one embed and stays inside Discord's description limit.
	 */
	public function test_build_message_is_an_embed_within_the_description_limit(): void {
		$this->stub_helpers();
		$rows = array();
		for ( $index = 0; $index < 80; $index++ ) {
			$rows[] = array(
				'name'          => 'Plugin ' . $index . ' ' . str_repeat( 'x', 80 ),
				'update_type'   => 'plugin',
				'from_version'  => '1.0',
				'to_version'    => '1.1',
				'update_status' => 'success',
				'user_id'       => '0',
			);
		}

		$report  = new Discord_Report();
		$message = $report->build_message( $rows, 'automatic' );

		$this->assertArrayHasKey( 'embeds', $message );
		$this->assertArrayNotHasKey( 'blocks', $message );
		$this->assertSame( 'Updates finished', $message['embeds'][0]['title'] );
		$this->assertLessThanOrEqual(
			Discord_Embed::DESCRIPTION_LIMIT,
			mb_strlen( (string) $message['embeds'][0]['description'], 'UTF-8' )
		);
		$this->assertStringContainsString( 'and ', (string) $message['embeds'][0]['description'] );
		$this->assertStringContainsString( 'more', (string) $message['embeds'][0]['description'] );
	}

	/**
	 * A disabled toggle, an empty URL, and a non-Discord URL do not post.
	 */
	public function test_send_report_skips_when_not_ready(): void {
		$this->stub_helpers();
		$this->site_options['updatebee_options']['discord_enabled'] = false;
		MonkeyFunctions\expect( 'wp_safe_remote_post' )->never();

		$report = new Discord_Report();
		$report->send_report( $this->sample_rows(), 'automatic' );

		$this->stub_helpers( '' );
		MonkeyFunctions\expect( 'wp_safe_remote_post' )->never();
		$report->send_report( $this->sample_rows(), 'automatic' );

		$this->stub_helpers( 'https://hooks.slack.com/services/T/B/X' );
		MonkeyFunctions\expect( 'wp_safe_remote_post' )->never();
		$report->send_report( $this->sample_rows(), 'automatic' );

		$this->assertSame( array(), $this->posted_bodies );
	}

	/**
	 * Each completion action posts one embed and does not follow redirects.
	 */
	public function test_completion_actions_post_once(): void {
		$this->stub_helpers();

		$report = new Discord_Report();
		$report->send_automatic_report( $this->sample_rows() );
		$report->send_manual_report( $this->sample_rows() );

		$this->assertCount( 2, $this->posted_bodies );
		$this->assertStringContainsString( '"embeds"', $this->posted_bodies[0] );
		$this->assertStringNotContainsString( '"blocks"', $this->posted_bodies[0] );
		$this->assertStringContainsString( 'Automatic', $this->posted_bodies[0] );
		$this->assertStringContainsString( 'Manual', $this->posted_bodies[1] );
		$this->assertStringContainsString( 'Akismet', $this->posted_bodies[0] );
		$this->assertSame( array( 0, 0 ), $this->posted_redirection );
	}

	/**
	 * Run registers both completion hooks.
	 */
	public function test_run_registers_completion_hooks(): void {
		$this->expectNotToPerformAssertions();
		Actions\expectAdded( 'updatebee_automatic_updates_complete' )->once();
		Actions\expectAdded( 'updatebee_manual_updates_complete' )->once();

		$report = new Discord_Report();
		$report->run();
	}

	/**
	 * Delayed rows are omitted when the skip setting is on.
	 */
	public function test_skip_delayed_omits_delayed_rows(): void {
		$this->stub_helpers();
		$this->site_options['updatebee_options']['discord_skip_delayed'] = true;

		$report = new Discord_Report();
		$report->send_report(
			array(
				array(
					'name'          => 'Akismet',
					'update_type'   => 'plugin',
					'from_version'  => '5.0',
					'to_version'    => '5.1',
					'update_status' => 'success',
				),
				array(
					'name'          => 'Patchstack Security',
					'update_type'   => 'plugin',
					'from_version'  => '2.3.7',
					'to_version'    => '2.3.8',
					'update_status' => 'delayed',
				),
			),
			'automatic'
		);

		$this->assertCount( 1, $this->posted_bodies );
		$this->assertStringContainsString( 'Akismet', $this->posted_bodies[0] );
		$this->assertStringNotContainsString( 'Patchstack Security', $this->posted_bodies[0] );
	}

	/**
	 * A delay-only batch does not post when delayed rows are skipped.
	 */
	public function test_skip_delayed_skips_post_when_batch_is_only_delays(): void {
		$this->stub_helpers();
		$this->site_options['updatebee_options']['discord_skip_delayed'] = true;
		MonkeyFunctions\expect( 'wp_safe_remote_post' )->never();

		$report = new Discord_Report();
		$report->send_report(
			array(
				array(
					'name'          => 'Patchstack Security',
					'update_type'   => 'plugin',
					'from_version'  => '2.3.7',
					'to_version'    => '2.3.8',
					'update_status' => 'delayed',
				),
			),
			'automatic'
		);

		$this->assertSame( array(), $this->posted_bodies );
	}

	/**
	 * Stub helpers used while building and sending a report.
	 *
	 * @param string $webhook Saved webhook URL.
	 */
	private function stub_helpers( string $webhook = self::WEBHOOK ): void {
		$this->posted_bodies                     = array();
		$this->posted_redirection                = array();
		$this->site_options['updatebee_options'] = array(
			'discord_webhook_url' => $webhook,
			'discord_enabled'     => true,
		);

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
			function ( $key, $fallback = false ) {
				return array_key_exists( $key, $this->site_options ) ? $this->site_options[ $key ] : $fallback;
			}
		);
		MonkeyFunctions\when( 'get_bloginfo' )->justReturn( 'Example Site' );
		MonkeyFunctions\when( 'home_url' )->justReturn( 'https://example.com/' );
		MonkeyFunctions\when( 'esc_url_raw' )->returnArg();
		MonkeyFunctions\when( 'wp_json_encode' )->alias(
			static function ( $data ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Test double for wp_json_encode().
				return (string) json_encode( $data );
			}
		);
		MonkeyFunctions\when( 'admin_url' )->alias(
			static function ( $path = '' ) {
				return 'https://example.com/wp-admin/' . $path;
			}
		);
		MonkeyFunctions\when( 'add_query_arg' )->alias(
			static function ( $args, $url ) {
				$tab = is_array( $args ) && isset( $args['tab'] ) ? (string) $args['tab'] : '';
				return $url . '&tab=' . $tab;
			}
		);
		MonkeyFunctions\when( 'sanitize_title' )->returnArg();
		MonkeyFunctions\when( 'is_multisite' )->justReturn( false );
		MonkeyFunctions\when( 'wp_parse_url' )->alias(
			static function ( $url ) {
				$parts = parse_url( (string) $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Stub for wp_parse_url().
				return false === $parts ? false : $parts;
			}
		);
		MonkeyFunctions\when( '_n' )->alias(
			static function ( $single, $plural, $number ) {
				unset( $plural );
				return sprintf( $single, $number );
			}
		);
		MonkeyFunctions\when( 'get_userdata' )->alias(
			static function () {
				$user               = new \stdClass();
				$user->display_name = 'Ada Lovelace';
				return $user;
			}
		);
		MonkeyFunctions\when( 'wp_safe_remote_post' )->alias(
			function ( $url, $args = array() ) {
				unset( $url );
				$this->posted_bodies[]      = isset( $args['body'] ) ? (string) $args['body'] : '';
				$this->posted_redirection[] = $args['redirection'] ?? null;
				return array();
			}
		);
		Options::flush_cache();
	}

	/**
	 * Sample plugin row.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function sample_rows(): array {
		return array(
			array(
				'name'          => 'Akismet',
				'update_type'   => 'plugin',
				'from_version'  => '5.0',
				'to_version'    => '5.1',
				'update_status' => 'success',
				'user_id'       => 4,
			),
		);
	}
}
