<?php
/**
 * REST végpont a szabad időpontokhoz: /wp-json/lif/v1/slots?group=...&service=...&pastor=...&from=...&days=7
 * Csak csoporton belül működik, és csak a csoport tagjainak időpontjait adja vissza.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'rest_api_init', function () {
	register_rest_route( 'lif/v1', '/slots', array(
		'methods'             => 'GET',
		'permission_callback' => '__return_true',
		'callback'            => 'lif_rest_slots',
	) );
} );

function lif_rest_slots( WP_REST_Request $req ) {
	$group = lif_group_by_slug( (string) $req->get_param( 'group' ) );
	if ( ! $group ) {
		return new WP_Error( 'not_found', 'Ismeretlen csoport', array( 'status' => 404 ) );
	}
	$service = lif_service( (int) $req->get_param( 'service' ) );
	if ( ! $service || ! $service->active ) {
		return new WP_Error( 'not_found', 'Ismeretlen szolgáltatás', array( 'status' => 404 ) );
	}
	$offered = lif_group_pastors_for_service( $group, $service->id );
	$pastor  = (string) $req->get_param( 'pastor' );
	$ids     = ( '' !== $pastor && 'any' !== $pastor ) ? array_values( array_intersect( $offered, array( (int) $pastor ) ) ) : $offered;

	$now   = lif_now();
	$today = $now->format( 'Y-m-d' );
	$from  = preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $req->get_param( 'from' ) ) ? $req->get_param( 'from' ) : $today;
	$days  = min( max( (int) $req->get_param( 'days' ) ?: 7, 1 ), 31 );
	$to    = ( new DateTimeImmutable( $from ) )->modify( '+' . ( $days - 1 ) . ' days' )->format( 'Y-m-d' );
	$slots = $ids ? lif_get_slots( $service, $ids, $from, $to ) : array();
	$max   = $now->modify( '+' . max( 1, (int) lif_setting( 'max_days_ahead' ) ) . ' days' )->format( 'Y-m-d' );

	$out = array();
	foreach ( lif_date_range( $from, $to ) as $date ) {
		$ts    = ( new DateTimeImmutable( $date, lif_tz() ) )->getTimestamp() + 12 * 3600;
		$items = array();
		foreach ( isset( $slots[ $date ] ) ? array_keys( $slots[ $date ] ) : array() as $start ) {
			$items[] = array( 'start' => lif_iso( $start ), 'label' => lif_wdate( 'H:i', $start ) );
		}
		$out[] = array(
			'date'    => $date,
			'weekday' => lif_wdate( 'l', $ts ),
			'label'   => lif_wdate( 'M j.', $ts ),
			'slots'   => $items,
		);
	}
	$res = rest_ensure_response( array( 'from' => $from, 'to' => $to, 'today' => $today, 'maxDay' => $max, 'days' => $out ) );
	$res->header( 'Cache-Control', 'no-store' );
	return $res;
}
