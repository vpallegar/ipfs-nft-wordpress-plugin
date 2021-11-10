<?php 

/**
*  html-landing
*
*  View to output settings
*/

if( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

	// Generate a custom nonce value.
	$add_meta_nonce = wp_create_nonce( 'IPFSBS_managenft_nonce' ); 
	$formaction = "mint";

	$id = !empty($_GET['id']) ? (int) $_GET['id'] : 0;
	$settings = get_option('ipfs-block-store_options') ?? [];
	$hostname = str_replace([':5001'], "", $settings['apibaseurl']);


?>
<div class="wrap">
	<h1><?php _e( 'Mint NFT', 'ipfs-block-store'); ?></h1>
	<?php
		if ( empty($id) ) {
			echo '<div class="notice notice-error is-dismissible">
				<p>'._('No media item sent', 'ipfs-block-store').'</p>
			</div>';
		}
		elseif ( empty($settings['allowminting'])) {
			echo '<div class="notice notice-error is-dismissible">
				<p>'._('Minting is not enabled.  Please check the settings page.', 'ipfs-block-store').'</p>
			</div>';
		}
		elseif ( empty($settings['contractaddress_matic']) && empty($settings['contractaddress_eth'])) {
			echo '<div class="notice notice-error is-dismissible">
				<p>'._('No contract address set to Mint NFT\'s.  Add an Ethereum or polygon (Matic) contract address on the settings page.', 'ipfs-block-store').'</p>
			</div>';
		}

		else {
			$args = array(
				'post_type' => 'attachment',
				'post__in' => [$id],
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
			
			$media = get_posts($args);
		}
	?>

	<?php if (!empty($media)) : ?>
	<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" id="IPFSBS_nft_meta_form" >			

		<input type="hidden" name="action" value="IPFSBS_mintnft">
		<input type="hidden" name="IPFSBS_managenft_nonce" value="<?php echo $add_meta_nonce ?>" />	
		<input type="hidden" name="formaction" value="<?php echo $formaction ?>" />		
		<input type="hidden" name="media_id" value="<?php echo $id ?>" />	


		<table class="table">
			<thead>
				<tr>
					<th>Image</th>
					<th>File</th>
					<th>Size</th>
				</tr>
			</thead>	
			<tbody>
			<?php
				foreach($media as $post) {
					$ipfs = get_post_meta($post->ID, 'ipfsbs_hash', true);
					$node_data = json_decode($ipfs, true);
					if (!empty($node_data)) :
						$image = wp_get_attachment_image( $post->ID, 'thumbnail' );
						$ipfs_url = $hostname.'/ipfs/'.$node_data['Hash'];
						echo "<tr><td>{$image}</td>
						<td>{$node_data['Name']}</td>
						<td>{$node_data['Size']}</td>
						</tr>";
					endif;
				}
				echo "<p><a href=\"{$ipfs_url}\" target=\"_blank\">{$ipfs_url}</a></p>";
			?>
			</tbody>
		</table>

		
		<?php

			if ('delete' == $formaction) :
		?>
                <p>
                    <label><strong>Are you sure you want to delete item "<?php echo esc_attr($existing_data['name']) ?? "";?>"?</label>
                    <br /><br />
                    <label><input type="checkbox" name="delete-item" value="1" required /> <small>check here to continue</small></label>
                </p>


			<p class="submit"><input type="submit" name="submit" id="submit" class="button button-primary button-danger" value="Delete Company"></p>
		<?php else : ?>

		<table class="form-table" role="presentation">
		<tbody>
			<?php
				$fields = [
					"name" => ["label"=>"Name", "type"=>"text", "required"=>true],
					"description" => ["label"=>"Description", "type"=>"textarea"],
					"attributes" => ["label"=>"Attributes<br>(key:value comma separated)<br><br>example: <br><i>Color:Black,Size:Small</i>", "type"=>"textarea"],
				];

				$ipfs_attr = get_post_meta($post->ID, 'ipfsbs_hash_nft_attributes', true);
				$existing_data = !empty($ipfs_attr) ? json_decode($ipfs_attr, true) : [];
				$atts = [];
				if (!empty($existing_data['attributes'])):
					foreach($existing_data['attributes'] as $akey => $avalue) {
						$atts[] = $avalue['trait_type'].":".$avalue['value'];
					}
					$existing_data['attributes'] =  implode("\r\n", $atts);
				endif;

				foreach ($fields as $key=>$value) :
					if ('date' == $value['type'] || 'text' == $value['type']) :
			?>
				<tr>
					<th scope="row"><?php _e( $value['label'], 'ipfs-block-store');?> <?php echo (!empty($value['required'])) ? " *" : "";?></th>
					<td><input id="f-<?php echo $key;?>" name="<?php echo $key;?>" type="<?php echo $value['type'];?>" size="40" value="<?php echo isset($existing_data[$key]) ? esc_attr($existing_data[$key]) : '';?>" <?php echo (!empty($value['required'])) ? "required" : "";?>><br><label for="f-<?php echo $key;?>"></label></td>
				</tr>
			<?php elseif ('textarea' == $value['type']) : ?>
				<tr>
					<th scope="row"><?php _e( $value['label'], 'ipfs-block-store');?> <?php echo (!empty($value['required'])) ? " *" : "";?></th>
					<td><textarea id="f-<?php echo $key;?>" name="<?php echo $key;?>" type="<?php echo $value['type'];?>" rows=5 cols=30><?php echo isset($existing_data[$key]) ? esc_textarea($existing_data[$key]) : '';?></textarea><br><label for="f-<?php echo $key;?>"></label></td>
				</tr>
			<?php elseif ('checkbox' == $value['type']) : ?>
				<tr>
					<th scope="row"><?php _e( $value['label'], 'ipfs-block-store');?></th>
					<td><input id="f-<?php echo $key;?>" name="<?php echo $key;?>" type="<?php echo $value['type'];?>" value="1" <?php echo !empty($existing_data[$key]) ? "checked" : '';?>><label for="f-<?php echo $key;?>">Yes <?php _e( $value['label'], 'ipfs-block-store');?></label></td>
				</tr>
				<?php endif; ?>
			<?php endforeach; ?>
			
		</tbody>
		</table>

		<p class="submit"><input type="submit" name="submit" id="submit" class="button button-primary" value="Mint NFT"></p>

		<?php endif; // end showing add+edit form
		?>
		
	</form>

	<?php endif; ?>

</div>