<?php
/**
 * Naptárnézet a lelkészi felületen: heti naptár a foglalásokkal, szünetekkel, elérhetőséggel,
 * távollétekkel és a Google Naptár foglalt időszakaival. Üres helyre kattintva foglalás vagy szünet
 * vehető fel, meglévő bejegyzésre kattintva megnézhető és lemondható / törölhető.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const LIF_CAL_PX_PER_MIN = 1; // 60 képpont egy óra

/** A naptárban mindenki – az admin is – csak a saját naptárát látja és kezeli. */
function lif_cal_pastor_id() {
	return get_current_user_id();
}

/** A hét hétfője (helyi idő szerint) a ?het=YYYY-MM-DD alapján, vagy az aktuális hét. */
function lif_cal_monday() {
	$tz  = lif_tz();
	$req = isset( $_REQUEST['het'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['het'] ) ) : '';
	$d   = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $req ) ? new DateTimeImmutable( $req, $tz ) : lif_now()->setTime( 0, 0 );
	return $d->setTime( 0, 0 )->modify( '-' . ( (int) $d->format( 'N' ) - 1 ) . ' days' );
}

function lif_cal_args( $uid, $monday ) {
	return array_filter( array(
		'het'     => $monday->format( 'Y-m-d' ),
		'lelkesz' => get_current_user_id() !== $uid ? $uid : null,
	) );
}

