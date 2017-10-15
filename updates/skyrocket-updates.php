<?php

// This is the secret key for API authentication.
// This key will match the 'Secret Key for License Verification Requests' Key in the Software License Manager plugin settings
define( 'SKYROCKET_EPHEMERIS_SECRET_KEY', 'YOUR-LICENSE-REQUESTS-SECRET-KEY' );

// This is the URL where API query request will be sent to.
// This should be the URL of the site where you have installed the Software License Manager plugin.
define( 'SKYROCKET_STORE_URL', 'HTTP://YOUR-WORDPRESS-SITE.COM' );

// This is a value that will be recorded in the Software License Manager data so you can identify licenses for this item/product.
define( 'SKYROCKET_ITEM_REFERENCE', 'YOUR PRODUCT NAME' );

/**
 * Create our License Management settings page admin option and call the function to create our settings page
 */
function skyrocket_product_license_menu() {
	add_options_page(
		__( 'License Activation Page', 'ephemeris' ),
		'Product Name',
		'manage_options',
		'product-name-license',
		'skyrocket_product_license_page'
	);
}
add_action( 'admin_menu', 'skyrocket_product_license_menu' );

/**
 * Create our License Management settings page
 */
function skyrocket_product_license_page() {
	$license = get_option( 'skyrocket_product_license_key' );
	$status = get_option( 'skyrocket_product_license_status' );
	?>
	<div class="wrap">
		<h2><?php _e( 'Product Name License Management' ); ?></h2>
		<form method="post" action="options.php">

			<?php settings_fields( 'skyrocket_product_license' ); ?>

			<table class="form-table">
				<tbody>
					<?php if ( $status != 'valid' ) { ?>

					<tr valign="top">
						<th scope="row" valign="top">
							<?php _e( 'License Key' ); ?>
						</th>
						<td>
							<input id="skyrocket_product_license_key" name="skyrocket_product_license_key" type="text" class="regular-text" value="<?php esc_attr_e( $license ); ?>" />
							<label class="description" for="skyrocket_product_license_key"><?php _e( 'Enter your license key' ); ?></label>
						</td>
					</tr>
					<?php } ?>

					<?php if ( $license != '' ) { ?>
						<tr valign="top">

								<?php if ( $status !== false && $status == 'valid' ) { ?>
									<th scope="row" valign="top">
										<?php _e( 'License Status' ); ?>
									</th>
									<td>
									<span style="color:green; line-height:30px; padding-right:10px"><?php _e( 'Active' ); ?></span>
									<?php wp_nonce_field( 'skyrocket_product_nonce', 'skyrocket_product_nonce' ); ?>
										<input type="submit" class="button-secondary" name="skyrocket_license_deactivate" value="<?php _e( 'Deactivate License' ); ?>"/>
									</td>
								<?php } else {
									wp_nonce_field( 'skyrocket_product_nonce', 'skyrocket_product_nonce' ); ?>
									<th scope="row" valign="top">
										<?php _e( 'Activate License' ); ?>
									</th>
									<td>
										<input type="submit" class="button-secondary" name="skyrocket_license_activate" value="<?php _e( 'Activate License' ); ?>"/>
									</td>
								<?php } ?>
						</tr>
					<?php } ?>
				</tbody>
			</table>
			<?php
			if ( empty( $license ) || $status != 'valid' ) {
				// If there's a valid license, we don't need to display the Save Changes submit button
				submit_button();
			}
			?>

		</form>
	<?php
}

/**
 * Create our settings in the options table
 */
function skyrocket_product_register_option() {
	register_setting( 'skyrocket_product_license', 'skyrocket_product_license_key', 'skyrocket_product_sanitize_license' );
}
add_action( 'admin_init', 'skyrocket_product_register_option' );

/**
 * If we've entered a new key, make sure the old status is removed first as it will need to be reactivated
 */
