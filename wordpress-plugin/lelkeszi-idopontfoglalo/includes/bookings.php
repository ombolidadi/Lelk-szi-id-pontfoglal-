<?php
/**
 * Foglalás létrehozása és lemondása (adatbázis + Google Naptár + e-mail).
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function lif_booking_by_token( $token ) {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare(
		'SELECT b.*, s.name AS service_name, s.location, g.slug AS group_slug, g.name AS group_name FROM ' . lif_t( 'bookings' ) . ' b
		JOIN ' . lif_t( 'services' ) . ' s ON s.id = b.service_id
		LEFT JOIN ' . lif_t( 'groups' ) . ' g ON g.id = b.group_id AND g.active = 1
		WHERE b.token = %s',
		$token
	) );
}

function lif_mail( $to, $subject, $text ) {
	if ( $to ) {
		wp_mail( $to, $subject, $text );
	}
}

/**
 * Foglalás. A $pastor_ids közül (csak a csoport tagjai!) az első olyan lelkészhez kerül, akinél az időpont még szabad.
 * Siker: a foglalás sora; hiba: WP_Error.
 */
function lif_create_booking( $service, $pastor_ids, $start_ts, $client, $client_id = null, $group = null ) {
	global $wpdb;
	$end_ts = $start_ts + (int) $service->duration_min * 60;
	$t      = lif_t( 'bookings' );

	// A legkevésbé leterhelt lelkész előre
	$load = array();
	foreach ( $pastor_ids as $pid ) {
		$load[ $pid ] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE user_id = %d AND status = 'confirmed' AND start_utc >= %s", $pid, lif_iso( time() ) ) );
	}
	asort( $load );
	$pastor_id = null;
	foreach ( array_keys( $load ) as $pid ) {
		if ( lif_is_slot_free( $service, $pid, $start_ts ) ) {
			$pastor_id = $pid;
			break;
		}
	}
	if ( ! $pastor_id ) {
		return new WP_Error( 'taken', 'Ez az időpont időközben betelt. Kérjük, válasszon másikat.' );
	}

	// Párhuzamos foglalások ellen: rövid zár a lelkészre, és újraellenőrzés közvetlenül beírás előtt.
	$lock = 'lif_lock_' . $pastor_id;
	if ( ! add_option( $lock, time(), '', 'no' ) ) {
		if ( (int) get_option( $lock ) < time() - 30 ) {
			update_option( $lock, time() );
		} else {
			return new WP_Error( 'busy', 'Valaki éppen ugyanerre az időpontra foglal. Kérjük, próbálja újra néhány másodperc múlva.' );
		}
	}
	$pad = (int) $service->buffer_min * 60;
	if ( lif_db_busy( $pastor_id, $start_ts - $pad, $end_ts + $pad ) ) {
		delete_option( $lock );
		return new WP_Error( 'taken', 'Ez az időpont időközben betelt. Kérjük, válasszon másikat.' );
	}
	$booking = lif_insert_booking( $service, $pastor_id, $start_ts, $end_ts, $client, $client_id, $group, true );
	delete_option( $lock );
	return $booking;
}

/**
 * Lelkész által a naptárból felvett foglalás. A heti elérhetőségtől függetlenül bármikor felvehető,
 * csak meglévő foglalással vagy szünettel nem ütközhet.
 */
function lif_create_manual_booking( $service, $pastor_id, $start_ts, $client, $notify_client = true ) {
	$end_ts = $start_ts + (int) $service->duration_min * 60;
	if ( lif_db_busy( $pastor_id, $start_ts, $end_ts ) ) {
		return new WP_Error( 'taken', 'Ebben az időpontban már van foglalás vagy szünet.' );
	}
	return lif_insert_booking( $service, $pastor_id, $start_ts, $end_ts, $client, null, null, false, $notify_client );
}

