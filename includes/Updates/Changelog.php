<?php
/**
 * Changelog HTML for installed plugins and themes.
 *
 * @package updatebee
 */

namespace UpdateBee\Updates;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolve a WordPress.org or third-party changelog without calling the info API until one is opened.
 */
class Changelog {

	/**
	 * Largest readme or changelog file to read.
	 */
	private const MAX_LOCAL_BYTES = 524288;

	/**
	 * Whether a plugin row can offer a changelog without a remote request.
	 *
	 * @param string $file Plugin basename.
	 * @return bool
	 */
	public static function plugin_is_available( string $file ): bool {
		return self::is_available( self::plugin_context( $file ) );
	}

	/**
	 * Whether a theme row can offer a changelog without a remote request.
	 *
	 * @param string $stylesheet Theme stylesheet.
	 * @return bool
	 */
	public static function theme_is_available( string $stylesheet ): bool {
		return self::is_available( self::theme_context( $stylesheet ) );
	}

	/**
	 * Sanitized changelog HTML for one installed plugin.
	 *
	 * @param string $file Plugin basename.
	 * @return string HTML, or an empty string when none can be resolved.
	 */
	public static function plugin_html( string $file ): string {
		return self::html_for( self::plugin_context( $file ) );
	}

	/**
	 * Sanitized changelog HTML for one installed theme.
	 *
	 * @param string $stylesheet Theme stylesheet.
	 * @return string HTML, or an empty string when none can be resolved.
	 */
	public static function theme_html( string $stylesheet ): string {
		return self::html_for( self::theme_context( $stylesheet ) );
	}

	/**
	 * Changelog HTML from a readme.txt body.
	 *
	 * Headings use `= 1.2.3 =` and list items use `*` or `-`.
	 *
	 * @param string $contents readme.txt contents.
	 * @return string
	 */
	public static function html_from_readme( string $contents ): string {
		$contents = str_replace( array( "\r\n", "\r" ), "\n", $contents );
		if ( ! preg_match( '/^==\s*Changelog\s*==\s*$/mi', $contents, $heading, PREG_OFFSET_CAPTURE ) ) {
			return '';
		}

		$start = (int) $heading[0][1] + strlen( (string) $heading[0][0] );
		$body  = substr( $contents, $start );

		if ( preg_match( '/^==\s+\S.*$/m', $body, $next, PREG_OFFSET_CAPTURE ) ) {
			$body = substr( $body, 0, (int) $next[0][1] );
		}

		return self::html_from_readme_body( trim( $body ) );
	}

	/**
	 * Whether a resolved context has a changelog source that does not need the network.
	 *
	 * @param array{directory: bool, slug: string, stored: string, package_dir: string, kind: string} $context Asset context.
	 * @return bool
	 */
	private static function is_available( array $context ): bool {
		if ( $context['directory'] || '' !== $context['stored'] ) {
			return true;
		}

		return '' !== self::local_html( $context['package_dir'] );
	}

	/**
	 * First non-empty changelog, then sanitized for the modal.
	 *
	 * @param array{directory: bool, slug: string, stored: string, package_dir: string, kind: string} $context Asset context.
	 * @return string
	 */
	private static function html_for( array $context ): string {
		$html = self::remote_html( $context );
		if ( '' === $html ) {
			$html = $context['stored'];
		}
		if ( '' === $html ) {
			$html = self::local_html( $context['package_dir'] );
		}
		if ( '' === $html ) {
			return '';
		}

		return self::sanitize_html( $html );
	}

	/**
	 * Plugin identity taken from the update transient and the install path.
	 *
	 * @param string $file Plugin basename.
	 * @return array{directory: bool, slug: string, stored: string, package_dir: string, kind: string}
	 */
	private static function plugin_context( string $file ): array {
		$extra     = self::update_extra( 'update_plugins', $file );
		$id        = isset( $extra['id'] ) && is_string( $extra['id'] ) ? $extra['id'] : '';
		$directory = self::is_wordpress_org_id( $id );
		$slug      = $directory ? self::slug_from_directory_id( $id ) : '';
		if ( '' === $slug ) {
			$slug = self::safe_slug( $extra['slug'] ?? '' );
		}

		return array(
			'kind'        => 'plugin',
			'directory'   => $directory,
			'slug'        => $slug,
			'stored'      => self::changelog_from_sections( $extra['sections'] ?? array() ),
			'package_dir' => Version_Controlled::plugin_directory( $file ),
		);
	}

