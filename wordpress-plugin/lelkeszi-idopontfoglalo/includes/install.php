<?php
/**
 * Telepítés: adatbázistáblák, szerepkörök, alapbeállítások.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function lif_tables() {
	global $wpdb;
	$p = $wpdb->prefix . 'lif_';
	return array(
		'services'          => $p . 'services',
		'pastor_services'   => $p . 'pastor_services',
		'availability'      => $p . 'availability',
		'timeoff'           => $p . 'timeoff',
		'groups'            => $p . 'groups',
		'group_members'     => $p . 'group_members',
		'bookings'          => $p . 'bookings',
		'invites'           => $p . 'invites',
		'google_accounts'   => $p . 'google_accounts',
		'google_calendars'  => $p . 'google_calendars',
		'blocks'            => $p . 'blocks',
	);
}

function lif_t( $name ) {
	$t = lif_tables();
	return $t[ $name ];
}

function lif_install_tables() {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$c = $wpdb->get_charset_collate();
	$t = lif_tables();

	dbDelta( "CREATE TABLE {$t['services']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(190) NOT NULL,
  slug varchar(190) NOT NULL,
  description text NULL,
  duration_min int(11) NOT NULL DEFAULT 60,
  buffer_min int(11) NOT NULL DEFAULT 0,
  location varchar(190) NOT NULL DEFAULT '',
  color varchar(7) NOT NULL DEFAULT '#3d5a66',
  active tinyint(1) NOT NULL DEFAULT 1,
  sort int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY slug (slug)
) $c;" );

	dbDelta( "CREATE TABLE {$t['pastor_services']} (
  user_id bigint(20) unsigned NOT NULL,
  service_id bigint(20) unsigned NOT NULL,
  PRIMARY KEY  (user_id,service_id)
) $c;" );

	dbDelta( "CREATE TABLE {$t['availability']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  weekday tinyint(1) NOT NULL,
  start_time char(5) NOT NULL,
  end_time char(5) NOT NULL,
  PRIMARY KEY  (id),
  KEY user_id (user_id)
) $c;" );

	dbDelta( "CREATE TABLE {$t['timeoff']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  start_date char(10) NOT NULL,
  end_date char(10) NOT NULL,
  note varchar(190) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  KEY user_id (user_id)
) $c;" );

	dbDelta( "CREATE TABLE {$t['groups']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(190) NOT NULL,
  slug varchar(190) NOT NULL,
  kind varchar(20) NOT NULL DEFAULT 'gyulekezet',
  description text NULL,
  active tinyint(1) NOT NULL DEFAULT 1,
  created_by bigint(20) unsigned NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY slug (slug)
) $c;" );

	dbDelta( "CREATE TABLE {$t['group_members']} (
  group_id bigint(20) unsigned NOT NULL,
  user_id bigint(20) unsigned NOT NULL,
  PRIMARY KEY  (group_id,user_id)
) $c;" );

	dbDelta( "CREATE TABLE {$t['bookings']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  service_id bigint(20) unsigned NOT NULL,
  user_id bigint(20) unsigned NOT NULL,
  group_id bigint(20) unsigned NULL,
  client_id bigint(20) unsigned NULL,
  start_utc varchar(25) NOT NULL,
  end_utc varchar(25) NOT NULL,
  client_name varchar(190) NOT NULL,
  client_email varchar(190) NOT NULL,
  client_phone varchar(50) NOT NULL DEFAULT '',
  note text NULL,
  status varchar(20) NOT NULL DEFAULT 'confirmed',
  token varchar(64) NOT NULL,
  google_account_id bigint(20) unsigned NULL,
  google_calendar_id varchar(255) NULL,
  google_event_id varchar(255) NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY token (token),
  KEY user_start (user_id,start_utc),
  KEY client_id (client_id)
) $c;" );

	dbDelta( "CREATE TABLE {$t['invites']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  token_hash char(64) NOT NULL,
  email varchar(190) NOT NULL,
  is_admin tinyint(1) NOT NULL DEFAULT 0,
  congregation varchar(190) NOT NULL DEFAULT '',
  group_ids text NULL,
  user_id bigint(20) unsigned NULL,
  created_by bigint(20) unsigned NULL,
  expires_at bigint(20) NOT NULL,
  used_at datetime NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY token_hash (token_hash)
) $c;" );

	dbDelta( "CREATE TABLE {$t['google_accounts']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  email varchar(190) NOT NULL,
  refresh_token text NULL,
  access_token text NULL,
  token_expiry bigint(20) NULL,
  last_error text NULL,
  PRIMARY KEY  (id),
  KEY user_id (user_id)
) $c;" );

	dbDelta( "CREATE TABLE {$t['blocks']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  start_utc varchar(25) NOT NULL,
  end_utc varchar(25) NOT NULL,
  note varchar(190) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  KEY user_start (user_id,start_utc)
) $c;" );

	dbDelta( "CREATE TABLE {$t['google_calendars']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  account_id bigint(20) unsigned NOT NULL,
  calendar_id varchar(255) NOT NULL,
  summary varchar(255) NOT NULL,
  is_primary tinyint(1) NOT NULL DEFAULT 0,
  can_write tinyint(1) NOT NULL DEFAULT 0,
  check_busy tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY account_id (account_id)
) $c;" );
}

/**
 * Szerepkörök:
 * - lif_pastor: lelkész (csak a saját időpontfoglaló felületét látja a WordPress adminban)
 * - lif_client: időpontkérő (nem lép be az adminba, csak a nyilvános fiókoldalt használja)
 * Jogosultságok: lif_pastor (lelkészi felület), lif_admin (lelkészek, szolgáltatások, beállítások kezelése).
 */
