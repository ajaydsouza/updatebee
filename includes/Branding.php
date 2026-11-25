<?php
/**
 * Plugin branding resolver.
 *
 * Branding can be locked with the UPDATEBEE_BRANDING constant or the
 * updatebee_branding_locked and updatebee_branding filters. The lock applies
 * only while the license is active.
 *
 * @package updatebee
 */

namespace UpdateBee;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use UpdateBee\Updater\License_Details;

/**
 * Apply saved branding to plugin identity surfaces.
 */
class Branding {

	/**
	 * Site option that stores saved branding.
	 */
	public const OPTION_KEY = 'updatebee_branding';

	/**
	 * Saved branding field keys.
	 *
	 * @var string[]
	 */
	private const FIELD_KEYS = array(
		'branding_name',
		'branding_description',
		'branding_author',
		'branding_url',
		'branding_hide_license_tab',
	);

	/**
	 * Cached plugin header defaults.
	 *
	 * @var array<string, string>|null
	 */
	private static $header = null;

	/**
	 * Cached saved branding.
	 *
	 * @var array<string, mixed>|null
	 */
	private static $stored = null;

	/**
	 * License state used by tests. Null reads the stored license.
	 *
	 * @var bool|null
	 */
	private static $license_active_override = null;

	/**
	 * Register branding filters.
	 */
	public function run(): void {
		add_filter( 'updatebee_plugin_name', array( $this, 'filter_plugin_name' ) );
		add_filter( 'all_plugins', array( $this, 'filter_all_plugins' ) );
	}

	/**
	 * Reset cached header defaults and saved branding.
	 */
	public static function flush_cache(): void {
		self::$header                  = null;
		self::$stored                  = null;
		self::$license_active_override = null;
	}

	/**
	 * Override the license check.
	 *
	 * Tests use this so the suite does not define UPDATEBEE_BRANDING. Null reads the stored license.
	 *
	 * @param bool|null $active Active license, or null to read storage.
	 */
	public static function set_license_active_override( ?bool $active ): void {
		self::$license_active_override = $active;
	}

	/**
	 * Whether a branding field belongs in the branding option.
	 *
	 * @param string $key Option key.
	 * @return bool
	 */
	public static function is_branding_key( string $key ): bool {
		return in_array( $key, self::FIELD_KEYS, true );
	}

	/**
	 * Whether branding is locked for this request.
	 *
	 * The lock requires an active license and either the UPDATEBEE_BRANDING constant or the updatebee_branding_locked filter.
	 *
	 * @return bool
	 */
	public static function is_locked(): bool {
		return self::lock_applies( self::license_is_active(), self::constant_is_defined() );
	}

	/**
	 * Whether branding is locked for a license state and a requested lock.
	 *
	 * An inactive license is never locked. The updatebee_branding_locked filter can force the lock on or off.
	 *
	 * @param bool $license_is_active Whether the license is active.
	 * @param bool $constant_defined  Whether UPDATEBEE_BRANDING is an array.
	 * @return bool
	 */
	public static function lock_applies( bool $license_is_active, bool $constant_defined ): bool {
		if ( ! $license_is_active ) {
			return false;
		}

		/**
		 * Whether plugin branding is locked.
		 *
		 * @param bool $locked Whether the UPDATEBEE_BRANDING constant is defined as an array.
		 */
		return (bool) apply_filters( 'updatebee_branding_locked', $constant_defined );
	}

	/**
	 * Locked branding values after the updatebee_branding filter.
	 *
	 * @param array<string, mixed> $constant Raw UPDATEBEE_BRANDING values.
	 * @return array{name: string, description: string, author: string, url: string, hide_license: bool}
	 */
	public static function resolve_locked_config( array $constant ): array {
		$values = array(
			'name'         => '',
			'description'  => '',
			'author'       => '',
			'url'          => '',
			'hide_license' => false,
		);

		foreach ( $values as $key => $fallback ) {
			if ( array_key_exists( $key, $constant ) ) {
				$values[ $key ] = $constant[ $key ];
			}
			unset( $fallback );
		}

		/**
		 * Locked branding values.
		 *
		 * @param array{name: string, description: string, author: string, url: string, hide_license: bool} $values Branding values.
		 */
		$filtered = apply_filters( 'updatebee_branding', $values );
		if ( ! is_array( $filtered ) ) {
			$filtered = $values;
		}

		return self::sanitize_lock_config( $filtered );
	}

