<?php
/**
 * Nyilvános oldalak a /idopont/ cím alatt. Nincs közös felület: a /idopont/ maga semmit nem listáz,
 * foglalni csak csoportlinken (/idopont/<csoport>/) lehet, és csak a csoport lelkészeihez.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function lif_register_rewrites() {
	add_rewrite_rule( '^idopont/?(.*)$', 'index.php?lif_path=/$matches[1]', 'top' );
}
add_action( 'init', function () {
	lif_register_rewrites();
	if ( get_option( 'lif_rewrite_version' ) !== LIF_VERSION ) {
		flush_rewrite_rules( false );
		update_option( 'lif_rewrite_version', LIF_VERSION );
	}
} );
add_filter( 'query_vars', function ( $vars ) {
	$vars[] = 'lif_path';
	return $vars;
} );

/* Időpontkérők nem lépnek be a WordPress adminba, és nem látják az admin sávot. */
function lif_is_client_only( $user = null ) {
	$user = $user ? $user : wp_get_current_user();
	return $user && $user->exists() && in_array( 'lif_client', (array) $user->roles, true ) && ! $user->has_cap( 'lif_pastor' ) && ! $user->has_cap( 'edit_posts' );
}
add_action( 'admin_init', function () {
	if ( lif_is_client_only() && ! wp_doing_ajax() ) {
		wp_safe_redirect( lif_url( '/fiok' ) );
		exit;
	}
} );
add_filter( 'show_admin_bar', function ( $show ) {
	return lif_is_client_only() ? false : $show;
} );
add_filter( 'login_redirect', function ( $to, $requested, $user ) {
	// A belépőűrlap alapértelmezésként az admin kezdőlapját kéri; ilyenkor mi döntjük el, hová menjen.
	$default = ! $requested || untrailingslashit( $requested ) === untrailingslashit( admin_url() );
	if ( $user instanceof WP_User && $default ) {
		if ( lif_is_client_only( $user ) ) {
			return lif_url( '/fiok' );
		}
		if ( $user->has_cap( 'lif_pastor' ) && ! $user->has_cap( 'edit_posts' ) ) {
			return admin_url( 'admin.php?page=lif' );
		}
	}
	return $to;
}, 10, 3 );

/* ---------- Kiszolgálás ---------- */

add_action( 'template_redirect', function () {
	$path = get_query_var( 'lif_path' );
	if ( '' === (string) $path && isset( $_GET['lif_path'] ) ) {
		$path = sanitize_text_field( wp_unslash( $_GET['lif_path'] ) );
	}
	if ( '' === (string) $path ) {
		return;
	}
	nocache_headers();
	header( 'X-Robots-Tag: noindex, nofollow' );
	lif_dispatch( trim( (string) $path, '/' ) );
	exit;
} );

function lif_is_post() {
	return 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' );
}

