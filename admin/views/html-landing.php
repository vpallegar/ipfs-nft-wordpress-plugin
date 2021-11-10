<?php 

/**
*  html-landing
*
*  View to output settings
*/

if( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

	// Generate a custom nonce value.
	$add_meta_nonce = wp_create_nonce( 'IPFSBS_managecompany_nonce' ); 
	$formaction = "add";


?>
<div class="wrap">
	<h1><?php _e( 'Current IPFS Stored Posts', 'ipfs-block-store'); ?></h1>
	<?php
		if ( !empty($_GET['itemedit']) ) {
			echo '<div class="notice notice-success is-dismissible">
				<p>Item has been updated.</p>
			</div>';
		}
	?>

	<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" id="IPFSBS_landing_meta_form" >			

		<input type="hidden" name="action" value="IPFSBS_form_response">
		<input type="hidden" name="IPFSBS_managecompany_nonce" value="<?php echo $add_meta_nonce ?>" />	
		<input type="hidden" name="formaction" value="<?php echo $formaction ?>" />	


		<table class="table" cellpadding="5" cellspacing="10">
			<thead>
				<tr>
					<th>Title</th>
					<th>Hash</th>
					<th>File</th>
					<th>Size</th>
				</tr>
			</thead>	
			<tbody>
			<?php
				$args = array(
					'post_type' => ['post','page'],
					'numberposts' => -1,
					'post_status' => null,
					'meta_query' => array(
						array(
							'key'     => 'ipfsbs_hash',
							'value' => '', 
							'compare' => '!='
						)
					)
				);

				$settings = get_option('ipfs-block-store_options') ?? [];
				$posts = get_posts($args);
				$hostname = str_replace([':5001'], "", $settings['apibaseurl'] ?? "");
				foreach($posts as $post) {
					$ipfs = get_post_meta($post->ID, 'ipfsbs_hash', true);
					$node_data = json_decode($ipfs, true);
					if (!empty($node_data)) :
						echo "<tr><td>{$post->post_title}</td>
						<td>{$node_data['Hash']}</td>
						<td>{$node_data['Name']}</td>
						<td>{$node_data['Size']}</td>
						<td><a href='{$hostname}/ipfs/{$node_data['Hash']}' target='_blank'  class='button button-link-edit'>view</a></td></tr>";
					endif;
				}
			?>
			</tbody>
		</table>

		<?php if (!empty($settings['allowminting'])): ?>
		<h2><?php _e('Media Available For NFT\'s', 'ipfs-block-store');?></h2>


		<table class="table" cellpadding="5" cellspacing="10">
			<thead>
				<tr>
					<th>Image</th>
					<th>File</th>
					<th>Size</th>
					<th></th>
				</tr>
			</thead>	
			<tbody>
			<?php

				$args = array(
					'post_type' => 'attachment',
					'numberposts' => -1,
					'post_status' => null,
					'meta_query' => array(
						array(
							'key'     => 'ipfsbs_hash',
							'value' => '', 
							'compare' => '!='
						)
					)
				);

				$settings = get_option('ipfs-block-store_options') ?? [];
				$posts = get_posts($args);
				$hostname = str_replace([':5001'], "", $settings['apibaseurl'] ?? "");
				foreach($posts as $post) {
					$ipfs = get_post_meta($post->ID, 'ipfsbs_hash', true);
					$node_data = json_decode($ipfs, true);
					if (!empty($node_data)) :
						$image = wp_get_attachment_image( $post->ID, 'thumbnail' );
						echo "<tr><td>{$image}</td>
						<td>{$node_data['Name']}</td>
						<td>{$node_data['Size']}</td>
						<td><a href='{$hostname}/ipfs/{$node_data['Hash']}' target='_blank' class='button button-link-edit'>view</a> <a href='?page=ipfs-block-mint_nft&id={$post->ID}'  class='button button-link-delete'>mint nft</a></td></tr>";
					endif;
				}
			?>
			</tbody>
		</table>

		<?php endif; ?>
		
	</form>

</div>