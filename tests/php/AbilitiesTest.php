<?php
/**
 * Tests for the Abilities API registry.
 *
 * @package updatebee
 */

namespace UpdateBee\Tests;

use Brain\Monkey\Functions as MonkeyFunctions;
use UpdateBee\Abilities\Policy;
use UpdateBee\Abilities\Read;
use UpdateBee\Abilities\Registry;
use UpdateBee\Options;

/**
 * Abilities API test case.
 */
class AbilitiesTest extends TestCase {

	/**
	 * Ability arguments captured from wp_register_ability().
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private static $abilities = array();

	/**
	 * Category arguments captured from wp_register_ability_category().
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private static $categories = array();

	/**
	 * Reset shared option and registration state.
	 */
	protected function setUp(): void {
		parent::setUp();
		self::$abilities  = array();
		self::$categories = array();
		Options::flush_cache();
	}

	/**
	 * Clear the options cache after each test.
	 */
	protected function tearDown(): void {
		Options::flush_cache();
		parent::tearDown();
	}

	/**
	 * Stub option helpers used by Options::get_options().
	 */
	private function stub_option_helpers(): void {
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
	}

	/**
	 * Registry registers the category and public abilities when the API hooks run.
	 */
	public function test_registers_category_and_abilities_on_hooks(): void {
		$hooks = array();

		MonkeyFunctions\when( 'add_action' )->alias(
			static function ( $hook, $callback ) use ( &$hooks ) {
				$hooks[ $hook ] = $callback;
				return true;
			}
		);
		MonkeyFunctions\when( 'wp_register_ability_category' )->alias(
			static function ( $slug, $args ) {
				self::$categories[ $slug ] = $args;
				return null;
			}
		);
		MonkeyFunctions\when( 'wp_register_ability' )->alias(
			static function ( $name, $args ) {
				self::$abilities[ $name ] = $args;
				return null;
			}
		);

		Registry::boot();

		$this->assertArrayHasKey( 'wp_abilities_api_categories_init', $hooks );
		$this->assertArrayHasKey( 'wp_abilities_api_init', $hooks );
		$this->assertIsCallable( $hooks['wp_abilities_api_categories_init'] );
		$this->assertIsCallable( $hooks['wp_abilities_api_init'] );

		$hooks['wp_abilities_api_categories_init']();
		$hooks['wp_abilities_api_init']();

		$this->assertArrayHasKey( 'updatebee', self::$categories );

		$expected = array(
			'updatebee/get-update-policy',
			'updatebee/list-plugins',
			'updatebee/list-themes',
			'updatebee/list-translations',
			'updatebee/get-available-updates',
			'updatebee/get-logs',
			'updatebee/get-log',
			'updatebee/get-fatal-findings',
			'updatebee/get-update-schedule',
			'updatebee/set-update-policy',
			'updatebee/set-plugin-policy',
			'updatebee/set-theme-policy',
			'updatebee/set-translation-policy',
			'updatebee/refresh-updates',
			'updatebee/schedule-update-checks',
			'updatebee/run-auto-updates',
		);

		foreach ( $expected as $name ) {
			$this->assertArrayHasKey( $name, self::$abilities );
			$this->assertTrue( self::$abilities[ $name ]['meta']['public'] );
			$this->assertTrue( self::$abilities[ $name ]['meta']['show_in_rest'] );
			$this->assertSame( array( Registry::class, 'can_execute' ), self::$abilities[ $name ]['permission_callback'] );
		}

		$this->assertTrue( self::$abilities['updatebee/get-update-policy']['meta']['annotations']['readonly'] );
		$this->assertFalse( self::$abilities['updatebee/get-update-policy']['meta']['annotations']['destructive'] );
		$this->assertTrue( self::$abilities['updatebee/run-auto-updates']['meta']['annotations']['destructive'] );
		$this->assertFalse( self::$abilities['updatebee/run-auto-updates']['meta']['annotations']['readonly'] );
		$this->assertFalse( self::$abilities['updatebee/run-auto-updates']['meta']['annotations']['idempotent'] );
		$this->assertStringContainsString( 'can change the site', self::$abilities['updatebee/run-auto-updates']['description'] );
	}

	/**
	 * Policy output includes flags and omits the Slack webhook URL.
	 */
	public function test_get_update_policy_omits_secrets(): void {
		$this->stub_option_helpers();
		MonkeyFunctions\expect( 'get_site_option' )
			->once()
			->with( 'updatebee_options', array() )
			->andReturn(
				array(
					'major_auto_updates' => 'on',
					'slack_webhook_url'  => 'https://hooks.slack.com/services/T00/B00/secret',
					'branding_name'      => 'Secret Brand',
				)
			);

		$policy = Read::get_update_policy();

		$this->assertSame( 'on', $policy['major_auto_updates'] );
		$this->assertSame( 'on', $policy['minor_auto_updates'] );
		$this->assertTrue( $policy['slack_configured'] );
		$this->assertFalse( $policy['discord_configured'] );
		$this->assertArrayNotHasKey( 'slack_webhook_url', $policy );
		$this->assertArrayNotHasKey( 'discord_webhook_url', $policy );
		$this->assertArrayNotHasKey( 'branding_name', $policy );
		$this->assertArrayNotHasKey( 'branding_description', $policy );
		$this->assertNotSame( 'https://hooks.slack.com/services/T00/B00/secret', $policy['upgrade_notice_keywords'] );
	}

