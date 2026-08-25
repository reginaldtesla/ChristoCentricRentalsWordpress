<?php

add_action('wp_ajax_rental_save_settings_section', 'rental_save_settings_section');

function rental_save_settings_section() {
    // Basic security
    if (
        !isset($_POST['rental_settings_section_nonce']) ||
        !wp_verify_nonce($_POST['rental_settings_section_nonce'], 'rental_settings_section_save')
    ) {
        wp_send_json_error([
            'message' => __('Security check failed, please reload the page.', 'rentopian-sync')
        ], 400);
    }

    if ( !current_user_can('manage_woocommerce') && !current_user_can('manage_options')) {
        wp_send_json_error([
            'message' => __('You are not allowed to perform this action.', 'rentopian-sync')
        ], 403);
    }

    $section = isset($_POST['section']) ? sanitize_text_field($_POST['section']) : '';

    if (!$section) {
        wp_send_json_error([
            'message' => __('Missing section identifier.', 'rentopian-sync')
        ], 400);
    }

    try {
        $result = rental_handle_settings_section_save($section, $_POST);

        if (!empty($result['error'])) {
            wp_send_json_error([
                'message' => $result['error']
            ], 400);
        }

        $message = !empty($result['message'])
            ? $result['message']
            : __('Settings saved successfully', 'rentopian-sync');

        wp_send_json_success([
            'message' => $message,
            'section' => $section,
            'test' => $result['test'] ?? '',
        ]);

    } catch (Exception $e) {
        // Optional: log via your ErrorHandler

        if (class_exists('ErrorHandler')) {

            ErrorHandler::registerErrorInLog(
                $e->getMessage(),
                'rental_save_settings_section',
                'rental-settings',
                [],
                ErrorHandler::TYPE_SYNC_RUNTIME
            );
        }

        wp_send_json_error([
            'message' => sprintf(
                __('Unexpected error: %s', 'rentopian-sync'),
                esc_html($e->getMessage())
            ),
            'test' => $result['test'] ?? '',
        ], 500);
    }
}



/**
 * Whether the settings page renders the fields that only apply to a site
 * syncing rental products.
 *
 * Sale-only and hourly-only sites never see those fields, so their names never
 * reach the payload. An unchecked box and a field that was never on the page
 * look identical to a saver, and a saver that cannot tell them apart clears
 * the setting on every save. Ask this before writing one of them, and read the
 * same answer when deciding whether to render them.
 *
 * @return bool
 */
function rental_settings_rental_fields_rendered() {
    $product_type = get_option('rental_synchronized_product_type');

    return $product_type != 'sale' && $product_type != 'hourly';
}

/**
 * Handle per-section settings saving logic.
 *
 * @param string $section
 * @param array  $data   Typically $_POST passed from AJAX.
 *
 * @return array [
 *   'error'   => string|null,
 *   'message' => string|null,
 * ]
 */
function rental_handle_settings_section_save($section, array $data) {
    global $wpdb, $rental_tables;

    switch ($section) {

        /**
         * general = first tab
         * (Layout + Quotes/Orders + Blacklists + Date/Time/ZIP + Prices + Delivery + Terms + Sets + Products + Visual)
         */
        case 'general':
            $results = [];

            // These functions only touch the options they care about.
            $results[] = rental_save_settings_section_layout($data);
            $results[] = rental_save_settings_section_quotes_orders($data);
            $results[] = rental_save_settings_section_blacklists($data);

            if (rental_settings_rental_fields_rendered()) {
                $results[] = rental_save_settings_section_date_time_zip_location($data);
            }

            $results[] = rental_save_settings_section_prices_visibility($data);
            $results[] = rental_save_settings_section_delivery($data);
            $results[] = rental_save_settings_section_terms_messages($data);
            $results[] = rental_save_settings_section_sets($data);
            $results[] = rental_save_settings_section_products($data);
            $results[] = rental_save_settings_section_visual_components($data);

            rental_handle_select_division_cookie($data);

            $errors   = [];
            $messages = [];

            foreach ($results as $res) {
                if (empty($res)) {
                    continue;
                }
                if (!empty($res['error'])) {
                    $errors[] = $res['error'];
                }
                if (!empty($res['errors']) && is_array($res['errors'])) {
                    $errors = array_merge($errors, $res['errors']);
                }
                if (!empty($res['message'])) {
                    $messages[] = $res['message'];
                }
            }

            if (!empty($errors)) {
                return [
                    'error'   => implode("\n", array_unique($errors)),
                    'message' => null,
                ];
            }

            return [
                'message' => __('General settings saved successfully.', 'rentopian-sync'),
            ];

        case 'text_labels':
            $result = rental_save_settings_section_text_labels($data);
            break;

        case 'tiers':
            $result = rental_save_settings_section_tiers($data);
            break;

        default:
            $result = [
                'error' => __('Unknown settings section.', 'rentopian-sync'),
            ];
    }

    return wp_parse_args($result, [
        'error'   => null,
        'message' => null,
    ]);
}


function rental_handle_select_division_cookie(array $data) {
    $opt_select_division = 'rental_select_division';

    $val_select_division = !empty($data[$opt_select_division]) ? 1 : 0;

    if (empty($val_select_division) && isset($_COOKIE['rental_division_id'])) {
        unset($_COOKIE['rental_division_id']);
        setcookie(
            'rental_division_id',
            '',
            time() - 31556952,
            '/',
            '',
            false,
            true
        );
    }
}



