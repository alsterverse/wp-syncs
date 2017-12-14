<?php

/**
 * Plugin Name: Syncs
 * Plugin URI: https://github.com/isotopsweden/wp-syncs
 * Description: Syncs synchronizes posts and taxonomies between sites.
 * Author: Isotop
 * Author URI: https://www.isotop.se
 * Version: 1.0.0
 * Textdomain: wp-syncs
 */

// Load Composer autoload if it exists.
if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
}

/**
 * Boot the plugin.
 */
add_action( 'plugins_loaded', function () {
	\Isotop\Syncs\Syncs::instance();

	// Bootstrap Cargo CLI.
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		WP_CLI::add_command( 'syncs', '\\Isotop\\Syncs\\CLI\\CLI' );
		WP_CLI::add_command( 'syncs sync', '\\Isotop\\Syncs\\CLI\\Commands\\Sync' );
	}

} );