function lif_check_nonce() {
	if ( ! isset( $_POST['_lif'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_lif'] ) ), 'lif_form' ) ) {
		lif_render( 'error', array( 'message' => 'Lejárt vagy érvénytelen űrlap. Kérjük, töltse újra az oldalt.' ), 'Hiba', 403 );
		exit;
	}
}

function lif_post( $key, $max = 500 ) {
	return isset( $_POST[ $key ] ) ? mb_substr( trim( sanitize_textarea_field( wp_unslash( $_POST[ $key ] ) ) ), 0, $max ) : '';
}

function lif_flash( $type, $text ) {
	setcookie( 'lif_flash', wp_json_encode( array( 'type' => $type, 'text' => $text ) ), 0, COOKIEPATH ? COOKIEPATH : '/' );
}

function lif_take_flash() {
	if ( empty( $_COOKIE['lif_flash'] ) ) {
		return null;
	}
	$f = json_decode( wp_unslash( $_COOKIE['lif_flash'] ), true );
	setcookie( 'lif_flash', '', time() - 3600, COOKIEPATH ? COOKIEPATH : '/' );
	return is_array( $f ) && isset( $f['type'], $f['text'] ) ? array( 'type' => sanitize_key( $f['type'] ), 'text' => (string) $f['text'] ) : null;
}

function lif_redirect( $url ) {
	wp_safe_redirect( $url );
	exit;
}

/** Teljes oldal kirajzolása a bővítmény saját, témától független elrendezésével. */
function lif_render( $template, $vars = array(), $title = '', $status = 200 ) {
	status_header( $status );
	$vars  = array_merge( array( 'group' => null ), $vars );
	$flash = lif_take_flash();
	$user  = wp_get_current_user();
	$org   = lif_setting( 'org_name' );
	extract( $vars, EXTR_SKIP ); // phpcs:ignore
	include LIF_DIR . 'templates/layout.php';
}

function lif_nonce_field() {
	wp_nonce_field( 'lif_form', '_lif', false );
}

function lif_not_found( $message = 'A keresett oldal nem található.' ) {
	lif_render( 'error', array( 'message' => $message ), 'Nem található', 404 );
}

function lif_dispatch( $path ) {
	$seg = '' === $path ? array() : explode( '/', $path );
	$a   = $seg[0] ?? '';

	if ( '' === $a ) {
		return lif_render( 'landing', array(), 'Időpontfoglalás' );
	}
	if ( 'adatkezeles' === $a ) {
		return lif_render( 'privacy', array(), 'Adatkezelési tájékoztató' );
	}
	if ( 'fiok' === $a ) {
		return lif_route_account( $seg[1] ?? '' );
	}
	if ( 'ellenorzes' === $a ) {
		return lif_route_2fa_verify();
	}
	if ( 'foglalas' === $a && isset( $seg[1] ) ) {
		return lif_route_booking( $seg[1] );
	}
	if ( 'naptar' === $a && isset( $seg[1] ) && preg_match( '/^([A-Za-z0-9_-]+)\.ics$/', $seg[1], $m ) ) {
		return lif_route_ics( $m[1] );
	}
	if ( 'meghivo' === $a && isset( $seg[1] ) ) {
		return lif_route_invite( $seg[1] );
	}
	if ( 'google-visszahivas' === $a ) {
		return lif_route_google_callback();
	}
	$group = lif_group_by_slug( $a );
	if ( ! $group ) {
		return lif_not_found( 'Ez a link nem létezik, vagy már nem érvényes. Kérjük, kérjen új linket a gyülekezetétől.' );
	}
	if ( isset( $seg[1] ) ) {
		return lif_route_book( $group, $seg[1] );
	}
	return lif_route_group( $group );
}

/* ---------- Csoport és foglalás ---------- */

function lif_route_group( $group ) {
	global $wpdb;
	$members  = lif_group_member_ids( $group->id );
	$services = array();
	$pastors  = array();
	foreach ( $wpdb->get_results( 'SELECT * FROM ' . lif_t( 'services' ) . ' WHERE active = 1 ORDER BY sort, name' ) as $s ) {
		$ids = array_values( array_intersect( lif_pastor_ids_for_service( $s->id ), $members ) );
		if ( $ids ) {
			$s->pastor_ids = $ids;
			$services[]    = $s;
		}
	}
	foreach ( $members as $uid ) {
		$u = get_userdata( $uid );
		if ( $u && lif_is_active_pastor( $u ) ) {
			$offered = array_filter( $services, function ( $s ) use ( $uid ) {
				return in_array( $uid, $s->pastor_ids, true );
			} );
			if ( $offered ) {
				$pastors[] = array( 'user' => $u, 'services' => $offered );
			}
		}
	}
	usort( $pastors, function ( $a, $b ) {
		return strcmp( remove_accents( $a['user']->display_name ), remove_accents( $b['user']->display_name ) );
	} );
	lif_render( 'group', compact( 'group', 'services', 'pastors' ), $group->name );
}

function lif_route_book( $group, $service_slug ) {
	$service = lif_service_by_slug( $service_slug );
	$ids     = $service ? lif_group_pastors_for_service( $group, $service->id ) : array();
	if ( ! $service || ! $ids ) {
		return lif_not_found( 'Ez a szolgáltatás ebben a csoportban nem foglalható.' );
	}
	$pastors = array_map( 'get_userdata', $ids );
	$user    = wp_get_current_user();
	$form    = $user->exists()
		? array( 'name' => $user->display_name, 'email' => $user->user_email, 'phone' => (string) get_user_meta( $user->ID, 'lif_phone', true ) )
		: array();
	$error     = null;
	$preselect = isset( $_GET['lelkesz'] ) ? (int) $_GET['lelkesz'] : 0;

	if ( lif_is_post() ) {
		lif_check_nonce();
		$form = array(
			'name'   => lif_post( 'name', 120 ),
			'email'  => $user->exists() ? $user->user_email : lif_post( 'email', 200 ),
			'phone'  => lif_post( 'phone', 40 ),
			'note'   => lif_post( 'note', 2000 ),
			'start'  => lif_post( 'start', 30 ),
			'pastor' => lif_post( 'pastor', 20 ),
		);
		$preselect = (int) $form['pastor'];
		$choose    = 'any' === $form['pastor'] || '' === $form['pastor'] ? $ids : array_values( array_intersect( $ids, array( (int) $form['pastor'] ) ) );
		$start_ts  = strtotime( $form['start'] );
		// Bejelentkezés nem kell: a név kötelező, mellé legalább egy elérhetőség (e-mail vagy telefon).
		$phone_digits = preg_replace( '/\D/', '', $form['phone'] );
		if ( '' === $form['name'] ) {
			$error = 'Kérjük, adja meg a nevét.';
		} elseif ( '' === $form['email'] && '' === $form['phone'] ) {
			$error = 'Kérjük, adjon meg legalább egy elérhetőséget: e-mail címet vagy telefonszámot.';
		} elseif ( '' !== $form['email'] && ! is_email( $form['email'] ) ) {
			$error = 'Az e-mail cím formátuma nem megfelelő. Javítsa, vagy hagyja üresen, és adjon meg telefonszámot.';
		} elseif ( '' !== $form['phone'] && ( strlen( $phone_digits ) < 6 || ! preg_match( '/^[0-9 +()\/.-]+$/', $form['phone'] ) ) ) {
			$error = 'A telefonszám formátuma nem megfelelő (pl. +36 30 123 4567).';
		} elseif ( ! $start_ts ) {
			$error = 'Kérjük, válasszon időpontot.';
		} elseif ( empty( $_POST['consent'] ) ) {
			$error = 'A foglaláshoz el kell fogadnia az adatkezelést.';
		} elseif ( ! $choose ) {
			$error = 'A kiválasztott lelkész nem érhető el ebben a csoportban.';
		} else {
			$booking = lif_create_booking( $service, $choose, $start_ts, $form, $user->exists() ? $user->ID : null, $group );
			if ( is_wp_error( $booking ) ) {
				$error = $booking->get_error_message();
			} else {
				lif_flash( 'success', 'Foglalását rögzítettük!' );
				lif_redirect( lif_url( '/foglalas/' . $booking->token ) );
			}
		}
	}
	lif_render( 'book', compact( 'group', 'service', 'pastors', 'form', 'error', 'preselect' ), $service->name, $error ? 400 : 200 );
}

function lif_google_calendar_link( $b, $pastor_name ) {
	$f = function ( $iso ) {
		return gmdate( 'Ymd\THis\Z', strtotime( $iso ) );
	};
	return 'https://calendar.google.com/calendar/render?' . http_build_query( array(
		'action'   => 'TEMPLATE',
		'text'     => $b->service_name . ' – ' . $pastor_name,
		'dates'    => $f( $b->start_utc ) . '/' . $f( $b->end_utc ),
		'details'  => 'Időpontfoglalás: ' . $b->service_name . "\nLelkész: " . $pastor_name,
		'location' => (string) $b->location,
	) );
}

function lif_route_booking( $token ) {
	$b = lif_booking_by_token( $token );
	if ( ! $b ) {
		return lif_not_found( 'Ez a foglalás nem található.' );
	}
	if ( lif_is_post() ) {
		lif_check_nonce();
		if ( strtotime( $b->start_utc ) > time() ) {
			lif_cancel_booking( $b, 'client' );
		}
		lif_flash( 'info', 'A foglalást lemondtuk.' );
		lif_redirect( lif_url( '/foglalas/' . $token ) );
	}
	$pastor = get_userdata( $b->user_id );
	lif_render( 'booking', array(
		'b'        => $b,
		'pastor'   => $pastor,
		'when'     => lif_format_range( $b->start_utc, $b->end_utc ),
		'is_past'  => strtotime( $b->start_utc ) < time(),
		'gcal'     => lif_google_calendar_link( $b, $pastor ? $pastor->display_name : '' ),
		'is_mine'  => is_user_logged_in() && (int) $b->client_id === get_current_user_id(),
	), 'Foglalás' );
}

/* ---------- Foglalói fiók ---------- */

function lif_safe_back( $value ) {
	$value = (string) $value;
	return ( 0 === strpos( $value, lif_url( '/' ) ) || 0 === strpos( $value, home_url( '/?lif_path=' ) ) ) ? $value : '';
}

function lif_route_account( $sub ) {
	$user = wp_get_current_user();
	switch ( $sub ) {
		case 'belepes':
			if ( $user->exists() ) {
				lif_redirect( lif_url( '/fiok' ) );
			}
			$back  = lif_safe_back( $_REQUEST['vissza'] ?? '' );
			$error = null;
			if ( lif_is_post() ) {
				lif_check_nonce();
				$key = 'login:' . ( $_SERVER['REMOTE_ADDR'] ?? '' );
				if ( lif_rate_blocked( $key, 10 ) ) {
					$error = 'Túl sok próbálkozás. Próbálja újra 15 perc múlva.';
				} else {
					$_REQUEST['redirect_to'] = $back ? $back : lif_url( '/fiok' );
					$signed = wp_signon( array(
						'user_login'    => lif_post( 'email', 200 ),
						'user_password' => isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : '',
						'remember'      => true,
					), is_ssl() );
					if ( is_wp_error( $signed ) ) {
						lif_rate_fail( $key );
						$error = 'Hibás e-mail cím vagy jelszó.';
					} else {
						lif_rate_reset( $key );
						lif_redirect( $_REQUEST['redirect_to'] );
					}
				}
			}
			return lif_render( 'login', compact( 'back', 'error' ), 'Belépés', $error ? 401 : 200 );

		case 'regisztracio':
			if ( $user->exists() ) {
				lif_redirect( lif_url( '/fiok' ) );
			}
			$back  = lif_safe_back( $_REQUEST['vissza'] ?? '' );
			$error = null;
			$form  = array();
			if ( lif_is_post() ) {
				lif_check_nonce();
				$form = array( 'name' => lif_post( 'name', 120 ), 'email' => lif_post( 'email', 200 ), 'phone' => lif_post( 'phone', 40 ) );
				$pw   = isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : '';
				if ( '' === $form['name'] ) {
					$error = 'Kérjük, adja meg a nevét.';
				} elseif ( ! is_email( $form['email'] ) ) {
					$error = 'Kérjük, adjon meg érvényes e-mail címet.';
				} elseif ( strlen( $pw ) < 8 ) {
					$error = 'A jelszó legalább 8 karakter legyen.';
				} elseif ( (string) wp_unslash( $_POST['password2'] ?? '' ) !== $pw ) {
					$error = 'A két jelszó nem egyezik.';
				} elseif ( empty( $_POST['consent'] ) ) {
					$error = 'A fiók létrehozásához el kell fogadnia az adatkezelési tájékoztatót.';
				} elseif ( email_exists( $form['email'] ) ) {
					$error = 'Ezzel az e-mail címmel már van fiók. Jelentkezzen be.';
				} else {
					$login = sanitize_user( strstr( $form['email'], '@', true ), true );
					$login = $login ? $login : 'foglalo';
					$base  = $login;
					for ( $i = 2; username_exists( $login ); $i++ ) {
						$login = $base . $i;
					}
					$uid = wp_insert_user( array(
						'user_login'   => $login,
						'user_email'   => $form['email'],
						'user_pass'    => $pw,
						'display_name' => $form['name'],
						'first_name'   => $form['name'],
						'role'         => 'lif_client',
					) );
					if ( is_wp_error( $uid ) ) {
						$error = $uid->get_error_message();
					} else {
						update_user_meta( $uid, 'lif_phone', $form['phone'] );
						update_user_meta( $uid, 'lif_calendar_token', lif_random( 24 ) );
						wp_set_auth_cookie( $uid, true, is_ssl() );
						lif_flash( 'success', 'Fiókja elkészült. Kétfaktoros hitelesítést a Foglalásaim oldalon kapcsolhat be.' );
						lif_redirect( $back ? $back : lif_url( '/fiok' ) );
					}
				}
			}
			return lif_render( 'register', compact( 'back', 'error', 'form' ), 'Fiók létrehozása', $error ? 400 : 200 );

		case 'ellenorzes':
			return lif_route_2fa_verify();
	}

	if ( ! $user->exists() ) {
		lif_redirect( add_query_arg( 'vissza', rawurlencode( lif_url( '/fiok' ) ), lif_url( '/fiok/belepes' ) ) );
	}

	if ( 'kilepes' === $sub && lif_is_post() ) {
		lif_check_nonce();
		$back = lif_safe_back( lif_post( 'vissza', 300 ) );
		wp_logout();
		lif_flash( 'info', 'Kijelentkezett.' );
		lif_redirect( $back ? $back : lif_url( '/fiok/belepes' ) );
	}
	if ( '2fa' === $sub ) {
		return lif_route_2fa_setup( $user );
	}
	if ( lif_is_post() ) {
		lif_check_nonce();
		global $wpdb;
		switch ( $sub ) {
			case 'adatok':
				$name = lif_post( 'name', 120 );
				if ( '' === $name ) {
					lif_flash( 'error', 'A név nem lehet üres.' );
					break;
				}
				$new_pw = isset( $_POST['new_password'] ) ? (string) wp_unslash( $_POST['new_password'] ) : '';
				if ( '' !== $new_pw ) {
					if ( ! wp_check_password( (string) wp_unslash( $_POST['current_password'] ?? '' ), $user->user_pass, $user->ID ) ) {
						lif_flash( 'error', 'A jelenlegi jelszó hibás.' );
						break;
					}
					if ( strlen( $new_pw ) < 8 ) {
						lif_flash( 'error', 'Az új jelszó legalább 8 karakter legyen.' );
						break;
					}
					wp_set_password( $new_pw, $user->ID );
					wp_set_auth_cookie( $user->ID, true, is_ssl() );
				}
				wp_update_user( array( 'ID' => $user->ID, 'display_name' => $name ) );
				update_user_meta( $user->ID, 'lif_phone', lif_post( 'phone', 40 ) );
				lif_flash( 'success', 'Adatai mentve.' );
				break;
			case 'naptar-uj':
				update_user_meta( $user->ID, 'lif_calendar_token', lif_random( 24 ) );
				lif_flash( 'info', 'Új naptárlinket készítettünk. A régi link már nem működik – a naptárában is cserélje le.' );
				break;
			case 'lemondas':
				$b = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . lif_t( 'bookings' ) . ' WHERE id = %d AND client_id = %d', (int) lif_post( 'id', 20 ), $user->ID ) );
				if ( $b && strtotime( $b->start_utc ) > time() ) {
					lif_cancel_booking( $b, 'client' );
					lif_flash( 'info', 'A foglalást lemondtuk.' );
				}
				break;
			case 'torles':
				if ( $user->has_cap( 'lif_pastor' ) ) {
					lif_flash( 'error', 'Lelkészi fiókot itt nem lehet törölni.' );
					break;
				}
				if ( ! wp_check_password( (string) wp_unslash( $_POST['password'] ?? '' ), $user->user_pass, $user->ID ) ) {
					lif_flash( 'error', 'A fiók törléséhez adja meg a jelszavát.' );
					break;
				}
				require_once ABSPATH . 'wp-admin/includes/user.php';
				$wpdb->update( lif_t( 'bookings' ), array( 'client_id' => null ), array( 'client_id' => $user->ID ) );
				wp_logout();
				wp_delete_user( $user->ID );
				lif_flash( 'info', 'Fiókját töröltük. Meglévő foglalásai megmaradtak.' );
				lif_redirect( lif_url( '/fiok/belepes' ) );
		}
		lif_redirect( lif_url( '/fiok' ) );
	}
	if ( '' !== $sub ) {
		return lif_not_found();
	}
	lif_route_account_dashboard( $user );
}

