<?php
/**
 * Tests for forced update summaries.
 *
 * @package updatebee
 */

namespace UpdateBee\Tests;

use Brain\Monkey\Functions;
use UpdateBee\Options;
use UpdateBee\Updates\Force_Update_Report;

/**
 * Covers update outcomes shown in Tools.
 */
class ForceUpdateReportTest extends TestCase {

	/**
	 * Successful updates and a named failure share a single summary.
	 */
	public function test_mixed_update_results(): void {
		Functions\when( '_n' )->alias(
			static fn( $singular, $plural, $count ) => 1 === $count ? $singular : $plural
		);
		Functions\when( 'number_format_i18n' )->alias( 'strval' );
		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );
		Functions\when( 'wp_get_list_item_separator' )->justReturn( ', ' );

		$success = (object) array( 'result' => true );
		$results = array(
			'plugin'      => array(
				$success,
				$success,
				(object) array(
					'name'   => 'Example Plugin',
					'result' => false,
				),
			),
			'theme'       => array( $success ),
			'translation' => array( (object) array( 'result' => array( 'destination' => 'languages' ) ) ),
			'core'        => array(
				(object) array(
					'item'   => (object) array( 'version' => '7.2' ),
					'result' => '7.2',
				),
			),
		);

		self::assertSame(
			'WordPress core updated to 7.2. 2 plugins updated. 1 theme updated. 1 translation updated. Failed to update Example Plugin.',
			Force_Update_Report::message( $results )
		);
	}

	/**
	 * An empty run explains why pending plugin offers were skipped.
	 */
	public function test_no_attempts_with_plugin_auto_updates_off(): void {
		Options::flush_cache();
		Functions\when( 'get_site_option' )->justReturn( array() );
		Functions\when( 'wp_parse_args' )->alias( static fn( $args, $defaults ) => array_merge( $defaults, $args ) );
		Functions\when( 'get_site_transient' )->alias(
			static fn( $name ) => 'update_plugins' === $name ? (object) array( 'response' => array( 'example/example.php' => (object) array() ) ) : false
		);

		try {
			self::assertSame(
				'No updates were installed. Plugin auto-updates are off.',
				Force_Update_Report::message( array() )
			);
		} finally {
			Options::flush_cache();
		}
	}
}
