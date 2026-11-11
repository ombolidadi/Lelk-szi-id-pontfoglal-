<?php if ( ! defined( 'ABSPATH' ) ) { exit; } ?>
<section class="card narrow stack">
	<h1>Lelkészi belépés</h1>
	<?php if ( $error ) : ?><div class="alert alert-error"><?php echo esc_html( $error ); ?></div><?php endif; ?>
	<form method="post" class="stack">
		<?php lif_nonce_field(); ?>
		<label>E-mail cím<input name="email" type="email" required autocomplete="username"></label>
		<label>Jelszó<input name="password" type="password" required autocomplete="current-password"></label>
		<label class="check"><input type="checkbox" name="remember"> Maradjak bejelentkezve ezen az eszközön</label>
		<button class="btn btn-primary">Belépés</button>
	</form>
	<p class="muted small">Lelkészi fiókot csak meghívóval lehet létrehozni. Elfelejtett jelszó esetén kérjen visszaállító linket a gyülekezet adminisztrátorától.</p>
</section>
