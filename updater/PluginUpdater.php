<?php
/**
 * FluentCart plugin update checks.
 *
 * @package updatebee
 */

namespace UpdateBee\Updater;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PluginUpdater {

	/**
	 * The caching key for version info.
	 *
	 * @var string
	 */
	private $cache_key;

	private $config = array();

	/**
	 * Initialize the class.
	 * @param array $config Configuration for the updater.
	 */
	public function __construct( $config = array() ) {
		$defaults = array(
			'type'                 => 'plugin', // Default type is 'plugin'.
			'slug'                 => '', // Slug for the plugin.
			'item_id'              => '', // Item ID for the plugin
			'basename'             => '', // Basename for the plugin
			'version'              => '', // Current Version of the plugin
			'api_url'              => '', // API URL for the updater.
			'license_key'          => '', // License key for the plugin. Optional
			'license_key_callback' => '', // Optional callback for license key
			'show_check_update'    => false, // Show check update link in plugin row meta.
		);

		$config = wp_parse_args( $config, $defaults );

		$this->config    = $config;
		$this->cache_key = 'fsl_' . md5( $config['basename'] . '_' . $config['item_id'] ) . '_version_info';

		if ( $config['type'] === 'plugin' ) {
			$this->initPluginUpdaterHooks(); // Initialize the plugin updater hooks.
		}
	}

