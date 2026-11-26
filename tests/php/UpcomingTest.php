<?php
/**
 * Tests for the upcoming updates list.
 *
 * @package updatebee
 */

namespace UpdateBee\Tests;

use Brain\Monkey\Functions as MonkeyFunctions;
use ReflectionClass;
use UpdateBee\Options;
use UpdateBee\Updates\Delay_Updates;
use UpdateBee\Updates\Upcoming;

/**
 * Upcoming list filtering.
 */
class UpcomingTest extends TestCase {

	/**
	 * Clear option and delay state after each test.
	 */
	protected function tearDown(): void {
		Options::flush_cache();
		$this->reset_delay_singleton();
		parent::tearDown();
	}

	/**
	 * A blocked plugin with an offer is left off the upcoming list.
	 */
	public function test_blocked_plugin_is_omitted(): void {
		$this->stub_list_helpers(
			array(
				'blocked_plugins' => array( 'akismet' ),
			)
		);

		$list  = Upcoming::get_list();
		$items = $list['items'];
		$ids   = array_column( $items, 'Id' );

		$this->assertSame( 0, $list['delay_updates'] );
		$this->assertSame( array( 'hello.php' ), $ids );
		$this->assertSame( 'plugin', $items[0]['Type'] );
		$this->assertFalse( $items[0]['Blocked'] );
		$this->assertFalse( $items[0]['HasChangelog'] );
		$this->assertSame( '', $items[0]['ChangelogNonce'] );
	}

	/**
	 * A stored changelog section marks the row without calling the info API.
	 */
	public function test_changelog_section_marks_the_row_without_a_remote_request(): void {
		MonkeyFunctions\expect( 'plugins_api' )->never();
		$this->stub_list_helpers(
			array(),
			array(
				'hello.php' => (object) array(
					'new_version' => '1.1',
					'package'     => 'https://downloads.example.com/hello.zip',
					'sections'    => array(
						'changelog' => '<p>Hello notes</p>',
					),
				),
			)
		);

		$hello = null;
		foreach ( Upcoming::get_list()['items'] as $item ) {
			if ( is_array( $item ) && 'hello.php' === ( $item['Id'] ?? '' ) ) {
				$hello = $item;
			}
		}

		$this->assertIsArray( $hello );
		$this->assertTrue( $hello['HasChangelog'] );
		$this->assertSame( 'nonce', $hello['ChangelogNonce'] );
	}

	/**
	 * Disabling every plugin update removes plugin rows from the list.
	 */
	public function test_fully_blocked_plugins_are_omitted(): void {
		$this->stub_list_helpers(
			array(
				'plugin_updates_disabled' => 'on',
			)
		);

		$ids = array_column( Upcoming::get_list()['items'], 'Id' );

		$this->assertSame( array(), $ids );
	}

	/**
	 * A longer site delay moves a stamped plugin's schedule forward.
	 */
	public function test_schedule_moves_when_delay_changes(): void {
		$stamp  = time();
		$stamps = array(
			'hello.php' => array(
				'version' => '1.1',
				'time'    => $stamp,
			),
		);

		MonkeyFunctions\when( 'get_option' )->alias(
			static function ( $key ) {
				if ( 'date_format' === $key ) {
					return 'Y-m-d';
				}

				if ( 'time_format' === $key ) {
					return 'H:i';
				}

				return false;
			}
		);
		MonkeyFunctions\when( 'wp_date' )->alias(
			static function ( $format, $timestamp ) {
				return gmdate( (string) $format, (int) $timestamp );
			}
		);

		$this->stub_list_helpers(
			array(
				'delay_updates' => 1,
			),
			null,
			$stamps
		);
		$short_list = Upcoming::get_list();
		$short      = $this->row_by_id( $short_list, 'hello.php' );

		Options::flush_cache();
		$this->stub_list_helpers(
			array(
				'delay_updates' => 4,
			),
			null,
			$stamps
		);
		$long_list = Upcoming::get_list();
		$long      = $this->row_by_id( $long_list, 'hello.php' );

		$this->assertSame( 1, $short_list['delay_updates'] );
		$this->assertSame( 4, $long_list['delay_updates'] );
		$this->assertSame(
			gmdate( 'Y-m-d H:i', $stamp + DAY_IN_SECONDS ),
			$short['Schedule']
		);
		$this->assertSame(
			gmdate( 'Y-m-d H:i', $stamp + ( 4 * DAY_IN_SECONDS ) ),
			$long['Schedule']
		);
		$this->assertNotSame( $short['Schedule'], $long['Schedule'] );
	}

