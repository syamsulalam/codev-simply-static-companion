<?php
/**
 * Plugin Name: Codev Simply Static Companion
 * Plugin URI:  https://codev.id/
 * Description: Sovereign high-performance GitHub & Cloudflare Pages deployment companion for Simply Static Free. Native Git transport, Cloudflare Pages edge hardening, dynamic asset sniffing, and selective single-post incremental exports.
 * Version:     1.0.0
 * Author:      Syamsul Alam & Codev Team
 * Author URI:  https://syamsulalam.net/
 * License:     GPL-2.0-or-later
 * Text Domain: codev-simply-static-companion
 * Requires at least: 6.0
 * Requires PHP: 8.0
 */

namespace Codev\Simply_Static_Companion;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CODEV_SS_COMPANION_VERSION', '1.0.0' );
define( 'CODEV_SS_COMPANION_FILE', __FILE__ );
define( 'CODEV_SS_COMPANION_PATH', plugin_dir_path( __FILE__ ) );
define( 'CODEV_SS_COMPANION_URL', plugin_dir_url( __FILE__ ) );

/**
 * PSR-4 compliant autoloader for Codev\Simply_Static_Companion classes.
 */
spl_autoload_register( function ( $class ) {
	$prefix = 'Codev\\Simply_Static_Companion\\';
	$base_dir = CODEV_SS_COMPANION_PATH . 'src/';

	$len = strlen( $prefix );
	if ( strncmp( $prefix, $class, $len ) !== 0 ) {
		return;
	}

	$relative_class = substr( $class, $len );
	$parts = explode( '\\', $relative_class );
	$file_name = 'class-' . strtolower( str_replace( '_', '-', array_pop( $parts ) ) ) . '.php';
	$subdir = ! empty( $parts ) ? implode( '/', $parts ) . '/' : '';

	$file = $base_dir . $subdir . $file_name;

	if ( file_exists( $file ) ) {
		require_once $file;
	}
} );

/**
 * Bootstrap the companion plugin after all plugins have loaded.
 */
add_action( 'plugins_loaded', function () {
	// Require Simply Static Free to be active
	if ( ! class_exists( '\\Simply_Static\\Plugin' ) && ! class_exists( '\\Simply_Static\\Task' ) ) {
		add_action( 'admin_notices', function () {
			if ( current_user_can( 'activate_plugins' ) ) {
				echo '<div class="notice notice-warning"><p><strong>Codev Simply Static Companion</strong> requires Simply Static Free to be installed and active.</p></div>';
			}
		} );
		return;
	}

	// Initialize the singleton plugin instance
	Plugin::instance();
} );
