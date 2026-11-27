<?php
/**
 * Admin AJAX for outgoing webhooks.
 *
 * @package updatebee
 */

namespace UpdateBee\Webhooks;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use UpdateBee\Functions;

/**
 * List, save, and test webhooks. Every handler checks a nonce and can_access_options().
 */
class Rest {

	public const NONCE_LIST = 'updatebee-get-webhooks';

	public const NONCE_GET = 'updatebee-retrieve-webhook';

	public const NONCE_SAVE = 'updatebee-save-webhook';

	public const NONCE_TEST = 'updatebee-send-webhook-test';

	/**
	 * Register admin-ajax actions.
	 */
	public function run(): void {
		add_action( 'wp_ajax_updatebee_get_webhooks', array( $this, 'ajax_get_webhooks' ) );
		add_action( 'wp_ajax_updatebee_retrieve_webhook', array( $this, 'ajax_retrieve_webhook' ) );
		add_action( 'wp_ajax_updatebee_save_webhook', array( $this, 'ajax_save_webhook' ) );
		add_action( 'wp_ajax_updatebee_delete_webhook', array( $this, 'ajax_delete_webhook' ) );
		add_action( 'wp_ajax_updatebee_set_webhook_enabled', array( $this, 'ajax_set_webhook_enabled' ) );
		add_action( 'wp_ajax_updatebee_send_webhook_test', array( $this, 'ajax_send_webhook_test' ) );
	}

	/**
	 * Nonce used to delete one webhook.
	 *
	 * @param string $id Webhook id.
	 * @return string
	 */
	public static function delete_nonce_action( string $id ): string {
		return 'updatebee-delete-webhook-' . $id;
	}

	/**
	 * Nonce used to enable or disable one webhook.
	 *
	 * @param string $id Webhook id.
	 * @return string
	 */
	public static function enable_nonce_action( string $id ): string {
		return 'updatebee-enable-webhook-' . $id;
	}

	/**
	 * Return the table rows.
	 */
	public function ajax_get_webhooks(): void {
		$this->authorize( self::NONCE_LIST );

		$webhooks = array();
		foreach ( Store::all() as $webhook ) {
			$webhooks[] = $this->list_payload( $webhook );
		}

		wp_send_json_success(
			array(
				'webhooks' => $webhooks,
			)
		);
	}

	/**
	 * Return one webhook for the editor.
	 */
	public function ajax_retrieve_webhook(): void {
		$this->authorize( self::NONCE_GET );

		$id      = $this->posted_id();
		$webhook = Store::find( $id );
		if ( null === $webhook ) {
			wp_send_json_error(
				array(
					'message' => __( 'Webhook could not be found.', 'updatebee' ),
				)
			);
		}

		wp_send_json_success(
			array(
				'webhook' => $this->editor_payload( $webhook ),
			)
		);
	}

	/**
	 * Create or update a webhook.
	 */
	public function ajax_save_webhook(): void {
		$this->authorize( self::NONCE_SAVE );

		$id    = $this->posted_id();
		$saved = Store::save( $this->input_from_request(), $id );
		if ( is_wp_error( $saved ) ) {
			wp_send_json_error(
				array(
					'message' => $saved->get_error_message(),
				)
			);
		}

		wp_send_json_success(
			array(
				'message' => __( 'Webhook saved.', 'updatebee' ),
				'webhook' => $this->editor_payload( $saved ),
			)
		);
	}

	/**
	 * Delete a webhook.
	 */
	public function ajax_delete_webhook(): void {
		if ( ! Functions::can_access_options() ) {
			wp_send_json_error();
		}

		$id = $this->posted_id();
		check_ajax_referer( self::delete_nonce_action( $id ), 'nonce' );

		if ( ! Store::delete( $id ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Webhook could not be deleted.', 'updatebee' ),
				)
			);
		}

