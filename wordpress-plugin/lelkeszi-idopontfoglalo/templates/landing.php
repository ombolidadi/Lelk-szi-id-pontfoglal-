<?php if ( ! defined( 'ABSPATH' ) ) { exit; } ?>
<section class="card narrow center stack">
	<h1>Időpontfoglalás</h1>
	<p>Időpontot a gyülekezetétől vagy lelkészétől kapott <strong>foglalási linken</strong> keresztül kérhet.</p>
	<p class="muted">Ha már foglalt, és fiókot is létrehozott, itt láthatja a foglalásait.</p>
	<p><a class="btn <?php echo $user->exists() ? 'btn-primary' : ''; ?>" href="<?php echo esc_url( $user->exists() ? lif_url( '/fiok' ) : lif_url( '/fiok/belepes' ) ); ?>"><?php echo $user->exists() ? 'Foglalásaim' : 'Belépés a foglalásaimhoz'; ?></a></p>
</section>