function rental_save_settings_section_layout(array $data) {

    $opt_form_layout         = 'rental_form_layout';
    $opt_dates_on_checkout   = 'rental_dates_on_checkout';
    $opt_dates_header_label  = 'rental_dates_header_label';

    // checkout layout mode option
    $opt_checkout_layout_mode = 'rental_checkout_layout_mode';

    // Form layout (horizontal / in-cart)
    $val_form_layout = isset($data[$opt_form_layout]) && $data[$opt_form_layout] === 'in-cart'
        ? 'in-cart'
        : 'horizontal';

    // Show dates only on checkout checkbox
    $val_dates_on_checkout = isset($data[$opt_dates_on_checkout]) ? 1 : 0;

    // Raw + sanitized header label (HTML allowed).
    $raw_dates_header_label = isset($data[$opt_dates_header_label])
        ? wp_unslash($data[$opt_dates_header_label])
        : '';

    $val_dates_header_label = wp_kses_post($raw_dates_header_label);

    $result = [
        'message' => __('Layout settings saved successfully.', 'rentopian-sync'),
    ];

    // Validate header label length
    if ($val_dates_header_label !== '') {
        $validation = rental_validate_text_length(
            $val_dates_header_label,
            2000,
            16 * 1024,
            __('Dates header label', 'rentopian-sync')
        );

        if ($validation['error']) {
            $result['error'] = $validation['error'];
        }
    }

    // compute checkout layout mode based on checkbox + posted mode
    $posted_mode = isset($data[$opt_checkout_layout_mode])
        ? sanitize_text_field(wp_unslash($data[$opt_checkout_layout_mode]))
        : '';

    // Default is classic
    $val_checkout_layout_mode = 'classic';

    if ($val_dates_on_checkout) {
        // Only allow "modern" when the dates form is on checkout
        $val_checkout_layout_mode = ($posted_mode === 'modern') ? 'modern' : 'classic';
    }

    // If overbooks are not allowed, force dates_on_checkout off and keep layout classic
    if (!get_option('rental_allow_overbook', 0) || !$val_dates_on_checkout) {
        $val_dates_on_checkout      = 0;
        $val_checkout_layout_mode   = 'classic';
    }

    // Save header label only if it passed validation
    if (empty($result['error'])) {
        update_option($opt_dates_header_label, $val_dates_header_label);
    }

    // Persist layout-related options
    update_option($opt_form_layout,           $val_form_layout);
    update_option($opt_dates_on_checkout,     $val_dates_on_checkout);
    update_option($opt_checkout_layout_mode,  $val_checkout_layout_mode);

    return $result;
}



function rental_save_settings_section_quotes_orders(array $data) {

    $opt_direct_only_bookings        = 'rental_direct_only_bookings';
    $opt_allow_to_pay_deposit        = 'rental_allow_to_pay_deposit';
    $opt_allow_to_pay_security_deposit = 'rental_allow_to_pay_security_deposit';
    $opt_allow_to_pay_another_amount = 'rental_allow_to_pay_another_amount';
    $opt_send_email                  = 'rental_send_email';
    $opt_rental_referral_sources_setting = 'rental_referral_sources_setting';
    $opt_rental_event_types_setting  = 'rental_event_types_setting';
    $opt_rental_exclude_order_fees   = 'rental_exclude_order_fees';
    $opt_rental_payment_tips_enabled = 'rental_payment_tips_enabled';
    $opt_rental_checkout_photo_upload_enabled = 'rental_checkout_photo_upload_enabled';

    $val_direct_only_bookings = isset($data[$opt_direct_only_bookings]) ? 1 : 0;
    $val_allow_to_pay_deposit = ($val_direct_only_bookings && isset($data[$opt_allow_to_pay_deposit])) ? 1 : 0;
    $val_allow_to_pay_another_amount = (
        $val_allow_to_pay_deposit &&
        isset($data[$opt_allow_to_pay_another_amount]) &&
        $data[$opt_allow_to_pay_another_amount]
    ) ? 1 : 0;

    $val_allow_to_pay_security_deposit = isset($data[$opt_allow_to_pay_security_deposit]) ? 1 : 0;
    $val_send_email = isset($data[$opt_send_email]) ? 1 : 0;
    $val_rental_referral_sources_setting = isset($data[$opt_rental_referral_sources_setting]) ? 1 : 0;
    $val_rental_event_types_setting      = isset($data[$opt_rental_event_types_setting]) ? 1 : 0;
    $val_rental_exclude_order_fees       = isset($data[$opt_rental_exclude_order_fees]) ? 1 : 0;
    $val_rental_payment_tips_enabled     = isset($data[$opt_rental_payment_tips_enabled]) ? 1 : 0;
    $val_rental_checkout_photo_upload_enabled = isset($data[$opt_rental_checkout_photo_upload_enabled]) ? 1 : 0;

    update_option($opt_direct_only_bookings, $val_direct_only_bookings);
    update_option($opt_allow_to_pay_deposit, $val_allow_to_pay_deposit);
    update_option($opt_allow_to_pay_another_amount, $val_allow_to_pay_another_amount);
    update_option($opt_allow_to_pay_security_deposit, $val_allow_to_pay_security_deposit);
    update_option($opt_send_email, $val_send_email);
    update_option($opt_rental_referral_sources_setting, $val_rental_referral_sources_setting);
    update_option($opt_rental_event_types_setting, $val_rental_event_types_setting);
    update_option($opt_rental_exclude_order_fees, $val_rental_exclude_order_fees);
    update_option($opt_rental_payment_tips_enabled, $val_rental_payment_tips_enabled);
    update_option($opt_rental_checkout_photo_upload_enabled, $val_rental_checkout_photo_upload_enabled);

    return [
        'message' => __('Quotes / Orders settings saved successfully.', 'rentopian-sync'),
        // 'test' => $val_rental_payment_tips_enabled,
    ];
}

/**
 * Save "Blacklists" section settings.
 */
/**
 * Save "Blacklists" section settings.
 */
function rental_save_settings_section_blacklists( array $data ) {
    $errors = [];

    // Toggle: send email when blacklisted client tries to book
    $notif_email = ! empty( $data['rental_client_blacklisted_notif_email'] ) ? 1 : 0;
    update_option( 'rental_client_blacklisted_notif_email', $notif_email );

    // Rejection message shown to blacklisted client (HTML allowed)
    $raw_reject_msg = isset( $data['rental_client_blacklisted_reject_msg'] )
        ? wp_unslash( $data['rental_client_blacklisted_reject_msg'] )
        : '';

    $reject_msg = wp_kses_post( $raw_reject_msg );

    $default_reject_msg = __( 'Sorry, your request has been declined.', 'rentopian-sync' );

    if ( $reject_msg === '' ) {
        // Fall back to safe default, always allowed
        $reject_msg = $default_reject_msg;
    } else {
        // Validate as a generic message: up to 2000 chars, ~16KB
        $validation = rental_validate_text_length(
            $reject_msg,
            2000,
            16 * 1024,
            __( 'Blacklist rejection message', 'rentopian-sync' )
        );

        if ( $validation['error'] ) {
            $errors[] = $validation['error'];
        }
    }

    // Save only if we have no length error OR we are saving the default fallback
    if ( empty( $errors ) || $reject_msg === $default_reject_msg ) {
        update_option( 'rental_client_blacklisted_reject_msg', $reject_msg );
    }

    return [
        'success' => empty( $errors ),
        'message' => __( 'Blacklist settings saved.', 'rentopian-sync' ),
        'errors'  => $errors,
    ];
}


/**
 * Whether two default time strings represent the same clock time (same calendar day).
 *
 * @param string $start Sanitized time string (e.g. "9:00 AM").
 * @param string $end   Sanitized time string (e.g. "5:00 PM").
 */
