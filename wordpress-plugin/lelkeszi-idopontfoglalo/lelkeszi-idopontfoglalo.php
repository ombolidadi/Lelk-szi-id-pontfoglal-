<?php
/**
 * Plugin Name:       Lelkészi Időpontfoglaló
 * Description:       Időpontfoglalás több lelkésznek: csoportonként megosztható foglalási linkek, meghívásos lelkészi regisztráció, opcionális kétfaktoros hitelesítés, foglalói fiók naptár-feliratkozással, lelkészenkénti Google Naptár-szinkron.
 * Version:           2.0.1
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Lelkészi Időpontfoglaló
 * License:           MIT
 * Text Domain:       lelkeszi-idopontfoglalo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'LIF_VERSION', '2.0.1' );
define( 'LIF_FILE', __FILE__ );
define( 'LIF_DIR', plugin_dir_path( __FILE__ ) );
define( 'LIF_URL', plugin_dir_url( __FILE__ ) );
define( 'LIF_DB_VERSION', 1 );

require_once LIF_DIR . 'includes/helpers.php';
require_once LIF_DIR . 'includes/install.php';
require_once LIF_DIR . 'includes/totp.php';
require_once LIF_DIR . 'includes/twofactor.php';
require_once LIF_DIR . 'includes/slots.php';
require_once LIF_DIR . 'includes/google.php';
require_once LIF_DIR . 'includes/bookings.php';
require_once LIF_DIR . 'includes/rest.php';
require_once LIF_DIR . 'includes/frontend.php';
require_once LIF_DIR . 'includes/portal.php';
require_once LIF_DIR . 'includes/admin.php';
require_once LIF_DIR . 'includes/demo.php';

register_activation_hook( __FILE__, 'lif_activate' );
register_deactivation_hook( __FILE__, 'lif_deactivate' );
add_action( 'plugins_loaded', 'lif_maybe_upgrade' );
