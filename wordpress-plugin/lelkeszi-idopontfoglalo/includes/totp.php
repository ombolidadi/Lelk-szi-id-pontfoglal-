<?php
/**
 * TOTP (RFC 6238) – a hitelesítő alkalmazások (Google Authenticator, Microsoft Authenticator, Authy) szabványa.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const LIF_B32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

function lif_b32_encode( $bin ) {
	$bits = '';
	foreach ( str_split( $bin ) as $c ) {
		$bits .= str_pad( decbin( ord( $c ) ), 8, '0', STR_PAD_LEFT );
	}
	$out = '';
	foreach ( str_split( $bits, 5 ) as $chunk ) {
		$out .= LIF_B32[ bindec( str_pad( $chunk, 5, '0' ) ) ];
	}
	return $out;
}

function lif_b32_decode( $str ) {
	$str  = preg_replace( '/[^A-Z2-7]/', '', strtoupper( (string) $str ) );
	$bits = '';
	foreach ( str_split( $str ) as $c ) {
		$bits .= str_pad( decbin( strpos( LIF_B32, $c ) ), 5, '0', STR_PAD_LEFT );
	}
	$out = '';
	foreach ( str_split( $bits, 8 ) as $byte ) {
		if ( 8 === strlen( $byte ) ) {
			$out .= chr( bindec( $byte ) );
		}
	}
	return $out;
}

function lif_hotp( $key, $counter, $digits = 6 ) {
	$msg  = pack( 'N2', ( $counter >> 32 ) & 0xffffffff, $counter & 0xffffffff );
	$h    = hash_hmac( 'sha1', $msg, $key, true );
	$off  = ord( $h[19] ) & 0xf;
	$code = ( ( ord( $h[ $off ] ) & 0x7f ) << 24 ) | ( ord( $h[ $off + 1 ] ) << 16 ) | ( ord( $h[ $off + 2 ] ) << 8 ) | ord( $h[ $off + 3 ] );
	return str_pad( (string) ( $code % ( 10 ** $digits ) ), $digits, '0', STR_PAD_LEFT );
}

function lif_totp_step( $time = null ) {
	return (int) floor( ( null === $time ? time() : $time ) / 30 );
}

function lif_totp_secret() {
	return lif_b32_encode( random_bytes( 20 ) );
}

/**
 * Ellenőrzi a kódot ±1 lépés (30 mp) tűréssel. Visszaadja az elfogadott lépést, vagy false-t.
 * A $last_step-nél nem nagyobb lépést elutasítja, így egy kód nem használható fel kétszer.
 */
function lif_totp_verify( $secret, $code, $last_step = 0, $time = null ) {
	$code = preg_replace( '/\s+/', '', (string) $code );
	if ( ! preg_match( '/^\d{6}$/', $code ) ) {
		return false;
	}
	$key = lif_b32_decode( $secret );
	$cur = lif_totp_step( $time );
	foreach ( array( $cur, $cur - 1, $cur + 1 ) as $s ) {
		if ( $s <= (int) $last_step ) {
			continue;
		}
		if ( hash_equals( lif_hotp( $key, $s ), $code ) ) {
			return $s;
		}
	}
	return false;
}

function lif_totp_uri( $secret, $account, $issuer ) {
	return 'otpauth://totp/' . rawurlencode( $issuer . ':' . $account ) . '?secret=' . $secret . '&issuer=' . rawurlencode( $issuer ) . '&algorithm=SHA1&digits=6&period=30';
}
