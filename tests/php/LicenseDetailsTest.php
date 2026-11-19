<?php
/**
 * Tests for license display fields.
 *
 * @package updatebee
 */

namespace UpdateBee\Tests;

use Brain\Monkey\Functions as MonkeyFunctions;
use UpdateBee\Updater\License_Details;

/**
 * License_Details presentation test case.
 */
class LicenseDetailsTest extends TestCase {

	/**
	 * Stub formatters used while presenting a license.
	 */
	private function stub_formatters(): void {
		MonkeyFunctions\when( 'sanitize_key' )->alias(
			static function ( $key ): string {
				$key = strtolower( (string) $key );
				return (string) preg_replace( '/[^a-z0-9_\-]/', '', $key );
			}
		);
		MonkeyFunctions\when( 'absint' )->alias(
			static function ( $value ): int {
				return abs( (int) $value );
			}
		);
		MonkeyFunctions\when( 'esc_url_raw' )->returnArg();
		MonkeyFunctions\when( 'date_i18n' )->alias(
			static function ( $format, $timestamp ): string {
				unset( $format );
				return gmdate( 'F jS, Y', (int) $timestamp );
			}
		);
		MonkeyFunctions\when( 'human_time_diff' )->justReturn( '3 weeks' );
	}

	/**
	 * A dated license shows the subscription upsell and activation stats.
	 */
	public function test_subscription_license_shows_upsell_and_stats(): void {
		$this->stub_formatters();

		$view = License_Details::present(
			array(
				'license_key'       => 'secret-key',
				'status'            => 'valid',
				'product_title'     => 'UpdateBee',
				'variation_title'   => 'Yearly',
				'expires'           => '2027-09-15 00:00:00',
				'activations_count' => 2,
				'activation_limit'  => 0,
				'renewal_url'       => 'https://updatebee.com/renew',
			)
		);

		$this->assertArrayNotHasKey( 'license_key', $view );
		$this->assertTrue( $view['has_license'] );
		$this->assertTrue( $view['is_active'] );
		$this->assertFalse( $view['is_lifetime'] );
		$this->assertTrue( $view['show_upsell'] );
		$this->assertSame( 'UpdateBee', $view['item_name'] );
		$this->assertSame( 'Yearly', $view['variation_name'] );
		$this->assertSame( 'Active', $view['status_label'] );
		$this->assertSame( 'active', $view['status_tone'] );
		$this->assertSame( '2 of Unlimited', $view['activations'] );
		$this->assertSame( 'September 15th, 2027', $view['expires_on'] );
		$this->assertSame( '3 weeks', $view['days_left'] );
		$this->assertSame( 'https://updatebee.com/renew', $view['renewal_url'] );
	}

	/**
	 * A lifetime license hides the upsell.
	 */
	public function test_lifetime_license_hides_upsell(): void {
		$this->stub_formatters();

		$view = License_Details::present(
			array(
				'license_key'       => 'secret-key',
				'status'            => 'valid',
				'product_title'     => 'UpdateBee',
				'expires'           => 'lifetime',
				'activations_count' => 2,
				'activation_limit'  => 5,
			)
		);

		$this->assertTrue( $view['is_active'] );
		$this->assertTrue( $view['is_lifetime'] );
		$this->assertFalse( $view['show_upsell'] );
		$this->assertSame( 'Never', $view['expires_on'] );
		$this->assertSame( 'Lifetime', $view['days_left'] );
		$this->assertSame( '2 of 5', $view['activations'] );
	}

	/**
	 * An expired license says Expired and still offers the upsell.
	 */
	public function test_expired_license_marks_days_left(): void {
		$this->stub_formatters();

		$view = License_Details::present(
			array(
				'license_key'   => 'secret-key',
				'status'        => 'expired',
				'product_title' => 'UpdateBee',
				'expires'       => '2020-01-01 00:00:00',
			)
		);

		$this->assertFalse( $view['is_active'] );
		$this->assertFalse( $view['is_lifetime'] );
		$this->assertSame( 'Expired', $view['status_label'] );
		$this->assertSame( 'Expired', $view['days_left'] );
		$this->assertTrue( $view['show_upsell'] );
	}

	/**
	 * No stored key leaves the table empty and does not echo a key.
	 */
	public function test_empty_license_uses_dashes(): void {
		$this->stub_formatters();

		$view = License_Details::present(
			array(
				'license_key' => '',
				'status'      => 'unregistered',
			)
		);

		$this->assertFalse( $view['has_license'] );
		$this->assertFalse( $view['is_active'] );
		$this->assertFalse( $view['is_lifetime'] );
		$this->assertFalse( $view['show_upsell'] );
		$this->assertSame( '—', $view['item_name'] );
		$this->assertSame( '', $view['variation_name'] );
		$this->assertSame( '—', $view['activations'] );
		$this->assertSame( 'Unregistered', $view['status_label'] );
		$this->assertArrayNotHasKey( 'license_key', $view );
	}

	/**
	 * A valid future license and a lifetime license are active.
	 */
	public function test_active_license_includes_lifetime_and_future_dates(): void {
		$this->stub_formatters();

		$this->assertTrue(
			License_Details::is_active(
				array(
					'license_key' => 'secret-key',
					'status'      => 'valid',
					'expires'     => '2027-09-15 00:00:00',
				)
			)
		);
		$this->assertTrue(
			License_Details::is_active(
				array(
					'license_key' => 'secret-key',
					'status'      => 'valid',
					'expires'     => 'lifetime',
				)
			)
		);
	}

	/**
	 * Empty, expired, and past-dated licenses are not active.
	 */
	public function test_inactive_license_cannot_hide_the_tab(): void {
		$this->stub_formatters();

		$this->assertFalse(
			License_Details::is_active(
				array(
					'license_key' => '',
					'status'      => 'unregistered',
				)
			)
		);
		$this->assertFalse(
			License_Details::is_active(
				array(
					'license_key' => 'secret-key',
					'status'      => 'expired',
					'expires'     => '2027-09-15 00:00:00',
				)
			)
		);
		$past = array(
			'license_key' => 'secret-key',
			'status'      => 'valid',
			'expires'     => '2020-01-01 00:00:00',
		);
		$this->assertFalse( License_Details::is_active( $past ) );
		$this->assertFalse( License_Details::present( $past )['is_active'] );
	}
}
