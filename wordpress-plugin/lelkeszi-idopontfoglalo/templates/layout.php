<?php
/** Közös oldalkeret. Változók: $template, $title, $group, $flash, $user, $org + a sablon saját változói. */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$lif_brand = $group ? $group->name : ( $org ? $org : get_bloginfo( 'name' ) );
?><!doctype html>
<html lang="hu">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
	<title><?php echo esc_html( $title . ' – ' . $lif_brand ); ?></title>
	<link rel="stylesheet" href="<?php echo esc_url( LIF_URL . 'assets/style.css?v=' . LIF_VERSION ); ?>">
</head>
<body class="lif">
<header class="topbar">
	<div class="wrap topbar-inner">
		<?php if ( $group ) : ?>
			<a class="brand" href="<?php echo esc_url( lif_group_url( $group ) ); ?>"><span class="brand-mark" aria-hidden="true">✝</span> <?php echo esc_html( $group->name ); ?></a>
		<?php else : ?>
			<span class="brand"><span class="brand-mark" aria-hidden="true">✝</span> <?php echo esc_html( $lif_brand ); ?></span>
		<?php endif; ?>
		<nav>
			<?php if ( $user->exists() ) : ?>
				<a href="<?php echo esc_url( lif_url( '/fiok' ) ); ?>">Foglalásaim</a>
				<form method="post" action="<?php echo esc_url( lif_url( '/fiok/kilepes' ) ); ?>" class="inline">
					<?php lif_nonce_field(); ?>
					<input type="hidden" name="vissza" value="<?php echo esc_attr( $group ? lif_group_url( $group ) : '' ); ?>">
					<button class="btn btn-small">Kijelentkezés</button>
				</form>
			<?php elseif ( 'landing' !== $template && 'invite' !== $template ) : ?>
				<a href="<?php echo esc_url( add_query_arg( 'vissza', rawurlencode( $group ? lif_group_url( $group ) : lif_url( '/fiok' ) ), lif_url( '/fiok/belepes' ) ) ); ?>">Belépés</a>
			<?php endif; ?>
		</nav>
	</div>
</header>
<main class="wrap">
	<?php if ( $flash ) : ?>
		<div class="alert alert-<?php echo esc_attr( $flash['type'] ); ?>"><?php echo esc_html( $flash['text'] ); ?></div>
	<?php endif; ?>
	<?php include LIF_DIR . 'templates/' . $template . '.php'; ?>
</main>
<footer class="footer">
	<div class="wrap"><?php echo esc_html( $org ? $org : get_bloginfo( 'name' ) ); ?> · <a href="<?php echo esc_url( lif_url( '/adatkezeles' ) ); ?>">Adatkezelési tájékoztató</a></div>
</footer>
<script src="<?php echo esc_url( LIF_URL . 'assets/front.js?v=' . LIF_VERSION ); ?>"></script>
</body>
</html>
