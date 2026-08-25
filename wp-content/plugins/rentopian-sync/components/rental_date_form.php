<?php  
if (defined('RENTOPIAN_SYNC_DATE_FORM')) {
    return;
}
define('RENTOPIAN_SYNC_DATE_FORM', true);

$tz = rental_get_timezone();
if ( !$tz) {
    $tz = get_option('timezone_string')?: get_option('gmt_offset');
    $tz = $tz? new DateTimeZone($tz): null;
}
$today = (new DateTime('now', $tz))->format('Y/m/d');

$decrypted_rental_start_date = "";
if (isset($_COOKIE['rental_start_date']) && $_COOKIE['rental_start_date']) {
    $decrypted_rental_start_date = decrypt_data($_COOKIE['rental_start_date'], get_option('rental_encryption_key'));
}

$decrypted_rental_end_date = "";
if (isset($_COOKIE['rental_end_date']) && $_COOKIE['rental_end_date']) {
    $decrypted_rental_end_date = decrypt_data($_COOKIE['rental_end_date'], get_option('rental_encryption_key'));
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
$opt_default_end_time = get_option('rental_default_end_time', '05:00 PM');

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

            $today_start_time = $today . ' ' . $opt_default_start_time;
            $end_date_end_time = $end_date . ' ' . $opt_default_end_time;

            update_option('rental_product_settings', rental_get_product_settings());
            // update_option('rental_product_settings', rental_request_product_settings());

            $start_end_date_expire_time = time() + RENTOPIAN_DATE_EXPIRE_TIME;

            // update cookies
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
            $decrypted_rental_end_date = decrypt_data($_COOKIE['rental_end_date'], get_option('rental_encryption_key'));
            $decrypted_rental_zip = decrypt_data($_COOKIE['rental_zip'], get_option('rental_encryption_key'));

        } catch (RentalException $e) {
            // session close
            session_unset();
            session_destroy();

            // unset all cookies
            if (isset($_SERVER['HTTP_COOKIE'])) {
                $cookies = explode(';', $_SERVER['HTTP_COOKIE']);
                foreach($cookies as $cookie) {
                    $parts = explode('=', $cookie);
                    $name = trim($parts[0]);
                    setcookie($name, '', time()-31556952);
                    setcookie($name, '', time()-31556952, '/');
                }
            }

            ErrorHandler::registerErrorInLog($e->getMessage(), $e->getFile(), $e->getLine(), $e->getType(), null, $e->getStatusCode());
            ?>
            <div class="rntp-notification rntp-notification--error">
                <a href class="rntp-notification__close color--error">×</a>
                <div class="rntp-notification__status rntp-bg--gradient-red">
                    &times;
                </div>
                <div class="rntp-notification__content">
                    <p class="rntp-notification__text"><?php echo $e->getMessage(); ?></p>
                </div>
            </div>
            <?php
        }
    }
    return;
}

// Drop dates that no longer clear the orders offset, not just dates in the past.
if (isset($_COOKIE['rental_start_date']) && $decrypted_rental_start_date && rental_start_date_violates_offset($decrypted_rental_start_date)) {

    unset(
        $_COOKIE['rental_start_date'],
        $_COOKIE['rental_end_date']
    );

    update_option('rental_product_settings', '');

    setcookie('rental_start_date', '', time() - (31556952), "/", "", false, true);
    setcookie('rental_end_date', '', time() - (31556952), "/", "", false, true);

}

$opt_hide_zip = get_option('rental_hide_zip');
$custom_cart_label = get_option('rental_cart_button_text') ? lcfirst(get_option('rental_cart_button_text')) : 'cart'; 
$select_dates_text = __('Please select the dates' . ($opt_hide_zip? '': ' and zip code') . ' so that you can add a product to the '.$custom_cart_label.'!', 'rentopian-sync');
$form_layout =  get_option('rental_form_layout') ? get_option('rental_form_layout') : 'horizontal';