function lif_pp_calendar() {
	global $wpdb;
	$tz     = lif_tz();
	$uid    = lif_cal_pastor_id();
	$monday = lif_cal_monday();
	$from   = $monday->getTimestamp();
	$to     = $monday->modify( '+7 days' )->getTimestamp();
	$args   = lif_cal_args( $uid, $monday );

	$bookings = $wpdb->get_results( $wpdb->prepare(
		'SELECT b.*, s.name AS service_name, s.color FROM ' . lif_t( 'bookings' ) . ' b JOIN ' . lif_t( 'services' ) . " s ON s.id = b.service_id
		WHERE b.user_id = %d AND b.status = 'confirmed' AND b.start_utc < %s AND b.end_utc > %s ORDER BY b.start_utc",
		$uid, lif_iso( $to ), lif_iso( $from )
	) );
	$blocks   = $wpdb->get_results( $wpdb->prepare(
		'SELECT * FROM ' . lif_t( 'blocks' ) . ' WHERE user_id = %d AND start_utc < %s AND end_utc > %s ORDER BY start_utc',
		$uid, lif_iso( $to ), lif_iso( $from )
	) );
	$timeoff  = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . lif_t( 'timeoff' ) . ' WHERE user_id = %d', $uid ) );
	$windows  = lif_windows( $uid );
	// Google foglalt időszakok – kivéve azokat, amelyek a saját foglalásaink (azok külön látszanak)
	$own    = array_map( function ( $b ) {
		return array( strtotime( $b->start_utc ), strtotime( $b->end_utc ) );
	}, $bookings );
	$gbusy  = array_values( array_filter( lif_google_busy( $uid, $from, $to ), function ( $g ) use ( $own ) {
		foreach ( $own as $o ) {
			if ( $g[0] >= $o[0] && $g[1] <= $o[1] ) {
				return false;
			}
		}
		return true;
	} ) );

	// Megjelenített órák: alapból 7–20, de igazodik az elérhetőséghez és a bejegyzésekhez
	$h_from = 7;
	$h_to   = 20;
	foreach ( $windows as $ws ) {
		foreach ( $ws as $w ) {
			$h_from = min( $h_from, (int) substr( $w[0], 0, 2 ) );
			$h_to   = max( $h_to, (int) ceil( ( (int) substr( $w[1], 0, 2 ) * 60 + (int) substr( $w[1], 3, 2 ) ) / 60 ) );
		}
	}
	foreach ( array_merge( $own, array_map( function ( $b ) {
		return array( strtotime( $b->start_utc ), strtotime( $b->end_utc ) );
	}, $blocks ) ) as $iv ) {
		$h_from = min( $h_from, (int) lif_wdate( 'G', $iv[0] ) );
		$h_to   = max( $h_to, min( 24, (int) lif_wdate( 'G', $iv[1] - 1 ) + 1 ) );
	}
	$minutes = ( $h_to - $h_from ) * 60;

	// Napi bontás
	$days = array();
	for ( $i = 0; $i < 7; $i++ ) {
		$d      = $monday->modify( "+$i days" );
		$date   = $d->format( 'Y-m-d' );
		$d0     = $d->getTimestamp();
		$d1     = $d->modify( '+1 day' )->getTimestamp();
		$pos    = function ( $s, $e ) use ( $d0, $h_from, $minutes ) {
			$top = (int) round( ( $s - $d0 ) / 60 ) - $h_from * 60;
			$bot = (int) round( ( $e - $d0 ) / 60 ) - $h_from * 60;
			$top = max( 0, $top );
			$bot = min( $minutes, $bot );
			return $bot > $top ? array( $top, $bot - $top ) : null;
		};
		$off    = false;
		foreach ( $timeoff as $t ) {
			if ( $date >= $t->start_date && $date <= $t->end_date ) {
				$off = $t->note ? $t->note : 'Távollét';
			}
		}
		$avail = array();
		foreach ( $windows[ (int) $d->format( 'N' ) ] ?? array() as $w ) {
			$s = ( new DateTimeImmutable( $date . ' ' . $w[0], $tz ) )->getTimestamp();
			$e = '24:00' === $w[1] ? $d1 : ( new DateTimeImmutable( $date . ' ' . $w[1], $tz ) )->getTimestamp();
			if ( $p = $pos( $s, $e ) ) {
				$avail[] = $p;
			}
		}
		$items = array();
		foreach ( $bookings as $b ) {
			$s = strtotime( $b->start_utc );
			$e = strtotime( $b->end_utc );
			if ( $s < $d1 && $e > $d0 && ( $p = $pos( max( $s, $d0 ), min( $e, $d1 ) ) ) ) {
				$items[] = array( 'kind' => 'booking', 'pos' => $p, 'b' => $b, 's' => $s, 'e' => $e );
			}
		}
		foreach ( $blocks as $b ) {
			$s = strtotime( $b->start_utc );
			$e = strtotime( $b->end_utc );
			if ( $s < $d1 && $e > $d0 && ( $p = $pos( max( $s, $d0 ), min( $e, $d1 ) ) ) ) {
				$items[] = array( 'kind' => 'block', 'pos' => $p, 'b' => $b, 's' => $s, 'e' => $e );
			}
		}
		$gb = array();
		foreach ( $gbusy as $g ) {
			if ( $g[0] < $d1 && $g[1] > $d0 && ( $p = $pos( max( $g[0], $d0 ), min( $g[1], $d1 ) ) ) ) {
				$gb[] = $p;
			}
		}
		$days[] = compact( 'd', 'date', 'off', 'avail', 'items', 'gb' );
	}

	$services = $wpdb->get_results( $wpdb->prepare(
		'SELECT s.* FROM ' . lif_t( 'services' ) . ' s JOIN ' . lif_t( 'pastor_services' ) . ' ps ON ps.service_id = s.id WHERE ps.user_id = %d AND s.active = 1 ORDER BY s.sort, s.name',
		$uid
	) );
	if ( ! $services ) {
		$services = $wpdb->get_results( 'SELECT * FROM ' . lif_t( 'services' ) . ' WHERE active = 1 ORDER BY sort, name' );
	}
	$today = lif_now()->format( 'Y-m-d' );
	$who   = get_userdata( $uid );

	// Eszköztár: hetek közti lapozás, lelkészválasztó (admin), jelmagyarázat
	echo '<div class="cal-toolbar">';
	echo '<div class="actions">';
	echo '<a class="btn btn-small" href="' . esc_url( lif_portal_url( 'naptar', array_merge( $args, array( 'het' => $monday->modify( '-7 days' )->format( 'Y-m-d' ) ) ) ) ) . '">‹ Előző hét</a>';
	echo '<a class="btn btn-small btn-ghost" href="' . esc_url( lif_portal_url( 'naptar', array_merge( $args, array( 'het' => $today ) ) ) ) . '">Ma</a>';
	echo '<a class="btn btn-small" href="' . esc_url( lif_portal_url( 'naptar', array_merge( $args, array( 'het' => $monday->modify( '+7 days' )->format( 'Y-m-d' ) ) ) ) ) . '">Következő hét ›</a>';
	echo '<strong class="cal-range">' . esc_html( lif_wdate( 'Y. F j.', $from ) . ' – ' . lif_wdate( 'F j.', $to - 1 ) ) . '</strong>';
	echo '</div>';
	echo '</div>';
	echo '<p class="muted small cal-help">Kattintson egy üres helyre új foglaláshoz vagy szünethez, egy bejegyzésre a részletekhez.'
		. '</p>';
	echo '<div class="cal-legend"><span class="lg lg-avail">Foglalható idő</span><span class="lg lg-booking">Foglalás</span><span class="lg lg-block">Szünet</span><span class="lg lg-gbusy">Google: foglalt</span><span class="lg lg-off">Távollét</span></div>';

	echo '<div class="cal-scroll"><div class="cal" style="--cal-h:' . (int) ( $minutes * LIF_CAL_PX_PER_MIN ) . 'px">';
	echo '<div class="cal-corner"></div>';
	foreach ( $days as $day ) {
		echo '<div class="cal-dayhead' . ( $day['date'] === $today ? ' today' : '' ) . '"><span>' . esc_html( lif_wdate( 'l', $day['d']->getTimestamp() + 43200 ) ) . '</span><small>' . esc_html( lif_wdate( 'M j.', $day['d']->getTimestamp() + 43200 ) ) . '</small></div>';
	}
	echo '<div class="cal-times">';
	for ( $h = $h_from; $h < $h_to; $h++ ) {
		echo '<span style="top:' . (int) ( ( $h - $h_from ) * 60 * LIF_CAL_PX_PER_MIN ) . 'px">' . sprintf( '%02d:00', $h ) . '</span>';
	}
	echo '</div>';
	foreach ( $days as $day ) {
		echo '<div class="cal-day' . ( $day['off'] ? ' is-off' : '' ) . '" data-date="' . esc_attr( $day['date'] ) . '" data-from="' . (int) $h_from . '" role="grid" aria-label="' . esc_attr( lif_wdate( 'l, F j.', $day['d']->getTimestamp() + 43200 ) ) . '">';
		for ( $h = $h_from + 1; $h < $h_to; $h++ ) {
			echo '<i class="cal-line" style="top:' . (int) ( ( $h - $h_from ) * 60 * LIF_CAL_PX_PER_MIN ) . 'px"></i>';
		}
		foreach ( $day['avail'] as $p ) {
			echo '<i class="cal-avail" style="top:' . (int) $p[0] . 'px;height:' . (int) $p[1] . 'px"></i>';
		}
		foreach ( $day['gb'] as $p ) {
			echo '<i class="cal-gbusy" style="top:' . (int) $p[0] . 'px;height:' . (int) $p[1] . 'px" title="Google Naptár: foglalt"></i>';
		}
		if ( $day['off'] ) {
			echo '<span class="cal-offlabel">' . esc_html( $day['off'] ) . '</span>';
		}
		foreach ( $day['items'] as $it ) {
			$b     = $it['b'];
			$time  = lif_wdate( 'H:i', $it['s'] ) . '–' . lif_wdate( 'H:i', $it['e'] );
			$style = 'top:' . (int) $it['pos'][0] . 'px;height:' . max( 18, (int) $it['pos'][1] ) . 'px';
			if ( 'booking' === $it['kind'] ) {
				$data = array(
					'kind'    => 'booking',
					'id'      => (int) $b->id,
					'title'   => $b->client_name,
					'service' => $b->service_name,
					'when'    => lif_format_range( $b->start_utc, $b->end_utc ),
					'email'   => $b->client_email,
					'phone'   => $b->client_phone,
					'note'    => (string) $b->note,
					'link'    => lif_url( '/foglalas/' . $b->token ),
					'past'    => strtotime( $b->start_utc ) < time(),
				);
				echo '<button type="button" class="cal-ev cal-booking" style="' . esc_attr( $style ) . ';--accent:' . esc_attr( $b->color ) . '" data-ev="' . esc_attr( wp_json_encode( $data ) ) . '"><b>' . esc_html( $time ) . '</b> ' . esc_html( $b->client_name ) . '<small>' . esc_html( $b->service_name ) . '</small></button>';
			} else {
				$data = array(
					'kind' => 'block',
					'id'   => (int) $b->id,
					'when' => lif_format_range( $b->start_utc, $b->end_utc ),
					'note' => (string) $b->note,
				);
				echo '<button type="button" class="cal-ev cal-block" style="' . esc_attr( $style ) . '" data-ev="' . esc_attr( wp_json_encode( $data ) ) . '"><b>' . esc_html( $time ) . '</b> ' . esc_html( $b->note ? $b->note : 'Szünet' ) . '</button>';
			}
		}
		echo '</div>';
	}
	echo '</div></div>';

	// Párbeszédablak: új foglalás / szünet, illetve bejegyzés részletei
	$hidden = function () use ( $uid, $monday ) {
		echo '<input type="hidden" name="lelkesz" value="' . (int) $uid . '"><input type="hidden" name="het" value="' . esc_attr( $monday->format( 'Y-m-d' ) ) . '">';
	};
	echo '<dialog id="cal-dlg" class="cal-dlg" aria-labelledby="cal-dlg-title">';
	echo '<div class="row-between"><h2 id="cal-dlg-title">Új bejegyzés</h2><button type="button" class="btn btn-small btn-ghost" data-close>Bezárás</button></div>';

	echo '<div id="dlg-new"><p class="muted" id="dlg-when"></p><div class="tabs" role="tablist"><a href="#" class="active" data-tab="tab-booking" role="tab">Foglalás</a><a href="#" data-tab="tab-block" role="tab">Szünet</a></div>';
	lif_pform( 'cal_booking', 'id="tab-booking" class="stack"' );
	$hidden();
	echo '<div class="form-grid"><label>Dátum<input type="date" name="date" required></label><label>Kezdés<input type="time" name="time" step="300" required></label>';
	echo '<label>Alkalom<select name="service" required>';
	foreach ( $services as $s ) {
		echo '<option value="' . (int) $s->id . '">' . esc_html( $s->name . ' (' . (int) $s->duration_min . ' perc)' ) . '</option>';
	}
	echo '</select></label></div>';
	echo '<div class="form-grid"><label>Név *<input name="name" required maxlength="120"></label><label>E-mail<input type="email" name="email" maxlength="200"></label><label>Telefon<input type="tel" name="phone" maxlength="40"></label></div>';
	echo '<label>Megjegyzés<textarea name="note" rows="2"></textarea></label>';
	echo '<label class="check"><input type="checkbox" name="notify" checked> Visszaigazolás küldése a foglalónak e-mailben (ha megadta)</label>';
	echo '<div><button class="btn btn-primary">Foglalás rögzítése</button></div></form>';
	lif_pform( 'cal_block', 'id="tab-block" class="stack" hidden' );
	$hidden();
	echo '<div class="form-grid"><label>Dátum<input type="date" name="date" required></label><label>Kezdete<input type="time" name="start" step="300" required></label><label>Vége<input type="time" name="end" step="300" required></label></div>';
	echo '<label>Megjegyzés (csak Ön látja)<input name="note" maxlength="190" placeholder="pl. ebéd, iroda, temetés"></label>';
	echo '<p class="muted small">A szünet ideje alatt nem lehet Önhöz időpontot foglalni.</p>';
	echo '<div><button class="btn btn-primary">Szünet rögzítése</button></div></form></div>';

	echo '<div id="dlg-booking" hidden><dl class="details" id="dlg-booking-details"></dl><div class="actions"><a class="btn btn-small btn-ghost" id="dlg-booking-link" target="_blank" href="#">Foglalás oldala</a>';
	lif_pform( 'cancel_booking', 'class="inline" id="dlg-cancel" onsubmit="return confirm(\'Biztosan lemondja? A foglaló értesítést kap, ha megadott e-mail címet.\')"' );
	$hidden();
	echo '<input type="hidden" name="id"><input type="hidden" name="back" value="naptar"><button class="btn btn-small btn-danger">Foglalás lemondása</button></form></div></div>';

	echo '<div id="dlg-block" hidden><dl class="details" id="dlg-block-details"></dl>';
	lif_pform( 'delete_block', 'class="inline" onsubmit="return confirm(\'Törli a szünetet?\')"' );
	$hidden();
	echo '<input type="hidden" name="id"><button class="btn btn-small btn-danger">Szünet törlése</button></form></div>';
	echo '</dialog>';
	echo '<script src="' . esc_url( LIF_URL . 'assets/calendar.js?v=' . LIF_VERSION ) . '"></script>';
}

