<?php
global $product;
global $wp;
$product_id = 0;
if (isset($product) && is_object($product) && $product && !$product->has_child()) {
    $product_id = $product->get_id();
    // $variants = $product->get_visible_children()[0];
}
$url = add_query_arg( $wp->query_vars, home_url( $wp->request ) );

$rental_sync_status = get_option('rental_synchronize_status');

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

$opt_default_start_time = '';
$opt_default_end_time = '';

if (get_option('rental_synchronized_product_type') != "sale" && get_option('rental_synchronized_product_type') != "hourly" && get_option('rental_form_layout') === "in-cart") {
    if (get_option('rental_dates_on_checkout', 0) != 1 || get_option('rental_allow_overbook', 1) != 1) {
    
        // Earliest selectable day and the day preselected inside it.
        $today_start = $today_end = '';
        $rntp_min_start_date = rental_get_earliest_start_date()->format('Y/m/d');
        $rntp_default_start = $rntp_default_end = '';

        $opt_show_location = get_option('rental_show_location');
        $opt_hide_end_date = get_query_var('product_type') == 'sale' || get_option('rental_hide_end_date');

        $opt_select_a_day_by_default = get_option('rental_select_a_day_by_default', 0);
        if ($opt_select_a_day_by_default == 1) {
            $opt_default_start_time = get_option('rental_default_start_time', '09:00 AM');
            $opt_default_end_time = get_option('rental_default_end_time', '05:00 PM');

            $default_start_day = rental_get_default_start_date();
            $default_end_day = rental_get_default_end_date($opt_hide_end_date);

            if ($default_start_day && $opt_default_start_time) {
                $today_start = $default_start_day->format('M j') . ', ' . $opt_default_start_time;
                $rntp_default_start = $default_start_day->format('Y/m/d') . ' ' . $opt_default_start_time;
            }
            if ($default_end_day && !empty($opt_default_end_time)) {
                $today_end = $default_end_day->format('M j') . ', ' . $opt_default_end_time;
                $rntp_default_end = $default_end_day->format('Y/m/d') . ' ' . $opt_default_end_time;
            }
        }

        $opt_hide_time = get_option('rental_hide_time_pickers');
        $opt_hide_zip = get_option('rental_hide_zip');
        $opt_date_step = $opt_hide_end_date? '': get_option('rental_date_step');
        $opt_select_date_range = $opt_date_step? get_option('rental_select_date_range'): 0;
        $opt_disabled_week_days = get_option('rental_disabled_week_days', '');
        // Ensure empty arrays become empty string, not the literal "Array"
        if (is_array($opt_disabled_week_days) && !empty($opt_disabled_week_days)) {
            $opt_disabled_week_days = json_encode($opt_disabled_week_days);
        } else {
            $opt_disabled_week_days = '';
        }
        $zip = isset($_COOKIE['rental_zip']) ? $decrypted_rental_zip : '';
        $selected_dates = '';
        $rental_start_date_calendar_formated = $rental_end_date_calendar_formated = "";
        // Year-qualified copies of the restored dates. "M j" alone is ambiguous
        // across a year boundary, and the calendar has to compare them with the
        // earliest allowed start.
        $rntp_saved_start = $rntp_saved_end = '';
        if (isset($_COOKIE['rental_start_date'])) {
            // $date_format = 'M j, y';
            $date_format = 'M j';
            if ( !$opt_hide_time) {
                $date_format .= ', g:i A';
            }

            // Event-offset mode: show the event date in the UI/calendar, not rental_start_date (computed window start).
            $rntp_event_for_ui       = function_exists('rental_get_decrypted_event_date_for_display') ? rental_get_decrypted_event_date_for_display() : '';
            $rntp_cal_anchor_start    = ($rntp_event_for_ui !== '') ? $rntp_event_for_ui : $decrypted_rental_start_date;

            $rental_start_date_default = '';
			// if default start time was available, force it in default selection
			if ($opt_default_start_time) {
                $rental_start_date_exploded = explode(' ', $rntp_cal_anchor_start);
                $rental_start_date_default = $rental_start_date_exploded[0] .', '. $opt_default_start_time;
			 }

	
            $rntp_start_ts = $opt_hide_time && $rental_start_date_default ? strtotime($rental_start_date_default) : strtotime($rntp_cal_anchor_start);
            $selected_dates = date($date_format, $rntp_start_ts);
            $rental_start_date_calendar_formated = date($date_format, $rntp_start_ts);
            $rntp_saved_start = $rntp_start_ts ? date('Y/m/d g:i A', $rntp_start_ts) : '';


            $rental_end_date_default = '';
			// if default end time was available, force it in default selection
			if ($opt_default_end_time) {
                $rental_end_date_exploded = $decrypted_rental_end_date ? explode(' ', $decrypted_rental_end_date) : explode(' ', $rntp_cal_anchor_start);
                $rental_end_date_default = $rental_end_date_exploded[0] .', '. $opt_default_end_time;
			}

            if ( !$opt_hide_end_date && isset($_COOKIE['rental_end_date'])) {

                $rntp_end_ts = $opt_hide_time && $rental_end_date_default ? strtotime($rental_end_date_default) : strtotime($decrypted_rental_end_date);
                $selected_dates .= ' - ' . date($date_format, $rntp_end_ts);
                $rental_end_date_calendar_formated = date($date_format, $rntp_end_ts);
                $rntp_saved_end = $rntp_end_ts ? date('Y/m/d g:i A', $rntp_end_ts) : '';
            }

            if (($opt_hide_time && $rental_end_date_default) || ($opt_hide_end_date && $rental_end_date_default) ) {
                $rental_end_date_calendar_formated = date($date_format, strtotime($rental_end_date_default));
                $rntp_saved_end = date('Y/m/d g:i A', strtotime($rental_end_date_default));
            }


            $today_start = $today_end = "";
            $rntp_default_start = $rntp_default_end = '';
        }
		
		
		
        ?>
        <div id="rental_min_container">

            <?php if ($opt_rental_dates_header_label = get_option('rental_dates_header_label', '')) : ?>
                <h6 class="rental-dates-header-label">
                    <?php echo $opt_rental_dates_header_label; ?>
                </h6>
            <?php endif ?>

            <?php if($rental_sync_status):?>
                <div class="rental-min-loader" style="display: none"></div>
                <ul id="rental_min_select_dates" class="rental-dates-summary rental-dates-summary-column-shape" <?php if ( !$selected_dates || !$zip): ?>style="display: none" <?php endif ?>>
                    <?php if ($opt_show_location): ?>
                        <li class="floating-label">
                            <input type="text" name="rental_address_pre" id="rental_address_pre" value="<?php echo isset($_COOKIE['rental_address']) ? $decrypted_rental_address : "" ?>" class="address-form rntp-address" required="required" placeholder="<?php _e('Location', 'rentopian-sync'); ?>" />
                            <label for="rental_address_pre"><?php _e('Location', 'rentopian-sync'); ?></label>
                        </li>
                    <?php endif ?>
                    <li class="floating-label">
                        <input id="select-rental-dates" type="text" readonly value="<?php echo $selected_dates; ?>" placeholder="<?php _e('Select Rental Dates', 'rentopian-sync'); ?>" required="required">
                        <label for="select-rental-dates"><?php _e('Rental Dates', 'rentopian-sync') ?></label>
                    </li>
                    <?php if ( !$opt_hide_zip): ?>
                        <li class="floating-label">
                            <input id="select-zip-code" type="text" readonly value="<?php echo $zip; ?>" placeholder="<?php _e('Enter ZIP Code', 'rentopian-sync'); ?>" required="required">
                            <label for="select-zip-code"><?php _e('ZIP', 'rentopian-sync'); ?></label>
                        </li>
                    <?php endif ?>
                </ul>
                <form action="<?php echo admin_url('admin-ajax.php'); ?>" method="post" id="rental_min_date_form" <?php if ($selected_dates && $zip): ?>style="display: none" <?php endif ?>>
                    <div id="rental_min_error_notification"></div>

                    <?php if ($opt_show_location): ?>
                        <div class="rental-min-form-row floating-label">
                            <label for="rental_address_min"><?php _e('Location', 'rentopian-sync'); ?></label>
                            <input type="text" name="rental_address_min" id="rental_address_min" value="<?php echo isset($_COOKIE['rental_address']) ? $decrypted_rental_address : "" ?>"  required="required" placeholder="<?php _e('Location', 'rentopian-sync'); ?>" />
                        </div>
                    <?php endif ?>

                    <div class="rental-min-form-row floating-label">
                        <input type="text" name="date" id="rental_min_date_input" placeholder="Rental Dates"
                            data-select-day-by-default="<?php echo $opt_select_a_day_by_default; ?>"
                            data-default-start-time="<?php echo $opt_default_start_time; ?>"
                            data-default-end-time="<?php echo $opt_default_end_time; ?>"
                            data-start-date-default="<?php echo $today_start; ?>"
                            data-end-date-default="<?php echo $today_end; ?>"
                            data-hide-end="<?php echo $opt_hide_end_date? 1: 0; ?>"
                            data-hide-time="<?php echo $opt_hide_time? 1: 0; ?>"
                            data-min-start="<?php echo get_option('rental_min_start_date'); ?>"
                            data-min-rage="<?php echo $opt_hide_end_date? '': get_option('rental_min_dates_range'); ?>"
                            data-max-rage="<?php echo $opt_hide_end_date? '': get_option('rental_max_dates_range'); ?>"
                            data-date-step="<?php echo $opt_date_step; ?>"
                            data-disabled-week-days="<?php echo esc_attr($opt_disabled_week_days); ?>"
                            data-start-date="<?php echo isset($_COOKIE['rental_start_date'])? $rental_start_date_calendar_formated: ''; ?>"
                            data-end-date="<?php echo isset($_COOKIE['rental_end_date'])? $rental_end_date_calendar_formated: ''; ?>"
                            data-min-start-date="<?php echo esc_attr($rntp_min_start_date); ?>"
                            data-default-start="<?php echo esc_attr($rntp_default_start); ?>"
                            data-default-end="<?php echo esc_attr($rntp_default_end); ?>"
                            data-saved-start="<?php echo esc_attr(isset($_COOKIE['rental_start_date'])? $rntp_saved_start: ''); ?>"
                            data-saved-end="<?php echo esc_attr(isset($_COOKIE['rental_end_date'])? $rntp_saved_end: ''); ?>"
                            value="<?php echo $selected_dates; ?> "
                            data-start-date-offset="<?php echo esc_attr((int) get_option('rental_start_date_offset', 0)); ?>"
                            data-end-date-offset="<?php echo esc_attr((int) get_option('rental_end_date_offset', 0)); ?>"
                            required="required"
                            />
                        <label for="rental_min_date_input"> <?php _e('Rental Dates', 'rentopian-sync'); ?></label>
                    </div>
                    <?php if ($opt_select_date_range) { ?>
                        <div class="rental-min-form-row floating-label">
                            <select name="date_range" id="rental_min_date_range"
                                    data-value="<?php echo $decrypted_rental_end_date ?? ''; ?>">
                            </select>
                            <label for="rental_min_date_range"> <?php _e('Date Range', 'rentopian-sync'); ?></label>
                        </div>
                    <?php } ?>
                    <?php if ( !$opt_hide_zip) { ?>
                        <div class="rental-min-form-row floating-label">
                            <input type="text" name="zip" id="rental_min_zip_input" placeholder="ZIP Code"
                                value="<?php echo $zip; ?>" required="required">
                            <label for="rental_min_zip_input"> <?php _e('ZIP Code', 'rentopian-sync'); ?></label>
                        </div>
                    <?php } ?>
                    <div class="rental-min-form-row">
                        <span class="rental-min-form-half-row">
                            <button type="button" id="rental_min_date_form_hide" class="rntp-button-wrap small alt button-2">
                                <?php _e('Cancel', 'rentopian-sync'); ?>
                            </button>
                        </span>
                        <span class="rental-min-form-half-row">
                            <button data-url="<?php echo $url; ?>" data-pid="<?php echo $product_id; ?>" type="submit" id="rental_min_date_form_submit" class="rntp-button-wrap small">
                                <?php _e('Apply', 'rentopian-sync'); ?>
                            </button>
                        </span>
                    </div>
                </form>

                <?php
                if (function_exists('rental_get_event_date_offsets') && rental_get_event_date_offsets() && function_exists('rental_render_rental_period_details_shell')) {
                    rental_render_rental_period_details_shell(array('display' => 'none'));
                }
                ?>

            <?php endif?>
        </div>
<?php 
    } 
} 
?>
