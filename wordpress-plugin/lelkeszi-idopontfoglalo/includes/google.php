<?php
/**
 * Google Naptár: minden lelkész a saját Google fiókját (akár többet) köti be.
 * Kiválasztja, mely naptárak foglaltságát figyelje a rendszer, és melyikbe kerüljenek a foglalások.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const LIF_GOOGLE_SCOPES = 'openid email https://www.googleapis.com/auth/calendar.readonly https://www.googleapis.com/auth/calendar.events';

function lif_google_client_id() {
	return defined( 'LIF_GOOGLE_CLIENT_ID' ) ? LIF_GOOGLE_CLIENT_ID : (string) lif_setting( 'google_client_id' );
}

function lif_google_client_secret() {
	return defined( 'LIF_GOOGLE_CLIENT_SECRET' ) ? LIF_GOOGLE_CLIENT_SECRET : (string) lif_setting( 'google_client_secret' );
}

function lif_google_configured() {
	return '' !== lif_google_client_id() && '' !== lif_google_client_secret();
}

function lif_google_redirect_uri() {
	return lif_url( '/google-visszahivas' );
}

function lif_google_auth_url( $state ) {
	return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query( array(
		'client_id'              => lif_google_client_id(),
		'redirect_uri'           => lif_google_redirect_uri(),
		'response_type'          => 'code',
		'scope'                  => LIF_GOOGLE_SCOPES,
		'access_type'            => 'offline',
		'prompt'                 => 'consent select_account',
		'include_granted_scopes' => 'true',
		'state'                  => $state,
	), '', '&', PHP_QUERY_RFC3986 );
}

function lif_google_token_request( $body ) {
	$res = wp_remote_post( 'https://oauth2.googleapis.com/token', array( 'body' => $body, 'timeout' => 15 ) );
	if ( is_wp_error( $res ) ) {
		return $res;
	}
	$json = json_decode( wp_remote_retrieve_body( $res ), true );
	if ( 200 !== wp_remote_retrieve_response_code( $res ) ) {
		return new WP_Error( 'google', 'Google token hiba: ' . ( isset( $json['error_description'] ) ? $json['error_description'] : ( isset( $json['error'] ) ? $json['error'] : 'ismeretlen' ) ) );
	}
	return $json;
}

/** OAuth visszahívás után: fiók mentése és naptárlista szinkronizálása. */
function lif_google_connect( $user_id, $code ) {
	global $wpdb;
	$tok = lif_google_token_request( array(
		'code'          => $code,
		'client_id'     => lif_google_client_id(),
		'client_secret' => lif_google_client_secret(),
		'redirect_uri'  => lif_google_redirect_uri(),
		'grant_type'    => 'authorization_code',
	) );
	if ( is_wp_error( $tok ) ) {
		return $tok;
	}
	$info = wp_remote_get( 'https://openidconnect.googleapis.com/v1/userinfo', array( 'headers' => array( 'Authorization' => 'Bearer ' . $tok['access_token'] ), 'timeout' => 15 ) );
	$email = is_wp_error( $info ) ? '' : (string) ( json_decode( wp_remote_retrieve_body( $info ), true )['email'] ?? '' );
	if ( '' === $email ) {
		return new WP_Error( 'google', 'Nem sikerült lekérni a Google fiók adatait.' );
	}
	$t        = lif_t( 'google_accounts' );
	$existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE user_id = %d AND email = %s", $user_id, $email ) );
	$data     = array(
		'access_token' => lif_encrypt( $tok['access_token'] ),
		'token_expiry' => time() + (int) ( $tok['expires_in'] ?? 3600 ),
		'last_error'   => null,
	);
	if ( ! empty( $tok['refresh_token'] ) ) {
		$data['refresh_token'] = lif_encrypt( $tok['refresh_token'] );
	}
	if ( $existing ) {
		$wpdb->update( $t, $data, array( 'id' => $existing->id ) );
		$account_id = (int) $existing->id;
	} else {
		$wpdb->insert( $t, array_merge( $data, array( 'user_id' => $user_id, 'email' => $email ) ) );
		$account_id = (int) $wpdb->insert_id;
	}
	$r = lif_google_sync_calendars( $account_id );
	return is_wp_error( $r ) ? $r : $account_id;
}

function lif_google_account( $id ) {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . lif_t( 'google_accounts' ) . ' WHERE id = %d', $id ) );
}