	/**
	 * A saved Discord webhook is reported as configured without returning the URL.
	 */
	public function test_get_update_policy_reports_discord_without_url(): void {
		$this->stub_option_helpers();
		MonkeyFunctions\when( 'wp_parse_url' )->alias(
			static function ( $url ) {
				$parts = parse_url( (string) $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Stub for wp_parse_url().
				return false === $parts ? false : $parts;
			}
		);
		MonkeyFunctions\expect( 'get_site_option' )
			->once()
			->with( 'updatebee_options', array() )
			->andReturn(
				array(
					'discord_webhook_url' => 'https://discord.com/api/webhooks/123456789012345678/abcdefghijklmnopqrstuvwxyz',
				)
			);

		$policy = Read::get_update_policy();

		$this->assertTrue( $policy['discord_configured'] );
		$this->assertArrayNotHasKey( 'discord_webhook_url', $policy );
	}

	/**
	 * Unknown policy keys are rejected before anything is saved.
	 */
	public function test_set_update_policy_rejects_unknown_keys(): void {
		$result = Policy::set_update_policy(
			array(
				'slack_webhook_url' => 'https://hooks.slack.com/services/T00/B00/secret',
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'updatebee_unknown_policy_key', $result->get_error_code() );
	}

	/**
	 * A known on/off key is persisted through Options.
	 */
	public function test_set_update_policy_persists_known_key(): void {
		$this->stub_option_helpers();
		MonkeyFunctions\expect( 'get_site_option' )
			->once()
			->with( 'updatebee_options', array() )
			->andReturn( array() );
		MonkeyFunctions\expect( 'update_site_option' )
			->once()
			->with(
				'updatebee_options',
				\Mockery::on(
					static function ( $options ) {
						return is_array( $options ) && 'on' === $options['plugin_auto_updates'];
					}
				)
			)
			->andReturn( true );

		$updated = Policy::set_update_policy(
			array(
				'plugin_auto_updates' => 'on',
			)
		);

		$this->assertIsArray( $updated );
		$this->assertSame( 'on', $updated['plugin_auto_updates'] );
		$this->assertArrayNotHasKey( 'slack_webhook_url', $updated );
	}

	/**
	 * A failed option write is reported as a policy save failure.
	 */
	public function test_set_update_policy_reports_save_failure(): void {
		$this->stub_option_helpers();
		MonkeyFunctions\when( 'get_site_option' )->justReturn( array() );
		MonkeyFunctions\when( 'update_site_option' )->justReturn( false );

		$result = Policy::set_update_policy(
			array(
				'plugin_auto_updates' => 'on',
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'updatebee_policy_save_failed', $result->get_error_code() );
	}

	/**
	 * An invalid translation field rejects the request before an earlier valid field is saved.
	 */
	public function test_set_translation_policy_validates_all_fields_before_saving(): void {
		MonkeyFunctions\when( 'wp_get_available_translations' )->justReturn(
			array(
				'da_DK' => array(
					'english_name' => 'Danish',
				),
			)
		);
		MonkeyFunctions\when( 'apply_filters' )->returnArg( 2 );
		MonkeyFunctions\expect( 'update_site_option' )->never();

		$result = Policy::set_translation_policy(
			array(
				'slug'        => 'da_DK',
				'auto_update' => true,
				'blocked'     => 'yes',
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'updatebee_invalid_policy_value', $result->get_error_code() );
	}

	/**
	 * An invalid field rejects the request before an earlier valid field is saved.
	 */
	public function test_set_plugin_policy_validates_all_fields_before_saving(): void {
		MonkeyFunctions\when( 'get_plugins' )->justReturn(
			array(
				'akismet/akismet.php' => array(
					'Name' => 'Akismet',
				),
			)
		);
		MonkeyFunctions\when( 'apply_filters' )->returnArg( 2 );
		MonkeyFunctions\expect( 'update_site_option' )->never();

		$result = Policy::set_plugin_policy(
			array(
				'file'    => 'akismet/akismet.php',
				'blocked' => true,
				'notes'   => 12,
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'updatebee_invalid_policy_value', $result->get_error_code() );
	}

	/**
	 * Missing plugin files do not change policy.
	 */
	public function test_set_plugin_policy_returns_error_for_missing_file(): void {
		MonkeyFunctions\when( 'get_plugins' )->justReturn( array() );
		MonkeyFunctions\when( 'apply_filters' )->returnArg( 2 );

		$result = Policy::set_plugin_policy(
			array(
				'file'        => 'missing/missing.php',
				'auto_update' => true,
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'updatebee_plugin_not_found', $result->get_error_code() );
	}

	/**
	 * The permission callback denies users who cannot manage settings.
	 */
	public function test_permission_callback_denies_users_without_access(): void {
		MonkeyFunctions\when( 'is_multisite' )->justReturn( false );
		MonkeyFunctions\when( 'is_network_admin' )->justReturn( false );
		MonkeyFunctions\when( 'is_plugin_active_for_network' )->justReturn( false );
		MonkeyFunctions\when( 'current_user_can' )->justReturn( false );

		$this->assertFalse( Registry::can_execute() );
	}
}
