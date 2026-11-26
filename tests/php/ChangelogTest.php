<?php
/**
 * Tests for plugin and theme changelog resolution.
 *
 * @package updatebee
 */

namespace UpdateBee\Tests;

use Brain\Monkey\Functions as MonkeyFunctions;
use UpdateBee\Updates\Changelog;

// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_mkdir,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir,WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

/**
 * Source order, sanitization, and availability.
 */
class ChangelogTest extends TestCase {

	/**
	 * Directories created by a test.
	 *
	 * @var array<int, string>
	 */
	private $created = array();

	/**
	 * Prepare escaping stubs and the tag processor double.
	 */
	protected function setUp(): void {
		parent::setUp();

		require_once __DIR__ . '/WP_HTML_Tag_Processor_Stub.php';

		if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
			define( 'WP_PLUGIN_DIR', ABSPATH . 'wp-content/plugins' );
		}

		if ( ! defined( 'WP_CONTENT_DIR' ) ) {
			define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );
		}

		MonkeyFunctions\when( 'esc_html' )->alias(
			static function ( $text ) {
				return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
			}
		);
		MonkeyFunctions\when( 'wp_kses_post' )->alias(
			static function ( $html ) {
				return strip_tags( (string) $html, '<a><p><br><ul><ol><li><h2><h3><h4><strong><em><code><pre><blockquote>' );
			}
		);
		$this->stub_url_parsing();
	}

	/**
	 * Remove directories created by a test.
	 */
	protected function tearDown(): void {
		foreach ( array_reverse( $this->created ) as $path ) {
			$this->remove_path( $path );
		}

		parent::tearDown();
	}

	/**
	 * A readme Changelog section becomes headings and lists.
	 */
	public function test_readme_changelog_becomes_headings_and_lists(): void {
		$html = Changelog::html_from_readme(
			"=== Example ===\n== Changelog ==\n= 1.2.3 =\n* Fixed the modal\n- Added a link\n= 1.2.2 =\n* Earlier fix\n== Upgrade Notice ==\nLeave this out\n"
		);

		$this->assertSame(
			'<h4>1.2.3</h4><ul><li>Fixed the modal</li><li>Added a link</li></ul><h4>1.2.2</h4><ul><li>Earlier fix</li></ul>',
			$html
		);
	}

	/**
	 * Paragraph text is kept, and an indented line stays with the list item above it.
	 */
	public function test_readme_paragraphs_and_list_continuation_are_kept(): void {
		$html = Changelog::html_from_readme(
			"== Changelog ==\nSecurity release.\n= 1.2.3 =\n* Fixed the modal\n  when it is wide\nPlease update.\n"
		);

		$this->assertSame(
			'<p>Security release.</p><h4>1.2.3</h4><ul><li>Fixed the modal when it is wide</li></ul><p>Please update.</p>',
			$html
		);
	}

	/**
	 * Stored HTML drops script tags and opens links in a new tab.
	 */
	public function test_changelog_html_is_sanitized(): void {
		$this->stub_plugins(
			array(
				'vendor/vendor.php' => (object) array(
					'sections' => array(
						'changelog' => '<p>Before</p><script>alert(1)</script><a href="https://example.com/notes" rel="nofollow">Notes</a>',
					),
				),
			)
		);

		$html = Changelog::plugin_html( 'vendor/vendor.php' );

		$this->assertStringNotContainsString( '<script', strtolower( $html ) );
		$this->assertStringContainsString( 'target="_blank"', $html );
		$this->assertStringContainsString( 'rel="nofollow noopener noreferrer"', $html );
		$this->assertStringContainsString( 'https://example.com/notes', $html );
	}

	/**
	 * A directory plugin uses the info API ahead of stored and local text.
	 */
	public function test_directory_plugin_prefers_the_info_api(): void {
		$directory = $this->plugin_directory( 'changelog-api' );
		file_put_contents(
			$directory . '/readme.txt',
			"== Changelog ==\n= 1.0.0 =\n* Local note\n"
		);
		$this->stub_plugins(
			array(
				'changelog-api/changelog-api.php' => (object) array(
					'id'       => 'w.org/plugins/changelog-api',
					'slug'     => 'changelog-api',
					'sections' => array(
						'changelog' => '<p>Stored changelog</p>',
					),
				),
			)
		);
		MonkeyFunctions\expect( 'plugins_api' )
			->once()
			->with(
				'plugin_information',
				\Mockery::on(
					static function ( $args ) {
						return is_array( $args )
							&& 'changelog-api' === ( $args['slug'] ?? '' )
							&& true === ( $args['fields']['sections'] ?? false );
					}
				)
			)
			->andReturn(
				(object) array(
					'sections' => (object) array(
						'changelog' => '<p>API changelog</p>',
					),
				)
			);

		$html = Changelog::plugin_html( 'changelog-api/changelog-api.php' );

		$this->assertStringContainsString( 'API changelog', $html );
		$this->assertStringNotContainsString( 'Stored changelog', $html );
		$this->assertStringNotContainsString( 'Local note', $html );
	}

	/**
	 * A directory theme uses the info API ahead of stored text.
	 */
	public function test_directory_theme_prefers_the_info_api(): void {
		$this->stub_themes(
			array(
				'twentytwentyfive' => array(
					'url'      => 'https://wordpress.org/themes/twentytwentyfive/',
					'sections' => array(
						'changelog' => '<p>Stored theme notes</p>',
					),
				),
			)
		);
		MonkeyFunctions\expect( 'themes_api' )
			->once()
			->with(
				'theme_information',
				\Mockery::on(
					static function ( $args ) {
						return is_array( $args ) && 'twentytwentyfive' === ( $args['slug'] ?? '' );
					}
				)
			)
			->andReturn(
				(object) array(
					'sections' => array(
						'changelog' => '<p>API theme notes</p>',
					),
				)
			);

		$html = Changelog::theme_html( 'twentytwentyfive' );

		$this->assertStringContainsString( 'API theme notes', $html );
		$this->assertStringNotContainsString( 'Stored theme notes', $html );
	}

	/**
	 * A third-party updater can supply the changelog.
	 */
	public function test_third_party_api_changelog_is_used(): void {
		$this->stub_plugins(
			array(
				'vendor/vendor.php' => (object) array(
					'slug'     => 'vendor-pro',
					'id'       => 'example.com/vendor-pro',
					'sections' => array(
						'changelog' => '<p>Stored vendor notes</p>',
					),
				),
			)
		);
		MonkeyFunctions\expect( 'plugins_api' )
			->once()
			->andReturn(
				(object) array(
					'external' => true,
					'sections' => array(
						'changelog' => '<p>Remote vendor notes</p>',
					),
				)
			);

		$html = Changelog::plugin_html( 'vendor/vendor.php' );

		$this->assertStringContainsString( 'Remote vendor notes', $html );
		$this->assertStringNotContainsString( 'Stored vendor notes', $html );
	}

	/**
	 * A third-party slug that collides with WordPress.org does not show the directory changelog.
	 */
	public function test_third_party_slug_collision_is_rejected(): void {
		$this->stub_url_parsing();
		$this->stub_plugins(
			array(
				'vendor/vendor.php' => (object) array(
					'slug'     => 'akismet',
					'id'       => 'example.com/vendor',
					'sections' => array(
						'changelog' => '<p>Vendor changelog</p>',
					),
				),
			)
		);
		MonkeyFunctions\expect( 'plugins_api' )
			->once()
			->andReturn(
				(object) array(
					'download_link' => 'https://downloads.wordpress.org/plugin/akismet.zip',
					'homepage'      => 'https://wordpress.org/plugins/akismet/',
					'sections'      => array(
						'changelog' => '<p>Directory changelog</p>',
					),
				)
			);

		$html = Changelog::plugin_html( 'vendor/vendor.php' );

		$this->assertStringContainsString( 'Vendor changelog', $html );
		$this->assertStringNotContainsString( 'Directory changelog', $html );
	}

	/**
	 * A third-party slug can offer a changelog before any section or local file exists.
	 */
	public function test_third_party_slug_is_available_without_a_stored_section(): void {
		MonkeyFunctions\expect( 'plugins_api' )->never();
		$this->stub_plugins(
			array(
				'vendor/vendor.php' => (object) array(
					'slug' => 'vendor-pro',
					'id'   => 'example.com/vendor-pro',
				),
			)
		);

		$this->assertTrue( Changelog::plugin_is_available( 'vendor/vendor.php' ) );
	}

	/**
	 * A lookalike update id is not a WordPress.org directory id.
	 */
	public function test_directory_id_must_match_the_whole_w_org_path(): void {
		MonkeyFunctions\expect( 'plugins_api' )->never();
		$this->stub_plugins(
			array(
				'vendor/vendor.php' => (object) array(
					'id' => 'notw.org/plugins/akismet',
				),
			)
		);

		$this->assertFalse( Changelog::plugin_is_available( 'vendor/vendor.php' ) );
	}

	/**
	 * A theme URL counts only when the host is wordpress.org.
	 */
	public function test_theme_url_requires_the_wordpress_org_host(): void {
		MonkeyFunctions\expect( 'themes_api' )->never();
		$this->stub_themes(
			array(
				'lookalike' => array(
					'url' => 'https://notwordpress.org/themes/lookalike/',
				),
			)
		);

		$this->assertFalse( Changelog::theme_is_available( 'lookalike' ) );
	}

	/**
	 * Directory membership is enough to show the row, without calling the info API.
	 */
	public function test_directory_plugin_is_available_without_a_remote_request(): void {
		MonkeyFunctions\expect( 'plugins_api' )->never();
		$this->stub_plugins(
			array(
				'akismet/akismet.php' => (object) array(
					'id'   => 'w.org/plugins/akismet',
					'slug' => 'akismet',
				),
			)
		);

		$this->assertTrue( Changelog::plugin_is_available( 'akismet/akismet.php' ) );
	}

	/**
	 * A changelog stored on the no-update row counts.
	 */
	public function test_no_update_section_counts_as_available(): void {
		MonkeyFunctions\expect( 'plugins_api' )->never();
		$this->stub_plugins(
			array(),
			array(
				'vendor/vendor.php' => array(
					'sections' => array(
						'changelog' => '<p>Current notes</p>',
					),
				),
			)
		);

		$this->assertTrue( Changelog::plugin_is_available( 'vendor/vendor.php' ) );
	}

	/**
	 * An installed readme Changelog section counts.
	 */
	public function test_local_readme_counts_as_available(): void {
		MonkeyFunctions\expect( 'plugins_api' )->never();
		$this->stub_plugins( array() );
		$directory = $this->plugin_directory( 'local-changelog' );
		file_put_contents(
			$directory . '/readme.txt',
			"== Description ==\nHello\n== Changelog ==\n= 1.0.0 =\n* Added the screen\n"
		);

		$this->assertTrue( Changelog::plugin_is_available( 'local-changelog/local-changelog.php' ) );
	}

	/**
	 * A changelog.txt file is used when the readme has no Changelog section.
	 */
	public function test_local_changelog_txt_is_used(): void {
		MonkeyFunctions\expect( 'plugins_api' )->never();
		$this->stub_plugins( array() );
		$directory = $this->plugin_directory( 'plain-notes' );
		file_put_contents( $directory . '/changelog.txt', "First paragraph.\n\nSecond paragraph." );

		$this->assertTrue( Changelog::plugin_is_available( 'plain-notes/plain-notes.php' ) );
		$html = Changelog::plugin_html( 'plain-notes/plain-notes.php' );
		$this->assertStringContainsString( '<p>First paragraph.</p>', $html );
		$this->assertStringContainsString( '<p>Second paragraph.</p>', $html );
	}

	/**
	 * An item with no directory id, stored section, or local file has no changelog.
	 */
	public function test_missing_sources_are_not_available(): void {
		MonkeyFunctions\expect( 'plugins_api' )->never();
		MonkeyFunctions\expect( 'themes_api' )->never();
		MonkeyFunctions\when( 'get_site_transient' )->justReturn( false );

		$this->assertFalse( Changelog::plugin_is_available( 'custom/custom.php' ) );
		$this->assertSame( '', Changelog::plugin_html( 'custom/custom.php' ) );
		$this->assertFalse( Changelog::theme_is_available( 'custom-theme' ) );
		$this->assertSame( '', Changelog::theme_html( 'custom-theme' ) );
	}

	/**
	 * Point update_plugins at these rows.
	 *
	 * @param array<string, mixed> $response  Pending updates.
	 * @param array<string, mixed> $no_update Current items.
	 */
	private function stub_plugins( array $response, array $no_update = array() ): void {
		$this->stub_transient( 'update_plugins', $response, $no_update );
	}

	/**
	 * Point update_themes at these rows.
	 *
	 * @param array<string, mixed> $response  Pending updates.
	 * @param array<string, mixed> $no_update Current items.
	 */
	private function stub_themes( array $response, array $no_update = array() ): void {
		$this->stub_transient( 'update_themes', $response, $no_update );
	}

	/**
	 * Stub one update transient.
	 *
	 * @param string               $name      Transient name.
	 * @param array<string, mixed> $response  Pending updates.
	 * @param array<string, mixed> $no_update Current items.
	 */
	private function stub_transient( string $name, array $response, array $no_update ): void {
		MonkeyFunctions\when( 'get_site_transient' )->alias(
			static function ( $transient ) use ( $name, $response, $no_update ) {
				if ( $transient !== $name ) {
					return false;
				}

				return (object) array(
					'response'  => $response,
					'no_update' => $no_update,
				);
			}
		);
	}

	/**
	 * Stub URL parsing used to recognize WordPress.org payloads.
	 */
	private function stub_url_parsing(): void {
		MonkeyFunctions\when( 'wp_parse_url' )->alias(
			static function ( $url, $component = -1 ) {
				if ( -1 === $component ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Test double for wp_parse_url.
					return parse_url( (string) $url );
				}

				// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Test double for wp_parse_url.
				return parse_url( (string) $url, $component );
			}
		);
	}

	/**
	 * Create a plugin directory under WP_PLUGIN_DIR.
	 *
	 * @param string $slug Plugin directory name.
	 * @return string Absolute directory.
	 */
	private function plugin_directory( string $slug ): string {
		$directory = rtrim( WP_PLUGIN_DIR, '/\\' ) . '/' . $slug;
		if ( ! is_dir( $directory ) ) {
			mkdir( $directory, 0777, true );
			$this->created[] = $directory;
		}

		return $directory;
	}

	/**
	 * Delete a file or directory tree.
	 *
	 * @param string $path Absolute path.
	 */
	private function remove_path( string $path ): void {
		if ( is_link( $path ) || is_file( $path ) ) {
			unlink( $path );
			return;
		}

		if ( ! is_dir( $path ) ) {
			return;
		}

		$items = scandir( $path );
		if ( is_array( $items ) ) {
			foreach ( $items as $item ) {
				if ( '.' === $item || '..' === $item ) {
					continue;
				}

				$this->remove_path( $path . '/' . $item );
			}
		}

		rmdir( $path );
	}
}
