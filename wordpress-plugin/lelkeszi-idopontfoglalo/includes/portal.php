<?php
/**
 * Önálló lelkészi felület a /idopont/lelkeszi/ címen – nem a WordPress admin.
 * Saját belépés, saját menü; a nyilvános oldalakon nincs rá link, a keresők nem indexelik.
 * Lelkésznek csak meghívóval lehet regisztrálni.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const LIF_PORTAL_PAGES = array(
	''               => array( 'Foglalások', 'lif_pastor' ),
	'naptar'         => array( 'Naptár', 'lif_pastor' ),
	'elerhetoseg'    => array( 'Elérhetőség', 'lif_pastor' ),
	'google'         => array( 'Google naptár', 'lif_pastor' ),
	'szolgaltatasok' => array( 'Szolgáltatások', 'lif_pastor' ),
	'csoportok'      => array( 'Csoportok', 'lif_pastor' ),
	'lelkeszek'      => array( 'Lelkészek', 'lif_admin' ),
	'beallitasok'    => array( 'Beállítások', 'lif_admin' ),
	'profil'         => array( 'Profilom', 'lif_pastor' ),
);

function lif_portal_url( $sub = '', $args = array() ) {
	return add_query_arg( $args, lif_url( '/lelkeszi/' . ( $sub ? $sub . '/' : '' ) ) );
}

function lif_portal_back( $sub, $msg, $args = array(), $error = false ) {
	if ( '' !== $msg ) {
		lif_flash( $error ? 'error' : 'success', $msg );
	}
	lif_redirect( lif_portal_url( $sub, $args ) );
}

/** Űrlap nyitása: az aktuális portáloldalra küld, „do” művelettel. */
function lif_pform( $do, $attrs = '' ) {
	echo '<form method="post" ' . $attrs . '>'; // phpcs:ignore
	lif_nonce_field();
	echo '<input type="hidden" name="do" value="' . esc_attr( $do ) . '">';
}

/** Mindenki – az admin is – csak a saját naptárát, foglalásait és elérhetőségét kezeli. */
function lif_can_manage_pastor( $pastor_id ) {
	return get_current_user_id() === (int) $pastor_id;
}

/** Csoportot létrehozni, szerkeszteni és tagokat beosztani csak az admin tud. */
function lif_can_edit_group( $group ) {
	return current_user_can( 'lif_admin' );
}

function lif_portal_render( $sub, $title, $callback ) {
	lif_render( 'portal', array( 'portal_sub' => $sub, 'portal_cb' => $callback ), $title );
}

/* ---------- Útvonalak ---------- */

function lif_route_portal( $sub ) {
	$user = wp_get_current_user();
	if ( 'belepes' === $sub ) {
		return lif_portal_login();
	}
	if ( ! $user->exists() ) {
		lif_redirect( lif_portal_url( 'belepes' ) );
	}
	if ( ! $user->has_cap( 'lif_pastor' ) || get_user_meta( $user->ID, 'lif_inactive', true ) ) {
		return lif_render( 'error', array( 'message' => 'Ez a felület a lelkészeknek szól. Lelkészi fiókot csak meghívóval lehet létrehozni.' ), 'Nincs hozzáférés', 403 );
	}
	if ( 'kilepes' === $sub ) {
		if ( lif_is_post() ) {
			lif_check_nonce();
			wp_logout();
			lif_flash( 'info', 'Kijelentkezett.' );
		}
		lif_redirect( lif_portal_url( 'belepes' ) );
	}
	if ( ! array_key_exists( $sub, LIF_PORTAL_PAGES ) ) {
		return lif_not_found();
	}
	if ( ! current_user_can( LIF_PORTAL_PAGES[ $sub ][1] ) ) {
		return lif_render( 'error', array( 'message' => 'Ehhez az oldalhoz adminisztrátori jog szükséges.' ), 'Nincs hozzáférés', 403 );
	}
	if ( lif_is_post() ) {
		lif_check_nonce();
		lif_portal_handle( $sub, sanitize_key( $_POST['do'] ?? '' ) );
	}
	$pages = array(
		''               => 'lif_pp_bookings',
		'naptar'         => 'lif_pp_calendar',
		'elerhetoseg'    => 'lif_pp_availability',
		'google'         => 'lif_pp_google',
		'szolgaltatasok' => 'lif_pp_services',
		'csoportok'      => 'lif_pp_groups',
		'lelkeszek'      => 'lif_pp_pastors',
		'beallitasok'    => 'lif_pp_settings',
		'profil'         => 'lif_pp_profile',
	);
	lif_portal_render( $sub, LIF_PORTAL_PAGES[ $sub ][0], $pages[ $sub ] );
}

function lif_portal_login() {
	$user = wp_get_current_user();
	if ( $user->exists() && $user->has_cap( 'lif_pastor' ) ) {
		lif_redirect( lif_portal_url() );
	}
	$error = null;
	if ( lif_is_post() ) {
		lif_check_nonce();
		$key = 'plogin:' . ( $_SERVER['REMOTE_ADDR'] ?? '' );
		if ( lif_rate_blocked( $key, 10 ) ) {
			$error = 'Túl sok próbálkozás. Próbálja újra 15 perc múlva.';
		} else {
			$login = lif_post( 'email', 200 );
			$cand  = is_email( $login ) ? get_user_by( 'email', $login ) : get_user_by( 'login', $login );
			if ( $cand && ! $cand->has_cap( 'lif_pastor' ) ) {
				lif_rate_fail( $key );
				$error = 'Ez a belépés a lelkészeknek szól. Időpontfoglalóként a foglalási linken léphet be.';
			} else {
				$_REQUEST['redirect_to'] = lif_portal_url();
				$signed                  = wp_signon( array(
					'user_login'    => $login,
					'user_password' => isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : '',
					'remember'      => ! empty( $_POST['remember'] ),
				), is_ssl() );
				if ( is_wp_error( $signed ) ) {
					lif_rate_fail( $key );
					$error = 'Hibás e-mail cím vagy jelszó.';
				} else {
					lif_rate_reset( $key );
					lif_redirect( lif_portal_url() );
				}
			}
		}
	}
	lif_render( 'portal-login', compact( 'error' ), 'Lelkészi belépés', $error ? 401 : 200 );
}

/* ---------- 1. Foglalások ---------- */

