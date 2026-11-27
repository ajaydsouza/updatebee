<?php
/**
 * Immediate fatal error email reports.
 *
 * @package updatebee
 */

namespace UpdateBee\Emails;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use UpdateBee\Discord\Discord_Fatals;
use UpdateBee\Discord\Discord_Webhook;
use UpdateBee\Functions;
use UpdateBee\Logs;
use UpdateBee\Options;
use UpdateBee\Slack\Slack_Fatals;
use UpdateBee\Slack\Slack_Webhook;
use UpdateBee\Updates\Fatal_Detection;

/**
 * Send an immediate email when a post-update fatal is logged.
 */
class Email_Fatals {

	/**
	 * Register completion hook.
	 */
	public function run(): void {
		add_action( 'updatebee_automatic_updates_complete', array( $this, 'maybe_send_fatal_report' ), 9, 1 );
		add_action( 'wp_ajax_updatebee_preview_email_fatals', array( $this, 'ajax_preview_email' ) );
	}

	/**
	 * Send immediately when detection is on and the batch contains fatals.
	 *
	 * @param array<int, array<string, mixed>> $updates Logged update rows.
	 */
	public function maybe_send_fatal_report( array $updates ): void {
		if ( ! Fatal_Detection::is_enabled() ) {
			return;
		}

		$filtered = self::filter_fatal_updates( $updates );
		if ( empty( $filtered ) ) {
			return;
		}

		$recipients = self::get_recipients();
		if ( ! empty( $recipients ) ) {
			$body = Emails::get_update_report_email(
				$filtered,
				self::get_report_title(),
				self::get_report_intro(),
				''
			);

			$this->send_email( $body, $recipients );
		}

		$this->maybe_send_slack_report( $filtered );
		$this->maybe_send_discord_report( $filtered );
	}

	/**
	 * Post a dedicated Slack fatal alert when enabled and a webhook is saved.
	 *
	 * @param array<int, array<string, mixed>> $updates Fatal log rows.
	 */
	private function maybe_send_slack_report( array $updates ): void {
		if ( ! Options::get_options( 'fatal_notify_slack' ) ) {
			return;
		}

		if ( ! Slack_Webhook::is_configured() ) {
			return;
		}

		$slack = new Slack_Fatals();
		$slack->send_report( $updates );
	}

	/**
	 * Post a dedicated Discord fatal alert when enabled and a webhook is saved.
	 *
	 * @param array<int, array<string, mixed>> $updates Fatal log rows.
	 */
	private function maybe_send_discord_report( array $updates ): void {
		if ( ! Options::get_options( 'fatal_notify_discord' ) ) {
			return;
		}

		if ( ! Discord_Webhook::is_configured() ) {
			return;
		}

		$discord = new Discord_Fatals();
		$discord->send_report( $updates );
	}

