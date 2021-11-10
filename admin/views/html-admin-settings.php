<?php 

/**
*  html-admin-settings
*
*  View to output settings
*/

if( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

?>
<div class="wrap">
	<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
	<form action="options.php" method="post">
		
		<?php
		
		// // output security fields
		settings_fields( 'ipfs-block-store_options' );
		
		// // output setting sections
		do_settings_sections( 'ipfs-block-store' );
		
		// // submit button
		submit_button();
		
		?>
		
	</form>
</div>