function skyrocket_product_sanitize_license( $new_key ) {
	$old_key = get_option( 'skyrocket_product_license_key' );
	if( $old_key && $old_key != $new_key ) {
		delete_option( 'skyrocket_product_license_status' );
	}

	return $new_key;
}

/**
 * Check with our Software License Manager API if we can activate the License Key
 */
function skyrocket_product_activate_license() {
	$return_val = false;

	// Listen for our activate button to be clicked
	if( isset( $_POST['skyrocket_license_activate'] ) ) {

		// Check our nonce to validate the form request came from the current site and not somewhere else
		if( ! check_admin_referer( 'skyrocket_product_nonce', 'skyrocket_product_nonce' ) ) {
			return;
		}

		// Retrieve the License Key from the database
		$license = trim( get_option( 'skyrocket_product_license_key' ) );

		$api_params = array(
			'secret_key' => SKYROCKET_EPHEMERIS_SECRET_KEY,
			'slm_action' => 'slm_activate',
			'license_key' => $license,
			'registered_domain' => home_url(),
			'item_reference' => SKYROCKET_ITEM_REFERENCE
			);

		// Call the Software License Manager API.
		$response = wp_remote_get(
			add_query_arg( $api_params, trailingslashit( SKYROCKET_STORE_URL ) ),
			array(
				'timeout' => 15,
				'sslverify' => false
			)
		);

		// Make sure the response returned ok before continuing any further
		if ( is_wp_error( $response ) ) {
			return false;
		}

		if ( is_array( $response ) ) {
			$json = $response['body'];
			$json = preg_replace( '/[\x00-\x1F\x80-\xFF]/', '', utf8_encode( $json ) );
			$license_data = json_decode( $json );
		}

		if ( $license_data->result == 'success' ) {
			// If activated successfully, update our status
			update_option( 'skyrocket_product_license_status', 'valid' );
			$return_val = true;
		}
		else {
			update_option( 'skyrocket_product_license_status', 'invalid' );
			$return_val = false;
		}
	}

	return $return_val;
}
add_action( 'admin_init', 'skyrocket_product_activate_license' );

/**
 * Check with our Software License Manager API if we can deactivate the License Key
 */
function skyrocket_product_deactivate_license() {
	$return_val = false;
	$domain_already_inactive = 80;

	// listen for our deactivate button to be clicked
	if( isset( $_POST['skyrocket_license_deactivate'] ) ) {

		// Check our nonce to validate the form request came from the current site and not somewhere else
		if( ! check_admin_referer( 'skyrocket_product_nonce', 'skyrocket_product_nonce' ) ) {
			return;
		}

		// Retrieve the License Key from the database
		$license = trim( get_option( 'skyrocket_product_license_key' ) );

		$api_params = array(
			'secret_key' => SKYROCKET_EPHEMERIS_SECRET_KEY,
			'slm_action' => 'slm_deactivate',
			'license_key' => $license,
			'registered_domain' => home_url(),
			'item_reference' => SKYROCKET_ITEM_REFERENCE
			);

		// Call the Software License Manager API.
		$response = wp_remote_get(
			add_query_arg( $api_params, trailingslashit( SKYROCKET_STORE_URL ) ),
			array(
				'timeout' => 15,
				'sslverify' => false
			)
		);

		// Make sure the response returned ok before continuing any further
		if ( is_wp_error( $response ) ) {
			return false;
		}

		if ( is_array( $response ) ) {
			$json = $response['body'];
			$json = preg_replace( '/[\x00-\x1F\x80-\xFF]/', '', utf8_encode( $json ) );
			$license_data = json_decode( $json );
		}

		if ( $license_data->result == 'success' || $license_data->error_code == $domain_already_inactive ) {
			// If deactivated successfully or if it's already been deactivated previously, update our status
			delete_option( 'skyrocket_product_license_status' );
			$return_val = true;
		}
		else {
			$return_val = false;
		}
	}

	return $return_val;
}
add_action( 'admin_init', 'skyrocket_product_deactivate_license' );
