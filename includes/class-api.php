<?php
/**
 * IPFS Block Store AJAX Class
 *
 * @package  ipfs-block-store
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

if ( ! class_exists( 'IPFS_BLOCK_STORE_AJAX' ) ) :

	/**
	 * IPFS Block Store API Class
	 */
	class IPFS_BLOCK_STORE_AJAX {

		const AJAX_NONCE = 'ipfs-block-store';
		const API_ENDPOINTS = [];

		protected $default_http_settings = array(
			'method' => 'GET', // 'GET', 'POST', 'HEAD', or 'PUT',
			// 'timeout' => 5,
			// redirection         Number of allowed redirects. Not supported by all transports
			// httpversion         Version of the HTTP protocol to use. Accepts '1.0' and '1.1'.
			'user-agent' =>        '',
			// reject_unsafe_urls  Whether to pass URLs through {@see wp_http_validate_url()}.
			// blocking            Whether the calling code requires the result of the request.
			'headers' => array(),
			'cookies' => array(),
			'body' => null,
		  );

		/**
		 * Constructor
		 *
		 * @return void
		 */
		public function __construct() {

			// Set User Agent.
			$this->default_http_settings['user-agent'] = 'Wordpress/IPFS_BLOCK_STORE v1.0.0'.'; ' . get_bloginfo( 'url' );

			// Grab Plugin Settings.
			$this->settings = get_option( 'ipfs-block-store_options' ) ?? [];
			
		}

		/**
		 * Create request to API.
		 * 
		 * @param string $endpoint
		 * @param string $path
		 * @param array $args
		 * @param string $body
		 */
		public function do_request($path, $args = []) {


			if ( empty( $this->settings['appid']) || empty( $this->settings['apibaseurl']) || empty( $this->settings['apipass']) || empty( $path ) || empty( $args ) ) return;

			//Initiate cURL
			$ch = curl_init();

			//Set the URL
			curl_setopt($ch, CURLOPT_URL, $this->settings['apibaseurl'] . $path);

			// Set project / secret
			curl_setopt($ch, CURLOPT_USERPWD, $this->settings['appid'] . ':' .  $this->settings['apipass']);

			//Set the HTTP request to POST
			curl_setopt($ch, CURLOPT_POST, true);

			//Tell cURL to return the output as a string.
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

			//If the function curl_file_create exists
			if(function_exists('curl_file_create')){
				//Use the recommended way, creating a CURLFile object.
				$filePath = curl_file_create($args['filePath'], null, $args['fileName']);
			} else{
				//Otherwise, do it the old way.
				//Get the canonicalized pathname of our file and prepend
				//the @ character.
				$filePath = '@' . realpath($args['filePath']);
				//Turn off SAFE UPLOAD so that it accepts files
				//starting with an @
				curl_setopt($ch, CURLOPT_SAFE_UPLOAD, false);
			}

			//Setup our POST fields
			$postFields = array(
				$args['fileName'] => $filePath
			);

			curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);

			//Execute the request
			$result = curl_exec($ch);

			//If an error occured, throw an exception
			//with the error message.
			if(curl_errno($ch)){
				return ['error'=>'The body on this request couldn\'t be json_decoded.', 'errorno'=>curl_errno($ch)];
				//throw new Exception(curl_error($ch));
			}
			else {
				return json_decode($result, true);
			}
		}


	}

endif;