function lif_google_access_token( $account ) {
	global $wpdb;
	$access = lif_decrypt( $account->access_token );
	if ( $access && (int) $account->token_expiry > time() + 60 ) {
		return $access;
	}
	$refresh = lif_decrypt( $account->refresh_token );
	if ( ! $refresh ) {
		return new WP_Error( 'google', 'Hiányzó frissítő token – csatlakoztassa újra a Google fiókot.' );
	}
	$tok = lif_google_token_request( array(
		'client_id'     => lif_google_client_id(),
		'client_secret' => lif_google_client_secret(),
		'refresh_token' => $refresh,
		'grant_type'    => 'refresh_token',
	) );
	if ( is_wp_error( $tok ) ) {
		$wpdb->update( lif_t( 'google_accounts' ), array( 'last_error' => $tok->get_error_message() ), array( 'id' => $account->id ) );
		return $tok;
	}
	$wpdb->update( lif_t( 'google_accounts' ), array(
		'access_token' => lif_encrypt( $tok['access_token'] ),
		'token_expiry' => time() + (int) ( $tok['expires_in'] ?? 3600 ),
		'last_error'   => null,
	), array( 'id' => $account->id ) );
	return $tok['access_token'];
}

function lif_google_api( $account, $method, $path, $body = null ) {
	$token = lif_google_access_token( $account );
	if ( is_wp_error( $token ) ) {
		return $token;
	}
	$args = array(
		'method'  => $method,
		'timeout' => 15,
		'headers' => array( 'Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json' ),
	);
	if ( null !== $body ) {
		$args['body'] = wp_json_encode( $body );
	}
	$res = wp_remote_request( 'https://www.googleapis.com/calendar/v3' . $path, $args );
	if ( is_wp_error( $res ) ) {
		return $res;
	}
	$code = wp_remote_retrieve_response_code( $res );
	$json = json_decode( wp_remote_retrieve_body( $res ), true );
	if ( $code >= 400 ) {
		return new WP_Error( 'google', sprintf( 'Google Naptár hiba (%d): %s', $code, $json['error']['message'] ?? '' ), array( 'status' => $code ) );
	}
	return is_array( $json ) ? $json : array();
}

