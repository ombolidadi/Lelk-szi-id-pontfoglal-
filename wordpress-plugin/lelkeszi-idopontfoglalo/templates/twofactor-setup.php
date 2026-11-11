<?php if ( ! defined( 'ABSPATH' ) ) { exit; } ?>
<p><a href="<?php echo esc_url( $back ); ?>">← Vissza</a></p>
<section class="card narrow stack">
	<h1>Kétfaktoros hitelesítés</h1>
	<?php if ( $error ) : ?><div class="alert alert-error"><?php echo esc_html( $error ); ?></div><?php endif; ?>

	<?php if ( $codes ) : ?>
		<div class="alert alert-success">A kétfaktoros hitelesítés be van kapcsolva.</div>
		<h2>Tartalékkódok</h2>
		<p>Ha elveszíti a telefonját, ezekkel a kódokkal tud belépni. Mindegyik <strong>egyszer</strong> használható. Írja le vagy mentse el őket biztonságos helyre – <strong>most látja őket utoljára</strong>.</p>
		<ul class="codes"><?php foreach ( $codes as $c ) : ?><li><code><?php echo esc_html( $c ); ?></code></li><?php endforeach; ?></ul>
		<p><a class="btn btn-primary" href="<?php echo esc_url( $back ); ?>">Kész, elmentettem</a></p>

	<?php elseif ( $enabled ) : ?>
		<div class="alert alert-success">Bekapcsolva. Belépéskor a jelszó után a hitelesítő alkalmazás kódját is kérjük.</div>
		<p class="muted">Fel nem használt tartalékkódok: <strong><?php echo (int) $backup_left; ?></strong></p>
		<h2>Kikapcsolás</h2>
		<form method="post" class="stack">
			<?php lif_nonce_field(); ?>
			<input type="hidden" name="do" value="disable">
			<label>Az alkalmazásban látható kód vagy egy tartalékkód<input name="code" required autocomplete="one-time-code" maxlength="11"></label>
			<button class="btn btn-danger">Kétfaktoros hitelesítés kikapcsolása</button>
		</form>

	<?php else : ?>
		<p>A kétfaktoros hitelesítés <strong>nem kötelező</strong>, de erősen ajánlott: így a jelszava mellé a telefonja is kell a belépéshez.</p>
		<ol class="steps">
			<li>Telepítsen egy hitelesítő alkalmazást (pl. Google Authenticator, Microsoft Authenticator vagy Authy).</li>
			<li>Olvassa be vele ezt a QR-kódot:
				<div class="qr" id="lif-qr" data-uri="<?php echo esc_attr( $setup['uri'] ); ?>"></div>
				<p class="muted small">Ha nem tudja beolvasni, írja be kézzel ezt a kulcsot: <code><?php echo esc_html( $setup['secret'] ); ?></code></p>
			</li>
			<li>Írja be az alkalmazásban megjelenő 6 jegyű kódot:</li>
		</ol>
		<form method="post" class="stack">
			<?php lif_nonce_field(); ?>
			<input type="hidden" name="do" value="enable">
			<label>Ellenőrző kód<input name="code" required autocomplete="one-time-code" inputmode="numeric" pattern="[0-9 ]{6,7}" maxlength="7"></label>
			<button class="btn btn-primary">Bekapcsolás</button>
		</form>
		<script src="<?php echo esc_url( LIF_URL . 'assets/qrcode.js?v=' . LIF_VERSION ); ?>"></script>
	<?php endif; ?>
</section>
