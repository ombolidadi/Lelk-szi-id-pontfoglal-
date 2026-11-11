<?php
/**
 * Bemutató adatok a kipróbáláshoz (pl. WordPress Playgroundban). Magától soha nem fut le,
 * csak ha kifejezetten meghívják: lif_seed_demo().
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function lif_seed_demo() {
	global $wpdb;
	if ( get_option( 'lif_demo_seeded' ) ) {
		return;
	}
	lif_seed_examples();
	$s             = lif_settings();
	$s['org_name'] = 'Bemutató Egyházközség';
	update_option( 'lif_settings', $s );

	$pastors = array(
		array( 'kiss.peter', 'Kiss Péter', 'parókus lelkész', 'Budavári Gyülekezet', true ),
		array( 'nagy.anna', 'Nagy Anna', 'beosztott lelkész', 'Budavári Gyülekezet', false ),
		array( 'toth.gabor', 'Tóth Gábor', 'ifjúsági lelkész', 'Pesti Gyülekezet', false ),
	);
	$ids = array();
	foreach ( $pastors as $i => $p ) {
		$uid = username_exists( $p[0] );
		if ( ! $uid ) {
			$uid = wp_insert_user( array(
				'user_login'   => $p[0],
				'user_email'   => $p[0] . '@demo.hu',
				'user_pass'    => 'demo1234',
				'display_name' => $p[1],
				'first_name'   => $p[1],
				'role'         => 'lif_pastor',
			) );
		}
		if ( $p[4] ) {
			( new WP_User( $uid ) )->add_cap( 'lif_admin' );
		}
		update_user_meta( $uid, 'lif_title', $p[2] );
		update_user_meta( $uid, 'lif_congregation', $p[3] );
		$ids[] = $uid;
		$wpdb->delete( lif_t( 'availability' ), array( 'user_id' => $uid ) );
		for ( $d = 1; $d <= 6; $d++ ) {
			if ( 2 === $i ) {
				$wpdb->insert( lif_t( 'availability' ), array( 'user_id' => $uid, 'weekday' => $d, 'start_time' => '14:00', 'end_time' => '19:00' ) );
			} else {
				$wpdb->insert( lif_t( 'availability' ), array( 'user_id' => $uid, 'weekday' => $d, 'start_time' => '09:00', 'end_time' => '12:00' ) );
				$wpdb->insert( lif_t( 'availability' ), array( 'user_id' => $uid, 'weekday' => $d, 'start_time' => '14:00', 'end_time' => '17:00' ) );
			}
		}
	}
	// A webhely adminisztrátora is lelkész lehet – itt nem soroljuk be, hogy a bemutató átlátható maradjon.
	$services = $wpdb->get_results( 'SELECT id, slug FROM ' . lif_t( 'services' ) );
	foreach ( $services as $sv ) {
		foreach ( $ids as $i => $uid ) {
			if ( 2 === $i && 'keresztelo-elotti-beszelgetes' === $sv->slug ) {
				continue;
			}
			$wpdb->replace( lif_t( 'pastor_services' ), array( 'user_id' => $uid, 'service_id' => $sv->id ) );
		}
	}
	$groups = array(
		array( 'Budavári Gyülekezet', 'budavari-gyulekezet-x7k2q', 'gyulekezet', '', array( $ids[0], $ids[1] ) ),
		array( 'Ifjúsági munkacsoport', 'ifjusagi-munkacsoport-m4p9z', 'munkacsoport', 'Ifjúsági lelkigondozás és beszélgetések.', array( $ids[1], $ids[2] ) ),
	);
	foreach ( $groups as $g ) {
		$wpdb->insert( lif_t( 'groups' ), array( 'name' => $g[0], 'slug' => $g[1], 'kind' => $g[2], 'description' => $g[3], 'active' => 1 ) );
		$gid = (int) $wpdb->insert_id;
		foreach ( $g[4] as $uid ) {
			$wpdb->replace( lif_t( 'group_members' ), array( 'group_id' => $gid, 'user_id' => $uid ) );
		}
	}
	if ( ! username_exists( 'teszt.elek' ) ) {
		$cid = wp_insert_user( array(
			'user_login'   => 'teszt.elek',
			'user_email'   => 'elek@demo.hu',
			'user_pass'    => 'demo1234',
			'display_name' => 'Teszt Elek',
			'role'         => 'lif_client',
		) );
		update_user_meta( $cid, 'lif_calendar_token', lif_random( 24 ) );
	}
	update_option( 'lif_demo_seeded', 1 );
}
