<?php
/**
 * Formats the results of a forced automatic update run.
 *
 * @package updatebee
 */

namespace UpdateBee\Updates;

use UpdateBee\Options;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * User-facing summary of a forced update run.
 */
class Force_Update_Report {

	/**
	 * Summarize the updates WordPress attempted.
	 *
	 * @param array<string, list<object>> $results WordPress automatic update results.
	 * @return string Update summary.
	 */
	public static function message( array $results ): string {
		$messages = array();
		$failed   = array();
		$counts   = array_fill_keys( array( 'plugin', 'theme', 'translation' ), 0 );

		foreach ( $results as $type => $updates ) {
			foreach ( $updates as $update ) {
				if ( empty( $update->result ) || is_wp_error( $update->result ) ) {
					$name = ! empty( $update->name ) ? $update->name : ( $update->item->plugin ?? $update->item->theme ?? __( 'WordPress core', 'updatebee' ) );
					if ( 'translation' === $type ) {
						/* translators: 1: Translation name, 2: Locale. */
						$name = sprintf( __( '%1$s (%2$s)', 'updatebee' ), $name, $update->item->language );
					}
					$failed[] = wp_strip_all_tags( $name );
				} elseif ( 'core' === $type ) {
					$version    = $update->item->version ?? $update->item->current;
					$messages[] = sprintf(
						/* translators: %s: WordPress version. */
						__( 'WordPress core updated to %s.', 'updatebee' ),
						$version
					);
				} elseif ( isset( $counts[ $type ] ) ) {
					++$counts[ $type ];
				}
			}
		}

		if ( $counts['plugin'] ) {
			/* translators: %s: Number of plugins updated. */
			$messages[] = sprintf( _n( '%s plugin updated.', '%s plugins updated.', $counts['plugin'], 'updatebee' ), number_format_i18n( $counts['plugin'] ) );
		}
		if ( $counts['theme'] ) {
			/* translators: %s: Number of themes updated. */
			$messages[] = sprintf( _n( '%s theme updated.', '%s themes updated.', $counts['theme'], 'updatebee' ), number_format_i18n( $counts['theme'] ) );
		}
		if ( $counts['translation'] ) {
			/* translators: %s: Number of translations updated. */
			$messages[] = sprintf( _n( '%s translation updated.', '%s translations updated.', $counts['translation'], 'updatebee' ), number_format_i18n( $counts['translation'] ) );
		}
		if ( ! $messages ) {
			$messages[] = __( 'No updates were installed.', 'updatebee' );
		}
		if ( $failed ) {
			$messages[] = sprintf(
				/* translators: %s: Names of updates that failed. */
				__( 'Failed to update %s.', 'updatebee' ),
				implode( wp_get_list_item_separator(), $failed )
			);
		}

		if ( ! $results ) {
			if ( 'on' !== Options::get_options( 'plugin_auto_updates' ) && ! empty( get_site_transient( 'update_plugins' )->response ) ) {
				$messages[] = __( 'Plugin auto-updates are off.', 'updatebee' );
			}
			if ( 'on' !== Options::get_options( 'theme_auto_updates' ) && ! empty( get_site_transient( 'update_themes' )->response ) ) {
				$messages[] = __( 'Theme auto-updates are off.', 'updatebee' );
			}
		}

		return implode( ' ', $messages );
	}
}