function lif_pp_bookings() {
	global $wpdb;
	$me   = get_current_user_id();
	$all  = false; // mindenki csak a saját foglalásait látja
	$past = ! empty( $_GET['korabbi'] );
	$now  = lif_iso( time() );
	$sql  = 'SELECT b.*, s.name AS service_name FROM ' . lif_t( 'bookings' ) . ' b JOIN ' . lif_t( 'services' ) . ' s ON s.id = b.service_id WHERE '
		. ( $all ? '1=1' : $wpdb->prepare( 'b.user_id = %d', $me ) )
		. $wpdb->prepare( $past ? ' AND b.start_utc < %s ORDER BY b.start_utc DESC' : ' AND b.start_utc >= %s ORDER BY b.start_utc ASC', $now ) . ' LIMIT 200';
	$rows = $wpdb->get_results( $sql ); // phpcs:ignore

	$todo = array();
	if ( lif_google_configured() && ! $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . lif_t( 'google_accounts' ) . ' WHERE user_id = %d', $me ) ) ) {
		$todo[] = '<a href="' . esc_url( lif_portal_url( 'google' ) ) . '">Kösse be a Google naptárát</a>, hogy a foglalások bekerüljenek, és a meglévő programjai ne legyenek foglalhatók.';
	}
	if ( ! $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . lif_t( 'group_members' ) . ' WHERE user_id = %d', $me ) ) ) {
		$todo[] = current_user_can( 'lif_admin' )
			? 'Még nem tagja egy csoportnak sem, ezért Önhöz nem lehet foglalni. Beosztás: <a href="' . esc_url( lif_portal_url( 'csoportok' ) ) . '">Csoportok</a>.'
			: 'Az adminisztrátor még nem osztotta be egy csoportba sem, ezért Önhöz még nem lehet foglalni.';
	}
	if ( ! lif_2fa_enabled( $me ) ) {
		$todo[] = 'Ajánlott: <a href="' . esc_url( lif_url( '/fiok/2fa' ) ) . '">kapcsolja be a kétfaktoros hitelesítést</a>.';
	}
	if ( $todo ) {
		echo '<div class="card todo"><h3>Teendők</h3><ul><li>' . implode( '</li><li>', $todo ) . '</li></ul></div>'; // phpcs:ignore
	}
	$base = array( 'mind' => $all ? 1 : null );
	echo '<div class="tabs">';
	echo '<a class="' . ( ! $past ? 'active' : '' ) . '" href="' . esc_url( lif_portal_url( '', $base ) ) . '">Közelgő</a>';
	echo '<a class="' . ( $past ? 'active' : '' ) . '" href="' . esc_url( lif_portal_url( '', array_merge( $base, array( 'korabbi' => 1 ) ) ) ) . '">Korábbi</a>';
	echo '</div>';
	if ( ! $rows ) {
		echo '<p class="muted">Nincs megjeleníthető foglalás.</p>';
		return;
	}
	echo '<div class="table-wrap"><table><thead><tr><th>Időpont</th><th>Alkalom</th>' . ( $all ? '<th>Lelkész</th>' : '' ) . '<th>Foglaló</th><th>Állapot</th><th></th></tr></thead><tbody>';
	foreach ( $rows as $b ) {
		$pastor = get_userdata( $b->user_id );
		echo '<tr' . ( 'cancelled' === $b->status ? ' class="cancelled"' : '' ) . '>';
		echo '<td>' . esc_html( lif_format_range( $b->start_utc, $b->end_utc ) ) . '</td><td>' . esc_html( $b->service_name ) . '</td>';
		if ( $all ) {
			echo '<td>' . esc_html( $pastor ? $pastor->display_name : '–' ) . '</td>';
		}
		echo '<td><strong>' . esc_html( $b->client_name ) . '</strong><br>' . implode( '<br>', array_map( 'esc_html', array_filter( array( $b->client_email, $b->client_phone ) ) ) );
		if ( $b->note ) {
			echo '<div class="note">' . esc_html( $b->note ) . '</div>';
		}
		echo '</td><td>' . ( 'confirmed' === $b->status ? 'Visszaigazolt' : 'Lemondva' ) . ( $b->google_event_id ? '<br><small class="muted">Google naptárban</small>' : '' ) . '</td><td>';
		if ( 'confirmed' === $b->status && ! $past ) {
			lif_pform( 'cancel_booking', 'onsubmit="return confirm(\'Biztosan lemondja? A foglaló értesítést kap, ha megadott e-mail címet.\')"' );
			echo '<input type="hidden" name="id" value="' . (int) $b->id . '"><button class="btn btn-small btn-danger">Lemondás</button></form>';
		}
		echo '</td></tr>';
	}
	echo '</tbody></table></div>';
}

/* ---------- 2. Elérhetőség ---------- */

function lif_pp_availability() {
	global $wpdb;
	$uid = get_current_user_id();
	$who = get_userdata( $uid );
	if ( get_current_user_id() !== $uid && $who ) {
		echo '<p class="muted">Lelkész: <strong>' . esc_html( $who->display_name ) . '</strong></p>';
	}
	$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . lif_t( 'availability' ) . ' WHERE user_id = %d ORDER BY weekday, start_time', $uid ) );
	echo '<p class="muted">Adja meg, mely időszakokban foglalhatók Önnél időpontok. Több időszakot vesszővel válasszon el, pl. <code>09:00-12:00, 14:00-17:30</code>. Üresen hagyott nap = nem foglalható. A Google naptárában lévő foglalt időszakokat a rendszer automatikusan kihagyja.</p>';
	lif_pform( 'save_availability', 'class="card"' );
	echo '<input type="hidden" name="pastor" value="' . (int) $uid . '"><div class="week-form">';
	foreach ( LIF_WEEKDAYS as $d => $label ) {
		$val = implode( ', ', array_map( function ( $r ) {
			return $r->start_time . '-' . $r->end_time;
		}, array_filter( $rows, function ( $r ) use ( $d ) {
			return (int) $r->weekday === $d;
		} ) ) );
		echo '<label><span>' . esc_html( $label ) . '</span><input name="day' . $d . '" value="' . esc_attr( $val ) . '" placeholder="pl. 09:00-12:00, 14:00-17:00"></label>';
	}
	echo '</div><button class="btn btn-primary">Mentés</button></form>';

	echo '<h2>Távollétek, szabadság</h2>';
	lif_pform( 'add_timeoff', 'class="card form-grid"' );
	echo '<input type="hidden" name="pastor" value="' . (int) $uid . '">';
	echo '<label>Kezdete<input type="date" name="start_date" required></label><label>Vége<input type="date" name="end_date"></label><label>Megjegyzés<input name="note" placeholder="pl. szabadság"></label><button class="btn">Hozzáadás</button></form>';
	$off = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . lif_t( 'timeoff' ) . ' WHERE user_id = %d AND end_date >= %s ORDER BY start_date', $uid, lif_now()->format( 'Y-m-d' ) ) );
	if ( ! $off ) {
		echo '<p class="muted">Nincs rögzített távollét.</p>';
		return;
	}
	echo '<ul class="list">';
	foreach ( $off as $t ) {
		echo '<li><span>' . esc_html( $t->start_date . ( $t->end_date !== $t->start_date ? ' – ' . $t->end_date : '' ) ) . ' <em class="muted">' . esc_html( $t->note ) . '</em></span>';
		lif_pform( 'delete_timeoff', 'class="inline"' );
		echo '<input type="hidden" name="id" value="' . (int) $t->id . '"><button class="btn btn-small btn-ghost">Törlés</button></form></li>';
	}
	echo '</ul>';
}

/* ---------- 3. Google naptár ---------- */