function rental_admin_default_start_end_times_equal( $start, $end ) {
    $start = trim( (string) $start );
    $end   = trim( (string) $end );
    if ( $start === '' || $end === '' ) {
        return false;
    }
    $base      = '1970-01-01 ';
    $ts_start  = strtotime( $base . $start );
    $ts_end    = strtotime( $base . $end );
    if ( false !== $ts_start && false !== $ts_end ) {
        return (int) $ts_start === (int) $ts_end;
    }
    $norm = static function ( $s ) {
        return strtolower( preg_replace( '/\s+/', '', (string) $s ) );
    };

    return $norm( $start ) === $norm( $end );
}

/**
 * Save "Date, Time & ZIP / Location" section coming from AJAX.
 *
 */
function rental_save_settings_section_date_time_zip_location( array $data ) {
    $errors = [];

    $opt_dates_on_checkout              = 'rental_dates_on_checkout';
    $opt_select_division                = 'rental_select_division';
    $opt_hide_zip                       = 'rental_hide_zip';
    $opt_event_start_time               = 'rental_event_start_time';
    $opt_hide_end_date                  = 'rental_hide_end_date';
    $opt_multi_day_event                = 'rental_multi_day_event';
    $opt_hide_time_pickers              = 'rental_hide_time_pickers';
    $opt_min_start_date                 = 'rental_min_start_date';
    $opt_min_dates_range                = 'rental_min_dates_range';
    $opt_max_dates_range                = 'rental_max_dates_range';
    $opt_date_step                      = 'rental_date_step';
    $opt_select_date_range              = 'rental_select_date_range';
    $opt_hide_damage_waiver             = 'rental_hide_damage_waiver';
    $opt_buy_damage_waiver_by_default   = 'rental_buy_damage_waiver_by_default';
    $opt_hide_and_buy_damage_waiver_by_default = 'rental_hide_and_buy_damage_waiver_by_default';
    $opt_disabled_week_days             = 'rental_disabled_week_days';
    $opt_location                       = 'rental_show_location';
    $opt_select_a_day_by_default        = 'rental_select_a_day_by_default';
    $opt_selected_default_day           = 'rental_selected_default_day';
    $opt_selected_default_day_automate  = 'rental_selected_default_day_automate';
    $opt_default_start_time             = 'rental_default_start_time';
    $opt_default_end_time               = 'rental_default_end_time';
    $opt_dates_header_label             = 'rental_dates_header_label';
    $opt_google_map_key                 = 'rental_google_map_key';
    $opt_not_open_dates_form            = 'rental_not_open_dates_form';

    $opt_checkout_layout_mode = 'rental_checkout_layout_mode';

    // Use POST value if present, otherwise fall back to current option
    $val_dates_on_checkout = !empty($data[$opt_dates_on_checkout])
        ? 1
        : (int) get_option($opt_dates_on_checkout, 0);

    // We need current (or posted) "select division" to compute hide_zip
    $val_select_division = !empty($data[$opt_select_division])
        ? 1
        : (int) get_option($opt_select_division, 0);

    $val_hide_zip         = ($val_select_division || !empty($data[$opt_hide_zip])) ? 1 : 0;
    if ($val_dates_on_checkout == 1) {
        $val_hide_zip = 1;
    }

    $val_event_start_time   = !empty($data[$opt_event_start_time]) ? 1 : 0;
    $val_hide_end_date      = !empty($data[$opt_hide_end_date]) ? 1 : 0;

    $val_multi_day_event    = !empty($data[$opt_multi_day_event]) ? 1 : 0;
    if ($val_dates_on_checkout != 1 || (string) $data[$opt_checkout_layout_mode] != 'modern') {
        $val_multi_day_event = 0;
    }
    
    // if multi day event enabled, we must hide the end date
    if ($val_multi_day_event == 1) {
        $val_hide_end_date = 0;
    }

    $val_hide_time_pickers  = !empty($data[$opt_hide_time_pickers]) ? 1 : 0;

    $min_start_raw  = isset($data[$opt_min_start_date]) ? (int) $data[$opt_min_start_date] : 0;
    $val_min_start_date = $min_start_raw > 0 ? $min_start_raw : '';

    $min_range_raw  = isset($data[$opt_min_dates_range]) ? (int) $data[$opt_min_dates_range] : 0;
    $val_min_dates_range = $min_range_raw > 0 ? $min_range_raw : '';

    $max_range_raw = isset($data[$opt_max_dates_range]) ? (int) $data[$opt_max_dates_range] : 0;
    $val_max_dates_range = (
        $max_range_raw > 0 &&
        (!$val_min_dates_range || $max_range_raw >= $val_min_dates_range)
    ) ? $max_range_raw : '';

    $date_step_raw = isset($data[$opt_date_step]) ? (int) $data[$opt_date_step] : 0;
    $val_date_step = (
        $date_step_raw >= 1 &&
        (!$val_max_dates_range || $date_step_raw <= $val_max_dates_range)
    ) ? $date_step_raw : '';

    $val_select_date_range = (
        !empty($data[$opt_select_date_range]) &&
        $val_date_step
    ) ? 1 : 0;

    // Clear rental_start_date / rental_end_date cookies when any of these change
    if (
        !empty($val_min_start_date)  ||
        !empty($val_min_dates_range) ||
        !empty($val_max_dates_range) ||
        !empty($val_date_step)       ||
        !empty($val_select_date_range)
    ) {
        if (isset($_COOKIE['rental_end_date'])) {
            unset($_COOKIE['rental_end_date']);
            setcookie('rental_end_date', '', time() - 31556952, '/', '', false, true);
        }
        if (isset($_COOKIE['rental_start_date'])) {
            unset($_COOKIE['rental_start_date']);
            setcookie('rental_start_date', '', time() - 31556952, '/', '', false, true);
        }
    }

    $val_hide_damage_waiver             = !empty($data[$opt_hide_damage_waiver]) ? 1 : 0;
    $val_buy_damage_waiver_by_default   = !empty($data[$opt_buy_damage_waiver_by_default]) ? 1 : 0;
    $val_hide_and_buy_damage_waiver_by_default = !empty($data[$opt_hide_and_buy_damage_waiver_by_default]) ? 1 : 0;

    $val_disabled_week_days = empty($val_date_step)
        ? ( isset($data[$opt_disabled_week_days]) ? (array) $data[$opt_disabled_week_days] : [] )
        : [];

    $val_location = !empty($data[$opt_location]) ? 1 : 0;
    if ($val_dates_on_checkout === 1 && (int) get_option('rental_allow_overbook', 1) === 1) {
        $val_location = 0;
    }

    $val_select_a_day_by_default = !empty($data[$opt_select_a_day_by_default]) ? 1 : 0;

    $selected_default_raw = isset($data[$opt_selected_default_day])
        ? (int) $data[$opt_selected_default_day]
        : 1;

    $min_selected_default_day = rental_get_min_selected_default_day($val_min_start_date);
    $max_selected_default_day = rental_get_max_selected_default_day($val_min_start_date);

    if ($selected_default_raw > $max_selected_default_day) {
        $selected_default_raw = $max_selected_default_day;
    }
    if ($selected_default_raw < 1) {
        $selected_default_raw = 1;
    }
    $val_selected_default_day = $selected_default_raw;

    // The default day may not fall inside the blocked window. Refuse the save
    // rather than storing a pair the calendars cannot honour.
    if ($val_select_a_day_by_default && $val_selected_default_day < $min_selected_default_day) {
        return [
            'error' => sprintf(
                /* translators: 1: offset in days, 2: earliest valid default day label. */
                __('The default selected day falls inside the orders offset of %1$d day(s). Choose "%2$s" or later.', 'rentopian-sync'),
                (int) $val_min_start_date,
                rental_get_selected_default_day_label($min_selected_default_day)
            ),
        ];
    }

    $val_selected_default_day_automate =
        !empty($data[$opt_selected_default_day_automate]) ? 1 : 0;

    $val_default_start_time = isset($data[$opt_default_start_time]) && $data[$opt_default_start_time] !== ''
        ? sanitize_text_field(wp_unslash($data[$opt_default_start_time]))
        : '9:00 AM';

    $val_default_end_time = isset($data[$opt_default_end_time]) && $data[$opt_default_end_time] !== ''
        ? sanitize_text_field(wp_unslash($data[$opt_default_end_time]))
        : '5:00 PM';

    if ( rental_admin_default_start_end_times_equal( $val_default_start_time, $val_default_end_time ) ) {
        return [
            'error' => __( 'Default start time and default end time cannot be the same.', 'rentopian-sync' ),
        ];
    }

    $dates_header_label = isset($data[$opt_dates_header_label])
        ? sanitize_text_field(wp_unslash($data[$opt_dates_header_label]))
        : '';

    $google_map_key = isset($data[$opt_google_map_key])
        ? sanitize_text_field(wp_unslash($data[$opt_google_map_key]))
        : '';

    $val_not_open_dates_form = !empty($data[$opt_not_open_dates_form]) ? 1 : 0;

    update_option($opt_dates_on_checkout,             $val_dates_on_checkout);
    update_option($opt_hide_zip,                      $val_hide_zip);
    update_option($opt_event_start_time,              $val_event_start_time);
    update_option($opt_hide_end_date,                 $val_hide_end_date);
    update_option($opt_multi_day_event,               $val_multi_day_event);
    update_option($opt_hide_time_pickers,             $val_hide_time_pickers);
    update_option($opt_min_start_date,                $val_min_start_date);
    update_option($opt_min_dates_range,               $val_min_dates_range);
    update_option($opt_max_dates_range,               $val_max_dates_range);
    update_option($opt_date_step,                     $val_date_step);
    update_option($opt_select_date_range,             $val_select_date_range);
    update_option($opt_hide_damage_waiver,            $val_hide_damage_waiver);
    update_option($opt_buy_damage_waiver_by_default,  $val_buy_damage_waiver_by_default);
    update_option($opt_hide_and_buy_damage_waiver_by_default, $val_hide_and_buy_damage_waiver_by_default);
    update_option($opt_disabled_week_days,            $val_disabled_week_days);
    update_option($opt_location,                      $val_location);
    update_option($opt_select_a_day_by_default,       $val_select_a_day_by_default);
    update_option($opt_selected_default_day,          $val_selected_default_day);
    update_option($opt_selected_default_day_automate, $val_selected_default_day_automate);
    update_option($opt_default_start_time,            $val_default_start_time);
    update_option($opt_default_end_time,              $val_default_end_time);
    update_option($opt_dates_header_label,            $dates_header_label);
    update_option($opt_google_map_key,                $google_map_key);
    update_option($opt_not_open_dates_form,           $val_not_open_dates_form);

    return [
        'success' => empty($errors),
        'message' => empty($errors)
            ? __('Date, time and location settings saved.', 'rentopian-sync')
            : __('Date, time and location settings saved with some adjustments.', 'rentopian-sync'),
        'errors'  => $errors,
    ];
}

