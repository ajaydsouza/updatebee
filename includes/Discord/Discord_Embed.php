<?php
/**
 * Discord embed payload helpers.
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
 * Build one Discord embed and keep it inside Discord's size limits.
 */
class Discord_Embed {

	/**
	 * Maximum characters in an embed description.
	 */
	public const DESCRIPTION_LIMIT = 4096;

	/**
	 * Green used when a batch has no failures.
	 */
	public const COLOR_OK = 3066993;

	/**
	 * Red used for failures and fatal alerts.
	 */
	public const COLOR_ALERT = 15158332;

	/**
	 * Blurple used for available-update digests.
	 */
	public const COLOR_INFO = 5793266;

	/**
	 * Group rows and format one line per item.
	 *
	 * @param array<int, array<string, string>> $rows Normalized rows.
	 * @param callable                          $line Callback that formats one row.
	 * @return array<int, array{heading: string, items: array<int, string>}>
	 */
	public static function groups( array $rows, callable $line ): array {
		$groups = array();
		foreach ( Slack_Row_Groups::group_rows( $rows ) as $update_type => $type_rows ) {
			$items = array();
			foreach ( $type_rows as $row ) {
				$items[] = (string) $line( $row );
			}
			if ( empty( $items ) ) {
				continue;
			}
			$groups[] = array(
				'heading' => Slack_Row_Groups::type_heading( (string) $update_type ),
				'items'   => $items,
			);
		}

		return $groups;
	}

	/**
	 * Join grouped lines and stop before the description limit.
	 *
	 * @param array<int, array{heading: string, items: array<int, string>}> $groups Grouped lines.
	 * @param string                                                        $lead   Summary shown above the list.
	 * @return string
	 */
	public static function fit_description( array $groups, string $lead = '' ): string {
		$lead      = trim( $lead );
		$separator = '' === $lead ? '' : "\n\n";
		$limit     = self::DESCRIPTION_LIMIT - self::length( $lead . $separator );
		if ( $limit < 1 ) {
			return self::clip( $lead, self::DESCRIPTION_LIMIT );
		}

		$body = self::fit_groups( $groups, $limit );
		if ( '' === $lead ) {
			return $body;
		}
		if ( '' === $body ) {
			return $lead;
		}

		return $lead . $separator . $body;
	}

	/**
	 * Join grouped lines and stop before a character limit.
	 *
	 * @param array<int, array{heading: string, items: array<int, string>}> $groups Grouped lines.
	 * @param int                                                           $limit  Maximum characters.
	 * @return string
	 */
	private static function fit_groups( array $groups, int $limit ): string {
		$total = 0;
		foreach ( $groups as $group ) {
			$total += count( $group['items'] );
		}

		$shown   = 0;
		$parts   = array();
		$stopped = false;

		foreach ( $groups as $group ) {
			if ( $stopped ) {
				break;
			}

			$kept = array();
			foreach ( $group['items'] as $item ) {
				$next_shown    = $shown + 1;
				$trial_kept    = array_merge( $kept, array( $item ) );
				$trial_parts   = $parts;
				$trial_parts[] = self::section( $group['heading'], $trial_kept );
				$trial         = implode( "\n\n", $trial_parts ) . self::more_line( $total - $next_shown );
				if ( self::length( $trial ) > $limit ) {
					$stopped = true;
					break;
				}
				$kept[] = $item;
				$shown  = $next_shown;
			}

			if ( ! empty( $kept ) ) {
				$parts[] = self::section( $group['heading'], $kept );
			}
		}

		$description = implode( "\n\n", $parts );
		$hidden      = $total - $shown;
		if ( $hidden < 1 ) {
			return $description;
		}

		$suffix = self::more_line( '' === $description ? max( 0, $total - 1 ) : $hidden );
		if ( '' === $description ) {
			$first  = isset( $groups[0]['items'][0] ) ? (string) $groups[0]['items'][0] : '';
			$budget = $limit - self::length( $suffix );
			if ( $budget < 1 ) {
				return self::clip( $suffix, $limit );
			}
			return self::clip( $first, $budget ) . $suffix;
		}

		return $description . $suffix;
	}