function lif_pp_google() {
	global $wpdb;
	$me = get_current_user_id();
	if ( ! lif_google_configured() ) {
		echo '<div class="alert alert-info">A Google integráció még nincs beállítva. Az adminisztrátor a <a href="' . esc_url( lif_portal_url( 'beallitasok' ) ) . '">Beállítások</a> oldalon adhatja meg a Google-azonosítót. Engedélyezett átirányítási URI: <code>' . esc_html( lif_google_redirect_uri() ) . '</code></div>';
		return;
	}
	echo '<p class="muted">Csatlakoztassa egy vagy több saját Google fiókját (pl. személyes és gyülekezeti). Jelölje be, mely naptárak foglalt időszakait vegye figyelembe a rendszer, és válassza ki, melyik naptárba kerüljenek az új foglalások.</p>';
	lif_pform( 'google_connect' );
	echo '<p><button class="btn btn-primary">+ Google fiók csatlakoztatása</button></p></form>';
	$accounts = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . lif_t( 'google_accounts' ) . ' WHERE user_id = %d ORDER BY email', $me ) );
	if ( ! $accounts ) {
		echo '<p class="muted">Még nincs csatlakoztatott Google fiók.</p>';
		return;
	}
	$target_acc = (int) get_user_meta( $me, 'lif_target_account', true );
	$target_cal = (string) get_user_meta( $me, 'lif_target_calendar', true );
	lif_pform( 'google_save' );
	foreach ( $accounts as $a ) {
		$cals = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . lif_t( 'google_calendars' ) . ' WHERE account_id = %d ORDER BY is_primary DESC, summary', $a->id ) );
		echo '<section class="card"><div class="row-between"><h3>' . esc_html( $a->email ) . '</h3><div class="actions"><button class="btn btn-small btn-ghost" name="refresh" value="' . (int) $a->id . '">Lista frissítése</button><button class="btn btn-small btn-danger" name="disconnect" value="' . (int) $a->id . '" onclick="return confirm(\'Leválasztja ezt a Google fiókot?\')">Leválasztás</button></div></div>';
		if ( $a->last_error ) {
			echo '<div class="alert alert-error">Utolsó hiba: ' . esc_html( $a->last_error ) . ' – szükség esetén csatlakoztassa újra a fiókot.</div>';
		}
		echo '<div class="table-wrap"><table><thead><tr><th>Naptár</th><th>Foglaltság figyelése</th><th>Foglalások ide</th></tr></thead><tbody>';
		foreach ( $cals as $c ) {
			echo '<tr><td>' . esc_html( $c->summary ) . ( $c->is_primary ? ' <small class="muted">(elsődleges)</small>' : '' ) . '</td>';
			echo '<td><input type="checkbox" name="busy[]" value="' . (int) $c->id . '" ' . checked( $c->check_busy, 1, false ) . ' aria-label="Foglaltság figyelése"></td><td>';
			echo $c->can_write ? '<input type="radio" name="target" value="' . (int) $c->id . '" ' . checked( $target_acc === (int) $a->id && $target_cal === $c->calendar_id, true, false ) . ' aria-label="Célnaptár">' : '<small class="muted">csak olvasható</small>';
			echo '</td></tr>';
		}
		echo '</tbody></table></div></section>';
	}
	echo '<label class="check"><input type="radio" name="target" value="none" ' . checked( '' === $target_cal, true, false ) . '> Ne kerüljenek a foglalások Google naptárba</label>';
	echo '<button class="btn btn-primary">Mentés</button></form>';
}

/* ---------- 4. Szolgáltatások ---------- */
// Közös szolgáltatás: owner_id üres – az admin kezeli, bárki vállalhatja.
// Saját szolgáltatás: owner_id = a lelkész – csak ő látja, módosítja, törli.
// Közös szolgáltatás módosításakor a nem admin lelkész saját változatot kap, így a többiekét nem írja felül.

function lif_service_can_edit( $s ) {
	if ( ! $s ) {
		return false;
	}
	return (int) $s->owner_id === get_current_user_id() || ( ! $s->owner_id && current_user_can( 'lif_admin' ) );
}

function lif_pp_services() {
	global $wpdb;
	if ( isset( $_GET['szerkeszt'] ) ) {
		return lif_pp_service_edit( 'uj' === $_GET['szerkeszt'] ? 0 : (int) $_GET['szerkeszt'] );
	}
	$me       = get_current_user_id();
	$services = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . lif_t( 'services' ) . ' WHERE owner_id IS NULL OR owner_id = %d ORDER BY owner_id IS NULL DESC, sort, name', $me ) );
	$mine     = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT service_id FROM ' . lif_t( 'pastor_services' ) . ' WHERE user_id = %d', $me ) ) );
	echo '<div class="row-between"><p class="muted" style="max-width:65ch">Jelölje be, mely alkalmakra lehet Önhöz időpontot foglalni. Saját alkalmat is felvehet; a közös alkalmakat módosítva saját változat készül, a többi lelkészé nem változik.</p>';
	echo '<a class="btn btn-primary" href="' . esc_url( lif_portal_url( 'szolgaltatasok', array( 'szerkeszt' => 'uj' ) ) ) . '">+ Új alkalom</a></div>';
	lif_pform( 'my_services' );
	echo '<div class="grid">';
	foreach ( $services as $s ) {
		$own = (int) $s->owner_id === $me;
		echo '<div class="card service-card' . ( $s->active ? '' : ' inactive' ) . '" style="--accent:' . esc_attr( $s->color ) . '">';
		echo '<span class="eyebrow">' . ( $own ? 'Saját' : 'Közös' ) . '</span>';
		echo '<label class="check"><input type="checkbox" name="services[]" value="' . (int) $s->id . '" ' . checked( in_array( (int) $s->id, $mine, true ), true, false ) . '> <strong>' . esc_html( $s->name ) . '</strong></label>';
		echo '<p class="meta">' . (int) $s->duration_min . ' perc' . ( $s->buffer_min ? ' + ' . (int) $s->buffer_min . ' perc szünet' : '' ) . ( $s->location ? ' · ' . esc_html( $s->location ) : '' ) . ( $s->active ? '' : ' · <em>inaktív</em>' ) . '</p>';
		if ( ! $own ) {
			$names = array_filter( array_map( function ( $id ) {
				$u = get_userdata( $id );
				return $u ? $u->display_name : '';
			}, lif_pastor_ids_for_service( $s->id ) ) );
			echo '<p class="meta">Vállalja: ' . esc_html( $names ? implode( ', ', $names ) : '–' ) . '</p>';
		}
		echo '<a href="' . esc_url( lif_portal_url( 'szolgaltatasok', array( 'szerkeszt' => $s->id ) ) ) . '">' . ( lif_service_can_edit( $s ) ? 'Szerkesztés' : 'Saját változat készítése' ) . '</a>';
		echo '</div>';
	}
	echo '</div><button class="btn btn-primary">Mentés</button></form>';
}

function lif_pp_service_edit( $id ) {
	global $wpdb;
	$me = get_current_user_id();
	$s  = $id ? lif_service( $id ) : (object) array( 'id' => 0, 'owner_id' => $me, 'name' => '', 'slug' => '', 'description' => '', 'duration_min' => 60, 'buffer_min' => 15, 'location' => '', 'color' => '#2F557F', 'active' => 1, 'sort' => 0 );
	if ( ! $s || ( $s->owner_id && (int) $s->owner_id !== $me ) ) {
		echo '<p>Nincs ilyen szolgáltatás.</p>';
		return;
	}
	$copy   = $id && ! lif_service_can_edit( $s ); // közös alkalom nem adminnak: saját változat készül
	$shared = $id && ! $s->owner_id && ! $copy;     // admin szerkeszti a közös alkalmat
	echo '<p><a href="' . esc_url( lif_portal_url( 'szolgaltatasok' ) ) . '">← Szolgáltatások</a></p><h2>' . ( $copy ? 'Saját változat: ' . esc_html( $s->name ) : ( $id ? 'Alkalom szerkesztése' : 'Új alkalom' ) ) . '</h2>';
	if ( $copy ) {
		echo '<div class="alert alert-info">Ez közös alkalom. A mentéskor <strong>saját változat</strong> készül belőle, és Önnél ez váltja fel a közöset. A többi lelkész változatlanul a közöset látja.</div>';
	}
	lif_pform( 'save_service', 'class="card"' );
	echo '<input type="hidden" name="id" value="' . (int) $s->id . '">';
	echo '<label>Név *<input name="name" required value="' . esc_attr( $s->name ) . '"></label>';
	echo '<label>Leírás<textarea name="description" rows="3">' . esc_textarea( $s->description ) . '</textarea></label><div class="form-grid">';
	echo '<label>Időtartam (perc)<input type="number" min="5" max="600" name="duration_min" value="' . (int) $s->duration_min . '"></label>';
	echo '<label>Szünet előtte/utána (perc)<input type="number" min="0" max="240" name="buffer_min" value="' . (int) $s->buffer_min . '"></label>';
	echo '<label>Helyszín<input name="location" value="' . esc_attr( $s->location ) . '" placeholder="pl. Lelkészi hivatal"></label>';
	echo '<label>Szín<input type="color" name="color" value="' . esc_attr( $s->color ) . '"></label>';
	echo '<label>Sorrend<input type="number" name="sort" value="' . (int) $s->sort . '"></label></div>';
	echo '<label class="check"><input type="checkbox" name="active" ' . checked( $s->active, 1, false ) . '> Aktív (foglalható)</label>';
	if ( ! $id && current_user_can( 'lif_admin' ) ) {
		echo '<label class="check"><input type="checkbox" name="shared"> Közös alkalom – minden lelkész vállalhatja (különben csak az Öné)</label>';
	}
	if ( $shared ) {
		$assigned = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT user_id FROM ' . lif_t( 'pastor_services' ) . ' WHERE service_id = %d', $id ) ) );
		echo '<h3>Lelkészek, akik vállalják</h3><p class="muted small">A lelkészek a saját Szolgáltatások oldalukon maguk is be- és kikapcsolhatják.</p><div class="chips">';
		foreach ( lif_pastors() as $p ) {
			echo '<label class="check chip"><input type="checkbox" name="pastors[]" value="' . (int) $p->ID . '" ' . checked( in_array( $p->ID, $assigned, true ), true, false ) . '> ' . esc_html( $p->display_name ) . '</label>';
		}
		echo '</div>';
	}
	echo '<div class="actions"><button class="btn btn-primary">' . ( $copy ? 'Saját változat mentése' : 'Mentés' ) . '</button></div></form>';
	if ( $id && ! $copy ) {
		lif_pform( 'delete_service', 'onsubmit="return confirm(\'Biztosan törli ezt az alkalmat?\')"' );
		echo '<input type="hidden" name="id" value="' . (int) $id . '"><button class="btn btn-danger">Alkalom törlése</button></form>';
	}
}

