<?php
/**
 * Közös segédfüggvények: beállítások, titkosítás, URL-ek, csoportok, lelkészek.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const LIF_KINDS = array(
	'gyulekezet'   => 'Gyülekezet',
	'munkacsoport' => 'Munkacsoport',
	'egyeb'        => 'Egyéb csoport',
);
function lif_kind_label( $kind ) {
	return array_key_exists( (string) $kind, LIF_KINDS ) ? LIF_KINDS[ $kind ] : 'Csoport';
}

const LIF_WEEKDAYS = array( 1 => 'Hétfő', 2 => 'Kedd', 3 => 'Szerda', 4 => 'Csütörtök', 5 => 'Péntek', 6 => 'Szombat', 7 => 'Vasárnap' );
const LIF_RESERVED = array( 'lelkeszi', 'fiok', 'foglalas', 'naptar', 'meghivo', 'ellenorzes', 'google-visszahivas', 'adatkezeles' );

function lif_default_settings() {
	return array(
		'org_name'             => get_bloginfo( 'name' ),
		'min_notice_hours'     => 12,
		'max_days_ahead'       => 60,
		'slot_step_min'        => 30,
		'invite_client'        => 1,
		'google_client_id'     => '',
		'google_client_secret' => '',
	);
}

function lif_settings() {
	$s = get_option( 'lif_settings', array() );
	return array_merge( lif_default_settings(), is_array( $s ) ? $s : array() );
}

function lif_setting( $key ) {
	$s = lif_settings();
	return isset( $s[ $key ] ) ? $s[ $key ] : null;
}

/** A WordPress beállított időzónája (Beállítások → Általános). */
function lif_tz() {
	return wp_timezone();
}

function lif_now() {
	return new DateTimeImmutable( 'now', lif_tz() );
}

function lif_iso( $ts ) {
	return gmdate( 'Y-m-d\TH:i:s\Z', (int) $ts );
}

/** Magyar dátumformázás a WordPress nyelvétől függetlenül (F, M, l, D betűk magyar névvel). */
function lif_wdate( $format, $ts ) {
	static $months = array( 1 => 'január', 'február', 'március', 'április', 'május', 'június', 'július', 'augusztus', 'szeptember', 'október', 'november', 'december' );
	static $mshort = array( 1 => 'jan.', 'febr.', 'márc.', 'ápr.', 'máj.', 'jún.', 'júl.', 'aug.', 'szept.', 'okt.', 'nov.', 'dec.' );
	static $days   = array( 1 => 'hétfő', 'kedd', 'szerda', 'csütörtök', 'péntek', 'szombat', 'vasárnap' );
	static $dshort = array( 1 => 'H', 'K', 'Sze', 'Cs', 'P', 'Szo', 'V' );
	$dt  = ( new DateTimeImmutable( '@' . (int) $ts ) )->setTimezone( lif_tz() );
	$out = '';
	foreach ( str_split( $format ) as $ch ) {
		switch ( $ch ) {
			case 'F': $out .= $months[ (int) $dt->format( 'n' ) ]; break;
			case 'M': $out .= $mshort[ (int) $dt->format( 'n' ) ]; break;
			case 'l': $out .= $days[ (int) $dt->format( 'N' ) ]; break;
			case 'D': $out .= $dshort[ (int) $dt->format( 'N' ) ]; break;
			default:  $out .= ctype_alpha( $ch ) ? $dt->format( $ch ) : $ch;
		}
	}
	return $out;
}

function lif_format( $iso, $format = 'Y. F j. H:i' ) {
	return lif_wdate( $format, strtotime( $iso ) );
}

function lif_format_range( $start, $end ) {
	return lif_wdate( 'Y. F j. (l) H:i', strtotime( $start ) ) . '–' . lif_wdate( 'H:i', strtotime( $end ) );
}

function lif_slugify( $s ) {
	$s = remove_accents( (string) $s );
	$s = strtolower( preg_replace( '/[^A-Za-z0-9]+/', '-', $s ) );
	return substr( trim( $s, '-' ), 0, 60 );
}

function lif_random( $bytes = 24 ) {
	return rtrim( strtr( base64_encode( random_bytes( $bytes ) ), '+/', '-_' ), '=' );
}

function lif_random_suffix() {
	return substr( preg_replace( '/[^a-z0-9]/', '', strtolower( lif_random( 8 ) ) ), 0, 6 );
}

/* ---------- Titkosítás (Google tokenekhez, 2FA-titkokhoz) ---------- */

function lif_crypto_key() {
	return hash( 'sha256', wp_salt( 'auth' ) . 'lif', true );
}

function lif_encrypt( $plain ) {
	if ( null === $plain || '' === $plain ) {
		return null;
	}
	$iv  = random_bytes( 12 );
	$tag = '';
	$enc = openssl_encrypt( (string) $plain, 'aes-256-gcm', lif_crypto_key(), OPENSSL_RAW_DATA, $iv, $tag );
	return 'v1:' . base64_encode( $iv ) . ':' . base64_encode( $tag ) . ':' . base64_encode( $enc );
}

