<?php if ( ! defined( 'ABSPATH' ) ) { exit; } ?>
<section class="card narrow stack">
	<h1>Fiók létrehozása</h1>
	<p class="muted">A fiókkal egy helyen láthatja a foglalásait, lemondhatja őket, és feliratkozhat rájuk a saját naptárában. Foglalni fiók nélkül is lehet.</p>
	<?php if ( $error ) : ?><div class="alert alert-error"><?php echo esc_html( $error ); ?></div><?php endif; ?>
	<form method="post" class="stack">
		<?php lif_nonce_field(); ?>
		<input type="hidden" name="vissza" value="<?php echo esc_attr( $back ); ?>">
		<label>Név *<input name="name" required maxlength="120" value="<?php echo esc_attr( $form['name'] ?? '' ); ?>" autocomplete="name"></label>
		<label>E-mail cím *<input name="email" type="email" required maxlength="200" value="<?php echo esc_attr( $form['email'] ?? '' ); ?>" autocomplete="email"></label>
		<label>Telefon<input name="phone" type="tel" maxlength="40" value="<?php echo esc_attr( $form['phone'] ?? '' ); ?>" autocomplete="tel"></label>
		<label>Jelszó (legalább 8 karakter) *<input name="password" type="password" minlength="8" required autocomplete="new-password"></label>
		<label>Jelszó még egyszer *<input name="password2" type="password" minlength="8" required autocomplete="new-password"></label>
		<label class="check"><input type="checkbox" name="consent" required> <span>Elolvastam és elfogadom az <a href="<?php echo esc_url( lif_url( '/adatkezeles' ) ); ?>" target="_blank">adatkezelési tájékoztatót</a>.</span></label>
		<button class="btn btn-primary">Fiók létrehozása</button>
	</form>
	<p class="small">Van már fiókja? <a href="<?php echo esc_url( lif_url( '/fiok/belepes' ) ); ?>">Belépés</a></p>
</section>
