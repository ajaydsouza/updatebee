<?php
/**
 * Tests for license API requests.
 *
 * @package updatebee
 */

namespace UpdateBee\Tests;

use Brain\Monkey\Functions as MonkeyFunctions;
use ReflectionClass;
use UpdateBee\Updater\FluentLicensing;

/**
 * HTTPS guard on the shared license request.
 */
class FluentLicensingRequestTest extends TestCase {

	/**
	 * Load the licensing client once per process.
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		require_once dirname( __DIR__, 2 ) . '/updater/FluentLicensing.php';
	}

	/**
	 * Call the shared license request with a configured API URL.
	 *
	 * @param string               $api_url     Licensing API URL.
	 * @param array<string, mixed> $payload     Request payload.
	 * @return mixed
	 */
	private function request( string $api_url, array $payload ) {
		$client = new FluentLicensing();
		$config = new ReflectionClass( FluentLicensing::class );
		$stored = $config->getProperty( 'config' );
		$stored->setAccessible( true );
		$stored->setValue(
			$client,
			array(
				'api_url' => $api_url,
				'item_id' => '43033',
				'version' => '1.2.2',
			)
		);

		$method = $config->getMethod( 'apiRequest' );
		$method->setAccessible( true );

		return $method->invoke( $client, 'activate_license', $payload );
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
	 * An HTTP API URL is rejected before the license key is posted.
	 */
	public function test_http_api_url_is_rejected_without_sending_the_key(): void {
		$this->stub_url_parser();
		MonkeyFunctions\expect( 'wp_remote_post' )->never();

		$result = $this->request(
			'http://example.com/',
			array(
				'license_key' => 'secret-key',
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'license_api_insecure', $result->get_error_code() );
	}

	/**
	 * A missing or protocol-relative URL is rejected the same way.
	 */
	public function test_non_https_api_urls_are_rejected_without_sending_the_key(): void {
		$this->stub_url_parser();
		MonkeyFunctions\expect( 'wp_remote_post' )->never();

		foreach ( array( '', '//example.com/license', 'javascript:alert(1)' ) as $api_url ) {
			$result = $this->request(
				$api_url,
				array(
					'license_key' => 'secret-key',
				)
			);

			$this->assertInstanceOf( \WP_Error::class, $result );
			$this->assertSame( 'license_api_insecure', $result->get_error_code() );
		}
	}

	/**
	 * An HTTPS API URL still posts the license key.
	 */
	public function test_https_api_url_sends_the_license_key(): void {
		$this->stub_url_parser();
		MonkeyFunctions\when( 'add_query_arg' )->alias(
			static function ( $args, $url ) {
				return $url . '?fluent-cart=' . $args['fluent-cart'];
			}
		);
		MonkeyFunctions\when( 'wp_parse_args' )->alias(
			static function ( $args, $defaults ) {
				return array_merge( $defaults, $args );
			}
		);
		MonkeyFunctions\when( 'home_url' )->justReturn( 'https://site.example' );
		MonkeyFunctions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		MonkeyFunctions\when( 'wp_remote_retrieve_body' )->justReturn( '{"status":"valid"}' );
		MonkeyFunctions\expect( 'wp_remote_post' )
			->once()
			->with(
				'https://updatebee.com/?fluent-cart=activate_license',
				\Mockery::on(
					static function ( $args ) {
						return isset( $args['body']['license_key'] ) && 'secret-key' === $args['body']['license_key'];
					}
				)
			)
			->andReturn( array( 'body' => '{"status":"valid"}' ) );

		$result = $this->request(
			'https://updatebee.com/',
			array(
				'license_key' => 'secret-key',
			)
		);

		$this->assertSame( array( 'status' => 'valid' ), $result );
	}
}
