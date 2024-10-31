<?php
/*
Plugin Name: SEPA Direct Debit for Subscriptions
Plugin URI: http://vansteinengroentjes.nl
Description: payIBAN Payment gateway for WooCommerce subscriptions SEPA direct debit payments in more than 33 countries.
Version: 4.5.2
Author: van Stein en Groentjes
Author URI: https://vansteinengroentjes.nl
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}


//Cron part to check payments made in PayIBAN



load_plugin_textdomain( 'woocommerce-payiban', false, plugin_basename( dirname( __FILE__ ) ) . '/languages/' ); 




/**
 * Adds a box to the main column on the Post and Page edit screens to let the user select the recurrent period
 */
add_action( 'add_meta_boxes', 'payiban_product_period_mb' );
function payiban_product_period_mb() {
    add_meta_box(
        'wc_product_period_mb',
        __( 'PayIBAN: Recurring payment option.', 'woocommerce-payiban' ),
        'payiban_product_period_inner_mb',
        'product',
        'normal',
        'high'
    );
}


function payibansepa_change_cart($price,$cart_item,$cart_item_key) {
	$price_ori = $_real_price;
	$initial_price = "";
	
	$perc = get_post_meta( $_productID, 'product_discountperc', true );
	$fixed = get_post_meta( $_productID, 'product_discountfixed', true );
	$price = $_real_price;
	if ($perc > 0 && $perc <= 100){
		$price = $price * (1.0- $perc/100.0);
	}if ($fixed > 0 ){
		$price = $price - $fixed;
	}
	if ($price != $_real_price){
		$initial_price = " (first term)";
	}
	$price = wc_price($price);

	echo $price.$initial_price;
	
}
add_action( 'woocommerce_cart_item_price', 'payibansepa_change_cart', 1, 3 );


function payibansepa_change_summary() {
	$_productID= get_the_ID();  

	$initial_price = "";

	$perc = get_post_meta( $_productID, 'product_discountperc', true );
	$fixed = get_post_meta( $_productID, 'product_discountfixed', true );
	if ($perc > 0 && $perc <= 100){
		$initial_price = __(" first term with ","woocommerce-payiban").$perc.__("% discount.","woocommerce-payiban");
	}else if ($fixed > 0 ){
		$initial_price = __(" first term with ","woocommerce-payiban").wc_price($fixed).__(" discount.","woocommerce-payiban");
	}
	if ($perc > 0 && $perc <= 100 && $fixed > 0){
		$initial_price = __(" first term with ","woocommerce-payiban").$perc.__("% and ","woocommerce-payiban").wc_price($fixed).__(" discount.","woocommerce-payiban");
	}

	echo '
	<div class="product-period">'.$initial_price.'
	</div>
	';


}
add_action( 'woocommerce_single_product_summary', 'payibansepa_change_summary', 10 );

function payibansepa_change_summary_checkout(){
  global $woocommerce;
  $cart = WC()->cart;
  $discount_fixed = 0;
  $total_price = $cart->get_total();
  foreach ( $cart->cart_contents as $product ) {
		$value3 = get_post_meta( $product["product_id"], 'product_discountperc', true );
		$value4 = get_post_meta( $product["product_id"], 'product_discountfixed', true );
		if ($value3 > 0 && $value3 <= 100){
			$discount_fixed += $product["data"]->get_price() * ($value3/100.0);
		}
		if ($value4 > 0){
			$discount_fixed += $value4;
		}
        
    }
    if ($discount_fixed>0){
    	echo __("A first term discount will be applied of ","woocommerce-payiban").wc_price( -$discount_fixed ).".";
    }
 }

add_action( 'woocommerce_review_order_before_payment', 'payibansepa_change_summary_checkout', 1 );

/**
 * Prints the box content.
 * 
 * @param WP_Post $post The object for the current post/page.
 */
function payiban_product_period_inner_mb( $post ) {

	  // Add an nonce field so we can check for it later.
	  wp_nonce_field( plugin_basename( __FILE__ ), 'wc_product_period_inner_mb_nonce' ); 
	  
	  /*
	   * Use get_post_meta() to retrieve an existing value
	   * from the database and use the value for the form.
	   */
	  $value3 = get_post_meta( $post->ID, 'product_discountperc', true );

      $value4 = get_post_meta( $post->ID, 'product_discountfixed', true );

	  echo '<p>'.__( 'Specify an optional discount for the first term in either percentage or fixed amount (only valid for PayIBAN payment gateway). Leave at 0 if you do not want to use this function, specifying both results in percentage having precedence over the fixed amount discount.', 'woocommerce-payiban' ).' </p>';
	  echo '<label for="product_discountperc_field">';
	  echo __( "First term discount in %:", 'woocommerce-payiban' );
	  echo '</label> ';
	  echo '<input type="number" value="'.$value3.'" id="product_discountperc_field"  name="product_discountperc_field">';
	  echo '<br/><label for="product_discountperc_field">';
	  echo __( "Fixed amount:", 'woocommerce-payiban' );
	  echo '</label> ';
	  echo '<input type="number" value="'.$value4.'" id="product_discountfixed_field"  name="product_discountfixed_field">';
}//function



/**
 * When the post is saved, saves our custom data.
 *
 * @param int $post_id The ID of the post being saved.
 */
