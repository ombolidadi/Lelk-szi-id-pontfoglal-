<?php
/**
 * A WordPress adminban csak egy menüpont van, ami az önálló lelkészi felületre visz.
 * A lelkészek (akik nem szerkesztői/adminisztrátorai a honlapnak) nem jutnak be a WordPress adminba.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Csak lelkész (nem szerkesztője/adminisztrátora a honlapnak). */
function lif_is_pastor_only( $user = null ) {
	$u = $user ? $user : wp_get_current_user();
	return $u && $u->exists() && $u->has_cap( 'lif_pastor' ) && ! $u->has_cap( 'edit_posts' );
}

add_action( 'admin_menu', function () {
	add_menu_page( 'Időpontfoglaló', 'Időpontfoglaló', 'lif_pastor', 'lif', '__return_null', 'dashicons-calendar-alt', 3 );
} );

// A menüpont az önálló lelkészi felületre visz.
add_action( 'load-toplevel_page_lif', function () {
	wp_safe_redirect( lif_portal_url() );
	exit;
} );

add_action( 'admin_init', function () {
	if ( wp_doing_ajax() ) {
		return;
	}
	if ( lif_is_pastor_only() ) {
		wp_safe_redirect( lif_portal_url() );
		exit;
	}
} );

add_filter( 'show_admin_bar', function ( $show ) {
	return lif_is_pastor_only() ? false : $show;
} );
