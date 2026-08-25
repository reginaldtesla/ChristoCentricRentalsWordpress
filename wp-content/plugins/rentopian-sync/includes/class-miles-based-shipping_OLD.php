<?php

// function miles_based_shipping_method() {

//     if (class_exists('Miles_Based_Shipping_Method')) {
//         return;
//     }

//     /**
//      * Miles_Based_Shipping_Method class.
//      * Add Miles based shipping
//      * @version		1.0.0
//      * @author 		Nishant Shaligram
//      */
//     class Miles_Based_Shipping_Method extends WC_Shipping_Method {
//         /**
//          * Constructor for your shipping class
//          *
//          * @access public
//          * @return void
//          */

//         public $id;
//         public $method_title;
//         public $method_description;
//         public $enabled;
//         public $title;
//         public $first_miles;
//         public $first_miles_rate;
//         public $per_mile_rate;

//         public function __construct() {
//             $this->id                 = 'miles_based';

//             $shipping_settings = get_option('rental_shipping_settings');
//             $shipping_title = $shipping_settings && isset($shipping_settings->shipping_by_title) && $shipping_settings->shipping_by_title ? $shipping_settings->shipping_by_title : 'Miles';
//             $shipping_label = $shipping_title . ' Based Shipping';
//             // if ($rental_miles_shipping_label_text = get_option('rental_miles_shipping_label_text', '')) {
//             //     $shipping_label = $rental_miles_shipping_label_text;
//             // }

//             $this->method_title       = __( $shipping_label, 'rentopian-sync' );
//             $this->method_description = __( 'Custom Shipping Method for '.$shipping_title.' Based', 'rentopian-sync' );
            
//             $this->init();

//             $this->enabled = isset( $this->settings['enabled'] ) ? $this->settings['enabled'] : 'yes';
//             $this->title = isset( $this->settings['title'] ) ? $this->settings['title'] : __( $shipping_label, 'rentopian-sync' );
//             $this->first_miles = isset( $this->settings['first_miles'] ) ? $this->settings['first_miles'] : 0;
//             $this->first_miles_rate = isset( $this->settings['first_miles_rate'] ) ? $this->settings['first_miles_rate'] : 0;
//             $this->per_mile_rate = isset( $this->settings['per_mile_rate'] ) ? $this->settings['per_mile_rate'] : 0;
//         }

//         /**
//          * Init your settings
//          *
//          * @access public
//          * @return void
//          */
//         function init() {
//             // Load the settings API
//             $this->init_form_fields();
//             $this->init_settings();

//             // Save settings in admin if you have any defined
//             add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );

//             //register extra fees
//             // $shipping_selected = empty($_SESSION['rental_pick_up']) || get_option('rental_disable_pick_up_option');
//             // if ($shipping_selected && !get_option('rental_do_not_use_rentopian_shipping')  ) {
//             //     add_action('woocommerce_cart_calculate_fees', array( $this,'rental_calculate_cart_fees' ) );
//             // }
//         //                    add_action('woocommerce_cart_calculate_fees', array( $this,'rental_shipping_tax_fees' ) );
//         }

//         /**
//          * Define settings field for this shipping
//          * @return void
//          */
//         function init_form_fields() {

//             $shipping_settings = get_option('rental_shipping_settings');
//             $shipping_title = $shipping_settings && isset($shipping_settings->shipping_by_title) && $shipping_settings->shipping_by_title ? $shipping_settings->shipping_by_title : 'Miles';

//             $shipping_label = $shipping_title . ' Based Shipping';
//             if ($rental_miles_shipping_label_text = get_option('rental_miles_shipping_label_text', '')) {
//                 $shipping_label = $rental_miles_shipping_label_text;
//             }

