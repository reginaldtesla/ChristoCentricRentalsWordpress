<?php
if (defined('RENTOPIAN_SYNC_DATE_FORM_MODERN')) {
    return;
}
define('RENTOPIAN_SYNC_DATE_FORM_MODERN', true);

$tz = rental_get_timezone();
if (!$tz) {
    $tz_option = get_option('timezone_string') ?: get_option('gmt_offset');
    $tz = $tz_option ? new DateTimeZone($tz_option) : null;
}
$today = (new DateTime('now', $tz))->format('Y/m/d');

$decrypted_rental_start_date = "";
if (isset($_COOKIE['rental_start_date']) && $_COOKIE['rental_start_date']) {
    $decrypted_rental_start_date = decrypt_data($_COOKIE['rental_start_date'], get_option('rental_encryption_key'));
    // FALLBACK: If decryption returned the same value (meaning it's plain text), check format and convert if needed
    // JavaScript displayFormat is 'MMM D, h:mm A' (e.g., "Jan 4, 10:00 AM")
    // JavaScript serverDateFormat is 'YYYY/MM/DD h:mm A' (e.g., "2026/01/04 10:00 AM")
    if ($decrypted_rental_start_date && $decrypted_rental_start_date === $_COOKIE['rental_start_date']) {
        // Check if already in server format (starts with year like "2026/")
        if (preg_match('/^\d{4}\//', $decrypted_rental_start_date)) {
            // Already in server format, use as-is
        } else {
            // Cookie is in plain text, might be in display format - try to parse and convert
            // Try format: "Jan 4, 10:00 AM" or "Jan 04, 10:00 AM"
            $parsed = date_create_from_format('M j, g:i A', $decrypted_rental_start_date);
            if ($parsed === false) {
                $parsed = date_create_from_format('M d, g:i A', $decrypted_rental_start_date);
            }
            if ($parsed === false) {
                // Try with h instead of g (12-hour with leading zero)
                $parsed = date_create_from_format('M j, h:i A', $decrypted_rental_start_date);
            }
            if ($parsed !== false) {
                //  If the parsed date is in the past relative to today, assume it's next year
                // This handles cases where user selects dates like "Jan 4" in December (should be Jan 4 of next year)
                $parsed_year = (int)$parsed->format('Y');
                $parsed_month = (int)$parsed->format('m');
                $parsed_day = (int)$parsed->format('d');
                $today_year = (int)date('Y');
                $today_month = (int)date('m');
                $today_day = (int)date('d');
                
                // If the parsed date (with current year) is before today, it's likely next year
                if ($parsed_year === $today_year) {
                    if ($parsed_month < $today_month || ($parsed_month === $today_month && $parsed_day < $today_day)) {
                        $parsed->modify('+1 year');
                    }
                }
                
                $decrypted_rental_start_date = $parsed->format('Y/m/d g:i A');
            }
        }
    }
}