function lif_decrypt( $payload ) {
	if ( ! $payload ) {
		return null;
	}
	$parts = explode( ':', (string) $payload );
	if ( 4 !== count( $parts ) || 'v1' !== $parts[0] ) {
		return null;
	}
	$out = openssl_decrypt( base64_decode( $parts[3] ), 'aes-256-gcm', lif_crypto_key(), OPENSSL_RAW_DATA, base64_decode( $parts[1] ), base64_decode( $parts[2] ) );
	return false === $out ? null : $out;
}

/* ---------- URL-ek ---------- */

/** Nyilvános URL a bővítmény oldalaihoz (szép permalinkekkel és nélkülük is működik). */
function lif_url( $path = '' ) {
	$path = '/' . ltrim( $path, '/' );
	if ( get_option( 'permalink_structure' ) ) {
		return home_url( '/idopont' . ( '/' === $path ? '/' : $path ) );
	}
	return add_query_arg( 'lif_path', rawurlencode( $path ), home_url( '/' ) );
}

function lif_group_url( $group ) {
	return lif_url( '/' . $group->slug );
}

/* ---------- Csoportok és lelkészek ---------- */

function lif_group_by_slug( $slug ) {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . lif_t( 'groups' ) . ' WHERE slug = %s AND active = 1', $slug ) );
}

function lif_group_member_ids( $group_id ) {
	global $wpdb;
	return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT user_id FROM ' . lif_t( 'group_members' ) . ' WHERE group_id = %d', $group_id ) ) );
}

function lif_is_group_member( $group_id, $user_id ) {
	return in_array( (int) $user_id, lif_group_member_ids( $group_id ), true );
}

function lif_unique_group_slug( $base, $exclude_id = 0 ) {
	global $wpdb;
	$base = $base ? $base : 'csoport';
	if ( in_array( $base, LIF_RESERVED, true ) ) {
		$base .= '-csoport';
	}
	$slug = $base;
	$i    = 2;
	while ( $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . lif_t( 'groups' ) . ' WHERE slug = %s AND id != %d', $slug, $exclude_id ) ) ) {
		$slug = $base . '-' . $i++;
	}
	return $slug;
}

/** Új csoport linkje véletlen végződést kap, így nem lehet kitalálni. */
function lif_new_group_slug( $name ) {
	return lif_unique_group_slug( lif_slugify( $name ) . '-' . lif_random_suffix() );
}

function lif_is_active_pastor( $user ) {
	$user = $user instanceof WP_User ? $user : get_userdata( (int) $user );
	return $user && $user->has_cap( 'lif_pastor' ) && ! get_user_meta( $user->ID, 'lif_inactive', true );
}

/** Aktív lelkészek listája (WP_User objektumok). */
function lif_pastors( $include_inactive = false ) {
	$users = get_users( array(
		'capability' => 'lif_pastor',
		'orderby'    => 'display_name',
	) );
	if ( $include_inactive ) {
		return $users;
	}
	return array_values( array_filter( $users, 'lif_is_active_pastor' ) );
}

function lif_pastor_meta( $user_id, $key ) {
	return (string) get_user_meta( $user_id, 'lif_' . $key, true );
}

function lif_pastor_ids_for_service( $service_id ) {
	global $wpdb;
	$ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT user_id FROM ' . lif_t( 'pastor_services' ) . ' WHERE service_id = %d', $service_id ) ) );
	return array_values( array_filter( $ids, 'lif_is_active_pastor' ) );
}

/** A csoport azon lelkészei, akik az adott szolgáltatást nyújtják – csak hozzájuk lehet foglalni. */
function lif_group_pastors_for_service( $group, $service_id ) {
	return array_values( array_intersect( lif_pastor_ids_for_service( $service_id ), lif_group_member_ids( $group->id ) ) );
}

function lif_service_by_slug( $slug ) {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . lif_t( 'services' ) . ' WHERE slug = %s AND active = 1', $slug ) );
}

function lif_service( $id ) {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . lif_t( 'services' ) . ' WHERE id = %d', $id ) );
}

function lif_parse_ranges( $text ) {
	$out = array();
	foreach ( preg_split( '/[,;\n]+/', (string) $text ) as $part ) {
		$part = trim( $part );
		if ( '' === $part ) {
			continue;
		}
		if ( ! preg_match( '/^(\d{1,2})(?::(\d{2}))?\s*[-–]\s*(\d{1,2})(?::(\d{2}))?$/u', $part, $m ) ) {
			return new WP_Error( 'range', sprintf( 'Érvénytelen időszak: „%s” (helyes formátum: 09:00-12:00)', $part ) );
		}
		$s = str_pad( $m[1], 2, '0', STR_PAD_LEFT ) . ':' . ( isset( $m[2] ) && '' !== $m[2] ? $m[2] : '00' );
		$e = str_pad( $m[3], 2, '0', STR_PAD_LEFT ) . ':' . ( isset( $m[4] ) && '' !== $m[4] ? $m[4] : '00' );
		if ( $s >= $e || $e > '24:00' ) {
			return new WP_Error( 'range', sprintf( 'Érvénytelen időszak: „%s”', $part ) );
		}
		$out[] = array( $s, $e );
	}
	return $out;
}
