<?php
/**
 * Lelkészi felület a WordPress adminban („Időpontfoglaló” menü).
 * Nyilvános oldalon nincs rá link; regisztrálni csak meghívóval lehet.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'admin_menu', function () {
	add_menu_page( 'Időpontfoglaló', 'Időpontfoglaló', 'lif_pastor', 'lif', 'lif_page_bookings', 'dashicons-calendar-alt', 3 );
	add_submenu_page( 'lif', 'Foglalások', 'Foglalások', 'lif_pastor', 'lif', 'lif_page_bookings' );
	add_submenu_page( 'lif', 'Elérhetőség', 'Elérhetőség', 'lif_pastor', 'lif-availability', 'lif_page_availability' );
	add_submenu_page( 'lif', 'Google naptár', 'Google naptár', 'lif_pastor', 'lif-google', 'lif_page_google' );
	add_submenu_page( 'lif', 'Szolgáltatások', 'Szolgáltatások', 'lif_pastor', 'lif-services', 'lif_page_services' );
	add_submenu_page( 'lif', 'Csoportok', 'Csoportok', 'lif_pastor', 'lif-groups', 'lif_page_groups' );
	add_submenu_page( 'lif', 'Lelkészek', 'Lelkészek', 'lif_admin', 'lif-pastors', 'lif_page_pastors' );
	add_submenu_page( 'lif', 'Beállítások', 'Beállítások', 'lif_admin', 'lif-settings', 'lif_page_settings' );
	add_submenu_page( 'lif', 'Profilom', 'Profilom', 'lif_pastor', 'lif-profile', 'lif_page_profile' );
	if ( lif_is_pastor_only() ) {
		remove_menu_page( 'index.php' );
	}
} );

/** Csak lelkész (nem szerkesztő/adminisztrátor): neki az Időpontfoglaló a kezdőlap. */
function lif_is_pastor_only() {
	$u = wp_get_current_user();
	return $u->exists() && $u->has_cap( 'lif_pastor' ) && ! $u->has_cap( 'edit_posts' );
}
add_action( 'load-index.php', function () {
	if ( lif_is_pastor_only() ) {
		wp_safe_redirect( admin_url( 'admin.php?page=lif' ) );
		exit;
	}
} );

add_action( 'admin_enqueue_scripts', function ( $hook ) {
	if ( false !== strpos( (string) $hook, 'lif' ) ) {
		wp_enqueue_style( 'lif-admin', LIF_URL . 'assets/admin.css', array(), LIF_VERSION );
		wp_enqueue_script( 'lif-front', LIF_URL . 'assets/front.js', array(), LIF_VERSION, true );
	}
} );

/* ---------- Közös elemek ---------- */

function lif_admin_url( $page, $args = array() ) {
	return add_query_arg( $args, admin_url( 'admin.php?page=' . $page ) );
}

function lif_admin_header( $title, $actions = '' ) {
	$user = wp_get_current_user();
	echo '<div class="wrap lif-admin">';
	echo '<div class="lif-head"><h1 class="wp-heading-inline">' . esc_html( $title ) . '</h1>' . $actions; // phpcs:ignore
	echo '<span class="lif-user">' . esc_html( $user->display_name ) . ' · <a class="button" href="' . esc_url( wp_logout_url( wp_login_url() ) ) . '">Kijelentkezés</a></span></div>';
	if ( ! empty( $_GET['lif_msg'] ) ) {
		$type = ! empty( $_GET['lif_err'] ) ? 'error' : 'success';
		echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible"><p>' . esc_html( wp_unslash( $_GET['lif_msg'] ) ) . '</p></div>';
	}
	if ( ! empty( $_GET['lif_welcome'] ) ) {
		echo '<div class="notice notice-info"><p>Üdvözöljük! Javasoljuk, hogy a <a href="' . esc_url( lif_admin_url( 'lif-profile' ) ) . '">Profilom</a> oldalon kapcsolja be a kétfaktoros hitelesítést, és kösse be a Google naptárát.</p></div>';
	}
}

function lif_admin_footer() {
	echo '</div>';
}

function lif_admin_form_open( $do, $extra = '' ) {
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" ' . $extra . '>'; // phpcs:ignore
	echo '<input type="hidden" name="action" value="lif"><input type="hidden" name="do" value="' . esc_attr( $do ) . '">';
	wp_nonce_field( 'lif_admin', '_lifn' );
}

function lif_back( $page, $msg, $args = array(), $error = false ) {
	$args['lif_msg'] = rawurlencode( $msg );
	if ( $error ) {
		$args['lif_err'] = 1;
	}
	wp_safe_redirect( lif_admin_url( $page, $args ) );
	exit;
}

function lif_can_manage_pastor( $pastor_id ) {
	return current_user_can( 'lif_admin' ) || get_current_user_id() === (int) $pastor_id;
}

function lif_can_edit_group( $group ) {
	return current_user_can( 'lif_admin' ) || lif_is_group_member( $group->id, get_current_user_id() );
}

/* ---------- 1. Foglalások ---------- */

function lif_page_bookings() {
	global $wpdb;
	$me   = get_current_user_id();
	$all  = current_user_can( 'lif_admin' ) && ! empty( $_GET['mind'] );
	$past = ! empty( $_GET['korabbi'] );
	$now  = lif_iso( time() );
	$sql  = 'SELECT b.*, s.name AS service_name FROM ' . lif_t( 'bookings' ) . ' b JOIN ' . lif_t( 'services' ) . ' s ON s.id = b.service_id WHERE '
		. ( $all ? '1=1' : $wpdb->prepare( 'b.user_id = %d', $me ) )
		. $wpdb->prepare( $past ? ' AND b.start_utc < %s ORDER BY b.start_utc DESC' : ' AND b.start_utc >= %s ORDER BY b.start_utc ASC', $now ) . ' LIMIT 200';
	$rows = $wpdb->get_results( $sql ); // phpcs:ignore

	lif_admin_header( 'Foglalások' );
	$todo = array();
	if ( lif_google_configured() && ! $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . lif_t( 'google_accounts' ) . ' WHERE user_id = %d', $me ) ) ) {
		$todo[] = '<a href="' . esc_url( lif_admin_url( 'lif-google' ) ) . '">Kösse be a Google naptárát</a>, hogy a foglalások bekerüljenek, és a meglévő programjai ne legyenek foglalhatók.';
	}
	if ( ! $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . lif_t( 'group_members' ) . ' WHERE user_id = %d', $me ) ) ) {
		$todo[] = 'Még nem tagja egy csoportnak sem, ezért Önhöz nem lehet foglalni. <a href="' . esc_url( lif_admin_url( 'lif-groups' ) ) . '">Csoportok</a>';
	}
	if ( ! lif_2fa_enabled( $me ) ) {
		$todo[] = 'Ajánlott: <a href="' . esc_url( lif_url( '/fiok/2fa' ) ) . '">kapcsolja be a kétfaktoros hitelesítést</a>.';
	}
	if ( $todo ) {
		echo '<div class="lif-card lif-todo"><strong>Teendők</strong><ul><li>' . implode( '</li><li>', $todo ) . '</li></ul></div>'; // phpcs:ignore
	}
	$base = array( 'mind' => $all ? 1 : null );
	echo '<ul class="subsubsub">';
	echo '<li><a class="' . ( ! $past ? 'current' : '' ) . '" href="' . esc_url( lif_admin_url( 'lif', $base ) ) . '">Közelgő</a> | </li>';
	echo '<li><a class="' . ( $past ? 'current' : '' ) . '" href="' . esc_url( lif_admin_url( 'lif', array_merge( $base, array( 'korabbi' => 1 ) ) ) ) . '">Korábbi</a></li>';
	if ( current_user_can( 'lif_admin' ) ) {
		echo '<li> | <a href="' . esc_url( lif_admin_url( 'lif', array( 'korabbi' => $past ? 1 : null, 'mind' => $all ? null : 1 ) ) ) . '">' . ( $all ? 'Csak az enyéim' : 'Minden lelkész' ) . '</a></li>';
	}
	echo '</ul><br class="clear">';

	if ( ! $rows ) {
		echo '<p class="description">Nincs megjeleníthető foglalás.</p>';
		return lif_admin_footer();
	}
	echo '<table class="widefat striped"><thead><tr><th>Időpont</th><th>Szolgáltatás</th>' . ( $all ? '<th>Lelkész</th>' : '' ) . '<th>Foglaló</th><th>Állapot</th><th></th></tr></thead><tbody>';
	foreach ( $rows as $b ) {
		$pastor = get_userdata( $b->user_id );
		echo '<tr' . ( 'cancelled' === $b->status ? ' class="lif-off"' : '' ) . '>';
		echo '<td>' . esc_html( lif_format_range( $b->start_utc, $b->end_utc ) ) . '</td>';
		echo '<td>' . esc_html( $b->service_name ) . '</td>';
		if ( $all ) {
			echo '<td>' . esc_html( $pastor ? $pastor->display_name : '–' ) . '</td>';
		}
		echo '<td><strong>' . esc_html( $b->client_name ) . '</strong><br>' . esc_html( $b->client_email ) . ( $b->client_phone ? '<br>' . esc_html( $b->client_phone ) : '' );
		if ( $b->note ) {
			echo '<div class="lif-note">' . esc_html( $b->note ) . '</div>';
		}
		echo '</td><td>' . ( 'confirmed' === $b->status ? 'Visszaigazolt' : 'Lemondva' ) . ( $b->google_event_id ? '<br><small>Google naptárban</small>' : '' ) . '</td><td>';
		if ( 'confirmed' === $b->status && ! $past ) {
			lif_admin_form_open( 'cancel_booking', 'onsubmit="return confirm(\'Biztosan lemondja? A foglaló értesítést kap.\')"' );
			echo '<input type="hidden" name="id" value="' . (int) $b->id . '"><button class="button button-link-delete">Lemondás</button></form>';
		}
		echo '</td></tr>';
	}
	echo '</tbody></table>';
	lif_admin_footer();
}