$decrypted_rental_end_date = "";
if (isset($_COOKIE['rental_end_date']) && $_COOKIE['rental_end_date']) {
    $decrypted_rental_end_date = decrypt_data($_COOKIE['rental_end_date'], get_option('rental_encryption_key'));
    // FALLBACK: If decryption returned the same value (meaning it's plain text), check format and convert if needed
    // JavaScript displayFormat is 'MMM D, h:mm A' (e.g., "Jan 4, 10:00 AM")
    // JavaScript serverDateFormat is 'YYYY/MM/DD h:mm A' (e.g., "2026/01/04 10:00 AM")
    if ($decrypted_rental_end_date && $decrypted_rental_end_date === $_COOKIE['rental_end_date']) {
        // Check if already in server format (starts with year like "2026/")
        if (preg_match('/^\d{4}\//', $decrypted_rental_end_date)) {
            // Already in server format, use as-is
        } else {
            // Cookie is in plain text, might be in display format - try to parse and convert
            // Try format: "Jan 4, 10:00 AM" or "Jan 04, 10:00 AM"
            $parsed = date_create_from_format('M j, g:i A', $decrypted_rental_end_date);
            if ($parsed === false) {
                $parsed = date_create_from_format('M d, g:i A', $decrypted_rental_end_date);
            }
            if ($parsed === false) {
                // Try with h instead of g (12-hour with leading zero)
                $parsed = date_create_from_format('M j, h:i A', $decrypted_rental_end_date);
            }
            if ($parsed !== false) {
                // If the parsed date is in the past relative to today, assume it's next year
                // This handles cases where user selects dates like "Jan 8" in December (should be Jan 8 of next year)
                $parsed_year = (int)$parsed->format('Y');
                $parsed_month = (int)$parsed->format('m');
                $parsed_day = (int)$parsed->format('d');
                $today_year = (int)date('Y');
                $today_month = (int)date('m');
                $today_day = (int)date('d');
                
                // If the parsed date (with current year) is before today, it's likely next year
                if ($parsed_year === $today_year) {
                    if ($parsed_month < $today_month || ($parsed_month === $today_month && $parsed_day < $today_day)) {
                        $parsed->modify('+1 year');
                    }
                }
                
                $decrypted_rental_end_date = $parsed->format('Y/m/d g:i A');
            }
        }
    }
}

$decrypted_rental_zip = 0;
if (isset($_COOKIE['rental_zip']) && $_COOKIE['rental_zip']) {
    $decrypted_rental_zip = decrypt_data($_COOKIE['rental_zip'], get_option('rental_encryption_key'));
}

$decrypted_rental_address = "";
if (isset($_COOKIE['rental_address']) && $_COOKIE['rental_address']) {
    $decrypted_rental_address = decrypt_data($_COOKIE['rental_address'], get_option('rental_encryption_key'));
}

$opt_default_start_time = get_option('rental_default_start_time', '09:00 AM');
$opt_default_end_time   = get_option('rental_default_end_time', '05:00 PM');

/**
 * Sale type behaviour (sets default dates and cookies then returns, no visible form)
 */
if (get_option('rental_synchronized_product_type') == "sale") {

    if (
        (!isset($_COOKIE['rental_start_date']) || date('Y/m/d', strtotime($decrypted_rental_start_date)) != $today)
    ) {

        try {

            if (strtotime($opt_default_end_time) > strtotime($opt_default_start_time)) {
                $end_date = $today;
            } else {
                $end_date = date('Y/m/d', strtotime("$today +1 day"));
            }

            $today_start_time   = $today . ' ' . $opt_default_start_time;
            $end_date_end_time  = $end_date . ' ' . $opt_default_end_time;

            update_option('rental_product_settings', rental_get_product_settings());

            $start_end_date_expire_time = time() + RENTOPIAN_DATE_EXPIRE_TIME;

            $encrypted_rental_start_date = encrypt_data($today_start_time, get_option('rental_encryption_key'));
            $_COOKIE['rental_start_date'] = $encrypted_rental_start_date;
            setcookie('rental_start_date', $encrypted_rental_start_date, $start_end_date_expire_time, "/", "", false, true);

            $encrypted_rental_end_date = encrypt_data($end_date_end_time, get_option('rental_encryption_key'));
            $_COOKIE['rental_end_date'] = $encrypted_rental_end_date;
            setcookie('rental_end_date', $encrypted_rental_end_date, $start_end_date_expire_time, "/", "", false, true);

            $encrypted_rental_zip = encrypt_data(true, get_option('rental_encryption_key'));
            $_COOKIE['rental_zip'] = $encrypted_rental_zip;
            setcookie('rental_zip', $encrypted_rental_zip, $start_end_date_expire_time, "/", "", false, false);

            $decrypted_rental_start_date = decrypt_data($_COOKIE['rental_start_date'], get_option('rental_encryption_key'));
            $decrypted_rental_end_date   = decrypt_data($_COOKIE['rental_end_date'], get_option('rental_encryption_key'));
            $decrypted_rental_zip        = decrypt_data($_COOKIE['rental_zip'], get_option('rental_encryption_key'));

        } catch (RentalException $e) {

            session_unset();
            session_destroy();

            if (isset($_SERVER['HTTP_COOKIE'])) {
                $cookies = explode(';', $_SERVER['HTTP_COOKIE']);
                foreach ($cookies as $cookie) {
                    $parts = explode('=', $cookie);
                    $name  = trim($parts[0]);
                    setcookie($name, '', time() - 31556952);
                    setcookie($name, '', time() - 31556952, '/');
                }
            }

            ErrorHandler::registerErrorInLog(
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                $e->getType(),
                null,
                $e->getStatusCode()
            );
            ?>
            <div class="rntp-notification rntp-notification--error">
                <a href class="rntp-notification__close color--error">×</a>
                <div class="rntp-notification__status rntp-bg--gradient-red">
                    &times;
                </div>
                <div class="rntp-notification__content">
                    <p class="rntp-notification__text"><?php echo esc_html($e->getMessage()); ?></p>
                </div>
            </div>
            <?php
        }
    }
    return;
}

