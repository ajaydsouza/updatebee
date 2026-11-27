<?php
/**
 * Send outgoing webhook reports.
 *
 * @package updatebee
 */

namespace UpdateBee\Webhooks;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Post one JSON report per enabled webhook when a matching update report has rows.
 */
class Dispatcher {

	/**
	 * Register send hooks.
	 */
	public function run(): void {
		add_action( 'updatebee_automatic_updates_complete', array( $this, 'send_automatic' ), 20, 1 );
		add_action( 'updatebee_manual_updates_complete', array( $this, 'send_manual' ), 20, 1 );
		add_action( 'updatebee_available_updates_report', array( $this, 'send_available' ), 10, 1 );
	}

	/**
	 * Send reports for an automatic batch.
	 *
	 * @param array<int, array<string, mixed>> $updates Log rows from this request.
	 */
	public function send_automatic( array $updates ): void {
		$this->send_completion( $updates, 'automatic' );
	}

	/**
	 * Send reports for a manual batch.
	 *
	 * @param array<int, array<string, mixed>> $updates Log rows from this request.
	 */
	public function send_manual( array $updates ): void {
		$this->send_completion( $updates, 'manual' );
	}

	/**
	 * Send the available-updates digest.
	 *
	 * @param array<int, array<string, mixed>> $rows Rows from the available-updates email job.
	 */
	public function send_available( array $rows ): void {
		$this->dispatch_report( $rows, 'available', 'scheduled' );
	}

	/**
	 * Split a finished batch into success, failure, and fatal reports.
	 *
	 * @param array<int, array<string, mixed>> $updates Log rows.
	 * @param string                           $trigger automatic|manual.
	 */
	public function send_completion( array $updates, string $trigger ): void {
		foreach ( array( 'success', 'failure', 'fatal' ) as $report ) {
			$this->dispatch_report( $updates, $report, $trigger );
		}
	}

	/**
	 * Post a payload and optionally remember the HTTP result.
	 *
	 * @param array<string, mixed> $webhook Stored webhook.
	 * @param array<string, mixed> $payload JSON document.
	 * @param bool                 $record  Whether to store the delivery on the webhook.
	 * @return array{ok: bool, status: int, error: string, message: string}
	 */
	public static function send( array $webhook, array $payload, bool $record = true ): array {
		$url = isset( $webhook['url'] ) ? (string) $webhook['url'] : '';
		if ( ! Store::is_https_url( $url ) ) {
			return self::result( false, 0, '', __( 'Enter an HTTPS webhook URL.', 'updatebee' ) );
		}

		$report  = isset( $payload['report'] ) ? (string) $payload['report'] : '';
		$trigger = isset( $payload['trigger'] ) ? (string) $payload['trigger'] : '';

		/**
		 * Filter a webhook payload before it is sent.
		 *
		 * Return an empty array to skip the request.
		 *
		 * @param array<string, mixed> $payload  JSON document.
		 * @param array<string, mixed> $webhook  Webhook record. Header values are secrets.
		 * @param string               $report   success|failure|fatal|available.
		 * @param string               $trigger  automatic|manual|scheduled|test.
		 */
		$payload = apply_filters( 'updatebee_webhook_payload', $payload, $webhook, $report, $trigger );
		if ( ! is_array( $payload ) || array() === $payload ) {
			return self::result( false, 0, '', __( 'Webhook payload was empty.', 'updatebee' ) );
		}

		$body = wp_json_encode( $payload );
		if ( ! is_string( $body ) || '' === $body ) {
			return self::result( false, 0, '', __( 'Webhook payload could not be encoded.', 'updatebee' ) );
		}

		$method  = isset( $webhook['method'] ) ? strtoupper( (string) $webhook['method'] ) : 'POST';
		$headers = array(
			'Content-Type' => 'application/json; charset=utf-8',
		);
		if ( isset( $webhook['headers'] ) && is_array( $webhook['headers'] ) ) {
			foreach ( $webhook['headers'] as $header ) {
				if ( ! is_array( $header ) ) {
					continue;
				}
				$name = isset( $header['name'] ) ? (string) $header['name'] : '';
				if ( 'custom' === $name ) {
					$name = isset( $header['custom_name'] ) ? (string) $header['custom_name'] : '';
				}
				$value = isset( $header['value'] ) ? (string) $header['value'] : '';
				if ( '' === $name || '' === $value || 0 === strcasecmp( $name, 'Content-Type' ) ) {
					continue;
				}
				$headers[ $name ] = $value;
			}
		}

		$response = wp_safe_remote_request(
			$url,
			array(
				'method'      => $method,
				'body'        => $body,
				'headers'     => $headers,
				'redirection' => 0,
				'timeout'     => 15,
			)
		);

		$status = 0;
		$error  = '';
		$ok     = false;
		if ( is_wp_error( $response ) ) {
			$error = self::public_error( $response->get_error_message(), $url );
		} else {
			$status = (int) wp_remote_retrieve_response_code( $response );
			$ok     = $status >= 200 && $status < 300;
			if ( ! $ok ) {
				$error = $status > 0 ? (string) $status : __( 'The webhook request failed.', 'updatebee' );
			}
		}

		$id = isset( $webhook['id'] ) ? (string) $webhook['id'] : '';
		if ( $record && Store::is_id( $id ) ) {
			Store::record_delivery( $id, $status, $error );
		}

		if ( $ok ) {
			$message = __( 'Webhook request sent.', 'updatebee' );
		} elseif ( '' !== $error ) {
			$message = $error;
		} else {
			$message = __( 'The webhook request failed.', 'updatebee' );
		}

		return self::result( $ok, $status, $error, $message );
	}