function lif_calendar_token( $user_id ) {
	$t = (string) get_user_meta( $user_id, 'lif_calendar_token', true );
	if ( '' === $t ) {
		$t = lif_random( 24 );
		update_user_meta( $user_id, 'lif_calendar_token', $t );
	}
	return $t;
}

function lif_route_account_dashboard( $user ) {
	global $wpdb;
	$rows = $wpdb->get_results( $wpdb->prepare(
		'SELECT b.*, s.name AS service_name, s.location, g.slug AS group_slug, g.name AS group_name FROM ' . lif_t( 'bookings' ) . ' b
		JOIN ' . lif_t( 'services' ) . ' s ON s.id = b.service_id
		LEFT JOIN ' . lif_t( 'groups' ) . ' g ON g.id = b.group_id AND g.active = 1
		WHERE b.client_id = %d ORDER BY b.start_utc DESC',
		$user->ID
	) );
	$now      = lif_iso( time() );
	$upcoming = array_reverse( array_values( array_filter( $rows, function ( $b ) use ( $now ) {
		return $b->end_utc >= $now;
	} ) ) );
	$past     = array_values( array_filter( $rows, function ( $b ) use ( $now ) {
		return $b->end_utc < $now;
	} ) );
	$https    = lif_url( '/naptar/' . lif_calendar_token( $user->ID ) . '.ics' );
	$webcal   = preg_replace( '#^https?:#', 'webcal:', $https );
	$cal      = array(
		'https'  => $https,
		'webcal' => $webcal,
		'google' => 'https://calendar.google.com/calendar/render?cid=' . rawurlencode( $webcal ),
	);
	$last_group = null;
	foreach ( $rows as $r ) {
		if ( $r->group_slug ) {
			$last_group = $r;
			break;
		}
	}
	lif_render( 'account', compact( 'user', 'upcoming', 'past', 'cal', 'last_group' ), 'Foglalásaim' );
}