//             // We will add our settings here
//             $this->form_fields = array(
//                 'enabled' => array(
//                     'title' => __( 'Enable', 'rentopian-sync' ),
//                     'type' => 'checkbox',
//                     'description' => __( 'Enable this shipping.', 'rentopian-sync' ),
//                     'default' => 'no'
//                 ),
//                 'title' => array(
//                     'title' => __( 'Title', 'rentopian-sync' ),
//                     'type' => 'text',
//                     'description' => __( 'Title for the shipping field on the website', 'rentopian-sync'),
//                     'default' => __( $shipping_label, 'rentopian-sync'),
//                     'custom_attributes' => array( 'readonly' => 'readonly'),
//                 ),
//                 'first_miles' => array(
//                     'title'     => __( 'First ' . $shipping_title, 'woocommere' ),
//                     'desc_tip' => __( 'The first few '.$shipping_title.' for which rate will be different', 'woocommere'),
//                     'id'       => 'first_miles',
//                     'type'     => 'text',
//                     'css'      => 'min-width:300px;',
//                     'default' => 10,
//                     'validate'=> 'validate-number',
//                     'custom_attributes' => array( 'readonly' => 'readonly' ),
//                 ),
//                 'first_miles_rate' => array(
//                     'title'     => __( 'Rate for First ' . $shipping_title , 'woocommere' ),
//                     'desc_tip' => __( 'The rate for first few ' . $shipping_title , 'woocommere' ),
//                     'id'       => 'first_miles_rate',
//                     'type'     => 'text',
//                     'css'      => 'min-width:300px;',
//                     'default' => 150,
//                     'validate'=> 'validate-number',
//                     'custom_attributes' => array( 'readonly' => 'readonly' ),
//                 ),
//                 'per_mile_rate' => array(
//                     'title'     => __( 'Per '.$shipping_title.' Rate', 'woocommere' ),
//                     'desc_tip' => __( 'The rate for each extra ' . $shipping_title . ' after first ' . $shipping_title , 'woocommere' ),
//                     'id'       => 'per_mile_rate',
//                     'type'     => 'text',
//                     'css'      => 'min-width:300px;',
//                     'default' => 50,
//                     'validate'=> 'validate-number',
//                     'custom_attributes' => array( 'readonly' => 'readonly' ),
//                 )
//             );
//         }

//         /**
//          * This function is used to calculate the shipping cost. Within this function we can check for weights, dimensions and other parameters.
//          *
//          * @access public
//          * @param mixed $package
//          * @return void
//          */
//         public function calculate_shipping($package = [], $return_rate = false) {
//             if ($this->check_free_shipping_amount()) {
//                 $custom_shipping_label_opt = get_option('rental_shipping_text') ;
//                 $custom_shipping_label = ($custom_shipping_label_opt == '') ? __('Shipping', 'rentopian-sync') : $custom_shipping_label_opt;

//                 // add Free Shipping rate
//                 $this->add_rate([
//                     'id' => 'free_shipping',
//                     'label' => __( 'Free '.$custom_shipping_label, 'rentopian-sync' ),
//                     'cost' => 0,
//                     'package' => $package,
//                 ]);

//                 return;
//             }

//             // We will add the cost, rate and logics in here
//             $distance = 0;
//             $cost = 0;

//             //get settigns from options table
//             $rental_divisions = get_option('rental_divisions');
//             $google_api_key = get_option('rental_google_distance_key');
//             $shipping_settings = get_option('rental_shipping_settings');

//             if( isset($rental_divisions) && $rental_divisions){
                
//                 if (get_option('rental_destination_address_parts') !== false) {
//                     delete_option('rental_destination_address_parts');
//                 }
                
//                 if (get_option('rental_store_address_parts') !== false) {
//                     delete_option('rental_store_address_parts');
//                 }

//                 if (get_option('rental_destination_store_address_error') !== false) {
//                     delete_option('rental_destination_store_address_error');
//                 }

//                 $store =  $this->get_store_address($rental_divisions);
//                 $destination = $this->get_destination_address();

//                 $rental_destination_address_parts = get_option('rental_destination_address_parts', []);
//                 $rental_store_address_parts = get_option('rental_store_address_parts', []);
//                 // $rental_destination_store_address_error = get_option('rental_destination_store_address_error', []);

//                 if ($rental_destination_address_parts && $rental_store_address_parts) {

// 					update_option('rental_destination_store_address_error', false);