	/**
	 * Replace the display name when a branded name is saved.
	 *
	 * @param string $plugin_name Default plugin name.
	 * @return string
	 */
	public function filter_plugin_name( string $plugin_name ): string {
		$branded = self::get_saved_name();
		if ( '' !== $branded ) {
			return $branded;
		}

		return $plugin_name;
	}

	/**
	 * Override this plugin's row on the Plugins screen.
	 *
	 * @param array<string, mixed> $plugins Installed plugins.
	 * @return array<string, mixed>
	 */
	public function filter_all_plugins( array $plugins ): array {
		$file = Functions::get_plugin_file();
		if ( ! isset( $plugins[ $file ] ) || ! is_array( $plugins[ $file ] ) ) {
			return $plugins;
		}

		$name = self::get_saved_name();
		if ( '' !== $name ) {
			$plugins[ $file ]['Name']  = $name;
			$plugins[ $file ]['Title'] = $name;
		}

		$description = self::get_saved_description();
		if ( '' !== $description ) {
			$plugins[ $file ]['Description'] = $description;
		}

		$author = self::get_saved_author();
		if ( '' !== $author ) {
			$plugins[ $file ]['Author']     = $author;
			$plugins[ $file ]['AuthorName'] = $author;
		}

		$url = self::get_saved_url();
		if ( '' !== $url ) {
			$plugins[ $file ]['PluginURI'] = $url;
			$plugins[ $file ]['AuthorURI'] = $url;
		}

		return $plugins;
	}

	/**
	 * Effective plugin name, or empty when using the default.
	 *
	 * @return string
	 */
	public static function get_saved_name(): string {
		if ( self::is_locked() ) {
			return self::locked_config()['name'];
		}

		return self::stored_string( 'branding_name' );
	}

	/**
	 * Effective plugin description, or empty when using the default.
	 *
	 * @return string
	 */
	public static function get_saved_description(): string {
		if ( self::is_locked() ) {
			return self::locked_config()['description'];
		}

		return self::stored_string( 'branding_description' );
	}

	/**
	 * Effective plugin author, or empty when using the default.
	 *
	 * @return string
	 */
	public static function get_saved_author(): string {
		if ( self::is_locked() ) {
			return self::locked_config()['author'];
		}

		return self::stored_string( 'branding_author' );
	}

	/**
	 * Effective plugin URL, or empty when using the default.
	 *
	 * @return string
	 */
	public static function get_saved_url(): string {
		if ( self::is_locked() ) {
			return self::locked_config()['url'];
		}

		return self::stored_string( 'branding_url' );
	}

	/**
	 * Saved branding, including values copied out of the main options.
	 *
	 * An unconfirmed copy is returned for this read and is not cached, so the next read can try again.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_stored(): array {
		if ( is_array( self::$stored ) ) {
			return self::$stored;
		}

		$raw = get_site_option( self::OPTION_KEY, null );
		if ( is_array( $raw ) ) {
			self::$stored = self::normalize_stored( $raw );
			return self::$stored;
		}

		$migrated = self::migrate_legacy();
		if ( null !== $migrated ) {
			if ( $migrated['persisted'] ) {
				self::$stored = $migrated['values'];
			}

			return $migrated['values'];
		}

		self::$stored = self::defaults();
		return self::$stored;
	}

	/**
	 * Merge fields into the branding option.
	 *
	 * @param array<string, mixed> $fields Branding fields.
	 * @return bool
	 */
	public static function update_stored( array $fields ): bool {
		$next = self::normalize_stored( array_merge( self::get_stored(), $fields ) );
		update_site_option( self::OPTION_KEY, $next );
		self::$stored = $next;

		return true;
	}

	/**
	 * Replace saved branding with empty defaults.
	 */
	public static function reset_stored(): void {
		$defaults = self::defaults();
		update_site_option( self::OPTION_KEY, $defaults );
		self::$stored = $defaults;
	}

	/**
	 * Delete saved branding.
	 */
	public static function delete_stored(): void {
		delete_site_option( self::OPTION_KEY );
		self::$stored = null;
	}

	/**
	 * Bundled default logo URL.
	 *
	 * @return string
	 */
	public static function get_default_logo_url(): string {
		return Functions::get_plugin_url( '/assets/logo.png' );
	}

