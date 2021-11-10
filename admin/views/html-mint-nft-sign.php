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
	<h1><?php _e( 'Sign to complete minting NFT', 'ipfs-block-store'); ?></h1>
	<?php
		if ( empty($settings['allowminting'])) {
			echo '<div class="notice notice-error is-dismissible">
				<p>'._('Minting is not enabled.  Please check the settings page.', 'ipfs-block-store').'</p>
			</div>';
		}
		elseif ( empty($settings['contractaddress_matic']) && empty($settings['contractaddress_eth'])) {
			echo '<div class="notice notice-danger is-dismissible">
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

			$contract_address = !empty($settings['contractaddress_eth']) ? $settings['contractaddress_eth'] : $settings['contractaddress_matic'];
			if (!empty($settings['contractaddress_eth'])) :
				$contract_network_text = 'Using Ethereum Mainnet.  Ensure your wallet is set to the Ethereum network.';
				$contract_network_name = "Ethereum Mainnet";
				$contract_network_url = "https://etherscan.io/tx/";
			else :
				$contract_network_text = 'Using Polygon(Matic) Mainnet.  Ensure your wallet is set to the Polygon(Matic) network.';
				$contract_network_name = "Polygon(Matic) Mainnet";
				$contract_network_url = "https://polygonscan.com/tx/";
			endif;
		}
	?>

	<?php if (!empty($media)) : ?>
	<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" id="IPFSBS_nft_meta_form" >			

		<input type="hidden" name="action" value="IPFSBS_mintnft_complete">
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
					$ipfs_nft = get_post_meta($post->ID, 'ipfsbs_hash_nft', true);
					$node_data = json_decode($ipfs, true);
					$node_data_nft = json_decode($ipfs_nft, true);
					if (!empty($node_data)) :
						$image = wp_get_attachment_image( $post->ID, 'thumbnail' );
						$ipfs_url = $hostname.'/ipfs/'.$node_data['Hash'];
						$ipfs_nft_url = $hostname.'/ipfs/'.$node_data_nft['Hash'];
						echo "<tr><td>{$image}</td>
						<td>{$node_data['Name']}</td>
						<td>{$node_data['Size']}</td>
						</tr>";
					endif;
				}
				echo "<p>IPFS File: <a href=\"{$ipfs_url}\" target=\"_blank\">{$ipfs_url}</a></p>";

				
				echo "<p>NFT metadata json: <a href=\"{$ipfs_nft_url}\" target=\"_blank\">{$ipfs_nft_url}</a></p>";
			?>
			</tbody>
		</table>

		
		<?php
				echo "<p>{$contract_network_text}</p>";
		?>
		<p class="submit"><input type="button" id="f-mintbtn" class="button button-primary" value="Mint NFT"></p>

		<div id="mint-output"></div>
	</form>

	<?php endif; ?>

	<script src="https://cdn.jsdelivr.net/npm/web3@latest/dist/web3.min.js"></script>
	<script>


		var mintButton = document.getElementById("f-mintbtn"), web3;
		mintButton.addEventListener('click', (e) => {
			e.preventDefault();
			mintButton.value = "Processing...";
			mintButton.disabled = true;

			// Wait for loading completion to avoid race conditions with web3 injection timing.
			var getWeb3Account = async function(){
				if (window.ethereum) {
					 web3 = new Web3(window.ethereum);
					try {
					// Request account access if needed
						await window.ethereum.enable();
						// Acccounts now exposed
						return web3;
					} catch (error) {
						console.error(error);
					}
				}
				// Legacy dapp browsers...
				else if (window.web3) {
					// Use Mist/MetaMask's provider.
					 web3 = window.web3;
					console.log('Injected web3 detected.');
					return web3;
				}
				// Fallback to localhost; use dev console port by default...
				else {
					const provider = new Web3.providers.HttpProvider('http://127.0.0.1:9545');
					web3 = new Web3(provider);
					console.log('No web3 instance injected, using Local web3.');
					return web3;
				}
			}


			var mint = async function mintNFT(publickey, url) {
				jQuery.getJSON("<?php echo IPFSBS_URL . 'admin/views/CreateNFT.json';?>", async function(contract) {

					const nonce = await web3.eth.getTransactionCount(publickey, "latest") //get latest nonce

					const contractAddress = "<?php echo $contract_address;?>";
					const nftContract = new web3.eth.Contract(contract.abi, contractAddress);
						//the transaction
						const tx = {
							from: publickey,
							to: contractAddress,
							nonce: nonce.toString(),
							// gas: 500000,
							data: nftContract.methods.mintNFT(publickey, url).encodeABI(),
						}
						console.log('tx', tx);
  
						//sign transaction via Metamask
						try {
							const txHash = await window.ethereum
								.request({
									method: 'eth_sendTransaction',
									params: [tx],
								});
								document.getElementById("mint-output").innerHTML = "<div class=\"notice notice-success is-dismissible\"> <p>✅  Transaction submitted.  Monitor status on the <?php echo $contract_network_name;?>: <a href=\"<?php echo $contract_network_url;?>" + txHash + "\" target=\"_blank\"><?php echo $contract_network_url;?>" + txHash + "</a></p> </div>";
								mintButton.style.display = 'none';

						} catch (error) {
								document.getElementById("mint-output").innerHTML = "<div class=\"notice notice-error is-dismissible\"> <p>😥 Something went wrong: " + error.message + "</p> </div>";
								mintButton.value = "Mint NFT";
								mintButton.disabled = false;
						}
				});
			}

			var getAccounts = async function() {
				await getWeb3Account();
				const accounts = await window.ethereum.request({ method: 'eth_requestAccounts' });
				window.ethereum.on('accountsChanged', function (accounts) {
					// Time to reload your interface with accounts[0]!
					console.log(accounts[0])
				});
				return accounts[0];
			}

			var account;
			(async () => {
				account = await getAccounts();
				if (!account) {
					alert("ETC account cannot be found.");
					mintButton.value = "Mint NFT";
					mintButton.disabled = false;
				}
				else {
					mint(account, '<?php echo $ipfs_nft_url;?>');
				}

			})()
		});

	</script>

</div>