//                     if (
//                         $rental_destination_address_parts['address_1'] && $rental_store_address_parts['address_1'] && ($rental_destination_address_parts['address_1'] == $rental_store_address_parts['address_1'])
//                         && $rental_destination_address_parts['city'] && $rental_store_address_parts['city'] && ($rental_destination_address_parts['city'] == $rental_store_address_parts['city'])
//                         && $rental_destination_address_parts['state'] && $rental_store_address_parts['state'] && ($rental_destination_address_parts['state'] == $rental_store_address_parts['state'])
//                         && $rental_destination_address_parts['country'] && $rental_store_address_parts['country'] && ($rental_destination_address_parts['country'] == $rental_store_address_parts['country'])
//                         && $rental_destination_address_parts['zip'] && $rental_store_address_parts['zip'] && ($rental_destination_address_parts['zip'] == $rental_store_address_parts['zip'])
//                     ) {
                        
//                         update_option('rental_destination_store_address_error', true);
// 						return NULL;
//                     }
//                 }

//                 if ( isset($google_api_key) && $google_api_key && $destination && $store ) {

//                     $min_order_amount = get_option('rental_min_order_amount');

//                     if ( !$min_order_amount || WC()->cart->get_subtotal() >= $min_order_amount) {
//                         $distance = $this->get_shipping_distance($store, $destination, $google_api_key, $shipping_settings);
//                         // display error message if exists and if we are in checkout page
//                         if (!is_numeric($distance) && !empty($distance) && is_checkout()) {
//                             echo $distance; 
//                         }
//                     }

//                     if ($min_order_amount && WC()->cart->get_subtotal() < $min_order_amount && get_option('rental_min_order_pickup') && get_option('rental_pickup_delivery') === 'company_client_delivery_return') {
//                         $distance = $this->get_shipping_distance($store, $destination, $google_api_key, $shipping_settings);
//                         // display error message if exists and if we are in checkout page
//                         if (!is_numeric($distance) && !empty($distance) && is_checkout()) {
//                             echo $distance; 
//                         }
//                     }
//                 }

//                 if (empty($distance)) {
//                     return NULL;
//                 }
                
//                 $first_miles = floatval($this->settings['first_miles']);
//                 $first_miles_rate = floatval($this->settings['first_miles_rate']);
//                 $per_mile_rate = floatval($this->settings['per_mile_rate']);

                
//                 if( $distance <= floor($first_miles) ) {
//                     $cost = $first_miles_rate;
//                 } else {
//                     $extra_miles = floatval($distance)-floatval($first_miles);
//                     $extra_miles_cost = floatval($extra_miles*$per_mile_rate);
//                     $cost = $extra_miles_cost + $first_miles_rate;
//                 }

//                 if (get_option('rental_double_shipping_fee')) {

//                     $encrypted_rental_pickup_cost = encrypt_data($cost, get_option('rental_encryption_key'));
//                     $_COOKIE['rental_pickup_cost'] = $encrypted_rental_pickup_cost;
//                     setcookie('rental_pickup_cost', $encrypted_rental_pickup_cost, time() + (3600), "/", "", false, true); // 1 hour expiration

//                     $cost *= 2;
//                 }

//                 $shipping_by = $shipping_settings && $shipping_settings->shipping_by ? $shipping_settings->shipping_by : 'mile';
//                 $distance_unit_short = $shipping_by === 'kilometre' ? 'KM' : 'MI';

//                 $shipping_label_distance = $this->title ." (".$distance." ".$distance_unit_short.")";
//                 if ($rental_miles_shipping_label_text = get_option('rental_miles_shipping_label_text', '')) {
//                     $shipping_label_distance = $rental_miles_shipping_label_text ." (".$distance." ".$distance_unit_short.")";
//                 }

//                 $rate = array(
//                     'id' => $this->id,
//                     // 'label' => $this->title ." (".$distance." ".$distance_unit_short.")",
//                     'label' => $shipping_label_distance,
//                     'cost' => $cost,
//                     'package' => $package,
//                     // 'taxes' => ['shipping_tax'=>$tax],
//                     'calc_tax' => 'per_order'
//                 );

              
//                 // add the rate
//                 $this->add_rate( $rate );


//                 if ($return_rate){
//                     return $rate;
//                 }
//             }

//         }
//         /**
//          * Shipping distance
//          */
//         public function get_shipping_distance($origin, $destination, $google_api_key, $shipping_settings) {

//             $origin = urlencode($origin);
//             $destination = urlencode($destination);