/* ---------- 5. Csoportok ---------- */

function lif_pp_groups() {
	global $wpdb;
	if ( isset( $_GET['szerkeszt'] ) && current_user_can( 'lif_admin' ) ) {
		return lif_pp_group_edit( 'uj' === $_GET['szerkeszt'] ? 0 : (int) $_GET['szerkeszt'] );
	}
	$me       = get_current_user_id();
	$is_admin = current_user_can( 'lif_admin' );
	// A lelkész csak azokat a csoportokat látja, amelyekbe az admin beosztotta; az admin mindet.
	$groups = $is_admin
		? $wpdb->get_results( 'SELECT * FROM ' . lif_t( 'groups' ) . ' ORDER BY active DESC, name' )
		: $wpdb->get_results( $wpdb->prepare( 'SELECT g.* FROM ' . lif_t( 'groups' ) . ' g JOIN ' . lif_t( 'group_members' ) . ' m ON m.group_id = g.id WHERE m.user_id = %d ORDER BY g.active DESC, g.name', $me ) );
	if ( $is_admin ) {
		echo '<div class="row-between"><p class="muted" style="max-width:65ch">Egy csoport (pl. gyülekezet vagy munkacsoport) saját foglalási linket kap. Aki ezt megnyitja, csak a csoport lelkészeinél foglalhat időpontot. A csoportokat és a beosztást csak Ön (adminisztrátor) kezeli – a lelkészek csak a saját csoportjaikat látják.</p>';
		echo '<a class="btn btn-primary" href="' . esc_url( lif_portal_url( 'csoportok', array( 'szerkeszt' => 'uj' ) ) ) . '">+ Új csoport</a></div>';
		// Beosztásra váró lelkészek
		$unassigned = array_filter( lif_pastors(), function ( $p ) use ( $wpdb ) {
			return ! $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . lif_t( 'group_members' ) . ' WHERE user_id = %d', $p->ID ) );
		} );
		if ( $unassigned ) {
			echo '<div class="card todo"><h3>Csoportba még nem beosztott lelkészek</h3><p class="muted">Hozzájuk addig nem lehet időpontot foglalni, amíg be nem osztja őket egy csoportba (a csoport Szerkesztés gombjával).</p><div class="chips">';
			foreach ( $unassigned as $p ) {
				echo '<span class="chip">' . esc_html( $p->display_name ) . '</span>';
			}
			echo '</div></div>';
		}
	} else {
		echo '<p class="muted" style="max-width:65ch">Ezekbe a csoportokba osztotta be Önt az adminisztrátor. A foglalási linket megoszthatja; aki megnyitja, csak a csoport lelkészeinél foglalhat. A csoportokat és a beosztást az adminisztrátor kezeli.</p>';
	}

	if ( ! $groups ) {
		echo '<p class="muted">' . ( $is_admin ? 'Még nincs csoport.' : 'Még nem osztották be egy csoportba sem.' ) . '</p>';
		return;
	}
	echo '<div class="grid">';
	foreach ( $groups as $i => $g ) {
		$members = array_filter( array_map( 'get_userdata', lif_group_member_ids( $g->id ) ) );
		$mine    = lif_is_group_member( $g->id, $me );
		$url     = lif_group_url( $g );
		echo '<div class="card group-card' . ( $g->active ? '' : ' inactive' ) . '"><p class="eyebrow">' . esc_html( lif_kind_label( $g->kind ) ) . ( $mine ? ' · tag vagyok' : '' ) . ( $g->active ? '' : ' · inaktív' ) . '</p>';
		echo '<h3>' . esc_html( $g->name ) . '</h3><p class="meta">Lelkészek: ' . esc_html( $members ? implode( ', ', wp_list_pluck( $members, 'display_name' ) ) : '–' ) . '</p>';
		if ( $g->active ) {
			echo '<div class="share"><input readonly value="' . esc_attr( $url ) . '" onclick="this.select()" aria-label="Megosztható link"><button type="button" class="btn btn-small" data-copy="' . esc_attr( $url ) . '">Másolás</button></div>';
		}
		echo '<div class="actions">';
		if ( $g->active ) {
			echo '<a class="btn btn-small btn-ghost" target="_blank" href="' . esc_url( $url ) . '">Megnyitás</a>';
		}
		if ( lif_can_edit_group( $g ) ) {
			echo '<a class="btn btn-small btn-ghost" href="' . esc_url( lif_portal_url( 'csoportok', array( 'szerkeszt' => $g->id ) ) ) . '">Szerkesztés</a>';
		}
		echo '</div></div>';
	}
	echo '</div>';
}

