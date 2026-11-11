<?php
/**
 * Opcionális kétfaktoros hitelesítés minden felhasználónak (lelkész és időpontkérő).
 * A belépés (wp-login.php vagy a foglalói belépőűrlap) után, ha a felhasználónál be van kapcsolva,
 * a rendszer kijelentkezteti, és csak a hitelesítő kód megadása után engedi be.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function lif_2fa_enabled( $user_id ) {
	return (bool) get_user_meta( $user_id, 'lif_totp_enabled', true );
}

/** A beállítás alatt álló (még nem megerősített) titok; ha nincs, újat készít. */
function lif_2fa_pending_secret( $user_id, $restart = false ) {
	$secret = $restart ? null : lif_decrypt( get_user_meta( $user_id, 'lif_totp_secret', true ) );
	if ( ! $secret || lif_2fa_enabled( $user_id ) ) {
		$secret = lif_totp_secret();
		update_user_meta( $user_id, 'lif_totp_secret', lif_encrypt( $secret ) );
	}
	return $secret;
}

/** Az első kód után bekapcsol; visszaadja a 8 tartalékkódot (csak ekkor láthatók), vagy false-t. */
function lif_2fa_confirm( $user_id, $code ) {
	if ( lif_2fa_enabled( $user_id ) ) {
		return false;
	}
	$secret = lif_decrypt( get_user_meta( $user_id, 'lif_totp_secret', true ) );
	$step   = $secret ? lif_totp_verify( $secret, $code ) : false;
	if ( ! $step ) {
		return false;
	}
	$codes  = array();
	$hashes = array();
	for ( $i = 0; $i < 8; $i++ ) {
		$raw      = strtoupper( bin2hex( random_bytes( 5 ) ) );
		$codes[]  = substr( $raw, 0, 5 ) . '-' . substr( $raw, 5 );
		$hashes[] = wp_hash_password( substr( $raw, 0, 5 ) . '-' . substr( $raw, 5 ) );
	}
	update_user_meta( $user_id, 'lif_totp_enabled', 1 );
	update_user_meta( $user_id, 'lif_totp_last', $step );
	update_user_meta( $user_id, 'lif_backup_codes', $hashes );
	return $codes;
}

/** Hitelesítő kód vagy tartalékkód ellenőrzése (a tartalékkód felhasználás után érvénytelen). */
function lif_2fa_check( $user_id, $code ) {
	if ( ! lif_2fa_enabled( $user_id ) ) {
		return false;
	}
	$secret = lif_decrypt( get_user_meta( $user_id, 'lif_totp_secret', true ) );
	$step   = $secret ? lif_totp_verify( $secret, $code, (int) get_user_meta( $user_id, 'lif_totp_last', true ) ) : false;
	if ( $step ) {
		update_user_meta( $user_id, 'lif_totp_last', $step );
		return true;
	}
	$norm = preg_replace( '/[^A-F0-9]/', '', strtoupper( (string) $code ) );
	if ( 10 !== strlen( $norm ) ) {
		return false;
	}
	$formatted = substr( $norm, 0, 5 ) . '-' . substr( $norm, 5 );
	$hashes    = (array) get_user_meta( $user_id, 'lif_backup_codes', true );
	foreach ( $hashes as $i => $h ) {
		if ( wp_check_password( $formatted, $h ) ) {
			unset( $hashes[ $i ] );
			update_user_meta( $user_id, 'lif_backup_codes', array_values( $hashes ) );
			return true;
		}
	}
	return false;
}

function lif_2fa_disable( $user_id ) {
	foreach ( array( 'lif_totp_enabled', 'lif_totp_secret', 'lif_totp_last', 'lif_backup_codes' ) as $k ) {
		delete_user_meta( $user_id, $k );
	}
}

function lif_2fa_backup_left( $user_id ) {
	return count( (array) get_user_meta( $user_id, 'lif_backup_codes', true ) );
}

/* ---------- Próbálkozás-korlátozás ---------- */

function lif_rate_blocked( $key, $max = 8 ) {
	return (int) get_transient( 'lif_rl_' . md5( $key ) ) >= $max;
}

function lif_rate_fail( $key ) {
	$k = 'lif_rl_' . md5( $key );
	set_transient( $k, (int) get_transient( $k ) + 1, 15 * MINUTE_IN_SECONDS );
}

function lif_rate_reset( $key ) {
	delete_transient( 'lif_rl_' . md5( $key ) );
}

/* ---------- Belépés elfogása ---------- */

/** Jelszavas belépés után: ha 2FA be van kapcsolva, kijelentkeztet, és az ellenőrző oldalra küld. */
add_action( 'wp_login', function ( $login, $user ) {
	if ( ! lif_2fa_enabled( $user->ID ) || defined( 'LIF_2FA_DONE' ) ) {
		return;
	}
	wp_clear_auth_cookie();
	wp_set_current_user( 0 );
	$token    = lif_random( 24 );
	$redirect = isset( $_REQUEST['redirect_to'] ) ? wp_validate_redirect( wp_unslash( $_REQUEST['redirect_to'] ), '' ) : '';
	set_transient( 'lif_2fa_' . hash( 'sha256', $token ), array(
		'user_id'  => $user->ID,
		'remember' => ! empty( $_POST['rememberme'] ),
		'redirect' => $redirect,
	), 5 * MINUTE_IN_SECONDS );
	setcookie( 'lif_2fa', $token, array(
		'expires'  => time() + 5 * MINUTE_IN_SECONDS,
		'path'     => COOKIEPATH ? COOKIEPATH : '/',
		'secure'   => is_ssl(),
		'httponly' => true,
		'samesite' => 'Lax',
	) );
	wp_safe_redirect( lif_url( '/ellenorzes' ) );
	exit;
}, 1, 2 );

/** A függő belépés adatai a sütiből. */
function lif_2fa_pending_login() {
	if ( empty( $_COOKIE['lif_2fa'] ) ) {
		return null;
	}
	$data = get_transient( 'lif_2fa_' . hash( 'sha256', sanitize_text_field( wp_unslash( $_COOKIE['lif_2fa'] ) ) ) );
	return is_array( $data ) ? $data : null;
}

function lif_2fa_finish_login( $data ) {
	delete_transient( 'lif_2fa_' . hash( 'sha256', sanitize_text_field( wp_unslash( $_COOKIE['lif_2fa'] ) ) ) );
	setcookie( 'lif_2fa', '', time() - 3600, COOKIEPATH ? COOKIEPATH : '/' );
	define( 'LIF_2FA_DONE', true );
	wp_set_auth_cookie( $data['user_id'], $data['remember'] );
	$user = get_userdata( $data['user_id'] );
	do_action( 'wp_login', $user->user_login, $user );
	if ( $data['redirect'] ) {
		return $data['redirect'];
	}
	return user_can( $user, 'lif_pastor' ) ? admin_url( 'admin.php?page=lif' ) : lif_url( '/fiok' );
}

/** Kétfaktoros felhasználók nem léphetnek be jelszóval XML-RPC-n vagy alkalmazásjelszóval (megkerülné a kódot). */
add_filter( 'authenticate', function ( $user ) {
	if ( $user instanceof WP_User && lif_2fa_enabled( $user->ID ) && ( ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) ) {
		return new WP_Error( 'lif_2fa', 'Ehhez a fiókhoz kétfaktoros hitelesítés tartozik.' );
	}
	return $user;
}, 100 );

add_filter( 'wp_is_application_passwords_available_for_user', function ( $available, $user ) {
	return lif_2fa_enabled( $user->ID ) ? false : $available;
}, 10, 2 );
