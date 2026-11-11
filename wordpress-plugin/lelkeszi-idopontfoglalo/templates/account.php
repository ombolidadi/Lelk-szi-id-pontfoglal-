<?php if ( ! defined( 'ABSPATH' ) ) { exit; }
$row = function ( $b, $can_cancel ) {
	?>
	<div class="card booking-row <?php echo 'cancelled' === $b->status ? 'inactive' : ''; ?>">
		<div class="stack tight">
			<strong><?php echo esc_html( $b->service_name ); ?></strong>
			<span><?php echo esc_html( lif_format_range( $b->start_utc, $b->end_utc ) ); ?></span>
			<?php $pu = get_userdata( $b->user_id ); ?>
			<span class="meta"><?php echo esc_html( implode( ' · ', array_filter( array( $pu ? $pu->display_name : '', $b->location, $b->group_name ) ) ) ); ?></span>
			<?php if ( 'cancelled' === $b->status ) : ?><span class="meta"><em>Lemondva</em></span><?php endif; ?>
		</div>
		<div class="actions">
			<a class="btn btn-small btn-ghost" href="<?php echo esc_url( lif_url( '/foglalas/' . $b->token ) ); ?>">Részletek</a>
			<?php if ( $can_cancel && 'confirmed' === $b->status ) : ?>
				<form method="post" action="<?php echo esc_url( lif_url( '/fiok/lemondas' ) ); ?>" class="inline" onsubmit="return confirm('Biztosan lemondja az időpontot?')">
					<?php lif_nonce_field(); ?>
					<input type="hidden" name="id" value="<?php echo (int) $b->id; ?>">
					<button class="btn btn-small btn-danger">Lemondás</button>
				</form>
			<?php endif; ?>
		</div>
	</div>
	<?php
};
?>
<?php if ( $last_group ) : ?><p><a href="<?php echo esc_url( lif_url( '/' . $last_group->group_slug ) ); ?>">← Új időpont foglalása (<?php echo esc_html( $last_group->group_name ); ?>)</a></p><?php endif; ?>
<h1>Foglalásaim</h1>

<h2>Közelgő időpontok</h2>
<?php if ( ! $upcoming ) : ?><p class="muted">Nincs közelgő foglalása.</p><?php endif; ?>
<div class="list-cards"><?php foreach ( $upcoming as $b ) { $row( $b, strtotime( $b->start_utc ) > time() ); } ?></div>

<h2>Szinkronizálás a saját naptárába</h2>
<section class="card stack">
	<p>Iratkozzon fel a foglalásaira a saját naptárában – az új foglalások és a lemondások ezután <strong>maguktól</strong> megjelennek. Nincs korlát: bármennyi foglalás és bármennyi eszköz feliratkozhat.</p>
	<div class="actions">
		<a class="btn btn-primary" href="<?php echo esc_url( $cal['google'] ); ?>" target="_blank" rel="noopener">Hozzáadás a Google Naptárhoz</a>
		<a class="btn" href="<?php echo esc_url( $cal['webcal'], array( 'webcal', 'http', 'https' ) ); ?>">Apple / Outlook naptár</a>
	</div>
	<label>Naptárlink (más naptárakhoz: „Feliratkozás URL alapján”)
		<span class="share"><input readonly value="<?php echo esc_attr( $cal['https'] ); ?>" id="cal-link" onclick="this.select()"><button type="button" class="btn btn-small" data-copy="<?php echo esc_attr( $cal['https'] ); ?>">Másolás</button></span>
	</label>
	<p class="muted small">A Google Naptár a feliratkozott naptárakat néhány óránként frissíti (ezt a Google szabja meg, nem állítható), az Apple és az Outlook általában óránként. A link személyes: ne ossza meg. Ha mégis kikerült, készítsen újat.</p>
	<form method="post" action="<?php echo esc_url( lif_url( '/fiok/naptar-uj' ) ); ?>" class="inline">
		<?php lif_nonce_field(); ?>
		<button class="btn btn-small btn-ghost">Új naptárlink készítése</button>
	</form>
</section>

<?php if ( $past ) : ?>
<h2>Korábbi időpontok</h2>
<div class="list-cards"><?php foreach ( $past as $b ) { $row( $b, false ); } ?></div>
<?php endif; ?>

<h2>Fiókbeállítások</h2>
<div class="grid">
	<form method="post" action="<?php echo esc_url( lif_url( '/fiok/adatok' ) ); ?>" class="card stack">
		<?php lif_nonce_field(); ?>
		<h3>Adataim</h3>
		<label>Név<input name="name" required value="<?php echo esc_attr( $user->display_name ); ?>" autocomplete="name"></label>
		<label>E-mail<input value="<?php echo esc_attr( $user->user_email ); ?>" readonly></label>
		<label>Telefon<input name="phone" value="<?php echo esc_attr( get_user_meta( $user->ID, 'lif_phone', true ) ); ?>" autocomplete="tel"></label>
		<label>Jelenlegi jelszó (csak jelszócseréhez)<input name="current_password" type="password" autocomplete="current-password"></label>
		<label>Új jelszó<input name="new_password" type="password" minlength="8" autocomplete="new-password"></label>
		<button class="btn btn-primary">Mentés</button>
	</form>
	<div class="card stack">
		<h3>Kétfaktoros hitelesítés</h3>
		<p class="muted"><?php echo lif_2fa_enabled( $user->ID ) ? 'Bekapcsolva ✓' : 'Nincs bekapcsolva. Nem kötelező, de védi a fiókját, ha a jelszava illetéktelen kézbe kerül.'; ?></p>
		<div><a class="btn" href="<?php echo esc_url( lif_url( '/fiok/2fa' ) ); ?>"><?php echo lif_2fa_enabled( $user->ID ) ? 'Kezelés' : 'Bekapcsolás'; ?></a></div>
		<?php if ( ! $user->has_cap( 'lif_pastor' ) ) : ?>
		<h3>Fiók törlése</h3>
		<form method="post" action="<?php echo esc_url( lif_url( '/fiok/torles' ) ); ?>" class="stack" onsubmit="return confirm('Biztosan törli a fiókját?')">
			<?php lif_nonce_field(); ?>
			<label>Jelszó<input name="password" type="password" required autocomplete="current-password"></label>
			<button class="btn btn-danger">Fiók törlése</button>
		</form>
		<?php endif; ?>
	</div>
</div>