/* ---------- 2. Elérhetőség ---------- */

function lif_page_availability() {
	global $wpdb;
	$uid = isset( $_GET['pastor'] ) && current_user_can( 'lif_admin' ) ? (int) $_GET['pastor'] : get_current_user_id();
	$who = get_userdata( $uid );
	lif_admin_header( 'Elérhetőség' . ( get_current_user_id() !== $uid && $who ? ' – ' . $who->display_name : '' ) );
	$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . lif_t( 'availability' ) . ' WHERE user_id = %d ORDER BY weekday, start_time', $uid ) );
	echo '<p class="description">Adja meg, mely időszakokban foglalhatók Önnél időpontok. Több időszakot vesszővel válasszon el, pl. <code>09:00-12:00, 14:00-17:30</code>. Üresen hagyott nap = nem foglalható. A Google naptárában lévő foglalt időszakokat a rendszer automatikusan kihagyja.</p>';
	lif_admin_form_open( 'save_availability' );
	echo '<input type="hidden" name="pastor" value="' . (int) $uid . '"><table class="form-table" role="presentation">';
	foreach ( LIF_WEEKDAYS as $d => $label ) {
		$val = implode( ', ', array_map( function ( $r ) {
			return $r->start_time . '-' . $r->end_time;
		}, array_filter( $rows, function ( $r ) use ( $d ) {
			return (int) $r->weekday === $d;
		} ) ) );
		echo '<tr><th scope="row"><label for="lif-d' . $d . '">' . esc_html( $label ) . '</label></th><td><input class="regular-text" id="lif-d' . $d . '" name="day' . $d . '" value="' . esc_attr( $val ) . '" placeholder="pl. 09:00-12:00, 14:00-17:00"></td></tr>';
	}
	echo '</table>';
	submit_button( 'Mentés' );
	echo '</form>';

	echo '<h2>Távollétek, szabadság</h2>';
	lif_admin_form_open( 'add_timeoff', 'class="lif-inline-form"' );
	echo '<input type="hidden" name="pastor" value="' . (int) $uid . '">';
	echo '<label>Kezdete <input type="date" name="start_date" required></label> <label>Vége <input type="date" name="end_date"></label> <label>Megjegyzés <input name="note" placeholder="pl. szabadság"></label> ';
	submit_button( 'Hozzáadás', 'secondary', 'submit', false );
	echo '</form>';
	$off = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . lif_t( 'timeoff' ) . ' WHERE user_id = %d AND end_date >= %s ORDER BY start_date', $uid, lif_now()->format( 'Y-m-d' ) ) );
	if ( $off ) {
		echo '<ul class="lif-list">';
		foreach ( $off as $t ) {
			echo '<li><span>' . esc_html( $t->start_date . ( $t->end_date !== $t->start_date ? ' – ' . $t->end_date : '' ) ) . ' <em>' . esc_html( $t->note ) . '</em></span>';
			lif_admin_form_open( 'delete_timeoff', 'class="lif-inline"' );
			echo '<input type="hidden" name="id" value="' . (int) $t->id . '"><button class="button-link button-link-delete">Törlés</button></form></li>';
		}
		echo '</ul>';
	} else {
		echo '<p class="description">Nincs rögzített távollét.</p>';
	}
	lif_admin_footer();
}

/* ---------- 3. Google naptár ---------- */

function lif_page_google() {
	global $wpdb;
	$me = get_current_user_id();
	lif_admin_header( 'Google naptár' );
	if ( ! lif_google_configured() ) {
		echo '<div class="notice notice-warning inline"><p>A Google integráció még nincs beállítva. Az adminisztrátor a <a href="' . esc_url( lif_admin_url( 'lif-settings' ) ) . '">Beállítások</a> oldalon adhatja meg a Google OAuth azonosítót. Engedélyezett átirányítási URI: <code>' . esc_html( lif_google_redirect_uri() ) . '</code></p></div>';
		return lif_admin_footer();
	}
	echo '<p class="description">Csatlakoztassa egy vagy több saját Google fiókját (pl. személyes és gyülekezeti). Jelölje be, mely naptárak foglalt időszakait vegye figyelembe a rendszer, és válassza ki, melyik naptárba kerüljenek az új foglalások.</p>';
	lif_admin_form_open( 'google_connect', 'class="lif-inline"' );
	submit_button( '+ Google fiók csatlakoztatása', 'primary', 'submit', false );
	echo '</form>';
	$accounts = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . lif_t( 'google_accounts' ) . ' WHERE user_id = %d ORDER BY email', $me ) );
	if ( ! $accounts ) {
		echo '<p>Még nincs csatlakoztatott Google fiók.</p>';
		return lif_admin_footer();
	}
	$target_acc = (int) get_user_meta( $me, 'lif_target_account', true );
	$target_cal = (string) get_user_meta( $me, 'lif_target_calendar', true );
	lif_admin_form_open( 'google_save' );
	foreach ( $accounts as $a ) {
		$cals = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . lif_t( 'google_calendars' ) . ' WHERE account_id = %d ORDER BY is_primary DESC, summary', $a->id ) );
		echo '<div class="lif-card"><h2>' . esc_html( $a->email ) . '</h2>';
		if ( $a->last_error ) {
			echo '<div class="notice notice-error inline"><p>Utolsó hiba: ' . esc_html( $a->last_error ) . ' – szükség esetén csatlakoztassa újra a fiókot.</p></div>';
		}
		echo '<table class="widefat striped"><thead><tr><th>Naptár</th><th>Foglaltság figyelése</th><th>Foglalások ide</th></tr></thead><tbody>';
		foreach ( $cals as $c ) {
			echo '<tr><td>' . esc_html( $c->summary ) . ( $c->is_primary ? ' <small>(elsődleges)</small>' : '' ) . '</td>';
			echo '<td><input type="checkbox" name="busy[]" value="' . (int) $c->id . '" ' . checked( $c->check_busy, 1, false ) . '></td><td>';
			echo $c->can_write ? '<input type="radio" name="target" value="' . (int) $c->id . '" ' . checked( $target_acc === (int) $a->id && $target_cal === $c->calendar_id, true, false ) . '>' : '<small>csak olvasható</small>';
			echo '</td></tr>';
		}
		echo '</tbody></table><p><button class="button" name="refresh" value="' . (int) $a->id . '">Lista frissítése</button> <button class="button button-link-delete" name="disconnect" value="' . (int) $a->id . '" onclick="return confirm(\'Leválasztja ezt a Google fiókot?\')">Leválasztás</button></p></div>';
	}
	echo '<p><label><input type="radio" name="target" value="none" ' . checked( '' === $target_cal, true, false ) . '> Ne kerüljenek a foglalások Google naptárba</label></p>';
	submit_button( 'Mentés' );
	echo '</form>';
	lif_admin_footer();
}