/**
 * Validate a (potentially HTML) string by character and byte length.
 *
 * @param string      $value       Raw (unslashed) string (may contain HTML).
 * @param int         $max_chars   Max UTF-8 characters (0 = no limit).
 * @param int         $max_bytes   Max bytes (0 = no limit).
 * @param string|null $field_label Optional label for error messages.
 *
 * @return array {
 *   @type string      $value Original value (unchanged).
 *   @type string|null $error Error message, or null if within limits.
 * }
 */
function rental_validate_text_length( $value, $max_chars = 0, $max_bytes = 0, $field_label = null ) {
    $label = $field_label ? $field_label : __( 'This field', 'rentopian-sync' );

    // For character limits, measure on a tag-stripped version so HTML markup
    // itself doesn't "eat" the quota.
    $plain         = wp_strip_all_tags( (string) $value );
    $length_chars  = mb_strlen( $plain, 'UTF-8' );

    if ( $max_chars > 0 && $length_chars > $max_chars ) {
        return [
            'value' => $value,
            'error' => sprintf(
                /* translators: 1: field label; 2: max chars; 3: current chars */
                __( '%1$s is too long (maximum %2$d characters, currently %3$d).', 'rentopian-sync' ),
                $label,
                $max_chars,
                $length_chars
            ),
        ];
    }

    if ( $max_bytes > 0 ) {
        // strlen() on a WP string = byte length (UTF-8).
        $length_bytes = strlen( (string) $value );

        if ( $length_bytes > $max_bytes ) {
            return [
                'value' => $value,
                'error' => sprintf(
                    /* translators: 1: field label; 2: max KB; 3: current KB (approx) */
                    __( '%1$s is too large (maximum about %2$d KB, currently about %3$d KB).', 'rentopian-sync' ),
                    $label,
                    (int) floor( $max_bytes / 1024 ),
                    (int) ceil( $length_bytes / 1024 )
                ),
            ];
        }
    }

    return [
        'value' => $value,
        'error' => null,
    ];
}




/**
 * Option names holding rich text, which must keep their markup.
 *
 * The "general" section runs several savers over the same payload, so a field
 * is written more than once per request. Listing it here makes every saver
 * store it with wp_kses_post(), so a later one cannot strip the markup.
 *
 * @return string[]
 */
function rental_get_html_settings_keys() {
    return [
        'rental_special_terms',
    ];
}


