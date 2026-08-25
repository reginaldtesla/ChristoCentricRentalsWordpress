<?php

// function reduced_rate_shipping_method() {

//     if (class_exists('WC_Reduced_Rate_Shipping_Method')) {
//         return;
//     }

//     /**
//      * WC_Reduced_Rate_Shipping_Method class.
//      * Add Miles based shipping
//      * @version		1.0.0
//      * @author 		Nishant Shaligram
//      */
//     class WC_Reduced_Rate_Shipping_Method extends WC_Shipping_Method {

//         /**
//          * @var float
//          */
//         public $min_order_price;

//         /**
//          * @var float
//          */
//         public $shipping_rate;

//         /**
//          * @var float
//          */
//         public $regular_shipping_rate;

//         /**
//          * Constructor. The instance ID is passed to this.
//          */
//         public function __construct( $instance_id = 0 ) {
//             $this->id                    = 'reduced_rate';
//             $this->title                 = 'Flat Rate';
//             $this->instance_id           = absint( $instance_id );
//             $this->method_title          = __('Flat rate', 'rentopian-sync');
//             $this->method_description    = __('Lets you charge a fixed rate for shipping.', 'rentopian-sync');
//             $this->supports              = array(
//                 'shipping-zones',
//                 'instance-settings',
//                 'instance-settings-modal',
//             );

//             $this->init();

//             $this->min_order_price = (float) $this->get_option('min_order_price', 0);
//             $this->shipping_rate = (float) $this->get_option('shipping_rate', 0);
//             $this->regular_shipping_rate = (float) $this->get_option('regular_shipping_rate', 0);

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
// //                    add_action('woocommerce_cart_calculate_fees', array( $this,'rental_calculate_cart_fees' ) );
//         }

//         /**
//          * Define settings field for this shipping
//          * @return void
//          */
//         function init_form_fields() {

//             // We will add our settings here
//             $this->instance_form_fields = array(
//                 'min_order_price' => array(
//                     'title'     => __( 'Minimum Order Price', 'rentopian-sync' ),
//                     'desc_tip' => __( 'Minimum order price for shipping rate', 'rentopian-sync' ),
//                     'id'       => 'min_order_price',
//                     'type'     => 'text',
//                     'css'      => 'min-width:300px;',
//                     'default' => 0,
//                     'custom_attributes' => array( 'required' => 'required'),

//                 ),
//                 'shipping_rate' => array(
//                     'title'     => __( 'Shipping Rate', 'rentopian-sync' ),
//                     'desc_tip' => __( 'The rate for first few miles', 'rentopian-sync' ),
//                     'id'       => 'shipping_rate',
//                     'type'     => 'text',
//                     'css'      => 'min-width:300px;',
//                     'default' => 0,
//                     'custom_attributes' => array( 'required' => 'required'),

//                 ),
//                 'regular_shipping_rate' => array(
//                     'title'     => __( 'Regular Shipping Rate', 'rentopian-sync' ),
//                     //'desc_tip' => __( 'Regular shipping rate' ),
//                     'id'       => 'regular_shipping_rate',
//                     'type'     => 'text',
//                     'css'      => 'min-width:300px;',
//                     'default' => 0,
//                     'custom_attributes' => array( 'required' => 'required'),

//                 )
//             );
//         }

//         /**
//          * calculate_shipping function.
//          *
//          * @access public
//          * @param mixed $package
//          * @return void
//          */
//         public function calculate_shipping($package = []) {
//             $custom_shipping_label_opt = get_option('rental_shipping_text') ;
//             $custom_shipping_label = ($custom_shipping_label_opt == '') ? __('Free Shipping', 'rentopian-sync') :  'Free '.$custom_shipping_label_opt;

//             if ($this->check_free_shipping_amount()) {
//                 // add Free Shipping rate
//                 $this->add_rate([
//                     'id' => 'free_shipping',
//                     'label' => $custom_shipping_label,
//                     'cost' => 0,
//                     'package' => $package,
//                 ]);
//                 return;
//             }

//             $cost = 0;
//             if ($this->min_order_price && WC()->cart->get_subtotal() >= $this->min_order_price) {
//                 $cost = $this->shipping_rate;
//             } else {
//                 $cost = $this->regular_shipping_rate;
//             }

//             if (get_option('rental_double_shipping_fee')) {
//                 // $_SESSION['rental_pickup_cost'] = $cost;

//                 $encrypted_rental_pickup_cost = encrypt_data($cost, get_option('rental_encryption_key'));
//                 $_COOKIE['rental_pickup_cost'] = $encrypted_rental_pickup_cost;
//                 setcookie('rental_pickup_cost', $encrypted_rental_pickup_cost, time() + (3600), "/", "", false, true); // 1 hour expiration

//                 $cost *= 2;
//             }

// //                    $tax_rate = $this->rental_shipping_tax_fees();
// //                    $tax = ( $cost * $tax_rate )/100;

//             $rate = array(
//                 'id'       => $this->id,
//                 'label'    => $this->title,
//                 'cost'     => $cost,
//                 'package' => $package,
// //                        'taxes' => ['shipping_tax'=>$tax],
//                 'calc_tax' => 'per_order'
//             );

//             // add the rate
//             $this->add_rate( $rate );
//         }


//         function validate_min_order_price_field($key,$value){
//             if (preg_match('/^[0-9]+(\.[0-9]{1,2})?$/', $value))
//             {
//                 return $value;
//             } else {
//                 $this->add_error( __( 'Enter Valid Price Amount', 'rentopian-sync' ) );
//             }
//         }
//         function validate_shipping_rate_field($key,$value){
//             if (preg_match('/^[0-9]+(\.[0-9]{1,2})?$/', $value))
//             {
//                 return $value;
//             } else {
//                 $this->add_error( __( 'Enter Valid Shiping Rate Amount', 'rentopian-sync' ) );
//             }
//         }
//         function validate_regular_shipping_rate_field($key,$value){
//             if (preg_match('/^[0-9]+(\.[0-9]{1,2})?$/', $value))
//             {
//                 return $value;
//             } else {
//                 $this->add_error( __( 'Enter Regular Shipping Rate Amount', 'rentopian-sync' ) );
//             }
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

// add_action( 'woocommerce_shipping_init', 'reduced_rate_shipping_method' );

// function add_reduced_rate_shipping_method( $methods ) {
//     $methods['reduced_rate'] = 'WC_Reduced_Rate_Shipping_Method';
//     return $methods;
// }

// add_filter( 'woocommerce_shipping_methods', 'add_reduced_rate_shipping_method' );