/* ---------- 4. Szolgáltatások ---------- */

function lif_page_services() {
	global $wpdb;
	if ( isset( $_GET['edit'] ) && current_user_can( 'lif_admin' ) ) {
		return lif_page_service_edit( 'new' === $_GET['edit'] ? null : (int) $_GET['edit'] );
	}
	$me       = get_current_user_id();
	$services = $wpdb->get_results( 'SELECT * FROM ' . lif_t( 'services' ) . ' ORDER BY sort, name' );
	$mine     = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT service_id FROM ' . lif_t( 'pastor_services' ) . ' WHERE user_id = %d', $me ) ) );
	lif_admin_header( 'Szolgáltatások', current_user_can( 'lif_admin' ) ? ' <a class="page-title-action" href="' . esc_url( lif_admin_url( 'lif-services', array( 'edit' => 'new' ) ) ) . '">Új szolgáltatás</a>' : '' );
	echo '<p class="description">Jelölje be, mely szolgáltatásokra lehet Önhöz időpontot foglalni.</p>';
	lif_admin_form_open( 'my_services' );
	echo '<div class="lif-grid">';
	foreach ( $services as $s ) {
		$names = array_filter( array_map( function ( $id ) {
			$u = get_userdata( $id );
			return $u ? $u->display_name : '';
		}, lif_pastor_ids_for_service( $s->id ) ) );
		echo '<div class="lif-card lif-svc' . ( $s->active ? '' : ' lif-off' ) . '" style="--accent:' . esc_attr( $s->color ) . '">';
		echo '<label><input type="checkbox" name="services[]" value="' . (int) $s->id . '" ' . checked( in_array( (int) $s->id, $mine, true ), true, false ) . '> <strong>' . esc_html( $s->name ) . '</strong></label>';
		echo '<p>' . (int) $s->duration_min . ' perc' . ( $s->buffer_min ? ' + ' . (int) $s->buffer_min . ' perc szünet' : '' ) . ( $s->location ? ' · ' . esc_html( $s->location ) : '' ) . ( $s->active ? '' : ' · <em>inaktív</em>' ) . '</p>';
		echo '<p class="description">Lelkészek: ' . esc_html( $names ? implode( ', ', $names ) : '–' ) . '</p>';
		if ( current_user_can( 'lif_admin' ) ) {
			echo '<a href="' . esc_url( lif_admin_url( 'lif-services', array( 'edit' => $s->id ) ) ) . '">Szerkesztés</a>';
		}
		echo '</div>';
	}
	echo '</div>';
	submit_button( 'Mentés' );
	echo '</form>';
	lif_admin_footer();
}

function lif_page_service_edit( $id ) {
	global $wpdb;
	$s = $id ? lif_service( $id ) : (object) array( 'id' => 0, 'name' => '', 'slug' => '', 'description' => '', 'duration_min' => 60, 'buffer_min' => 15, 'location' => '', 'color' => '#3d5a66', 'active' => 1, 'sort' => 0 );
	$assigned = $id ? array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT user_id FROM ' . lif_t( 'pastor_services' ) . ' WHERE service_id = %d', $id ) ) ) : array();
	lif_admin_header( $id ? 'Szolgáltatás szerkesztése' : 'Új szolgáltatás' );
	echo '<p><a href="' . esc_url( lif_admin_url( 'lif-services' ) ) . '">← Szolgáltatások</a></p>';
	lif_admin_form_open( 'save_service' );
	echo '<input type="hidden" name="id" value="' . (int) $s->id . '"><table class="form-table" role="presentation">';
	echo '<tr><th><label for="n">Név *</label></th><td><input id="n" class="regular-text" name="name" required value="' . esc_attr( $s->name ) . '"></td></tr>';
	echo '<tr><th><label for="d">Leírás</label></th><td><textarea id="d" class="large-text" rows="3" name="description">' . esc_textarea( $s->description ) . '</textarea></td></tr>';
	echo '<tr><th><label for="du">Időtartam (perc)</label></th><td><input id="du" type="number" min="5" max="600" name="duration_min" value="' . (int) $s->duration_min . '"></td></tr>';
	echo '<tr><th><label for="bu">Szünet előtte/utána (perc)</label></th><td><input id="bu" type="number" min="0" max="240" name="buffer_min" value="' . (int) $s->buffer_min . '"></td></tr>';
	echo '<tr><th><label for="lo">Helyszín</label></th><td><input id="lo" class="regular-text" name="location" value="' . esc_attr( $s->location ) . '" placeholder="pl. Lelkészi hivatal"></td></tr>';
	echo '<tr><th><label for="sl">URL-azonosító</label></th><td><input id="sl" class="regular-text" name="slug" value="' . esc_attr( $s->slug ) . '" placeholder="automatikus"></td></tr>';
	echo '<tr><th><label for="co">Szín</label></th><td><input id="co" type="color" name="color" value="' . esc_attr( $s->color ) . '"></td></tr>';
	echo '<tr><th><label for="so">Sorrend</label></th><td><input id="so" type="number" name="sort" value="' . (int) $s->sort . '"></td></tr>';
	echo '<tr><th>Állapot</th><td><label><input type="checkbox" name="active" ' . checked( $s->active, 1, false ) . '> Aktív (foglalható)</label></td></tr>';
	echo '<tr><th>Lelkészek</th><td>';
	foreach ( lif_pastors() as $p ) {
		echo '<label class="lif-chip"><input type="checkbox" name="pastors[]" value="' . (int) $p->ID . '" ' . checked( in_array( $p->ID, $assigned, true ), true, false ) . '> ' . esc_html( $p->display_name ) . '</label> ';
	}
	echo '</td></tr></table>';
	submit_button( 'Mentés' );
	echo '</form>';
	if ( $id ) {
		lif_admin_form_open( 'delete_service', 'onsubmit="return confirm(\'Biztosan törli a szolgáltatást?\')"' );
		echo '<input type="hidden" name="id" value="' . (int) $id . '"><button class="button button-link-delete">Szolgáltatás törlése</button></form>';
	}
	lif_admin_footer();
}

/* ---------- 5. Csoportok ---------- */