	/**
	 * Preview the fatal report, using sample rows when no fatal logs exist.
	 */
	public function ajax_preview_email(): void {
		if ( ! Functions::can_access_options() ) {
			wp_die( esc_html__( 'Security check failed.', 'updatebee' ) );
		}

		$nonce = isset( $_GET['nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'updatebee-preview-email-fatals' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'updatebee' ) );
		}

		$updates   = self::rows_from_fatal_logs( Logs::get_recent_fatal_logs() );
		$is_sample = empty( $updates );
		$updates   = Emails::rows_for_preview( $updates, 'fatal' );

		$email_body = Emails::get_update_report_email(
			$updates,
			self::get_report_title(),
			Emails::preview_intro( self::get_report_intro(), $is_sample ),
			''
		);

		add_filter( 'safe_style_css', array( Emails::class, 'allow_email_styles' ) );
		echo wp_kses( $email_body, Functions::get_kses_allowed_html( false, true ) );
		remove_filter( 'safe_style_css', array( Emails::class, 'allow_email_styles' ) );
		exit;
	}

	/**
	 * Keep only fatal log rows.
	 *
	 * @param array<int, array<string, mixed>> $updates Update rows.
	 * @return array<int, array<string, mixed>>
	 */
	public static function filter_fatal_updates( array $updates ): array {
		$filtered = array();
		foreach ( $updates as $update ) {
			if ( ! is_array( $update ) ) {
				continue;
			}
			if ( 'fatal' !== ( $update['update_status'] ?? '' ) ) {
				continue;
			}
			$filtered[] = $update;
		}

		return $filtered;
	}

	/**
	 * Recipients for fatal reports, falling back to the network admin email.
	 *
	 * @return array<int, string>
	 */
	public static function get_recipients(): array {
		$value  = Options::get_options( 'fatal_email_recipients' );
		$emails = array();
		if ( is_array( $value ) ) {
			foreach ( $value as $email ) {
				if ( ! is_string( $email ) ) {
					continue;
				}
				$email = sanitize_email( $email );
				if ( is_email( $email ) ) {
					$emails[] = $email;
				}
			}
		}

		$emails = array_values( array_unique( $emails ) );

		/**
		 * Filter fatal report recipients.
		 *
		 * @since 1.0.0
		 *
		 * @param array<int, string> $emails Recipient list.
		 */
		$filtered = apply_filters( 'updatebee_fatal_email_recipients', $emails );
		if ( is_array( $filtered ) ) {
			$emails = array();
			foreach ( $filtered as $email ) {
				if ( is_string( $email ) && is_email( $email ) ) {
					$emails[] = $email;
				}
			}
			$emails = array_values( array_unique( $emails ) );
		}

		if ( ! empty( $emails ) ) {
			return $emails;
		}

		$admin_email = sanitize_email( (string) get_site_option( 'admin_email' ) );
		if ( is_email( $admin_email ) ) {
			return array( $admin_email );
		}

		return array();
	}

	/**
	 * Recipients shown on the Emails card.
	 *
	 * @return array<int, string>
	 */
	public static function get_recipients_for_display(): array {
		return self::get_recipients();
	}

	/**
	 * Fatal report title.
	 *
	 * @return string
	 */
	private static function get_report_title(): string {
		return __( 'Fatal error after automatic updates', 'updatebee' );
	}

	/**
	 * Fatal report introduction.
	 *
	 * @return string
	 */
	private static function get_report_intro(): string {
		return sprintf(
			/* translators: %s: Plugin name. */
			__( 'A PHP fatal error was detected after automatic updates. %s did not deactivate any plugins. WordPress Recovery Mode may still email a login link separately. WordPress may have restored a previous plugin version on its own.', 'updatebee' ),
			Functions::get_plugin_name()
		);
	}

	/**
	 * Shape fatal log rows for the email template.
	 *
	 * @param array<int, array<string, mixed>> $logs Fatal log rows.
	 * @return array<int, array<string, mixed>>
	 */
	private static function rows_from_fatal_logs( array $logs ): array {
		$logs_url = Functions::get_settings_url( 'logs' );
		$rows     = array();

		foreach ( $logs as $log ) {
			if ( ! is_array( $log ) ) {
				continue;
			}

			$name = isset( $log['name'] ) ? (string) $log['name'] : '';
			if ( '' === $name ) {
				continue;
			}

			$rows[] = array(
				'name'          => $name,
				'update_type'   => isset( $log['update_type'] ) ? (string) $log['update_type'] : '',
				'from_version'  => isset( $log['from_version'] ) ? (string) $log['from_version'] : '',
				'to_version'    => isset( $log['to_version'] ) ? (string) $log['to_version'] : '',
				'date'          => isset( $log['date'] ) ? (string) $log['date'] : '',
				'update_status' => 'fatal',
				'fatal_message' => self::first_fatal_line( isset( $log['raw'] ) ? (string) $log['raw'] : '' ),
				'detail_url'    => $logs_url,
				'detail_label'  => __( 'View logs', 'updatebee' ),
			);
		}

		return $rows;
	}

	/**
	 * First line of a fatal note, or a short fallback.
	 *
	 * @param string $notes Raw log notes.
	 * @return string
	 */
	private static function first_fatal_line( string $notes ): string {
		$notes = trim( $notes );
		if ( '' === $notes ) {
			return __( 'A PHP fatal error was detected after this update.', 'updatebee' );
		}

		$lines = preg_split( '/\r\n|\r|\n/', $notes );
		$first = is_array( $lines ) ? trim( (string) ( $lines[0] ?? '' ) ) : '';
		if ( '' === $first ) {
			return __( 'A PHP fatal error was detected after this update.', 'updatebee' );
		}

		return $first;
	}

	/**
	 * Send the HTML fatal report.
	 *
	 * @param string             $email_body HTML body.
	 * @param array<int, string> $recipients To addresses.
	 */
	private function send_email( string $email_body, array $recipients ): void {
		if ( '' === trim( $email_body ) || empty( $recipients ) ) {
			return;
		}

		$subject = sprintf(
			/* translators: %s: site name. */
			__( 'Fatal error after automatic updates on %s', 'updatebee' ),
			get_bloginfo( 'name' )
		);

		$headers = array(
			sprintf(
				'From: %s <%s>',
				sanitize_text_field( get_bloginfo( 'name' ) ),
				sanitize_text_field( get_bloginfo( 'admin_email' ) )
			),
			'Content-Type: text/html; charset=UTF-8',
		);

		add_filter( 'safe_style_css', array( Emails::class, 'allow_email_styles' ) );
		$email_body = wp_kses( $email_body, Functions::get_kses_allowed_html( false, true ) );
		wp_mail( $recipients, $subject, $email_body, $headers );
		remove_filter( 'safe_style_css', array( Emails::class, 'allow_email_styles' ) );
	}
}