/* ---------- Kétfaktoros hitelesítés oldalai ---------- */

function lif_route_2fa_setup( $user ) {
	$error = null;
	$codes = null;
	$back  = $user->has_cap( 'lif_pastor' ) ? admin_url( 'admin.php?page=lif-profile' ) : lif_url( '/fiok' );
	if ( lif_is_post() ) {
		lif_check_nonce();
		$key = '2fa-setup:' . $user->ID;
		if ( lif_rate_blocked( $key ) ) {
			$error = 'Túl sok hibás próbálkozás. Várjon 15 percet.';
		} elseif ( 'disable' === lif_post( 'do', 10 ) ) {
			if ( lif_2fa_check( $user->ID, lif_post( 'code', 20 ) ) ) {
				lif_2fa_disable( $user->ID );
				lif_flash( 'info', 'A kétfaktoros hitelesítést kikapcsolta.' );
				lif_redirect( lif_url( '/fiok/2fa' ) );
			}
			lif_rate_fail( $key );
			$error = 'A kód nem megfelelő, a kétfaktoros hitelesítés bekapcsolva maradt.';
		} else {
			$codes = lif_2fa_confirm( $user->ID, lif_post( 'code', 20 ) );
			if ( ! $codes ) {
				lif_rate_fail( $key );
				$error = 'A kód nem megfelelő. Ellenőrizze, hogy a telefon órája pontos-e, és írja be az alkalmazásban éppen látható kódot.';
			} else {
				lif_rate_reset( $key );
			}
		}
	}
	$enabled = lif_2fa_enabled( $user->ID );
	$setup   = null;
	if ( ! $enabled && ! $codes ) {
		$secret = lif_2fa_pending_secret( $user->ID );
		$setup  = array(
			'secret' => trim( chunk_split( $secret, 4, ' ' ) ),
			'uri'    => lif_totp_uri( $secret, $user->user_email, lif_setting( 'org_name' ) ? lif_setting( 'org_name' ) : get_bloginfo( 'name' ) ),
		);
	}
	$backup_left = lif_2fa_backup_left( $user->ID );
	lif_render( 'twofactor-setup', compact( 'enabled', 'setup', 'codes', 'error', 'back', 'backup_left' ), 'Kétfaktoros hitelesítés', $error ? 400 : 200 );
}