	/**
	 * Send one report to every enabled webhook that includes it and has rows.
	 *
	 * @param array<int, array<string, mixed>> $rows    Candidate rows.
	 * @param string                           $report  Report key.
	 * @param string                           $trigger Trigger key.
	 */
	private function dispatch_report( array $rows, string $report, string $trigger ): void {
		foreach ( Store::enabled() as $webhook ) {
			$reports = isset( $webhook['reports'] ) && is_array( $webhook['reports'] ) ? $webhook['reports'] : array();
			if ( empty( $reports[ $report ] ) ) {
				continue;
			}

			$types    = isset( $webhook['types'] ) && is_array( $webhook['types'] ) ? $webhook['types'] : array();
			$filtered = Payload::filter_rows( $rows, $report, $types );
			if ( array() === $filtered ) {
				continue;
			}

			$payload = Payload::from_rows( $report, $trigger, $filtered );
			if ( empty( $payload['items'] ) ) {
				continue;
			}

			self::send( $webhook, $payload, true );
		}
	}

	/**
	 * Remove the URL and line breaks from a transport error.
	 *
	 * @param string $message Error message.
	 * @param string $url     Webhook URL.
	 * @return string
	 */
	private static function public_error( string $message, string $url ): string {
		$message = str_replace( array( $url, "\r", "\n" ), '', $message );
		$message = preg_replace( '/\s+/', ' ', $message );
		if ( ! is_string( $message ) ) {
			return __( 'The webhook request failed.', 'updatebee' );
		}
		$message = trim( $message );
		if ( '' === $message ) {
			return __( 'The webhook request failed.', 'updatebee' );
		}
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $message, 0, 180 );
		}

		return substr( $message, 0, 180 );
	}

	/**
	 * Shape a send result.
	 *
	 * @param bool   $ok      Whether the response was a 2xx.
	 * @param int    $status  HTTP status.
	 * @param string $error   Public error text.
	 * @param string $message Admin message.
	 * @return array{ok: bool, status: int, error: string, message: string}
	 */
	private static function result( bool $ok, int $status, string $error, string $message ): array {
		return array(
			'ok'      => $ok,
			'status'  => $status,
			'error'   => $error,
			'message' => $message,
		);
	}
}
