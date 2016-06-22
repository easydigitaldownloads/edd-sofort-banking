<?php
/*
Plugin Name: Easy Digital Downloads - SofortBanking
Plugin URL: https://easydigitaldownloads.com
Description: Easy Digital Downloads Plugin for accepting payment through SofortBanking Gateway.
Version: 1.0
Author: Easy Digital Downloads
Author URI: https://easydigitaldownloads.com
*/

if( is_admin() && class_exists( 'EDD_License' ) ) {

	$eddsofort_license = new EDD_License( __FILE__, 'SOFORT Banking', '1.0', 'Easy Digital Downloads' );

}

// registers the gateway
function sofort_register_gateway( $gateways ) {
	$gateways['sofort'] = array( 'admin_label' => 'SofortBanking', 'checkout_label' => __( 'SofortBanking', 'edd_sofort' ) );
	return $gateways;
}
add_filter( 'edd_payment_gateways', 'sofort_register_gateway' );

// Remove default CC form
add_action( 'edd_sofort_cc_form', '__return_false' );

/**
 * Register the payment icon
 */
function edd_sofort_payment_icon( $icons ) {
	$icons[ plugin_dir_url( __FILE__ ) . '/sofort.png'] = 'SofortBanking';
	return $icons;
}
add_filter( 'edd_accepted_payment_icons', 'edd_sofort_payment_icon' );

// processes the payment
function sofort_process_payment( $purchase_data ) {
	global $edd_options;

	// check there is a gateway name
	if ( ! isset( $purchase_data['post_data']['edd-gateway'] ) ) {
		return;
	}

	// collect payment data
	$payment_data = array(
		'price'         => $purchase_data['price'],
		'date'          => $purchase_data['date'],
		'user_email'    => $purchase_data['user_email'],
		'purchase_key'  => $purchase_data['purchase_key'],
		'currency'      => $edd_options['currency'],
		'downloads'     => $purchase_data['downloads'],
		'user_info'     => $purchase_data['user_info'],
		'cart_details'  => $purchase_data['cart_details'],
		'status'        => 'pending'
	);

	$errors = edd_get_errors();

	if ( $errors ) {
		// problems? send back
		edd_send_back_to_checkout( '?payment-mode=' . $purchase_data['post_data']['edd-gateway'] );
	} else {

		$payment = edd_insert_payment( $payment_data );

		// check payment
		if ( ! $payment ) {

			edd_send_back_to_checkout( '?payment-mode=' . $purchase_data['post_data']['edd-gateway'] );

		} else {

			if ( ! class_exists( 'SofortLib' ) ) {
				require_once 'library/sofortLib.php';
			}

			$return_url = add_query_arg( 'payment-confirmation', 'paypal', get_permalink( $edd_options['success_page'] ) );

			$Sofort = new SofortLib_Multipay( trim( $edd_options['sofort_config_id'] ) );
			$Sofort->setSofortueberweisung();
			$Sofort->setAmount( $purchase_data['price'] );
			$Sofort->setReason( 'CartId ' . $payment, $purchase_data['post_data']['edd_first'] .' '. $purchase_data['post_data']['edd_last'] );
			$Sofort->addUserVariable( $payment );
			$Sofort->setSuccessUrl( $return_url );
			$Sofort->setAbortUrl( edd_get_failed_transaction_uri() );
			$Sofort->setTimeoutUrl( edd_get_failed_transaction_uri() );
			$Sofort->setNotificationUrl( home_url( '/?sofort=ipn' ) );
			$Sofort->sendRequest();

			if ( $Sofort->isError() ) {
				//PNAG-API didn't accept the data
				wp_die( $Sofort->getError(), 'Error' );
			} else {
				//buyer must be redirected to $paymentUrl else payment cannot be successfully completed!
				$paymentUrl = $Sofort->getPaymentUrl();
				edd_empty_cart();
				wp_redirect( $paymentUrl ); exit;
			}

		}

	}

}
add_action( 'edd_gateway_sofort', 'sofort_process_payment' );

function sofort_ipn() {
	global $edd_options;

	if ( isset( $_GET['sofort'] ) && $_GET['sofort'] == 'ipn' ) {

		require_once 'library/sofortLib.php';
		$notification = new SofortLib_Notification();
		$notification->getNotification();

		$transactionId = $notification->getTransactionId();

		if ( $transactionId ) {

			// fetch some information for the transaction id retrieved above
			$transactionData = new SofortLib_TransactionData( trim( $edd_options['sofort_config_id'] ) );
			$transactionData->setTransaction( $transactionId );
			$transactionData->sendRequest();
			$reason     = $transactionData->getReason();
			$payment_id = str_replace( 'CartId ', '', $reason[0] );
			edd_update_payment_status( $payment_id, 'publish' );

			edd_insert_payment_note( $payment_id, 'Payment Successful. Transaction ID is ' . $transactionId );

		}
		exit;
	}
}
add_action( 'init', 'sofort_ipn' );

/**
* Register our settings section
*
* @return array
*/
function sofort_edd_settings_section( $sections ) {
	// Note the array key here of 'sofort-settings'
	$sections['sofort-settings'] = __( 'SofortBanking', 'edd_sofort' );
	return $sections;
}
add_filter( 'edd_settings_sections_gateways', 'sofort_edd_settings_section' );

/**
* Register our settings
*
* @return array
*/
function sofort_add_settings( $settings ) {

	$sofort_settings = array(
		array(
			'id' => 'sofort_settings',
			'name' => '<strong>' . __( 'SofortBanking Settings', 'edd_sofort' ) . '</strong>',
			'desc' => __( 'Configure the gateway settings', 'edd_sofort' ),
			'type' => 'header'
		),
		array(
			'id' => 'sofort_config_id',
			'name' => __( 'Configuration ID', 'edd_sofort' ),
			'desc' => __( 'Please enter your Configuration ID. Found in the API Key section of your Sofort account.', 'edd_sofort' ),
			'type' => 'text',
			'size' => 'regular'
		)
	);

	// If EDD is at version 2.5 or later...
	if ( version_compare( EDD_VERSION, 2.5, '>=' ) ) {
		// Use the previously noted array key as an array key again and next your settings
		$sofort_settings = array( 'sofort-settings' => $sofort_settings );
	}

	return array_merge( $settings, $sofort_settings );
}
add_filter( 'edd_settings_gateways', 'sofort_add_settings' );
