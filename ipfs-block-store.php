<?php
/**
 * IPFS Block Store
 *
 * Plugin Name: IPFS Block Store
 * Description: Store your posts/media on an IPFS node and mint nft's from media.
 * Version:     1.0.0
 * Author:      iPal Media Inc.
 * Author URI:  https://ipalmedia.com
 * Text Domain: ipfs-block-store
 * Domain Path: /languages
 *
 * @package IPFS Block Store
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

if ( ! class_exists( 'IPFS_BLOCK_STORE' ) ) :


	// include Classes.
	$includes = array(
		'class-api',
	);

	foreach ( $includes as $inc ) {
		include plugin_dir_path( __FILE__ ) . "/includes/{$inc}.php";
	}


	/**
	 * Plugin Main Class
	 */
	class IPFS_BLOCK_STORE {

		/**
		 * Plugin Version Number
		 *
		 * @var  string $version The plugin version number.
		 */
		public $version = '1.0.0';

		/**
		 * Plugin Settings Array
		 *
		 * @var  array $settings The plugin settings array.
		 */
		public $settings = array();

		const POST_TYPE_NAME = 'ipfs-block-store';

		/**
		 * Initialize the plugin
		 *
		 * @return void
		 */
		public static function init() {
			$class = __CLASS__;
			$blt   = new $class();
			$blt->init_actions();
		}

		/**
		 * Constructor
		 *
		 * @return void
		 */
		public function __construct() {
			
			// Grab Plugin Settings.
			$this->settings = get_option( 'ipfs-block-store_options' ) ?? [];
		}

		/**
		 * Initialize the plugin features
		 *
		 * @return void
		 */
		public function init_actions() {

			$this->define( 'IPFSBS_URL', plugin_dir_url( __FILE__ ) );
			$this->define( 'IPFSBS_PATH', plugin_dir_path( __FILE__ ) );

			register_activation_hook( __FILE__, array( __CLASS__, 'activate' ) );
			register_deactivation_hook( __FILE__, array( __CLASS__, 'deactivate' ) );

			// setup admin menu.
			add_action( 'admin_menu', array( $this, 'admin_menu_init' ) );

			// add checkbox to media uploads
			$this->MediaLibrarySetup();

			// add hook to save post on add/update.
			add_action( 'save_post', array( $this, 'save_data' ), 10, 3 );

			// add hook to mint nft
			add_action( 'admin_post_IPFSBS_mintnft', array( $this, 'save_mint_data' ));

			// setup settings page.
			add_action( 'admin_init', array( $this, 'register_settings' ) );

			// setup cache.
			add_action( 'admin_init', array( $this, 'setup_cache' ) );

			// add meta boxes to cms.
			add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ) );

			// finally display any admin notices.
			add_action( 'admin_notices', array( $this, 'check_admin_notices' ) );

			// add ajax functions.
			//add_action( 'wp_loaded', array( 'IPFS_BLOCK_STORE_AJAX', 'add_wp_ajax_functions' ) );
		}

		/**
		 * Add admin menus
		 *
		 * @return void
		 */
		public function admin_menu_init() {


			$hook = add_menu_page(
				__( 'IPFS Block Store', 'ipfs-block-store' ),
				esc_html__( 'IPFS Block Store', 'ipfs-block-store' ),
				'manage_options',
				'ipfs-block-store',
				array( $this, 'show_cms_landing_page' ),
				'dashicons-format-aside',
				900
			);

			add_submenu_page(
				'ipfs-block-store',
				__( 'Settings', 'ipfs-block-store' ),
				esc_html__( 'Settings', 'ipfs-block-store' ),
				'manage_options',
				'ipfs-block-store_options',
				array( $this, 'show_settings_page' )
			);

			add_submenu_page(
				null,
				__( 'Mint', 'ipfs-block-store' ),
				esc_html__( 'Mint', 'ipfs-block-store' ),
				'manage_options',
				'ipfs-block-mint_nft',
				array( $this, 'show_mint_page' )
			);

			add_submenu_page(
				null,
				__( 'Complete Mint', 'ipfs-block-store' ),
				esc_html__( 'Complete Mint', 'ipfs-block-store' ),
				'manage_options',
				'ipfs-block-mint_nft_sign',
				array( $this, 'show_mint_sign_page' )
			);
		}

		/**
		 * Add checkbox to media library
		 */
		public function MediaLibrarySetup() {

			// Add checkbox to media library popup
			add_filter( 'attachment_fields_to_edit', function($form_fields, $post){
				$checked = get_post_meta( $post->ID, 'include_in_ipfs', false ) ? 'checked="checked"' : '';

				$value = get_post_meta( $post->ID, 'ipfsbs_hash', false);

				$form_fields['include_in_ipfs'] = array(
					'label' => 'Save to IPFS',
					'input' => 'html',
					'html'  => "<input type=\"checkbox\"
						name=\"attachments[{$post->ID}][include_in_ipfs]\"
						id=\"attachments[{$post->ID}][include_in_ipfs]\"
						value=\"1\" {$checked}/><br />");

				if (!empty($value)) {
					$values = json_decode($value[count($value) - 1], true);
					$hash = $values['Hash'];

					$form_fields['include_in_ipfs_hash'] = array(
						'label' => 'IPFS Hash',
						'input' => 'html',
						'html'  => "<input type='text' disabled value='{$hash}'>"
					);


					$hostname = str_replace([':5001'], "", $this->settings['apibaseurl']);
					$form_fields['include_in_ipfs_link'] = array(
						'label' => 'IPFS Link',
						'input' => 'html',
						'html'  => "<a href='{$hostname}/ipfs/{$hash}' target='_blank'>View IPFS File</a>"
					);
				}

				return $form_fields;
			}, null, 2 );

			// Save action for media item
			add_filter("attachment_fields_to_save", function($post, $attachment){
				if(!empty($attachment['include_in_ipfs'])) {

					$imagePath = get_attached_file($post['ID']);

					if (!empty($imagePath)) :
						// save item to IPFS
						$args = [
							'fileName' => $post['post_title'], 
							'filePath' => $imagePath
						];
						$request = $this->addToIPFS("/api/v0/add", $args);

						if (!empty($request['Name']) && !empty($request['Hash']) && !empty($request['Size']) ) {
							// We have a valid file back, save to post meta
							$newData = json_encode($request);
							add_post_meta( $post['ID'], 'ipfsbs_hash', $newData, false );

						}
						else {
							print_r($request);
							die('Error occurred saving to ipfs');
						}

					endif;
					
					update_post_meta($post['ID'], 'include_in_ipfs', 1);
				} 
				else {
					update_post_meta($post['ID'], 'include_in_ipfs', 0);
				}
			}, null , 2);

		}


		/**
		 *
		 * Save items to IPFS upon save/update.
		 *
		 * @param   int   $post_id The post ID.
		 * @param   array $post The post object.
		 * @param   bool  $update If post was updated.
		 * @return  void
		 */
		public function save_data( $post_id, $post, $update ) {

			if ( empty( $this->settings['activate']) || empty( $this->settings['appid']) || empty( $this->settings['apibaseurl']) || empty( $this->settings['apipass'])) return;


			if ( ! isset( $_POST['save_ipfs__nonce'] ) ) {
				return;
			}
		
			// Verify that the nonce is valid.
			if ( ! wp_verify_nonce( $_POST['save_ipfs__nonce'], 'save_ipfs__nonce' ) ) {
				return;
			}

			// if checkbox not checked to save, then skip
			if (empty($_POST['save-to-ipfs'])) return;

			// Avoid autosave.
			if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
				return;
			}

			if ( ! isset( $_POST['post_type'] ) ) {
				return;
			}

			// First we need to check if the current user is authorised to do this action.
			if ( self::POST_TYPE_NAME === $_POST['post_type'] ) {
				if ( ! current_user_can( 'edit_page', $post_id ) ) {
					return;
				}
			} else {
				if ( ! current_user_can( 'edit_post', $post_id ) ) {
					return;
				}
			}

			// get all acf fields if exist
			if (class_exists('ACF')) {
				$post->acf = get_fields($post->ID) ?? [];
			}
			$gzfile = gzencode(json_encode($post), 9);
			$gzfileName = $post->post_name . '-' . date("YmdHis"). '.json';
			$gzfilePath = IPFSBS_PATH . 'cache/posts/' . $gzfileName;

			// Now with output lets save json file using slug as filename
			file_put_contents($gzfilePath, $gzfile);

			$args = [
				'fileName' => $gzfileName, 
				'filePath' => $gzfilePath
			];

			// Add new record
			$request = $this->addToIPFS("/api/v0/add", $args);

			if (!empty($request['Name']) && !empty($request['Hash']) && !empty($request['Size']) ) {
				// We have a valid file back, save to post meta
				$newData = json_encode($request);
				add_post_meta( $post_id, 'ipfsbs_hash', $newData, false );

			}
			else {
				print_r($request);
				die('Error occurred saving to ipfs');
			}
		}


		/**
		 *
		 * Mint Items.
		 *
		 * @return  void
		 */
		public function save_mint_data( ) {

			// Avoid autosave.
			if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
				return;
			}
			if( isset( $_POST['IPFSBS_managenft_nonce'] ) && wp_verify_nonce( $_POST['IPFSBS_managenft_nonce'], 'IPFSBS_managenft_nonce') ) {
				

				$settings = get_option('ipfs-block-store_options') ?? [];
				$hostname = str_replace([':5001'], "", $settings['apibaseurl']);
				if (empty($settings['allowminting']) || (empty($settings['contractaddress_matic']) && empty($settings['contractaddress_eth']))) return;

				$media_id = !empty($_POST['media_id']) ? (int)$_POST['media_id'] : 0;
				if (empty($media_id)) return;

				$args = array(
					'post_type' => 'attachment',
					'post__in' => [$media_id],
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

				if (empty($media)) return;

				foreach($media as $post) {
					$ipfs = get_post_meta($post->ID, 'ipfsbs_hash', true);
					$post_id = $post->ID;
					$node_data = json_decode($ipfs, true);
					$ipfs_url = $hostname.'/ipfs/'.$node_data['Hash'];
				}

				// // lets check if nft exists already
				// $ipfs_nft = get_post_meta($post->ID, 'ipfsbs_hash_nft', true);
				// if (!empty($ipfs_nft)) {
				// 	$node_data_nft = json_decode($ipfs_nft, true);
				// 	if (!empty($node_data_nft['Hash'])) {
				// 		$ipfs_nft_url = $hostname.'/ipfs/'.$node_data_nft['Hash'];
				// 	}
				// }

				if (empty($ipfs_nft_url)) :

					// NFT metadata doesnt exist, so lets create it

					// now build variables for json

					// Creatae attributes
					$attr_post = explode("\r\n", $_POST['attributes']);
					$attributes = [];
					foreach($attr_post as $a) {
						$values = explode(':', $a);
						if (!empty($values[1])) {
							$attributes[] = ["trait_type"=>trim($values[0]), "value"=>trim($values[1])];
						}
					}

					$json_params = file_get_contents("php://input");
					$nft_metadata = [
						"name" => sanitize_text_field( $_POST['name'] ),
						"description" => sanitize_text_field( $_POST['description'] ),
						"attributes" => $attributes,
						"image" => $ipfs_url,
					];

					// Now lets save json to ipfs
					$gzfile = json_encode($nft_metadata);
					$gzfileName = $node_data['Name'] . '-' . date("YmdHis"). '.json';
					$gzfilePath = IPFSBS_PATH . 'cache/media/' . $gzfileName;

					// Now with output lets save json file using slug as filename
					file_put_contents($gzfilePath, $gzfile);

					$args = [
						'fileName' => $gzfileName, 
						'filePath' => $gzfilePath
					];

					// Add new record
					$request = $this->addToIPFS("/api/v0/add", $args);

					if (!empty($request['Name']) && !empty($request['Hash']) && !empty($request['Size']) ) {
						// We have a valid file back, save to post meta
						$newData = json_encode($request);
						add_post_meta( $post_id, 'ipfsbs_hash_nft_attributes', json_encode($nft_metadata), false );
						add_post_meta( $post_id, 'ipfsbs_hash_nft', $newData, false );
					}

				endif; 

				// Now redirect to final step to sign nft
				return wp_redirect( admin_url( "admin.php?page=ipfs-block-mint_nft_sign&id=" . $post_id) );
				exit;

			}			
			else {
				wp_die( __( 'Invalid nonce specified', 'ipfs-block-store' ), __( 'Error', 'ipfs-block-store' ), array(
							'response' 	=> 403,
							'back_link' => 'admin.php?page=' . 'ipfs-block-store',
					) );
			}

		}


		/**
		 * Add item to IPFS
		 */
		protected function addToIPFS( $path, $args = [] ) {

			if (empty($path)) return;

			$api = new IPFS_BLOCK_STORE_AJAX();
			$request = $api->do_request(
				$path,
				$args,
			);

			return $request ?? false;

		}

		/**
		 *
		 * Defines a constant if doesnt already exist.
		 *
		 * @param   string $name The constant name.
		 * @param   mixed  $value The constant value.
		 * @return  void
		 */
		protected function define( $name, $value = true ) {
			if ( ! defined( $name ) ) {
				define( $name, $value );
			}
		}

		/**
		 *
		 * Returns the plugin path to a specified file.
		 *
		 * @param   string $filename The specified file.
		 * @return  string
		 */
		protected function get_path( $filename = '' ) {
			return IPFSBS_PATH . ltrim( $filename, '/' );
		}


		/**
		 *  This function will load in a file from the 'admin/views' folder and allow variables to be passed through
		 *
		 *  @param  string $dir Directory to locate within.
		 *  @param  string $path Filename.
		 *  @param  array  $args Any arguments.
		 *  @return  void
		 */
		public function get_view( $dir = 'admin', $path = '', $args = array() ) {

			// allow view file name shortcut.
			if ( substr( $path, -4 ) !== '.php' ) {

				$path = $this->get_path( "{$dir}/views/{$path}.php" );

			}

			// include.
			if ( file_exists( $path ) ) {
				extract( $args );
				include $path;
			}

		}

		/**
		 * Check if any admin notices to report on init
		 *   - currently ensures acf is defined
		 *
		 * @return void
		 */
		public function check_admin_notices() {
			$message = null;
			// get the current screen.
			$screen             = get_current_screen();
			$screens_to_show_on = array( self::POST_TYPE_NAME, 'edit-' . self::POST_TYPE_NAME );

			// return if not this plugin.
			if ( ! in_array( $screen->id, $screens_to_show_on, true ) ) {
				return;
			}
			

			$settings = get_option('ipfs-block-store_options') ?? [];

			if ( empty( $settings['appid']) ) {
				echo '<div class="notice notice-danger is-dismissible"><p><strong>' . esc_html__( 'Please set your IPFS Blockstore user in order to store data on IPFS.', 'ipfs-block-store' ) . '</strong></p></div>';
			}
			if ( empty( $settings['apibaseurl']) ) {
				echo '<div class="notice notice-danger is-dismissible"><p><strong>' . esc_html__( 'Please set your IPFS Blockstore Node Url in order to store data on IPFS.', 'ipfs-block-store' ) . '</strong></p></div>';
			}
			if ( empty( $settings['apipass']) ) {
				echo '<div class="notice notice-danger is-dismissible"><p><strong>' . esc_html__( 'Please set your IPFS Blockstore user Secret Key in order to store data on IPFS.', 'ipfs-block-store' ) . '</strong></p></div>';
			}
			

			return;
		}

		/**
		 * Add meta boxes to cms
		 */
		public function add_meta_boxes() {


			if ( empty( $this->settings['activate']) ) return;

			add_meta_box(
				'dwp-save-checkbox',
				__( 'IPFS Save Block Store', 'ipfs-block-store' ),
				[$this, 'global_notice_meta_box_callback'],
				null,
				'side'
			);
		}

		public function global_notice_meta_box_callback( $post ) {

			// Add a nonce field so we can check for it later.
			wp_nonce_field( 'save_ipfs__nonce', 'save_ipfs__nonce' );
		
			$value = get_post_meta( $post->ID, 'ipfsbs_hash', false);
			$checked = !empty($this->settings['alwayssave']) ? 'checked' : '';
		
			echo '<label><input type="checkbox" name="save-to-ipfs" value="1" '.$checked.'>Save Revision To IPFS</label>';

			$this->displayIPSRevisions($value);
			
		}

		protected function displayIPSRevisions($value) {

			if (empty($value)) return;
			
			if (!empty($value)) {
				echo '<h3>Current Saved IPFS Revisions</h3>';
			?>
			<table class="table">
				<thead>
					<tr>
						<th>File</th>
						<th>Size</th>
						<th></th>
					</tr>
				</thead>	
				<tbody>
				<?php
					$hostname = str_replace([':5001'], "", $this->settings['apibaseurl']);
					foreach($value as $item){
						$node_data = json_decode($item, true);
						echo "<tr style=''>
						<td valign='top' style='white-space: break-spaces; max-width: 100px; overflow: hidden;margin-bottom: 20px;'>{$node_data['Hash']}</td>
						<td valign='top' style='padding: 0 15px;' >{$node_data['Size']}</td>
						<td valign='top' ><a href='{$hostname}/ipfs/{$node_data['Hash']}' target='_blank'>view</a> <a href=''>restore</a></td></tr>";
					}
				?>
				</tbody>
			</table>
		<?php
			}
		}

		/**
		 * Setup cache folders
		 */
		public function setup_cache() {

			// Create cache folder
			if (!is_dir(IPFSBS_PATH . 'cache/')) {
				// dir doesn't exist, make it
				mkdir(IPFSBS_PATH . 'cache/');
			}

			// Create posts folder
			if (!is_dir(IPFSBS_PATH . 'cache/posts/')) {
				// dir doesn't exist, make it
				mkdir(IPFSBS_PATH . 'cache/posts/');
			}

			// Create media folder
			if (!is_dir(IPFSBS_PATH . 'cache/media/')) {
				// dir doesn't exist, make it
				mkdir(IPFSBS_PATH . 'cache/media/');
			}

		}

		/**
		 * Register settings
		 */
		public function register_settings() {

			register_setting(
				'ipfs-block-store_options',
				'ipfs-block-store_options',
				array( $this, 'callback_validate_options' )
			);

			add_settings_section(
				'ipfs-block-store_creds',
				esc_html__( 'IPFS Block Store settings', 'ipfs-block-store' ),
				array( $this, 'callback_admin_settings' ),
				'ipfs-block-store'
			);

			add_settings_field(
				'activate',
				esc_html__( 'Enable IPFS Saving', 'ipfs-block-store' ),
				array( $this, 'callback_field_checkbox' ),
				'ipfs-block-store',
				'ipfs-block-store_creds',
				array(
					'id'    => 'activate',
					'label' => esc_html__( 'Yes Activate', 'ipfs-block-store' ),
				)
			);

			add_settings_field(
				'alwayssave',
				esc_html__( 'Always save posts revisions to ipfs on update', 'ipfs-block-store' ),
				array( $this, 'callback_field_checkbox' ),
				'ipfs-block-store',
				'ipfs-block-store_creds',
				array(
					'id'    => 'alwayssave',
					'label' => esc_html__( 'Yes Always Save', 'ipfs-block-store' ),
				)
			);

			add_settings_field(
				'apibaseurl',
				esc_html__( 'IPFS Infura Node Endpoint', 'ipfs-block-store' ),
				array( $this, 'callback_field_text' ),
				'ipfs-block-store',
				'ipfs-block-store_creds',
				array(
					'id'    => 'apibaseurl',
					'label' => esc_html__( 'ie https://ipfs.infura.io:5001', 'ipfs-block-store' ),
				)
			);

			add_settings_field(
				'appid',
				esc_html__( 'Infura Project ID', 'ipfs-block-store' ),
				array( $this, 'callback_field_text' ),
				'ipfs-block-store',
				'ipfs-block-store_creds',
				array(
					'id'    => 'appid',
					'label' => esc_html__( '', 'ipfs-block-store' ),
				)
			);

			add_settings_field(
				'apipass',
				esc_html__( 'Infura Project Secret', 'ipfs-block-store' ),
				array( $this, 'callback_field_secret' ),
				'ipfs-block-store',
				'ipfs-block-store_creds',
				array(
					'id'    => 'apipass',
					'label' => esc_html__( '', 'ipfs-block-store' ),
				)
			);



			add_settings_field(
				'allowminting',
				esc_html__( 'Allowing Minting of Media to an NFT', 'ipfs-block-store' ),
				array( $this, 'callback_field_checkbox' ),
				'ipfs-block-store',
				'ipfs-block-store_creds',
				array(
					'id'    => 'allowminting',
					'label' => esc_html__( 'Yes', 'ipfs-block-store' ),
				)
			);


			add_settings_field(
				'contractaddress_eth',
				esc_html__( 'NFT Minting ETH Contract Address', 'ipfs-block-store' ),
				array( $this, 'callback_field_text' ),
				'ipfs-block-store',
				'ipfs-block-store_creds',
				array(
					'id'    => 'contractaddress_eth',
					'label' => _( 'If entered will be used over Polygon (Matic)', 'ipfs-block-store' ),
				)
			);


			add_settings_field(
				'contractaddress_matic',
				esc_html__( 'NFT Minting Polygon (Matic) Contract Address', 'ipfs-block-store' ),
				array( $this, 'callback_field_text' ),
				'ipfs-block-store',
				'ipfs-block-store_creds',
				array(
					'id'    => 'contractaddress_matic',
					'label' => _( '<b>0x7057d967E7a44AB5ac0Dc8054D10462aBF3cA592</b><br>available from plugin author', 'ipfs-block-store' ),
					'placeholder'    => '',
				)
			);
		}


		/**
		 * Validate Company form on update
		 *
		 * @param  string $input Input Value to clean.
		 */
		public function callback_validate_options_form( $input ) {

			return $input;

		}

		/**
		 * Validate settings on update
		 *
		 * @param  string $input Input Value to clean.
		 */
		public function callback_validate_options( $input ) {


			// ipfs node user key.
			if ( isset( $input['appid'] ) ) {
				$input['appid'] = sanitize_text_field( $input['appid'] );
			}

			// ipfs node url.
			if ( isset( $input['apibaseurl'] ) ) {
				$input['apibaseurl'] = sanitize_text_field( $input['apibaseurl'] );
			}

			// ipfs node secret.
			if ( isset( $input['apisecret'] ) ) {
				$input['apisecret'] = sanitize_text_field( $input['apisecret'] );
			}

			// ipfs activate.
			if ( isset( $input['activate'] ) ) {
				$input['activate'] = sanitize_text_field( $input['activate'] );
			}

			// ipfs node secret.
			if ( isset( $input['alwayssave'] ) ) {
				$input['alwayssave'] = sanitize_text_field( $input['alwayssave'] );
			}

			return $input;

		}

		/**
		 * Settings Main instruction text
		 */
		public function callback_admin_settings() {

			echo '<p>' . esc_html__( '', 'ipfs-block-store' ) . '</p>';
		}

		/**
		 * Settings Text field
		 *
		 * @param  array $args Parameters to pass.
		 */
		public function callback_field_text( $args ) {

			$options = get_option( 'ipfs-block-store_options', array( $this, 'settings_defaults' ) );

			$id    = isset( $args['id'] ) ? $args['id'] : '';
			$label = isset( $args['label'] ) ? $args['label'] : '';

			$value = isset( $options[ $id ] ) ? sanitize_text_field( $options[ $id ] ) : '';

			$output  = '<input id="ipfs-block-store_options_' . $id . '" name="ipfs-block-store_options[' . $id . ']" type="text" size="40" value="' . $value . '"><br />';
			$output .= '<label for="ipfs-block-store_options_' . $id . '">' . $label . '</label>';

			echo $output;
		}

		/**
		 * Settings Checkbox field
		 *
		 * @param  array $args Parameters to pass.
		 */
		public function callback_field_checkbox( $args ) {

			$options = get_option( 'ipfs-block-store_options', array( $this, 'settings_defaults' ) );

			$id    = isset( $args['id'] ) ? $args['id'] : '';
			$label = isset( $args['label'] ) ? $args['label'] : '';

			$value = isset( $options[ $id ] ) ? sanitize_text_field( $options[ $id ] ) : '';
			$checked = !empty($value) ? 'checked' : '';

			$output  = '<input id="ipfs-block-store_options_' . $id . '" name="ipfs-block-store_options[' . $id . ']" type="checkbox" size="40" value="1" '.$checked.'> ';
			$output .= '<label for="ipfs-block-store_options_' . $id . '">' . $label . '</label>';

			echo $output;
		}

		/**
		 * Settings Secret fields
		 *
		 * @param  array $args Parameters to pass.
		 */
		public function callback_field_secret( $args ) {

			$options = get_option( 'ipfs-block-store_options', array( $this, 'settings_defaults' ) );

			$id    = isset( $args['id'] ) ? $args['id'] : '';
			$label = isset( $args['label'] ) ? $args['label'] : '';

			$value = isset( $options[ $id ] ) ? sanitize_text_field( $options[ $id ] ) : '';

			$output  = '<input id="ipfs-block-store_options_' . $id . '" name="ipfs-block-store_options[' . $id . ']" type="password" size="40" value="' . $value . '"><br />';
			$output .= '<label for="ipfs-block-store_options_' . $id . '">' . $label . '</label>';

			echo $output;
		}

		/**
		 * Settings default values
		 */
		public function settings_default() {
			return array(
				'apibaseurl'  => '',
				'appid'  => '',
				'apisecret' => '',
				'activate' => '',
				'alwayssave' => '',
			);
		}


		/**
		 * Displays NFT Minting page fields.
		 */
		public function show_mint_page($page = false) {
			// check if user is allowed access.
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}

			return $this->get_view( 'admin', 'html-mint-nft' );

		}

		/**
		 * Displays NFT Minting sign step.
		 */
		public function show_mint_sign_page($page = false) {
			// check if user is allowed access.
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}

			return $this->get_view( 'admin', 'html-mint-nft-sign' );

		}


		/**
		 * Displays settings page fields.
		 */
		public function show_settings_page($page = false) {
			// check if user is allowed access.
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}

			return $this->get_view( 'admin', 'html-admin-settings' );

		}

		/**
		 * Displays CMS landing page fields.
		 */
		public function show_cms_landing_page($page = false) {
			// check if user is allowed access.
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}

			return $this->get_view( 'admin', 'html-landing' );

		}


		/**
		 * Set defaults on activation.
		 */
		public static function activate() {

		}

		/**
		 * Perform actions during plugin deactivation.
		 */
		public static function deactivate() {

		}
	}

	add_action( 'plugins_loaded', array( 'IPFS_BLOCK_STORE', 'init' ) );

endif;
