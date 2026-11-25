<?php
/**
 * Tests for the branding resolver.
 *
 * @package updatebee
 */

namespace UpdateBee\Tests;

use Brain\Monkey\Functions as MonkeyFunctions;
use UpdateBee\Branding;
use UpdateBee\Options;

/**
 * Branding test case.
 */
class BrandingTest extends TestCase {

	/**
	 * Reset caches after each test.
	 */
	protected function tearDown(): void {
		Options::flush_cache();
		Branding::flush_cache();
		parent::tearDown();
	}

	/**
	 * Stub option helpers with optional stored branding values.
	 *
	 * @param array<string, mixed> $stored Stored option overrides.
	 */
	private function stub_options( array $stored = array() ): void {
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
			static function ( $key, $fallback = false ) use ( $stored ) {
				if ( 'updatebee_options' === $key ) {
					return $stored;
				}

				return $fallback;
			}
		);
		MonkeyFunctions\when( 'plugin_dir_url' )->alias(
			static function () {
				return 'https://example.com/wp-content/plugins/updatebee/';
			}
		);
		MonkeyFunctions\when( 'absint' )->alias(
			static function ( $value ) {
				return abs( (int) $value );
			}
		);
		MonkeyFunctions\when( 'update_site_option' )->justReturn( true );
	}

	/**
	 * Stub the sanitizers used by locked branding values.
	 */
	private function stub_sanitizers(): void {
		MonkeyFunctions\when( 'sanitize_text_field' )->alias(
			static function ( $value ) {
				return is_string( $value ) ? trim( $value ) : '';
			}
		);
		MonkeyFunctions\when( 'sanitize_textarea_field' )->alias(
			static function ( $value ) {
				return is_string( $value ) ? trim( $value ) : '';
			}
		);
		MonkeyFunctions\when( 'esc_url_raw' )->alias(
			static function ( $value ) {
				return is_string( $value ) ? $value : '';
			}
		);
	}

	/**
	 * Empty branding options fall back to the default plugin name.
	 */
	public function test_filter_plugin_name_falls_back_when_empty(): void {
		$this->stub_options();
		$branding = new Branding();

		$this->assertSame( 'UpdateBee', $branding->filter_plugin_name( 'UpdateBee' ) );
	}

	/**
	 * A saved name replaces the default display name.
	 */
	public function test_filter_plugin_name_uses_saved_value(): void {
		$this->stub_options(
			array(
				'branding_name' => 'Agency Updates',
			)
		);
		$branding = new Branding();

		$this->assertSame( 'Agency Updates', $branding->filter_plugin_name( 'UpdateBee' ) );
	}

	/**
	 * The Plugins screen row is left alone when branding fields are empty.
	 */
	public function test_filter_all_plugins_leaves_defaults_when_empty(): void {
		$this->stub_options();
		MonkeyFunctions\when( 'plugin_basename' )->justReturn( 'updatebee/updatebee.php' );

		$plugins = array(
			'updatebee/updatebee.php' => array(
				'Name'        => 'UpdateBee',
				'Title'       => 'UpdateBee',
				'Description' => 'Take complete control over your automatic updates.',
				'Author'      => 'MediaRon LLC',
				'AuthorName'  => 'MediaRon LLC',
				'PluginURI'   => 'https://updatebee.com',
				'AuthorURI'   => 'https://updatebee.com',
			),
		);

		$branding = new Branding();
		$filtered = $branding->filter_all_plugins( $plugins );

		$this->assertSame( 'UpdateBee', $filtered['updatebee/updatebee.php']['Name'] );
		$this->assertSame( 'MediaRon LLC', $filtered['updatebee/updatebee.php']['Author'] );
		$this->assertSame( 'https://updatebee.com', $filtered['updatebee/updatebee.php']['PluginURI'] );
	}

	/**
	 * Saved branding overrides this plugin's Plugins screen row.
	 */
	public function test_filter_all_plugins_applies_saved_branding(): void {
		$this->stub_options(
			array(
				'branding_name'        => 'Agency Updates',
				'branding_description' => 'Managed automatic updates.',
				'branding_author'      => 'Agency Co',
				'branding_url'         => 'https://agency.example/',
			)
		);
		MonkeyFunctions\when( 'plugin_basename' )->justReturn( 'updatebee/updatebee.php' );

		$plugins = array(
			'updatebee/updatebee.php' => array(
				'Name'        => 'UpdateBee',
				'Title'       => 'UpdateBee',
				'Description' => 'Take complete control over your automatic updates.',
				'Author'      => 'MediaRon LLC',
				'AuthorName'  => 'MediaRon LLC',
				'PluginURI'   => 'https://updatebee.com',
				'AuthorURI'   => 'https://updatebee.com',
			),
			'akismet/akismet.php'     => array(
				'Name' => 'Akismet',
			),
		);

		$branding = new Branding();
		$filtered = $branding->filter_all_plugins( $plugins );

		$this->assertSame( 'Agency Updates', $filtered['updatebee/updatebee.php']['Name'] );
		$this->assertSame( 'Agency Updates', $filtered['updatebee/updatebee.php']['Title'] );
		$this->assertSame( 'Managed automatic updates.', $filtered['updatebee/updatebee.php']['Description'] );
		$this->assertSame( 'Agency Co', $filtered['updatebee/updatebee.php']['Author'] );
		$this->assertSame( 'Agency Co', $filtered['updatebee/updatebee.php']['AuthorName'] );
		$this->assertSame( 'https://agency.example/', $filtered['updatebee/updatebee.php']['PluginURI'] );
		$this->assertSame( 'https://agency.example/', $filtered['updatebee/updatebee.php']['AuthorURI'] );
		$this->assertSame( 'Akismet', $filtered['akismet/akismet.php']['Name'] );
	}

	/**
	 * The bundled logo URL is used when no custom name is set.
	 */
	public function test_get_default_logo_url_returns_bundled_asset(): void {
		$this->stub_options();

		$this->assertSame(
			'https://example.com/wp-content/plugins/updatebee/assets/logo.png',
			Branding::get_default_logo_url()
		);
	}

	/**
	 * Sanitize branding settings and trim fields.
	 */
	public function test_sanitize_settings_cleans_fields(): void {
		MonkeyFunctions\when( 'sanitize_text_field' )->alias(
			static function ( $value ) {
				return is_string( $value ) ? trim( $value ) : '';
			}
		);
		MonkeyFunctions\when( 'sanitize_textarea_field' )->alias(
			static function ( $value ) {
				return is_string( $value ) ? trim( $value ) : '';
			}
		);
		MonkeyFunctions\when( 'esc_url_raw' )->alias(
			static function ( $value ) {
				return is_string( $value ) ? $value : '';
			}
		);

		$sanitized = Branding::sanitize_settings(
			array(
				'branding_name'        => ' Agency Updates ',
				'branding_description' => ' Managed updates. ',
				'branding_author'      => ' Agency Co ',
				'branding_url'         => 'https://agency.example/',
			)
		);

		$this->assertSame( 'Agency Updates', $sanitized['branding_name'] );
		$this->assertSame( 'Managed updates.', $sanitized['branding_description'] );
		$this->assertSame( 'Agency Co', $sanitized['branding_author'] );
		$this->assertSame( 'https://agency.example/', $sanitized['branding_url'] );
		$this->assertArrayNotHasKey( 'branding_logo_id', $sanitized );
	}

	/**
	 * Header defaults come from plugin header data.
	 */
	public function test_get_header_defaults_uses_plugin_data(): void {
		$this->stub_options();
		MonkeyFunctions\when( 'get_plugin_data' )->justReturn(
			array(
				'Name'        => 'UpdateBee',
				'Description' => 'Take complete control over your automatic updates.',
				'Author'      => '<a href="https://updatebee.com">MediaRon LLC</a>',
				'PluginURI'   => 'https://updatebee.com',
			)
		);
		MonkeyFunctions\when( 'wp_strip_all_tags' )->alias(
			static function ( $value ) {
				return trim( preg_replace( '/<[^>]*>/', '', (string) $value ) );
			}
		);

		$defaults = Branding::get_header_defaults();

		$this->assertSame( 'UpdateBee', $defaults['name'] );
		$this->assertSame( 'Take complete control over your automatic updates.', $defaults['description'] );
		$this->assertSame( 'MediaRon LLC', $defaults['author'] );
		$this->assertSame( 'https://updatebee.com', $defaults['url'] );
		$this->assertArrayNotHasKey( 'logo_url', $defaults );
	}

	/**
	 * An inactive license cannot turn the hide flag on or clear it.
	 */
	public function test_resolve_hide_license_tab_keeps_stored_value_when_inactive(): void {
		$this->assertFalse( Branding::resolve_hide_license_tab( true, false, false ) );
		$this->assertTrue( Branding::resolve_hide_license_tab( true, false, true ) );
		$this->assertTrue( Branding::resolve_hide_license_tab( false, false, true ) );
	}

	/**
	 * An active license stores the requested hide value.
	 */
	public function test_resolve_hide_license_tab_follows_request_when_active(): void {
		$this->assertTrue( Branding::resolve_hide_license_tab( true, true, false ) );
		$this->assertFalse( Branding::resolve_hide_license_tab( false, true, true ) );
	}

	/**
	 * An inactive license is never locked. An active license follows the requested lock.
	 */
	public function test_lock_applies_requires_an_active_license(): void {
		MonkeyFunctions\when( 'apply_filters' )->returnArg( 2 );

		$this->assertFalse( Branding::lock_applies( false, true ) );
		$this->assertFalse( Branding::lock_applies( true, false ) );
		$this->assertTrue( Branding::lock_applies( true, true ) );
	}

	/**
	 * The lock filter can lock branding without the constant.
	 */
	public function test_lock_filter_can_force_the_lock(): void {
		MonkeyFunctions\when( 'apply_filters' )->alias(
			static function ( $hook, $value ) {
				if ( 'updatebee_branding_locked' === $hook ) {
					return true;
				}

				return $value;
			}
		);

		$this->assertTrue( Branding::lock_applies( true, false ) );
		$this->assertFalse( Branding::lock_applies( false, false ) );
	}

	/**
	 * The values filter replaces constant branding while locked.
	 */
	public function test_branding_filter_replaces_constant_values(): void {
		$this->stub_sanitizers();
		MonkeyFunctions\when( 'apply_filters' )->alias(
			static function ( $hook, $value ) {
				if ( 'updatebee_branding' === $hook && is_array( $value ) ) {
					$value['name']         = 'Filtered Agency';
					$value['hide_license'] = true;
					return $value;
				}

				return $value;
			}
		);

		$config = Branding::resolve_locked_config(
			array(
				'name'         => 'Constant Agency',
				'hide_license' => false,
			)
		);

		$this->assertSame( 'Filtered Agency', $config['name'] );
		$this->assertTrue( $config['hide_license'] );
	}

	/**
	 * An active lock uses filter values instead of the stored option.
	 */
	public function test_saved_getters_use_lock_values_when_license_is_active(): void {
		$this->stub_options(
			array(
				'branding_name'             => 'Stored Agency',
				'branding_hide_license_tab' => false,
			)
		);
		$this->stub_sanitizers();
		$this->force_lock(
			array(
				'name'         => 'Locked Agency',
				'description'  => 'Managed updates.',
				'author'       => 'Agency Co',
				'url'          => 'https://agency.example/',
				'hide_license' => true,
			)
		);

		$this->assertTrue( Branding::is_locked() );
		$this->assertSame( 'Locked Agency', Branding::get_saved_name() );
		$this->assertSame( 'Managed updates.', Branding::get_saved_description() );
		$this->assertSame( 'Agency Co', Branding::get_saved_author() );
		$this->assertSame( 'https://agency.example/', Branding::get_saved_url() );
		$this->assertTrue( Branding::hides_license_tab() );
		$this->assertSame( 'Stored Agency', Branding::get_stored()['branding_name'] );
	}

	/**
	 * An inactive license ignores the lock and reads stored branding.
	 */
	public function test_saved_getters_use_stored_values_when_license_is_inactive(): void {
		$this->stub_options(
			array(
				'branding_name'             => 'Stored Agency',
				'branding_hide_license_tab' => true,
			)
		);
		$this->stub_sanitizers();
		Branding::set_license_active_override( false );
		MonkeyFunctions\when( 'apply_filters' )->alias(
			static function ( $hook, $value ) {
				if ( 'updatebee_branding_locked' === $hook ) {
					return true;
				}
				if ( 'updatebee_branding' === $hook && is_array( $value ) ) {
					$value['name'] = 'Locked Agency';
					return $value;
				}

				return $value;
			}
		);

		$this->assertFalse( Branding::is_locked() );
		$this->assertSame( 'Stored Agency', Branding::get_saved_name() );
		$this->assertFalse( Branding::hides_license_tab() );
	}

	/**
	 * Legacy branding keys are copied into the branding option.
	 */
	public function test_legacy_branding_is_copied_out_of_main_options(): void {
		$stored  = array(
			Options::OPTIONS_KEY => array(
				'branding_name' => 'Agency Updates',
				'delay_updates' => 2,
			),
		);
		$persist = true;
		$this->stub_site_option_store( $stored, $persist );

		$this->assertSame( 'Agency Updates', Branding::get_saved_name() );
		$this->assertSame( 'Agency Updates', $stored[ Branding::OPTION_KEY ]['branding_name'] );
		$this->assertArrayNotHasKey( 'branding_name', $stored[ Options::OPTIONS_KEY ] );
		$this->assertSame( 2, $stored[ Options::OPTIONS_KEY ]['delay_updates'] );
	}

	/**
	 * A failed branding copy leaves the legacy keys in place for another try.
	 */
	public function test_legacy_branding_stays_when_the_copy_does_not_persist(): void {
		$stored  = array(
			Options::OPTIONS_KEY => array(
				'branding_name' => 'Agency Updates',
				'delay_updates' => 2,
			),
		);
		$persist = false;
		$this->stub_site_option_store( $stored, $persist );

		$this->assertSame( 'Agency Updates', Branding::get_saved_name() );
		$this->assertArrayNotHasKey( Branding::OPTION_KEY, $stored );
		$this->assertSame( 'Agency Updates', $stored[ Options::OPTIONS_KEY ]['branding_name'] );
		$this->assertSame( 2, $stored[ Options::OPTIONS_KEY ]['delay_updates'] );

		$persist = true;

		$this->assertSame( 'Agency Updates', Branding::get_saved_name() );
		$this->assertSame( 'Agency Updates', $stored[ Branding::OPTION_KEY ]['branding_name'] );
		$this->assertArrayNotHasKey( 'branding_name', $stored[ Options::OPTIONS_KEY ] );
		$this->assertSame( 2, $stored[ Options::OPTIONS_KEY ]['delay_updates'] );
	}

	/**
	 * Cleanup runs when the branding option matches, even if the write returns false.
	 */
	public function test_legacy_branding_is_removed_when_the_read_back_matches(): void {
		$stored  = array(
			Options::OPTIONS_KEY => array(
				'branding_name' => 'Agency Updates',
				'delay_updates' => 2,
			),
		);
		$persist = true;
		$this->stub_site_option_store( $stored, $persist, false );

		$this->assertSame( 'Agency Updates', Branding::get_saved_name() );
		$this->assertSame( 'Agency Updates', $stored[ Branding::OPTION_KEY ]['branding_name'] );
		$this->assertArrayNotHasKey( 'branding_name', $stored[ Options::OPTIONS_KEY ] );
		$this->assertSame( 2, $stored[ Options::OPTIONS_KEY ]['delay_updates'] );
	}

	/**
	 * Stub site options backed by a store the test can change.
	 *
	 * @param array<string, mixed> $stored                   Option values keyed by option name.
	 * @param bool                 $persist_branding         Whether a branding write is visible to the next read.
	 * @param bool                 $branding_update_succeeds Return value for the branding option write.
	 */
	private function stub_site_option_store( array &$stored, bool &$persist_branding, bool $branding_update_succeeds = true ): void {
		MonkeyFunctions\when( 'get_site_option' )->alias(
			static function ( $key, $fallback = false ) use ( &$stored ) {
				if ( array_key_exists( $key, $stored ) ) {
					return $stored[ $key ];
				}

				return $fallback;
			}
		);
		MonkeyFunctions\when( 'update_site_option' )->alias(
			static function ( $key, $value ) use ( &$stored, &$persist_branding, $branding_update_succeeds ) {
				$is_branding = Branding::OPTION_KEY === $key;
				if ( $is_branding && ! $persist_branding ) {
					return false;
				}

				$stored[ $key ] = $value;

				if ( $is_branding ) {
					return $branding_update_succeeds;
				}

				return true;
			}
		);
	}

	/**
	 * Force an active license and locked branding values.
	 *
	 * @param array<string, mixed> $values Locked branding values.
	 */
	private function force_lock( array $values ): void {
		Branding::set_license_active_override( true );
		MonkeyFunctions\when( 'apply_filters' )->alias(
			static function ( $hook, $value ) use ( $values ) {
				if ( 'updatebee_branding_locked' === $hook ) {
					return true;
				}
				if ( 'updatebee_branding' === $hook && is_array( $value ) ) {
					return array_merge( $value, $values );
				}

				return $value;
			}
		);
	}
}
