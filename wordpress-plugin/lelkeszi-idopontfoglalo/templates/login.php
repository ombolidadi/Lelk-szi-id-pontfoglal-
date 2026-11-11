<?php if ( ! defined( 'ABSPATH' ) ) { exit; } ?>
<section class="card narrow stack">
	<h1>Belépés</h1>
	<p class="muted">Lépjen be, hogy lássa a foglalásait, és a saját naptárába szinkronizálhassa őket.</p>
	<?php if ( $error ) : ?><div class="alert alert-error"><?php echo esc_html( $error ); ?></div><?php endif; ?>
	<form method="post" class="stack">
		<?php lif_nonce_field(); ?>
		<input type="hidden" name="vissza" value="<?php echo esc_attr( $back ); ?>">
		<label>E-mail cím<input name="email" type="email" required autocomplete="username"></label>
		<label>Jelszó<input name="password" type="password" required autocomplete="current-password"></label>
		<button class="btn btn-primary">Belépés</button>
	</form>
	<p class="small">Még nincs fiókja? <a href="<?php echo esc_url( $back ? add_query_arg( 'vissza', rawurlencode( $back ), lif_url( '/fiok/regisztracio' ) ) : lif_url( '/fiok/regisztracio' ) ); ?>">Fiók létrehozása</a> · <a href="<?php echo esc_url( wp_lostpassword_url() ); ?>">Elfelejtett jelszó</a></p>
</section>
