<?php
/**
 * PHPStan bootstrap for plugin constants.
 *
 * @package updatebee
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/tmp/wordpress/' );
}

if ( ! defined( 'UPDATEBEE_FILE' ) ) {
	define( 'UPDATEBEE_FILE', dirname( __DIR__, 2 ) . '/updatebee.php' );
}

if ( ! defined( 'UPDATEBEE_VERSION' ) ) {
	define( 'UPDATEBEE_VERSION', '1.0.0' );
}

require_once dirname( __DIR__, 2 ) . '/updater/PluginUpdater.php';
require_once dirname( __DIR__, 2 ) . '/updater/FluentLicensing.php';
