<?php
/** Közös oldalkeret. Változók: $template, $title, $group, $flash, $user, $org + a sablon saját változói. */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$lif_brand = $group ? $group->name : ( $org ? $org : get_bloginfo( 'name' ) );
if ( $portal ) {
	$lif_brand = 'Lelkészi felület – ' . ( $org ? $org : get_bloginfo( 'name' ) );
}
?><!doctype html>
<html lang="hu">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
	<title><?php echo esc_html( $title . ' – ' . $lif_brand ); ?></title>
	<link rel="stylesheet" href="<?php echo esc_url( LIF_URL . 'assets/style.css?v=' . LIF_VERSION ); ?>">
</head>
<body class="lif<?php echo $portal ? ' lif-portal' : ''; ?>">
<aside class="sabbath"><img src="<?php echo esc_url( LIF_URL . 'assets/logo/adventist-symbol-tm--white.svg' ); ?>" alt="Hetednapi Adventista Egyház – szimbólum" width="76" height="76"></aside>
<header class="topbar">
	<div class="wrap topbar-inner">
		<?php if ( $portal ) : ?>
			<a class="brand" href="<?php echo esc_url( lif_portal_url() ); ?>"><img class="brand-logo" src="<?php echo esc_url( LIF_URL . 'assets/logo/adventist-symbol-tm--white.svg' ); ?>" alt="" width="34" height="34"><?php echo esc_html( $lif_brand ); ?></a>
		<?php elseif ( $group ) : ?>
			<a class="brand" href="<?php echo esc_url( lif_group_url( $group ) ); ?>"><img class="brand-logo" src="<?php echo esc_url( LIF_URL . 'assets/logo/adventist-symbol-tm--white.svg' ); ?>" alt="" width="34" height="34"><?php echo esc_html( $group->name ); ?></a>
		<?php else : ?>
			<span class="brand"><img class="brand-logo" src="<?php echo esc_url( LIF_URL . 'assets/logo/adventist-symbol-tm--white.svg' ); ?>" alt="" width="34" height="34"><?php echo esc_html( $lif_brand ); ?></span>
		<?php endif; ?>
		<nav>
			<?php if ( $portal ) : ?>
				<?php if ( $user->exists() && $user->has_cap( 'lif_pastor' ) ) : ?>
					<span class="muted small"><?php echo esc_html( $user->display_name ); ?></span>
					<form method="post" action="<?php echo esc_url( lif_portal_url( 'kilepes' ) ); ?>" class="inline">
						<?php lif_nonce_field(); ?>
						<button class="btn btn-small">Kijelentkezés</button>
					</form>
				<?php endif; ?>
			<?php elseif ( $user->exists() ) : ?>
				<a href="<?php echo esc_url( lif_url( '/fiok' ) ); ?>">Foglalásaim</a>
				<form method="post" action="<?php echo esc_url( lif_url( '/fiok/kilepes' ) ); ?>" class="inline">
					<?php lif_nonce_field(); ?>
					<input type="hidden" name="vissza" value="<?php echo esc_attr( $group ? lif_group_url( $group ) : '' ); ?>">
					<button class="btn btn-small">Kijelentkezés</button>
				</form>
			<?php elseif ( 'landing' !== $template && 'invite' !== $template && ! $portal ) : ?>
				<a href="<?php echo esc_url( add_query_arg( 'vissza', rawurlencode( $group ? lif_group_url( $group ) : lif_url( '/fiok' ) ), lif_url( '/fiok/belepes' ) ) ); ?>">Belépés</a>
			<?php endif; ?>
		</nav>
	</div>
</header>
<?php if ( $portal && $user->exists() && $user->has_cap( 'lif_pastor' ) && 'portal-login' !== $template ) : ?>
<nav class="subnav" aria-label="Lelkészi menü">
	<div class="wrap">
		<?php foreach ( LIF_PORTAL_PAGES as $slug => $item ) : if ( ! current_user_can( $item[1] ) ) { continue; } ?>
			<a href="<?php echo esc_url( lif_portal_url( $slug ) ); ?>" class="<?php echo $portal_sub === $slug ? 'active' : ''; ?>"><?php echo esc_html( $item[0] ); ?></a>
		<?php endforeach; ?>
	</div>
</nav>
<?php endif; ?>
<main class="wrap">
	<?php if ( $flash ) : ?>
		<div class="alert alert-<?php echo esc_attr( $flash['type'] ); ?>"><?php echo esc_html( $flash['text'] ); ?></div>
	<?php endif; ?>
	<?php include LIF_DIR . 'templates/' . $template . '.php'; ?>
</main>
<footer class="footer">
	<div class="wrap"><?php echo esc_html( $org ? $org : get_bloginfo( 'name' ) ); ?> · <a href="<?php echo esc_url( lif_url( '/adatkezeles' ) ); ?>">Adatkezelési tájékoztató</a>
		<span class="tm">Az adventista szimbólum a Hetednapi Adventista Egyház Generálkonferenciájának védjegye.</span></div>
</footer>
<script src="<?php echo esc_url( LIF_URL . 'assets/front.js?v=' . LIF_VERSION ); ?>"></script>
</body>
</html>