/**
 * Expired dates
 * Only clear cookies if the start date no longer clears the orders offset.
 * Compare date parts only, not time, to avoid false positives
 */
if (isset($_COOKIE['rental_start_date']) && $decrypted_rental_start_date) {
    if (rental_start_date_violates_offset($decrypted_rental_start_date)) {
        unset(
            $_COOKIE['rental_start_date'],
            $_COOKIE['rental_end_date']
        );

        update_option('rental_product_settings', '');

        setcookie('rental_start_date', '', time() - (31556952), "/", "", false, true);
        setcookie('rental_end_date', '', time() - (31556952), "/", "", false, true);
    }
}

/**
 * Options / labels
 */
$opt_hide_zip        = get_option('rental_hide_zip');
$custom_cart_label   = get_option('rental_cart_button_text') ? lcfirst(get_option('rental_cart_button_text')) : 'cart';
$select_dates_text   = __('Please select the dates' . ($opt_hide_zip ? '' : ' and zip code') . ' so that you can add a product to the '.$custom_cart_label.'!', 'rentopian-sync');
$form_layout         = get_option('rental_form_layout') ? get_option('rental_form_layout') : 'horizontal';

/**
 * This branch only affects in-cart layout / product page.
 */
if ( get_option('rental_dates_on_checkout', 0) != 1 || get_option('rental_allow_overbook', 1) != 1) {
    if ($form_layout === 'in-cart'):
        if (!(isset($_COOKIE['rental_start_date']) && $_COOKIE['rental_start_date'] && isset($_COOKIE['rental_zip'])) && is_product()):?>
            <div class="rntp-notification">
                <div class="rntp-notification__content">
                    <p class="rntp-notification__text">
                        <span <?php if ( !get_option('rental_not_open_dates_form')): ?>id="rental_open_mini_cart"<?php endif; ?> data-show-error="1">
                            <?php echo esc_html($select_dates_text); ?>
                        </span>
                    </p>
                </div>
            </div>
        <?php elseif (isset($_COOKIE['rental_product_added_to_cart_message']) && !empty($_COOKIE['rental_product_added_to_cart_message'])): ?>
            <div class="rntp-notification rntp-notification--success">
                <div class="rntp-notification__content">
                    <p class="rntp-notification__text">
                        <span id="rental_open_mini_cart">
                            <?php
                                echo esc_html($_COOKIE['rental_product_added_to_cart_message']);
                                unset($_COOKIE['rental_product_added_to_cart_message']);
                                setcookie('rental_product_added_to_cart_message', '', time() - (31556952), "/", "", false, true);
                            ?>
                        </span>
                    </p>
                </div>
            </div>
        <?php endif;
        return;
    endif;
}

/**
 * Product-page notifications
 */
