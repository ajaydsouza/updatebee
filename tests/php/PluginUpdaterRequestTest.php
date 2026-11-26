<?php
/**
 * Tests for plugin update requests.
 *
 * @package updatebee
 */

namespace UpdateBee\Tests;

use Brain\Monkey\Functions as MonkeyFunctions;
use ReflectionClass;
use UpdateBee\Updater\PluginUpdater;

/**
 * HTTPS guard on update checks that send a license key.
 */
class PluginUpdaterRequestTest extends TestCase {

	/**
	 * Load the updater once per process.
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		require_once dirname( __DIR__, 2 ) . '/updater/PluginUpdater.php';
	}

	/**
	 * Call the remote version request with a configured API URL.
	 *
	 * @param string   $api_url  Updater API URL.
	 * @param callable $callback License key callback.
	 * @return mixed
	 */
	private function version_info( string $api_url, callable $callback ) {
		$updater = ( new ReflectionClass( PluginUpdater::class ) )->newInstanceWithoutConstructor();
		$config  = new ReflectionClass( PluginUpdater::class );
		$stored  = $config->getProperty( 'config' );
		$stored->setAccessible( true );
		$stored->setValue(
			$updater,
			array(
				'api_url'              => $api_url,
				'license_key'          => '',
				'license_key_callback' => $callback,
				'item_id'              => '43033',
				'version'              => '1.2.2',
				'slug'                 => 'updatebee',
				'basename'             => 'updatebee/updatebee.php',
			)
		);

		$method = $config->getMethod( 'getRemoteVersionInfo' );
		$method->setAccessible( true );

		return $method->invoke( $updater );
	}

	/**
	 * Stub wp_parse_url() for the scheme component.
	 */
	private function stub_url_parser(): void {
		MonkeyFunctions\when( 'wp_parse_url' )->alias(
			static function ( $url, $component = -1 ) {
				if ( PHP_URL_SCHEME !== $component || ! is_string( $url ) ) {
					return null;
				}

				if ( 1 !== preg_match( '#^([a-z][a-z0-9+.-]*):#i', $url, $matches ) ) {
					return null;
				}

				return $matches[1];
			}
		);
	}

	/**
	 * An HTTP store URL is rejected before the license key callback runs.
	 */
	public function test_http_store_url_does_not_send_the_license_key(): void {
		$this->stub_url_parser();
		MonkeyFunctions\expect( 'wp_remote_post' )->never();
		$called = false;

		$result = $this->version_info(
			'http://example.com/',
			static function () use ( &$called ) {
				$called = true;

				return 'secret-key';
			}
		);

		$this->assertFalse( $result );
		$this->assertFalse( $called );
	}

	/**
	 * An HTTPS store URL still posts the key from the license callback.
	 */
	public function test_https_store_url_sends_the_license_key(): void {
		$this->stub_url_parser();
		MonkeyFunctions\when( 'apply_filters' )->returnArg( 2 );
		MonkeyFunctions\when( 'add_query_arg' )->alias(
			static function ( $args, $url ) {
				return $url . '?fluent-cart=' . $args['fluent-cart'];
			}
		);
		MonkeyFunctions\when( 'home_url' )->justReturn( 'https://site.example' );
		MonkeyFunctions\when( 'get_bloginfo' )->justReturn( '6.7' );
		MonkeyFunctions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		MonkeyFunctions\when( 'wp_remote_retrieve_body' )->justReturn( '{"new_version":"9.9.9"}' );
		MonkeyFunctions\expect( 'wp_remote_post' )
			->once()
			->with(
				'https://updatebee.com/?fluent-cart=get_license_version',
				\Mockery::on(
					static function ( $args ) {
						return isset( $args['body']['license_key'] ) && 'secret-key' === $args['body']['license_key'];
					}
				)
			)
			->andReturn( array( 'body' => '{"new_version":"9.9.9"}' ) );

		$result = $this->version_info(
			'https://updatebee.com/',
			static function () {
				return 'secret-key';
			}
		);

		$this->assertIsObject( $result );
		$this->assertSame( '9.9.9', $result->new_version );
	}
}
