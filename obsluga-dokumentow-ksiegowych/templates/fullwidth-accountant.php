<?php
/**
 * Szablon pełnoekranowy strony księgowego (bez nagłówka, stopki i boku motywu).
 * Używany, gdy w ustawieniach włączono "Strona księgowego w pełnym ekranie".
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
		html, body.pit-accountant-fullscreen { margin: 0 !important; padding: 0 !important; width: 100% !important; height: 100% !important; overflow-x: hidden; }
		body.pit-accountant-fullscreen .pit-fullscreen-inner { margin: 0 !important; padding: 0 !important; width: 100% !important; min-height: 100%; box-sizing: border-box; display: flex; flex-direction: column; align-items: center; }
		body.pit-accountant-fullscreen .pit-accountant-panel { width: 96% !important; max-width: 96% !important; margin-left: auto !important; margin-right: auto !important; box-sizing: border-box; }
	</style>
</head>
<body class="pit-accountant-fullscreen">
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