function payibanfree_product_period_save_postdata( $post_id ) {
  /*
   * We need to verify this came from the our screen and with proper authorization,
   * because save_post can be triggered at other times.
   */
  // Check if our nonce is set.
  if ( ! isset( $_POST['wc_product_period_inner_mb_nonce'] ) )
    return;
  $nonce = $_POST['wc_product_period_inner_mb_nonce'];
  // Verify that the nonce is valid.
  if ( ! wp_verify_nonce( $nonce, plugin_basename( __FILE__ ) ) )
      return;
  // If this is an autosave, our form has not been submitted, so we don't want to do anything.
  if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) 
      return;
  // Check the user's permissions.
  if ( 'page' == $_POST['post_type'] ) {
    if ( ! current_user_can( 'edit_page', $post_id ) )
        return;
  } else {
    if ( ! current_user_can( 'edit_post', $post_id ) )
        return;
  }
  /* OK, its safe for us to save the data now. */
  // Sanitize user input.
  $mydata = sanitize_text_field( $_POST['product_period_field'] );
  // Update the meta field in the database.
  update_post_meta( $post_id, 'product_period', $mydata );

  $mydata = sanitize_text_field( $_POST['product_numberofperiod_field'] );
  // Update the meta field in the database.
  update_post_meta( $post_id, 'product_numberofperiod', $mydata );

  $mydata = intval(sanitize_text_field( $_POST['product_discountperc_field'] ));
  update_post_meta( $post_id, 'product_discountperc', $mydata );

  $mydata = intval(sanitize_text_field( $_POST['product_discountfixed_field'] ));
  update_post_meta( $post_id, 'product_discountfixed', $mydata );
}
add_action( 'save_post', 'payibanfree_product_period_save_postdata' );



// add custom interval
function cron_add_minute_payiban( $schedules ) {
	// Adds once every minute to the existing schedules.
    $schedules['everyminute'] = array(
	    'interval' => 3600,
	    'display' => __( 'Once Every Minute' )
    );
    return $schedules;
}
add_filter( 'cron_schedules', 'cron_add_minute_payiban' );

// here's the function we'd like to call with our cron job
function payiban_repeat_function() {
	global $woocommerce;
	// do here what needs to be done automatically as per your schedule
	// in this example we're sending an email
	$deze = new WC_payIBAN();
	
	$api_username = $deze ->merchant_id;
	$api_password = $deze ->merchant_pass;
	$emailstring = "Mandaatids:".$api_username;
	$message = "";

	if (WC_Subscriptions_Manager){
		$all_subscriptions = WC_Subscriptions_Manager::get_all_users_subscriptions();
		foreach ($all_subscriptions as $user_id => $subscriptions){
			foreach ($subscriptions as $subscription){
				//get order id
				if ($subscription['status']=="active"){
					$order_id = $subscription['order_id'];
					$mandaatid = get_post_meta( $order_id, 'Mandaatid', true );
					$emailstring .= $mandaatid." ";
					//check for payments at payiban
					$url = 'https://api.payiban.com/webservice/index.php?user='.$api_username.'&password='.$api_password.'&request=machtiging&kolom=mandateid&waarde='.$mandaatid; // basis url van elke xmlstring request
					

					$xmlresponse = file_get_contents( $url );


					//de response verwerken

					$result = simplexml_load_string( $xmlresponse );
					$error_message=$result->error;
					if ( $error_message != '' ) {
						WC_Subscriptions_Manager::process_subscription_payment_failure_on_order( $order_id );
						$subject = "PayIban Error for Mandateid";
						$message .= "
	The order with id ".$order_id." gives an error when requesting the payment mandate with id ".$mandaatid.", the subscription is put on hold. Visit your PayIBAN account for more information.";
						
						
					} else {
						//check status
						//"lopend - gestopt - storno - verwijderd"
						$machtiging_status = $result->machtiging->status;
						if ($machtiging_status != "lopend"){
							// components for our email
							// put on hold subscription
							WC_Subscriptions_Manager::process_subscription_payment_failure_on_order( $order_id );
							
							$subject = 'PayIBAN: Subscription canceled';
							$message .= "
	The order with id ".$order_id." is put on hold by the payIBAN gateway, check your PayIBAN account for details why the payment failed.";
							
							
						}

						
					}
				}
			}
		}
		if ($message != ""){
			wp_mail($deze->merchant_mail, $subject, $message);
		}
	}
}

// hook that function onto our scheduled event:
add_action ('payiban_cronjob', 'payiban_repeat_function'); 


