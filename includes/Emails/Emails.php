<?php
/**
 * HTML email report builder.
 *
 * @package updatebee
 */

namespace UpdateBee\Emails;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use UpdateBee\Functions;

/**
 * Shared email template rendering.
 */
class Emails {

	/**
	 * Build the update report HTML email body.
	 *
	 * @param array<int, array<string, mixed>> $updates            Log rows.
	 * @param string                           $report_title       Email title.
	 * @param string                           $report_description Intro paragraph.
	 * @param string                           $cron_hook          Cron hook for next-run footer.
	 * @return string HTML email body.
	 */
	public static function get_update_report_email( array $updates, string $report_title, string $report_description, string $cron_hook ): string {
		// Load the HTML email shell. Without the template there is nothing to send.
		$template_path = __DIR__ . '/templates/update-report-blue.html';
		if ( ! file_exists( $template_path ) ) {
			return '';
		}

		ob_start();
		include $template_path;
		$report = ob_get_clean();
		if ( ! is_string( $report ) ) {
			return '';
		}

		// Fill in the header placeholders of the shell.
		$report = str_replace( '{{email_title}}', $report_title, $report );
		$report = str_replace( '{{site_url}}', esc_url( home_url( '/' ) ), $report );
		$report = str_replace( '{{site_name}}', esc_html( get_bloginfo( 'name' ) ), $report );
		$report = str_replace( '{{introduction}}', $report_description, $report );

		// Markup for one report section (a heading plus the table of rows), repeated for each update type.
		$item_table = '<table class="heading_block block-6" width="100%" border="0" cellpadding="0" cellspacing="0" role="presentation" style="mso-table-lspace: 0pt; mso-table-rspace: 0pt;">
		<tr>
			<td class="pad" style="text-align:center;width:100%;">
				<h1 style="margin: 0; color: #296bb9; direction: ltr; font-family: Verdana, Geneva, sans-serif; font-size: 22px; font-weight: 700; letter-spacing: normal; line-height: 120%; text-align: left; margin-top: 0; margin-bottom: 0;"><span class="tinyMce-placeholder">{{update_title}}</span></h1>
			</td>
		</tr>
	</table>
	<table class="html_block block-8" width="100%" border="0" cellpadding="0" cellspacing="0" role="presentation" style="mso-table-lspace: 0pt; mso-table-rspace: 0pt;">
		<tr>
			<td class="pad" style="padding-top:10px;">
				<div style="font-family:Verdana, Geneva, sans-serif;text-align:center;" align="center"><table width="100%" border="0" align="left" cellspacing="2px" cellpadding="5px">{{updates}}</div>
			</td>
		</tr>
	</table>';

		// Group the log rows by update type (plugin, theme, core, and so on).
		$update_items = array();
		foreach ( $updates as $update ) {
			$normalized = self::normalize_log_row( $update );
			if ( empty( $normalized ) ) {
				continue;
			}

			$type = $normalized['update_type'];
			if ( ! isset( $update_items[ $type ] ) ) {
				$update_items[ $type ] = array();
			}
			$update_items[ $type ][] = $normalized;
		}
		ksort( $update_items );

		// Build one section per update type.
		$output_html = '';
		foreach ( $update_items as $update_type => $update_item ) {
			// Only add the details column when at least one row has a link or a fatal message to show.
			$has_details = false;
			foreach ( $update_item as $normalized ) {
				if ( '' !== $normalized['detail_url'] || '' !== $normalized['fatal_message'] ) {
					$has_details = true;
					break;
				}
			}

			$inner_html      = self::report_table_start( $has_details );
			$section_content = $item_table;
			$section_title   = self::get_type_section_title( (string) $update_type );

			foreach ( $update_item as $normalized ) {
				$inner_html .= self::render_report_row( $normalized, $has_details );
			}
			$inner_html .= self::report_table_end();

			$section_content = str_replace( '{{update_title}}', $section_title, $section_content );
			$section_content = str_replace( '{{updates}}', $inner_html, $section_content );
			$output_html    .= $section_content;
		}

		$report = str_replace( '{{report}}', $output_html, $report );

		// Tell the reader when the next report is due, or that none are scheduled.
		$next_cron = wp_next_scheduled( $cron_hook );
		if ( $next_cron ) {
			$next_cron_text = __( 'Your next scheduled report will be sent on:', 'updatebee' ) . ' ' . get_date_from_gmt( gmdate( 'Y-m-d', $next_cron ), 'F j, Y' ) . ' ' . _x( 'at', 'at what time', 'updatebee' ) . ' ' . get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $next_cron ), 'g:i a' );
		} else {
			$next_cron_text = __( 'There are no scheduled email reports.', 'updatebee' );
		}

		// Link to the Emails settings so the schedule can be changed.
		$change_link = Functions::get_settings_url( 'emails' );
		$change_link = '<a href="' . esc_url( $change_link ) . '" target="_blank" style="text-decoration: underline; color: #e4af4b;" rel="noopener">' . esc_html__( 'Change', 'updatebee' ) . '</a>';

		$report = str_replace( '{{next_cron}}', $next_cron_text, $report );
		$report = str_replace( '{{change_link}}', $change_link, $report );

		return $report;
	}

	/**
	 * Normalize a log row for the email template.
	 *
	 * @param array<string, mixed> $row Log row from DB or hook batch.
	 * @return array<string, string>|array{}
	 */
	public static function normalize_log_row( array $row ): array {
		$name = isset( $row['name'] ) ? (string) $row['name'] : '';
		if ( '' === $name ) {
			return array();
		}

		$detail_url    = isset( $row['detail_url'] ) ? (string) $row['detail_url'] : '';
		$detail_label  = isset( $row['detail_label'] ) ? (string) $row['detail_label'] : '';
		$fatal_message = isset( $row['fatal_message'] ) ? (string) $row['fatal_message'] : '';
		if ( '' === $detail_label && '' !== $detail_url ) {
			$detail_label = __( 'View details', 'updatebee' );
		}

		return array(
			'name'          => $name,
			'update_type'   => isset( $row['update_type'] ) ? (string) $row['update_type'] : '',
			'from_version'  => isset( $row['from_version'] ) ? (string) $row['from_version'] : '',
			'to_version'    => isset( $row['to_version'] ) ? (string) $row['to_version'] : '',
			'date'          => isset( $row['date'] ) ? (string) $row['date'] : '',
			'update_status' => isset( $row['update_status'] ) ? (string) $row['update_status'] : '',
			'detail_url'    => $detail_url,
			'detail_label'  => $detail_label,
			'fatal_message' => $fatal_message,
		);
	}

	/**
	 * Real preview rows, or sample rows when the report is empty.
	 *
	 * @param array<int, array<string, mixed>> $rows Real report rows.
	 * @param string                           $kind success|failure|fatal|available.
	 * @return array<int, array<string, mixed>>
	 */
	public static function rows_for_preview( array $rows, string $kind ): array {
		if ( ! empty( $rows ) ) {
			return $rows;
		}

		return self::sample_preview_rows( $kind );
	}

	/**
	 * Intro text, with a sample-data notice when the preview is not real.
	 *
	 * @param string $intro     Report introduction.
	 * @param bool   $is_sample Whether the rows are samples.
	 * @return string
	 */
	public static function preview_intro( string $intro, bool $is_sample ): string {
		if ( ! $is_sample ) {
			return $intro;
		}

		return __( 'This preview uses sample data because there is nothing to report right now.', 'updatebee' ) . ' ' . $intro;
	}

	/**
	 * Sample log rows for an empty email preview.
	 *
	 * @param string $kind success|failure|fatal|available.
	 * @return array<int, array<string, mixed>>
	 */
	public static function sample_preview_rows( string $kind ): array {
		$date = current_time( 'mysql' );
		if ( ! is_string( $date ) ) {
			$date = '';
		}

		switch ( $kind ) {
			case 'failure':
				return array(
					self::sample_row( 'Akismet Anti-spam', 'plugin', '5.3.5', '5.3.6', $date, 'failure' ),
					self::sample_row( 'Twenty Twenty-Five', 'theme', '1.2', '1.3', $date, 'failure' ),
				);
			case 'fatal':
				$row                  = self::sample_row( 'Akismet Anti-spam', 'plugin', '5.3.5', '5.3.6', $date, 'fatal' );
				$row['fatal_message'] = 'Uncaught Error: Call to undefined function foo() in wp-content/plugins/akismet/akismet.php:100';
				$row['detail_url']    = Functions::get_settings_url( 'logs' );
				$row['detail_label']  = __( 'View logs', 'updatebee' );
				return array( $row );
			case 'available':
				return array(
					array_merge(
						self::sample_row( 'Akismet Anti-spam', 'plugin', '5.3.5', '5.3.6', $date, 'available' ),
						array(
							'detail_url'   => esc_url( 'https://wordpress.org/plugins/akismet/#developers' ),
							'detail_label' => __( 'Changelog', 'updatebee' ),
						)
					),
					array_merge(
						self::sample_row( 'Twenty Twenty-Five', 'theme', '1.2', '1.3', $date, 'available' ),
						array(
							'detail_url'   => esc_url( 'https://wordpress.org/themes/twentytwentyfive/' ),
							'detail_label' => __( 'Theme details', 'updatebee' ),
						)
					),
					array_merge(
						self::sample_row( 'WordPress', 'core', '6.8', '6.8.1', $date, 'available' ),
						array(
							'detail_url'   => esc_url( 'https://codex.wordpress.org/Version_6.8.1' ),
							'detail_label' => __( 'Changelog', 'updatebee' ),
						)
					),
					self::sample_row( 'WordPress (en_US)', 'translation', '', '6.8.1', $date, 'available' ),
				);
			case 'success':
				return array(
					self::sample_row( 'Akismet Anti-spam', 'plugin', '5.3.5', '5.3.6', $date, 'success' ),
					self::sample_row( 'Twenty Twenty-Five', 'theme', '1.2', '1.3', $date, 'success' ),
					self::sample_row( 'WordPress', 'core', '6.8', '6.8.1', $date, 'success' ),
					self::sample_row( 'WordPress (en_US)', 'translation', '', '6.8.1', $date, 'success' ),
				);
			default:
				return array();
		}
	}

	/**
	 * One sample log row.
	 *
	 * @param string $name         Item name.
	 * @param string $update_type  plugin|theme|core|translation.
	 * @param string $from_version Installed version.
	 * @param string $to_version   Target version.
	 * @param string $date         Display date.
	 * @param string $status       Update status.
	 * @return array<string, string>
	 */
	private static function sample_row( string $name, string $update_type, string $from_version, string $to_version, string $date, string $status ): array {
		return array(
			'name'          => $name,
			'update_type'   => $update_type,
			'from_version'  => $from_version,
			'to_version'    => $to_version,
			'date'          => $date,
			'update_status' => $status,
		);
	}

	/**
	 * Open a report table with optional Details column.
	 *
	 * @param bool $has_details Whether to include a Details header.
	 * @return string
	 */
	private static function report_table_start( bool $has_details ): string {
		$headers = '<th>' . esc_html__( 'Update name', 'updatebee' ) . '</th><th>' . esc_html__( 'Type', 'updatebee' ) . '</th><th>' . esc_html__( 'From', 'updatebee' ) . '</th><th>' . esc_html__( 'To', 'updatebee' ) . '</th><th>' . esc_html__( 'Date', 'updatebee' ) . '</th>';
		if ( $has_details ) {
			$headers .= '<th>' . esc_html__( 'Details', 'updatebee' ) . '</th>';
		}

		return '<table width="100%" border="0" align="left" cellspacing="2px" cellpadding="5px"><thead><tr align="left" bgcolor="#296bb9" style="color:#FFFFFF">' . $headers . '</thead><tbody align="left">';
	}

	/**
	 * Close a report table.
	 *
	 * @return string
	 */
	private static function report_table_end(): string {
		return '</tbody></table>';
	}

	/**
	 * Render one report table row.
	 *
	 * @param array<string, string> $normalized  Normalized row.
	 * @param bool                  $has_details Whether the section includes details links.
	 * @return string
	 */
	private static function render_report_row( array $normalized, bool $has_details ): string {
		ob_start();
		?>
		<tr bgcolor="#EAEAEA">
			<td><?php echo esc_html( $normalized['name'] ); ?></td>
			<td><?php echo esc_html( ucfirst( $normalized['update_type'] ) ); ?></td>
			<td><?php echo esc_html( $normalized['from_version'] ); ?></td>
			<td><?php echo esc_html( $normalized['to_version'] ); ?></td>
			<td><?php echo esc_html( $normalized['date'] ); ?></td>
			<?php if ( $has_details ) : ?>
				<td>
					<?php if ( '' !== $normalized['fatal_message'] ) : ?>
						<?php echo esc_html( $normalized['fatal_message'] ); ?>
						<?php if ( '' !== $normalized['detail_url'] ) : ?>
							<br />
						<?php endif; ?>
					<?php endif; ?>
					<?php if ( '' !== $normalized['detail_url'] ) : ?>
						<a href="<?php echo esc_url( $normalized['detail_url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $normalized['detail_label'] ); ?></a>
					<?php endif; ?>
				</td>
			<?php endif; ?>
		</tr>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Section heading for an update type bucket.
	 *
	 * @param string $update_type plugin|theme|core|translation.
	 * @return string
	 */
	private static function get_type_section_title( string $update_type ): string {
		switch ( $update_type ) {
			case 'plugin':
				return esc_html__( 'Plugins', 'updatebee' );
			case 'theme':
				return esc_html__( 'Themes', 'updatebee' );
			case 'translation':
				return esc_html__( 'Translations', 'updatebee' );
			case 'core':
				return esc_html__( 'WordPress core', 'updatebee' );
			default:
				return esc_html( ucfirst( $update_type ) );
		}
	}

	/**
	 * Expand safe CSS for HTML email tables.
	 *
	 * @param array<int, string> $css Allowed CSS properties.
	 * @return array<int, string>
	 */
	public static function allow_email_styles( array $css ): array {
		$extra = array(
			'display',
			'width',
			'height',
			'margin',
			'padding',
			'border',
			'color',
			'background',
			'background-color',
			'font-family',
			'font-size',
			'font-weight',
			'line-height',
			'text-align',
			'letter-spacing',
			'direction',
			'mso-table-lspace',
			'mso-table-rspace',
		);

		return array_values( array_unique( array_merge( $css, $extra ) ) );
	}
}