	/**
	 * Plugin-header defaults used as placeholders.
	 *
	 * @return array<string, string>
	 */
	public static function get_header_defaults(): array {
		if ( is_array( self::$header ) ) {
			return self::$header;
		}

		$defaults = array(
			'name'        => __( 'UpdateBee', 'updatebee' ),
			'description' => '',
			'author'      => '',
			'url'         => '',
		);

		if ( defined( 'UPDATEBEE_FILE' ) ) {
			if ( ! function_exists( 'get_plugin_data' ) ) {
				$plugin_file = ABSPATH . 'wp-admin/includes/plugin.php';
				if ( file_exists( $plugin_file ) ) {
					require_once $plugin_file;
				}
			}

			if ( function_exists( 'get_plugin_data' ) ) {
				$data = get_plugin_data( UPDATEBEE_FILE, false, false );
				if ( is_array( $data ) ) {
					if ( ! empty( $data['Name'] ) ) {
						$defaults['name'] = (string) $data['Name'];
					}
					if ( ! empty( $data['Description'] ) ) {
						$defaults['description'] = (string) $data['Description'];
					}
					if ( ! empty( $data['Author'] ) ) {
						$defaults['author'] = wp_strip_all_tags( (string) $data['Author'] );
					}
					if ( ! empty( $data['PluginURI'] ) ) {
						$defaults['url'] = (string) $data['PluginURI'];
					}
				}
			}
		}

		self::$header = $defaults;

		return $defaults;
	}

	/**
	 * Sanitize branding fields for storage.
	 *
	 * @param array<string, mixed> $input Raw branding fields.
	 * @return array<string, string>
	 */
	public static function sanitize_settings( array $input ): array {
		$name = '';
		if ( isset( $input['branding_name'] ) ) {
			$name = sanitize_text_field( (string) $input['branding_name'] );
		}

		$description = '';
		if ( isset( $input['branding_description'] ) ) {
			$description = sanitize_textarea_field( (string) $input['branding_description'] );
		}

		$author = '';
		if ( isset( $input['branding_author'] ) ) {
			$author = sanitize_text_field( (string) $input['branding_author'] );
		}

		$url = '';
		if ( isset( $input['branding_url'] ) ) {
			$raw = sanitize_text_field( (string) $input['branding_url'] );
			$url = '' !== $raw ? esc_url_raw( $raw ) : '';
		}

		return array(
			'branding_name'        => $name,
			'branding_description' => $description,
			'branding_author'      => $author,
			'branding_url'         => $url,
		);
	}

	/**
	 * Whether the License tab should be omitted.
	 *
	 * The saved choice applies only while the stored license is active. A lock uses its own hide_license value.
	 *
	 * @return bool
	 */
	public static function hides_license_tab(): bool {
		if ( ! self::license_is_active() ) {
			return false;
		}

		if ( self::is_locked() ) {
			return self::locked_config()['hide_license'];
		}

		return (bool) self::get_stored()['branding_hide_license_tab'];
	}

	/**
	 * Value to store for the hide-license toggle.
	 *
	 * An inactive license keeps the saved choice and does not accept a new one.
	 *
	 * @param bool $requested         Requested toggle value.
	 * @param bool $license_is_active Whether the stored license is active.
	 * @param bool $stored            Currently saved value.
	 * @return bool
	 */
	public static function resolve_hide_license_tab( bool $requested, bool $license_is_active, bool $stored ): bool {
		if ( ! $license_is_active ) {
			return $stored;
		}

		return $requested;
	}

	/**
	 * Whether UPDATEBEE_BRANDING is an array.
	 *
	 * @return bool
	 */
	private static function constant_is_defined(): bool {
		return defined( 'UPDATEBEE_BRANDING' ) && is_array( constant( 'UPDATEBEE_BRANDING' ) );
	}

	/**
	 * Constant values, or an empty array when the constant is absent.
	 *
	 * @return array<string, mixed>
	 */
	private static function constant_config(): array {
		if ( ! self::constant_is_defined() ) {
			return array();
		}

		$config = constant( 'UPDATEBEE_BRANDING' );
		if ( ! is_array( $config ) ) {
			return array();
		}

		return $config;
	}

	/**
	 * Sanitized lock values for this request.
	 *
	 * @return array{name: string, description: string, author: string, url: string, hide_license: bool}
	 */
	private static function locked_config(): array {
		return self::resolve_locked_config( self::constant_config() );
	}

	/**
	 * Whether the stored license is active.
	 *
	 * @return bool
	 */
	private static function license_is_active(): bool {
		if ( null !== self::$license_active_override ) {
			return self::$license_active_override;
		}

		try {
			return License_Details::is_active();
		} catch ( \Throwable $exception ) {
			unset( $exception );
			return false;
		}
	}

