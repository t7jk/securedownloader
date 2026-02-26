<?php
/**
 * Szablon pełnoekranowy strony podatnika (bez nagłówka, stopki i boku motywu).
 *
 * @package Obsluga_dokumentow_ksiegowych
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php wp_head(); ?>
	<style type="text/css">
		html, body.pit-client-fullscreen { margin: 0 !important; padding: 0 !important; width: 100% !important; height: 100% !important; overflow-x: hidden; }
		body.pit-client-fullscreen .pit-fullscreen-inner { margin: 0 !important; padding: 0 !important; width: 100% !important; min-height: 100%; box-sizing: border-box; display: flex; justify-content: center; }
		body.pit-client-fullscreen .pit-client-page { width: 80% !important; max-width: 80% !important; margin-left: auto !important; margin-right: auto !important; box-sizing: border-box; }
	</style>
</head>
<body class="pit-client-fullscreen">
	<div class="pit-fullscreen-inner">
		<?php
		while ( have_posts() ) {
			the_post();
			the_content();
		}
		?>
	</div>
	<?php wp_footer(); ?>
</body>
</html>