	/**
	 * Run plugin updater hooks.
	 *
	 * @return void
	 */
	private function initPluginUpdaterHooks() {
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'checkPluginUpdate' ) );
		add_filter( 'plugins_api', array( $this, 'pluginsApiFilter' ), 10, 3 );
		add_action( 'in_plugin_update_message-' . $this->config['basename'], array( $this, 'plugin_update_message' ), 10, 2 );

		if ( $this->config['show_check_update'] ) {

			$getParam = 'fluent_sl_check_update_' . $this->config['slug'];

			add_filter(
				'plugin_row_meta',
				function ( $links, $file ) use ( $getParam ) {
					if ( $this->config['basename'] !== $file ) {
						return $links;
					}

					// The link changes state (it clears the cached update data), so it carries a nonce.
					$checkUpdateUrl = esc_url( wp_nonce_url( admin_url( 'plugins.php?' . $getParam . '=' . time() ), $getParam ) );
					$row_meta       = array(
						'check_update' => '<a style="color: #583fad;font-weight: 600;" href="' . $checkUpdateUrl . '" aria-label="' . esc_attr__( 'Check Update', 'updatebee' ) . '">' . esc_html__( 'Check Update', 'updatebee' ) . '</a>',
					);

					return array_merge( $links, $row_meta );
				},
				10,
				2
			);

			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Presence check only; the nonce is verified inside the admin_init callback.
			if ( isset( $_GET[ $getParam ] ) ) {
				add_action(
					'admin_init',
					function () use ( $getParam ) {
						// Require both the capability and a valid nonce before touching the update data.
						if ( current_user_can( 'update_plugins' ) && isset( $_GET['_wpnonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), $getParam ) ) {
							delete_transient( $this->cache_key );

							add_filter(
								'fluent_sl/api_request_query_params',
								function ( $params ) {
									$params['disable_cache'] = 'yes';
									return $params;
								}
							);

							// Remove our filter on the site transient
							remove_filter( 'pre_set_site_transient_update_plugins', array( $this, 'checkPluginUpdate' ) );

							$update_cache = get_site_transient( 'update_plugins' );
							if ( $update_cache && ! empty( $update_cache->response[ $this->config['basename'] ] ) ) {
								unset( $update_cache->response[ $this->config['basename'] ] );
							}

							$update_cache = $this->checkPluginUpdate( $update_cache );
							set_site_transient( 'update_plugins', $update_cache );

							// Restore our filter
							add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'checkPluginUpdate' ) );

							wp_safe_redirect( admin_url( 'plugins.php?s=' . $this->config['slug'] . '&plugin_status=all' ) );
							exit();
						}
					}
				);
			}
		}
	}

	/**
	 * Check for Update for this specific plugin.
	 *
	 * @param Object $transient_data Transient data for update.
	 */
	public function checkPluginUpdate( $transient_data ) {

		global $pagenow;

		if ( ! is_object( $transient_data ) ) {
			$transient_data = new \stdClass();
		}

		if ( 'plugins.php' === $pagenow && is_multisite() ) {
			// Skip the license API on the network plugins screen. Keep a response that is already stored.
			return $this->keep_existing_plugin_update( $transient_data );
		}

		if ( ! empty( $transient_data->response ) && ! empty( $transient_data->response[ $this->config['basename'] ] ) ) {
			$transient_data->response[ $this->config['basename'] ] = $this->ensure_update_fields( $transient_data->response[ $this->config['basename'] ] );
			return $transient_data;
		}

		$version_info = $this->getVersionInfo();

		if ( false !== $version_info && is_object( $version_info ) && isset( $version_info->new_version ) ) {
			$version_info = $this->ensure_update_fields( clone $version_info );
			unset( $version_info->sections );
			// If new version available then set to `response`.
			if ( version_compare( $this->config['version'], $version_info->new_version, '<' ) ) {
				$transient_data->response[ $this->config['basename'] ] = $version_info;
			} else {
				// If new version is not available then set to `no_update`.
				$transient_data->no_update[ $this->config['basename'] ] = $version_info;
			}

			$transient_data->last_checked                         = time();
			$transient_data->checked[ $this->config['basename'] ] = $this->config['version'];
		}

		return $transient_data;
	}

	/**
	 * Filter the plugins API response for this specific plugin.
	 *
	 * @param mixed $data Plugin data.
	 * @param string $action The action type.
	 * @param object $args Arguments.
	 *
	 * @return mixed
	 */
	public function pluginsApiFilter( $data, $action = '', $args = null ) {
		// must be requesting plugin info.
		if ( 'plugin_information' !== $action || ! $args ) {
			return $data;
		}

		$slug = $this->config['slug'];

		// check f this our plugin or not
		if ( ! isset( $args->slug ) || ( $args->slug !== $slug ) ) {
			return $data;
		}

		// Remote sections win. readme.txt supplies a changelog when the payload has none.
		$data = $this->getVersionInfo();

		if ( ! is_object( $data ) ) {
			$data              = new \stdClass();
			$data->new_version = $this->config['version'];
			$data->name        = 'UpdateBee';
		}

		$data = $this->ensure_update_fields( clone $data );
		if ( empty( $data->name ) ) {
			$data->name = 'UpdateBee';
		}
		if ( empty( $data->version ) && ! empty( $data->new_version ) ) {
			$data->version = $data->new_version;
		}

		$sections = array();
		if ( ! empty( $data->sections ) ) {
			$sections = (array) $data->sections;
		}
		if ( empty( $sections['changelog'] ) ) {
			$changelog = $this->local_changelog_html();
			if ( '' !== $changelog ) {
				$sections['changelog'] = $changelog;
			}
		}
		$data->sections = $sections;
		$data->external = true;

		return $data;
	}

	/**
	 * Append the upgrade notice under the core update sentence.
	 *
	 * Core prints "There is a new version…" itself. The plugins screen does not print upgrade_notice.
	 *
	 * @param array  $plugin_data Plugin row data. Unused.
	 * @param object $response    Update transient entry.
	 * @return void
	 */
	public function plugin_update_message( $plugin_data, $response ) {
		unset( $plugin_data );

		$notice = '';
		if ( is_object( $response ) && isset( $response->upgrade_notice ) && is_string( $response->upgrade_notice ) ) {
			$notice = $this->trimUpgradeNotice( $response->upgrade_notice );
		}
		if ( '' === $notice && is_object( $response ) && isset( $response->new_version ) ) {
			$notice = $this->local_upgrade_notice( (string) $response->new_version );
		}
		if ( '' === $notice ) {
			return;
		}

		echo '<br />' . esc_html( $notice );
	}

	/**
	 * Keep a stored UpdateBee offer when the network plugins screen skips the remote check.
	 *
	 * @param object $transient_data Update transient being saved.
	 * @return object
	 */
	private function keep_existing_plugin_update( $transient_data ) {
		$basename = $this->config['basename'];

		if ( ! empty( $transient_data->response[ $basename ] ) ) {
			$transient_data->response[ $basename ] = $this->ensure_update_fields( $transient_data->response[ $basename ] );
			return $transient_data;
		}

		if ( ! empty( $transient_data->no_update[ $basename ] ) ) {
			return $transient_data;
		}

		$stored = get_site_transient( 'update_plugins' );
		if ( ! is_object( $stored ) || empty( $stored->response[ $basename ] ) ) {
			return $transient_data;
		}

		if ( ! isset( $transient_data->response ) || ! is_array( $transient_data->response ) ) {
			$transient_data->response = array();
		}

		$transient_data->response[ $basename ] = $this->ensure_update_fields( $stored->response[ $basename ] );

		return $transient_data;
	}

	/**
	 * Fill the update fields WordPress uses for the plugins-screen row.
	 *
	 * @param mixed $info Version payload.
	 * @return mixed
	 */
	private function ensure_update_fields( $info ) {
		if ( is_array( $info ) ) {
			$info = (object) $info;
		}
		if ( ! is_object( $info ) ) {
			return $info;
		}

		if ( empty( $info->slug ) ) {
			$info->slug = $this->config['slug'];
		}
		if ( empty( $info->plugin ) ) {
			$info->plugin = $this->config['basename'];
		}
		if ( empty( $info->url ) ) {
			$info->url = ! empty( $this->config['store_url'] ) ? $this->config['store_url'] : $this->config['api_url'];
		}
		if ( ! isset( $info->package ) || ! is_string( $info->package ) ) {
			$info->package = '';
		}
		if ( ( empty( $info->upgrade_notice ) || ! is_string( $info->upgrade_notice ) ) && ! empty( $info->new_version ) ) {
			$notice = $this->local_upgrade_notice( (string) $info->new_version );
			if ( '' !== $notice ) {
				$info->upgrade_notice = $notice;
			}
		} elseif ( isset( $info->upgrade_notice ) && is_string( $info->upgrade_notice ) ) {
			$info->upgrade_notice = $this->trimUpgradeNotice( $info->upgrade_notice );
		}

		return $info;
	}

	/**
	 * Upgrade notice for one version from readme.txt.
	 *
	 * @param string $version Offered version.
	 * @return string
	 */
	private function local_upgrade_notice( $version ) {
		$section = $this->readmeSection( 'Upgrade Notice' );
		if ( '' === $section ) {
			return '';
		}

		$notices = array();
		$current = '';
		foreach ( preg_split( "/\n/", $section ) as $line ) {
			$line = trim( $line );
			if ( preg_match( '/^=\s*(.+?)\s*=$/', $line, $heading ) ) {
				$current = trim( $heading[1] );
				if ( ! isset( $notices[ $current ] ) ) {
					$notices[ $current ] = '';
				}
				continue;
			}
			if ( '' === $current || '' === $line ) {
				continue;
			}
			$notices[ $current ] .= ( '' === $notices[ $current ] ? '' : ' ' ) . $line;
		}

		foreach ( $notices as $notice_version => $text ) {
			if ( version_compare( (string) $notice_version, $version, '==' ) ) {
				return $this->trimUpgradeNotice( $text );
			}
		}

		return '';
	}

	/**
	 * Changelog HTML from readme.txt for the details modal.
	 *
	 * @return string
	 */
	private function local_changelog_html() {
		$section = $this->readmeSection( 'Changelog' );
		if ( '' === $section ) {
			return '';
		}

		$html    = '';
		$in_list = false;
		foreach ( preg_split( "/\n/", $section ) as $line ) {
			$line = trim( $line );
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
	 * Body of one readme.txt section.
	 *
	 * @param string $title Section title.
	 * @return string
	 */
	private function readmeSection( $title ) {
		$path = dirname( __DIR__ ) . '/readme.txt';
		if ( ! is_readable( $path ) ) {
			return '';
		}

		$raw = file_get_contents( $path );
		if ( ! is_string( $raw ) || '' === $raw ) {
			return '';
		}

		$contents = str_replace( array( "\r\n", "\r" ), "\n", $raw );
		$pattern  = '/^==\s*' . preg_quote( $title, '/' ) . '\s*==\s*\n(.*?)(?:\n==\s|\z)/s';
		if ( ! preg_match( $pattern, $contents, $match ) ) {
			return '';
		}

		return trim( $match[1] );
	}

	/**
	 * Plain upgrade notice, capped the way WordPress.org caps it.
	 *
	 * @param string $notice Notice text.
	 * @return string
	 */
	private function trimUpgradeNotice( $notice ) {
		$notice = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $notice ) ) );
		if ( strlen( $notice ) > 300 ) {
			$notice = rtrim( substr( $notice, 0, 300 ) ) . '…';
		}

		return $notice;
	}

	/**
	 * Get version info from database
	 *
	 * @return mixed
	 */
	private function getCachedVersionInfo() {
		global $pagenow;

		// If updater page then force fetch.
		if ( 'update-core.php' === $pagenow || ( $pagenow === 'plugin-install.php' && ! empty( $_GET['plugin'] ) ) ) {
			return false;
		}

		return get_transient( $this->cache_key );
	}

	/**
	 * Set version info to transient
	 *
	 * @param Object $value Version info to store in the transient.
	 * @return void
	 */
	private function setCachedVersionInfo( $value ) {
		if ( ! $value ) {
			return;
		}

		set_transient( $this->cache_key, $value, 3 * HOUR_IN_SECONDS ); // cache for 3 hours.
	}

	/**
	 * Get Plugin Version Info
	 */
	private function getVersionInfo() {
		$versionInfo = $this->getCachedVersionInfo();

		if ( false === $versionInfo ) {
			$versionInfo = $this->getRemoteVersionInfo();
			$this->setCachedVersionInfo( $versionInfo );
		}

		return $versionInfo;
	}

	private function getRemoteVersionInfo() {
		$url     = $this->config['api_url'];
		$fullUrl = add_query_arg(
			apply_filters(
				'fluent_sl/api_request_query_params',
				array(
					'fluent-cart' => 'get_license_version',
				),
				$this->config
			),
			$url
		);

		$payload = array(
			'item_id'          => $this->config['item_id'],
			'current_version'  => $this->config['version'],
			'site_url'         => home_url(),
			'platform_version' => get_bloginfo( 'version' ),
			'server_version'   => PHP_VERSION,
			'license_key'      => $this->config['license_key'],
		);

		if ( empty( $payload['license_key'] ) && ! empty( $this->config['license_key_callback'] ) ) {
			$payload['license_key'] = call_user_func( $this->config['license_key_callback'] );
		}

		$payload = apply_filters( 'fluent_sl/updater_payload_' . $this->config['slug'], $payload, $this->config );

		// send the post request to the API.
		$response = wp_remote_post(
			$fullUrl,
			array(
				'timeout' => 15,
				'body'    => $payload,
			)
		);

		if ( is_wp_error( $response ) ) {
			return false; // Return false if there is an error.
		}

		if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return false; // Return false if the response code is not 200.
		}

		$responseBody = wp_remote_retrieve_body( $response );

		if ( empty( $responseBody ) ) {
			return false; // Return false if the response body is empty.
		}

		$versionInfo = json_decode( $responseBody );
		if ( null === $versionInfo || ! is_object( $versionInfo ) ) {
			return false; // Return false if the response body is not a valid JSON object.
		}

		// Ensure the version info has the required properties.
		if ( ! isset( $versionInfo->new_version ) ) {
			return false; // Return false if the required properties are not set.
		}

		$versionInfo->plugin = $this->config['basename'];
		$versionInfo->slug   = $this->config['slug'];

		if ( ! empty( $versionInfo->sections ) ) {
			$versionInfo->sections = (array) $versionInfo->sections; // Ensure sections is an array.
		}

		if ( ! isset( $versionInfo->banners ) ) {
			$versionInfo->banners = array(); // Ensure banners is set.
		} else {
			$versionInfo->banners = (array) $versionInfo->banners; // Ensure banners is an array.
		}

		if ( ! isset( $versionInfo->icons ) ) {
			$versionInfo->icons = array(); // Ensure icons is set.
		} else {
			$versionInfo->icons = (array) $versionInfo->icons; // Ensure icons is an array.
		}

		return $versionInfo; // Return the version info object.
	}
}