function lif_pp_group_edit( $id ) {
	global $wpdb;
	$g = $id ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . lif_t( 'groups' ) . ' WHERE id = %d', $id ) ) : (object) array( 'id' => 0, 'name' => '', 'slug' => '', 'kind' => 'gyulekezet', 'description' => '', 'active' => 1 );
	if ( ! $g || ( $id && ! lif_can_edit_group( $g ) ) ) {
		echo '<div class="alert alert-error">Ezt a csoportot csak a tagjai és az adminisztrátorok szerkeszthetik.</div>';
		return;
	}
	$assigned = $id ? lif_group_member_ids( $id ) : array(); // a tagokat az admin jelöli ki
	echo '<p><a href="' . esc_url( lif_portal_url( 'csoportok' ) ) . '">← Csoportok</a></p><h2>' . ( $id ? 'Csoport szerkesztése' : 'Új csoport' ) . '</h2>';
	if ( $id && $g->active ) {
		$url = lif_group_url( $g );
		echo '<div class="card"><h3>Megosztható foglalási link</h3><p class="muted">Ezen a linken csak a csoport lelkészeinek időpontjai foglalhatók.</p><div class="share"><input readonly value="' . esc_attr( $url ) . '" onclick="this.select()" aria-label="Megosztható link"><button type="button" class="btn btn-small" data-copy="' . esc_attr( $url ) . '">Másolás</button></div></div>';
	}
	lif_pform( 'save_group', 'class="card"' );
	echo '<input type="hidden" name="id" value="' . (int) $g->id . '"><div class="form-grid">';
	echo '<label>Név *<input name="name" required value="' . esc_attr( $g->name ) . '" placeholder="pl. Budavári Gyülekezet"></label>';
	echo '<label>Típus<select name="kind">';
	foreach ( LIF_KINDS as $k => $l ) {
		echo '<option value="' . esc_attr( $k ) . '" ' . selected( $g->kind, $k, false ) . '>' . esc_html( $l ) . '</option>';
	}
	echo '</select></label><label>Link-azonosító<input name="slug" value="' . esc_attr( $g->slug ) . '" placeholder="automatikus, véletlen végződéssel"></label></div>';
	echo '<label>Leírás (a csoport foglalóoldalán jelenik meg)<textarea name="description" rows="3">' . esc_textarea( $g->description ) . '</textarea></label>';
	echo '<label class="check"><input type="checkbox" name="active" ' . checked( $g->active, 1, false ) . '> Aktív (a link működik)</label>';
	echo '<h3>Lelkészek a csoportban</h3><div class="chips">';
	foreach ( lif_pastors() as $p ) {
		$c = lif_pastor_meta( $p->ID, 'congregation' );
		echo '<label class="check chip"><input type="checkbox" name="members[]" value="' . (int) $p->ID . '" ' . checked( in_array( $p->ID, $assigned, true ), true, false ) . '> ' . esc_html( $p->display_name ) . ( $c ? ' <small class="muted">(' . esc_html( $c ) . ')</small>' : '' ) . '</label>';
	}
	echo '</div><div class="actions"><button class="btn btn-primary">Mentés</button></div></form>';
	if ( $id ) {
		lif_pform( 'delete_group', 'onsubmit="return confirm(\'Biztosan törli a csoportot? A megosztott link ezután nem működik.\')"' );
		echo '<input type="hidden" name="id" value="' . (int) $id . '"><button class="btn btn-danger">Csoport törlése</button></form>';
	}
}

/* ---------- 6. Lelkészek és meghívók (admin) ---------- */

function lif_pp_pastors() {
	global $wpdb;
	$created = get_transient( 'lif_created_invite_' . get_current_user_id() );
	delete_transient( 'lif_created_invite_' . get_current_user_id() );
	if ( $created ) {
		echo '<div class="alert alert-success stack"><div>' . ( $created['reset'] ? 'Jelszó-visszaállító link' : 'Meghívó' ) . ' elkészült: <strong>' . esc_html( $created['email'] ) . '</strong> – ' . ( $created['mailed'] ? 'e-mailben is elküldtük.' : 'küldje el neki. A link 7 napig érvényes, egyszer használható, és csak most látható.' ) . '</div>';
		echo '<div class="share"><input readonly id="invite-link" value="' . esc_attr( $created['link'] ) . '" onclick="this.select()" aria-label="Meghívólink"><button type="button" class="btn btn-small" data-copy="' . esc_attr( $created['link'] ) . '">Másolás</button></div></div>';
	}
	$groups = $wpdb->get_results( 'SELECT * FROM ' . lif_t( 'groups' ) . ' ORDER BY name' );
	echo '<div class="table-wrap"><table><thead><tr><th>Név</th><th>Gyülekezet</th><th>Csoportok</th><th>Szerepkör</th><th>Védelem</th><th></th></tr></thead><tbody>';
	foreach ( lif_pastors( true ) as $p ) {
		$inactive = (bool) get_user_meta( $p->ID, 'lif_inactive', true );
		$gnames   = array();
		foreach ( $groups as $g ) {
			if ( lif_is_group_member( $g->id, $p->ID ) ) {
				$gnames[] = $g->name;
			}
		}
		echo '<tr' . ( $inactive ? ' class="cancelled"' : '' ) . '><td><strong>' . esc_html( $p->display_name ) . '</strong><br><small>' . esc_html( $p->user_email ) . '</small></td><td>';
		lif_pform( 'pastor_congregation', 'class="congr-form"' );
		echo '<input type="hidden" name="id" value="' . (int) $p->ID . '"><input name="congregation" list="lif-congr" value="' . esc_attr( lif_pastor_meta( $p->ID, 'congregation' ) ) . '" placeholder="Gyülekezet" aria-label="Gyülekezet"><button class="btn btn-small btn-ghost">Mentés</button></form></td>';
		echo '<td>' . esc_html( $gnames ? implode( ', ', $gnames ) : '–' ) . '</td>';
		echo '<td>' . ( $p->has_cap( 'lif_admin' ) ? 'Adminisztrátor' : 'Lelkész' ) . ( $inactive ? ' (inaktív)' : '' ) . '</td>';
		echo '<td>' . ( lif_2fa_enabled( $p->ID ) ? 'Kétfaktoros ✓' : 'Csak jelszó' ) . '</td><td class="actions">';
		lif_pform( 'pastor_action', 'class="inline"' );
		echo '<input type="hidden" name="id" value="' . (int) $p->ID . '"><button class="btn btn-small btn-ghost" name="what" value="reset">Jelszó-visszaállító link</button>';
		if ( get_current_user_id() !== $p->ID && ! user_can( $p, 'manage_options' ) ) {
			if ( lif_2fa_enabled( $p->ID ) ) {
				echo '<button class="btn btn-small btn-ghost" name="what" value="reset2fa">2FA kikapcsolása</button>';
			}
			echo '<button class="btn btn-small btn-ghost" name="what" value="admin">' . ( $p->has_cap( 'lif_admin' ) ? 'Admin jog elvétele' : 'Admin jog' ) . '</button>';
			echo '<button class="btn btn-small btn-ghost" name="what" value="toggle">' . ( $inactive ? 'Aktiválás' : 'Inaktiválás' ) . '</button>';
		}
		echo '</form></td></tr>';
	}
	echo '</tbody></table></div><datalist id="lif-congr">';
	foreach ( array_unique( array_filter( array_map( function ( $p ) {
		return lif_pastor_meta( $p->ID, 'congregation' );
	}, lif_pastors( true ) ) ) ) as $c ) {
		echo '<option value="' . esc_attr( $c ) . '">';
	}
	echo '</datalist>';

	echo '<h2>Lelkész meghívása</h2><p class="muted">A lelkészi felületre csak meghívóval lehet regisztrálni. A meghívott a linken maga adja meg a nevét és a jelszavát.</p>';
	lif_pform( 'invite', 'class="card form-grid"' );
	echo '<label>E-mail cím *<input id="ie" type="email" name="email" required></label><label>Gyülekezet<input name="congregation" list="lif-congr"></label>';
	echo '<label class="check full-row"><input type="checkbox" name="is_admin"> Adminisztrátor (lelkészeket hívhat meg, szolgáltatásokat és beállításokat kezel)</label>';
	if ( $groups ) {
		echo '<div class="full-row"><p class="label">Csoportok</p><div class="chips">';
		foreach ( $groups as $g ) {
			echo '<label class="check chip"><input type="checkbox" name="groups[]" value="' . (int) $g->id . '"> ' . esc_html( $g->name ) . '</label>';
		}
		echo '</div></div>';
	}
	echo '<button class="btn btn-primary">Meghívó készítése</button></form>';

	$invites = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . lif_t( 'invites' ) . ' WHERE used_at IS NULL AND expires_at > %d ORDER BY created_at DESC', time() ) );
	if ( $invites ) {
		echo '<h2>Függő meghívók</h2><ul class="list">';
		foreach ( $invites as $i ) {
			echo '<li><span><strong>' . esc_html( $i->email ) . '</strong> <small class="muted">' . ( $i->user_id ? 'jelszó-visszaállítás' : ( $i->is_admin ? 'adminisztrátor' : 'lelkész' ) ) . ' · lejár: ' . esc_html( lif_wdate( 'M j. H:i', (int) $i->expires_at ) ) . '</small></span>';
			lif_pform( 'revoke_invite', 'class="inline"' );
			echo '<input type="hidden" name="id" value="' . (int) $i->id . '"><button class="btn btn-small btn-ghost">Visszavonás</button></form></li>';
		}
		echo '</ul>';
	}
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