//payiban hook bij het opslaan van een order
function post_save_subscription_check( $post_ID, $data )
{
	//check if subscription
	$mandaatid = get_post_meta($post_ID, 'Mandaatid', true);
	$IBAN = get_post_meta($post_ID, 'IBAN', true);
	if (!empty($mandaatid)){
		$subscription_key = WC_Subscriptions_Manager::get_subscription_key( $post_ID );
		$subscription = WC_Subscriptions_Manager::get_subscription( $subscription_key );

		if ($subscription['status']=="active"){

	    	$deze = new WC_payIBAN();
		
			$api_username = $deze ->merchant_id;
			$api_password = $deze ->merchant_pass;

			//get mandate from payiban
			$url = 'https://api.payiban.com/webservice/index.php?user='.$api_username.'&password='.$api_password.'&request=machtiging&kolom=mandateid&waarde='.$mandaatid; // basis url van elke xmlstring request


			$xmlresponse = file_get_contents( $url );
			$result = simplexml_load_string( $xmlresponse );
			$error_message=$result->error;

			if ($error_message == ''){
				$current_iban = $result->machtiging->iban;
				$current_rekening = $result->machtiging->rekeningnummer;
				$current_period = $result->machtiging->frequentie;
				$current_price = $result->machtiging->bedrag;
			}

			
			
			$message .= "\n Post meta keys:".json_encode($_POST['meta_key']);
			$metakeys = $_POST['meta_key'];
			$subscription_price_key = "";
			$subscription_period_key = "";
			foreach ($metakeys as $key => $value){
				if ($value == "_subscription_recurring_amount"){
					$subscription_price_key = $key;
				}
				if ($value == "_subscription_period"){
					$subscription_period_key = $key;
				}
			}
			$subscription_price = "";
			$subscription_period = "";
			$metavalues = $_POST['meta_value'];
			foreach ($metavalues as $key => $value){
				if ($key == $subscription_price_key){
					$subscription_price = $value;
				}
				if ($key == $subscription_period_key){
					$subscription_period = $value;
				}
			}
			

			//check for changes
			if ($current_iban != $IBAN && $current_rekening != $IBAN){
				//update IBAN
				$url = 'https://api.payiban.com/webservice/index.php?user='.$api_username.'&password='.$api_password.'&request=change_bankaccount&mandateid='.$mandaatid."&rekeningnummer=".$IBAN; // basis url van elke xmlstring request
				$xmlresponse = file_get_contents( $url );
				$result = simplexml_load_string( $xmlresponse );
			}
			//check for changes
			
			if ($subscription_period == "month"){
				$subscription_period = "maand";
			}else if ($subscription_period == "year"){
				$subscription_period = "jaar";
			}else if ($subscription_period == "6 months"){
				$subscription_period = "halfjaar";
			}else if ($subscription_period == "3 months"){
				$subscription_period = "halfjaar";
			}else if ($subscription_period == "2 months"){
				$subscription_period = "twee maanden";
			}else if ($subscription_period == "day"){
				$subscription_period = "dag";
			}else if ($subscription_period == "week"){
				$subscription_period = "week";
			}

			//check for changes
			if ($current_period != $subscription_period && $current_price == $subscription_price){
				//update period
				$url = 'https://api.payiban.com/webservice/index.php'; // basis url van elke xmlstring request
				$xml = '<?xml version="1.0" encoding="UTF-8"?>
<webservice>
<user>'.$api_username.'</user>
<password>'.$api_password.'</password>
<request>change_mandate</request>
<test>nee</test>
<output>xml</output>
<data>
   <item>
      <mandateid>'.$mandaatid.'</mandateid>
      <periode>'.$subscription_period.'</periode>
      <bedrag>'.$subscription_price.'</bedrag>
   </item>
</data>
</webservice>';
			}
			else if ($current_price != $subscription_price){
				//update price
				$url = 'https://api.payiban.com/webservice/index.php'; // basis url van elke xmlstring request
				$xml = '<?xml version="1.0" encoding="UTF-8"?>
<webservice>
<user>'.$api_username.'</user>
<password>'.$api_password.'</password>
<request>change_mandate</request>
<test>nee</test>
<output>xml</output>
<data>
   <item>
      <mandateid>'.$mandaatid.'</mandateid>
      <periode>'.$subscription_period.'</periode>
      <bedrag>'.$subscription_price.'</bedrag>
   </item>
</data>
</webservice>';
				$xmlresponse = file_get_contents( $url.'?xmlstring='.urlencode( $xml ) );
			}
	       

	        
	        //wp_mail($deze->merchant_mail, $mandaatid, $message);
	    }
    }
}
add_action('pre_post_update', 'post_save_subscription_check', 10, 2 );



//pre_post_update
/**
 * Check if WooCommerce is active
 **/