//             $shipping_by = $shipping_settings && $shipping_settings->shipping_by ? $shipping_settings->shipping_by : 'mile';
//             $distance_unit = $shipping_by === 'kilometre' ? 'metrics' : 'imperial';

//             $data = file_get_contents('https://maps.googleapis.com/maps/api/distancematrix/json?units='.$distance_unit.'&origins=' . $origin . '&destinations=' . $destination . '&key=' . $google_api_key);
//             $data = json_decode($data);

//             if ($data->status === 'OK') {
//                 $elements = $data->rows[0]->elements;
//                 if (isset($elements[0]) && $elements[0]->status === 'OK') {
                    
//                     // $distance_in_meter  = (float) str_replace(",", "", $elements[0]->distance->value); // value in meter
//                     // since value is reliable, because returning value in meter all time. we just need to convert it into mile
//                     // miles = meters × 0.000621
//                     // $distance_in_miles = ceil($distance_in_meter * 0.00062137);
//                     // return $distance_in_miles;

//                     return floatval(str_replace(",", "", $elements[0]->distance->text)); // text showing foot or miles type casted to float to use in calculations

//                 } else {
//                     if (true === WP_DEBUG) {
//                         error_log("distance matrix api: nothing happened");
//                     }
//                     print_r( "nothing happened" );
//                 }
//             } else {
                
//                 if (isset($data->error_message) && !empty($data->error_message)) {
//                     $msg = '<div class="woocommerce-notices-wrapper">';
//                     $msg .= '<ul class="woocommerce-error" role="alert">';
//                     $msg .= '<li>'. $data->error_message .'</li>';
//                     $msg .= '</ul></div>';
//                     return $msg;
//                 }

//                 // error_log("distance matrix api: error happened");
//                 // if (true === WP_DEBUG) {
//                     // if (is_array($data) || is_object($data)) {
//                     //     error_log(print_r($data, true));
//                     // } else {
//                     //     error_log($data);
//                     // }
//                 // }
//                 // print_r( $data );
//                 // print_r( "error happened" );
//             }
//             return null;
//         }
//         /**
//          * destination address
//          */
//         public function get_destination_address() {
//             global $woocommerce;

//             update_option('rental_destination_address_parts', []);

//             if (
//                 isset($_COOKIE['rental_client_address_lat'])
//                 && isset($_COOKIE['rental_client_address_lng'])
//                 && $_COOKIE['rental_client_address_lat']
//                 && $_COOKIE['rental_client_address_lng']
//             ) {
//                 $destination = $_COOKIE['rental_client_address_lat'].','.$_COOKIE['rental_client_address_lng'];
//                 return $destination;
//             }
                

//             $customer = $woocommerce->customer;
//             $address_1 = $customer->get_shipping_address_1();
//             $address_2 = $customer->get_shipping_address_2();
//             $city = $customer->get_shipping_city();
//             $country = $customer->get_shipping_country();
//             $zip = $customer->get_shipping_postcode();
//             if ( !$address_1 || !$city || !$country) {
//                 return '';
//             }


//             $rental_destination_address_parts_opt = [
//                 'address_1' => $address_1,
//                 'city' => $city,
//                 'country' => $country,
//                 'zip' => '',
//                 'address_2' => '',
//                 'state' => ''
//             ];

//             $destination = $address_1;
//             if ($address_2) {
//                 $destination .= '+' . $address_2;
//                 $rental_destination_address_parts_opt['address_2'] = $address_2;
//             }

//             $destination .= '+' . $city;
//             if ($state = $customer->get_shipping_state()) {
//                 $destination .= '+' . $state;
//                 $rental_destination_address_parts_opt['state'] = $state;
//             }

//             if ($zip) {
//                 $destination .= '+' . $zip;
//                 $rental_destination_address_parts_opt['zip'] = $zip;
//             }
//             $destination .= '+' . $country;

//             update_option('rental_destination_address_parts', $rental_destination_address_parts_opt);

//             return str_replace(" ", "+", $destination);
//         }

//         public function set_destination_address($shipping_address_1, $shipping_state, $shipping_city, $shipping_country, $shipping_postcode, $address_2 = '') {
//             global $woocommerce;
//             $customer = $woocommerce->customer;

