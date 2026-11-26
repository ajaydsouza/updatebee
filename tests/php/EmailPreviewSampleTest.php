<?php
/**
 * Tests for sample data in empty email previews.
 *
 * @package updatebee
 */

namespace UpdateBee\Tests;

use Brain\Monkey\Functions as MonkeyFunctions;
use UpdateBee\Emails\Emails;

/**
 * Sample email preview test case.
 */
class EmailPreviewSampleTest extends TestCase {

	/**
	 * Stub helpers used while building and rendering a preview.
	 */
	private function stub_preview_helpers(): void {
		MonkeyFunctions\when( 'current_time' )->justReturn( '2026-01-01 12:00:00' );
		MonkeyFunctions\when( 'esc_url' )->returnArg( 1 );
		MonkeyFunctions\when( 'esc_url_raw' )->returnArg( 1 );
		MonkeyFunctions\when( 'esc_html' )->returnArg( 1 );
		MonkeyFunctions\when( 'home_url' )->justReturn( 'https://example.com/' );
		MonkeyFunctions\when( 'get_bloginfo' )->alias(
			static function ( $show = '' ) {
				return 'name' === $show ? 'Example' : '';
			}
		);
		MonkeyFunctions\when( 'wp_next_scheduled' )->justReturn( false );
		MonkeyFunctions\when( 'is_multisite' )->justReturn( false );
		MonkeyFunctions\when( 'admin_url' )->justReturn( 'https://example.com/wp-admin/tools.php?page=updatebee' );
		MonkeyFunctions\when( 'add_query_arg' )->returnArg( 2 );
		MonkeyFunctions\when( 'sanitize_title' )->returnArg( 1 );
	}

	/**
	 * Success samples cover each update type.
	 */
	public function test_success_sample_rows_cover_each_type(): void {
		$this->stub_preview_helpers();

		$rows  = Emails::sample_preview_rows( 'success' );
		$types = array_column( $rows, 'update_type' );

		$this->assertSame( array( 'plugin', 'theme', 'core', 'translation' ), $types );
		foreach ( $rows as $row ) {
			$this->assertSame( 'success', $row['update_status'] );
		}
	}

	/**
	 * Failure samples are plugin and theme rows without a fatal message.
	 */
	public function test_failure_sample_rows_are_failures_without_details(): void {
		$this->stub_preview_helpers();

		$rows = Emails::sample_preview_rows( 'failure' );

		$this->assertCount( 2, $rows );
		$this->assertSame( array( 'plugin', 'theme' ), array_column( $rows, 'update_type' ) );
		foreach ( $rows as $row ) {
			$this->assertSame( 'failure', $row['update_status'] );
			$this->assertArrayNotHasKey( 'fatal_message', $row );
			$this->assertArrayNotHasKey( 'detail_url', $row );
		}
	}

	/**
	 * Fatal samples include the error message and a logs link.
	 */
	public function test_fatal_sample_row_includes_message_and_logs_link(): void {
		$this->stub_preview_helpers();

		$rows = Emails::sample_preview_rows( 'fatal' );

		$this->assertCount( 1, $rows );
		$this->assertSame( 'fatal', $rows[0]['update_status'] );
		$this->assertStringContainsString( 'Call to undefined function foo()', $rows[0]['fatal_message'] );
		$this->assertSame( 'View logs', $rows[0]['detail_label'] );
		$this->assertNotSame( '', $rows[0]['detail_url'] );
	}

	/**
	 * Available samples include changelog links on plugin, theme, and core.
	 */
	public function test_available_sample_rows_include_detail_links(): void {
		$this->stub_preview_helpers();

		$rows = Emails::sample_preview_rows( 'available' );

		$this->assertSame( array( 'plugin', 'theme', 'core', 'translation' ), array_column( $rows, 'update_type' ) );
		$this->assertSame( 'https://wordpress.org/plugins/akismet/#developers', $rows[0]['detail_url'] );
		$this->assertSame( 'Changelog', $rows[0]['detail_label'] );
		$this->assertSame( 'https://wordpress.org/themes/twentytwentyfive/', $rows[1]['detail_url'] );
		$this->assertSame( 'Theme details', $rows[1]['detail_label'] );
		$this->assertStringContainsString( 'Version_6.8.1', $rows[2]['detail_url'] );
		$this->assertArrayNotHasKey( 'detail_url', $rows[3] );
	}

	/**
	 * A preview keeps real rows when the report already has data.
	 */
	public function test_rows_for_preview_keeps_real_rows(): void {
		$real = array(
			array(
				'name'         => 'Hello Dolly',
				'update_type'  => 'plugin',
				'from_version' => '1.7.2',
				'to_version'   => '1.7.3',
			),
		);

		$this->assertSame( $real, Emails::rows_for_preview( $real, 'success' ) );
	}

	/**
	 * An empty preview falls back to sample rows.
	 */
	public function test_rows_for_preview_uses_samples_when_empty(): void {
		$this->stub_preview_helpers();

		$rows = Emails::rows_for_preview( array(), 'success' );

		$this->assertSame( 'Akismet Anti-spam', $rows[0]['name'] );
		$this->assertSame( 'success', $rows[0]['update_status'] );
	}

	/**
	 * Sample notice is added only for sample previews.
	 */
	public function test_preview_intro_prefixes_sample_notice(): void {
		$intro = 'Your site has had some recent automatic updates.';

		$this->assertSame( $intro, Emails::preview_intro( $intro, false ) );
		$this->assertStringStartsWith( 'This preview uses sample data', Emails::preview_intro( $intro, true ) );
		$this->assertStringContainsString( $intro, Emails::preview_intro( $intro, true ) );
	}

	/**
	 * Fatal sample HTML includes the error and a Details column.
	 */
	public function test_fatal_sample_email_renders_details(): void {
		$this->stub_preview_helpers();

		$html = Emails::get_update_report_email(
			Emails::sample_preview_rows( 'fatal' ),
			'Fatal error after automatic updates',
			Emails::preview_intro( 'A PHP fatal error was detected.', true ),
			''
		);

		$this->assertStringContainsString( 'Call to undefined function foo()', $html );
		$this->assertStringContainsString( 'Details', $html );
		$this->assertStringContainsString( 'View logs', $html );
		$this->assertStringContainsString( 'This preview uses sample data', $html );
	}

	/**
	 * Available sample HTML includes a changelog link in the Details column.
	 */
	public function test_available_sample_email_renders_details_link(): void {
		$this->stub_preview_helpers();

		$html = Emails::get_update_report_email(
			Emails::sample_preview_rows( 'available' ),
			'Available updates',
			'Intro',
			'updatebee_email_updates_available_report'
		);

		$this->assertStringContainsString( 'Details', $html );
		$this->assertStringContainsString( 'Changelog', $html );
		$this->assertStringContainsString( 'https://wordpress.org/plugins/akismet/#developers', $html );
	}
}