function lif_route_2fa_verify() {
	$pending = lif_2fa_pending_login();
	if ( ! $pending ) {
		lif_redirect( lif_url( '/fiok/belepes' ) );
	}
	$error = null;
	if ( lif_is_post() ) {
		lif_check_nonce();
		$key = '2fa-login:' . $pending['user_id'];
		if ( lif_rate_blocked( $key ) ) {
			$error = 'Túl sok hibás próbálkozás. Várjon 15 percet.';
		} elseif ( lif_2fa_check( $pending['user_id'], lif_post( 'code', 20 ) ) ) {
			lif_rate_reset( $key );
			lif_redirect( lif_2fa_finish_login( $pending ) );
		} else {
			lif_rate_fail( $key );
			$error = 'A kód nem megfelelő. Próbálja újra, vagy használjon tartalékkódot.';
		}
	}
	lif_render( 'twofactor-verify', compact( 'error' ), 'Ellenőrző kód', $error ? 401 : 200 );
}

/* ---------- Meghívó: lelkészi regisztráció csak meghívólinkkel ---------- */

function lif_find_invite( $token ) {
	global $wpdb;
	$inv = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . lif_t( 'invites' ) . ' WHERE token_hash = %s', hash( 'sha256', $token ) ) );
	return ( $inv && ! $inv->used_at && (int) $inv->expires_at > time() ) ? $inv : null;
}

