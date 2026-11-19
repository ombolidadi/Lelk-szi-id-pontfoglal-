<?php if ( ! defined( 'ABSPATH' ) ) { exit; } ?>
<section class="card narrow stack">
	<?php if ( $existing ) : ?>
		<h1>Új jelszó beállítása</h1>
		<p>Kedves <?php echo esc_html( $existing->display_name ); ?>! Adjon meg új jelszót a lelkészi felülethez.</p>
	<?php else : ?>
		<h1>Regisztráció a lelkészi felületre</h1>
		<p>Meghívást kapott a(z) <?php echo esc_html( $org ); ?> időpontfoglaló rendszerébe. A fiókja ehhez az e-mail címhez készül: <strong><?php echo esc_html( $invite->email ); ?></strong></p>
	<?php endif; ?>
	<?php if ( $error ) : ?><div class="alert alert-error"><?php echo esc_html( $error ); ?></div><?php endif; ?>
	<form method="post" class="stack">
		<?php lif_nonce_field(); ?>
		<?php if ( ! $existing ) : ?>
			<label>Név *<input name="name" required maxlength="120" value="<?php echo esc_attr( $form['name'] ?? '' ); ?>" autocomplete="name"></label>
			<label>Megnevezés (pl. „parókus lelkész”)<input name="title" maxlength="120" value="<?php echo esc_attr( $form['title'] ?? '' ); ?>"></label>
		<?php endif; ?>
		<label>Jelszó (legalább 10 karakter) *<input name="password" type="password" minlength="10" required autocomplete="new-password"></label>
		<label>Jelszó még egyszer *<input name="password2" type="password" minlength="10" required autocomplete="new-password"></label>
		<button class="btn btn-primary"><?php echo $existing ? 'Jelszó mentése' : 'Regisztráció'; ?></button>
	</form>
	<p class="muted small">Belépés után a Profilom oldalon bekapcsolhatja a kétfaktoros hitelesítést.</p>
</section>