	/**
	 * Build the webhook body for one embed.
	 *
	 * @param string                                                        $title       Embed title.
	 * @param string                                                        $url         Title link.
	 * @param string                                                        $description Embed description.
	 * @param array<int, array{name: string, value: string, inline?: bool}> $fields      Embed fields.
	 * @param int                                                           $color       Decimal color.
	 * @param string                                                        $author_name Site name shown above the title.
	 * @param string                                                        $author_url  Link for the site name.
	 * @return array<string, mixed>
	 */
	public static function payload( string $title, string $url, string $description, array $fields, int $color, string $author_name = '', string $author_url = '' ): array {
		$author_name = trim( $author_name );
		$embed       = array(
			'title' => self::clip( $title, 256 ),
			'url'   => $url,
			'color' => $color,
		);
		if ( '' !== $author_name ) {
			$embed['author'] = array(
				'name' => self::clip( $author_name, 256 ),
			);
			if ( '' !== $author_url ) {
				$embed['author']['url'] = $author_url;
			}
		}
		if ( '' !== $description ) {
			$embed['description'] = self::clip( $description, self::DESCRIPTION_LIMIT );
		}
		$clean_fields = self::fields( $fields );
		if ( ! empty( $clean_fields ) ) {
			$embed['fields'] = $clean_fields;
		}

		return array(
			'username'         => self::username(),
			'allowed_mentions' => array(
				'parse' => array(),
			),
			'embeds'           => array( $embed ),
		);
	}

	/**
	 * Heading plus its item lines.
	 *
	 * @param string             $heading Section heading.
	 * @param array<int, string> $items   Item lines.
	 * @return string
	 */
	private static function section( string $heading, array $items ): string {
		return '**' . $heading . '**' . "\n" . implode( "\n", $items );
	}

	/**
	 * Omitted-item line, including the leading newline.
	 *
	 * @param int $hidden Number of items left out.
	 * @return string
	 */
	private static function more_line( int $hidden ): string {
		if ( $hidden < 1 ) {
			return '';
		}

		return "\n" . sprintf(
			/* translators: %d: Number of items omitted from the Discord message. */
			_n( 'and %d more', 'and %d more', $hidden, 'updatebee' ),
			$hidden
		);
	}

	/**
	 * Webhook display name.
	 *
	 * @return string
	 */
	private static function username(): string {
		$name = Functions::get_plugin_name();
		if ( '' === $name ) {
			$name = 'UpdateBee';
		}

		return self::clip( $name, 80 );
	}

	/**
	 * Keep embed fields inside Discord's per-field limits.
	 *
	 * @param array<int, array{name: string, value: string, inline?: bool}> $fields Embed fields.
	 * @return array<int, array{name: string, value: string, inline: bool}>
	 */
	private static function fields( array $fields ): array {
		$clean = array();
		foreach ( $fields as $field ) {
			$name  = self::clip( $field['name'], 256 );
			$value = self::clip( $field['value'], 1024 );
			if ( '' === $name || '' === $value ) {
				continue;
			}
			$clean[] = array(
				'name'   => $name,
				'value'  => $value,
				'inline' => ! empty( $field['inline'] ),
			);
			if ( count( $clean ) >= 25 ) {
				break;
			}
		}

		return $clean;
	}

	/**
	 * Character length, counting multibyte characters when possible.
	 *
	 * @param string $text Text to measure.
	 * @return int
	 */
	private static function length( string $text ): int {
		if ( function_exists( 'mb_strlen' ) ) {
			return (int) mb_strlen( $text, 'UTF-8' );
		}

		return strlen( $text );
	}

	/**
	 * Shorten text to a character limit.
	 *
	 * @param string $text  Text to shorten.
	 * @param int    $limit Maximum characters.
	 * @return string
	 */
	private static function clip( string $text, int $limit ): string {
		if ( $limit < 1 ) {
			return '';
		}
		if ( self::length( $text ) <= $limit ) {
			return $text;
		}
		if ( function_exists( 'mb_substr' ) ) {
			return (string) mb_substr( $text, 0, $limit, 'UTF-8' );
		}

		return substr( $text, 0, $limit );
	}
}
