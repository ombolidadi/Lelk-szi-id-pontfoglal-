<?php if ( ! defined( 'ABSPATH' ) ) { exit; } ?>
<h1><?php echo esc_html( $title ); ?></h1>
<?php call_user_func( $portal_cb ); ?>
