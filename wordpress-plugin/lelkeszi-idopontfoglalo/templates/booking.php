<?php if ( ! defined( 'ABSPATH' ) ) { exit; } ?>
<section class="card narrow stack">
	<?php if ( 'cancelled' === $b->status ) : ?>
		<h1>Lemondott foglalás</h1>
		<p>Ez a foglalás le lett mondva.</p>
	<?php else : ?>
		<h1>Foglalás visszaigazolása</h1>
		<p>Köszönjük! Időpontját rögzítettük. Kérjük, <strong>mentse el ezt az oldalt</strong> (vagy a linkjét), ha később le szeretné mondani<?php echo $b->client_email ? ' – a linket e-mailben is elküldtük' : ''; ?>.</p>
	<?php endif; ?>
	<dl class="details">
		<dt>Szolgáltatás</dt><dd><?php echo esc_html( $b->service_name ); ?></dd>
		<dt>Lelkész</dt><dd><?php echo esc_html( $pastor ? $pastor->display_name : '–' ); ?><?php $c = $pastor ? lif_pastor_meta( $pastor->ID, 'congregation' ) : ''; echo $c ? ' <span class="muted">(' . esc_html( $c ) . ')</span>' : ''; ?></dd>
		<dt>Időpont</dt><dd><?php echo esc_html( $when ); ?></dd>
		<?php if ( $b->location ) : ?><dt>Helyszín</dt><dd><?php echo esc_html( $b->location ); ?></dd><?php endif; ?>
		<dt>Név</dt><dd><?php echo esc_html( $b->client_name ); ?></dd>
		<?php if ( $b->client_email ) : ?><dt>E-mail</dt><dd><?php echo esc_html( $b->client_email ); ?></dd><?php endif; ?>
		<?php if ( $b->client_phone ) : ?><dt>Telefon</dt><dd><?php echo esc_html( $b->client_phone ); ?></dd><?php endif; ?>
	</dl>
	<?php if ( 'confirmed' === $b->status && ! $is_past ) : ?>
		<p class="actions">
			<a class="btn" href="<?php echo esc_url( $gcal ); ?>" target="_blank" rel="noopener">Hozzáadás a Google Naptárhoz</a>
			<?php if ( $is_mine ) : ?><a class="btn btn-ghost" href="<?php echo esc_url( lif_url( '/fiok' ) ); ?>">Foglalásaim</a><?php endif; ?>
		</p>
		<form method="post" onsubmit="return confirm('Biztosan lemondja az időpontot?')">
			<?php lif_nonce_field(); ?>
			<button class="btn btn-danger">Időpont lemondása</button>
		</form>
	<?php endif; ?>
	<?php if ( $b->group_slug ) : ?><p><a href="<?php echo esc_url( lif_url( '/' . $b->group_slug ) ); ?>">← <?php echo esc_html( $b->group_name ); ?></a></p><?php endif; ?>
</section>