if ( true || in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {
	
	/**
	* Add welcome screen
	**/
	add_action('admin_menu', 'payiban_welcome_screen_page');
	function payiban_welcome_screen_page(){
		add_dashboard_page('Welcome', 'Welcome', 'read', 'payiban-plugin-welcome', 'payiban_welcome_page');
	}
	function payiban_welcome_page(){
		echo '<iframe src="'.__("https://www.payiban.com/welcome/",'woocommerce-payiban' ).'" style="position: absolute; height: 1000px; width:100%; border: none"></iframe>';
	}
	add_action('activated_plugin','payiban_welcome_redirect');
	function payiban_welcome_redirect($plugin)
	{
		if($plugin=='payiban-sepa-direct-debit-for-subscriptions/woocommerce-payIBAN.php') {
			//create new account

			wp_redirect(admin_url('admin.php?page=wc-settings&tab=checkout&section=wc_payiban'));
			die();
		}
	}


	// create a scheduled event (if it does not exist already)
	function payiban_cronstarter_activation() {
		if( !wp_next_scheduled( 'payiban_cronjob' ) ) {  
		   wp_schedule_event( time(), 'daily', 'payiban_cronjob' );   //daily
		}
	}

	// and make sure it's called whenever WordPress loads
	add_action('wp', 'payiban_cronstarter_activation');

	// unschedule event upon plugin deactivation
	function payiban_cronstarter_deactivate() {	
		// find out when the last event was scheduled
		$timestamp = wp_next_scheduled ('payiban_cronjob');
		// unschedule previous event if any
		wp_unschedule_event ($timestamp, 'payiban_cronjob');
	} 
	register_deactivation_hook (__FILE__, 'payiban_cronstarter_deactivate');

	add_action( 'admin_head', 'payiban_remove_menu_entry' );
	function payiban_remove_menu_entry(){
		remove_submenu_page( 'index.php', 'payiban-plugin-welcome' );
	}

	add_action( 'plugins_loaded', 'woocommerce_payiban_init', 0 );
	function woocommerce_payiban_init() {
		

		if ( !class_exists( 'WC_Payment_Gateway' ) ) return;
		if ( !function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH.'/wp-admin/includes/plugin.php';
		}
		
		//functions to check iban account numbers
		require_once 'checkiban.php';


		class WC_payIBAN extends WC_Payment_Gateway{

			public function __construct() {
				global $woocommerce;


				$this -> id = 'payiban';
				$this -> method_title = 'PayIBAN';
				$this -> icon = plugins_url( plugin_basename( dirname( __FILE__ ) ).'/payibanlogo.png', dirname( __FILE__ ) );
				$this -> has_fields = true;

				$this -> init_form_fields();
				$this -> init_settings();

				$this -> title = $this -> settings['title'];
				$this -> description = $this -> settings['description'];

				$this -> test_mode = $this -> settings['test_mode'];
				$this -> merchant_id = $this -> settings['merchant_id'];
				$this -> merchant_pass = $this -> settings['merchant_pass'];
				$this -> text_language = $this -> settings['text_language'];
				$this -> redirect_page_id = $this -> settings['redirect_page_id'];
				$this -> liveurl = 'not needed';
				$this -> merchant_mail = $this -> settings['merchant_mail'];
				$this -> generalterm = $this -> settings['generalterm'];


				$this -> msg['message'] = '';
				$this -> msg['class'] = '';

				if ( version_compare( WOOCOMMERCE_VERSION, '2.0.0', '>=' ) ) {
					add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( &$this, 'process_admin_options' ) );
				} else {
					add_action( 'woocommerce_update_options_payment_gateways', array( &$this, 'process_admin_options' ) );
				}



				//Register subscriptions and products
				if ( is_plugin_active( 'woocommerce-subscriptions/woocommerce-subscriptions.php' ) ) {
					$this->supports = array(  'subscriptions', 'subscription_cancellation','subscription_amount_changes', 'gateway_scheduled_payments','subscription_suspension','subscription_reactivation' );
					/*Later perhaps:
					'products',
					'subscription_cancellation',
				    'subscription_suspension',
				    'subscription_reactivation',
				    'subscription_amount_changes',
				    'subscription_date_changes',
				    'subscription_payment_method_change'*/
					add_action( 'cancelled_subscription_payiban', array( &$this, 'cancel_payiban' ), 10, 2 );
					add_action( 'woocommerce_subscriptions_changed_failing_payment_method_payiban', array( &$this, 'change_payment__payiban' ), 10, 2 );
				}
			}

			/*Function to set the options for the admin, generates the fields for the form*/
			function init_form_fields() {
				$this -> form_fields = array(
					
					'enabled' => array(
						'title' => __( 'Enable/Disable', 'woocommerce-payiban' ),
						'type' => 'checkbox',
						'label' => __( 'Enable payIBAN Payment Module.', 'woocommerce-payiban' ),
						'default' => 'no' ),
					'test_mode' => array(
						'title' => __( 'Test mode', 'woocommerce-payiban' ),
						'type' => 'checkbox',
						'label' => __( 'In the test mode, payments are not processed.', 'woocommerce-payiban' ),
						'default' => 'yes' ),
					'title' => array(
						'title' => __( 'Title:', 'woocommerce-payiban' ),
						'type'=> 'text',
						'description' => __( 'This controls the title which the user sees during checkout.', 'woocommerce-payiban' ),
						'default' => __( 'SEPA Direct Debit processed by', 'woocommerce-payiban' ) ),
					'description' => array(
						'title' => __( 'Description:', 'woocommerce-payiban' ),
						'type' => 'textarea',
						'description' => __( 'This controls the description which the user sees during checkout.', 'woocommerce-payiban' ),
						'default' => __( 'Pay securely by using your IBAN bank account with PayIBAN.', 'woocommerce-payiban' ) ),
					'generalterm' => array(
						'title' => __( 'General terms:', 'woocommerce-payiban' ),
						'type' => 'textarea',
						'description' => __( 'This controls the general term message which the user sees during checkout.', 'woocommerce-payiban' ),
						'default' => __( 'By signing this (recurring) mandate form, you authorise (merchant) to send instructions.', 'woocommerce-payiban' ) ),
					'merchant_id' => array(
						'title' => __( 'Username', 'woocommerce-payiban' ),
						'type' => 'text',
						'description' => __( 'Check username and password within section API and plugin of your account.  <a href="https://www.payiban.com/nl/aanmelden/">Click to register your free account.</a>', 'woocommerce-payiban' ),
						'default' => get_option( "payiban_api_user", ""),
						 ),
					'merchant_pass' => array(
						'title' => __( 'Password', 'woocommerce-payiban' ),
						'type' => 'text',
						'description' =>  __( 'API password given to you by payIBAN.<br/>Need support: <a href="mailto:support@payiban.com">support@payiban.com</a>.', 'woocommerce-payiban' ),
						'default' => get_option( "payiban_api_pass", "" ),
						),
					'merchant_mail' => array(
						'title' => __( 'Admin Email', 'woocommerce-payiban' ),
						'type' => 'text',
						'description' =>  __( '!Important: Email adress for notices about cancelled subscriptions', 'woocommerce-payiban' ),
						'default' => get_option( 'admin_email' ) ),
					'text_language' => array(
						'title' => __( 'Default text language', 'woocommerce-payiban' ),
						'type' => 'text',
						'description' =>  __( 'Language of the TAN-code text message. Two letters like "nl", "en", "es" etc.', 'woocommerce-payiban' ),
						'default' => 'nl' ),
					'redirect_page_id' => array(
						'title' => __( 'Return Page' ),
						'type' => 'select',
						'options' => $this -> get_pages( 'Select Page' ),
						'description' => __( 'URL of success page', 'woocommerce-payiban' ),
						)
					
				);
			}

			public function create_url( $args ) {
		        $base_url = 'https://www.payiban.com/nl/';//'https://vansteinengroentjes.nl/agora/';//'https://www.payiban.com/nl/';

		        $base_url = add_query_arg( 'wc-api', 'software-api', $base_url );
		        return $base_url . '&' . http_build_query( $args );
			}

			public function admin_options() {
				 // check user capabilities
		        if (!current_user_can('manage_options')) {
		            return;
		        }
		        
				echo '<h3>'.__( 'PayIBAN Payment Gateway', 'woocommerce-payiban' ).'</h3>';
				echo '<p>'.__( 'PayIBAN plugin made by Bas van Stein, van Stein en Groentjes', 'woocommerce-payiban' ).'</p>';
				echo '<table class="form-table">';
				// Generate the HTML For the settings form.
				
				$this -> generate_settings_html();
				echo '</table>';
			}

			/**
			 * Form that the user sees on checkout. Handles filling in data, requesting TAN code and final payment registration.
			 * */
			function payment_fields() {
				echo wpautop( wptexturize( $this -> description ) );
				global $woocommerce;

				$redirect_url = ( $this -> redirect_page_id=='' || $this -> redirect_page_id==0 )?get_site_url() . '/':get_permalink( $this -> redirect_page_id );
				$user_id = get_current_user_id();

				
				echo '
					<h5>'.__( '1. IBAN and mobile.', 'woocommerce-payiban' ).' </h5>


				   
				    <input name="iban" id="iban" type="text" value="" placeholder="'.__( 'IBAN number', 'woocommerce-payiban' ).'"/>

				    <input name="mobilephone" id="mobilephone" type="text" value="" placeholder="'.__( 'Mobile number', 'woocommerce-payiban' ).'"/>';

				    /*<label for="geslacht">'.__( 'Account name:', 'woocommerce-payiban' ).'*</label><br/>
				    <select name="geslacht" >
				        <option value="man">'.__( 'Mr.', 'woocommerce-payiban' ).'</option>
				        <option value="vrouw">'.__( 'Ms.', 'woocommerce-payiban' ).'</option>
				    </select>
				    <input name="voorletters" id="payIBAN_inititials" type="text"  placeholder="'.__( 'Initials', 'woocommerce-payiban' ).'"/>
				    <input name="tussenvoegsel" id="payIBAN_preposition" type="text" placeholder="'.__( 'Preposition', 'woocommerce-payiban' ).'" />
				    <input name="achternaam" id="payIBAN_familyname" type="text"  placeholder="'.__( 'Family name', 'woocommerce-payiban' ).'"/>*/
				 echo '
				 	<hr>
				    <h5>'.__( '2. Request your TAN-code', 'woocommerce-payiban' ).'</h5><br/>
				    <input type="submit" name="tancode_aanvraag" value="'.__( 'Request TAN code', 'woocommerce-payiban' ).'"><br/>
				     <hr>
				    <h5>'.__( '3. Fill in your TAN-code.', 'woocommerce-payiban' ).'</h5>
				  
				    
				    <input name="tancode" type="text" id="payIBAN_TANcode" placeholder="TAN-code"/>';
					echo '<p><br/>'.$this -> generalterm.'</p>';

			}

			/**
			 * FUNCTION TO CHECK THE FORM ABOVE
			 */
			function validate_fields() {
				global $woocommerce;
				//Requested TAN Code

				if ( $_POST['tancode'] == '' || isset( $_POST['tancode_aanvraag'] ) ) {
					$api_mobielnummer = $_POST['mobilephone'];

					if ( $api_mobielnummer == '' ) {
						wc_add_notice( __('Error! For receiving the PayIBAN TAN code, a valid number is required (31612345678).', 'woocommerce-payiban' ) ,'error');
					
						return false;
					}

					if ( strpos( $api_mobielnummer, '06' ) === 0 ) {
						$api_mobielnummer = preg_replace( '/06/', '316', $api_mobielnummer, 1 );
					}


					$language = $this ->text_language;

					$api_username = $this ->merchant_id;
					$api_password = $this ->merchant_pass;
					$api_test = 'nee';
					if ( $this->test_mode == 'no' ) {
						$api_test = 'nee';
					}else {
						$api_test = 'ja';
					}
					//SEND TAN CODE
					try {
						$url = 'https://api.payiban.com/webservice/index.php'; // basis url van elke xmlstring request
						$xml = '<?xml version="1.0" encoding="UTF-8"?> <webservice> <user>'.$api_username.'</user> <password>'.$api_password.'</password> <request>sms_tancode</request> <test>'.$api_test.'</test> <output>xml</output> <data> <item><taalcode>'.$language.'</taalcode> <mobielnummer>'.$api_mobielnummer.'</mobielnummer> </item> </data> </webservice>';




						$xmlresponse = file_get_contents( $url.'?xmlstring='.urlencode( $xml ) );

						//de response verwerken

						$result = simplexml_load_string( $xmlresponse );
						$error_message=$result->error;
						if ( $error_message != '' ) {
							wc_add_notice( __( 'ERROR! ', 'woocommerce-payiban' ) . $error_message  ,'error');
						}else {
							wc_add_notice( __( 'Go to step 3 and enter your TAN code. ', 'woocommerce-payiban' ) ,'error');
						}
					}catch (Exception $e) {
						wc_add_notice( __( 'Caught exception: ', 'woocommerce-payiban' ).$e->getMessage() ,'error');
						
					}
					return false;
				}else {
					if ( $_POST['iban'] == '' || $_POST['billing_first_name']=='' ||  $_POST['billing_last_name']=='' ) {
						wc_add_notice( __( 'ERROR! Please fill in all fields. ', 'woocommerce-payiban' ) ,'error');
						return false;
					}
					if ( $this->Proef11( $_POST['iban'] ) == false && verify_iban($_POST['iban']) == false) {
						wc_add_notice( __( 'ERROR! The given IBAN account number is not valid. ', 'woocommerce-payiban' ) ,'error');
						return false;
					}

					return true;
				}
			}

			

			/**
			 * Process the payment and return the result
			 * */
			function process_payment( $order_id ) {

				global $woocommerce;

				$order = new WC_Order( $order_id );
				

				// API account settings
				$api_username = $this ->merchant_id;
				$api_password = $this ->merchant_pass;
				$api_test = 'nee';
				$api_aantal_termen = 0;
				if ( $this->test_mode == 'no' ) {
					$api_test = 'nee';
				}else {
					$api_test = 'ja';
				}
				$initial_price = 0;



				if (!is_plugin_active( 'woocommerce-subscriptions/woocommerce-subscriptions.php' ) 
					|| WC_Subscriptions_Order::order_contains_subscription( $order_id ) === false) {

				//	|| WC_Subscriptions_Cart::cart_contains_subscription( $woocommerce->cart ) == false) {

					//One time payment
					$api_periode = 'eenmalig';
					$price = $order->get_total();
					$initial_price = $price;
				} else {

					if (WC_Subscriptions_Order::get_subscription_trial_length( $order ) > 0){
						wc_add_notice( __( 'ERROR! payIBAN does not support trial periods or signup fees. ', 'woocommerce-payiban' ) ,'error');
						
						return;
					}

					$periodname = WC_Subscriptions_Order::get_subscription_period( $order );
					

					$price = WC_Subscriptions_Order::get_recurring_total( $order );
					//$price = WC_Subscriptions_Order::get_price_per_period( $order );
					$initial_price = WC_Subscriptions_Order::get_total_initial_payment( $order );
					
					$period = WC_Subscriptions_Order::get_subscription_interval( $order );

					$order_items = $order->get_items();
					//print_r($order_items);
					$first_discount_perc = 0;
					$first_discount_fixed = 0;
					foreach ($order_items as $key => $item) {
						$product = $item->get_product();
						$first_discount_perc = get_post_meta( $product->get_id(), 'product_discountperc', true );
      					$first_discount_fixed = get_post_meta( $product->get_id(), 'product_discountfixed', true );
					}
					// Only one subscription allowed in the cart when PayIBAN is active
					//$product = $order_items[0]->get_product(); //$order->get_product_from_item(  );

					$api_aantal_termen = WC_Subscriptions_Product::get_length( $product );


					if ( strtolower( $periodname ) == 'day' && $period!=1 ) {
						wc_add_notice( __( 'ERROR! payIBAN only supports per day, per week, per month, per two / three months, per six months and per year. ', 'woocommerce-payiban' ) ,'error');
						return false;
					}
					if ( strtolower( $periodname ) == 'week' && $period!=1 ) {
						wc_add_notice( __( 'ERROR! payIBAN only supports per day, per week, per month, per two / three months, per six months and per year. ', 'woocommerce-payiban' ) ,'error');
						return false;
					}
					if ( strtolower( $periodname ) == 'year' && $period==1 ) {
						$period = 12;
					}

					if ( strtolower( $periodname ) == 'day' && $period==1 ) {
						$api_periode = 'dag';
					}
				    else if ( strtolower( $periodname ) == 'week' && $period==1 ) {
						$api_periode = 'week';
					}else if ( $period==1 ) {
						$api_periode = 'maand';
					}else if ( $period==2 ) {
						$api_periode = 'twee maanden';
					}else if ( $period==3 ) {
						$api_periode = 'kwartaal';
					}else if ( $period==6 ) {
						$api_periode = 'halfjaar';
					}else if ( $period==12 ) {
						$api_periode = 'jaar';
					}else {
						wc_add_notice( __( 'ERROR! payIBAN only supports per day, per week, per month, per two / three months, per six months and per year. ', 'woocommerce-payiban' ) ,'error');
						return false;
					}
				}

				
				// Het bedrag van de machtiging
				$api_bedrag = $price;
				$api_first_bedrag = $initial_price;
				if ($first_discount_perc > 0 && $first_discount_perc <= 100){
					$api_first_bedrag = $api_first_bedrag * (1.0- $first_discount_perc/100.0);
				}if ($first_discount_fixed > 0 ){
					$api_first_bedrag = $api_first_bedrag - $first_discount_fixed;
				}


				$year = date( 'Y' );
				$month = date( 'm' );
				$monthint = intval( date( 'n' ) );
				$datum = date( 'Y-m-d' );
				$day = intval( date( 'j' ) );

				//get voorletters from first_name
				$words = preg_split("/[\s,_-]+/", sanitize_text_field($_POST['billing_first_name']));
				$acronym = "";

				foreach ($words as $w) {
					$acronym .= $w[0]. ".";
				}
				$acronym = trim($acronym);

				// Posts vanuit het formulier
				$api_tancode        = $_POST['tancode'];
				$api_toevoegdatum   = $datum;
				$api_geslacht       = "man"; //$_POST['geslacht'];
				$api_voorletters    = $acronym; //$_POST['billing_first_name'];
				$api_tussenvoegsel  = ""; //$_POST['tussenvoegsel'];
				$api_achternaam     = $_POST['billing_last_name'];
				$api_straat         = $_POST['billing_address_1'];
				$api_postcode       = $_POST['billing_postcode'];
				$api_woonplaats     = $_POST['billing_city'];
				$api_emailadres     = $_POST['billing_email'];
				$api_iban           = $_POST['iban'];
				$api_mobielnummer   = $_POST['mobilephone'];
				$api_referentie     = 'payIBAN order '.$order_id;


				// TOEVOEGEN van een nieuwe machtiging
				$url = 'https://api.payiban.com/webservice/index.php'; // Basis url van elke xmlstring request
				$xml = '
<?xml version="1.0" encoding="UTF-8"?>
<webservice>
    <user>'.$api_username.'</user>
    <password>'.$api_password.'</password>
    <request>import_machtiging</request>
    <test>'.$api_test.'</test>
    <data>
        <item>
            <tancode>'.$api_tancode.'</tancode>
            <periode>'.$api_periode.'</periode>';

            if ($api_aantal_termen > 0){
					$xml .= '<termijnbetaling>true</termijnbetaling>
							<aantaltermijnen>'.$api_aantal_termen.'</aantaltermijnen>
							<bedragdelendoortermijnen>false</bedragdelendoortermijnen>
							<frequency>'.$api_periode.'</frequency>';
			}
			$xml .= '            <toevoegdatum>'.$api_toevoegdatum.'</toevoegdatum>
            <bedrag>'.$api_first_bedrag.'</bedrag>
            <std_bedrag>'.$api_bedrag.'</std_bedrag>
            <bedrijfsnaam></bedrijfsnaam>
            <geslacht>'.$api_geslacht.'</geslacht>
            <voorletters>'.$api_voorletters.'</voorletters>
            <tussenvoegsel>'.$api_tussenvoegsel.'</tussenvoegsel>
            <achternaam>'.$api_achternaam.'</achternaam>
            <straat>'.$api_straat.'</straat>
            <postcode>'.$api_postcode.'</postcode>
            <woonplaats>'.$api_woonplaats.'</woonplaats>
            <emailadres>'.$api_emailadres.'</emailadres>
            <iban>'.$api_iban.'</iban>
            <bic></bic>
            <mobielnummer>'.$api_mobielnummer.'</mobielnummer>
            <referentie>'.$api_referentie.'</referentie>
        </item>
    </data>
</webservice>';

				$xmlresponse = file_get_contents( $url.'?xmlstring='.urlencode( $xml ));

				$result = simplexml_load_string( $xmlresponse );

				if ( $result->error != '' ) {

					wc_add_notice( __( 'Payment error:', 'woocommerce-payiban' ) . $result->error  ,'error');
					return;
				}else {
					$mandaatid = (string)$result->data->item->mandateid;
					//update the order meta data
					update_post_meta( $order_id, 'Mandaatid', $mandaatid);
					update_post_meta( $order_id, 'IBAN', $api_iban);

					//get other data from api

					$api_username = $this ->merchant_id;
					$api_password = $this ->merchant_pass;
					//here we can get the other information from the API
					$url = 'https://api.payiban.com/webservice/index.php?user='.$api_username.'&password='.$api_password.'&request=machtiging&kolom=mandateid&waarde='.$mandaatid; // basis url van elke xmlstring request
				
					$xmlresponse = file_get_contents( $url );
					//de response verwerken
					$result = simplexml_load_string( $xmlresponse );
					$error_message=$result->error;
					if ($error_message == ''){
						$current_iban = $result->machtiging->iban;
						$current_bic = $result->machtiging->bic;
						$current_gebruikers_id = $result->machtiging->gebruikers_id;
						$current_rekening = $result->machtiging->rekeningnummer;
						$current_period = $result->machtiging->frequentie;
						$current_price = $result->machtiging->bedrag;
						update_post_meta( $order_id, 'IBAN', (string)$current_iban);
						update_post_meta( $order_id, 'BIC', (string)$current_bic);
						update_post_meta( $order_id, 'Accountholder', (string)$current_gebruikers_id);
						update_post_meta( $order_id, 'Rekening', (string)$current_rekening);
						update_post_meta( $order_id, 'PayIBAN periode', (string)$current_period);
						update_post_meta( $order_id, 'PayIBAN prijs', (string)$current_price);
					}

					$order->payment_complete();
					// Return thank you page redirect
					return array(
						'result' => 'success',
						'redirect' => $this->get_return_url( $order )
					);
				}


			}

			function Proef11( $bankrek ) {
				$csom = 0;                                       // variabele initialiseren
				$pos = 9;                                        // het aantal posities waaruit een bankrekeningnr hoort te bestaan
				for ( $i = 0; $i < strlen( $bankrek ); $i++ ) {
					$num = substr( $bankrek, $i, 1 );                  // bekijk elk karakter van de ingevoerde string
					if ( is_numeric( $num ) ) {                      // controleer of het karakter numeriek is
						$csom += $num * $pos;                       // bereken somproduct van het cijfer en diens positie
						$pos--;                                     // naar de volgende positie
					}
				}
				$postb = ( ( $pos > 1 ) && ( $pos < 7 ) );         // True als resterende posities tussen 1 en 7 => Postbank
				$mod = $csom % 11;                                                                                                                                                                                                // bereken restwaarde van somproduct/11.
				return $postb || !( $pos || $mod );             // True als het een postbanknr is of restwaarde=0 zonder resterende posities
			}






			// get all pages HELPER FUNCTION
			function get_pages( $title = false, $indent = true ) {
				$wp_pages = get_pages( 'sort_column=menu_order' );
				$page_list = array();
				if ( $title ) $page_list[] = $title;
				foreach ( $wp_pages as $page ) {
					$prefix = '';
					// show indented child pages?
					if ( $indent ) {
						$has_parent = $page->post_parent;
						while ( $has_parent ) {
							$prefix .=  ' - ';
							$next_page = get_page( $has_parent );
							$has_parent = $next_page->post_parent;
						}
					}
					// add to page list array array
					$page_list[$page->ID] = $prefix . $page->post_title;
				}
				return $page_list;
			}

			/**
			 * When a subscription is canceled, notify the admin by email and cancel the subscription in woocommerce
			 * Admin has to cancel the subscription in payIBAN.
			 * */
			function cancel_payiban( $order, $product_id ) {
				global $woocommerce;


				$order_id = ( WC()->version < '2.7.0' ) ? $order->id : $order->get_id();
				$mandaatid = get_post_meta( $order_id, 'Mandaatid', true );

				$api_username = $this ->merchant_id;
				$api_password = $this ->merchant_pass;
				$api_test = 'nee';
				if ( $this->test_mode == 'no' ) {
					$api_test = 'nee';
				}else {
					$api_test = 'ja';
				}


				$url = 'https://api.payiban.com/webservice/index.php'; // Basis url van elke xmlstring request
				$xml = '
				<?xml version="1.0" encoding="UTF-8"?>
				<webservice>
					<user>'.$api_username.'</user>
				    <password>'.$api_password.'</password>
				    <request>verwijder_machtiging</request>
				    <test>'.$api_test.'</test>
				    <output>xml</output>
				    <data>
				        <item>
				            <mandateid>'.$mandaatid.'</mandateid>
				        </item>
				    </data>
				</webservice>';



				$xmlresponse = file_get_contents( $url.'?xmlstring='.urlencode( $xml ));
				//@todo@ split on nothing here for you
				$result = simplexml_load_string( $xmlresponse );

				if ( $result->error != '' ) {
					wc_add_notice( __( 'Error canceling your subscription, please contact the website admin.<br/>', 'woocommerce-payiban' ) . $result->error  ,'error');
					
					return;
				}else {
					if ( $this -> merchant_mail != '' ) {
						$message = __( 'Dear Administrator,\nOne of your customers has cancelled a subscription.\nThe subscription is also canceled in payIBAN.\n\nDetails of the order:', 'woocommerce-payiban' );

						$_pf = new WC_Product_Factory();
						$_product = $_pf->get_product( $product_id );

						if (is_plugin_active( 'woocommerce-subscriptions/woocommerce-subscriptions.php' ) ){ 
							$periodname = WC_Subscriptions_Order::get_subscription_period( $order );
							$price = WC_Subscriptions_Order::get_recurring_total( $order );
							$period = WC_Subscriptions_Order::get_subscription_interval( $order );
						}else{
							$periodname = 'one-time';
							$period = '';
							$price = $_product->price;
						}

						

						$message .= 'Customer ID: '.$order->user_id.'\n';
						$message .= 'Order number: '.$order->get_order_number().'\n';
						$message .= ''.$price.' per '.$period.' '.$periodname.'\n';
						$message .= 'Product description: '.$_product->get_title().'\n\nKind regards,\n payIBAN plugin.';

						wp_mail( $this -> merchant_mail, __( 'IMPORTANT: Cancelled Subscription', 'woocommerce-payiban' ), $message );
						//wc_add_notice( __('Subscription successfully ended. ', 'woocommerce-payiban'), $notice_type = 'success' );
					}
					return true;
				}
			}
		}



		

		/**
		 * Add the Gateway to WooCommerce
		 * */
		function woocommerce_add_payiban_gateway( $methods ) {
			$methods[] = 'WC_payIBAN';
			return $methods;
		}

		add_filter( 'woocommerce_payment_gateways', 'woocommerce_add_payiban_gateway' );

	}
}