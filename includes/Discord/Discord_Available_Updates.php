<?php
/**
 * Discord reports for scheduled available-updates digests.
 *
 * @package updatebee
 */

namespace UpdateBee\Discord;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use UpdateBee\Functions;

/**
 * Post available updates to Discord when enabled on the report.
 */
class Discord_Available_Updates {

	/**
	 * Send an embed digest for pending updates.
	 *
	 * @param array<int, array<string, mixed>> $updates Report rows.
	 */
	public function send_report( array $updates ): void {
		$rows = $this->normalize_rows( $updates );
		if ( empty( $rows ) ) {
			return;
		}

		$message = $this->build_message( $rows );

		/**
		 * Filter the Discord payload before it is sent.
		 *
		 * @param array<string, mixed>              $message Discord webhook payload.
		 * @param array<int, array<string, string>> $rows    Normalized rows.
		 */
		$message = apply_filters( 'updatebee_discord_available_updates_message', $message, $rows );
		if ( ! is_array( $message ) || empty( $message ) ) {
			return;
		}

		Discord_Webhook::post( $message );
	}

	/**
	 * Build the Discord payload.
	 *
	 * @param array<int, array<string, string>> $rows Normalized rows.
	 * @return array<string, mixed>
	 */
	public function build_message( array $rows ): array {
		$site_name = (string) get_bloginfo( 'name' );
		$logs_url  = Functions::get_settings_url( 'logs' );
		$total     = count( $rows );

		$groups = Discord_Embed::groups(
			$rows,
			static function ( array $row ): string {
				$from = '' !== $row['from_version'] ? $row['from_version'] : '—';
				$line = sprintf(
					'%1$s — %2$s → %3$s',
					$row['name'],
					$from,
					$row['to_version']
				);
				if ( '' !== $row['detail_url'] ) {
					$label = '' !== $row['detail_label'] ? $row['detail_label'] : __( 'Details', 'updatebee' );
					$line .= ' — [' . $label . '](' . $row['detail_url'] . ')';
				}
				return $line;
			}
		);

		return Discord_Embed::payload(
			sprintf(
				/* translators: %d: number of available updates. */
				_n( '%d update available', '%d updates available', $total, 'updatebee' ),
				$total
			),
			$logs_url,
			Discord_Embed::fit_description( $groups ),
			array(),
			Discord_Embed::COLOR_INFO,
			$site_name,
			home_url( '/' )
		);
	}

	/**
	 * Keep rows that have a name.
	 *
	 * @param array<int, array<string, mixed>> $updates Report rows.
	 * @return array<int, array<string, string>>
	 */
	private function normalize_rows( array $updates ): array {
		$rows = array();
		foreach ( $updates as $update ) {
			if ( ! is_array( $update ) ) {
				continue;
			}
			$name = isset( $update['name'] ) ? (string) $update['name'] : '';
			if ( '' === $name ) {
				continue;
			}
			$rows[] = array(
				'name'          => $name,
				'update_type'   => isset( $update['update_type'] ) ? (string) $update['update_type'] : '',
				'from_version'  => isset( $update['from_version'] ) ? (string) $update['from_version'] : '',
				'to_version'    => isset( $update['to_version'] ) ? (string) $update['to_version'] : '',
				'detail_url'    => isset( $update['detail_url'] ) ? (string) $update['detail_url'] : '',
				'detail_label'  => isset( $update['detail_label'] ) ? (string) $update['detail_label'] : '',
				'update_status' => isset( $update['update_status'] ) ? (string) $update['update_status'] : '',
			);
		}

		return $rows;
	}
}