function lif_install_roles() {
	add_role( 'lif_pastor', 'Lelkész', array( 'read' => true, 'lif_pastor' => true ) );
	add_role( 'lif_client', 'Időpontkérő', array( 'read' => true ) );
	$admin = get_role( 'administrator' );
	if ( $admin ) {
		$admin->add_cap( 'lif_pastor' );
		$admin->add_cap( 'lif_admin' );
	}
}

function lif_activate() {
	lif_install_tables();
	lif_install_roles();
	update_option( 'lif_db_version', LIF_DB_VERSION );
	if ( ! get_option( 'lif_settings' ) ) {
		add_option( 'lif_settings', lif_default_settings() );
		lif_seed_examples();
	}
	lif_register_rewrites();
	flush_rewrite_rules();
}

function lif_deactivate() {
	flush_rewrite_rules();
}

function lif_maybe_upgrade() {
	if ( (int) get_option( 'lif_db_version' ) < LIF_DB_VERSION ) {
		lif_install_tables();
		lif_install_roles();
		update_option( 'lif_db_version', LIF_DB_VERSION );
	}
}

/** Első aktiváláskor néhány példa szolgáltatás, hogy legyen mivel kezdeni. */
function lif_seed_examples() {
	global $wpdb;
	if ( $wpdb->get_var( 'SELECT COUNT(*) FROM ' . lif_t( 'services' ) ) ) {
		return;
	}
	$examples = array(
		array( 'Lelkigondozói beszélgetés', 'Személyes beszélgetés lelki kérdésekről, nehézségekről.', 60, '#3d5a66' ),
		array( 'Keresztelő előtti beszélgetés', 'Felkészítő beszélgetés a keresztelés előtt.', 60, '#6a5a2e' ),
		array( 'Esküvői jegyesoktatás', 'Felkészítő alkalom házasságkötés előtt.', 90, '#7a3f5c' ),
		array( 'Temetés egyeztetése', 'A temetési szertartás részleteinek megbeszélése.', 45, '#4b5560' ),
	);
	foreach ( $examples as $i => $e ) {
		$wpdb->insert( lif_t( 'services' ), array(
			'name'         => $e[0],
			'slug'         => lif_slugify( $e[0] ),
			'description'  => $e[1],
			'duration_min' => $e[2],
			'buffer_min'   => 15,
			'location'     => 'Lelkészi hivatal',
			'color'        => $e[3],
			'active'       => 1,
			'sort'         => $i,
		) );
	}
}