	/**
	 * One stored string field.
	 *
	 * @param string $key branding_name, branding_description, branding_author, or branding_url.
	 * @return string
	 */
	private static function stored_string( string $key ): string {
		$stored = self::get_stored();
		$value  = $stored[ $key ] ?? '';

		return is_string( $value ) ? $value : '';
	}

	/**
	 * Empty branding option.
	 *
	 * @return array<string, mixed>
	 */
	private static function defaults(): array {
		return array(
			'branding_name'             => '',
			'branding_description'      => '',
			'branding_author'           => '',
			'branding_url'              => '',
			'branding_hide_license_tab' => false,
		);
	}

	/**
	 * Normalize a stored branding array.
	 *
	 * @param array<string, mixed> $raw Raw branding fields.
	 * @return array<string, mixed>
	 */
	private static function normalize_stored( array $raw ): array {
		$stored = self::defaults();
		foreach ( $stored as $key => $fallback ) {
			if ( ! array_key_exists( $key, $raw ) ) {
				continue;
			}

			if ( 'branding_hide_license_tab' === $key ) {
				$stored[ $key ] = (bool) $raw[ $key ];
				continue;
			}

			$stored[ $key ] = is_string( $raw[ $key ] ) ? trim( $raw[ $key ] ) : '';
			unset( $fallback );
		}

		return $stored;
	}

	/**
	 * Copy branding keys out of the main options array.
	 *
	 * Legacy keys stay until a read-back of the branding option matches.
	 * update_site_option() returns false when the stored value is unchanged, so that read-back decides whether cleanup runs.
	 * A failed copy can run again.
	 *
	 * @return array{values: array<string, mixed>, persisted: bool}|null Saved branding, or null when the main option has none.
	 */
	private static function migrate_legacy(): ?array {
		$main = get_site_option( Options::OPTIONS_KEY, array() );
		if ( ! is_array( $main ) ) {
			return null;
		}

		$found  = false;
		$values = self::defaults();
		foreach ( self::FIELD_KEYS as $key ) {
			if ( ! array_key_exists( $key, $main ) ) {
				continue;
			}

			$found          = true;
			$values[ $key ] = $main[ $key ];
		}

		if ( ! $found ) {
			return null;
		}

		$values = self::normalize_stored( $values );
		update_site_option( self::OPTION_KEY, $values );
		if ( ! self::destination_matches( $values ) ) {
			return array(
				'values'    => $values,
				'persisted' => false,
			);
		}

		foreach ( self::FIELD_KEYS as $key ) {
			unset( $main[ $key ] );
		}

		update_site_option( Options::OPTIONS_KEY, $main );
		Options::flush_cache();

		return array(
			'values'    => $values,
			'persisted' => true,
		);
	}

	/**
	 * Whether the branding option contains these values.
	 *
	 * @param array<string, mixed> $values Expected branding.
	 * @return bool
	 */
	private static function destination_matches( array $values ): bool {
		$saved = get_site_option( self::OPTION_KEY, null );
		if ( ! is_array( $saved ) ) {
			return false;
		}

		return self::normalize_stored( $saved ) === $values;
	}

	/**
	 * Sanitize one locked branding config.
	 *
	 * @param array<string, mixed> $config Raw lock values.
	 * @return array{name: string, description: string, author: string, url: string, hide_license: bool}
	 */
	private static function sanitize_lock_config( array $config ): array {
		$sanitized = self::sanitize_settings(
			array(
				'branding_name'        => $config['name'] ?? '',
				'branding_description' => $config['description'] ?? '',
				'branding_author'      => $config['author'] ?? '',
				'branding_url'         => $config['url'] ?? '',
			)
		);

		return array(
			'name'         => $sanitized['branding_name'],
			'description'  => $sanitized['branding_description'],
			'author'       => $sanitized['branding_author'],
			'url'          => $sanitized['branding_url'],
			'hide_license' => self::is_truthy( $config['hide_license'] ?? false ),
		);
	}

	/**
	 * Whether a lock flag should hide the License tab.
	 *
	 * @param mixed $value Raw hide_license value.
	 * @return bool
	 */
	private static function is_truthy( $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}

		if ( is_int( $value ) ) {
			return 1 === $value;
		}

		if ( is_string( $value ) ) {
			return in_array( strtolower( $value ), array( '1', 'true', 'yes', 'on' ), true );
		}

		return false;
	}
}