if (
    !(isset($_COOKIE['rental_start_date']) && $_COOKIE['rental_start_date'] && isset($_COOKIE['rental_zip']))
    && is_product()
):
    ?>
    <div class="rntp-notification">
        <div class="rntp-notification__content">
            <p class="rntp-notification__text"><?php echo esc_html($select_dates_text); ?></p>
        </div>
    </div>
<?php elseif (!empty($_COOKIE['rental_product_added_to_cart_message'])): ?>
    <div class="rntp-notification rntp-notification--success">
        <div class="rntp-notification__content">
            <p class="rntp-notification__text">
                <?php
                    echo esc_html($_COOKIE['rental_product_added_to_cart_message']);
                    unset($_COOKIE['rental_product_added_to_cart_message']);
                    setcookie('rental_product_added_to_cart_message', '', time() - (31556952), "/", "", false, true);
                ?>
            </p>
        </div>
    </div>
<?php endif;

/**
 * Values to feed the dates fields.
 */
$dates_on_checkout = 0;
$start_date = $end_date = $zip = '';
if (isset($_COOKIE['rental_start_date']) && $decrypted_rental_start_date) {
    $start_date = $decrypted_rental_start_date;
    if (function_exists('rental_get_decrypted_event_date_for_display')) {
        $rntp_ev_disp = rental_get_decrypted_event_date_for_display();
        if ($rntp_ev_disp !== '') {
            $start_date = $rntp_ev_disp;
        }
    }
}
if (isset($_COOKIE['rental_end_date']) && $decrypted_rental_end_date) {
    $end_date = $decrypted_rental_end_date;
}
if (isset($_COOKIE['rental_zip'])) {
    $dates_on_checkout = get_option('rental_dates_on_checkout', 0) == 1 && get_option('rental_allow_overbook', 1) == 1 ? 1 : 0;
    if ( !rental_is_date_form_filled() && $dates_on_checkout === 1) {
        $zip = '';
    } else {
        $zip = $decrypted_rental_zip;
    }
}

$opt_show_location     = get_option('rental_show_location');
$zipcode_included      = !$opt_hide_zip ? 'zipcode-included' : '';
$opt_hide_end_date     = get_query_var('product_type') == 'sale' || get_option('rental_hide_end_date');
$opt_hide_time_pickers = get_option('rental_hide_time_pickers');
$opt_disabled_week_days = get_option('rental_disabled_week_days', '');
// Ensure empty arrays become empty string, not the literal "Array"
if (is_array($opt_disabled_week_days) && !empty($opt_disabled_week_days)) {
    $opt_disabled_week_days = json_encode($opt_disabled_week_days);
} else {
    $opt_disabled_week_days = '';
}

$rntp_form_filled = rental_is_date_form_filled() ? ' rntp-form-filled' : '';

/**
 * Default start/end labels
 */
$today_start = $today_end = '';
$rntp_min_start_date = rental_get_earliest_start_date()->format('Y/m/d');
$rntp_default_start = $rntp_default_end = '';
$opt_rental_select_a_day_by_default = get_option('rental_select_a_day_by_default', 0);

if ($opt_rental_select_a_day_by_default == 1 && $dates_on_checkout != 1) {

    $default_start_day = rental_get_default_start_date();
    $default_end_day   = rental_get_default_end_date($opt_hide_end_date);

    if ($default_start_day && $opt_default_start_time) {
        $today_start        = $default_start_day->format('M j') . ', ' . $opt_default_start_time;
        $rntp_default_start = $default_start_day->format('Y/m/d') . ' ' . $opt_default_start_time;
    }
    if ($default_end_day && !$opt_hide_end_date && !empty($opt_default_end_time)) {
        $today_end        = $default_end_day->format('M j') . ', ' . $opt_default_end_time;
        $rntp_default_end = $default_end_day->format('Y/m/d') . ' ' . $opt_default_end_time;
    }

} else {

    // No default day configured: the field still opens on the earliest
    // selectable day rather than on today.
    $earliest_day = rental_get_earliest_start_date();

    $today_start        = $earliest_day->format('M j') . ', ' . $opt_default_start_time;
    $rntp_default_start = $earliest_day->format('Y/m/d') . ' ' . $opt_default_start_time;
}

