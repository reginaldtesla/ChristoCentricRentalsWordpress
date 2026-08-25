<?php

function miles_based_shipping_method() {

    if (class_exists('Miles_Based_Shipping_Method')) {
        return;
    }

    /**
     * Miles_Based_Shipping_Method class.
     * Add Miles based shipping
     * @version		1.0.0
     * @author 		Nishant Shaligram
     */
    class Miles_Based_Shipping_Method extends WC_Shipping_Method {
        /**
         * Constructor for your shipping class
         *
         * @access public
         * @return void
         */

        public $id;
        public $method_title;
        public $method_description;
        public $enabled;
        public $title;
        public $first_miles;
        public $first_miles_rate;
        public $per_mile_rate;

        public function __construct() {
            $this->id                 = 'miles_based';

            // $shipping_settings = get_option('rental_shipping_settings');
            $delivery_settings = rental_get_delivery_settings();
            $shipping_title = $delivery_settings && isset($delivery_settings['shipping_by_title']) && $delivery_settings['shipping_by_title'] ? $delivery_settings['shipping_by_title'] : 'Miles';
            $shipping_label = $shipping_title . ' Based Shipping';
            // if ($rental_miles_shipping_label_text = get_option('rental_miles_shipping_label_text', '')) {
            //     $shipping_label = $rental_miles_shipping_label_text;
            // }

            $this->method_title       = __( $shipping_label, 'rentopian-sync' );
            $this->method_description = __( 'Custom Shipping Method for '.$shipping_title.' Based', 'rentopian-sync' );
            
            $this->init();

            $this->enabled = isset( $this->settings['enabled'] ) ? $this->settings['enabled'] : 'yes';
            $this->title = isset( $this->settings['title'] ) ? $this->settings['title'] : __( $shipping_label, 'rentopian-sync' );
            $this->first_miles = isset( $this->settings['first_miles'] ) ? $this->settings['first_miles'] : 0;
            $this->first_miles_rate = isset( $this->settings['first_miles_rate'] ) ? $this->settings['first_miles_rate'] : 0;
            $this->per_mile_rate = isset( $this->settings['per_mile_rate'] ) ? $this->settings['per_mile_rate'] : 0;
        }

        /**
         * Init your settings
         *
         * @access public
         * @return void
         */
        function init() {
            // Load the settings API
            $this->init_form_fields();
            $this->init_settings();

            // Save settings in admin if you have any defined
            add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
        }

        /**
         * Define settings field for this shipping
         * @return void
         */
        function init_form_fields() {

            // $shipping_settings = get_option('rental_shipping_settings');
            $delivery_settings = rental_get_delivery_settings();
            $shipping_title = $delivery_settings && isset($delivery_settings['shipping_by_title']) && $delivery_settings['shipping_by_title'] ? $delivery_settings['shipping_by_title'] : 'Miles';

            $shipping_label = $shipping_title . ' Based Shipping';
            if ($rental_miles_shipping_label_text = get_option('rental_miles_shipping_label_text', '')) {
                $shipping_label = $rental_miles_shipping_label_text;
            }

            // We will add our settings here
            $this->form_fields = array(
                'enabled' => array(
                    'title' => __( 'Enable', 'rentopian-sync' ),
                    'type' => 'checkbox',
                    'description' => __( 'Enable this shipping.', 'rentopian-sync' ),
                    'default' => 'no'
                ),
                'title' => array(
                    'title' => __( 'Title', 'rentopian-sync' ),
                    'type' => 'text',
                    'description' => __( 'Title for the shipping field on the website', 'rentopian-sync'),
                    'default' => __( $shipping_label, 'rentopian-sync'),
                    'custom_attributes' => array( 'readonly' => 'readonly'),
                ),
            );
        }

        /**
         * This function is used to calculate the shipping cost. Within this function we can check for weights, dimensions and other parameters.
         *
         * @access public
         * @param mixed $package
         * @return void
         */
        public function calculate_shipping($package = [], $return_rate = false) {
            $distance = 0;
            $distance_pickup = 0;
            $cost = 0;
            $cost_delivery = 0;
            $cost_delivery_original = 0;
            $cost_pickup = 0;
            $cost_pickup_original = 0;
            $additional_charge_delivery = 0;
            $additional_charge_pickup = 0;

            $shipping_calc_failed = false;

            $is_order_review_ajax = defined( 'DOING_AJAX' ) && DOING_AJAX
                && isset( $_GET['wc-ajax'] ) && $_GET['wc-ajax'] === 'update_order_review';
                
            $outside_zone_notice_added = false;

            $add_outside_zone_notice   = function() use ( $is_order_review_ajax, &$outside_zone_notice_added ) {

                if ( $outside_zone_notice_added || $is_order_review_ajax ) {
                    return;
                }

                // Only show the notice on the checkout page itself
                if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
                    return;
                }

                $outside_zone_notice_added = true;

                wc_add_notice( __( 'Please contact us for a quote since you are outside of our delivery zone', 'rentopian-sync' ), 'notice' );
            };

            $rental_product_subtotal = get_rental_session_data('rental_product_subtotal', 0);
            $delivery_settings = rental_get_delivery_settings();

            $delivery_address = $this->get_destination_delivery_address();

            /**
             * if there is no address, still add a placeholder rate
             */
            if ( empty($delivery_address['city']) || empty($delivery_address['state']) || empty($delivery_address['address']) ) {

                $shipping_label_distance = $this->title;
                if ($rental_miles_shipping_label_text = get_option('rental_miles_shipping_label_text', '')) {
                    $shipping_label_distance = $rental_miles_shipping_label_text;
                }

                // Append a hint so customers understand why it's 0 / disabled
                $shipping_label_distance .= ' — ' . __('please enter your address to calculate price', 'rentopian-sync');

                $rate = array(
                    'id'      => $this->id,        // 'miles_based'
                    'label'   => $shipping_label_distance,
                    'cost'    => 0,
                    'package' => $package,
                    'meta_data' => array(
                        'needs_address' => true,
                    ),
                );

                $this->add_rate($rate);

                if ($return_rate) {
                    return $rate;
                }

                // Don’t try to call the API without address
                return;
            }

            $params = [];
            if ($delivery_address['city'] && $delivery_address['state'] && $delivery_address['address']) {
                
                $params['address'] = json_encode($delivery_address);

                $params['address_pickup'] = json_encode([]);
                if (
                    isset($_COOKIE['rental_different_pick_up_address']) 
                    && $_COOKIE['rental_different_pick_up_address'] == 1
                    && $delivery_settings['enable_different_pickup_delivery_address_for_website']
                ) {
                    $params['address_pickup'] = json_encode($this->get_destination_pickup_address());
                }
                

                $rental_start_date = "";
                if ( isset($_COOKIE['rental_start_date']) ) {
                    $rental_start_date = decrypt_data($_COOKIE['rental_start_date'], get_option('rental_encryption_key'));
                }

                $rental_end_date = "";
                if ( isset($_COOKIE['rental_end_date']) ) {
                    $rental_end_date = decrypt_data($_COOKIE['rental_end_date'], get_option('rental_encryption_key'));
                }

                $products_subtotal_for_one_day = 0;
                $products_subtotal_raw_for_one_day = 0;
                foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {

                    $product_id = $cart_item['variation_id'] ? $cart_item['variation_id'] : $cart_item['product_id'];
                    
                    if ($rental_product_price_total_opt = get_rental_session_data('rental_product_price_total', [])) {

                        foreach($rental_product_price_total_opt as $product_id_as_key => $rental_product_price_total_item) {

                            if (
                                $product_id_as_key == $product_id
                            ) {
                                
                                $price = format_value_to_fixed_precision(rental_calculate_cart_item_price($cart_item), 2);
                                $subtotal = (int) $cart_item["quantity"] * $price;

                                $products_subtotal_for_one_day += $subtotal;
                            }

                        }
                    }

                    // product subtotal raw calc
                    $product_subtotal_raw = (float) get_post_meta($product_id, '_price', true);
                    $quantity = (int) $cart_item['quantity'];

                    // if (!isset($cart_item["rental_add_on_of"])) {
                        $products_subtotal_raw_for_one_day += $product_subtotal_raw * $quantity;
                    // }
                    
                }


                if ($products_subtotal_for_one_day) {

                    $days = get_days_from_rental_dates($rental_start_date, $rental_end_date);
                    $products_subtotal_for_one_day = (float) $products_subtotal_for_one_day / $days;
                }

                $params['product_subtotal'] = !empty($rental_product_subtotal) ? $rental_product_subtotal : WC()->cart->get_subtotal(); 
                $params['product_raw_subtotal_for_1_day'] = $products_subtotal_raw_for_one_day; 
                $params['product_subtotal_for_1_day'] = $products_subtotal_for_one_day; 

                $params['delivery_start_time'] = '';
                $params['delivery_day'] = '';
                $params['pickup_start_time'] = '';
                $params['pickup_day'] = '';
                if ($delivery_times_data = $this->get_shipping_times_days()) {
                    $params['delivery_time_id'] = !empty($delivery_times_data['delivery_time_id']) ? $delivery_times_data['delivery_time_id'] : 0;
                    $params['delivery_start_time'] = !empty($delivery_times_data['delivery_start_time']) ? $delivery_times_data['delivery_start_time'] : '';
                    $params['delivery_end_time'] = !empty($delivery_times_data['delivery_end_time']) ? $delivery_times_data['delivery_end_time'] : '';
                    $params['delivery_day'] = !empty($delivery_times_data['delivery_day']) ? $delivery_times_data['delivery_day'] : -1;

                    $params['pickup_time_id'] = !empty($delivery_times_data['pickup_time_id']) ? $delivery_times_data['pickup_time_id'] : 0;
                    $params['pickup_start_time'] = !empty($delivery_times_data['pickup_start_time']) ? $delivery_times_data['pickup_start_time'] : '';
                    $params['pickup_end_time'] = !empty($delivery_times_data['pickup_end_time']) ? $delivery_times_data['pickup_end_time'] : '';
                    $params['pickup_day'] = !empty($delivery_times_data['pickup_day']) ? $delivery_times_data['pickup_day'] : -1;
                }


                
                /*
                * Sample response for miles based delivery
                * 
                * 
                    $response =[
                        "delivery_calc_data" => [
                            "status" => 1,
                            "distance" => 317,
                            "price" => 285.3,
                        ],
                        "additional_charge" => 5.88,
                        "venue_cost" => ,
                        "venue_cost_type" => ,
                        "delivery_exact_pickup_cost" => 20,
                        "settings" => [ ... ]
                    ]; 

                * Sample response for zone based delivery
                * 
                * 
                    $response =[
                        "delivery_calc_data" => [
                            "min_order_price" => "500",
                            "shipping_rate" => "15",
                            "regular_shipping_rate" => "35",
                        ],
                        "additional_charge" => 5.88,
                        "venue_cost" => ,
                        "venue_cost_type" => ,
                        "delivery_exact_pickup_cost" => 20,
                        "settings" => [ ... ]
                    ]; 
                */
                $response = json_decode(rental_curl('shipping/calculate-shipping-cost', get_option('rental_api_key'), false, $params), true);
                // echo "<pre>"; print_r($response); echo "</pre>";

                $this->reset_delivery_params_data();

                if ($this->check_free_shipping_amount($response['settings'] ?? '')) {
                    $custom_shipping_label_opt = get_option('rental_shipping_text') ;
                    $custom_shipping_label = ($custom_shipping_label_opt == '') ? __('Shipping', 'rentopian-sync') : $custom_shipping_label_opt;

                    // make delivery cost 0 on free shipping
                    set_rental_session_data('rental_delivery_cost', 0);
                    set_rental_session_data('rental_pickup_cost', 0);

                    // add Free Shipping rate
                    $this->add_rate([
                        'id' => 'free_shipping',
                        'label' => __( 'Free '.$custom_shipping_label, 'rentopian-sync' ),
                        'cost' => 0,
                        'package' => $package,
                    ]);

                    return;
                }

                set_rental_session_data('rental_shipping_has_error', 0);
                $is_zone_based_delivery_pickup = $response['is_zone_based_delivery'] ?? false;
                if ($response) {

                    if (empty($response['delivery']['delivery_calc_data']) && $is_zone_based_delivery_pickup) {

                        set_rental_session_data('rental_shipping_has_error', 1);
                        
                        ErrorHandler::registerErrorInLog($response['delivery']['delivery_calc_data']['message'] ?? 'Error on calculating the delivery cost!', "class-miles-based-shipping.php", "255", RentalException::TYPE_RUNTIME, null, "500");

                        set_rental_session_data('rental_delivery_cost', 0);
                        set_rental_session_data('rental_pickup_cost', 0);

                        set_rental_session_data('rental_delivery_original_cost', 0);
                        set_rental_session_data('rental_pickup_original_cost', 0);

                        $shipping_calc_failed = true;

                        // return;
                    }

                    if (isset($response['delivery']['delivery_calc_data'])) {

                        // zone based calc
                        $is_zone_based_delivery = false;
                        if (isset($response['delivery']['delivery_calc_data']['regular_shipping_rate'])) {

                            $cost_delivery = format_value_to_fixed_precision($response['delivery']['delivery_calc_data']['regular_shipping_rate'], 2);

                            if ( !isEmptyPrice($response['delivery']['delivery_calc_data']['min_order_price']) && ($rental_product_subtotal >= $response['delivery']['delivery_calc_data']['min_order_price']) ) {

                                $cost_delivery = format_value_to_fixed_precision($response['delivery']['delivery_calc_data']['shipping_rate'], 2);
                            }

                            $cost_delivery_original = $cost_delivery;

                            $is_zone_based_delivery = true;
                        } 
                        

                        if (isset($response['delivery']['delivery_calc_data']['status']) && empty($response['delivery']['delivery_calc_data']['status'])) {
        
                            set_rental_session_data('rental_shipping_has_error', 1);

                            ErrorHandler::registerErrorInLog($response['delivery']['delivery_calc_data']['message'] ?? 'Error on calculating the delivery cost!', "class-miles-based-shipping.php", "266", RentalException::TYPE_RUNTIME, null, "500");
        
                            set_rental_session_data('rental_delivery_cost', 0);
                            set_rental_session_data('rental_pickup_cost', 0);

                            set_rental_session_data('rental_delivery_original_cost', 0);
                            set_rental_session_data('rental_pickup_original_cost', 0);


                            $shipping_calc_failed = true;

                            // return;
                        }

                        // Venue matched based calc
                        $delivery_venue_cost_is_replaced = 0;
                        $added_delivery_venue_cost = 0;
                        if ( !isEmptyPrice($response['delivery']['venue_cost']) ) {

                            if (isset($response['delivery']['venue_address_id']) && $response['delivery']['venue_address_id']) {
                                set_rental_session_data('rental_delivery_venue_address_id', $response['delivery']['venue_address_id']);
                            } else {
                                set_rental_session_data('rental_delivery_venue_address_id', 0);
                            }

                            if ($response['delivery']['venue_cost_type'] === 'REPLACE') {
                                // fully replace venue cost
                                $delivery_venue_cost_is_replaced = 1;

                                $cost_delivery = format_value_to_fixed_precision($response['delivery']['venue_cost'], 2);

                                $cost_delivery_original = $cost_delivery;

                            } else if ($response['delivery']['venue_cost_type'] === 'ADD') {
                                // incrementally add the venue cost to delivery cost and other costs

                                $added_delivery_venue_cost = format_value_to_fixed_precision($response['delivery']['venue_cost'], 2);
                            }
                        }


                        // calculating normal delivery cost if not zone based and no venue matched
                        if (!$delivery_venue_cost_is_replaced && !$is_zone_based_delivery) {
                            $cost_delivery = isset($response['delivery']['delivery_calc_data']['price']) ? format_value_to_fixed_precision($response['delivery']['delivery_calc_data']['price'], 2) : 0;

                            $cost_delivery_original = $cost_delivery;
                        }

                        // incrementally add the venue cost to delivery cost and other costs
                        if ($added_delivery_venue_cost) {
                            $cost_delivery += $added_delivery_venue_cost;
                        }

                        if (!$delivery_venue_cost_is_replaced && $additional_charge_delivery = isset($response['delivery']['additional_charge']) && $response['delivery']['additional_charge'] ? $response['delivery']['additional_charge'] : 0) {
                            $cost_delivery += $additional_charge_delivery;
                        }

                        if (isset($response['delivery']['delivery_time_based_fee']) && !isEmptyPrice($response['delivery']['delivery_time_based_fee'])) {

                            set_rental_session_data('delivery_time_based_fee', $response['delivery']['delivery_time_based_fee']);

                            $cost_delivery += $response['delivery']['delivery_time_based_fee'];
                        }
                        
                        
                        $distance = isset($response['delivery']['delivery_calc_data']['distance']) ? $response['delivery']['delivery_calc_data']['distance'] : 0;
                    }


                    set_rental_session_data('charge_only_delivery_for_website', $response['settings']['charge_only_delivery_for_website']);
                    if (!$response['settings']['charge_only_delivery_for_website']) {

                        if (isset($response['pickup']['delivery_calc_data'])) {

                            // zone based calc
                            $is_zone_based_pickup = false;
                            if (isset($response['pickup']['delivery_calc_data']['regular_shipping_rate'])) {

                                $cost_pickup = format_value_to_fixed_precision($response['pickup']['delivery_calc_data']['regular_shipping_rate'], 2);

                                if ( !isEmptyPrice($response['pickup']['delivery_calc_data']['min_order_price']) && ($rental_product_subtotal >= $response['pickup']['delivery_calc_data']['min_order_price']) ) {

                                    $cost_pickup = format_value_to_fixed_precision($response['pickup']['delivery_calc_data']['shipping_rate'], 2);
                                }

                                $is_zone_based_pickup = true;

                                $cost_pickup_original = $cost_pickup;
                            }


                            if (isset($response['pickup']['delivery_calc_data']['status']) && empty($response['pickup']['delivery_calc_data']['status'])) {
            
                                set_rental_session_data('rental_shipping_has_error', 1);

                                ErrorHandler::registerErrorInLog($response['pickup']['delivery_calc_data']['message'] ?? 'Error on calculating the delivery cost!', "class-miles-based-shipping.php", "217", RentalException::TYPE_RUNTIME, null, "500");
            
                                set_rental_session_data('rental_delivery_cost', 0);
                                set_rental_session_data('rental_pickup_cost', 0);

                                $shipping_calc_failed = true;

                                // return;
                            }



                            // Venue matched based calc
                            $added_pickup_venue_cost = 0;
                            $pickup_venue_cost_is_replaced = 0;
                            if ( !isEmptyPrice($response['pickup']['venue_cost']) ) {

                                if (
                                    isset($response['pickup']['pickup_address_type']) 
                                    && $response['pickup']['pickup_address_type'] == 2 
                                    && isset($response['pickup']['pickup_address_id']) 
                                    && $response['pickup']['pickup_address_id']
                                ) {
                                    set_rental_session_data('rental_pickup_venue_address_id', $response['pickup']['pickup_address_id']);
                                } else {
                                    set_rental_session_data('rental_pickup_venue_address_id', 0);
                                }

                                set_rental_session_data('rental_pickup_address_type', $response['pickup']['pickup_address_type'] ?? 1);

                                if ($response['pickup']['venue_cost_type'] === 'REPLACE') {
                                    // fully replace venue cost

                                    $pickup_venue_cost_is_replaced = 1;

                                    $cost_pickup = format_value_to_fixed_precision($response['pickup']['venue_cost'], 2);

                                    $cost_pickup_original = $cost_pickup;

                                } else if ($response['pickup']['venue_cost_type'] === 'ADD') {
                                    // incrementally add the venue cost to pickup cost and other costs

                                    $added_pickup_venue_cost = format_value_to_fixed_precision($response['pickup']['venue_cost'], 2);
                                }
                            }


                            // calculating normal delivery cost if not zone based and no pickup venue matched
                            if (!$pickup_venue_cost_is_replaced && !$is_zone_based_pickup) {
                                $cost_pickup = isset($response['pickup']['delivery_calc_data']['price']) ? format_value_to_fixed_precision($response['pickup']['delivery_calc_data']['price'], 2) : 0;    
                            
                                $cost_pickup_original = $cost_pickup;
                            }

                            if ($added_pickup_venue_cost) {
                                $cost_pickup += $added_pickup_venue_cost;
                            }

                            if (!$pickup_venue_cost_is_replaced && $additional_charge_pickup = isset($response['pickup']['additional_charge']) && $response['pickup']['additional_charge'] ? $response['pickup']['additional_charge'] : 0) {
                                $cost_pickup += $additional_charge_pickup;
                            }

                            
                            if (isset($response['pickup']['pickup_time_based_fee']) && !isEmptyPrice($response['pickup']['pickup_time_based_fee'])) {

                                set_rental_session_data('pickup_time_based_fee', $response['pickup']['pickup_time_based_fee']);

                                $cost_pickup += $response['pickup']['pickup_time_based_fee'];
                            }


                            $distance_pickup = isset($response['pickup']['delivery_calc_data']['distance']) ? $response['pickup']['delivery_calc_data']['distance'] : 0;
                        }

                    }


                $cost = $cost_delivery;
                $is_local_pickup_chosen = function_exists( 'rental_is_chosen_shipping_all_local_pickup' )
                    && rental_is_chosen_shipping_all_local_pickup();

                $charge_only_delivery_for_website = (int) get_rental_session_data( 'charge_only_delivery_for_website', 0 );

                if ( $cost ) {
                    // Skip persisting delivery to session when checkout has only local pickup selected
                    // so that toggling back to delivery doesn't pay the round-trip leg twice.
                    if ( $is_local_pickup_chosen ) {
                        set_rental_session_data( 'rental_delivery_original_cost', 0 );
                    } else {
                        set_rental_session_data( 'rental_delivery_cost', $cost );

                        if ( $cost_delivery_original ) {
                            set_rental_session_data( 'rental_delivery_original_cost', $cost_delivery_original );
                        }
                    }
                }

                if ($cost_pickup) {

                    set_rental_session_data('rental_pickup_cost', $cost_pickup);

                    if ($cost_pickup_original) {
                        set_rental_session_data('rental_pickup_original_cost', $cost_pickup_original);
                    }

                    if ( ! $charge_only_delivery_for_website ) {
                        $cost = $cost_delivery + $cost_pickup;
                    }
                }

                if ( $shipping_calc_failed && $cost <= 0 ) {
                    $add_outside_zone_notice();
                } elseif ( $cost > 0 ) {
                    set_rental_session_data( 'rental_shipping_has_error', 0 );
                }

                }

            
                $distance_unit_short_text = "";
                $distance_unit_short = $response['settings']['shipping_by'] === 'mile' ? 'MI' : 'KM';

                $distance_unit_short_text = " (".$distance." ".$distance_unit_short.")";

                if ($distance_pickup) {
                    $distance = $distance + $distance_pickup;
                    $distance_unit_short_text = " (".$distance." ".$distance_unit_short.")";
                }
                
                if (
                    isset($_COOKIE['rental_different_pick_up_address']) 
                    && $_COOKIE['rental_different_pick_up_address'] == 1
                    && $delivery_settings['enable_different_pickup_delivery_address_for_website']
                    && $distance_pickup
                ) {
                    $distance_unit_short_text = " (".$distance." ".$distance_unit_short." (Delivery) ) + " . " (".$distance_pickup." ".$distance_unit_short." (Pickup) )";
                }

               
                // if ($additional_charge) {
                //     $distance_unit_short_text = " ( ".$distance." ".$distance_unit_short." - (Cost: ". get_woocommerce_currency_symbol().$cost. "- Addtional Charge: ".get_woocommerce_currency_symbol().$additional_charge.") )";
                // }
                
                $shipping_label_distance = $this->title . $distance_unit_short_text;

                if ($rental_miles_shipping_label_text = get_option('rental_miles_shipping_label_text', '')) {
                    $shipping_label_distance = $rental_miles_shipping_label_text . $distance_unit_short_text;
                }

                $rate = array(
                    'id' => $this->id,
                    // 'label' => $this->title ." (".$distance." ".$distance_unit_short.")",
                    'label' => !$is_zone_based_delivery_pickup ? $shipping_label_distance : "Zone Based Delivery",
                    'cost' => $cost,
                    'package' => $package,
                    // 'taxes' => ['shipping_tax'=>$tax],
                    'calc_tax' => 'per_order'
                );

                // add the rate
                $this->add_rate( $rate );


                if ($return_rate){
                    return $rate;
                }

            }

        }

        /**
         * Determine if "Ship to different address" is active
         * 
         * Checks multiple sources in order of priority:
         * 1. POST data (immediate form submission)
         * 2. Cookie (AJAX updates)
         * 
         * @return bool
         */
        private function is_ship_to_different_address() {
            // First check POST data (highest priority - direct form submission and AJAX post_data)
            if (!empty($_POST)) {
                $data = wc_clean(wp_unslash($_POST));
                if (!empty($data['post_data']) && is_string($data['post_data'])) {
                    parse_str($data['post_data'], $parsed);
                    if (!empty($parsed) && is_array($parsed)) {
                        $data = array_merge($data, wc_clean($parsed));
                    }
                }
                if (isset($data['ship_to_different_address'])) {
                    return !empty($data['ship_to_different_address']);
                }
            }

            // Fallback to cookie (for AJAX requests)
            if (isset($_COOKIE['rental_ship_to_different_address'])) {
                return $_COOKIE['rental_ship_to_different_address'] === '1';
            }

            return false;
        }

        /**
         * Get normalized checkout POST data for address resolution.
         * WooCommerce update_order_review AJAX sends shortened keys (address, city, state, postcode, country)
         * and may send the full form as post_data. Merge both so billing_address_1 / billing_city etc. are available.
         *
         * @return array
         */
        private function get_checkout_post_data() {
            $data = !empty($_POST) ? wc_clean(wp_unslash($_POST)) : array();
            if (!empty($data['post_data']) && is_string($data['post_data'])) {
                parse_str($data['post_data'], $parsed);
                if (!empty($parsed) && is_array($parsed)) {
                    $data = array_merge($data, wc_clean($parsed));
                }
            }
            // WooCommerce checkout.js sends short keys for billing in update_order_review; map into billing_* so one path works.
            if (isset($data['address']) && (string) $data['address'] !== '' && empty($data['billing_address_1'])) {
                $data['billing_address_1'] = $data['address'];
            }
            if (isset($data['address_2']) && (string) $data['address_2'] !== '' && empty($data['billing_address_2'])) {
                $data['billing_address_2'] = $data['address_2'];
            }
            if (isset($data['city']) && (string) $data['city'] !== '' && empty($data['billing_city'])) {
                $data['billing_city'] = $data['city'];
            }
            if (isset($data['state']) && (string) $data['state'] !== '' && empty($data['billing_state'])) {
                $data['billing_state'] = $data['state'];
            }
            if (isset($data['postcode']) && (string) $data['postcode'] !== '' && empty($data['billing_postcode'])) {
                $data['billing_postcode'] = $data['postcode'];
            }
            if (isset($data['country']) && (string) $data['country'] !== '' && empty($data['billing_country'])) {
                $data['billing_country'] = $data['country'];
            }
            return $data;
        }

        /**
         * Get destination delivery address
         * 
         * Returns the appropriate address (billing or shipping) based on
         * the "Ship to different address" checkbox state. Uses normalized POST
         * (WooCommerce AJAX short keys + post_data) so Google autocomplete + update_checkout work.
         * 
         * @return array Address parameters including lat/long coordinates
         */
        public function get_destination_delivery_address() {
            global $woocommerce;
            $address_params = [];

            $address_params['lat'] = '';
            $address_params['long'] = '';

            $customer = $woocommerce->customer;
            $ship_to_diff = $this->is_ship_to_different_address();
            $data = $this->get_checkout_post_data();
                
            if ($ship_to_diff) {
                // ========================================
                // SHIPPING ADDRESS (ship to different)
                // ========================================
                
                // Get shipping address data from POST or customer object
                $address_params['city']      = !empty($data['shipping_city']) ? $data['shipping_city'] : $customer->get_shipping_city();
                $address_params['state']     = !empty($data['shipping_state']) ? $data['shipping_state'] : $customer->get_shipping_state();
                $address_params['address']   = !empty($data['shipping_address_1']) ? $data['shipping_address_1'] : $customer->get_shipping_address_1();
                $address_params['address_2'] = !empty($data['shipping_address_2']) ? $data['shipping_address_2'] : $customer->get_shipping_address_2();
                $address_params['country']   = !empty($data['shipping_country']) ? $data['shipping_country'] : $customer->get_shipping_country();
                $address_params['zip']       = !empty($data['shipping_postcode']) ? $data['shipping_postcode'] : $customer->get_shipping_postcode();

                // Get coordinates - prioritize shipping-specific cookies
                if (
                    !empty($_COOKIE['rental_client_shipping_address_lat'])
                    && !empty($_COOKIE['rental_client_shipping_address_lng'])
                ) {
                    $address_params['lat'] = $_COOKIE['rental_client_shipping_address_lat'];
                    $address_params['long'] = $_COOKIE['rental_client_shipping_address_lng'];
                } elseif (
                    !empty($_COOKIE['rental_client_address_lat'])
                    && !empty($_COOKIE['rental_client_address_lng'])
                ) {
                    // Fallback to generic coordinates (should have been synced by JS)
                    $address_params['lat'] = $_COOKIE['rental_client_address_lat'];
                    $address_params['long'] = $_COOKIE['rental_client_address_lng'];
                }
                // Note: Do NOT use billing coordinates when shipping is checked

            } else {
                // ========================================
                // BILLING ADDRESS (default)
                // ========================================
                
                // Get billing address data from POST or customer object
                $address_params['city']      = !empty($data['billing_city']) ? $data['billing_city'] : $customer->get_billing_city();
                $address_params['state']     = !empty($data['billing_state']) ? $data['billing_state'] : $customer->get_billing_state();
                $address_params['address']   = !empty($data['billing_address_1']) ? $data['billing_address_1'] : $customer->get_billing_address_1();
                $address_params['address_2'] = !empty($data['billing_address_2']) ? $data['billing_address_2'] : $customer->get_billing_address_2();
                $address_params['country']   = !empty($data['billing_country']) ? $data['billing_country'] : $customer->get_billing_country();
                $address_params['zip']       = !empty($data['billing_postcode']) ? $data['billing_postcode'] : $customer->get_billing_postcode();

                // Get coordinates - prioritize billing-specific cookies
                if (
                    !empty($_COOKIE['rental_client_billing_address_lat'])
                    && !empty($_COOKIE['rental_client_billing_address_lng'])
                ) {
                    $address_params['lat'] = $_COOKIE['rental_client_billing_address_lat'];
                    $address_params['long'] = $_COOKIE['rental_client_billing_address_lng'];
                } elseif (
                    !empty($_COOKIE['rental_client_address_lat'])
                    && !empty($_COOKIE['rental_client_address_lng'])
                ) {
                    // Fallback to generic coordinates (should have been synced by JS)
                    $address_params['lat'] = $_COOKIE['rental_client_address_lat'];
                    $address_params['long'] = $_COOKIE['rental_client_address_lng'];
                }
                // Note: Do NOT use shipping coordinates when billing is the source
            }

            return $address_params;
        }

        
        


        public function get_destination_pickup_address() {
            $address_params = [];

            if (
                isset($_COOKIE['rental_client_pickup_address_lat'])
                && isset($_COOKIE['rental_client_pickup_address_lng'])
                && $_COOKIE['rental_client_pickup_address_lat']
                && $_COOKIE['rental_client_pickup_address_lng']
            ) {
                
                $address_params['lat'] = $_COOKIE['rental_client_pickup_address_lat'];
                $address_params['long'] = $_COOKIE['rental_client_pickup_address_lng'];
            }

            $address_params['city']      = isset($_COOKIE['rental_pick_up_city']) ? $_COOKIE['rental_pick_up_city'] : '';
            $address_params['state']       = isset($_COOKIE['rental_pick_up_state']) ? $_COOKIE['rental_pick_up_state'] : '';
            $address_params['address']   = isset($_COOKIE['rental_pick_up_address_1']) ? $_COOKIE['rental_pick_up_address_1'] : '';
            $address_params['address_2']   = isset($_COOKIE['rental_pick_up_address_2']) ? $_COOKIE['rental_pick_up_address_2'] : '';
            $address_params['country']     =  isset($_COOKIE['rental_pick_up_country']) ? $_COOKIE['rental_pick_up_country'] : '';
            $address_params['zip']    = isset($_COOKIE['rental_pick_up_postcode']) ? $_COOKIE['rental_pick_up_postcode'] : '';

            return $address_params;
        }

        public function set_destination_address($shipping_address_1, $shipping_state, $shipping_city, $shipping_country, $shipping_postcode, $address_2 = '') {
            global $woocommerce;
            $customer = $woocommerce->customer;

            $customer->set_shipping_address_1($shipping_address_1);
            $customer->set_shipping_address_2($address_2);
            $customer->set_shipping_city($shipping_city);
            $customer->set_shipping_state($shipping_state);
            $customer->set_shipping_country($shipping_country);
            $customer->set_shipping_postcode($shipping_postcode);

            $customer->save();
        }

        /**
         * Check if the free shipping amount is met
         *
         * @return bool
         */
        private function check_free_shipping_amount($delivery_settings) {
            if ($delivery_settings) {
                global $woocommerce;

                // $free_shipping_amount = get_option('rental_free_shipping_amount');
                $free_shipping_amount = $delivery_settings['free_shipping_amount_for_website'];
    
                return $free_shipping_amount > 0 && $woocommerce->cart->get_subtotal() >= $free_shipping_amount;
            }

            return false;
        }

        private function reset_delivery_params_data() {
            set_rental_session_data('rental_delivery_venue_address_id', 0);
            set_rental_session_data('rental_pickup_venue_address_id', 0);
            set_rental_session_data('rental_delivery_cost', 0);
            set_rental_session_data('rental_pickup_cost', 0);
            set_rental_session_data('rental_pickup_address_type', 1);
            set_rental_session_data('delivery_time_based_fee', 0);
            set_rental_session_data('pickup_time_based_fee', 0);
        }

        private function get_shipping_times_days() {

            $results = [];
            if (
                isset($_COOKIE['rental_selected_delivery_selection_time']) 
                && $_COOKIE['rental_selected_delivery_selection_time']
            ) {

                $rental_selected_delivery_selection_time = json_decode(stripslashes($_COOKIE['rental_selected_delivery_selection_time']), 1);

                if ($rental_selected_delivery_selection_time) {
                    $results['delivery_time_id'] = $rental_selected_delivery_selection_time['id'];
                    $results['delivery_start_time'] = $rental_selected_delivery_selection_time['start_time'];
                    $results['delivery_end_time'] = $rental_selected_delivery_selection_time['end_time'];
                    $results['delivery_day'] = $rental_selected_delivery_selection_time['day'];
                }
            }

            $opt_hide_time_pickers = get_option('rental_hide_time_pickers');
            $opt_default_start_time = get_option('rental_default_start_time', '09:00 AM');
            $opt_default_end_time = get_option('rental_default_end_time', '05:00 PM');
            $opt_hide_end_date = get_option('rental_hide_end_date');

            $rental_start_date = isset($_COOKIE['rental_start_date']) && $_COOKIE['rental_start_date'] ? decrypt_data($_COOKIE['rental_start_date'], get_option('rental_encryption_key')) : '';
            $rental_end_date = isset($_COOKIE['rental_end_date']) && $_COOKIE['rental_end_date'] ? decrypt_data($_COOKIE['rental_end_date'], get_option('rental_encryption_key')) : '';
            $finilizedStartEndDate = RTDelivery::extractFinalRentalStartEndDate($rental_start_date, $rental_end_date, $opt_hide_time_pickers, $opt_default_start_time, $opt_default_end_time, $opt_hide_end_date);

            $rental_start_date = $finilizedStartEndDate['rental_start_date'];
            $rental_end_date = $finilizedStartEndDate['rental_end_date'];

            // set rental start time if no specific delivery time was selected
            if (!isset($_COOKIE['rental_selected_delivery_selection_time'])
                || empty($_COOKIE['rental_selected_delivery_selection_time'])
                && $rental_start_date
            ) {

                $delivery_start_time = getTimeFromDate($rental_start_date, $opt_default_start_time);

                $rental_start_date_obj = parseWithDefaultTime($rental_start_date, $opt_default_start_time);
                $start_date_day_name = $rental_start_date_obj->format('l'); // Full name of the day

                $results['delivery_time_id'] = 0;
                $results['delivery_start_time'] = $delivery_start_time ?? '';
                $results['delivery_end_time'] = '';
                $results['delivery_day'] = getDayNumberByDayName($start_date_day_name);

                // setting the delivery start/end time cookie to be calculated in the order
                $selected_delivery_selection_time = [
                    'id' => 0,
                    'start_time' => $delivery_start_time ?? '',
                    'end_time' => '',
                    'day' => getDayNumberByDayName($start_date_day_name),
                ];
                $_COOKIE['rental_selected_delivery_selection_time'] = json_encode($selected_delivery_selection_time);
                setcookie('rental_selected_delivery_selection_time', json_encode($selected_delivery_selection_time), time() + RENTOPIAN_DATE_EXPIRE_TIME, "/", "", false, true);
            }

            if (
                isset($_COOKIE['rental_selected_pickup_selection_time']) 
                && $_COOKIE['rental_selected_pickup_selection_time']
            ) {

                $rental_selected_pickup_selection_time = json_decode(stripslashes($_COOKIE['rental_selected_pickup_selection_time']), 1);

                if ($rental_selected_pickup_selection_time) {
                    $results['pickup_time_id'] = $rental_selected_pickup_selection_time['id'];
                    $results['pickup_start_time'] = $rental_selected_pickup_selection_time['start_time'];
                    $results['pickup_end_time'] = $rental_selected_pickup_selection_time['end_time'];
                    $results['pickup_day'] = $rental_selected_pickup_selection_time['day'];
                }
            }


            // set rental end time if no specific pickup time was selected
            if (!isset($_COOKIE['rental_selected_pickup_selection_time'])
                || empty($_COOKIE['rental_selected_pickup_selection_time'])
                && $rental_end_date
            ) {
                    
                $delivery_end_time = getTimeFromDate($rental_end_date, $opt_default_end_time);

                $rental_end_date_obj = parseWithDefaultTime($rental_end_date, $opt_default_end_time);
                $end_date_day_name = $rental_end_date_obj->format('l'); // Full name of the day

                $results['pickup_time_id'] = 0;
                $results['pickup_start_time'] = $delivery_end_time ?? '';
                $results['pickup_end_time'] = '';
                $results['pickup_day'] = getDayNumberByDayName($end_date_day_name);


                // setting the pickup start/end time cookie to be calculated in the order
                $selected_pickup_selection_time = [
                    'id' => 0,
                    'start_time' => $delivery_end_time ?? '',
                    'end_time' => $delivery_end_time ?? '',
                    'day' => getDayNumberByDayName($end_date_day_name),
                ];
                $_COOKIE['rental_selected_pickup_selection_time'] = json_encode($selected_pickup_selection_time);
                setcookie('rental_selected_pickup_selection_time', json_encode($selected_pickup_selection_time), time() + RENTOPIAN_DATE_EXPIRE_TIME, "/", "", false, true);
                
            }

            return $results;
        }

    }
}

$woocommerce_miles_based_settings = get_option('woocommerce_miles_based_settings');
// if ($woocommerce_miles_based_settings 
//     // && $woocommerce_miles_based_settings['enabled'] === 'yes'
// ) {
    add_action( 'woocommerce_shipping_init', 'miles_based_shipping_method' );
    function add_miles_based_shipping_method( $methods ) {
        $methods[] = 'Miles_Based_Shipping_Method';
        return $methods;
    }
    add_filter( 'woocommerce_shipping_methods', 'add_miles_based_shipping_method' );
// }