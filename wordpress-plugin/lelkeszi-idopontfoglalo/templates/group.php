<?php if ( ! defined( 'ABSPATH' ) ) { exit; } ?>
<section class="hero">
	<p class="eyebrow"><?php echo esc_html( lif_kind_label( $group->kind ) ); ?></p>
	<h1><?php echo esc_html( $group->name ); ?></h1>
	<p><?php echo esc_html( $group->description ? $group->description : 'Foglaljon időpontot lelkészeinkhez beszélgetésre, lelkigondozásra vagy egyéb alkalomra.' ); ?></p>
</section>

<?php if ( $error ) : ?><div class="alert alert-error" id="form-error"><?php echo esc_html( $error ); ?></div><?php endif; ?>

<?php if ( ! $pastors ) : ?>
	<p class="muted">Jelenleg nincs foglalható időpont ebben a csoportban.</p>
<?php else : ?>
<form method="post" id="booking-form" class="wizard" novalidate data-lif="<?php echo esc_attr( wp_json_encode( $data ) ); ?>">
	<?php lif_nonce_field(); ?>
	<input type="hidden" name="pastor" id="f-pastor">
	<input type="hidden" name="service" id="f-service">
	<input type="hidden" name="start" id="f-start">

	<section class="card step" id="step-pastor">
		<h2><span class="step-no">1</span> Kinél szeretne időpontot foglalni?</h2>
		<div class="choice-grid" id="pastor-list"></div>
	</section>

	<section class="card step" id="step-service" hidden>
		<h2><span class="step-no">2</span> Milyen alkalomra szeretne időpontot?</h2>
		<div class="choice-grid" id="service-list"></div>
	</section>

	<section class="card step" id="step-time" hidden>
		<h2><span class="step-no">3</span> Mikor?</h2>
		<div class="week-nav">
			<button type="button" class="btn btn-ghost" id="prev-week">‹ Előző</button>
			<strong id="week-label"></strong>
			<button type="button" class="btn btn-ghost" id="next-week">Következő ›</button>
		</div>
		<div id="slots" class="slots" aria-live="polite"></div>
	</section>

	<section class="card step" id="step-details" hidden>
		<div class="summary" id="summary"></div>

		<?php if ( ! $user->exists() ) : ?>
			<div class="optional-login">
				<p><strong>Van fiókja?</strong> <a id="login-link" href="<?php echo esc_url( lif_url( '/fiok/belepes' ) ); ?>">Jelentkezzen be</a> – akkor nem kell kitöltenie az adatait, és egy helyen látja az időpontjait. A választása megmarad.</p>
				<p class="muted small">A bejelentkezés nem kötelező: fiók nélkül is foglalhat, elég a neve és egy elérhetőség.</p>
			</div>
		<?php else : ?>
			<p class="muted small">Bejelentkezve: <strong><?php echo esc_html( $user->display_name ); ?></strong> – adatait kitöltöttük. A foglalás megjelenik a Foglalásaim oldalon.</p>
		<?php endif; ?>

		<h2><span class="step-no">4</span> Adatai</h2>
		<div class="form-grid">
			<label>Név *<input name="name" id="f-name" maxlength="120" value="<?php echo esc_attr( $form['name'] ?? '' ); ?>" autocomplete="name"></label>
			<label>E-mail<input name="email" id="f-email" type="email" maxlength="200" value="<?php echo esc_attr( $form['email'] ?? '' ); ?>" autocomplete="email" <?php echo $user->exists() ? 'readonly' : ''; ?>></label>
			<label>Telefon<input name="phone" id="f-phone" type="tel" maxlength="40" value="<?php echo esc_attr( $form['phone'] ?? '' ); ?>" autocomplete="tel" placeholder="pl. +36 30 123 4567"></label>
		</div>
		<p class="muted small">* A név kötelező, és adjon meg <strong>legalább egy elérhetőséget</strong> (e-mail vagy telefon). Visszaigazolást e-mailben küldünk, ha megadja.</p>
		<label>Megjegyzés (nem kötelező)<textarea name="note" rows="3" maxlength="2000" placeholder="Miben segíthetünk?"><?php echo esc_textarea( $form['note'] ?? '' ); ?></textarea></label>

		<?php if ( ! $user->exists() ) : ?>
			<div class="register-offer">
				<label class="check"><input type="checkbox" name="register" id="f-register" <?php checked( ! empty( $_POST['register'] ) ); ?>> <span><strong>Fiókot is létrehozok</strong> (nem kötelező) – így később bejelentkezve látom és lemondhatom az időpontjaimat, és a saját naptáramba szinkronizálhatom őket.</span></label>
				<div class="form-grid" id="register-fields" hidden>
					<label>Jelszó (legalább 8 karakter)<input name="password" id="f-password" type="password" minlength="8" autocomplete="new-password"></label>
					<label>Jelszó még egyszer<input name="password2" id="f-password2" type="password" minlength="8" autocomplete="new-password"></label>
					<p class="muted small full-row">A fiókhoz az e-mail címével lép majd be, ezért azt is adja meg.</p>
				</div>
			</div>
		<?php endif; ?>

		<label class="check"><input type="checkbox" name="consent" id="f-consent"> <span>Elolvastam és elfogadom az <a href="<?php echo esc_url( lif_url( '/adatkezeles' ) ); ?>" target="_blank">adatkezelési tájékoztatót</a>.</span></label>
		<div id="client-error" class="alert alert-error" hidden></div>
		<button class="btn btn-primary" id="submit">Időpont lefoglalása</button>
	</section>
</form>
<script src="<?php echo esc_url( LIF_URL . 'assets/booking.js?v=' . LIF_VERSION ); ?>"></script>
<?php endif; ?>