function lif_google_sync_calendars( $account_id ) {
	global $wpdb;
	$account = lif_google_account( $account_id );
	$items   = array();
	$page    = null;
	do {
		$q   = array( 'minAccessRole' => 'freeBusyReader', 'maxResults' => 250 );
		if ( $page ) {
			$q['pageToken'] = $page;
		}
		$res = lif_google_api( $account, 'GET', '/users/me/calendarList?' . http_build_query( $q ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$items = array_merge( $items, $res['items'] ?? array() );
		$page  = $res['nextPageToken'] ?? null;
	} while ( $page );

	$t    = lif_t( 'google_calendars' );
	$seen = array();
	foreach ( $items as $c ) {
		$seen[]   = $c['id'];
		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT id FROM $t WHERE account_id = %d AND calendar_id = %s", $account_id, $c['id'] ) );
		$data     = array(
			'summary'    => $c['summaryOverride'] ?? ( $c['summary'] ?? $c['id'] ),
			'is_primary' => ! empty( $c['primary'] ) ? 1 : 0,
			'can_write'  => in_array( $c['accessRole'] ?? '', array( 'owner', 'writer' ), true ) ? 1 : 0,
		);
		if ( $existing ) {
			$wpdb->update( $t, $data, array( 'id' => $existing->id ) );
		} else {
			$wpdb->insert( $t, array_merge( $data, array( 'account_id' => $account_id, 'calendar_id' => $c['id'], 'check_busy' => ! empty( $c['primary'] ) ? 1 : 0 ) ) );
		}
	}
	foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT id, calendar_id FROM $t WHERE account_id = %d", $account_id ) ) as $e ) {
		if ( ! in_array( $e->calendar_id, $seen, true ) ) {
			$wpdb->delete( $t, array( 'id' => $e->id ) );
		}
	}
	// Ha a lelkésznek még nincs célnaptára, az elsődleges naptár lesz az.
	if ( ! get_user_meta( $account->user_id, 'lif_target_calendar', true ) ) {
		foreach ( $items as $c ) {
			if ( ! empty( $c['primary'] ) ) {
				update_user_meta( $account->user_id, 'lif_target_account', $account_id );
				update_user_meta( $account->user_id, 'lif_target_calendar', $c['id'] );
			}
		}
	}
	return true;
}

/** Foglalt időszakok a lelkész összes kiválasztott naptárából (5 percig gyorsítótárazva). */
function lif_google_busy( $user_id, $from_ts, $to_ts ) {
	global $wpdb;
	if ( ! lif_google_configured() ) {
		return array();
	}
	$cache_key = 'lif_busy_' . $user_id . '_' . md5( $from_ts . '-' . $to_ts );
	$cached    = get_transient( $cache_key );
	if ( is_array( $cached ) ) {
		return $cached;
	}
	$rows = $wpdb->get_results( $wpdb->prepare(
		'SELECT c.calendar_id, c.account_id FROM ' . lif_t( 'google_calendars' ) . ' c JOIN ' . lif_t( 'google_accounts' ) . ' a ON a.id = c.account_id WHERE a.user_id = %d AND c.check_busy = 1',
		$user_id
	) );
	$by_account = array();
	foreach ( $rows as $r ) {
		$by_account[ $r->account_id ][] = array( 'id' => $r->calendar_id );
	}
	$busy = array();
	foreach ( $by_account as $account_id => $cals ) {
		$fb = lif_google_api( lif_google_account( $account_id ), 'POST', '/freeBusy', array(
			'timeMin' => lif_iso( $from_ts ),
			'timeMax' => lif_iso( $to_ts ),
			'items'   => $cals,
		) );
		if ( is_wp_error( $fb ) ) {
			$wpdb->update( lif_t( 'google_accounts' ), array( 'last_error' => $fb->get_error_message() ), array( 'id' => $account_id ) );
			continue;
		}
		foreach ( $fb['calendars'] ?? array() as $cal ) {
			foreach ( $cal['busy'] ?? array() as $b ) {
				$busy[] = array( strtotime( $b['start'] ), strtotime( $b['end'] ) );
			}
		}
	}
	set_transient( $cache_key, $busy, 5 * MINUTE_IN_SECONDS );
	return $busy;
}

function lif_google_flush_busy_cache() {
	global $wpdb;
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_lif\\_busy\\_%' OR option_name LIKE '\\_transient\\_timeout\\_lif\\_busy\\_%'" );
}

function lif_google_create_event( $user_id, $args ) {
	if ( ! lif_google_configured() ) {
		return null;
	}
	$account_id = (int) get_user_meta( $user_id, 'lif_target_account', true );
	$calendar   = (string) get_user_meta( $user_id, 'lif_target_calendar', true );
	$account    = $account_id ? lif_google_account( $account_id ) : null;
	if ( ! $account || '' === $calendar ) {
		return null;
	}
	$tz   = wp_timezone_string();
	$body = array(
		'summary'     => $args['summary'],
		'description' => $args['description'],
		'start'       => array( 'dateTime' => lif_iso( $args['start'] ), 'timeZone' => $tz ),
		'end'         => array( 'dateTime' => lif_iso( $args['end'] ), 'timeZone' => $tz ),
		'reminders'   => array( 'useDefault' => true ),
	);
	if ( ! empty( $args['location'] ) ) {
		$body['location'] = $args['location'];
	}
	if ( ! empty( $args['invite'] ) && ! empty( $args['attendee_email'] ) ) {
		$body['attendees'] = array( array( 'email' => $args['attendee_email'], 'displayName' => $args['attendee_name'] ) );
	}
	$ev = lif_google_api( $account, 'POST', '/calendars/' . rawurlencode( $calendar ) . '/events' . ( ! empty( $args['invite'] ) ? '?sendUpdates=all' : '' ), $body );
	if ( is_wp_error( $ev ) ) {
		return $ev;
	}
	return array( 'account_id' => $account->id, 'calendar_id' => $calendar, 'event_id' => $ev['id'] );
}

function lif_google_delete_event( $account_id, $calendar_id, $event_id, $notify ) {
	$account = lif_google_account( $account_id );
	if ( ! $account || ! $event_id ) {
		return;
	}
	$r = lif_google_api( $account, 'DELETE', '/calendars/' . rawurlencode( $calendar_id ) . '/events/' . rawurlencode( $event_id ) . ( $notify ? '?sendUpdates=all' : '' ) );
	if ( is_wp_error( $r ) ) {
		$data = $r->get_error_data();
		if ( ! in_array( $data['status'] ?? 0, array( 404, 410 ), true ) ) {
			error_log( '[lif] Google esemény törlése sikertelen: ' . $r->get_error_message() );
		}
	}
}