function lif_pp_settings() {
	$s = lif_settings();
	lif_pform( 'save_settings', 'class="card"' );
	echo '<label>Gyülekezet / egyházközség neve<input name="org_name" value="' . esc_attr( $s['org_name'] ) . '"></label><div class="form-grid">';
	echo '<label>Legalább ennyi órával előre<input type="number" min="0" name="min_notice_hours" value="' . (int) $s['min_notice_hours'] . '"></label>';
	echo '<label>Legfeljebb ennyi nappal előre<input type="number" min="1" max="365" name="max_days_ahead" value="' . (int) $s['max_days_ahead'] . '"></label>';
	echo '<label>Időpontok lépésköze (perc)<input type="number" min="5" max="240" name="slot_step_min" value="' . (int) $s['slot_step_min'] . '"></label></div>';
	echo '<p class="muted small">Időzóna: ' . esc_html( wp_timezone_string() ) . ' (a WordPress általános beállításaiban módosítható).</p>';
	echo '<label class="check"><input type="checkbox" name="invite_client" ' . checked( ! empty( $s['invite_client'] ), true, false ) . '> A foglaló kapjon Google Naptár-meghívót (ha megadott e-mail címet)</label>';
	echo '<h3>Google Naptár-összekapcsolás</h3><p class="muted small">Hozzon létre egy OAuth-ügyfelet a Google Cloud Console-ban (Web application), engedélyezze a Google Calendar API-t, és adja meg ezt az átirányítási URI-t: <code>' . esc_html( lif_google_redirect_uri() ) . '</code></p>';
	if ( defined( 'LIF_GOOGLE_CLIENT_ID' ) ) {
		echo '<p>A Google-azonosítók a wp-config.php-ban vannak megadva.</p>';
	} else {
		echo '<div class="form-grid"><label>Client ID<input name="google_client_id" value="' . esc_attr( $s['google_client_id'] ) . '"></label>';
		echo '<label>Client secret<input type="password" name="google_client_secret" value="" placeholder="' . ( $s['google_client_secret'] ? '•••••• (megadva – üresen hagyva nem változik)' : '' ) . '"></label></div>';
	}
	echo '<div><button class="btn btn-primary">Mentés</button></div></form>';
	echo '<div class="card"><h3>A lelkészi felület címe</h3><p>A lelkészek itt lépnek be: <code>' . esc_html( lif_portal_url() ) . '</code></p><p class="muted small">Erre a címre a nyilvános oldalakon nincs link, és a keresők nem indexelik. A lelkészek a WordPress adminfelületére nem jutnak be.</p></div>';
}

/* ---------- 8. Profilom ---------- */

function lif_pp_profile() {
	$u = wp_get_current_user();
	echo '<div class="grid">';
	lif_pform( 'save_profile', 'class="card stack"' );
	echo '<h3>Adataim</h3><label>Név<input name="name" required value="' . esc_attr( $u->display_name ) . '"></label>';
	echo '<label>Megnevezés<input name="title" value="' . esc_attr( lif_pastor_meta( $u->ID, 'title' ) ) . '" placeholder="pl. parókus lelkész"></label>';
	echo '<label>Gyülekezet<input name="congregation" value="' . esc_attr( lif_pastor_meta( $u->ID, 'congregation' ) ) . '"></label>';
	echo '<label>Rövid bemutatkozás<textarea name="bio" rows="4">' . esc_textarea( $u->description ) . '</textarea></label>';
	echo '<label>E-mail cím (belépéshez és értesítésekhez)<input type="email" name="email" required value="' . esc_attr( $u->user_email ) . '"></label>';
	echo '<button class="btn btn-primary">Mentés</button></form>';
	echo '<div class="stack">';
	echo '<div class="card stack"><h3>Kétfaktoros hitelesítés</h3><p class="muted">' . ( lif_2fa_enabled( $u->ID ) ? 'Bekapcsolva ✓ – belépéskor a jelszó mellett a hitelesítő alkalmazás kódját is kérjük.' : 'Nincs bekapcsolva. Ajánlott: a jelszó mellé a telefonja is kell a belépéshez.' ) . '</p>';
	echo '<div><a class="btn" href="' . esc_url( lif_url( '/fiok/2fa' ) ) . '">' . ( lif_2fa_enabled( $u->ID ) ? 'Kezelés' : 'Bekapcsolás' ) . '</a></div></div>';
	lif_pform( 'change_password', 'class="card stack"' );
	echo '<h3>Jelszócsere</h3><label>Jelenlegi jelszó<input type="password" name="current_password" required autocomplete="current-password"></label>';
	echo '<label>Új jelszó (legalább 10 karakter)<input type="password" name="new_password" minlength="10" required autocomplete="new-password"></label>';
	echo '<button class="btn">Jelszó módosítása</button></form></div></div>';
}

/* ---------- Űrlapok feldolgozása ---------- */

