<?php
/**
 * JSON body for an outgoing webhook report.
 *
 * @package updatebee
 */

namespace UpdateBee\Webhooks;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Build one report document from log-shaped rows.
 */
class Payload {

	public const NOTE_LENGTH = 500;

	/**
	 * Map stored type flags onto log update_type values.
	 *
	 * @var array<string, string>
	 */
	private const TYPE_FLAGS = array(
		'plugin'       => 'plugins',
		'plugins'      => 'plugins',
		'theme'        => 'themes',
		'themes'       => 'themes',
		'translation'  => 'translations',
		'translations' => 'translations',
		'core'         => 'core',
	);

	/**
	 * Singular type stored on each item.
	 *
	 * @var array<string, string>
	 */
	private const TYPE_NAMES = array(
		'plugin'       => 'plugin',
		'plugins'      => 'plugin',
		'theme'        => 'theme',
		'themes'       => 'theme',
		'translation'  => 'translation',
		'translations' => 'translation',
		'core'         => 'core',
	);

	/**
	 * Sample document used by Send test.
	 *
	 * @return array<string, mixed>
	 */
	public static function sample(): array {
		return self::from_rows(
			'success',
			'test',
			array(
				array(
					'name'          => 'Akismet',
					'slug'          => 'akismet/akismet.php',
					'update_type'   => 'plugin',
					'from_version'  => '5.3',
					'to_version'    => '5.4',
					'update_status' => 'success',
					'raw'           => '',
				),
			)
		);
	}

	/**
	 * Keep rows that belong in this report and pass the webhook's type flags.
	 *
	 * @param array<int, array<string, mixed>> $rows   Log-shaped rows.
	 * @param string                           $report success|failure|fatal|available.
	 * @param array<string, mixed>             $types  Type flags from the webhook.
	 * @return array<int, array<string, mixed>>
	 */
	public static function filter_rows( array $rows, string $report, array $types ): array {
		$matched = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || ! self::status_matches( $row, $report ) || ! self::type_allowed( $row, $types ) ) {
				continue;
			}
			$matched[] = $row;
		}

		return $matched;
	}

	/**
	 * Build the JSON document for one report.
	 *
	 * @param string                           $report  success|failure|fatal|available.
	 * @param string                           $trigger automatic|manual|scheduled|test.
	 * @param array<int, array<string, mixed>> $rows    Rows already filtered for this webhook.
	 * @return array<string, mixed>
	 */
	public static function from_rows( string $report, string $trigger, array $rows ): array {
		$items = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$item = self::item( $row );
			if ( null !== $item ) {
				$items[] = $item;
			}
		}

		$site_name = sanitize_text_field( (string) get_bloginfo( 'name' ) );
		$site_url  = esc_url_raw( home_url( '/' ) );

		return array(
			'report'  => $report,
			'trigger' => $trigger,
			'site'    => array(
				'name' => $site_name,
				'url'  => $site_url,
			),
			'counts'  => array(
				'items' => count( $items ),
			),
			'items'   => $items,
		);
	}

	/**
	 * Whether a row's status belongs in the report.
	 *
	 * Successful reports include security releases.
	 *
	 * @param array<string, mixed> $row    Log row.
	 * @param string               $report Report key.
	 * @return bool
	 */
	private static function status_matches( array $row, string $report ): bool {
		$status = isset( $row['update_status'] ) ? (string) $row['update_status'] : '';
		if ( 'success' === $report ) {
			return in_array( $status, array( 'success', 'security' ), true );
		}

		return $status === $report;
	}

	/**
	 * Whether the webhook includes this row's update type.
	 *
	 * @param array<string, mixed> $row   Log row.
	 * @param array<string, mixed> $types Type flags.
	 * @return bool
	 */
	private static function type_allowed( array $row, array $types ): bool {
		$update_type = isset( $row['update_type'] ) ? (string) $row['update_type'] : '';
		$flag        = self::TYPE_FLAGS[ $update_type ] ?? '';
		if ( '' === $flag ) {
			return false;
		}

		return ! empty( $types[ $flag ] );
	}

	/**
	 * One item, without stack traces.
	 *
	 * @param array<string, mixed> $row Log row.
	 * @return array<string, string>|null
	 */
	private static function item( array $row ): ?array {
		$name = sanitize_text_field( (string) ( $row['name'] ?? '' ) );
		if ( '' === $name ) {
			return null;
		}

		$update_type = isset( $row['update_type'] ) ? (string) $row['update_type'] : '';
		$type        = self::TYPE_NAMES[ $update_type ] ?? '';
		if ( '' === $type ) {
			return null;
		}

		$notes = '';
		if ( isset( $row['fatal_message'] ) && is_string( $row['fatal_message'] ) && '' !== $row['fatal_message'] ) {
			$notes = $row['fatal_message'];
		} elseif ( isset( $row['raw'] ) && is_string( $row['raw'] ) ) {
			$notes = $row['raw'];
		}

		return array(
			'name'         => $name,
			'slug'         => sanitize_text_field( (string) ( $row['slug'] ?? '' ) ),
			'type'         => $type,
			'from_version' => sanitize_text_field( (string) ( $row['from_version'] ?? '' ) ),
			'to_version'   => sanitize_text_field( (string) ( $row['to_version'] ?? '' ) ),
			'status'       => sanitize_text_field( (string) ( $row['update_status'] ?? '' ) ),
			'notes'        => self::notes( $notes ),
		);
	}

	/**
	 * Plain-text notes, truncated, with stack traces left out of the payload.
	 *
	 * @param string $notes Raw notes.
	 * @return string
	 */
	private static function notes( string $notes ): string {
		$notes = wp_strip_all_tags( $notes );
		$notes = preg_replace( '/\s+/', ' ', $notes );
		if ( ! is_string( $notes ) ) {
			return '';
		}
		$notes = trim( $notes );
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $notes, 0, self::NOTE_LENGTH );
		}

		return substr( $notes, 0, self::NOTE_LENGTH );
	}
}