function lif_route_invite( $token ) {
	global $wpdb;
	$invite = lif_find_invite( $token );
	if ( ! $invite ) {
		return lif_render( 'error', array( 'message' => 'Ez a meghívólink lejárt vagy már felhasználták. Kérjen újat a gyülekezet adminisztrátorától.' ), 'Érvénytelen meghívó', 404 );
	}
	$existing = $invite->user_id ? get_userdata( $invite->user_id ) : null;
	$error    = null;
	$form     = array();
	if ( lif_is_post() ) {
		lif_check_nonce();
		$form = array( 'name' => lif_post( 'name', 120 ), 'title' => lif_post( 'title', 120 ) );
		$pw   = isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : '';
		$pw2  = isset( $_POST['password2'] ) ? (string) wp_unslash( $_POST['password2'] ) : '';
		if ( ! $existing && '' === $form['name'] ) {
			$error = 'Kérjük, adja meg a nevét.';
		} elseif ( strlen( $pw ) < 10 ) {
			$error = 'A jelszó legalább 10 karakter legyen.';
		} elseif ( $pw !== $pw2 ) {
			$error = 'A két jelszó nem egyezik.';
		} elseif ( ! $existing && email_exists( $invite->email ) ) {
			$error = 'Ezzel az e-mail címmel már van fiók. Jelentkezzen be, vagy kérjen jelszó-visszaállító linket.';
		} else {
			$wpdb->update( lif_t( 'invites' ), array( 'used_at' => current_time( 'mysql', true ) ), array( 'id' => $invite->id ) );
			if ( $existing ) {
				wp_set_password( $pw, $existing->ID );
				lif_flash( 'success', 'Új jelszava beállítva. Most már beléphet.' );
				lif_redirect( wp_login_url( admin_url( 'admin.php?page=lif' ) ) );
			}
			$login = sanitize_user( strstr( $invite->email, '@', true ), true );
			$login = $login ? $login : 'lelkesz';
			$base  = $login;
			for ( $i = 2; username_exists( $login ); $i++ ) {
				$login = $base . $i;
			}
			$uid = wp_insert_user( array(
				'user_login'   => $login,
				'user_email'   => $invite->email,
				'user_pass'    => $pw,
				'display_name' => $form['name'],
				'first_name'   => $form['name'],
				'role'         => 'lif_pastor',
			) );
			if ( is_wp_error( $uid ) ) {
				$error = $uid->get_error_message();
			} else {
				if ( $invite->is_admin ) {
					( new WP_User( $uid ) )->add_cap( 'lif_admin' );
				}
				update_user_meta( $uid, 'lif_title', $form['title'] );
				update_user_meta( $uid, 'lif_congregation', $invite->congregation );
				foreach ( (array) json_decode( (string) $invite->group_ids, true ) as $gid ) {
					$wpdb->replace( lif_t( 'group_members' ), array( 'group_id' => (int) $gid, 'user_id' => $uid ) );
				}
				for ( $d = 1; $d <= 5; $d++ ) {
					$wpdb->insert( lif_t( 'availability' ), array( 'user_id' => $uid, 'weekday' => $d, 'start_time' => '09:00', 'end_time' => '16:00' ) );
				}
				foreach ( $wpdb->get_col( 'SELECT id FROM ' . lif_t( 'services' ) . ' WHERE active = 1' ) as $sid ) {
					$wpdb->replace( lif_t( 'pastor_services' ), array( 'user_id' => $uid, 'service_id' => (int) $sid ) );
				}
				wp_set_auth_cookie( $uid, false, is_ssl() );
				lif_redirect( admin_url( 'admin.php?page=lif&lif_welcome=1' ) );
			}
		}
	}
	lif_render( 'invite', compact( 'invite', 'existing', 'error', 'form' ), $existing ? 'Új jelszó beállítása' : 'Regisztráció', $error ? 400 : 200 );
}