/** Foglalás beírása + Google Naptár-esemény + e-mail értesítések. */
function lif_insert_booking( $service, $pastor_id, $start_ts, $end_ts, $client, $client_id, $group, $notify_pastor, $notify_client = true ) {
	global $wpdb;
	$t     = lif_t( 'bookings' );
	$token = lif_random( 24 );
	$wpdb->insert( $t, array(
		'service_id'   => $service->id,
		'user_id'      => $pastor_id,
		'group_id'     => $group ? $group->id : null,
		'client_id'    => $client_id,
		'start_utc'    => lif_iso( $start_ts ),
		'end_utc'      => lif_iso( $end_ts ),
		'client_name'  => $client['name'],
		'client_email' => $client['email'],
		'client_phone' => $client['phone'],
		'note'         => $client['note'],
		'status'       => 'confirmed',
		'token'        => $token,
		'created_at'   => current_time( 'mysql', true ),
	) );
	$booking_id = (int) $wpdb->insert_id;

	$pastor   = get_userdata( $pastor_id );
	$settings = lif_settings();
	$link     = lif_url( '/foglalas/' . $token );

	$ev = lif_google_create_event( $pastor_id, array(
		'summary'        => $service->name . ' – ' . $client['name'],
		'description'    => implode( "\n", array_filter( array(
			'Szolgáltatás: ' . $service->name,
			'Név: ' . $client['name'],
			$client['email'] ? 'E-mail: ' . $client['email'] : '',
			$client['phone'] ? 'Telefon: ' . $client['phone'] : '',
			$client['note'] ? "\nMegjegyzés:\n" . $client['note'] : '',
			"\nLemondás: " . $link,
		) ) ),
		'location'       => $service->location,
		'start'          => $start_ts,
		'end'            => $end_ts,
		'attendee_email' => $client['email'], // üres, ha a vendég csak telefonszámot adott meg
		'attendee_name'  => $client['name'],
		'invite'         => $notify_client && ! empty( $settings['invite_client'] ),
	) );
	if ( is_wp_error( $ev ) ) {
		error_log( '[lif] Google esemény létrehozása sikertelen: ' . $ev->get_error_message() );
	} elseif ( $ev ) {
		$wpdb->update( $t, array(
			'google_account_id'  => $ev['account_id'],
			'google_calendar_id' => $ev['calendar_id'],
			'google_event_id'    => $ev['event_id'],
		), array( 'id' => $booking_id ) );
		lif_google_flush_busy_cache();
	}

	$when = lif_format_range( lif_iso( $start_ts ), lif_iso( $end_ts ) );
	lif_mail( $notify_client ? $client['email'] : '', 'Időpontfoglalás visszaigazolása – ' . $service->name,
		"Kedves {$client['name']}!\n\nIdőpontját rögzítettük.\n\nSzolgáltatás: {$service->name}\nLelkész: {$pastor->display_name}\nIdőpont: $when\n"
		. ( $service->location ? "Helyszín: {$service->location}\n" : '' )
		. "\nHa mégsem tud eljönni, itt lemondhatja: $link\n\nÁldás és békesség!\n{$settings['org_name']}" );
	lif_mail( $notify_pastor ? $pastor->user_email : '', 'Új foglalás: ' . $service->name . ' – ' . $client['name'],
		"Új időpontfoglalás érkezett.\n\nSzolgáltatás: {$service->name}\nIdőpont: $when\nNév: {$client['name']}\n" . ( $client['email'] ? "E-mail: {$client['email']}\n" : '' )
		. ( $client['phone'] ? "Telefon: {$client['phone']}\n" : '' ) . ( $client['note'] ? "Megjegyzés: {$client['note']}\n" : '' ) );

	return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d", $booking_id ) );
}

function lif_cancel_booking( $booking, $by ) {
	global $wpdb;
	if ( 'confirmed' !== $booking->status ) {
		return;
	}
	$settings = lif_settings();
	$wpdb->update( lif_t( 'bookings' ), array( 'status' => 'cancelled' ), array( 'id' => $booking->id ) );
	if ( $booking->google_event_id ) {
		lif_google_delete_event( $booking->google_account_id, $booking->google_calendar_id, $booking->google_event_id, ! empty( $settings['invite_client'] ) );
		lif_google_flush_busy_cache();
	}
	$service = lif_service( $booking->service_id );
	$pastor  = get_userdata( $booking->user_id );
	$when    = lif_format_range( $booking->start_utc, $booking->end_utc );
	if ( 'client' === $by ) {
		lif_mail( $pastor ? $pastor->user_email : '', 'Lemondott foglalás: ' . $service->name . ' – ' . $booking->client_name,
			"{$booking->client_name} lemondta a(z) $when időpontot ({$service->name})." );
	} else {
		$group = $booking->group_id ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . lif_t( 'groups' ) . ' WHERE id = %d AND active = 1', $booking->group_id ) ) : null;
		lif_mail( $booking->client_email, 'Időpont lemondva – ' . $service->name,
			"Kedves {$booking->client_name}!\n\nSajnos a(z) $when időpontot ({$service->name}, " . ( $pastor ? $pastor->display_name : '' ) . ") le kellett mondanunk.\n"
			. ( $group ? 'Új időpontot itt foglalhat: ' . lif_group_url( $group ) . "\n" : '' ) . "\n{$settings['org_name']}" );
	}
}
