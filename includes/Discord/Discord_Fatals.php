<?php
/**
 * Discord reports for post-update fatal errors.
 *
 * @package updatebee
 */

namespace UpdateBee\Discord;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use UpdateBee\Functions;
use UpdateBee\Slack\Slack_Row_Groups;

/**
 * Post a dedicated Discord alert when fatals are detected after automatic updates.
 */
class Discord_Fatals {

	/**
	 * Send an embed fatal-detection alert.
	 *
	 * The fatal-email caller can read this result. A failed delivery does not throw.
	 *
	 * @param array<int, array<string, mixed>> $updates Logged update rows.
	 * @return bool Whether Discord accepted the payload.
	 */
	public function send_report( array $updates ): bool {
		$rows = $this->normalize_rows( $updates );
		if ( empty( $rows ) ) {
			return false;
		}

		$message = $this->build_message( $rows );

		/**
		 * Filter the Discord payload before it is sent.
		 *
		 * @param array<string, mixed>              $message Discord webhook payload.
		 * @param array<int, array<string, string>> $rows    Normalized fatal rows.
		 */
		$message = apply_filters( 'updatebee_discord_fatals_message', $message, $rows );
		if ( ! is_array( $message ) || empty( $message ) ) {
			return false;
		}

		return Discord_Webhook::post( $message );
	}

	/**
	 * Build the Discord payload for fatal rows.
	 *
	 * @param array<int, array<string, string>> $rows Normalized fatal rows.
	 * @return array<string, mixed>
	 */
	public function build_message( array $rows ): array {
		$site_name = (string) get_bloginfo( 'name' );
		$logs_url  = Functions::get_settings_url( 'logs' );
		$total     = count( $rows );
		$note      = sprintf(
			/* translators: %s: Plugin name. */
			__( 'This is a fatal detection alert. %s did not deactivate any plugins. WordPress Recovery Mode may still email a login link separately. WordPress may have restored a previous plugin version on its own.', 'updatebee' ),
			Functions::get_plugin_name()
		);

		$groups = Discord_Embed::groups(
			$rows,
			static function ( array $row ): string {
				$type_label = Slack_Row_Groups::type_heading( $row['update_type'] );
				$from       = '' !== $row['from_version'] ? $row['from_version'] : '—';
				$to         = '' !== $row['to_version'] ? $row['to_version'] : '—';
				$line       = $row['name'] . ' — ' . $type_label . Discord_Embed::version_clause( $from, $to );
				if ( '' !== $row['fatal_message'] ) {
					$line .= "\n" . $row['fatal_message'];
				}
				return $line;
			}
		);

		return Discord_Embed::payload(
			sprintf(
				/* translators: %d: fatal count. */
				_n( '%d fatal after automatic updates', '%d fatals after automatic updates', $total, 'updatebee' ),
				$total
			),
			$logs_url,
			Discord_Embed::fit_description( $groups, $note ),
			array(),
			Discord_Embed::COLOR_ALERT,
			$site_name,
			home_url( '/' )
		);
	}

	/**
	 * Keep fatal rows that have a name.
	 *
	 * @param array<int, array<string, mixed>> $updates Logged update rows.
	 * @return array<int, array<string, string>>
	 */
	private function normalize_rows( array $updates ): array {
		$rows = array();
		foreach ( $updates as $update ) {
			if ( ! is_array( $update ) ) {
				continue;
			}
			if ( 'fatal' !== ( $update['update_status'] ?? '' ) ) {
				continue;
			}

			$name = isset( $update['name'] ) ? (string) $update['name'] : '';
			if ( '' === $name ) {
				continue;
			}

			$fatal_message = isset( $update['fatal_message'] ) ? trim( (string) $update['fatal_message'] ) : '';
			if ( '' === $fatal_message && isset( $update['raw'] ) ) {
				$fatal_message = self::first_line( (string) $update['raw'] );
			}

			$rows[] = array(
				'name'          => $name,
				'update_type'   => isset( $update['update_type'] ) ? (string) $update['update_type'] : '',
				'from_version'  => isset( $update['from_version'] ) ? (string) $update['from_version'] : '',
				'to_version'    => isset( $update['to_version'] ) ? (string) $update['to_version'] : '',
				'fatal_message' => $fatal_message,
				'update_status' => 'fatal',
			);
		}

		return $rows;
	}

	/**
	 * First non-empty line of raw notes.
	 *
	 * @param string $notes Raw upgrade notes.
	 * @return string
	 */
	private static function first_line( string $notes ): string {
		$notes = trim( $notes );
		if ( '' === $notes ) {
			return '';
		}

		$lines = preg_split( '/\r\n|\r|\n/', $notes );
		if ( ! is_array( $lines ) || empty( $lines[0] ) ) {
			return '';
		}

		return (string) $lines[0];
	}
}