/**
 * Generic saver for simple sections that only persist options.
 *
 * It will:
 * - Ignore core control fields (action, section, nonce).
 * - Only touch options whose name starts with "rental_" (configurable via $prefix).
 * - Sanitize values:
 *     - For keys listed in 'html_keys', and for rental_get_html_settings_keys() → wp_kses_post()
 *     - All others → sanitize_text_field()
 * - Arrays are sanitized per element and stored as arrays.
 *
 * @param array $data Raw $_POST coming from the section form.
 * @param array $args {
 *   @type string $prefix       Option name prefix to restrict to. Default 'rental_'.
 *   @type array  $exclude_keys Keys that must not be processed.
 *   @type array  $html_keys    Option names that may contain limited HTML.
 * }
 *
 * @return array ['success' => bool]
 */
function rental_save_settings_section_generic( array $data, array $args = [] ) {
    $prefix       = isset( $args['prefix'] ) ? (string) $args['prefix'] : 'rental_';
    $exclude_keys = isset( $args['exclude_keys'] ) ? (array) $args['exclude_keys'] : [];
    $html_keys    = isset( $args['html_keys'] ) ? (array) $args['html_keys'] : [];

    $html_keys = array_unique( array_merge( $html_keys, rental_get_html_settings_keys() ) );

    $exclude_keys = array_merge(
        $exclude_keys,
        [
            'action',
            'section',
            'rental_settings_section_nonce',
        ]
    );

    foreach ( $data as $key => $value ) {
        if ( ! is_string( $key ) ) {
            continue;
        }

        // layout mode is handled only in rental_save_settings_section_layout()
        if ( $key === 'rental_checkout_layout_mode' ) {
            continue;
        }


        if ( in_array( $key, $exclude_keys, true ) ) {
            continue;
        }

        if ( 0 !== strpos( $key, $prefix ) ) {
            // Not one of our options, skip.
            continue;
        }

        // Arrays (multi-select / checkboxes etc.)
        if ( is_array( $value ) ) {
            $unslashed = wp_unslash( $value );
            $sanitized = array_map( 'sanitize_text_field', $unslashed );
            update_option( $key, $sanitized );
            continue;
        }

        // Scalars
        $value         = wp_unslash( $value );
        $is_html_field = in_array( $key, $html_keys, true );

        // Normalize boolean-like strings for non-HTML fields,
        // so flags never get stored as literal 'true' / 'false'.
        if ( ! $is_html_field && is_string( $value ) ) {
            $lower = strtolower( $value );
            if ( $lower === 'true' ) {
                $value = 1;
            } elseif ( $lower === 'false' ) {
                $value = 0;
            }
        }

        if ( $is_html_field ) {
            update_option( $key, wp_kses_post( $value ) );
        } else {
            update_option( $key, sanitize_text_field( $value ) );
        }
    }

    return [
        'success' => true,
    ];
}


/**
 * Save "Prices & Visibility" section settings.
 *
 */
function rental_save_settings_section_prices_visibility( array $data ) {

    $opt_direct_only_bookings          = 'rental_direct_only_bookings';
    $opt_hide_product_price            = 'rental_hide_product_price';
    $opt_show_product_price_only_in_cart = 'rental_show_product_price_only_in_cart';
    $opt_hide_damage_waiver            = 'rental_hide_damage_waiver';
    $opt_buy_damage_waiver_by_default  = 'rental_buy_damage_waiver_by_default';
    $opt_hide_and_buy_damage_waiver_by_default = 'rental_hide_and_buy_damage_waiver_by_default';
    $opt_show_damage_waiver_on_cart    = 'rental_show_damage_waiver_on_cart';
    $opt_hide_set_items                = 'rental_hide_set_items';
    $opt_filter_unavailable_products   = 'rental_filter_unavailable_products';
    $opt_display_sale_products_page    = 'rental_display_sale_products_page';

    $val_direct_only_bookings = !empty($data[$opt_direct_only_bookings])
        ? 1
        : (int) get_option($opt_direct_only_bookings, 0);

    // cannot hide prices if direct bookings are enabled
    $val_hide_product_price = (
        !$val_direct_only_bookings &&
        !empty($data[$opt_hide_product_price])
    ) ? 1 : 0;

    // can only "show in cart only" when prices are not hidden
    $val_show_product_price_only_in_cart = (
        !$val_hide_product_price &&
        !empty($data[$opt_show_product_price_only_in_cart])
    ) ? 1 : 0;

    $val_hide_set_items = !empty($data[$opt_hide_set_items]) ? 1 : 0;
    $val_filter_unavailable_products = !empty($data[$opt_filter_unavailable_products]) ? 1 : 0;
    $val_display_sale_products_page  = !empty($data[$opt_display_sale_products_page]) ? 1 : 0;

    if (rental_settings_rental_fields_rendered()) {
        $val_hide_damage_waiver            = !empty($data[$opt_hide_damage_waiver]) ? 1 : 0;
        $val_buy_damage_waiver_by_default  = !empty($data[$opt_buy_damage_waiver_by_default]) ? 1 : 0;
        $val_hide_and_buy_damage_waiver_by_default =
            !empty($data[$opt_hide_and_buy_damage_waiver_by_default]) ? 1 : 0;
        $val_show_damage_waiver_on_cart = !empty($data[$opt_show_damage_waiver_on_cart]) ? 1 : 0;

        update_option($opt_hide_damage_waiver,           $val_hide_damage_waiver);
        update_option($opt_buy_damage_waiver_by_default, $val_buy_damage_waiver_by_default);
        update_option($opt_hide_and_buy_damage_waiver_by_default, $val_hide_and_buy_damage_waiver_by_default);
        update_option($opt_show_damage_waiver_on_cart,   $val_show_damage_waiver_on_cart);
    }

    update_option($opt_hide_set_items,                $val_hide_set_items);
    update_option($opt_filter_unavailable_products,   $val_filter_unavailable_products);
    update_option($opt_display_sale_products_page,    $val_display_sale_products_page);
    update_option($opt_hide_product_price,            $val_hide_product_price);
    update_option($opt_show_product_price_only_in_cart, $val_show_product_price_only_in_cart);

    // Any other simple rental_* fields submitted with this section can be handled by generic saver,
    // but we exclude the ones we just handled so we don't override our conditional logic.
    rental_save_settings_section_generic($data, [
        'exclude_keys' => [
            $opt_direct_only_bookings,
            $opt_hide_product_price,
            $opt_show_product_price_only_in_cart,
            $opt_hide_set_items,
            $opt_filter_unavailable_products,
            $opt_display_sale_products_page,
            $opt_hide_damage_waiver,
            $opt_buy_damage_waiver_by_default,
            $opt_hide_and_buy_damage_waiver_by_default,
            $opt_show_damage_waiver_on_cart,
        ],
    ]);

    return [
        'message' => __('Prices & visibility settings saved successfully.', 'rentopian-sync'),
    ];
}


