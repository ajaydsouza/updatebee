<?php
/**
 * Discord reports after updates finish.
 *
 * @package updatebee
 */

namespace UpdateBee\Discord;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use UpdateBee\Functions;
use UpdateBee\Options;

/**
 * Post one Discord report for a finished automatic or manual update batch.
 */
class Discord_Report {

	/**
	 * Register completion hooks.
	 */
	public function run(): void {
		add_action( 'updatebee_automatic_updates_complete', array( $this, 'send_automatic_report' ), 10, 1 );
		add_action( 'updatebee_manual_updates_complete', array( $this, 'send_manual_report' ), 10, 1 );
	}

	/**
	 * Send the report for an automatic batch.
	 *
	 * @param array<int, array<string, mixed>> $updates Log rows from this request.
	 */
	public function send_automatic_report( array $updates ): void {
		$this->send_report( $updates, 'automatic' );
	}

	/**
	 * Send the report for a manual batch.
	 *
	 * @param array<int, array<string, mixed>> $updates Log rows from this request.
	 */
	public function send_manual_report( array $updates ): void {
		$this->send_report( $updates, 'manual' );
	}

	/**
	 * Post an embed when a webhook is saved and Discord sending is on.
	 *
	 * Completion hooks ignore this result. A failed delivery does not interrupt the update batch.
	 *
	 * @param array<int, array<string, mixed>> $updates Log rows from this request.
	 * @param string                           $offer   automatic|manual.
	 * @return bool Whether Discord accepted the payload.
	 */
	public function send_report( array $updates, string $offer ): bool {
		if ( ! Discord_Webhook::is_configured() || ! Options::get_options( 'discord_enabled' ) ) {
			return false;
		}

		$rows = $this->normalize_rows( $updates );
		if ( empty( $rows ) ) {
			return false;
		}

		$message = $this->build_message( $rows, $offer );

		/**
		 * Filter the Discord payload before it is sent.
		 *
		 * @param array<string, mixed>              $message Discord webhook payload.
		 * @param array<int, array<string, string>> $rows    Normalized log rows.
		 * @param string                            $offer   automatic|manual.
		 */
		$message = apply_filters( 'updatebee_discord_message', $message, $rows, $offer );
		if ( ! is_array( $message ) || empty( $message ) ) {
			return false;
		}

		return Discord_Webhook::post( $message );
	}

	/**
	 * Build the Discord payload.
	 *
	 * @param array<int, array<string, string>> $rows  Normalized log rows.
	 * @param string                            $offer automatic|manual.
	 * @return array<string, mixed>
	 */
	public function build_message( array $rows, string $offer ): array {
		$site_name = (string) get_bloginfo( 'name' );
		$site_url  = home_url( '/' );
		$logs_url  = Functions::get_settings_url( 'logs' );
		$successes = 0;
		$failures  = 0;
		$fatals    = 0;

		foreach ( $rows as $row ) {
			if ( 'success' === $row['update_status'] || 'security' === $row['update_status'] ) {
				++$successes;
			} elseif ( 'failure' === $row['update_status'] ) {
				++$failures;
			} elseif ( 'fatal' === $row['update_status'] ) {
				++$fatals;
			}
		}

		$trigger = ( 'manual' === $offer )
			? __( 'Manual', 'updatebee' )
			: __( 'Automatic', 'updatebee' );

		$summary = sprintf(
			/* translators: 1: Automatic or Manual, 2: success count, 3: failure count. */
			__( '%1$s · %2$d succeeded · %3$d failed', 'updatebee' ),
			$trigger,
			$successes,
			$failures
		);
		if ( $fatals > 0 ) {
			$summary .= ' · ' . sprintf(
				/* translators: %d: fatal count. */
				_n( '%d fatal', '%d fatals', $fatals, 'updatebee' ),
				$fatals
			);
		}
		if ( 'manual' === $offer ) {
			$summary .= ' · ' . $this->manual_user_label( $rows );
		}

		$groups = Discord_Embed::groups(
			$rows,
			static function ( array $row ): string {
				$status_label = $row['update_status'];
				if ( 'security' === $status_label ) {
					$status_label = __( 'Success - Security Release', 'updatebee' );
				}
				return $row['name'] . ' — ' . $status_label . Discord_Embed::version_clause( $row['from_version'], $row['to_version'] );
			}
		);

		return Discord_Embed::payload(
			__( 'Updates finished', 'updatebee' ),
			$logs_url,
			Discord_Embed::fit_description( $groups, $summary ),
			array(),
			( $failures > 0 || $fatals > 0 ) ? Discord_Embed::COLOR_ALERT : Discord_Embed::COLOR_OK,
			$site_name,
			$site_url
		);
	}

	/**
	 * Keep named rows, omitting delayed updates when that Discord setting is on.
	 *
	 * @param array<int, array<string, mixed>> $updates Log rows.
	 * @return array<int, array<string, string>>
	 */
	private function normalize_rows( array $updates ): array {
		$skip_delayed = (bool) Options::get_options( 'discord_skip_delayed' );
		$rows         = array();
		foreach ( $updates as $update ) {
			if ( ! is_array( $update ) ) {
				continue;
			}
			$status = isset( $update['update_status'] ) ? (string) $update['update_status'] : '';
			if ( $skip_delayed && 'delayed' === $status ) {
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
				'update_status' => $status,
				'user_id'       => isset( $update['user_id'] ) ? (string) $update['user_id'] : '0',
			);
		}

		return $rows;
	}

	/**
	 * Display name for the user who ran a manual update.
	 *
	 * @param array<int, array<string, string>> $rows Normalized rows.
	 * @return string
	 */
	private function manual_user_label( array $rows ): string {
		$user_id = 0;
		foreach ( $rows as $row ) {
			$user_id = (int) $row['user_id'];
			if ( $user_id > 0 ) {
				break;
			}
		}

		if ( $user_id < 1 ) {
			return __( 'Unknown', 'updatebee' );
		}

		$user = get_userdata( $user_id );
		if ( is_object( $user ) && isset( $user->display_name ) && '' !== (string) $user->display_name ) {
			return (string) $user->display_name;
		}

		return __( 'Unknown', 'updatebee' );
	}
}