if (isset($_COOKIE['rental_start_date'])) {
    $date_format = 'M j';
    if (!$opt_hide_time_pickers) {
        $date_format .= ', g:i A';
    }
    $today_end = date($date_format, strtotime($decrypted_rental_end_date));
}

/**
 * Labels and date-step option.
 */
$opt_start_date_text = get_option('rental_start_date_text');
$opt_end_date_text   = get_option('rental_end_date_text');
$opt_start_date_text = $opt_start_date_text ? $opt_start_date_text : 'Start Date';
$opt_end_date_text   = $opt_end_date_text ? $opt_end_date_text : 'Return Date';

$opt_date_step = get_option('rental_date_step');

/**
 * Multi Day Event
 * Uses dedicated cookie 'rental_multi_day_preference' to track user's checkbox choice
 */
$opt_multi_day_event_enabled = get_option('rental_multi_day_event', 0);

/**
 * Determine initial checkbox state based on user's preference cookie
 * The checkbox state is now independent of end_date cookie existence
 * We use a dedicated cookie 'rental_multi_day_preference' to track user's choice
 */
$multi_day_checked = '';
if (isset($_COOKIE['rental_multi_day_preference']) && $_COOKIE['rental_multi_day_preference'] === '1') {
    $multi_day_checked = 'checked';
}

/**
 * End date visibility logic:
 * 
 * 1. If rental_hide_end_date is true: ALWAYS hide end date
 * 2. If rental_multi_day_event feature is DISABLED: ALWAYS show end date (regular rental behavior)
 * 3. If rental_multi_day_event feature is ENABLED:
 *    - Show end date only if checkbox is checked (cookie = '1')
 *    - Hide end date if checkbox is unchecked (no cookie or cookie = '0')
 */
$end_date_initial_display = '';
if ($opt_hide_end_date) {
    // Admin has permanently hidden end date via rental_hide_end_date setting
    $end_date_initial_display = 'style="display:none;"';
} elseif ($opt_multi_day_event_enabled) {
    // Multi-day feature is ENABLED - visibility depends on checkbox state
    if ($multi_day_checked === '') {
        // Checkbox is unchecked (no cookie or cookie = '0'), hide end date
        $end_date_initial_display = 'style="display:none;"';
    }
    // If checkbox is checked ($multi_day_checked === 'checked'), show end date (empty string)
}
// If multi-day feature is DISABLED ($opt_multi_day_event_enabled == 0),
// end date is always visible (regular rental behavior) - $end_date_initial_display stays empty

/**
 * EVENT DATE OFFSET: Ensure rentalDateOffsets is available for rental-date-form-modern.js.
 */
wp_localize_script('rental-date-form-modern', 'rentalDateOffsets', array(
    'startDateOffset'  => (int) get_option('rental_start_date_offset', 0),
    'endDateOffset'    => (int) get_option('rental_end_date_offset', 0),
    'defaultStartTime' => get_option('rental_default_start_time', '9:00 AM'),
    'defaultEndTime'   => get_option('rental_default_end_time', '05:00 PM'),
    'hideEndDate'      => (int) get_option('rental_hide_end_date', 0),
    'hideZip'          => (int) get_option('rental_hide_zip', 0),
));
?>