function lif_page_groups() {
	global $wpdb;
	if ( isset( $_GET['edit'] ) ) {
		return lif_page_group_edit( 'new' === $_GET['edit'] ? null : (int) $_GET['edit'] );
	}
	$me     = get_current_user_id();
	$groups = $wpdb->get_results( 'SELECT * FROM ' . lif_t( 'groups' ) . ' ORDER BY active DESC, name' );
	lif_admin_header( 'Csoportok', ' <a class="page-title-action" href="' . esc_url( lif_admin_url( 'lif-groups', array( 'edit' => 'new' ) ) ) . '">Új csoport</a>' );
	echo '<p class="description">Egy csoport (pl. gyülekezet vagy munkacsoport) saját foglalási linket kap. Aki ezt megnyitja, csak a csoport lelkészeinél foglalhat időpontot. Közös, mindenkit listázó felület nincs. A link véletlen végződése miatt kitalálni sem lehet.</p>';

	$existing = array_map( function ( $g ) {
		return mb_strtolower( $g->name );
	}, $groups );
	$congr    = array();
	foreach ( lif_pastors() as $p ) {
		$c = lif_pastor_meta( $p->ID, 'congregation' );
		if ( $c && ! in_array( mb_strtolower( $c ), $existing, true ) ) {
			$congr[ $c ] = ( $congr[ $c ] ?? 0 ) + 1;
		}
	}
	if ( $congr ) {
		echo '<div class="lif-card lif-todo"><strong>Csoport létrehozása gyülekezetből</strong><p class="description">Ezekhez a gyülekezetekhez még nincs csoport. Egy kattintással létrehozhatja, a gyülekezet lelkészei tagok lesznek.</p>';
		foreach ( $congr as $name => $n ) {
			lif_admin_form_open( 'group_from_congregation', 'class="lif-inline"' );
			echo '<input type="hidden" name="congregation" value="' . esc_attr( $name ) . '"><button class="button">+ ' . esc_html( $name ) . ' (' . (int) $n . ' lelkész)</button></form> ';
		}
		echo '</div>';
	}
	if ( ! $groups ) {
		echo '<p>Még nincs csoport.</p>';
		return lif_admin_footer();
	}
	echo '<div class="lif-grid">';
	foreach ( $groups as $i => $g ) {
		$members = array_filter( array_map( 'get_userdata', lif_group_member_ids( $g->id ) ) );
		$mine    = lif_is_group_member( $g->id, $me );
		$url     = lif_group_url( $g );
		echo '<div class="lif-card' . ( $g->active ? '' : ' lif-off' ) . '"><p class="lif-eyebrow">' . esc_html( lif_kind_label( $g->kind ) ) . ( $mine ? ' · tag vagyok' : '' ) . ( $g->active ? '' : ' · inaktív' ) . '</p>';
		echo '<h2>' . esc_html( $g->name ) . '</h2>';
		echo '<p class="description">Lelkészek: ' . esc_html( $members ? implode( ', ', wp_list_pluck( $members, 'display_name' ) ) : '–' ) . '</p>';
		if ( $g->active ) {
			echo '<p class="lif-share"><input readonly class="regular-text" id="gl-' . (int) $i . '" value="' . esc_attr( $url ) . '" onclick="this.select()"> <button type="button" class="button" data-copy="' . esc_attr( $url ) . '">Másolás</button></p>';
			echo '<a class="button" target="_blank" href="' . esc_url( $url ) . '">Megnyitás</a> ';
		}
		if ( lif_can_edit_group( $g ) ) {
			echo '<a class="button" href="' . esc_url( lif_admin_url( 'lif-groups', array( 'edit' => $g->id ) ) ) . '">Szerkesztés</a>';
		}
		echo '</div>';
	}
	echo '</div>';
	lif_admin_footer();
}

function lif_page_group_edit( $id ) {
	global $wpdb;
	$g = $id ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . lif_t( 'groups' ) . ' WHERE id = %d', $id ) ) : (object) array( 'id' => 0, 'name' => '', 'slug' => '', 'kind' => 'gyulekezet', 'description' => '', 'active' => 1 );
	if ( ! $g || ( $id && ! lif_can_edit_group( $g ) ) ) {
		wp_die( 'Ezt a csoportot csak a tagjai és az adminisztrátorok szerkeszthetik.' );
	}
	$assigned = $id ? lif_group_member_ids( $id ) : array( get_current_user_id() );
	lif_admin_header( $id ? 'Csoport szerkesztése' : 'Új csoport' );
	echo '<p><a href="' . esc_url( lif_admin_url( 'lif-groups' ) ) . '">← Csoportok</a></p>';
	if ( $id && $g->active ) {
		$url = lif_group_url( $g );
		echo '<div class="lif-card"><strong>Megosztható foglalási link</strong><p class="description">Ezen a linken csak a csoport lelkészeinek időpontjai foglalhatók.</p><p class="lif-share"><input readonly class="large-text" value="' . esc_attr( $url ) . '" onclick="this.select()"> <button type="button" class="button" data-copy="' . esc_attr( $url ) . '">Másolás</button></p></div>';
	}
	lif_admin_form_open( 'save_group' );
	echo '<input type="hidden" name="id" value="' . (int) $g->id . '"><table class="form-table" role="presentation">';
	echo '<tr><th><label for="gn">Név *</label></th><td><input id="gn" class="regular-text" name="name" required value="' . esc_attr( $g->name ) . '" placeholder="pl. Budavári Gyülekezet"></td></tr>';
	echo '<tr><th><label for="gk">Típus</label></th><td><select id="gk" name="kind">';
	foreach ( LIF_KINDS as $k => $l ) {
		echo '<option value="' . esc_attr( $k ) . '" ' . selected( $g->kind, $k, false ) . '>' . esc_html( $l ) . '</option>';
	}
	echo '</select></td></tr>';
	echo '<tr><th><label for="gs">Link-azonosító</label></th><td><input id="gs" class="regular-text" name="slug" value="' . esc_attr( $g->slug ) . '" placeholder="automatikus, véletlen végződéssel"><p class="description">A véletlen végződés megnehezíti a link kitalálását.</p></td></tr>';
	echo '<tr><th><label for="gd">Leírás</label></th><td><textarea id="gd" class="large-text" rows="3" name="description">' . esc_textarea( $g->description ) . '</textarea><p class="description">A csoport foglalóoldalán jelenik meg.</p></td></tr>';
	echo '<tr><th>Állapot</th><td><label><input type="checkbox" name="active" ' . checked( $g->active, 1, false ) . '> Aktív (a link működik)</label></td></tr>';
	echo '<tr><th>Lelkészek a csoportban</th><td>';
	foreach ( lif_pastors() as $p ) {
		$c = lif_pastor_meta( $p->ID, 'congregation' );
		echo '<label class="lif-chip"><input type="checkbox" name="members[]" value="' . (int) $p->ID . '" ' . checked( in_array( $p->ID, $assigned, true ), true, false ) . '> ' . esc_html( $p->display_name ) . ( $c ? ' <small>(' . esc_html( $c ) . ')</small>' : '' ) . '</label> ';
	}
	echo '</td></tr></table>';
	submit_button( 'Mentés' );
	echo '</form>';
	if ( $id ) {
		lif_admin_form_open( 'delete_group', 'onsubmit="return confirm(\'Biztosan törli a csoportot? A megosztott link ezután nem működik.\')"' );
		echo '<input type="hidden" name="id" value="' . (int) $id . '"><button class="button button-link-delete">Csoport törlése</button></form>';
	}
	lif_admin_footer();
}

/* ---------- 6. Lelkészek és meghívók (admin) ---------- */

