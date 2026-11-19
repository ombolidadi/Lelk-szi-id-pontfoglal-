<?php if ( ! defined( 'ABSPATH' ) ) { exit; } ?>
<section class="card narrow stack">
	<h1>Ellenőrző kód</h1>
	<p>Írja be a hitelesítő alkalmazásban látható 6 jegyű kódot. Ha nincs önnél a telefonja, használjon egy tartalékkódot.</p>
	<?php if ( $error ) : ?><div class="alert alert-error"><?php echo esc_html( $error ); ?></div><?php endif; ?>
	<form method="post" class="stack">
		<?php lif_nonce_field(); ?>
		<label>Kód<input name="code" required autofocus autocomplete="one-time-code" maxlength="11"></label>
		<button class="btn btn-primary">Belépés</button>
	</form>
</section>
