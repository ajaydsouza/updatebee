<?php
/**
 * Post payloads to the configured Discord incoming webhook.
 *
 * @package updatebee
 */

namespace UpdateBee\Discord;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use UpdateBee\Options;

/**
 * Shared Discord webhook transport.
 */
class Discord_Webhook {

	/**
	 * Hosts that serve Discord incoming webhooks.
	 *
	 * @var array<int, string>
	 */
	private const HOSTS = array(
		'discord.com',
		'discordapp.com',
	);

	/**
	 * Whether a Discord incoming webhook URL is stored.
	 *
	 * @return bool
	 */
	public static function is_configured(): bool {
		$webhook = Options::get_options( 'discord_webhook_url' );
		return is_string( $webhook ) && self::is_valid_url( $webhook );
	}

	/**
	 * Accept an empty value or a Discord incoming webhook URL.
	 *
	 * @param string $url Raw URL.
	 * @return string|\WP_Error Sanitized URL, or an error when the value is not a Discord webhook.
	 */
	public static function sanitize_url( string $url ) {
		$url = trim( $url );
		if ( '' === $url ) {
			return '';
		}

		if ( ! self::is_valid_url( $url ) ) {
			return new \WP_Error(
				'updatebee_discord_webhook_invalid',
				__( 'Enter a Discord incoming webhook URL.', 'updatebee' )
			);
		}

		return esc_url_raw( $url );
	}

	/**
	 * Whether the URL is an HTTPS Discord incoming webhook.
	 *
	 * @param string $url Candidate URL.
	 * @return bool
	 */
	public static function is_valid_url( string $url ): bool {
		if ( ! str_starts_with( $url, 'https://' ) || false === filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return false;
		}

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) ) {
			return false;
		}

		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['port'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) ) {
			return false;
		}

		$host = isset( $parts['host'] ) ? strtolower( (string) $parts['host'] ) : '';
		if ( ! in_array( $host, self::HOSTS, true ) ) {
			return false;
		}

		$path = isset( $parts['path'] ) ? (string) $parts['path'] : '';
		return 1 === preg_match( '#^/api/webhooks/\d+/[A-Za-z0-9_-]+/?$#', $path );
	}

	/**
	 * Post a Discord payload when a webhook URL is saved.
	 *
	 * @param array<string, mixed> $message Discord webhook payload.
	 */
	public static function post( array $message ): void {
		if ( ! self::is_configured() || empty( $message ) ) {
			return;
		}

		$webhook = Options::get_options( 'discord_webhook_url' );
		if ( ! is_string( $webhook ) ) {
			return;
		}

		// The safe variant rejects loopback and private-network hosts, so an admin-supplied URL cannot reach internal services (SSRF).
		wp_safe_remote_post(
			esc_url_raw( $webhook ),
			array(
				'body'        => wp_json_encode( $message ),
				// Do not follow redirects: a redirect could point at an http:// URL and send the report in clear text.
				'redirection' => 0,
				'headers'     => array(
					'Content-Type' => 'application/json; charset=utf-8',
				),
			)
		);
	}
}