//             $customer->set_shipping_address_1($shipping_address_1);
//             $customer->set_shipping_address_2($address_2);
//             $customer->set_shipping_city($shipping_city);
//             $customer->set_shipping_state($shipping_state);
//             $customer->set_shipping_country($shipping_country);
//             $customer->set_shipping_postcode($shipping_postcode);

//             $customer->save();
//         }

//         /**
//          * Shop Address
//          */
//         public function get_store_address($rental_divisions){
//             // $rental_divisions =  get_option( 'rental_divisions' );
//             //check if rental division is only one then just send its address
//             if( count($rental_divisions) < 2 ){
//                 $shipping_rental_divison = $rental_divisions[0];
//             }
//             //else find selected division or use main division
//             else{
//                 // if rental select division option is enabled and divion set in session
//                 // if(get_option('rental_select_division') && (isset($_COOKIE['rental_division_id']) && !empty($_COOKIE['rental_division_id'])) ){
//                 if(get_option('rental_select_division') && (isset($_COOKIE['rental_division_id']) && !empty($_COOKIE['rental_division_id'])) ){
//                     // $selected_division = $_COOKIE['rental_division_id'];
//                     $selected_division = $_COOKIE['rental_division_id'];

//                     $shipping_rental_divison = '';
//                     foreach( $rental_divisions as $division ){
//                         if($division->id == $selected_division){
//                             $shipping_rental_divison = $division;
//                             break;
//                         }
//                     }
//                 }
//                 //retrieve main division form options
//                 else{
//                     $shipping_rental_divison = '';
//                     foreach( $rental_divisions as $division ){
//                         if($division->main_division == 1){
//                             $shipping_rental_divison = $division;
//                             break;
//                         }
//                     }
//                 }

//             }

//             // get divions address object
//             $store_divison = $shipping_rental_divison->address;

//             update_option('rental_store_address_parts', []);

//             if ($store_divison->longitude && $store_divison->latitude) {

//                 $destination = $store_divison->latitude.','.$store_divison->longitude;
//                 return $destination;
//             }

//             // assign each variable value of division's address,city,state,country
//             $store_address     = $store_divison->address;
//             $store_address_2   = $store_divison->address_2;
//             $store_city        = $store_divison->city;
//             $store_state   = $store_divison->state;
//             $store_country = $store_divison->country;
//             $store_postcode    = $store_divison->zip;


//             update_option('rental_store_address_parts', [
//                 'address_1' => $store_address,
//                 'address_2' => $store_address_2,
//                 'city' => $store_city,
//                 'state' => $store_state,
//                 'country' => $store_country,
//                 'zip' => $store_postcode
//             ]);

//             $destination = $store_address;
//             if ($store_address_2) {
//                 $destination .= '+' . $store_address_2;
//             }
//             $destination .= '+' . $store_city;
//             if ($store_state) {
//                 $destination .= '+' . $store_state;
//             }
//             if ($store_postcode) {
//                 $destination .= '+' . $store_postcode;
//             }
//             if ($store_country) {
//                 $destination .= '+' . $store_country;
//             }

//             return str_replace(" ", "+", $destination);
//         }
//         /**
//          * Add other rental fees
//          */
//         public function rental_shipping_tax_fees(){

//             $product_settings = get_option('rental_product_settings');
            
//             $shipping = $product_settings['shipping'];
//             $shipping_tax = $shipping['tax_rate'];

//             return $shipping_tax;
//         }

//         /**
//          * Check if the free shipping amount is met
//          *
//          * @return bool
//          */
//         private function check_free_shipping_amount() {
//             global $woocommerce;

//             $free_shipping_amount = get_option('rental_free_shipping_amount');

//             return $free_shipping_amount > 0 && $woocommerce->cart->get_subtotal() >= $free_shipping_amount;
//         }
//     }
// }

// $woocommerce_miles_based_settings = get_option('woocommerce_miles_based_settings');
// if ($woocommerce_miles_based_settings && $woocommerce_miles_based_settings['enabled'] === 'yes') {
//     add_action( 'woocommerce_shipping_init', 'miles_based_shipping_method' );
//     function add_miles_based_shipping_method( $methods ) {
//         $methods[] = 'Miles_Based_Shipping_Method';
//         return $methods;
//     }
//     add_filter( 'woocommerce_shipping_methods', 'add_miles_based_shipping_method' );
// }