function lif_page_pastors() {
	global $wpdb;
	lif_admin_header( 'Lelkészek' );
	$created = get_transient( 'lif_created_invite_' . get_current_user_id() );
	delete_transient( 'lif_created_invite_' . get_current_user_id() );
	if ( $created ) {
		echo '<div class="notice notice-success"><p>' . ( $created['reset'] ? 'Jelszó-visszaállító link' : 'Meghívó' ) . ' elkészült: <strong>' . esc_html( $created['email'] ) . '</strong> – ' . ( $created['mailed'] ? 'e-mailben is elküldtük.' : 'küldje el neki. A link 7 napig érvényes, egyszer használható, és csak most látható.' ) . '</p>';
		echo '<p class="lif-share"><input readonly class="large-text" id="invite-link" value="' . esc_attr( $created['link'] ) . '" onclick="this.select()"> <button type="button" class="button" data-copy="' . esc_attr( $created['link'] ) . '">Másolás</button></p></div>';
	}
	$groups = $wpdb->get_results( 'SELECT * FROM ' . lif_t( 'groups' ) . ' ORDER BY name' );
	echo '<table class="widefat striped"><thead><tr><th>Név</th><th>Gyülekezet</th><th>Csoportok</th><th>Szerepkör</th><th>Védelem</th><th></th></tr></thead><tbody>';
	foreach ( lif_pastors( true ) as $p ) {
		$inactive = (bool) get_user_meta( $p->ID, 'lif_inactive', true );
		$gnames   = array();
		foreach ( $groups as $g ) {
			if ( lif_is_group_member( $g->id, $p->ID ) ) {
				$gnames[] = $g->name;
			}
		}
		echo '<tr' . ( $inactive ? ' class="lif-off"' : '' ) . '><td><strong>' . esc_html( $p->display_name ) . '</strong><br><small>' . esc_html( $p->user_email ) . '</small></td><td>';
		lif_admin_form_open( 'pastor_congregation', 'class="lif-inline"' );
		echo '<input type="hidden" name="id" value="' . (int) $p->ID . '"><input name="congregation" list="lif-congr" value="' . esc_attr( lif_pastor_meta( $p->ID, 'congregation' ) ) . '" placeholder="Gyülekezet"> <button class="button button-small">Mentés</button></form></td>';
		echo '<td>' . esc_html( $gnames ? implode( ', ', $gnames ) : '–' ) . '</td>';
		echo '<td>' . ( $p->has_cap( 'lif_admin' ) ? 'Adminisztrátor' : 'Lelkész' ) . ( $inactive ? ' (inaktív)' : '' ) . '</td>';
		echo '<td>' . ( lif_2fa_enabled( $p->ID ) ? 'Kétfaktoros ✓' : 'Csak jelszó' ) . '</td><td class="lif-actions">';
		echo '<a class="button button-small" href="' . esc_url( lif_admin_url( 'lif-availability', array( 'pastor' => $p->ID ) ) ) . '">Elérhetőség</a> ';
		lif_admin_form_open( 'pastor_action', 'class="lif-inline"' );
		echo '<input type="hidden" name="id" value="' . (int) $p->ID . '">';
		echo '<button class="button button-small" name="what" value="reset">Jelszó-visszaállító link</button> ';
		if ( get_current_user_id() !== $p->ID && ! user_can( $p, 'manage_options' ) ) {
			if ( lif_2fa_enabled( $p->ID ) ) {
				echo '<button class="button button-small" name="what" value="reset2fa">2FA kikapcsolása</button> ';
			}
			echo '<button class="button button-small" name="what" value="admin">' . ( $p->has_cap( 'lif_admin' ) ? 'Admin jog elvétele' : 'Admin jog' ) . '</button> ';
			echo '<button class="button button-small" name="what" value="toggle">' . ( $inactive ? 'Aktiválás' : 'Inaktiválás' ) . '</button>';
		}
		echo '</form></td></tr>';
	}
	echo '</tbody></table>';
	$congrs = array_unique( array_filter( array_map( function ( $p ) {
		return lif_pastor_meta( $p->ID, 'congregation' );
	}, lif_pastors( true ) ) ) );
	echo '<datalist id="lif-congr">';
	foreach ( $congrs as $c ) {
		echo '<option value="' . esc_attr( $c ) . '">';
	}
	echo '</datalist>';

	echo '<h2>Lelkész meghívása</h2><p class="description">A lelkészi felületre csak meghívóval lehet regisztrálni. A meghívott a linken maga adja meg a nevét és a jelszavát.</p>';
	lif_admin_form_open( 'invite' );
	echo '<table class="form-table" role="presentation">';
	echo '<tr><th><label for="ie">E-mail cím *</label></th><td><input id="ie" type="email" class="regular-text" name="email" required></td></tr>';
	echo '<tr><th><label for="ic">Gyülekezet</label></th><td><input id="ic" class="regular-text" name="congregation" list="lif-congr"></td></tr>';
	echo '<tr><th>Szerepkör</th><td><label><input type="checkbox" name="is_admin"> Adminisztrátor (lelkészeket hívhat meg, szolgáltatásokat és beállításokat kezel)</label></td></tr>';
	if ( $groups ) {
		echo '<tr><th>Csoportok</th><td>';
		foreach ( $groups as $g ) {
			echo '<label class="lif-chip"><input type="checkbox" name="groups[]" value="' . (int) $g->id . '"> ' . esc_html( $g->name ) . '</label> ';
		}
		echo '</td></tr>';
	}
	echo '</table>';
	submit_button( 'Meghívó készítése' );
	echo '</form>';

	$invites = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . lif_t( 'invites' ) . ' WHERE used_at IS NULL AND expires_at > %d ORDER BY created_at DESC', time() ) );
	if ( $invites ) {
		echo '<h2>Függő meghívók</h2><ul class="lif-list">';
		foreach ( $invites as $i ) {
			echo '<li><span><strong>' . esc_html( $i->email ) . '</strong> <small>' . ( $i->user_id ? 'jelszó-visszaállítás' : ( $i->is_admin ? 'adminisztrátor' : 'lelkész' ) ) . ' · lejár: ' . esc_html( lif_wdate( 'M j. H:i', (int) $i->expires_at ) ) . '</small></span>';
			lif_admin_form_open( 'revoke_invite', 'class="lif-inline"' );
			echo '<input type="hidden" name="id" value="' . (int) $i->id . '"><button class="button-link button-link-delete">Visszavonás</button></form></li>';
		}
		echo '</ul>';
	}
	lif_admin_footer();
}

function lif_create_invite( $email, $is_admin, $congregation, $group_ids, $user_id = null ) {
	global $wpdb;
	$token = lif_random( 24 );
	$wpdb->query( $wpdb->prepare( 'UPDATE ' . lif_t( 'invites' ) . ' SET expires_at = 0 WHERE email = %s AND used_at IS NULL', $email ) );
	$wpdb->insert( lif_t( 'invites' ), array(
		'token_hash'   => hash( 'sha256', $token ),
		'email'        => $email,
		'is_admin'     => $is_admin ? 1 : 0,
		'congregation' => $congregation,
		'group_ids'    => wp_json_encode( array_map( 'intval', $group_ids ) ),
		'user_id'      => $user_id,
		'created_by'   => get_current_user_id(),
		'expires_at'   => time() + 7 * DAY_IN_SECONDS,
		'created_at'   => current_time( 'mysql', true ),
	) );
	return lif_url( '/meghivo/' . $token );
}

/* ---------- 7. Beállítások (admin) ---------- */