/** A naptár űrlapjainak feldolgozása (a portál űrlapkezelőjéből hívva). Igazzal tér vissza, ha kezelte. */
function lif_calendar_handle( $do ) {
	global $wpdb;
	if ( ! in_array( $do, array( 'cal_booking', 'cal_block', 'delete_block' ), true ) ) {
		return false;
	}
	$uid    = lif_cal_pastor_id();
	$monday = lif_cal_monday();
	$args   = lif_cal_args( $uid, $monday );
	$tz     = lif_tz();
	$parse  = function ( $date, $time ) use ( $tz ) {
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) || ! preg_match( '/^\d{2}:\d{2}$/', $time ) ) {
			return null;
		}
		return ( new DateTimeImmutable( $date . ' ' . $time, $tz ) )->getTimestamp();
	};

	if ( 'cal_booking' === $do ) {
		$service = lif_service( (int) lif_post( 'service', 20 ) );
		$start   = $parse( lif_post( 'date', 10 ), lif_post( 'time', 5 ) );
		$client  = array(
			'name'  => lif_post( 'name', 120 ),
			'email' => lif_post( 'email', 200 ),
			'phone' => lif_post( 'phone', 40 ),
			'note'  => lif_post( 'note', 2000 ),
		);
		if ( ! $service || ! $start ) {
			lif_portal_back( 'naptar', 'Adja meg az alkalmat, a dátumot és a kezdés idejét.', $args, true );
		}
		if ( '' === $client['name'] ) {
			lif_portal_back( 'naptar', 'A foglaló nevét meg kell adni.', $args, true );
		}
		if ( '' !== $client['email'] && ! is_email( $client['email'] ) ) {
			lif_portal_back( 'naptar', 'Az e-mail cím formátuma nem megfelelő.', $args, true );
		}
		$r = lif_create_manual_booking( $service, $uid, $start, $client, ! empty( $_POST['notify'] ) );
		if ( is_wp_error( $r ) ) {
			lif_portal_back( 'naptar', $r->get_error_message(), $args, true );
		}
		lif_portal_back( 'naptar', 'Foglalás rögzítve: ' . lif_format_range( $r->start_utc, $r->end_utc ) . '.', $args );
	}

	if ( 'cal_block' === $do ) {
		$date  = lif_post( 'date', 10 );
		$start = $parse( $date, lif_post( 'start', 5 ) );
		$end   = $parse( $date, lif_post( 'end', 5 ) );
		if ( '24:00' === lif_post( 'end', 5 ) || ( $end && $start && $end <= $start && '00:00' === lif_post( 'end', 5 ) ) ) {
			$end = ( new DateTimeImmutable( $date, $tz ) )->modify( '+1 day' )->getTimestamp();
		}
		if ( ! $start || ! $end || $end <= $start ) {
			lif_portal_back( 'naptar', 'A szünet vége legyen későbbi a kezdeténél.', $args, true );
		}
		$conflict = $wpdb->get_var( $wpdb->prepare(
			'SELECT COUNT(*) FROM ' . lif_t( 'bookings' ) . " WHERE user_id = %d AND status = 'confirmed' AND start_utc < %s AND end_utc > %s",
			$uid, lif_iso( $end ), lif_iso( $start )
		) );
		$wpdb->insert( lif_t( 'blocks' ), array( 'user_id' => $uid, 'start_utc' => lif_iso( $start ), 'end_utc' => lif_iso( $end ), 'note' => lif_post( 'note', 190 ) ) );
		lif_portal_back( 'naptar', 'Szünet rögzítve.' . ( $conflict ? ' Figyelem: erre az időre már van foglalás – azt nem mondtuk le.' : '' ), $args );
	}

	// delete_block
	$b = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . lif_t( 'blocks' ) . ' WHERE id = %d', (int) lif_post( 'id', 20 ) ) );
	if ( $b && lif_can_manage_pastor( $b->user_id ) ) {
		$wpdb->delete( lif_t( 'blocks' ), array( 'id' => $b->id ) );
	}
	lif_portal_back( 'naptar', 'Szünet törölve.', $args );
}
