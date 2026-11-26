<?php
/**
 * Scoped override for the AUTOMATIC_UPDATER_DISABLED default.
 *
 * @package updatebee
 */

namespace UpdateBee\Updates;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use UpdateBee\Options;

class Automatic_Updater_Override {

	public const OPTION          = 'automatic_updater_disabled_override';
	public const FILTER_PRIORITY = PHP_INT_MIN;

	public static function is_constant_active(): bool {
		return defined( 'AUTOMATIC_UPDATER_DISABLED' ) && (bool) AUTOMATIC_UPDATER_DISABLED;
	}

	public static function is_enabled(): bool {
		if ( ! self::is_constant_active() ) {
			return false;
		}

		$value = Options::get_options( self::OPTION );
		return true === $value || 1 === $value || '1' === $value || 'on' === $value || 'true' === $value;
	}

	public static function can_ignore_for_one_run(): bool {
		return self::is_constant_active() && ! self::is_enabled();
	}

	public static function filter_disabled( $disabled ): bool {
		return self::allow_constant_default( $disabled, self::is_enabled() );
	}

	public static function filter_disabled_for_one_run( $disabled ): bool {
		return self::allow_constant_default( $disabled, self::is_constant_active() );
	}

	public static function allow_constant_default( $disabled, bool $override_enabled ): bool {
		return $override_enabled && $disabled ? false : (bool) $disabled;
	}

	public static function with_one_run_override( callable $callback ): mixed {
		if ( ! self::can_ignore_for_one_run() ) {
			return $callback();
		}

		$filter = array( self::class, 'filter_disabled_for_one_run' );
		add_filter( 'automatic_updater_disabled', $filter, self::FILTER_PRIORITY );

		try {
			return $callback();
		} finally {
			remove_filter( 'automatic_updater_disabled', $filter, self::FILTER_PRIORITY );
		}
	}

	public static function is_disabled( bool $doing_cron = false ): bool {
		if ( ! class_exists( '\\WP_Automatic_Updater' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-automatic-updater.php';
		}

		$updater = new \WP_Automatic_Updater();
		if ( ! $doing_cron ) {
			return $updater->is_disabled();
		}

		add_filter( 'wp_doing_cron', '__return_true' );
		try {
			return $updater->is_disabled();
		} finally {
			remove_filter( 'wp_doing_cron', '__return_true' );
		}
	}
}