function lif_page_settings() {
	$s = lif_settings();
	lif_admin_header( 'Beállítások' );
	lif_admin_form_open( 'save_settings' );
	echo '<table class="form-table" role="presentation">';
	echo '<tr><th><label for="on">Gyülekezet / egyházközség neve</label></th><td><input id="on" class="regular-text" name="org_name" value="' . esc_attr( $s['org_name'] ) . '"></td></tr>';
	echo '<tr><th><label for="mn">Legalább ennyi órával előre</label></th><td><input id="mn" type="number" min="0" name="min_notice_hours" value="' . (int) $s['min_notice_hours'] . '"></td></tr>';
	echo '<tr><th><label for="md">Legfeljebb ennyi nappal előre</label></th><td><input id="md" type="number" min="1" max="365" name="max_days_ahead" value="' . (int) $s['max_days_ahead'] . '"></td></tr>';
	echo '<tr><th><label for="st">Időpontok lépésköze (perc)</label></th><td><input id="st" type="number" min="5" max="240" name="slot_step_min" value="' . (int) $s['slot_step_min'] . '"></td></tr>';
	echo '<tr><th>Időzóna</th><td>' . esc_html( wp_timezone_string() ) . ' <p class="description">A WordPress Beállítások → Általános oldalán módosítható.</p></td></tr>';
	echo '<tr><th>Meghívó a foglalónak</th><td><label><input type="checkbox" name="invite_client" ' . checked( ! empty( $s['invite_client'] ), true, false ) . '> A foglaló kapjon Google Naptár-meghívót (a Google küldi e-mailben)</label></td></tr>';
	echo '</table><h2>Google Naptár-összekapcsolás</h2>';
	echo '<p class="description">Hozzon létre egy OAuth-ügyfelet a Google Cloud Console-ban (Web application), engedélyezze a Google Calendar API-t, és adja meg ezt az átirányítási URI-t: <code>' . esc_html( lif_google_redirect_uri() ) . '</code></p>';
	echo '<table class="form-table" role="presentation">';
	if ( defined( 'LIF_GOOGLE_CLIENT_ID' ) ) {
		echo '<tr><th>Google azonosítók</th><td>A wp-config.php-ban vannak megadva.</td></tr>';
	} else {
		echo '<tr><th><label for="gi">Client ID</label></th><td><input id="gi" class="large-text" name="google_client_id" value="' . esc_attr( $s['google_client_id'] ) . '"></td></tr>';
		echo '<tr><th><label for="gs">Client secret</label></th><td><input id="gs" type="password" class="regular-text" name="google_client_secret" value="" placeholder="' . ( $s['google_client_secret'] ? '•••••• (megadva – üresen hagyva nem változik)' : '' ) . '"></td></tr>';
	}
	echo '</table>';
	submit_button( 'Mentés' );
	echo '</form>';
	echo '<h2>Biztonsági javaslat</h2><p class="description">A lelkészi felület a szokásos WordPress-belépésen (wp-login.php) érhető el. Ha ennek a címét is el szeretné rejteni, telepítse a „WPS Hide Login” bővítményt, és adjon meg egy egyedi belépési címet. A bővítmény oldalai nem kerülnek be a keresőkbe.</p>';
	lif_admin_footer();
}

/* ---------- 8. Profilom ---------- */

function lif_page_profile() {
	$u = wp_get_current_user();
	lif_admin_header( 'Profilom' );
	lif_admin_form_open( 'save_profile' );
	echo '<table class="form-table" role="presentation">';
	echo '<tr><th><label for="pn">Név</label></th><td><input id="pn" class="regular-text" name="name" required value="' . esc_attr( $u->display_name ) . '"></td></tr>';
	echo '<tr><th><label for="pt">Megnevezés</label></th><td><input id="pt" class="regular-text" name="title" value="' . esc_attr( lif_pastor_meta( $u->ID, 'title' ) ) . '" placeholder="pl. parókus lelkész"></td></tr>';
	echo '<tr><th><label for="pc">Gyülekezet</label></th><td><input id="pc" class="regular-text" name="congregation" value="' . esc_attr( lif_pastor_meta( $u->ID, 'congregation' ) ) . '"></td></tr>';
	echo '<tr><th><label for="pb">Rövid bemutatkozás</label></th><td><textarea id="pb" class="large-text" rows="4" name="bio">' . esc_textarea( $u->description ) . '</textarea></td></tr>';
	echo '</table>';
	submit_button( 'Mentés' );
	echo '</form>';
	echo '<div class="lif-card"><h2>Kétfaktoros hitelesítés</h2><p>' . ( lif_2fa_enabled( $u->ID ) ? 'Bekapcsolva ✓ – belépéskor a jelszó mellett a hitelesítő alkalmazás kódját is kérjük.' : 'Nincs bekapcsolva. Ajánlott: a jelszó mellé a telefonja is kell a belépéshez.' ) . '</p>';
	echo '<a class="button button-primary" href="' . esc_url( lif_url( '/fiok/2fa' ) ) . '">' . ( lif_2fa_enabled( $u->ID ) ? 'Kezelés' : 'Bekapcsolás' ) . '</a></div>';
	echo '<div class="lif-card"><h2>Jelszó és e-mail</h2><p><a class="button" href="' . esc_url( admin_url( 'profile.php' ) ) . '">Jelszó vagy e-mail módosítása</a></p></div>';
	lif_admin_footer();
}

/* ---------- Űrlapok feldolgozása ---------- */

