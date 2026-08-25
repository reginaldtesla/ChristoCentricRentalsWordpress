<div class="rental-location-modal" aria-hidden="true">
    <div class="rental-location-modal_dialog">
        <div class="rental-location-modal_header">
            <h2><?php _e('Please select a Location', 'rentopian-sync'); ?></h2>
            <p class="rental-location-desc"><?php _e('Pick the one that\'s closest to you.', 'rentopian-sync'); ?></p>
            <a href="#" class="rental-location-btn_close rental-close-location-modal" aria-hidden="true">&times;</a>
        </div>
        <div class="rental-location-modal_body">
            <form id="rental-location-modal-form" method="post" style="display: none;">
                <input type="number" name="rental_division_id">
            </form>

            <ul class="rental-location-list">
                <?php $selected_division = null;
                $division_id = isset($_COOKIE['rental_division_id'])? $_COOKIE['rental_division_id']: null;
                foreach ($divisions as $division):
                    if (get_option('rental_synchronized_product_type') == "hourly" && !$division->working_hours) {
                        continue;
                    }

                    $selected_class = '';
                    if ($division_id) {
                        if ($division_id == $division->id) {
                            $selected_division = $division;
                            $selected_class = 'rental-selected-location';
                        }
                    } elseif ($division->main_division) {
                        setcookie('rental_division_id', $division->id, time() + (10800), "/", "", false, true);
                        $_COOKIE['rental_division_id'] = $division->id;
                        $selected_division = $division;
                        $selected_class = 'rental-selected-location';
                    } ?>
                    <li class="<?php echo $selected_class; ?>">
                        <h4><?php echo $division->title; ?></h4>

                        <p>
                            <?php $address_on_map = 'https://maps.google.com/?q='.urlencode($division->address->address.', '.$division->address->city); ?>
                            <a href="<?php echo esc_url($address_on_map)?>" target="_blank" class="rental-location-address">
                                <?php echo $division->address->address . ($division->address->address_2? ' ' . $division->address->address_2: '') .', '.$division->address->city. ', ' . $division->address->zip; ?>
                            </a>
                        </p>
                        <a href="#" class="rental-location-select-btn <?php echo $selected_class; ?>" data-id="<?php echo $division->id; ?>">
                            <?php _e('Select', 'rentopian-sync'); ?>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
            <p class="rental-location-warning">
                <?php
                     $custom_cart_label = get_option('rental_cart_button_text') ? lcfirst(get_option('rental_cart_button_text')) : 'cart'; 
                    _e('*Once the location is changed, all previously selected products will be removed from the '.$custom_cart_label.'.', 'rentopian-sync'); 
                ?>
            </p>
        </div>
    </div>
</div>
<span class="location-picker">
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 86.96 122.88"><g fill-rule="evenodd" clip-rule="evenodd"><path d="M27.81 96.57c4.42 7.04 9.2 15.57 13.92 23.86 1.75 3.06 3.33 3.52 5.43-.11 4.56-7.91 9.04-16.1 13.76-23.74 14.33-23.19 37.78-45.8 19.13-77.01C63.49-8.14 18.45-5.18 4.98 20.62c-15.44 29.57 8.67 53.41 22.83 75.95z" fill="#ea4335"/><path d="M43.46 25.59c9.31 0 16.86 7.55 16.86 16.86s-7.55 16.86-16.86 16.86c-9.31 0-16.86-7.55-16.86-16.86s7.55-16.86 16.86-16.86z" fill="#a60d0d"/></g></svg>
    <?php if ($selected_division) echo $selected_division->title; ?>
    (<a href="#" class="rental-open-location-modal"><?php _e('Change location', 'rentopian-sync') ?></a>)
</span>