		wp_send_json_success(
			array(
				'id'      => $id,
				'message' => __( 'Webhook deleted.', 'updatebee' ),
			)
		);
	}

	/**
	 * Enable or disable a webhook from the list.
	 */
	public function ajax_set_webhook_enabled(): void {
		if ( ! Functions::can_access_options() ) {
			wp_send_json_error();
		}

		$id = $this->posted_id();
		check_ajax_referer( self::enable_nonce_action( $id ), 'nonce' );

		$enabled = $this->posted_bool( 'enabled', false );
		$saved   = Store::set_enabled( $id, $enabled );
		if ( is_wp_error( $saved ) ) {
			wp_send_json_error(
				array(
					'message' => $saved->get_error_message(),
				)
			);
		}

		wp_send_json_success(
			array(
				'message' => $enabled
					? __( 'Webhook enabled.', 'updatebee' )
					: __( 'Webhook disabled.', 'updatebee' ),
				'webhook' => $this->list_payload( $saved ),
			)
		);
	}

	/**
	 * Send a sample payload. Unsaved forms are sent but not stored.
	 */
	public function ajax_send_webhook_test(): void {
		$this->authorize( self::NONCE_TEST );

		$id      = $this->posted_id();
		$webhook = Store::sanitize( $this->input_from_request(), $id );
		if ( is_wp_error( $webhook ) ) {
			wp_send_json_error(
				array(
					'message' => $webhook->get_error_message(),
				)
			);
		}

		$record = false;
		if ( '' !== $id ) {
			if ( null === Store::find( $id ) ) {
				wp_send_json_error(
					array(
						'message' => __( 'Webhook could not be found.', 'updatebee' ),
					)
				);
			}
			$webhook['id'] = $id;
			$record        = true;
		}

		$result = Dispatcher::send( $webhook, Payload::sample(), $record );
		if ( empty( $result['ok'] ) ) {
			wp_send_json_error(
				array(
					'message' => $result['message'],
				)
			);
		}

		wp_send_json_success(
			array(
				'message'      => $result['message'],
				'lastDelivery' => array(
					'time'   => '',
					'status' => $result['status'],
					'error'  => $result['error'],
				),
			)
		);
	}

	/**
	 * Capability and nonce check for screen-level actions.
	 *
	 * @param string $action Nonce action.
	 */
	private function authorize( string $action ): void {
		if ( ! Functions::can_access_options() ) {
			wp_send_json_error();
		}

		check_ajax_referer( $action, 'nonce' );
	}

	/**
	 * Webhook id from the request.
	 *
	 * @return string
	 */
	private function posted_id(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Caller checks the nonce.
		if ( ! isset( $_POST['id'] ) ) {
			return '';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Caller checks the nonce.
		$id = sanitize_text_field( wp_unslash( $_POST['id'] ) );
		if ( 'new' === $id ) {
			return '';
		}

		return $id;
	}

	/**
	 * Boolean from a posted scalar.
	 *
	 * @param string $key      Request key.
	 * @param bool   $fallback Value when the key is missing.
	 * @return bool
	 */
	private function posted_bool( string $key, bool $fallback ): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Caller checks the nonce.
		if ( ! isset( $_POST[ $key ] ) ) {
			return $fallback;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Boolean is sanitized below. Caller checks the nonce.
		return Functions::sanitize_rest_boolean( wp_unslash( $_POST[ $key ] ) );
	}

	/**
	 * Editor fields from the request. Store::sanitize() validates them.
	 *
	 * @return array<string, mixed>
	 */
	private function input_from_request(): array {
		return array(
			// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized in Store::sanitize(). Caller checks the nonce.
			'name'    => isset( $_POST['name'] ) ? wp_unslash( $_POST['name'] ) : '',
			// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized in Store::sanitize(). Caller checks the nonce.
			'url'     => isset( $_POST['url'] ) ? wp_unslash( $_POST['url'] ) : '',
			// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized in Store::sanitize(). Caller checks the nonce.
			'enabled' => isset( $_POST['enabled'] ) ? wp_unslash( $_POST['enabled'] ) : true,
			// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized in Store::sanitize(). Caller checks the nonce.
			'method'  => isset( $_POST['method'] ) ? wp_unslash( $_POST['method'] ) : 'POST',
			'headers' => $this->posted_array( 'headers' ),
			'reports' => $this->posted_array( 'reports' ),
			'types'   => $this->posted_array( 'types' ),
		);
	}

	/**
	 * Posted array, or an empty array.
	 *
	 * @param string $key Request key.
	 * @return array<mixed>
	 */
	private function posted_array( string $key ): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Caller checks the nonce.
		if ( ! isset( $_POST[ $key ] ) || ! is_array( $_POST[ $key ] ) ) {
			return array();
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized in Store::sanitize().
		return wp_unslash( $_POST[ $key ] );
	}

	/**
	 * Row for the DataViews table. Header values are omitted.
	 *
	 * @param array<string, mixed> $webhook Stored webhook.
	 * @return array<string, mixed>
	 */
	private function list_payload( array $webhook ): array {
		$id              = isset( $webhook['id'] ) ? (string) $webhook['id'] : '';
		$reports         = isset( $webhook['reports'] ) && is_array( $webhook['reports'] ) ? $webhook['reports'] : array();
		$enabled_reports = array();
		foreach ( Store::REPORTS as $report ) {
			if ( ! empty( $reports[ $report ] ) ) {
				$enabled_reports[] = $report;
			}
		}

		$delivery = isset( $webhook['last_delivery'] ) && is_array( $webhook['last_delivery'] ) ? $webhook['last_delivery'] : array();

		return array(
			'id'           => $id,
			'name'         => isset( $webhook['name'] ) ? (string) $webhook['name'] : '',
			'url'          => isset( $webhook['url'] ) ? (string) $webhook['url'] : '',
			'enabled'      => ! empty( $webhook['enabled'] ),
			'method'       => isset( $webhook['method'] ) ? (string) $webhook['method'] : 'POST',
			'reports'      => $enabled_reports,
			'lastDelivery' => array(
				'time'   => isset( $delivery['time'] ) ? (string) $delivery['time'] : '',
				'status' => isset( $delivery['status'] ) ? (int) $delivery['status'] : 0,
				'error'  => isset( $delivery['error'] ) ? (string) $delivery['error'] : '',
			),
			'deleteNonce'  => wp_create_nonce( self::delete_nonce_action( $id ) ),
			'enableNonce'  => wp_create_nonce( self::enable_nonce_action( $id ) ),
		);
	}

	/**
	 * Full editor record, including header values.
	 *
	 * @param array<string, mixed> $webhook Stored webhook.
	 * @return array<string, mixed>
	 */
	private function editor_payload( array $webhook ): array {
		$headers = array();
		if ( isset( $webhook['headers'] ) && is_array( $webhook['headers'] ) ) {
			foreach ( $webhook['headers'] as $header ) {
				if ( ! is_array( $header ) ) {
					continue;
				}
				$headers[] = array(
					'name'       => isset( $header['name'] ) ? (string) $header['name'] : '',
					'customName' => isset( $header['custom_name'] ) ? (string) $header['custom_name'] : '',
					'value'      => isset( $header['value'] ) ? (string) $header['value'] : '',
				);
			}
		}

		$delivery = isset( $webhook['last_delivery'] ) && is_array( $webhook['last_delivery'] ) ? $webhook['last_delivery'] : array();

		return array(
			'id'           => isset( $webhook['id'] ) ? (string) $webhook['id'] : '',
			'name'         => isset( $webhook['name'] ) ? (string) $webhook['name'] : '',
			'url'          => isset( $webhook['url'] ) ? (string) $webhook['url'] : '',
			'enabled'      => ! empty( $webhook['enabled'] ),
			'method'       => isset( $webhook['method'] ) ? (string) $webhook['method'] : 'POST',
			'headers'      => $headers,
			'reports'      => $this->flag_payload( $webhook['reports'] ?? array(), Store::REPORTS ),
			'types'        => $this->flag_payload( $webhook['types'] ?? array(), Store::TYPES ),
			'lastDelivery' => array(
				'time'   => isset( $delivery['time'] ) ? (string) $delivery['time'] : '',
				'status' => isset( $delivery['status'] ) ? (int) $delivery['status'] : 0,
				'error'  => isset( $delivery['error'] ) ? (string) $delivery['error'] : '',
			),
		);
	}

	/**
	 * Boolean map for the editor.
	 *
	 * @param mixed    $flags   Stored flags.
	 * @param string[] $allowed Allowed keys.
	 * @return array<string, bool>
	 */
	private function flag_payload( $flags, array $allowed ): array {
		if ( ! is_array( $flags ) ) {
			$flags = array();
		}

		$payload = array();
		foreach ( $allowed as $key ) {
			$payload[ $key ] = ! empty( $flags[ $key ] );
		}

		return $payload;
	}
}