add_action( 'admin_post_lif', function () {
	global $wpdb;
	if ( ! current_user_can( 'lif_pastor' ) ) {
		wp_die( 'Nincs jogosultsága.' );
	}
	check_admin_referer( 'lif_admin', '_lifn' );
	$me = get_current_user_id();
	$do = sanitize_key( $_POST['do'] ?? '' );
	$p  = function ( $k, $max = 500 ) {
		return isset( $_POST[ $k ] ) ? mb_substr( trim( sanitize_textarea_field( wp_unslash( $_POST[ $k ] ) ) ), 0, $max ) : '';
	};
	$ids = function ( $k ) {
		return array_map( 'intval', (array) ( $_POST[ $k ] ?? array() ) );
	};
	$need_admin = function () {
		if ( ! current_user_can( 'lif_admin' ) ) {
			wp_die( 'Ehhez adminisztrátori jog szükséges.' );
		}
	};

	switch ( $do ) {
		case 'cancel_booking':
			$b = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . lif_t( 'bookings' ) . ' WHERE id = %d', (int) $p( 'id' ) ) );
			if ( $b && lif_can_manage_pastor( $b->user_id ) ) {
				lif_cancel_booking( $b, 'pastor' );
			}
			lif_back( 'lif', 'A foglalást lemondta, a foglaló értesítést kap.' );

		case 'save_availability':
			$uid = (int) $p( 'pastor' );
			if ( ! lif_can_manage_pastor( $uid ) ) {
				wp_die( 'Nincs jogosultsága.' );
			}
			$parsed = array();
			for ( $d = 1; $d <= 7; $d++ ) {
				$r = lif_parse_ranges( $p( 'day' . $d ) );
				if ( is_wp_error( $r ) ) {
					lif_back( 'lif-availability', $r->get_error_message(), array( 'pastor' => $uid ), true );
				}
				$parsed[ $d ] = $r;
			}
			$wpdb->delete( lif_t( 'availability' ), array( 'user_id' => $uid ) );
			foreach ( $parsed as $d => $ranges ) {
				foreach ( $ranges as $r ) {
					$wpdb->insert( lif_t( 'availability' ), array( 'user_id' => $uid, 'weekday' => $d, 'start_time' => $r[0], 'end_time' => $r[1] ) );
				}
			}
			lif_back( 'lif-availability', 'Heti elérhetőség mentve.', array( 'pastor' => $uid ) );

		case 'add_timeoff':
			$uid  = (int) $p( 'pastor' );
			$from = $p( 'start_date', 10 );
			$to   = $p( 'end_date', 10 ) ? $p( 'end_date', 10 ) : $from;
			if ( ! lif_can_manage_pastor( $uid ) ) {
				wp_die( 'Nincs jogosultsága.' );
			}
			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) || $to < $from ) {
				lif_back( 'lif-availability', 'Adjon meg érvényes dátumokat (a vége nem lehet korábbi a kezdeténél).', array( 'pastor' => $uid ), true );
			}
			$wpdb->insert( lif_t( 'timeoff' ), array( 'user_id' => $uid, 'start_date' => $from, 'end_date' => $to, 'note' => $p( 'note', 190 ) ) );
			lif_back( 'lif-availability', 'Távollét rögzítve.', array( 'pastor' => $uid ) );

		case 'delete_timeoff':
			$t = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . lif_t( 'timeoff' ) . ' WHERE id = %d', (int) $p( 'id' ) ) );
			if ( $t && lif_can_manage_pastor( $t->user_id ) ) {
				$wpdb->delete( lif_t( 'timeoff' ), array( 'id' => $t->id ) );
			}
			lif_back( 'lif-availability', 'Távollét törölve.', array( 'pastor' => $t ? $t->user_id : $me ) );

		case 'google_connect':
			if ( ! lif_google_configured() ) {
				lif_back( 'lif-google', 'A Google integráció nincs beállítva.', array(), true );
			}
			$state = lif_random( 18 );
			update_user_meta( $me, 'lif_oauth_state', $state );
			wp_redirect( lif_google_auth_url( $state ) ); // phpcs:ignore -- külső cím (Google)
			exit;

		case 'google_save':
			$own = function ( $account_id ) use ( $wpdb, $me ) {
				return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . lif_t( 'google_accounts' ) . ' WHERE id = %d AND user_id = %d', $account_id, $me ) );
			};
			if ( ! empty( $_POST['refresh'] ) && $own( (int) $_POST['refresh'] ) ) {
				$r = lif_google_sync_calendars( (int) $_POST['refresh'] );
				lif_back( 'lif-google', is_wp_error( $r ) ? $r->get_error_message() : 'Naptárlista frissítve.', array(), is_wp_error( $r ) );
			}
			if ( ! empty( $_POST['disconnect'] ) && $own( (int) $_POST['disconnect'] ) ) {
				$aid = (int) $_POST['disconnect'];
				$acc = lif_google_account( $aid );
				$tok = lif_decrypt( $acc->refresh_token ) ? lif_decrypt( $acc->refresh_token ) : lif_decrypt( $acc->access_token );
				if ( $tok ) {
					wp_remote_post( 'https://oauth2.googleapis.com/revoke?token=' . rawurlencode( $tok ) );
				}
				$wpdb->delete( lif_t( 'google_calendars' ), array( 'account_id' => $aid ) );
				$wpdb->delete( lif_t( 'google_accounts' ), array( 'id' => $aid ) );
				if ( (int) get_user_meta( $me, 'lif_target_account', true ) === $aid ) {
					delete_user_meta( $me, 'lif_target_account' );
					delete_user_meta( $me, 'lif_target_calendar' );
				}
				lif_back( 'lif-google', 'Google fiók leválasztva.' );
			}
			$cals = $wpdb->get_results( $wpdb->prepare( 'SELECT c.* FROM ' . lif_t( 'google_calendars' ) . ' c JOIN ' . lif_t( 'google_accounts' ) . ' a ON a.id = c.account_id WHERE a.user_id = %d', $me ) );
			$busy = $ids( 'busy' );
			foreach ( $cals as $c ) {
				$wpdb->update( lif_t( 'google_calendars' ), array( 'check_busy' => in_array( (int) $c->id, $busy, true ) ? 1 : 0 ), array( 'id' => $c->id ) );
				if ( (string) $c->id === ( $_POST['target'] ?? '' ) && $c->can_write ) {
					update_user_meta( $me, 'lif_target_account', $c->account_id );
					update_user_meta( $me, 'lif_target_calendar', $c->calendar_id );
				}
			}
			if ( 'none' === ( $_POST['target'] ?? '' ) ) {
				delete_user_meta( $me, 'lif_target_account' );
				delete_user_meta( $me, 'lif_target_calendar' );
			}
			lif_google_flush_busy_cache();
			lif_back( 'lif-google', 'Naptárbeállítások mentve.' );

		case 'my_services':
			$wpdb->delete( lif_t( 'pastor_services' ), array( 'user_id' => $me ) );
			foreach ( $ids( 'services' ) as $sid ) {
				$wpdb->replace( lif_t( 'pastor_services' ), array( 'user_id' => $me, 'service_id' => $sid ) );
			}
			lif_back( 'lif-services', 'Az Ön által vállalt szolgáltatások mentve.' );

		case 'save_service':
			$need_admin();
			$id   = (int) $p( 'id' );
			$name = $p( 'name', 190 );
			if ( '' === $name ) {
				lif_back( 'lif-services', 'A név kötelező.', array( 'edit' => $id ? $id : 'new' ), true );
			}
			$slug = lif_slugify( $p( 'slug' ) ? $p( 'slug' ) : $name );
			if ( $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . lif_t( 'services' ) . ' WHERE slug = %s AND id != %d', $slug, $id ) ) ) {
				lif_back( 'lif-services', 'Ez az URL-azonosító már foglalt, válasszon másikat.', array( 'edit' => $id ? $id : 'new' ), true );
			}
			$data = array(
				'name'         => $name,
				'slug'         => $slug,
				'description'  => $p( 'description', 1000 ),
				'duration_min' => max( 5, min( 600, (int) $p( 'duration_min' ) ) ),
				'buffer_min'   => max( 0, min( 240, (int) $p( 'buffer_min' ) ) ),
				'location'     => $p( 'location', 190 ),
				'color'        => preg_match( '/^#[0-9a-f]{6}$/i', $p( 'color' ) ) ? $p( 'color' ) : '#3d5a66',
				'active'       => empty( $_POST['active'] ) ? 0 : 1,
				'sort'         => (int) $p( 'sort' ),
			);
			if ( $id ) {
				$wpdb->update( lif_t( 'services' ), $data, array( 'id' => $id ) );
			} else {
				$wpdb->insert( lif_t( 'services' ), $data );
				$id = (int) $wpdb->insert_id;
			}
			$wpdb->delete( lif_t( 'pastor_services' ), array( 'service_id' => $id ) );
			foreach ( $ids( 'pastors' ) as $uid ) {
				$wpdb->replace( lif_t( 'pastor_services' ), array( 'user_id' => $uid, 'service_id' => $id ) );
			}
			lif_back( 'lif-services', 'Szolgáltatás mentve.' );

		case 'delete_service':
			$need_admin();
			$id = (int) $p( 'id' );
			if ( $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . lif_t( 'bookings' ) . ' WHERE service_id = %d LIMIT 1', $id ) ) ) {
				$wpdb->update( lif_t( 'services' ), array( 'active' => 0 ), array( 'id' => $id ) );
				lif_back( 'lif-services', 'A szolgáltatáshoz tartoznak foglalások, ezért csak inaktiváltuk.' );
			}
			$wpdb->delete( lif_t( 'services' ), array( 'id' => $id ) );
			$wpdb->delete( lif_t( 'pastor_services' ), array( 'service_id' => $id ) );
			lif_back( 'lif-services', 'Szolgáltatás törölve.' );

		case 'group_from_congregation':
			$name    = $p( 'congregation', 190 );
			$members = array_filter( lif_pastors(), function ( $u ) use ( $name ) {
				return lif_pastor_meta( $u->ID, 'congregation' ) === $name;
			} );
			if ( '' === $name || ! $members ) {
				lif_back( 'lif-groups', 'Nincs ilyen gyülekezetű lelkész.', array(), true );
			}
			$wpdb->insert( lif_t( 'groups' ), array( 'name' => $name, 'slug' => lif_new_group_slug( $name ), 'kind' => 'gyulekezet', 'description' => '', 'active' => 1, 'created_by' => $me ) );
			$gid = (int) $wpdb->insert_id;
			foreach ( $members as $u ) {
				$wpdb->replace( lif_t( 'group_members' ), array( 'group_id' => $gid, 'user_id' => $u->ID ) );
			}
			lif_back( 'lif-groups', sprintf( '„%s” csoport létrehozva %d lelkésszel.', $name, count( $members ) ), array( 'edit' => $gid ) );

		case 'save_group':
			$id = (int) $p( 'id' );
			if ( $id ) {
				$g = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . lif_t( 'groups' ) . ' WHERE id = %d', $id ) );
				if ( ! $g || ! lif_can_edit_group( $g ) ) {
					wp_die( 'Nincs jogosultsága.' );
				}
			}
			$name = $p( 'name', 190 );
			if ( '' === $name ) {
				lif_back( 'lif-groups', 'A név kötelező.', array( 'edit' => $id ? $id : 'new' ), true );
			}
			$slug = $p( 'slug' ) ? lif_slugify( $p( 'slug' ) ) : ( $id ? $g->slug : lif_new_group_slug( $name ) );
			if ( in_array( $slug, LIF_RESERVED, true ) || $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . lif_t( 'groups' ) . ' WHERE slug = %s AND id != %d', $slug, $id ) ) ) {
				lif_back( 'lif-groups', 'Ez a link-azonosító már foglalt, válasszon másikat.', array( 'edit' => $id ? $id : 'new' ), true );
			}
			$kind = sanitize_key( $p( 'kind' ) );
			$data = array(
				'name'        => $name,
				'slug'        => $slug,
				'kind'        => array_key_exists( $kind, LIF_KINDS ) ? $kind : 'egyeb',
				'description' => $p( 'description', 1000 ),
				'active'      => empty( $_POST['active'] ) ? 0 : 1,
			);
			if ( $id ) {
				$wpdb->update( lif_t( 'groups' ), $data, array( 'id' => $id ) );
			} else {
				$wpdb->insert( lif_t( 'groups' ), array_merge( $data, array( 'created_by' => $me ) ) );
				$id = (int) $wpdb->insert_id;
			}
			$members = $ids( 'members' );
			// Nem admin lelkész nem zárhatja ki magát véletlenül (különben elveszítené a szerkesztési jogot).
			if ( ! current_user_can( 'lif_admin' ) && ! in_array( $me, $members, true ) ) {
				$members[] = $me;
			}
			$wpdb->delete( lif_t( 'group_members' ), array( 'group_id' => $id ) );
			foreach ( array_unique( $members ) as $uid ) {
				if ( lif_is_active_pastor( $uid ) ) {
					$wpdb->replace( lif_t( 'group_members' ), array( 'group_id' => $id, 'user_id' => $uid ) );
				}
			}
			lif_back( 'lif-groups', 'Csoport mentve.', array( 'edit' => $id ) );

		case 'delete_group':
			$g = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . lif_t( 'groups' ) . ' WHERE id = %d', (int) $p( 'id' ) ) );
			if ( $g && lif_can_edit_group( $g ) ) {
				$wpdb->delete( lif_t( 'groups' ), array( 'id' => $g->id ) );
				$wpdb->delete( lif_t( 'group_members' ), array( 'group_id' => $g->id ) );
			}
			lif_back( 'lif-groups', 'Csoport törölve. A megosztott link ezután nem működik.' );

		case 'invite':
			$need_admin();
			$email = sanitize_email( $p( 'email', 190 ) );
			if ( ! is_email( $email ) ) {
				lif_back( 'lif-pastors', 'Adjon meg érvényes e-mail címet.', array(), true );
			}
			if ( email_exists( $email ) ) {
				lif_back( 'lif-pastors', 'Ezzel az e-mail címmel már van fiók a weboldalon.', array(), true );
			}
			$link = lif_create_invite( $email, ! empty( $_POST['is_admin'] ), $p( 'congregation', 190 ), $ids( 'groups' ) );
			$org  = lif_setting( 'org_name' );
			$sent = wp_mail( $email, 'Meghívó – ' . $org . ' időpontfoglaló', "Meghívást kapott a(z) $org lelkészi időpontfoglaló felületére.\n\nA regisztrációhoz nyissa meg ezt a linket (7 napig érvényes, egyszer használható):\n$link\n" );
			set_transient( 'lif_created_invite_' . $me, array( 'email' => $email, 'link' => $link, 'mailed' => (bool) $sent, 'reset' => false ), 10 * MINUTE_IN_SECONDS );
			lif_back( 'lif-pastors', '' );

		case 'revoke_invite':
			$need_admin();
			$wpdb->update( lif_t( 'invites' ), array( 'expires_at' => 0 ), array( 'id' => (int) $p( 'id' ) ) );
			lif_back( 'lif-pastors', 'Meghívó visszavonva.' );

		case 'pastor_congregation':
			$need_admin();
			update_user_meta( (int) $p( 'id' ), 'lif_congregation', $p( 'congregation', 190 ) );
			lif_back( 'lif-pastors', 'Gyülekezet mentve.' );

		case 'pastor_action':
			$need_admin();
			$u = get_userdata( (int) $p( 'id' ) );
			if ( ! $u || ! $u->has_cap( 'lif_pastor' ) ) {
				lif_back( 'lif-pastors', 'Ismeretlen lelkész.', array(), true );
			}
			$what = sanitize_key( $p( 'what' ) );
			if ( 'reset' === $what ) {
				$link = lif_create_invite( $u->user_email, false, '', array(), $u->ID );
				set_transient( 'lif_created_invite_' . $me, array( 'email' => $u->user_email, 'link' => $link, 'mailed' => false, 'reset' => true ), 10 * MINUTE_IN_SECONDS );
				lif_back( 'lif-pastors', '' );
			}
			if ( $u->ID === $me || user_can( $u, 'manage_options' ) ) {
				lif_back( 'lif-pastors', 'Ezt a saját vagy a webhely-adminisztrátori fiókon nem lehet módosítani.', array(), true );
			}
			if ( 'reset2fa' === $what ) {
				lif_2fa_disable( $u->ID );
				lif_back( 'lif-pastors', $u->display_name . ' kétfaktoros hitelesítése kikapcsolva (pl. elveszett telefon esetén).' );
			}
			if ( 'admin' === $what ) {
				$u->has_cap( 'lif_admin' ) ? $u->remove_cap( 'lif_admin' ) : $u->add_cap( 'lif_admin' );
				lif_back( 'lif-pastors', 'Jogosultság módosítva.' );
			}
			if ( 'toggle' === $what ) {
				$inactive = (bool) get_user_meta( $u->ID, 'lif_inactive', true );
				$inactive ? delete_user_meta( $u->ID, 'lif_inactive' ) : update_user_meta( $u->ID, 'lif_inactive', 1 );
				lif_back( 'lif-pastors', $u->display_name . ( $inactive ? ' újra aktív.' : ' inaktiválva – hozzá nem lehet foglalni.' ) );
			}
			lif_back( 'lif-pastors', 'Ismeretlen művelet.', array(), true );

		case 'save_settings':
			$need_admin();
			$s                     = lif_settings();
			$s['org_name']         = $p( 'org_name', 190 ) ? $p( 'org_name', 190 ) : get_bloginfo( 'name' );
			$s['min_notice_hours'] = max( 0, (int) $p( 'min_notice_hours' ) );
			$s['max_days_ahead']   = max( 1, min( 365, (int) $p( 'max_days_ahead' ) ) );
			$s['slot_step_min']    = max( 5, min( 240, (int) $p( 'slot_step_min' ) ) );
			$s['invite_client']    = empty( $_POST['invite_client'] ) ? 0 : 1;
			if ( isset( $_POST['google_client_id'] ) ) {
				$s['google_client_id'] = $p( 'google_client_id', 300 );
			}
			if ( '' !== $p( 'google_client_secret', 300 ) ) {
				$s['google_client_secret'] = $p( 'google_client_secret', 300 );
			}
			update_option( 'lif_settings', $s );
			lif_back( 'lif-settings', 'Beállítások mentve.' );

		case 'save_profile':
			$name = $p( 'name', 190 );
			if ( '' === $name ) {
				lif_back( 'lif-profile', 'A név nem lehet üres.', array(), true );
			}
			wp_update_user( array( 'ID' => $me, 'display_name' => $name, 'description' => $p( 'bio', 1000 ) ) );
			update_user_meta( $me, 'lif_title', $p( 'title', 190 ) );
			update_user_meta( $me, 'lif_congregation', $p( 'congregation', 190 ) );
			lif_back( 'lif-profile', 'Profil mentve.' );
	}
	lif_back( 'lif', 'Ismeretlen művelet.', array(), true );
} );