	/**
	 * Theme identity taken from the update transient and the install path.
	 *
	 * @param string $stylesheet Theme stylesheet.
	 * @return array{directory: bool, slug: string, stored: string, package_dir: string, kind: string}
	 */
	private static function theme_context( string $stylesheet ): array {
		$extra     = self::update_extra( 'update_themes', $stylesheet );
		$id        = isset( $extra['id'] ) && is_string( $extra['id'] ) ? $extra['id'] : '';
		$url       = isset( $extra['url'] ) && is_string( $extra['url'] ) ? $extra['url'] : '';
		$directory = self::is_wordpress_org_id( $id ) || self::is_wordpress_org_theme_url( $url );
		$slug      = '';
		if ( $directory ) {
			$slug = self::safe_slug( $stylesheet );
			if ( '' === $slug ) {
				$slug = self::slug_from_directory_id( $id );
			}
		}
		if ( '' === $slug ) {
			$slug = self::safe_slug( $extra['slug'] ?? '' );
		}

		return array(
			'kind'        => 'theme',
			'directory'   => $directory,
			'slug'        => $slug,
			'stored'      => self::changelog_from_sections( $extra['sections'] ?? array() ),
			'package_dir' => Version_Controlled::theme_directory( $stylesheet ),
		);
	}

	/**
	 * Update-transient row for one plugin file or theme stylesheet.
	 *
	 * Response wins over no_update. Both are read unfiltered so a blocked offer can still supply its changelog.
	 *
	 * @param string $transient_name update_plugins or update_themes.
	 * @param string $item           Plugin basename or theme stylesheet.
	 * @return array<string, mixed>
	 */
	private static function update_extra( string $transient_name, string $item ): array {
		$updates = Hide_Blocked_Updates::unfiltered(
			static function () use ( $transient_name ) {
				return get_site_transient( $transient_name );
			}
		);

		$extra = null;
		if ( is_object( $updates ) ) {
			if ( isset( $updates->response[ $item ] ) ) {
				$extra = $updates->response[ $item ];
			} elseif ( isset( $updates->no_update[ $item ] ) ) {
				$extra = $updates->no_update[ $item ];
			}
		}

		if ( is_object( $extra ) ) {
			$extra = (array) $extra;
		}

		return is_array( $extra ) ? $extra : array();
	}

	/**
	 * Changelog from the info API.
	 *
	 * Directory items use that payload. A third-party item ignores a WordPress.org payload so a slug collision cannot show another project.
	 *
	 * @param array{directory: bool, slug: string, stored: string, package_dir: string, kind: string} $context Asset context.
	 * @return string
	 */
	private static function remote_html( array $context ): string {
		if ( '' === $context['slug'] ) {
			return '';
		}

		$api = self::request_api( $context['kind'], $context['slug'] );
		if ( is_wp_error( $api ) || ! is_object( $api ) ) {
			return '';
		}

		if ( ! $context['directory'] && self::is_wordpress_org_result( $api ) ) {
			return '';
		}

		$sections = isset( $api->sections ) ? $api->sections : array();

		return self::changelog_from_sections( $sections );
	}

