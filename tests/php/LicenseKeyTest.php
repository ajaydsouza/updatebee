<?php
/**
 * Tests for a license key set in code.
 *
 * The suite does not define UPDATEBEE_LICENSE_KEY. Constant values are passed
 * into the resolver, and the filter is stubbed for the live lookup.
 *
 * @package updatebee
 */

namespace UpdateBee\Tests;

use Brain\Monkey\Functions as MonkeyFunctions;
use UpdateBee\Updater\License_Key;

/**
 * License_Key resolution and License screen guard.
 */
class LicenseKeyTest extends TestCase {

	/**
	 * Stub the sanitizer used for defined keys.
	 */
	private function stub_sanitizer(): void {
		MonkeyFunctions\when( 'sanitize_text_field' )->alias(
			static function ( $value ) {
				return is_string( $value ) ? trim( $value ) : '';
			}
		);
	}

	/**
	 * Stub updatebee_license_key.
	 *
	 * @param mixed $filtered Value the filter returns.
	 */
	private function stub_license_key_filter( $filtered ): void {
		MonkeyFunctions\when( 'apply_filters' )->alias(
			static function ( $hook, $value ) use ( $filtered ) {
				if ( 'updatebee_license_key' === $hook ) {
					return $filtered;
				}

				return $value;
			}
		);
	}

	/**
	 * A constant value is used when the filter does not replace it.
	 */
	public function test_resolve_uses_the_constant_when_the_filter_is_empty(): void {
		$this->stub_sanitizer();

		$this->assertSame( 'constant-key', License_Key::resolve_defined_key( 'constant-key', '' ) );
		$this->assertSame( 'constant-key', License_Key::resolve_defined_key( 'constant-key', '   ' ) );
	}

	/**
	 * A non-empty filter value replaces the constant.
	 */
	public function test_resolve_prefers_a_non_empty_filter_value(): void {
		$this->stub_sanitizer();

		$this->assertSame(
			'filter-key',
			License_Key::resolve_defined_key( 'constant-key', ' filter-key ' )
		);
	}

	/**
	 * An empty filter and an empty constant leave the saved key in place.
	 */
	public function test_empty_filter_falls_back_to_storage(): void {
		$this->stub_sanitizer();
		MonkeyFunctions\when( 'apply_filters' )->returnArg( 2 );

		$this->assertSame( '', License_Key::defined_key() );
		$this->assertFalse( License_Key::is_defined() );
		$this->assertSame( '', License_Key::resolve_defined_key( '', '' ) );
	}

	/**
	 * Non-strings from the filter are ignored.
	 */
	public function test_resolve_ignores_non_string_filter_values(): void {
		$this->stub_sanitizer();

		$this->assertSame( 'constant-key', License_Key::resolve_defined_key( 'constant-key', array( 'nope' ) ) );
		$this->assertSame( 'constant-key', License_Key::resolve_defined_key( 'constant-key', 42 ) );
		$this->assertSame( '', License_Key::resolve_defined_key( '', null ) );
	}

	/**
	 * The live lookup returns the filtered key.
	 */
	public function test_defined_key_uses_the_filter_when_no_constant_is_defined(): void {
		$this->stub_sanitizer();
		$this->stub_license_key_filter( 'from-mu-plugin' );

		$this->assertSame( 'from-mu-plugin', License_Key::defined_key() );
		$this->assertTrue( License_Key::is_defined() );
	}

	/**
	 * A posted key cannot replace a code-defined key.
	 */
	public function test_posted_key_is_rejected_when_a_key_is_defined(): void {
		$this->stub_sanitizer();
		$this->stub_license_key_filter( 'defined-key' );

		$this->assertSame(
			'The license key is set in wp-config.php or an mu-plugin and cannot be changed here.',
			License_Key::posted_key_error( 'other-key' )
		);
	}

	/**
	 * An empty posted key still rechecks the defined key.
	 */
	public function test_empty_posted_key_still_checks_when_a_key_is_defined(): void {
		$this->stub_sanitizer();
		$this->stub_license_key_filter( 'defined-key' );

		$this->assertSame( '', License_Key::posted_key_error( '' ) );
	}

	/**
	 * Revoke is blocked while a key is defined in code.
	 */
	public function test_revoke_is_rejected_when_a_key_is_defined(): void {
		$this->stub_sanitizer();
		$this->stub_license_key_filter( 'defined-key' );

		$this->assertSame(
			'The license key is set in wp-config.php or an mu-plugin and cannot be revoked here.',
			License_Key::revoke_error()
		);
	}

	/**
	 * Save and revoke stay available when no key is defined in code.
	 */
	public function test_manual_changes_are_allowed_without_a_defined_key(): void {
		$this->stub_sanitizer();
		$this->stub_license_key_filter( '' );

		$this->assertSame( '', License_Key::posted_key_error( 'typed-key' ) );
		$this->assertSame( '', License_Key::revoke_error() );
	}

	/**
	 * Activation is skipped only when the stored record is this key and settled.
	 */
	public function test_record_matches_settled_statuses_for_the_same_key(): void {
		MonkeyFunctions\when( 'sanitize_key' )->alias(
			static function ( $key ): string {
				$key = strtolower( (string) $key );
				return (string) preg_replace( '/[^a-z0-9_\-]/', '', $key );
			}
		);

		$this->assertTrue(
			License_Key::record_matches(
				array(
					'license_key' => 'defined-key',
					'status'      => 'valid',
				),
				'defined-key'
			)
		);
		$this->assertTrue(
			License_Key::record_matches(
				array(
					'license_key' => 'defined-key',
					'status'      => 'Expired',
				),
				'defined-key'
			)
		);
		$this->assertFalse(
			License_Key::record_matches(
				array(
					'license_key' => 'saved-key',
					'status'      => 'valid',
				),
				'defined-key'
			)
		);
		$this->assertFalse(
			License_Key::record_matches(
				array(
					'license_key' => 'defined-key',
					'status'      => 'unregistered',
				),
				'defined-key'
			)
		);
		$this->assertFalse( License_Key::record_matches( array(), 'defined-key' ) );
	}

	/**
	 * Front-end requests do not activate a defined key.
	 */
	public function test_should_sync_runs_for_admin_cron_and_cli_only(): void {
		$this->assertTrue( License_Key::should_sync( true, false, false ) );
		$this->assertTrue( License_Key::should_sync( false, true, false ) );
		$this->assertTrue( License_Key::should_sync( false, false, true ) );
		$this->assertFalse( License_Key::should_sync( false, false, false ) );
	}
}