<div id="rental_date_form_modern" 
     class="rntp-rental-form rntp-rental-form-modern<?php echo esc_attr($rntp_form_filled); ?>"
     data-debug-hide-end="<?php echo $opt_hide_end_date ? '1' : '0'; ?>"
     data-debug-multi-day-enabled="<?php echo $opt_multi_day_event_enabled ? '1' : '0'; ?>"
     data-debug-multi-day-checked="<?php echo $multi_day_checked ? '1' : '0'; ?>"
     data-debug-end-display="<?php echo esc_attr($end_date_initial_display); ?>">
    <div class="rntp-form-wrapper <?php echo esc_attr($zipcode_included); ?>">

        <?php
        /* if ($opt_show_location): ?>
            <p class="form-row form-row-wide rntp-address-block" id="rental_address_field">
                <label for="rental_address"><?php _e('Location', 'rentopian-sync'); ?></label>
                <span class="woocommerce-input-wrapper">
                    <input type="text"
                           name="rental_address"
                           id="rental_address"
                           value="<?php echo isset($_COOKIE['rental_address']) ? esc_attr($decrypted_rental_address) : ''; ?>"
                           class="address-form rntp-address" />
                </span>
            </p>
        <?php endif; */ 
        ?>

        <p class="form-row form-row-first col-sm-6 validate-required rntp-start-date-block"
            id="rental_start_date_field"
            data-priority="15">

            <label for="rental_start_date" class="required_field">
                <?php echo esc_html($opt_start_date_text); ?>&nbsp;
                <span class="required" aria-hidden="true">*</span>
            </label>
            <span class="woocommerce-input-wrapper">
                <input type="text"
                    class="input-text date-form rntp-date"
                    name="start_date"
                    id="rental_start_date"
                    placeholder="<?php echo esc_attr($opt_start_date_text); ?>"
                    required="required"
                    data-start-date-default="<?php echo esc_attr($today_start); ?>"
                    data-end-date-default="<?php echo esc_attr($today_end); ?>"
                    data-end-date="<?php echo esc_attr($end_date); ?>"
                    data-hide-end="<?php echo $opt_hide_end_date ? 1 : 0; ?>"
                    data-hide-time="<?php echo (bool) $opt_hide_time_pickers; ?>"
                    data-min-start="<?php echo esc_attr(get_option('rental_min_start_date')); ?>"
                    data-min-start-date="<?php echo esc_attr($rntp_min_start_date); ?>"
                    data-default-start="<?php echo esc_attr($rntp_default_start); ?>"
                    data-default-end="<?php echo esc_attr($rntp_default_end); ?>"
                    data-disabled-week-days="<?php echo !empty($opt_disabled_week_days) ? esc_attr($opt_disabled_week_days) : ''; ?>"
                    data-value="<?php echo esc_attr($start_date); ?>"
                    data-start-date-offset="<?php echo esc_attr((int) get_option('rental_start_date_offset', 0)); ?>"
                    data-end-date-offset="<?php echo esc_attr((int) get_option('rental_end_date_offset', 0)); ?>"
                    />
            </span>
            <span class="form-error" style="display:none;"><?php esc_html_e('This field is required.', 'rentopian-sync'); ?></span>
        </p>

        <?php 
        /**
         * Multi Day Event Checkbox
         * Only show if:
         * 1. Admin has enabled the feature (rental_multi_day_event option)
         * 2. End date is not permanently hidden (rental_hide_end_date)
         * 
         * Checkbox state persists via 'rental_multi_day_preference' cookie
         * This cookie is set by JavaScript when user toggles the checkbox
         * 
         * Functionality:
         * - When checked: Shows end date field and makes it required
         * - When unchecked: Hides end date field and removes requirement
         * - State persists independently of end_date cookie (which always exists)
         */
        if ($opt_multi_day_event_enabled && !$opt_hide_end_date): 
        ?>
            <p class="form-row form-row-wide col-sm-12 rntp-multi-day-toggle" 
               id="rental_multi_day_field"
               data-priority="15.5">
                <label class="woocommerce-form__label woocommerce-form__label-for-checkbox checkbox">
                    <input type="checkbox" 
                           class="woocommerce-form__input woocommerce-form__input-checkbox input-checkbox" 
                           name="rental_multi_day_event" 
                           id="rental_multi_day_event"
                           <?php echo $multi_day_checked; ?> />
                    <span><?php esc_html_e('Multi Day Event', 'rentopian-sync'); ?></span>
                </label>
            </p>
        <?php endif; ?>

        <?php if (!$opt_hide_end_date): ?>
            <?php if ($opt_date_step && get_option('rental_select_date_range')): ?>
                <p class="form-row form-row-last col-sm-6 validate-required rntp-end-date-block rntp-end-date-conditional"
                   id="rental_end_date_field"
                   data-priority="16"
                   <?php echo $end_date_initial_display; ?>>
                    <label for="rental_end_date" class="required_field">
                        <?php _e('Date Range', 'rentopian-sync'); ?>&nbsp;
                        <span class="required" aria-hidden="true">*</span>
                    </label>
                    <span class="woocommerce-input-wrapper">
                        <select class="select date-form rntp-date"
                                name="end_date"
                                id="rental_end_date"
                                <?php echo $multi_day_checked ? 'required="required"' : ''; ?>
                                data-range="1"
                                data-min-rage="<?php echo esc_attr(get_option('rental_min_dates_range')); ?>"
                                data-max-rage="<?php echo esc_attr(get_option('rental_max_dates_range')); ?>"
                                data-date-step="<?php echo esc_attr($opt_date_step); ?>"
                                data-value="<?php echo esc_attr($end_date); ?>">
                        </select>
                    </span>
                    <span class="form-error" style="display:none;"><?php esc_html_e('This field is required.', 'rentopian-sync'); ?></span>
                </p>
            <?php else: ?>
                <p class="form-row form-row-last col-sm-6 validate-required rntp-end-date-block rntp-end-date-conditional"
                   id="rental_end_date_field"
                   data-priority="16"
                   <?php echo $end_date_initial_display; ?>>
                    <label for="rental_end_date" class="required_field">
                        <?php echo esc_html($opt_end_date_text); ?>&nbsp;
                        <span class="required" aria-hidden="true">*</span>
                    </label>
                    <span class="woocommerce-input-wrapper">
                        <input type="text"
                            class="input-text date-form rntp-date"
                            name="end_date"
                            id="rental_end_date"
                            placeholder="<?php echo esc_attr($opt_end_date_text); ?>"
                            <?php echo $multi_day_checked ? 'required="required"' : ''; ?>
                            data-min-rage="<?php echo esc_attr(get_option('rental_min_dates_range')); ?>"
                            data-max-rage="<?php echo esc_attr(get_option('rental_max_dates_range')); ?>"
                            data-date-step="<?php echo esc_attr($opt_date_step); ?>"
                            data-value="<?php echo esc_attr($end_date); ?>" />
                    </span>
                    <span class="form-error" style="display:none;"><?php esc_html_e('This field is required.', 'rentopian-sync'); ?></span>
                </p>
            <?php endif; ?>
        <?php endif; ?>

        <?php if (!$opt_hide_zip): ?>
            <p class="form-row form-row-wide col-sm-12 validate-required rntp-zipcode-block"
            id="rental_zip_field"
            data-priority="17">
                <label for="rental_zip" class="required_field">
                    <?php _e('ZIP Code', 'rentopian-sync'); ?>&nbsp;
                    <span class="required" aria-hidden="true">*</span>
                </label>
                <span class="woocommerce-input-wrapper">
                    <input type="text"
                        class="input-text date-form"
                        name="zip"
                        id="rental_zip"
                        placeholder="<?php esc_attr_e('ZIP Code', 'rentopian-sync'); ?>"
                        required="required"
                        value="<?php echo esc_attr($zip); ?>" />
                </span>
                <span class="form-error" style="display:none;"><?php esc_html_e('This field is required.', 'rentopian-sync'); ?></span>
            </p>
        <?php endif; ?>
    </div>
    <?php
    if (function_exists('rental_get_event_date_offsets') && rental_get_event_date_offsets() && function_exists('rental_render_rental_period_details_shell')) {
        rental_render_rental_period_details_shell(
            array(
                'display' => 'none',
                'variant' => 'modern_div',
            )
        );
    }
    ?>
</div>