if ( get_option('rental_dates_on_checkout', 0) != 1 || get_option('rental_allow_overbook', 1) != 1) {
    if($form_layout === 'in-cart'):
        if (!(isset($_COOKIE['rental_start_date']) && $_COOKIE['rental_start_date'] && isset($_COOKIE['rental_zip'])) && is_product()):?>
            <div class="rntp-notification">
                <div class="rntp-notification__content">
                    <p class="rntp-notification__text">
                        <span <?php if ( !get_option('rental_not_open_dates_form')): ?>id="rental_open_mini_cart"<?php endif; ?>data-show-error="1">
                            <?php echo $select_dates_text; ?>
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
                            echo $_COOKIE['rental_product_added_to_cart_message'];
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

if ( 
    !(isset($_COOKIE['rental_start_date']) && $_COOKIE['rental_start_date'] && isset($_COOKIE['rental_zip']))
    && is_product() 
): 
?>
    <div class="rntp-notification">
        <div class="rntp-notification__content">
            <p class="rntp-notification__text"><?php echo $select_dates_text; ?></p>
        </div>
    </div>
<?php elseif (!empty($_COOKIE['rental_product_added_to_cart_message'])): ?>
    <div class="rntp-notification rntp-notification--success">
        <div class="rntp-notification__content">
            <p class="rntp-notification__text">
                <?php 
                    echo $_COOKIE['rental_product_added_to_cart_message'];
                    unset($_COOKIE['rental_product_added_to_cart_message']); 
                    setcookie('rental_product_added_to_cart_message', '', time() - (31556952), "/", "", false, true);
                ?>
            </p>
        </div>
    </div>
<?php endif; 

?>

<?php
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
if (isset($_COOKIE['rental_end_date'])) {
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

$opt_show_location = get_option('rental_show_location');
$zipcode_included = !$opt_hide_zip ? 'zipcode-included' : '';
$opt_hide_zip = get_option('rental_hide_zip');
$opt_hide_end_date = get_query_var('product_type') == 'sale' || get_option('rental_hide_end_date');
$opt_hide_time_pickers = get_option('rental_hide_time_pickers');
$opt_disabled_week_days = get_option('rental_disabled_week_days', '');

// Ensure empty arrays become empty string, not the literal "Array"
if (is_array($opt_disabled_week_days) && !empty($opt_disabled_week_days)) {
    $opt_disabled_week_days = json_encode($opt_disabled_week_days);
} else {
    $opt_disabled_week_days = '';
}

$rntp_form_filled = rental_is_date_form_filled() ? ' rntp-form-filled' : '';


// Earliest selectable day and the day preselected inside it.
$today_start = $today_end = '';
$rntp_min_start_date = rental_get_earliest_start_date()->format('Y/m/d');
$rntp_default_start = $rntp_default_end = '';
$opt_rental_select_a_day_by_default = get_option('rental_select_a_day_by_default', 0);

if ($opt_rental_select_a_day_by_default == 1 && $dates_on_checkout != 1) {

    $default_start_day = rental_get_default_start_date();
    $default_end_day = rental_get_default_end_date($opt_hide_end_date);

    if ($default_start_day && $opt_default_start_time) {
        $today_start = $default_start_day->format('M j') . ', ' . $opt_default_start_time;
        $rntp_default_start = $default_start_day->format('Y/m/d') . ' ' . $opt_default_start_time;
    }
    // default end date/time
    if ($default_end_day && !$opt_hide_end_date && !empty($opt_default_end_time)) {
        $today_end = $default_end_day->format('M j') . ', ' . $opt_default_end_time;
        $rntp_default_end = $default_end_day->format('Y/m/d') . ' ' . $opt_default_end_time;
    }

} else {
    // No default day configured: the field still opens on the earliest
    // selectable day rather than on today.
    $earliest_day = rental_get_earliest_start_date();

    $today_start = $earliest_day->format('M j') . ', ' . $opt_default_start_time;
    $rntp_default_start = $earliest_day->format('Y/m/d') . ' ' . $opt_default_start_time;
}


if (isset($_COOKIE['rental_start_date'])) {
    $date_format = 'M j';
    if ( !$opt_hide_time_pickers) {
        $date_format .= ', g:i A';
    }
    // $today_start = date($date_format, strtotime($_COOKIE['rental_start_date']));
    $today_end = date($date_format, strtotime($decrypted_rental_end_date));
}

$opt_start_date_text = get_option('rental_start_date_text');
$opt_end_date_text = get_option('rental_end_date_text');
$opt_start_date_text = $opt_start_date_text ? $opt_start_date_text : 'Start Date';
$opt_end_date_text = $opt_end_date_text ? $opt_end_date_text : 'Return Date';

$submit_btn_text = 'Show Products';
if ($dates_on_checkout == 1) {
    $submit_btn_text = get_option('rental_proceed_text', 'Proceed');
    if($submit_btn_text == '') $submit_btn_text = 'Proceed';
}

?>
    
<form id="rental_date_form" class="rntp-rental-form<?php echo $rntp_form_filled; ?>" method="post" enctype="multipart/form-data">

    <div class="rntp-form-wrapper <?php echo $zipcode_included; ?> ">
        <?php if ($opt_show_location): ?>
            <div class="rntp-address-block">
                <label for="rental_address"><?php _e('Location', 'rentopian-sync'); ?></label>
                <input type="text" name="rental_address" id="rental_address" value="<?php echo isset($_COOKIE['rental_address']) ? $decrypted_rental_address : "" ?>" class="address-form rntp-address" required="required" />
            </div>
        <?php endif ?>
        <div class="rntp-start-date-block">
            <label for="rental_start_date"><?php echo $opt_start_date_text; ?></label>
            <input type="text"
                    class="date-form rntp-date"
                    name="start_date"
                    id="rental_start_date"
                    required="required"
                    data-start-date-default="<?php echo $today_start; ?>"
                    data-end-date-default="<?php echo $today_end; ?>"
                    data-end-date="<?php echo $end_date; ?>"
                    data-hide-end="<?php echo $opt_hide_end_date? 1: 0; ?>"
                    data-hide-time="<?php echo (bool) $opt_hide_time_pickers; ?>"
                    data-min-start="<?php echo get_option('rental_min_start_date'); ?>"
                    data-min-start-date="<?php echo esc_attr($rntp_min_start_date); ?>"
                    data-default-start="<?php echo esc_attr($rntp_default_start); ?>"
                    data-default-end="<?php echo esc_attr($rntp_default_end); ?>"
                    data-disabled-week-days="<?php echo !empty($opt_disabled_week_days) ? esc_attr($opt_disabled_week_days) : ''; ?>"
                    data-value="<?php echo $start_date; ?>"
                    data-start-date-offset="<?php echo esc_attr((int) get_option('rental_start_date_offset', 0)); ?>"
                    data-end-date-offset="<?php echo esc_attr((int) get_option('rental_end_date_offset', 0)); ?>"
            />
        </div>
        <?php if ( !$opt_hide_end_date) {
            $opt_date_step = get_option('rental_date_step');
            if ($opt_date_step && get_option('rental_select_date_range')) { ?>
            <div class="rntp-end-date-block">
                <label for="rental_end_date"><?php _e('Date Range', 'rentopian-sync'); ?></label>
                <select class="date-form rntp-date"
                        name="end_date"
                        id="rental_end_date"
                        required="required"
                        data-range="1"
                        data-min-rage="<?php echo get_option('rental_min_dates_range'); ?>"
                        data-max-rage="<?php echo get_option('rental_max_dates_range'); ?>"
                        data-date-step="<?php echo $opt_date_step; ?>"
                        data-value="<?php echo $end_date; ?>">
                </select>
            </div>
            <?php } else { ?>
            <div class="rntp-end-date-block">
                <label for="rental_end_date"><?php echo $opt_end_date_text; ?></label>
                <input type="text"
                       class="date-form rntp-date"
                       name="end_date"
                       id="rental_end_date"
                       required="required"
                       data-min-rage="<?php echo get_option('rental_min_dates_range'); ?>"
                       data-max-rage="<?php echo get_option('rental_max_dates_range'); ?>"
                       data-date-step="<?php echo $opt_date_step; ?>"
                       data-value="<?php echo $end_date; ?>"/>
            </div>
            <?php }
        }
        if ( !$opt_hide_zip) { ?>
            <div class="rntp-zipcode-block">
                <label for="rental_zip"><?php _e('ZIP Code', 'rentopian-sync'); ?></label>
                <input type="text" class="date-form" name="zip" id="rental_zip" required="required"
                       value="<?php echo $zip; ?>">
            </div>
        <?php } ?>
        <div class="rntp-submit-block">
            <button type="submit" id="submit_date" class="rntp-submit-button">
                <?php _e($submit_btn_text, 'rentopian-sync'); ?>
            </button>
        </div>
    </div>
</form>
<?php
 if (
    !(get_option('rental_dates_on_checkout', 0) == 1 
    && get_option('rental_allow_overbook', 1) == 1 
    && get_option('rental_checkout_layout_mode', 'classic') !== 'modern')
) {
    if (function_exists('rental_get_event_date_offsets') && rental_get_event_date_offsets() && function_exists('rental_render_rental_period_details_shell')) {
        rental_render_rental_period_details_shell(['display' => 'none', 'type' => 'horizontal']);
    }
}
?>