function lif_portal_handle( $sub, $do ) {
	global $wpdb;
	$me         = get_current_user_id();
	$p          = 'lif_post';
	$ids        = function ( $k ) {
		return array_map( 'intval', (array) ( $_POST[ $k ] ?? array() ) );
	};
	$need_admin = function () {
		if ( ! current_user_can( 'lif_admin' ) ) {
			lif_portal_back( '', 'Ehhez adminisztrátori jog szükséges.', array(), true );
		}
	};

	lif_calendar_handle( $do ); // a naptár saját műveletei (ha az övé, ott véget ér)

	switch ( $do ) {
		case 'cancel_booking':
			$b = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . lif_t( 'bookings' ) . ' WHERE id = %d', (int) $p( 'id' ) ) );
			if ( $b && lif_can_manage_pastor( $b->user_id ) ) {
				lif_cancel_booking( $b, 'pastor' );
			}
			if ( 'naptar' === $p( 'back', 20 ) ) {
				lif_portal_back( 'naptar', 'A foglalást lemondta.', lif_cal_args( lif_cal_pastor_id(), lif_cal_monday() ) );
			}
			lif_portal_back( '', 'A foglalást lemondta.' );

		case 'save_availability':
			$uid = (int) $p( 'pastor' );
			if ( ! lif_can_manage_pastor( $uid ) ) {
				lif_portal_back( 'elerhetoseg', 'Nincs jogosultsága.', array(), true );
			}
			$args   = $uid !== $me ? array( 'lelkesz' => $uid ) : array();
			$parsed = array();
			for ( $d = 1; $d <= 7; $d++ ) {
				$r = lif_parse_ranges( $p( 'day' . $d ) );
				if ( is_wp_error( $r ) ) {
					lif_portal_back( 'elerhetoseg', $r->get_error_message(), $args, true );
				}
				$parsed[ $d ] = $r;
			}
			$wpdb->delete( lif_t( 'availability' ), array( 'user_id' => $uid ) );
			foreach ( $parsed as $d => $ranges ) {
				foreach ( $ranges as $r ) {
					$wpdb->insert( lif_t( 'availability' ), array( 'user_id' => $uid, 'weekday' => $d, 'start_time' => $r[0], 'end_time' => $r[1] ) );
				}
			}
			lif_portal_back( 'elerhetoseg', 'Heti elérhetőség mentve.', $args );

		case 'add_timeoff':
			$uid  = (int) $p( 'pastor' );
			$args = $uid !== $me ? array( 'lelkesz' => $uid ) : array();
			$from = $p( 'start_date', 10 );
			$to   = $p( 'end_date', 10 ) ? $p( 'end_date', 10 ) : $from;
			if ( ! lif_can_manage_pastor( $uid ) ) {
				lif_portal_back( 'elerhetoseg', 'Nincs jogosultsága.', array(), true );
			}
			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) || $to < $from ) {
				lif_portal_back( 'elerhetoseg', 'Adjon meg érvényes dátumokat (a vége nem lehet korábbi a kezdeténél).', $args, true );
			}
			$wpdb->insert( lif_t( 'timeoff' ), array( 'user_id' => $uid, 'start_date' => $from, 'end_date' => $to, 'note' => $p( 'note', 190 ) ) );
			lif_portal_back( 'elerhetoseg', 'Távollét rögzítve.', $args );

		case 'delete_timeoff':
			$t = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . lif_t( 'timeoff' ) . ' WHERE id = %d', (int) $p( 'id' ) ) );
			if ( $t && lif_can_manage_pastor( $t->user_id ) ) {
				$wpdb->delete( lif_t( 'timeoff' ), array( 'id' => $t->id ) );
			}
			lif_portal_back( 'elerhetoseg', 'Távollét törölve.', $t && (int) $t->user_id !== $me ? array( 'lelkesz' => $t->user_id ) : array() );

		case 'google_connect':
			if ( ! lif_google_configured() ) {
				lif_portal_back( 'google', 'A Google integráció nincs beállítva.', array(), true );
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
				lif_portal_back( 'google', is_wp_error( $r ) ? $r->get_error_message() : 'Naptárlista frissítve.', array(), is_wp_error( $r ) );
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
				lif_portal_back( 'google', 'Google fiók leválasztva.' );
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
			lif_portal_back( 'google', 'Naptárbeállítások mentve.' );

		case 'my_services':
			$wpdb->delete( lif_t( 'pastor_services' ), array( 'user_id' => $me ) );
			$visible = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . lif_t( 'services' ) . ' WHERE owner_id IS NULL OR owner_id = %d', $me ) ) );
			foreach ( array_intersect( $ids( 'services' ), $visible ) as $sid ) {
				$wpdb->replace( lif_t( 'pastor_services' ), array( 'user_id' => $me, 'service_id' => $sid ) );
			}
			lif_portal_back( 'szolgaltatasok', 'Az Ön által vállalt alkalmak mentve.' );

		case 'save_service':
			$id       = (int) $p( 'id' );
			$existing = $id ? lif_service( $id ) : null;
			if ( $id && ( ! $existing || ( $existing->owner_id && (int) $existing->owner_id !== $me ) ) ) {
				lif_portal_back( 'szolgaltatasok', 'Ezt az alkalmat nem módosíthatja.', array(), true );
			}
			$copy = $existing && ! lif_service_can_edit( $existing );
			$back = array( 'szerkeszt' => $id ? $id : 'uj' );
			$name = $p( 'name', 190 );
			if ( '' === $name ) {
				lif_portal_back( 'szolgaltatasok', 'A név kötelező.', $back, true );
			}
			// Egyedi URL-azonosító (a lelkészek alkalmai azonos néven is létezhetnek)
			$slug = ( $existing && ! $copy ) ? $existing->slug : lif_slugify( $name );
			$slug = $slug ? $slug : 'alkalom';
			$base = $slug;
			while ( $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . lif_t( 'services' ) . ' WHERE slug = %s AND id != %d', $slug, $copy ? 0 : $id ) ) ) {
				$slug = $base . '-' . lif_random_suffix();
			}
			$data = array(
				'name'         => $name,
				'slug'         => $slug,
				'description'  => $p( 'description', 1000 ),
				'duration_min' => max( 5, min( 600, (int) $p( 'duration_min' ) ) ),
				'buffer_min'   => max( 0, min( 240, (int) $p( 'buffer_min' ) ) ),
				'location'     => $p( 'location', 190 ),
				'color'        => preg_match( '/^#[0-9a-f]{6}$/i', $p( 'color' ) ) ? $p( 'color' ) : '#2F557F',
				'active'       => empty( $_POST['active'] ) ? 0 : 1,
				'sort'         => (int) $p( 'sort' ),
			);
			if ( $existing && ! $copy ) {
				$wpdb->update( lif_t( 'services' ), $data, array( 'id' => $id ) );
				if ( ! $existing->owner_id ) { // admin: közös alkalom vállalói
					$wpdb->delete( lif_t( 'pastor_services' ), array( 'service_id' => $id ) );
					foreach ( $ids( 'pastors' ) as $uid ) {
						$wpdb->replace( lif_t( 'pastor_services' ), array( 'user_id' => $uid, 'service_id' => $id ) );
					}
				}
				lif_portal_back( 'szolgaltatasok', 'Alkalom mentve.' );
			}
			$shared            = ! $existing && current_user_can( 'lif_admin' ) && ! empty( $_POST['shared'] );
			$data['owner_id']  = $shared ? null : $me;
			$wpdb->insert( lif_t( 'services' ), $data );
			$new_id = (int) $wpdb->insert_id;
			$wpdb->replace( lif_t( 'pastor_services' ), array( 'user_id' => $me, 'service_id' => $new_id ) );
			if ( $copy ) { // a saját változat váltja fel a közöset ennél a lelkésznél
				$wpdb->delete( lif_t( 'pastor_services' ), array( 'user_id' => $me, 'service_id' => $id ) );
				lif_portal_back( 'szolgaltatasok', 'Saját változat elkészült. Önnél ez váltja fel a közös alkalmat.' );
			}
			lif_portal_back( 'szolgaltatasok', 'Új alkalom felvéve.' );

		case 'delete_service':
			$id = (int) $p( 'id' );
			$s  = lif_service( $id );
			if ( ! lif_service_can_edit( $s ) ) {
				lif_portal_back( 'szolgaltatasok', 'Ezt az alkalmat nem törölheti. Ha nem szeretné vállalni, vegye ki mellőle a pipát.', array(), true );
			}
			if ( $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . lif_t( 'bookings' ) . ' WHERE service_id = %d LIMIT 1', $id ) ) ) {
				$wpdb->update( lif_t( 'services' ), array( 'active' => 0 ), array( 'id' => $id ) );
				lif_portal_back( 'szolgaltatasok', 'Ehhez az alkalomhoz tartoznak foglalások, ezért csak inaktiváltuk (nem foglalható).' );
			}
			$wpdb->delete( lif_t( 'services' ), array( 'id' => $id ) );
			$wpdb->delete( lif_t( 'pastor_services' ), array( 'service_id' => $id ) );
			lif_portal_back( 'szolgaltatasok', 'Alkalom törölve.' );

		case 'group_from_congregation': // megszűnt: a csoportbeosztás nem automatikus
			lif_portal_back( 'csoportok', 'A csoportokat és a beosztást az adminisztrátor kézzel kezeli.', array(), true );

		case 'save_group':
			$need_admin();
			$id = (int) $p( 'id' );
			$g  = null;
			if ( $id ) {
				$g = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . lif_t( 'groups' ) . ' WHERE id = %d', $id ) );
				if ( ! $g || ! lif_can_edit_group( $g ) ) {
					lif_portal_back( 'csoportok', 'Nincs jogosultsága.', array(), true );
				}
			}
			$back = array( 'szerkeszt' => $id ? $id : 'uj' );
			$name = $p( 'name', 190 );
			if ( '' === $name ) {
				lif_portal_back( 'csoportok', 'A név kötelező.', $back, true );
			}
			$slug = $p( 'slug' ) ? lif_slugify( $p( 'slug' ) ) : ( $g ? $g->slug : lif_new_group_slug( $name ) );
			if ( in_array( $slug, LIF_RESERVED, true ) || $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . lif_t( 'groups' ) . ' WHERE slug = %s AND id != %d', $slug, $id ) ) ) {
				lif_portal_back( 'csoportok', 'Ez a link-azonosító már foglalt, válasszon másikat.', $back, true );
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
			$members = $ids( 'members' ); // csak az admin által bejelölt lelkészek
			$wpdb->delete( lif_t( 'group_members' ), array( 'group_id' => $id ) );
			foreach ( array_unique( $members ) as $uid ) {
				if ( lif_is_active_pastor( $uid ) ) {
					$wpdb->replace( lif_t( 'group_members' ), array( 'group_id' => $id, 'user_id' => $uid ) );
				}
			}
			lif_portal_back( 'csoportok', 'Csoport mentve.', array( 'szerkeszt' => $id ) );

		case 'delete_group':
			$need_admin();
			$g = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . lif_t( 'groups' ) . ' WHERE id = %d', (int) $p( 'id' ) ) );
			if ( $g && lif_can_edit_group( $g ) ) {
				$wpdb->delete( lif_t( 'groups' ), array( 'id' => $g->id ) );
				$wpdb->delete( lif_t( 'group_members' ), array( 'group_id' => $g->id ) );
			}
			lif_portal_back( 'csoportok', 'Csoport törölve. A megosztott link ezután nem működik.' );

		case 'invite':
			$need_admin();
			$email = sanitize_email( $p( 'email', 190 ) );
			if ( ! is_email( $email ) ) {
				lif_portal_back( 'lelkeszek', 'Adjon meg érvényes e-mail címet.', array(), true );
			}
			if ( email_exists( $email ) ) {
				lif_portal_back( 'lelkeszek', 'Ezzel az e-mail címmel már van fiók a weboldalon.', array(), true );
			}
			$link = lif_create_invite( $email, ! empty( $_POST['is_admin'] ), $p( 'congregation', 190 ), $ids( 'groups' ) );
			$org  = lif_setting( 'org_name' );
			$sent = wp_mail( $email, 'Meghívó – ' . $org . ' időpontfoglaló', "Meghívást kapott a(z) $org lelkészi időpontfoglaló felületére.\n\nA regisztrációhoz nyissa meg ezt a linket (7 napig érvényes, egyszer használható):\n$link\n\nA lelkészi felület címe: " . lif_portal_url() . "\n" );
			set_transient( 'lif_created_invite_' . $me, array( 'email' => $email, 'link' => $link, 'mailed' => (bool) $sent, 'reset' => false ), 10 * MINUTE_IN_SECONDS );
			lif_portal_back( 'lelkeszek', '' );

		case 'revoke_invite':
			$need_admin();
			$wpdb->update( lif_t( 'invites' ), array( 'expires_at' => 0 ), array( 'id' => (int) $p( 'id' ) ) );
			lif_portal_back( 'lelkeszek', 'Meghívó visszavonva.' );

		case 'pastor_congregation':
			$need_admin();
			update_user_meta( (int) $p( 'id' ), 'lif_congregation', $p( 'congregation', 190 ) );
			lif_portal_back( 'lelkeszek', 'Gyülekezet mentve.' );

		case 'pastor_action':
			$need_admin();
			$u = get_userdata( (int) $p( 'id' ) );
			if ( ! $u || ! $u->has_cap( 'lif_pastor' ) ) {
				lif_portal_back( 'lelkeszek', 'Ismeretlen lelkész.', array(), true );
			}
			$what = sanitize_key( $p( 'what' ) );
			if ( 'reset' === $what ) {
				$link = lif_create_invite( $u->user_email, false, '', array(), $u->ID );
				set_transient( 'lif_created_invite_' . $me, array( 'email' => $u->user_email, 'link' => $link, 'mailed' => false, 'reset' => true ), 10 * MINUTE_IN_SECONDS );
				lif_portal_back( 'lelkeszek', '' );
			}
			if ( $u->ID === $me || user_can( $u, 'manage_options' ) ) {
				lif_portal_back( 'lelkeszek', 'Ezt a saját vagy a webhely-adminisztrátori fiókon nem lehet módosítani.', array(), true );
			}
			if ( 'reset2fa' === $what ) {
				lif_2fa_disable( $u->ID );
				lif_portal_back( 'lelkeszek', $u->display_name . ' kétfaktoros hitelesítése kikapcsolva (pl. elveszett telefon esetén).' );
			}
			if ( 'admin' === $what ) {
				$u->has_cap( 'lif_admin' ) ? $u->remove_cap( 'lif_admin' ) : $u->add_cap( 'lif_admin' );
				lif_portal_back( 'lelkeszek', 'Jogosultság módosítva.' );
			}
			if ( 'toggle' === $what ) {
				$inactive = (bool) get_user_meta( $u->ID, 'lif_inactive', true );
				$inactive ? delete_user_meta( $u->ID, 'lif_inactive' ) : update_user_meta( $u->ID, 'lif_inactive', 1 );
				lif_portal_back( 'lelkeszek', $u->display_name . ( $inactive ? ' újra aktív.' : ' inaktiválva – hozzá nem lehet foglalni, és nem léphet be.' ) );
			}
			lif_portal_back( 'lelkeszek', 'Ismeretlen művelet.', array(), true );

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
			lif_portal_back( 'beallitasok', 'Beállítások mentve.' );

		case 'save_profile':
			$name  = $p( 'name', 190 );
			$email = sanitize_email( $p( 'email', 190 ) );
			if ( '' === $name || ! is_email( $email ) ) {
				lif_portal_back( 'profil', 'Adjon meg nevet és érvényes e-mail címet.', array(), true );
			}
			$owner = email_exists( $email );
			if ( $owner && (int) $owner !== $me ) {
				lif_portal_back( 'profil', 'Ez az e-mail cím már egy másik fiókhoz tartozik.', array(), true );
			}
			wp_update_user( array( 'ID' => $me, 'display_name' => $name, 'description' => $p( 'bio', 1000 ), 'user_email' => $email ) );
			update_user_meta( $me, 'lif_title', $p( 'title', 190 ) );
			update_user_meta( $me, 'lif_congregation', $p( 'congregation', 190 ) );
			lif_portal_back( 'profil', 'Profil mentve.' );

		case 'change_password':
			$u   = wp_get_current_user();
			$new = isset( $_POST['new_password'] ) ? (string) wp_unslash( $_POST['new_password'] ) : '';
			if ( ! wp_check_password( (string) wp_unslash( $_POST['current_password'] ?? '' ), $u->user_pass, $u->ID ) ) {
				lif_portal_back( 'profil', 'A jelenlegi jelszó hibás.', array(), true );
			}
			if ( strlen( $new ) < 10 ) {
				lif_portal_back( 'profil', 'Az új jelszó legalább 10 karakter legyen.', array(), true );
			}
			wp_set_password( $new, $u->ID );
			wp_set_auth_cookie( $u->ID, false, is_ssl() );
			lif_portal_back( 'profil', 'Jelszó módosítva.' );
	}
	lif_portal_back( $sub, 'Ismeretlen művelet.', array(), true );
}