/* ---------- Google OAuth visszahívás ---------- */

function lif_route_google_callback() {
	$user = wp_get_current_user();
	if ( ! $user->exists() || ! $user->has_cap( 'lif_pastor' ) ) {
		lif_redirect( wp_login_url() );
	}
	$back     = admin_url( 'admin.php?page=lif-google' );
	$expected = get_user_meta( $user->ID, 'lif_oauth_state', true );
	delete_user_meta( $user->ID, 'lif_oauth_state' );
	if ( ! empty( $_GET['error'] ) ) {
		lif_redirect( add_query_arg( 'lif_msg', rawurlencode( 'A Google hozzáférés megszakadt.' ), $back ) );
	}
	$state = sanitize_text_field( wp_unslash( $_GET['state'] ?? '' ) );
	$code  = sanitize_text_field( wp_unslash( $_GET['code'] ?? '' ) );
	if ( ! $code || ! $state || ! hash_equals( (string) $expected, $state ) ) {
		lif_redirect( add_query_arg( 'lif_msg', rawurlencode( 'Érvénytelen Google válasz. Próbálja újra.' ), $back ) );
	}
	$r = lif_google_connect( $user->ID, $code );
	lif_redirect( add_query_arg( 'lif_msg', rawurlencode( is_wp_error( $r ) ? $r->get_error_message() : 'Google fiók csatlakoztatva. Válassza ki, mely naptárakat vegyük figyelembe!' ), $back ) );
}

