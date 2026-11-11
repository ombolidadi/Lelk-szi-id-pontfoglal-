<?php if ( ! defined( 'ABSPATH' ) ) { exit; } ?>
<p><a href="<?php echo esc_url( lif_group_url( $group ) ); ?>">← <?php echo esc_html( $group->name ); ?></a></p>
<section class="card" style="--accent: <?php echo esc_attr( $service->color ); ?>">
	<h1><?php echo esc_html( $service->name ); ?></h1>
	<?php if ( $service->description ) : ?><p><?php echo esc_html( $service->description ); ?></p><?php endif; ?>
	<p class="meta"><?php echo (int) $service->duration_min; ?> perc<?php echo $service->location ? ' · ' . esc_html( $service->location ) : ''; ?></p>
</section>

<?php if ( $error ) : ?><div class="alert alert-error"><?php echo esc_html( $error ); ?></div><?php endif; ?>

<form method="post" id="booking-form" class="card" data-api="<?php echo esc_url( rest_url( 'lif/v1/slots' ) ); ?>" data-group="<?php echo esc_attr( $group->slug ); ?>" data-service="<?php echo (int) $service->id; ?>">
	<?php lif_nonce_field(); ?>
	<input type="hidden" name="start" id="start" value="<?php echo esc_attr( $form['start'] ?? '' ); ?>">

	<h2>1. Lelkész</h2>
	<div class="pastor-pick">
		<?php if ( count( $pastors ) > 1 ) : ?>
			<label class="radio-card"><input type="radio" name="pastor" value="any" <?php checked( ! $preselect ); ?>> <span>Bármelyik lelkész<small><?php echo esc_html( $group->name ); ?></small></span></label>
		<?php endif; ?>
		<?php foreach ( $pastors as $p ) : $meta = array_filter( array( lif_pastor_meta( $p->ID, 'title' ), lif_pastor_meta( $p->ID, 'congregation' ) ) ); ?>
			<label class="radio-card"><input type="radio" name="pastor" value="<?php echo (int) $p->ID; ?>" <?php checked( $preselect === $p->ID || 1 === count( $pastors ) ); ?>>
				<span><?php echo esc_html( $p->display_name ); ?><?php if ( $meta ) : ?><small><?php echo esc_html( implode( ' · ', $meta ) ); ?></small><?php endif; ?></span></label>
		<?php endforeach; ?>
	</div>

	<h2>2. Időpont</h2>
	<div class="week-nav">
		<button type="button" class="btn btn-ghost" id="prev-week">‹ Előző</button>
		<strong id="week-label"></strong>
		<button type="button" class="btn btn-ghost" id="next-week">Következő ›</button>
	</div>
	<div id="slots" class="slots" aria-live="polite"><p class="muted">Betöltés…</p></div>
	<p id="chosen" class="chosen" hidden></p>

	<h2>3. Adatai</h2>
	<?php if ( $user->exists() ) : ?>
		<p class="muted small">Bejelentkezve: <strong><?php echo esc_html( $user->user_email ); ?></strong>. A foglalás megjelenik a Foglalásaim oldalon és a naptár-feliratkozásában.</p>
	<?php else : ?>
		<p class="muted small">Van fiókja? <a href="<?php echo esc_url( add_query_arg( 'vissza', rawurlencode( lif_url( '/' . $group->slug . '/' . $service->slug ) ), lif_url( '/fiok/belepes' ) ) ); ?>">Jelentkezzen be</a> – vagy <a href="<?php echo esc_url( add_query_arg( 'vissza', rawurlencode( lif_url( '/' . $group->slug . '/' . $service->slug ) ), lif_url( '/fiok/regisztracio' ) ) ); ?>">hozzon létre egyet</a>, hogy lássa a foglalásait és a saját naptárába szinkronizálhassa őket.</p>
	<?php endif; ?>
	<div class="form-grid">
		<label>Név *<input name="name" required maxlength="120" value="<?php echo esc_attr( $form['name'] ?? '' ); ?>" autocomplete="name"></label>
		<label>E-mail *<input name="email" type="email" required maxlength="200" value="<?php echo esc_attr( $form['email'] ?? '' ); ?>" autocomplete="email" <?php echo $user->exists() ? 'readonly' : ''; ?>></label>
		<label>Telefon<input name="phone" type="tel" maxlength="40" value="<?php echo esc_attr( $form['phone'] ?? '' ); ?>" autocomplete="tel"></label>
	</div>
	<label>Megjegyzés (nem kötelező)<textarea name="note" rows="3" maxlength="2000" placeholder="Miben segíthetünk?"><?php echo esc_textarea( $form['note'] ?? '' ); ?></textarea></label>
	<label class="check"><input type="checkbox" name="consent" required> <span>Elolvastam és elfogadom az <a href="<?php echo esc_url( lif_url( '/adatkezeles' ) ); ?>" target="_blank">adatkezelési tájékoztatót</a>.</span></label>
	<button class="btn btn-primary" id="submit" disabled>Időpont lefoglalása</button>
</form>
<script src="<?php echo esc_url( LIF_URL . 'assets/booking.js?v=' . LIF_VERSION ); ?>"></script>