/**
 * Save "Delivery" section settings.
 *
 */
function rental_save_settings_section_delivery( array $data ) {
    global $wpdb, $rental_tables;

    $opt_do_not_use_rentopian_shipping = 'rental_do_not_use_rentopian_shipping';
    $opt_track_wc_shipping             = 'rental_track_wc_shipping';
    $opt_different_pick_up_addresses   = 'rental_different_pick_up_addresses';
    $opt_double_shipping_fee           = 'rental_double_shipping_fee';
    $opt_combine_shipping_tax          = 'rental_combine_shipping_tax';
    $opt_free_shipping_amount          = 'rental_free_shipping_amount';
    $opt_google_distance_key           = 'rental_google_distance_key';
    $opt_pickup_delivery               = 'rental_pickup_delivery';
    $opt_use_google_distance_key       = 'rental_use_google_distance_key';
    $opt_full_payment_for_delivery     = 'rental_full_payment_for_delivery';

    $val_do_not_use_rentopian_shipping = !empty($data[$opt_do_not_use_rentopian_shipping]) ? 1 : 0;

    // Track WC shipping only when Rentopian shipping is disabled 
    $val_track_wc_shipping = (
        $val_do_not_use_rentopian_shipping &&
        !empty($data[$opt_track_wc_shipping])
    ) ? 1 : 0;

    // These are meaningful only when Rentopian shipping is ON
    $val_different_pick_up_addresses = (
        !$val_do_not_use_rentopian_shipping &&
        !empty($data[$opt_different_pick_up_addresses])
    ) ? 1 : 0;

    $val_double_shipping_fee = (
        !$val_do_not_use_rentopian_shipping &&
        !empty($data[$opt_double_shipping_fee])
    ) ? 1 : 0;

    $val_combine_shipping_tax = !empty($data[$opt_combine_shipping_tax]) ? 1 : 0;

    $val_free_shipping_amount = (
        isset($data[$opt_free_shipping_amount]) &&
        $data[$opt_free_shipping_amount] !== '' &&
        floatval($data[$opt_free_shipping_amount]) > 0
    ) ? floatval($data[$opt_free_shipping_amount]) : '';

    $val_google_distance_key = isset($data[$opt_google_distance_key])
        ? sanitize_text_field(wp_unslash($data[$opt_google_distance_key]))
        : '';

    $val_pickup_delivery = isset($data[$opt_pickup_delivery])
        ? sanitize_text_field(wp_unslash($data[$opt_pickup_delivery]))
        : get_option($opt_pickup_delivery, 'company_delivery_return');

    // Keep original rule: only allow "use distance key" when key is set
    $val_use_google_distance_key = (
        !empty($val_google_distance_key) &&
        !empty($data[$opt_use_google_distance_key])
    ) ? 1 : 0;

    // Meaningful only when both delivery and pickup are offered
    $val_full_payment_for_delivery = (
        $val_pickup_delivery === 'company_client_delivery_return' &&
        !empty($data[$opt_full_payment_for_delivery])
    ) ? 1 : 0;


    if ($val_do_not_use_rentopian_shipping == 1) {

        // When NOT using Rentopian shipping, zero all Rentopian-specific delivery flags.
        $val_different_pick_up_addresses = 0;
        $val_double_shipping_fee         = 0;
        $val_free_shipping_amount        = '';
        $val_google_distance_key         = '';
        $val_full_payment_for_delivery   = 0;

    } else {

        $old_pickup_delivery = get_option($opt_pickup_delivery, 'company_delivery_return');
        
        $woocommerce_shipping_zones        = $wpdb->prefix . 'woocommerce_shipping_zones';
        $woocommerce_shipping_zone_methods = $wpdb->prefix . 'woocommerce_shipping_zone_methods';
        
        // Only manipulate shipping zones when the delivery mode actually changes
        if ($val_pickup_delivery !== $old_pickup_delivery) {

            // Company delivery/return only – no local pickup
            if ($val_pickup_delivery === 'company_delivery_return') {

                rental_remove_all_local_pickup( $wpdb, $woocommerce_shipping_zones, $woocommerce_shipping_zone_methods );

                // Remove all zones so Rentopian can recreate them elsewhere
                if (function_exists('rental_empty_shipping_zones')) {
                    rental_empty_shipping_zones();
                }

                // Re-activate miles_based shipping
                if (function_exists('rental_ensure_miles_based_shipping_active')) {
                    rental_ensure_miles_based_shipping_active( $wpdb, $woocommerce_shipping_zone_methods );
                }
            }

            // Client pickup/return only – only Local Pickup
            if ($val_pickup_delivery === 'client_pickup_return') {

                $val_different_pick_up_addresses = 0;
                $val_double_shipping_fee         = 0;
                $val_free_shipping_amount        = '';

                if (function_exists('rental_empty_shipping_zones')) {
                    rental_empty_shipping_zones();
                }

                // Ensure dedicated "Local Pickup" zone exists
                $local_pickup_zone_id = $wpdb->get_col("
                    SELECT 
                        wszm.zone_id
                    FROM 
                        $woocommerce_shipping_zone_methods AS wszm
                    LEFT JOIN $woocommerce_shipping_zones AS zones
                        ON zones.zone_id = wszm.zone_id
                    WHERE 
                        wszm.method_id = 'local_pickup'
                        AND zones.zone_name = 'Local Pickup'
                ");

                if (empty($local_pickup_zone_id)) {
                    $wpdb->insert($woocommerce_shipping_zones, [
                        'zone_name'  => 'Local Pickup',
                        'zone_order' => 9999,
                    ]);

                    $wpdb->insert($woocommerce_shipping_zone_methods, [
                        'zone_id'      => $wpdb->insert_id,
                        'method_id'    => 'local_pickup',
                        'method_order' => 9999,
                        'is_enabled'   => 1,
                    ]);
                }
            }

            // Both company delivery & client pickup
            if ($val_pickup_delivery === 'company_client_delivery_return') {

                rental_ensure_local_pickup_on_all_zones($wpdb, $woocommerce_shipping_zones, $woocommerce_shipping_zone_methods);
                rental_ensure_local_pickup_zone_exists($wpdb, $woocommerce_shipping_zones, $woocommerce_shipping_zone_methods);
                if (function_exists('rental_ensure_miles_based_shipping_active')) {
                    rental_ensure_miles_based_shipping_active( $wpdb, $woocommerce_shipping_zone_methods );
                }
            }

        } else if ($val_pickup_delivery === 'company_client_delivery_return') {
            // Even on re-save with same mode, do a non-destructive check
            // to repair any missing local_pickup methods (self-healing).

            rental_ensure_local_pickup_on_all_zones($wpdb, $woocommerce_shipping_zones, $woocommerce_shipping_zone_methods);
            rental_ensure_local_pickup_zone_exists($wpdb, $woocommerce_shipping_zones, $woocommerce_shipping_zone_methods);
            if (function_exists('rental_ensure_miles_based_shipping_active')) {
                rental_ensure_miles_based_shipping_active( $wpdb, $woocommerce_shipping_zone_methods );
            }

        } else if ($val_pickup_delivery === 'company_delivery_return') {
            // Self-healing on re-save: repair miles_based if it was lost.
            if (function_exists('rental_ensure_miles_based_shipping_active')) {
                rental_ensure_miles_based_shipping_active( $wpdb, $woocommerce_shipping_zone_methods );
            }
        }
       
    }

    // Persist options AFTER we’ve normalised values above
    update_option($opt_do_not_use_rentopian_shipping, $val_do_not_use_rentopian_shipping);
    update_option($opt_track_wc_shipping,             $val_track_wc_shipping);
    update_option($opt_different_pick_up_addresses,   $val_different_pick_up_addresses);
    update_option($opt_double_shipping_fee,           $val_double_shipping_fee);
    update_option($opt_combine_shipping_tax,          $val_combine_shipping_tax);
    update_option($opt_free_shipping_amount,          $val_free_shipping_amount);
    update_option($opt_google_distance_key,           $val_google_distance_key);
    update_option($opt_pickup_delivery,               $val_pickup_delivery);
    update_option($opt_use_google_distance_key,       $val_use_google_distance_key);
    update_option($opt_full_payment_for_delivery,     $val_full_payment_for_delivery);

    // For any other plain rental_* fields in this section, let generic saver handle them
    rental_save_settings_section_generic($data, [
        'exclude_keys' => [
            $opt_do_not_use_rentopian_shipping,
            $opt_track_wc_shipping,
            $opt_different_pick_up_addresses,
            $opt_double_shipping_fee,
            $opt_combine_shipping_tax,
            $opt_free_shipping_amount,
            $opt_google_distance_key,
            $opt_pickup_delivery,
            $opt_use_google_distance_key,
            $opt_full_payment_for_delivery,
        ],
    ]);

    rental_flush_shipping_cache();

    return [
        'message' => __('Delivery settings saved successfully.', 'rentopian-sync'),
    ];
}



/**
 * Validate character limits for "Terms & Messages" style textareas.
 *
 * rules:
 * - Long legal text (terms / policies): max ~8000 chars of *plain text*
 * - Other free-text messages/notes/contents: max ~2000 chars
 *
 * We match fields by name patterns so you don't have to list each key:
 * - Any rental_* field with "terms", "conditions", "policy" in the key
 * - Any rental_* field that ends with _message, _text, _content, _note
 *
 * @param array $data Raw $_POST from this section.
 * @return string[]   List of error messages (empty if ok).
 */
function rental_validate_terms_messages_lengths( array $data ) {
    $errors = [];

    $rules = [
        // Big legal-ish blobs (Terms & Conditions, policies, etc.)
        [
            'pattern' => '/^rental_.*(terms?|conditions?|policy|policies)/i',
            'max_chars' => 8000,        // max plain text characters
            'max_bytes' => 80 * 1024,   // max ~80 KB including HTML
        ],
        // Generic messages, helper texts, notes, etc.
        [
            'pattern' => '/^rental_.*(_message|_text|_content|_note)$/i',
            'max_chars' => 2000,
            'max_bytes' => 16 * 1024,   // e.g. ~16 KB including HTML
        ],
    ];

    foreach ( $data as $key => $value ) {

        if ( !is_string( $key ) || 0 !== strpos( $key, 'rental_' ) ) {
            continue;
        }

        if ( !is_string( $value ) || $value === '' ) {
            continue;
        }

        $raw   = wp_unslash( $value );
        $bytes = strlen( $raw ); // bytes including HTML tags

        foreach ( $rules as $rule ) {
            if ( empty( $rule['pattern'] ) || ! preg_match( $rule['pattern'], $key ) ) {
                continue;
            }

            $plain  = trim( wp_strip_all_tags( $raw ) );
            $length = function_exists( 'mb_strlen' ) ? mb_strlen( $plain ) : strlen( $plain );

            $max_chars = isset( $rule['max_chars'] ) ? (int) $rule['max_chars'] : 0;
            $max_bytes = isset( $rule['max_bytes'] ) ? (int) $rule['max_bytes'] : 0;

            $human_label = ucwords( str_replace(
                ['rental_', '_'],
                ['', ' '],
                $key
            ) );

            if ( $max_chars > 0 && $length > $max_chars ) {
                $errors[] = sprintf(
                    /* translators: 1: Field label, 2: max characters, 3: actual length */
                    __( '%1$s is too long (maximum %2$d characters of text, you have %3$d). Please shorten it.', 'rentopian-sync' ),
                    $human_label,
                    $max_chars,
                    $length
                );
                break;
            }

            if ( $max_bytes > 0 && $bytes > $max_bytes ) {
                $errors[] = sprintf(
                    /* translators: 1: Field label, 2: max KB, 3: actual KB (approx) */
                    __( '%1$s is too large (maximum ~%2$d KB, current size is ~%3$d KB). Please remove some formatting or shorten it.', 'rentopian-sync' ),
                    $human_label,
                    (int) round($max_bytes / 1024),
                    (int) round($bytes / 1024)
                );
                break;
            }
        }
    }

    return $errors;
}


/**
 * Save "Terms & Messages" section settings.
 *
 */
function rental_save_settings_section_terms_messages( array $data ) {

    // Run character limit validation before saving anything
    $errors = rental_validate_terms_messages_lengths( $data );

    if ( !empty( $errors ) ) {
        return [
            'errors'  => $errors,
            'message' => __( 'Please review and shorten the highlighted text fields, then try again.', 'rentopian-sync' ),
        ];
    }

    // Rich-text fields are declared in rental_get_html_settings_keys(); marking
    // the whole payload as HTML here would also skip the generic saver's
    // 'true'/'false' to 1/0 normalization and store checkboxes as strings.
    rental_save_settings_section_generic( $data );

    return [
        'message' => __( 'Terms & messages settings saved successfully.', 'rentopian-sync' ),
    ];
}


/**
 * Save "Sets" section settings.
 *
 */
function rental_save_settings_section_sets( array $data ) {

    // Unchecked checkboxes are absent from the payload, so the generic
    // saver can only ever turn this flag on. Resolve it explicitly to
    // 1/0 first so unchecking persists.
    $opt_hide_variant_product_name = Rental_Sets_Admin_Settings::OPT_HIDE_VARIANT_PRODUCT_NAME;
    update_option(
        $opt_hide_variant_product_name,
        !empty($data[$opt_hide_variant_product_name]) ? 1 : 0
    );

    rental_save_settings_section_generic( $data, [
        'exclude_keys' => [
            $opt_hide_variant_product_name,
        ],
    ] );

    return [
        'message' => __( 'Set settings saved successfully.', 'rentopian-sync' ),
    ];
}

/**
 * Save "Products" section settings.
 *
 */
function rental_save_settings_section_products( array $data ) {

    // Unchecked checkboxes are absent from the payload, so the generic saver
    // can only ever turn this flag on. Resolve it explicitly to 1/0 first so
    // unchecking persists.
    $opt_group_attribute_values = Rental_Attribute_Groups::SETTING;
    update_option(
        $opt_group_attribute_values,
        !empty($data[$opt_group_attribute_values]) ? 1 : 0
    );

    rental_save_settings_section_generic( $data, [
        'exclude_keys' => [
            $opt_group_attribute_values,
        ],
    ] );

    return [
        'message' => __( 'Product settings saved successfully.', 'rentopian-sync' ),
    ];
}

/**
 * Save "Visual Components" section settings.
 *
 */
function rental_save_settings_section_visual_components( array $data ) {

    $opt_hide_product_type_label = 'rental_hide_product_type_label';
    $opt_select_division         = 'rental_select_division';
    $opt_not_open_dates_form     = 'rental_not_open_dates_form';
    $opt_auto_date_show_product_selection = 'rental_auto_date_show_product_selection';
    $opt_auto_add_to_cart_on_date_selection = 'rental_auto_add_to_cart_on_date_selection';

    $val_select_division         = !empty($data[$opt_select_division]) ? 1 : 0;
    $val_not_open_dates_form     = !empty($data[$opt_not_open_dates_form]) ? 1 : 0;
    $val_auto_date_show_product_selection = !empty($data[$opt_auto_date_show_product_selection]) ? 1 : 0;
    $val_auto_add_to_cart_on_date_selection = !empty($data[$opt_auto_add_to_cart_on_date_selection]) ? 1 : 0;

    // Only the pages that render this field can turn it off. It defaults to on
    // and is hidden on sale-only and hourly-only sites, so writing it from a
    // payload that could never carry it would clear it on every save.
    if (rental_settings_rental_fields_rendered()) {
        update_option($opt_hide_product_type_label, !empty($data[$opt_hide_product_type_label]) ? 1 : 0);
    }

    update_option($opt_select_division,         $val_select_division);
    update_option($opt_not_open_dates_form,     $val_not_open_dates_form);
    update_option($opt_auto_date_show_product_selection, $val_auto_date_show_product_selection);
    update_option($opt_auto_add_to_cart_on_date_selection, $val_auto_add_to_cart_on_date_selection);

    rental_save_settings_section_generic($data, [
        'exclude_keys' => [
            $opt_hide_product_type_label,
            $opt_select_division,
            $opt_not_open_dates_form,
            $opt_auto_date_show_product_selection,
            $opt_auto_add_to_cart_on_date_selection,
        ],
    ]);

    return [
        'message' => __( 'Visual components settings saved successfully.', 'rentopian-sync' ),
    ];
}



/**
 * Save "Textual Labels" section settings.
 *
 * All rental_* keys in this section are treated as textual labels
 * and are limited to 200 characters (after stripping HTML tags).
 */
function rental_save_settings_section_text_labels( array $data ) {

    $errors    = [];
    $html_keys = [];

    foreach ( $data as $key => $value ) {
        if ( !is_string( $key ) ) {
            continue;
        }

        // Only actual label options: rental_* but skip the section nonce
        if ( 0 !== strpos( $key, 'rental_' ) ) {
            continue;
        }
        if ( 'rental_settings_section_nonce' === $key ) {
            continue;
        }

        $html_keys[] = $key;

        // Build a flat string for length calculation
        if ( is_array( $value ) ) {
            $value_for_length = implode( ' ', array_map( 'wp_unslash', (array) $value ) );
        } else {
            $value_for_length = wp_unslash( (string) $value );
        }

        // Textual label rule: max 200 characters, no explicit byte limit.
        $validation = rental_validate_text_length(
            $value_for_length,
            200,
            0,
            sprintf( __( 'Label "%s"', 'rentopian-sync' ), $key )
        );

        if ( $validation['error'] ) {
            $errors[] = $validation['error'];
        }
    }

    if ( !empty( $errors ) ) {
        // Bubble up to rental_handle_settings_section_save() as a section error
        return [
            'error'   => implode( "\n", array_unique( $errors ) ),
            'message' => null,
        ];
    }

    rental_save_settings_section_generic( $data, [
        'html_keys' => $html_keys,
    ] );

    return [
        'message' => __( 'Text labels saved successfully.', 'rentopian-sync' ),
    ];
}





function rental_save_settings_section_tiers(array $data) {
    global $wpdb, $rental_tables;
    $rental_day_tiers = $wpdb->prefix . $rental_tables["day_tiers"];

    if (!isset($data['rental_day_tiers'])) {
        return [
            'error' => __('Missing tiers payload.', 'rentopian-sync'),
        ];
    }

    $tiers = json_decode(stripslashes($data['rental_day_tiers']));

    $error = null;
    $wpdb->query("DELETE FROM $rental_day_tiers");

    if ( !empty($tiers)) {
        $count = count($tiers);
        $tiers_sql = [];
        for ($i = 0; $i < $count; $i++) {
            if ( !isset($tiers[$i]->min) || !isset($tiers[$i]->max) || !isset($tiers[$i]->day)
                || ($i && $tiers[$i]->min != $tiers[$i - 1]->max)
                || ( !$tiers[$i]->max || $tiers[$i]->min >= $tiers[$i]->max)) {
                $error = "Wrong days calculation rules";
                break;
            }

            $tiers_sql[] = "(". $tiers[$i]->min .", ". $tiers[$i]->max .", ". $tiers[$i]->day .")";
        }

        if ( !$error) {
            rental_insert("INSERT INTO `$rental_day_tiers` (`min`, `max`, `day`) VALUES", $tiers_sql);
        }
    }

    if ($error) {
        return ['error' => $error];
    }

    return [
        'message' => __('Multiplication tiers saved successfully.', 'rentopian-sync'),
        'test' => $tiers,
    ];
}
