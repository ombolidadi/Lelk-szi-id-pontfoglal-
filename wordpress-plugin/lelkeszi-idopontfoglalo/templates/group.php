<?php if ( ! defined( 'ABSPATH' ) ) { exit; } ?>
<section class="hero">
	<p class="eyebrow"><?php echo esc_html( lif_kind_label( $group->kind ) ); ?></p>
	<h1><?php echo esc_html( $group->name ); ?></h1>
	<p><?php echo esc_html( $group->description ? $group->description : 'Foglaljon időpontot lelkészeinkhez beszélgetésre, lelkigondozásra vagy egyéb alkalomra.' ); ?></p>
</section>

<?php if ( ! $user->exists() ) : ?>
	<p class="muted small">Van fiókja? <a href="<?php echo esc_url( add_query_arg( 'vissza', rawurlencode( lif_group_url( $group ) ), lif_url( '/fiok/belepes' ) ) ); ?>">Jelentkezzen be</a>, hogy egy helyen lássa a foglalásait, és a saját naptárába szinkronizálhassa őket. Fiók nélkül is foglalhat.</p>
<?php endif; ?>

<h2>Válasszon szolgáltatást</h2>
<?php if ( ! $services ) : ?>
	<p class="muted">Jelenleg nincs foglalható szolgáltatás.</p>
<?php endif; ?>
<div class="grid">
	<?php foreach ( $services as $s ) : ?>
		<a class="card service-card" href="<?php echo esc_url( lif_url( '/' . $group->slug . '/' . $s->slug ) ); ?>" style="--accent: <?php echo esc_attr( $s->color ); ?>">
			<h3><?php echo esc_html( $s->name ); ?></h3>
			<?php if ( $s->description ) : ?><p><?php echo esc_html( $s->description ); ?></p><?php endif; ?>
			<p class="meta"><?php echo (int) $s->duration_min; ?> perc<?php echo $s->location ? ' · ' . esc_html( $s->location ) : ''; ?></p>
			<span class="btn btn-small">Időpontot foglalok</span>
		</a>
	<?php endforeach; ?>
</div>

<?php if ( $pastors ) : ?>
<h2>Lelkészeink</h2>
<div class="grid">
	<?php foreach ( $pastors as $p ) : $u = $p['user']; $meta = array_filter( array( lif_pastor_meta( $u->ID, 'title' ), lif_pastor_meta( $u->ID, 'congregation' ) ) ); ?>
		<div class="card">
			<h3><?php echo esc_html( $u->display_name ); ?></h3>
			<?php if ( $meta ) : ?><p class="meta"><?php echo esc_html( implode( ' · ', $meta ) ); ?></p><?php endif; ?>
			<?php if ( $u->description ) : ?><p><?php echo esc_html( $u->description ); ?></p><?php endif; ?>
			<div class="chips">
				<?php foreach ( $p['services'] as $s ) : ?>
					<a class="chip" href="<?php echo esc_url( add_query_arg( 'lelkesz', $u->ID, lif_url( '/' . $group->slug . '/' . $s->slug ) ) ); ?>"><?php echo esc_html( $s->name ); ?></a>
				<?php endforeach; ?>
			</div>
		</div>
	<?php endforeach; ?>
</div>
<?php endif; ?>
