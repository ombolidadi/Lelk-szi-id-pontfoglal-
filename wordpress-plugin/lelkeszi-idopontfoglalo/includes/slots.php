<?php
/**
 * Szabad időpontok: heti elérhetőség − távollét − meglévő foglalások − Google Naptár foglaltság.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tiszta számítás (adatbázis nélkül).
 * $windows: [weekday => [[ 'HH:MM', 'HH:MM' ], ...]], $busy: [[start_ts, end_ts], ...]
 * Visszaad: [ 'YYYY-MM-DD' => [ [start_ts, end_ts], ... ] ]
 */
function lif_compute_free_slots( $dates, $tz, $windows, $timeoff, $busy, $duration_min, $buffer_min, $step_min, $earliest ) {
	$out  = array();
	$step = max( 5, (int) $step_min ) * 60;
	$dur  = (int) $duration_min * 60;
	$pad  = (int) $buffer_min * 60;
	foreach ( $dates as $date ) {
		$out[ $date ] = array();
		foreach ( $timeoff as $t ) {
			if ( $date >= $t['start_date'] && $date <= $t['end_date'] ) {
				continue 2;
			}
		}
		$weekday = (int) ( new DateTimeImmutable( $date, $tz ) )->format( 'N' );
		foreach ( isset( $windows[ $weekday ] ) ? $windows[ $weekday ] : array() as $w ) {
			$ws = ( new DateTimeImmutable( $date . ' ' . $w[0], $tz ) )->getTimestamp();
			$we = ( '24:00' === $w[1] )
				? ( new DateTimeImmutable( $date, $tz ) )->modify( '+1 day' )->getTimestamp()
				: ( new DateTimeImmutable( $date . ' ' . $w[1], $tz ) )->getTimestamp();
			for ( $s = $ws; $s + $dur <= $we; $s += $step ) {
				if ( null !== $earliest && $s < $earliest ) {
					continue;
				}
				$e = $s + $dur;
				foreach ( $busy as $b ) {
					if ( $s < $b[1] + $pad && $e + $pad > $b[0] ) {
						continue 2;
					}
				}
				$out[ $date ][] = array( $s, $e );
			}
		}
		usort( $out[ $date ], function ( $a, $b ) {
			return $a[0] - $b[0];
		} );
	}
	return $out;
}

function lif_date_range( $from, $to ) {
	$dates = array();
	$d     = new DateTimeImmutable( $from );
	$end   = new DateTimeImmutable( $to );
	while ( $d <= $end ) {
		$dates[] = $d->format( 'Y-m-d' );
		$d       = $d->modify( '+1 day' );
	}
	return $dates;
}

function lif_windows( $user_id ) {
	global $wpdb;
	$windows = array();
	foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT weekday, start_time, end_time FROM ' . lif_t( 'availability' ) . ' WHERE user_id = %d ORDER BY weekday, start_time', $user_id ) ) as $r ) {
		$windows[ (int) $r->weekday ][] = array( $r->start_time, $r->end_time );
	}
	return $windows;
}

function lif_db_busy( $user_id, $from_ts, $to_ts, $exclude_id = 0 ) {
	global $wpdb;
	$rows = $wpdb->get_results( $wpdb->prepare(
		'SELECT start_utc, end_utc FROM ' . lif_t( 'bookings' ) . " WHERE user_id = %d AND status = 'confirmed' AND start_utc < %s AND end_utc > %s AND id != %d",
		$user_id, lif_iso( $to_ts ), lif_iso( $from_ts ), $exclude_id
	) );
	// A lelkész által a naptárban felvett szünetek is foglaltnak számítanak.
	$blocks = $wpdb->get_results( $wpdb->prepare(
		'SELECT start_utc, end_utc FROM ' . lif_t( 'blocks' ) . ' WHERE user_id = %d AND start_utc < %s AND end_utc > %s',
		$user_id, lif_iso( $to_ts ), lif_iso( $from_ts )
	) );
	return array_map( function ( $r ) {
		return array( strtotime( $r->start_utc ), strtotime( $r->end_utc ) );
	}, array_merge( $rows, $blocks ) );
}

/**
 * Szabad időpontok egy szolgáltatáshoz, több lelkészre.
 * Visszaad: [ 'YYYY-MM-DD' => [ start_ts => [pastor_id, ...], ... ] ]
 */
function lif_get_slots( $service, $pastor_ids, $from, $to, $use_google = true ) {
	global $wpdb;
	$tz    = lif_tz();
	$now   = lif_now();
	$today = $now->format( 'Y-m-d' );
	$last  = $now->modify( '+' . max( 1, (int) lif_setting( 'max_days_ahead' ) ) . ' days' )->format( 'Y-m-d' );
	$from  = max( $from, $today );
	$to    = min( $to, $last );
	if ( $from > $to ) {
		return array();
	}
	$dates       = lif_date_range( $from, $to );
	$range_start = ( new DateTimeImmutable( $from, $tz ) )->getTimestamp();
	$range_end   = ( new DateTimeImmutable( $to, $tz ) )->modify( '+1 day' )->getTimestamp();
	$pad         = (int) $service->buffer_min * 60;
	$earliest    = time() + (int) lif_setting( 'min_notice_hours' ) * 3600;

	$merged = array_fill_keys( $dates, array() );
	foreach ( $pastor_ids as $pid ) {
		$busy = lif_db_busy( $pid, $range_start - $pad, $range_end + $pad );
		if ( $use_google ) {
			$busy = array_merge( $busy, lif_google_busy( $pid, $range_start - $pad, $range_end + $pad ) );
		}
		$timeoff = $wpdb->get_results( $wpdb->prepare( 'SELECT start_date, end_date FROM ' . lif_t( 'timeoff' ) . ' WHERE user_id = %d', $pid ), ARRAY_A );
		$res     = lif_compute_free_slots( $dates, $tz, lif_windows( $pid ), $timeoff, $busy, $service->duration_min, $service->buffer_min, lif_setting( 'slot_step_min' ), $earliest );
		foreach ( $res as $date => $slots ) {
			foreach ( $slots as $s ) {
				$merged[ $date ][ $s[0] ][] = $pid;
			}
		}
	}
	foreach ( $merged as $date => $slots ) {
		ksort( $merged[ $date ] );
	}
	return $merged;
}

function lif_is_slot_free( $service, $pastor_id, $start_ts ) {
	$date = wp_date( 'Y-m-d', $start_ts, lif_tz() );
	$res  = lif_get_slots( $service, array( $pastor_id ), $date, $date );
	return isset( $res[ $date ][ $start_ts ] );
}