/* ---------- Naptár-feliratkozás (ICS) ---------- */

function lif_ics_escape( $s ) {
	return str_replace( array( '\\', ';', ',', "\r\n", "\n" ), array( '\\\\', '\\;', '\\,', '\\n', '\\n' ), (string) $s );
}

function lif_ics_fold( $line ) {
	$out = array();
	while ( strlen( $line ) > 75 ) {
		$cut = 75;
		while ( $cut > 0 && ( ord( $line[ $cut ] ) & 0xC0 ) === 0x80 ) {
			$cut--;
		}
		$out[] = substr( $line, 0, $cut );
		$line  = ' ' . substr( $line, $cut );
	}
	$out[] = $line;
	return implode( "\r\n", $out );
}

function lif_route_ics( $token ) {
	global $wpdb;
	$users = get_users( array( 'meta_key' => 'lif_calendar_token', 'meta_value' => $token, 'number' => 1 ) );
	if ( ! $users ) {
		status_header( 404 );
		header( 'Content-Type: text/plain; charset=utf-8' );
		echo 'A naptárlink nem érvényes.';
		return;
	}
	$since = lif_iso( time() - 90 * DAY_IN_SECONDS );
	$rows  = $wpdb->get_results( $wpdb->prepare(
		'SELECT b.*, s.name AS service_name, s.location FROM ' . lif_t( 'bookings' ) . ' b JOIN ' . lif_t( 'services' ) . ' s ON s.id = b.service_id
		WHERE b.client_id = %d AND b.end_utc >= %s ORDER BY b.start_utc',
		$users[0]->ID, $since
	) );
	$host  = wp_parse_url( home_url(), PHP_URL_HOST );
	$f     = function ( $iso ) {
		return gmdate( 'Ymd\THis\Z', strtotime( $iso ) );
	};
	$lines = array( 'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Lelkeszi idopontfoglalo//HU', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH',
		'X-WR-CALNAME:' . lif_ics_escape( lif_setting( 'org_name' ) . ' – időpontjaim' ), 'X-WR-TIMEZONE:' . wp_timezone_string(),
		'REFRESH-INTERVAL;VALUE=DURATION:PT1H', 'X-PUBLISHED-TTL:PT1H' );
	foreach ( $rows as $b ) {
		$pastor  = get_userdata( $b->user_id );
		$pname   = $pastor ? $pastor->display_name : '';
		$lines[] = 'BEGIN:VEVENT';
		$lines[] = 'UID:' . $b->token . '@' . $host;
		$lines[] = 'DTSTAMP:' . gmdate( 'Ymd\THis\Z', strtotime( $b->created_at . ' UTC' ) );
		$lines[] = 'DTSTART:' . $f( $b->start_utc );
		$lines[] = 'DTEND:' . $f( $b->end_utc );
		$lines[] = 'SUMMARY:' . lif_ics_escape( $b->service_name . ' – ' . $pname );
		$lines[] = 'DESCRIPTION:' . lif_ics_escape( "Lelkész: $pname\nFoglalás: " . lif_url( '/foglalas/' . $b->token ) );
		if ( $b->location ) {
			$lines[] = 'LOCATION:' . lif_ics_escape( $b->location );
		}
		$lines[] = 'STATUS:' . ( 'confirmed' === $b->status ? 'CONFIRMED' : 'CANCELLED' );
		$lines[] = 'END:VEVENT';
	}
	$lines[] = 'END:VCALENDAR';
	header( 'Content-Type: text/calendar; charset=utf-8' );
	header( 'Cache-Control: no-cache' );
	echo implode( "\r\n", array_map( 'lif_ics_fold', $lines ) ) . "\r\n"; // phpcs:ignore
}