	/**
	 * Stub the WordPress calls used while building upcoming rows.
	 *
	 * @param array<string, mixed>       $options         Stored updatebee_options values.
	 * @param array<string, object>|null $plugin_response Pending plugin offers. Null uses the default offers.
	 * @param array<string, mixed>       $plugin_stamps   First-seen plugin update stamps.
	 */
	private function stub_list_helpers( array $options, ?array $plugin_response = null, array $plugin_stamps = array() ): void {
		if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
			define( 'WP_PLUGIN_DIR', ABSPATH . 'wp-content/plugins' );
		}

		MonkeyFunctions\when( 'absint' )->alias(
			static function ( $value ) {
				return abs( (int) $value );
			}
		);
		MonkeyFunctions\when( 'add_filter' )->justReturn( true );
		MonkeyFunctions\when( 'add_action' )->justReturn( true );
		MonkeyFunctions\when( 'sanitize_text_field' )->returnArg( 1 );
		MonkeyFunctions\when( 'sanitize_title' )->returnArg( 1 );
		MonkeyFunctions\when( 'wp_kses_post' )->returnArg( 1 );
		MonkeyFunctions\when( 'wp_create_nonce' )->justReturn( 'nonce' );
		MonkeyFunctions\when( 'current_user_can' )->justReturn( true );
		MonkeyFunctions\when( 'wp_get_scheduled_event' )->justReturn( false );
		MonkeyFunctions\when( 'wp_get_themes' )->justReturn( array() );
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
			static function ( $key, $default_value = false ) use ( $options, $plugin_stamps ) {
				if ( 'updatebee_options' === $key ) {
					return $options;
				}

				if ( Delay_Updates::PLUGIN_OPTION === $key ) {
					return $plugin_stamps;
				}

				return $default_value;
			}
		);
		Options::flush_cache();
		MonkeyFunctions\when( 'get_plugins' )->justReturn(
			array(
				'akismet/akismet.php' => array(
					'Name'    => 'Akismet',
					'Version' => '5.0',
				),
				'hello.php'           => array(
					'Name'    => 'Hello Dolly',
					'Version' => '1.0',
				),
			)
		);
		MonkeyFunctions\when( 'get_plugin_data' )->alias(
			static function ( $plugin_file ) {
				if ( str_contains( (string) $plugin_file, 'akismet' ) ) {
					return array( 'Version' => '5.0' );
				}

				return array( 'Version' => '1.0' );
			}
		);
		$plugin_response = $plugin_response ?? array(
			'akismet/akismet.php' => (object) array(
				'new_version' => '5.1',
				'package'     => 'https://downloads.example.com/akismet.zip',
			),
			'hello.php'           => (object) array(
				'new_version' => '1.1',
				'package'     => 'https://downloads.example.com/hello.zip',
			),
		);
		MonkeyFunctions\when( 'get_site_transient' )->alias(
			static function ( $key ) use ( $plugin_response ) {
				if ( 'update_plugins' !== $key ) {
					return false;
				}

				return (object) array(
					'response' => $plugin_response,
				);
			}
		);
	}

	/**
	 * One upcoming row by asset id.
	 *
	 * @param array<string, mixed> $payload Upcoming list payload.
	 * @param string               $id      Plugin file or theme stylesheet.
	 * @return array<string, mixed> Matching row.
	 */
	private function row_by_id( array $payload, string $id ): array {
		$items = isset( $payload['items'] ) && is_array( $payload['items'] ) ? $payload['items'] : array();
		foreach ( $items as $item ) {
			if ( is_array( $item ) && ( $item['Id'] ?? '' ) === $id ) {
				return $item;
			}
		}

		$this->fail( 'Upcoming row was not found.' );

		return array();
	}

	/**
	 * Clear the Delay_Updates singleton created while labeling a schedule.
	 */
	private function reset_delay_singleton(): void {
		$reflection = new ReflectionClass( Delay_Updates::class );
		$property   = $reflection->getProperty( 'instance' );
		$property->setAccessible( true );
		$property->setValue( null, null );

		$skip = $reflection->getProperty( 'skip_delay' );
		$skip->setAccessible( true );
		$skip->setValue( null, false );
	}
}