	/**
	 * Plugin or theme information request with sections only.
	 *
	 * @param string $kind plugin or theme.
	 * @param string $slug Directory or updater slug.
	 * @return mixed
	 */
	private static function request_api( string $kind, string $slug ) {
		$args = array(
			'slug'   => $slug,
			'fields' => self::section_fields(),
		);

		if ( 'theme' === $kind ) {
			if ( ! function_exists( 'themes_api' ) ) {
				require_once ABSPATH . 'wp-admin/includes/theme.php';
			}

			return themes_api( 'theme_information', $args );
		}

		if ( ! function_exists( 'plugins_api' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		}

		return plugins_api( 'plugin_information', $args );
	}

	/**
	 * Fields requested from the plugin and theme information APIs.
	 *
	 * @return array<string, bool>
	 */
	private static function section_fields(): array {
		return array(
			'sections'          => true,
			'versions'          => false,
			'description'       => false,
			'short_description' => false,
			'rating'            => false,
			'ratings'           => false,
			'downloaded'        => false,
			'downloadlink'      => false,
			'last_updated'      => false,
			'added'             => false,
			'tags'              => false,
			'compatibility'     => false,
			'homepage'          => false,
			'donate_link'       => false,
			'reviews'           => false,
			'banners'           => false,
			'icons'             => false,
			'active_installs'   => false,
			'contributors'      => false,
			'requires'          => false,
			'requires_php'      => false,
			'tested'            => false,
		);
	}

	/**
	 * Changelog HTML stored on an update row.
	 *
	 * @param mixed $sections Sections array or object.
	 * @return string
	 */
	private static function changelog_from_sections( $sections ): string {
		if ( is_object( $sections ) ) {
			$sections = (array) $sections;
		}
		if ( ! is_array( $sections ) || ! isset( $sections['changelog'] ) || ! is_string( $sections['changelog'] ) ) {
			return '';
		}

		return trim( $sections['changelog'] );
	}

	/**
	 * Installed readme.txt changelog, then changelog.txt.
	 *
	 * @param string $dir Package directory.
	 * @return string
	 */
	private static function local_html( string $dir ): string {
		if ( '' === $dir || ! is_dir( $dir ) ) {
			return '';
		}

		$readme = self::read_text( $dir . '/readme.txt' );
		if ( '' !== $readme ) {
			$html = self::html_from_readme( $readme );
			if ( '' !== $html ) {
				return $html;
			}
		}

		return self::html_from_plain( self::read_text( $dir . '/changelog.txt' ) );
	}

	/**
	 * Read a local text file, capped so a huge package file cannot be pulled into the list.
	 *
	 * @param string $path Absolute path.
	 * @return string
	 */
	private static function read_text( string $path ): string {
		if ( ! is_readable( $path ) ) {
			return '';
		}

		$size = filesize( $path );
		if ( false === $size || $size > self::MAX_LOCAL_BYTES ) {
			return '';
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local readme or changelog shipped with the installed package.
		$raw = file_get_contents( $path );

		return is_string( $raw ) ? $raw : '';
	}

	/**
	 * Turn one readme Changelog section into headings and lists.
	 *
	 * @param string $section Section body.
	 * @return string
	 */
	private static function html_from_readme_body( string $section ): string {
		if ( '' === $section ) {
			return '';
		}

		$lines = preg_split( "/\n/", $section );
		if ( ! is_array( $lines ) ) {
			return '';
		}

		$html    = '';
		$in_list = false;
		foreach ( $lines as $line ) {
			$line = trim( (string) $line );
			if ( preg_match( '/^=\s*(.+?)\s*=$/', $line, $heading ) ) {
				if ( $in_list ) {
					$html   .= '</ul>';
					$in_list = false;
				}
				$html .= '<h4>' . esc_html( $heading[1] ) . '</h4>';
				continue;
			}

			if ( preg_match( '/^[\*\-]\s+(.+)$/', $line, $item ) ) {
				if ( ! $in_list ) {
					$html   .= '<ul>';
					$in_list = true;
				}
				$html .= '<li>' . esc_html( $item[1] ) . '</li>';
			}
		}

		if ( $in_list ) {
			$html .= '</ul>';
		}

		return $html;
	}

	/**
	 * Escape a plain changelog.txt into paragraphs.
	 *
	 * @param string $contents File contents.
	 * @return string
	 */
	private static function html_from_plain( string $contents ): string {
		$contents = trim( str_replace( array( "\r\n", "\r" ), "\n", $contents ) );
		if ( '' === $contents ) {
			return '';
		}

		$blocks = preg_split( "/\n\s*\n/", $contents );
		if ( ! is_array( $blocks ) ) {
			return '';
		}

		$html = '';
		foreach ( $blocks as $block ) {
			$block = trim( (string) $block );
			if ( '' === $block ) {
				continue;
			}

			$html .= '<p>' . nl2br( esc_html( $block ), false ) . '</p>';
		}

		return $html;
	}

	/**
	 * Keep post HTML and open links in a new tab.
	 *
	 * @param string $html Changelog HTML.
	 * @return string
	 */
	private static function sanitize_html( string $html ): string {
		$html = trim( wp_kses_post( $html ) );
		if ( '' === $html || ! class_exists( '\WP_HTML_Tag_Processor' ) ) {
			return $html;
		}

		$processor = new \WP_HTML_Tag_Processor( $html );
		while ( $processor->next_tag( array( 'tag_name' => 'a' ) ) ) {
			$processor->set_attribute( 'target', '_blank' );
			$rel  = $processor->get_attribute( 'rel' );
			$rels = array();
			if ( is_string( $rel ) && '' !== trim( $rel ) ) {
				$parts = preg_split( '/\s+/', trim( $rel ) );
				if ( is_array( $parts ) ) {
					$rels = $parts;
				}
			}
			foreach ( array( 'noopener', 'noreferrer' ) as $token ) {
				if ( ! in_array( $token, $rels, true ) ) {
					$rels[] = $token;
				}
			}
			$processor->set_attribute( 'rel', implode( ' ', $rels ) );
		}

		return $processor->get_updated_html();
	}

	/**
	 * Whether an update id points at WordPress.org.
	 *
	 * @param string $directory_id Update id.
	 * @return bool
	 */
	private static function is_wordpress_org_id( string $directory_id ): bool {
		return '' !== $directory_id && (bool) preg_match( '/w\.org/i', $directory_id );
	}

	/**
	 * Whether a theme URL points at the WordPress.org theme directory.
	 *
	 * @param string $url Theme URL.
	 * @return bool
	 */
	private static function is_wordpress_org_theme_url( string $url ): bool {
		return (bool) preg_match( '#WordPress\.org/themes#i', $url );
	}

	/**
	 * Slug embedded in a WordPress.org update id.
	 *
	 * @param string $directory_id Update id, such as w.org/plugins/akismet.
	 * @return string
	 */
	private static function slug_from_directory_id( string $directory_id ): string {
		if ( ! preg_match( '#w\.org/(?:plugins|themes)/([a-z0-9][a-z0-9-]*)#i', $directory_id, $match ) ) {
			return '';
		}

		return strtolower( $match[1] );
	}

	/**
	 * Slug that is safe to send to an information API.
	 *
	 * @param mixed $slug Candidate slug.
	 * @return string
	 */
	private static function safe_slug( $slug ): string {
		if ( ! is_string( $slug ) ) {
			return '';
		}

		$slug = strtolower( trim( $slug ) );
		if ( ! preg_match( '/^[a-z0-9][a-z0-9-]*$/', $slug ) ) {
			return '';
		}

		return $slug;
	}

	/**
	 * Whether an information API payload is a WordPress.org directory result.
	 *
	 * Updaters that set `external` are treated as third-party even when a URL mentions wordpress.org.
	 *
	 * @param object $api Information API result.
	 * @return bool
	 */
	private static function is_wordpress_org_result( object $api ): bool {
		if ( ! empty( $api->external ) ) {
			return false;
		}

		foreach ( array( 'download_link', 'package', 'homepage', 'preview_url' ) as $key ) {
			if ( ! isset( $api->$key ) || ! is_string( $api->$key ) || '' === $api->$key ) {
				continue;
			}

			if ( self::url_is_wordpress_org( $api->$key ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a URL is hosted on WordPress.org.
	 *
	 * @param string $url Candidate URL.
	 * @return bool
	 */
	private static function url_is_wordpress_org( string $url ): bool {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( is_string( $host ) && preg_match( '/(?:^|\.)WordPress\.org$/i', $host ) ) {
			return true;
		}

		return (bool) preg_match( '#WordPress\.org/(?:plugins|themes)/#i', $url );
	}
}
