/*--------------------------------------------------------------
Rentopian Sync Checkout Scripts
Author: Rentopian
Website: https://rentopian.com
Copyright 2019, Rentopian Inc. All Rights Reserved.
----------------------------------------------------------------*/

jQuery(document).ready(function($) {

  /**
   * Lock the billing country to the WooCommerce store base country.
   * The country comes from store settings, so it is filled and read-only on the form.
   * The <select> stays enabled (disabled selects are not submitted); interaction is
   * blocked and any programmatic change is reverted back to the base country.
   */
  function lockCountryField(selector) {
    var base = (typeof rentalObj !== 'undefined' && rentalObj.baseCountry) ? rentalObj.baseCountry : '';

    var $country = $(selector);
    if (!$country.length) { return; }

    // Hidden-visual country fields are filled programmatically (Google autocomplete
    // when a key is set, otherwise the base country). Don't lock them — that would
    // override autocomplete — just guarantee a value so the required field is satisfied.
    if ($country.closest('.rentopian-hidden-visual-wrapper').length) {
      if (base && !$country.val()) {
        $country.val(base);
        if ($country.hasClass('select2-hidden-accessible')) {
          $country.trigger('change.select2');
        }
      }
      return;
    }

    if (!base) { return; }

    if ($country.val() !== base) {
      $country.val(base);
      if ($country.hasClass('select2-hidden-accessible')) {
        $country.trigger('change.select2');
      }
    }

    $country.attr('aria-readonly', 'true').addClass('rntp-country-locked');
    $country.css('pointer-events', 'none').attr('tabindex', '-1');
    $country.next('.select2-container').css('pointer-events', 'none').attr('tabindex', '-1');

    $country.off('change.rntpCountryLock').on('change.rntpCountryLock', function () {
      if ($(this).val() !== base) {
        $(this).val(base);
        if ($(this).hasClass('select2-hidden-accessible')) {
          $(this).trigger('change.select2');
        }
      }
    });
  }

  function lockCountryFields() {
    // Billing and shipping country are both determined by store settings: filled
    // from the base country and locked read-only (like the billing country).
    lockCountryField('#billing_country');
    lockCountryField('#shipping_country');
  }

  lockCountryFields();
  $(document.body).on('updated_checkout', lockCountryFields);

  /**
   * Hidden Visual for "Ship to a different address?" only takes effect when the
   * checkbox is activated (checked — e.g. via the rental_ship_to_different_address
   * cookie / pre-check). If it was set Hidden Visual but is NOT checked, reveal it
   * so the customer can still toggle the shipping section.
   */
  function applyShipToDifferentHiddenVisual() {
    var $cb = $('#ship_to_different_address, input[name="ship_to_different_address"]').first();
    if (!$cb.length) { return; }

    var $wrapper = $cb.closest('.rentopian-hidden-visual-wrapper');
    if (!$wrapper.length) { return; } // not Hidden Visual → nothing to do

    var cookieChecked = (typeof getCookie === 'function') && getCookie('rental_ship_to_different_address') === '1';
    var isChecked = $cb.is(':checked') || cookieChecked;

    if (isChecked) {
      // Activated → keep it hidden (Hidden Visual applies) and ensure shipping shows.
      if (!$cb.is(':checked')) {
        $cb.prop('checked', true).trigger('change');
      }
    } else {
      // Not activated → Hidden Visual must not hide it; reveal so it stays usable.
      $wrapper.removeClass('rentopian-hidden-visual-wrapper');
    }
  }
  applyShipToDifferentHiddenVisual();
  $(document.body).on('updated_checkout', applyShipToDifferentHiddenVisual);

  /**
   * Track "Ship to different address" checkbox state and manage coordinate switching
   */
  function trackShipToDifferentAddress() {
      // Check both possible checkbox IDs (classic and modern checkout)
      var $checkbox = $('#ship_to_different_address');
      if (!$checkbox.length) $checkbox = $('#ship-to-different-address-checkbox');
      var isChecked = $checkbox.length > 0 && $checkbox.is(':checked');
      
      // Store the checkbox state in a cookie
      setCookie('rental_ship_to_different_address', isChecked ? '1' : '0');
  }

  /**
   * Sync coordinates based on ship-to-different checkbox state
   * This ensures the generic lat/lng cookies match the correct address source
   */
  function syncCoordinatesForDelivery() {
      // Check both possible checkbox IDs (classic and modern checkout)
      var $checkbox = $('#ship_to_different_address');
      if (!$checkbox.length) $checkbox = $('#ship-to-different-address-checkbox');
      var isShipToDifferent = $checkbox.length > 0 && $checkbox.is(':checked');
      
      if (isShipToDifferent) {
          // Use shipping address coordinates for delivery calculation
          var shippingLat = getCookie('rental_client_shipping_address_lat');
          var shippingLng = getCookie('rental_client_shipping_address_lng');
          
          if (shippingLat && shippingLng) {
              setCookie('rental_client_address_lat', shippingLat);
              setCookie('rental_client_address_lng', shippingLng);
          } else {
              // Clear generic coordinates if shipping coordinates don't exist
              // This forces the API to recalculate based on the address fields
              deleteCookie('rental_client_address_lat');
              deleteCookie('rental_client_address_lng');
          }
      } else {
          // Use billing address coordinates for delivery calculation
          var billingLat = getCookie('rental_client_billing_address_lat');
          var billingLng = getCookie('rental_client_billing_address_lng');
          
          if (billingLat && billingLng) {
              setCookie('rental_client_address_lat', billingLat);
              setCookie('rental_client_address_lng', billingLng);
          } else {
              // Clear generic coordinates if billing coordinates don't exist
              deleteCookie('rental_client_address_lat');
              deleteCookie('rental_client_address_lng');
          }
      }
  }

  /**
   * Helper function to delete a cookie
   */
  function deleteCookie(name) {
      document.cookie = name + '=; Path=/; Expires=Thu, 01 Jan 1970 00:00:01 GMT;';
  }

  // Recalculate totals when "Ship to a different address?" is toggled
  // Listen on BOTH possible checkbox selectors (classic and modern checkout)
  $(document).on("change", "#ship-to-different-address-checkbox, #ship_to_different_address, input[name='ship_to_different_address']", function(e) {
      var isChecked = $(this).is(':checked');

      // Update the checkbox state cookie immediately
      trackShipToDifferentAddress();
      
      // Sync coordinates based on the new state
      syncCoordinatesForDelivery();

      // Force WooCommerce to recalc shipping / totals
      // Use a longer delay to ensure cookies are set before the AJAX request
      setTimeout(function() {
          $('body').trigger('update_checkout');
      }, 350);
  });

  // Initialize checkbox state tracking on page load
  trackShipToDifferentAddress();
  
  // Also sync coordinates on page load based on initial checkbox state
  syncCoordinatesForDelivery();

  // Helper to check if ship-to-different is checked
  function isShipToDifferentChecked() {
      var $checkbox = $('#ship_to_different_address');
      if (!$checkbox.length) $checkbox = $('#ship-to-different-address-checkbox');
      return $checkbox.length > 0 && $checkbox.is(':checked');
  }

  // Monitor shipping address field changes
  $(document).on('change blur', '#shipping_address_1, #shipping_city, #shipping_state, #shipping_postcode, #shipping_country', function() {
      if (isShipToDifferentChecked()) {
          // Sync coordinates from shipping to generic
          syncCoordinatesForDelivery();
          
          // Trigger shipping recalculation
          setTimeout(function() {
              $('body').trigger('update_checkout');
          }, 350);
      }
  });

  // Monitor billing address changes
  $(document).on('change blur', '#billing_address_1, #billing_city, #billing_state, #billing_postcode, #billing_country', function() {
      if (!isShipToDifferentChecked()) {
          // Only sync billing coordinates when NOT shipping to different address
          syncCoordinatesForDelivery();
          
          // Trigger recalculation
          setTimeout(function() {
              $('body').trigger('update_checkout');
          }, 350);
      }
      // If ship-to-different is checked, don't change generic coordinates when billing changes
  });


  // event time picker
  var $event_time = $('#rental_event_time');
  $event_time.calentim({
    showCalendars: false,
    showTimePickers: true,
    format: 'h:mm A',
    singleDate: true,
    minDate: $event_time.attr('min'),
    minuteSteps: 15
  });


  // damage waiver
  $('input[type=radio][name="damage_waiver"]').on('change', function() {
    var val = this.value;
    // The payment panel holds the place order button, so blocking it for the whole
    // request stops the order being submitted before the choice is stored.
    // Same selectors WooCommerce blocks, which it re-blocks on update_checkout.
    var $review = $('.woocommerce-checkout-payment, .woocommerce-checkout-review-order-table');
    block($review);

    $.ajax({
      type: 'POST',
      url: rentalObj.url,
      data: {
        action: 'rental_damage_waiver',
        damage_waiver: val
      },
      success: function() {
        unblock($review);
        $('body').trigger('update_checkout');
        $(document.body).trigger('wc_fragment_refresh');
      },
      error: function(data) {
        console.log(data);
        unblock($review);
      }
    });
  });


  if (rentalObj.rentalShowLocation == 1) {

      // set customer input full address in checkout page
      var $city = $("#billing_city");
      var $state = $("#billing_state");
      var $country = $("#billing_country");
      var $zip = $("#billing_postcode");
      var $address = $("#billing_address_1");
      var $address_2 = $("#billing_address_2");

      var $city_shipping = $("#shipping_city");
      var $state_shipping = $("#shipping_state");
      var $country_shipping = $("#shipping_country");
      var $zip_shipping = $("#shipping_postcode");
      var $address_shipping = $("#shipping_address_1");
      var $address_2_shipping = $("#shipping_address_2");

      // Default country falls back to the WooCommerce store base country, not a hardcoded value.
      var rntpBaseCountry = (typeof rentalObj !== 'undefined' && rentalObj.baseCountry) ? rentalObj.baseCountry : 'US';

      block($city);
      block($state);
      block($country);
      block($address);
      block($address_2);
      $.ajax({
        type: 'GET',
        url: rentalObj.url,
        data: {
          action: 'rental_replace_full_address_in_checkout',
        },
        success: function(data) {
        },
        error: function(data) {
          console.log(data);
        },complete: function(result) {

          var data = result.responseJSON;

          if (data !== undefined && data) {

              if (data.zip !== '' && data.address === '') {
                // custom zip code(not from google map)
                $zip.val(data.zip);
                $zip_shipping.val(data.zip);

              } else if (data.address === '' && data.zip === '') {
                // address data from google map
                var googleMapData = getCookie('rental_google_map_address');

                if (googleMapData !== '' && googleMapData !== undefined) {
                  if (isJsonString(googleMapData)) {
                    var address_parts = JSON.parse(googleMapData);
                    if (address_parts.length) {
                      var city, state, country, zip, address, address_2;
                      for(var i in address_parts) {
                        if (address_parts[i]['key'] === 'city') {
                          city = address_parts[i]['value'];
                        } else if (address_parts[i]['key'] === 'state') {
                          state = address_parts[i]['value'];
                        } else if (address_parts[i]['key'] === 'country') {
                          country = address_parts[i]['value'];
                        } else if (address_parts[i]['key'] === 'zip') {
                          zip = address_parts[i]['value'];
                        } else if (address_parts[i]['key'] === 'address') {
                          address = address_parts[i]['value'].trim();
                        } else if (address_parts[i]['key'] === 'address_2') {
                          address_2 = address_parts[i]['value'].trim();
                        }
                      }
            
                      if (city !== undefined && city !== '') {
                        $city.val(city);
                        $city_shipping.val(city);
                      }
                      if (state !== '') {
                        $state.val(state).trigger('change');
                        $state_shipping.val(state).trigger('change');
                      }
                      if (country !== '') {
                        $country.val(country).trigger('change');
                        $country_shipping.val(country).trigger('change');
                      }
            
                      // if (data.zip != '') {
                      //   $zip.val(data.zip);
                      //   $zip_shipping.val(data.zip);
                      // } else {
                        if (zip !== undefined && zip !== '') {
                          $zip.val(zip);
                          $zip_shipping.val(zip);
                        }
                      // }
        
                      if (address !== '') {
                        $address.val(address);
                        $address_shipping.val(address);
                      }

                      if (address_2 !== '') {
                        $address_2.val(address_2);
                        $address_2_shipping.val(address_2);
                      }
                      

                    }
                  }
                  
                }
                

              } else {

                // custom address/zip code(not from google map) in full address delivery mode
                $city.val('');
                $state.val('').trigger('change');
                $country.val(rntpBaseCountry).trigger('change');

                if (data.zip !== '') {
                  $zip.val(data.zip);
                  $zip_shipping.val(data.zip);
                }
                $address.val(data.address);
                $address_shipping.val(data.address);
              }

              
          } else {

            // remove previous remaining data
            $city.val('');
            $state.val('').trigger('change');
            $country.val(rntpBaseCountry).trigger('change');
            $zip.val('');
            $address.val('');
            $address_2.val('');

            $city_shipping.val('');
            $state_shipping.val('').trigger('change');
            $country_shipping.val(rntpBaseCountry).trigger('change');
            $zip_shipping.val('');
            $address_shipping.val('');
            $address_2_shipping.val('');
          }


          $('body').trigger('update_checkout', { update_shipping_method: true });
          
          unblock($city);
          unblock($state);
          unblock($country);
          unblock($address);
          unblock($address_2);
        }
      });

  }
    

    // =====================================================================
    // RENTAL CHECKOUT FIELDS - localStorage persistence
    // Preserves user input across page refreshes until order is submitted
    // =====================================================================
    
    // Component-style field IDs to preserve
    var componentFieldIds = [
        'rental_event_types_id',
        'rental_referral_source_id',
        'rental_damage_waiver'
    ];
    
    // Build selectors for custom rental fields and component fields
    var rentalFieldSelectors = [
        // Custom fields (IDs like rental_custom_*, rental_custom_fields[*], etc.)
        "input[id^='rental_custom']",
        "select[id^='rental_custom']",
        "textarea[id^='rental_custom']",
        "input[name^='rental_custom']",
        "select[name^='rental_custom']",
        "textarea[name^='rental_custom']"
    ];
    
    // Add component field selectors
    componentFieldIds.forEach(function(fieldId) {
        rentalFieldSelectors.push('#' + fieldId);
        rentalFieldSelectors.push('[name="' + fieldId + '"]');
    });
    
    var rentalFieldInputSelector = rentalFieldSelectors.join(', ');
    
    // Storage key prefix for all rental checkout fields
    var RENTAL_STORAGE_PREFIX = 'rental_cf_';

    function saveRentalFieldToLocal(element) {
        var elementId = element.attr('id') || element.attr('name');
        if (!elementId) return;
        
        // Use a consistent prefix for localStorage keys
        var storageKey = RENTAL_STORAGE_PREFIX + elementId;
        var elementValue;

        if (element.is('select')) {
            if (element.prop('multiple')) {
                // For multi-select, save all selected values as JSON
                elementValue = element.val();
                if (elementValue && elementValue.length) {
                    localStorage.setItem(storageKey, JSON.stringify(elementValue));
                } else {
                    localStorage.removeItem(storageKey);
                }
            } else {
                // For simple select, save the selected value
                elementValue = element.val();
                if (elementValue) {
                    localStorage.setItem(storageKey, elementValue);
                } else {
                    localStorage.removeItem(storageKey);
                }
            }
        } else if (element.is(':checkbox')) {
            // For checkboxes, save checked state
            localStorage.setItem(storageKey, element.is(':checked') ? '1' : '0');
        } else if (element.is(':radio')) {
            // For radio buttons, only save if checked
            if (element.is(':checked')) {
                localStorage.setItem(storageKey, element.val());
            }
        } else {
            // For other input types (text, textarea, etc.)
            elementValue = element.val();
            if (elementValue) {
                localStorage.setItem(storageKey, elementValue);
            } else {
                localStorage.removeItem(storageKey);
            }
        }
    }

    // Attach event listeners for rental field elements (existing and dynamically added)
    $(document).on('change input', rentalFieldInputSelector, function() {
        saveRentalFieldToLocal($(this));
    });

    // Restore rental field values from localStorage on page load
    setTimeout(function() {
        Object.keys(localStorage).forEach(function(key) {
            if (key.startsWith(RENTAL_STORAGE_PREFIX)) {
                var storedValue = localStorage.getItem(key);
                if (!storedValue) return;

                // Extract the original element ID from the storage key
                var elementId = key.replace(RENTAL_STORAGE_PREFIX, '');
                
                // Try to find element by ID first, then by name
                var $element = $('#' + elementId.replace(/[\[\]]/g, '\\$&'));
                if (!$element.length) {
                    $element = $('[name="' + elementId + '"]');
                }

                if ($element.length) {
                    if ($element.is('select')) {
                        if ($element.prop('multiple')) {
                            try {
                                var values = JSON.parse(storedValue);
                                $element.val(values).trigger('change');
                            } catch (e) {
                                // Invalid JSON, skip
                            }
                        } else {
                            $element.val(storedValue).trigger('change');
                        }
                    } else if ($element.is(':checkbox')) {
                        $element.prop('checked', storedValue === '1').trigger('change');
                    } else if ($element.is(':radio')) {
                        // For radio buttons, check the one with matching value
                        $('[name="' + elementId + '"][value="' + storedValue + '"]').prop('checked', true).trigger('change');
                    } else {
                        $element.val(storedValue);
                    }
                }
            }
        });
    }, 500);

    // Clear rental field localStorage on successful order submission
    $(document.body).on('checkout_error', function() {
        // Keep values on error so user doesn't lose data
    });
    
    // Clear localStorage when order is successfully placed (on thank you page)
    if ($('.woocommerce-order-received').length || $('.woocommerce-thankyou').length) {
        Object.keys(localStorage).forEach(function(key) {
            if (key.startsWith(RENTAL_STORAGE_PREFIX)) {
                localStorage.removeItem(key);
            }
        });
    }
    
    // Always clear time selection fields from localStorage on any checkout page load
    // These should always come fresh from cookies/session, not persist across orders
    localStorage.removeItem(RENTAL_STORAGE_PREFIX + 'delivery_time_selections_id');
    localStorage.removeItem(RENTAL_STORAGE_PREFIX + 'pickup_time_selections_id');
    

    if (rentalObj.isTipAllowed == 1) {
      setTimeout(() => {

        $('#rental_payment_tip_id').on('change', function() {
            updateTipAmount();
        });
  
      }, 1000);
  
      updateTipAmount();
  
      function updateTipAmount() {
        let tipId = $('#rental_payment_tip_id').val()
  
        $.ajax({
          type: 'POST',
          url: rentalObj.url,
          data: {
            action: 'rental_update_total_on_tip_amount_change',
            tip_id: tipId,
          },
          success: function(data) {
          },
          error: function(data) {
            console.log(data);
          },complete: function(result) {
  
            var data = result.responseJSON;
  
            if (data !== undefined && data) {
              
              
              let newHTML = '<span class="woocommerce-Price-amount amount">' +
                                '<bdi>' +
                                    '<span class="woocommerce-Price-currencySymbol">'+ data.currency +'</span>' + data.payment_tip_amount +
                                '</bdi>' +
                            '</span>';
              
              $('#rental-tip-amount').html(newHTML);
            }
  
            $('body').trigger('update_checkout');
          }
        });
      }
  
    }
   

    function checkMinOrderNotice() {

      $.ajax({
        type: 'POST',
        url: rentalObj.url,
        data: {
          action: 'rental_get_min_order_notice'
        },
        success: function(response) {
          
          if (response.success && response.data.message) {

        	  // Check if the message is already displayed to avoid duplicates
            if ($('.woocommerce-error:contains("' + response.data.message + '")').length === 0) {
                // $('form.checkout').prepend('<div class="woocommerce-error">' + response.data.message + '</div>');
                $('.woocommerce-terms-and-conditions-wrapper').before('<div class="woocommerce-error">' + response.data.message + '</div>');
            }
			  
          }

        },
        error: function(data) {
          console.log(data);
        }
      });

    }


    $(document.body).on('updated_checkout', function () {
      // Restore delivery and pickup time selections after form refresh so they are not lost
      // (e.g. when another script triggers update_checkout or after multi-day was toggled elsewhere).
      (function restoreTimeSelectionsFromCookies() {
        var $deliverySelect = $('select[name="delivery_time_selections_id"]');
        var $pickupSelect = $('select[name="pickup_time_selections_id"]');
        if (typeof getCookie !== 'function') return;
        var savedDelivery = getCookie('delivery_time_selections_id');
        var savedPickup = getCookie('pickup_time_selections_id');
        if (savedDelivery && $deliverySelect.length && $deliverySelect.find('option[value="' + savedDelivery + '"]').length) {
          if ($deliverySelect.val() !== savedDelivery) {
            $deliverySelect.val(savedDelivery);
          }
        }
        if (savedPickup && $pickupSelect.length && $pickupSelect.find('option[value="' + savedPickup + '"]').length) {
          if ($pickupSelect.val() !== savedPickup) {
            $pickupSelect.val(savedPickup);
          }
        }
      })();

      // Restore start/end date inputs when update was triggered by multi-day toggle
      // (form refresh can overwrite them with server defaults). Run after a short delay
      // so we run after the date picker re-inits (rental-date-form-modern.js uses 150ms).
      (function restoreDatesAfterMultiDayToggle() {
        var state = window.rentopianCheckoutDates;
        if (!state || !state.restoreAfterUpdate) return;
        var startVal = state.startDate;
        var endVal = state.endDate;
        state.restoreAfterUpdate = false;
        setTimeout(function() {
          var $startInput = $('.rntp-start-date-block').find('input#rental_start_date');
          var $endInput = $('.rntp-start-date-block').find('input#rental_end_date');
          if (startVal && $startInput.length) {
            $startInput.val(startVal);
          }
          if (endVal !== undefined && $endInput.length) {
            $endInput.val(endVal);
          }
        }, 200);
      })();

        checkMinOrderNotice();

        // Shipping related
        trackShipToDifferentAddress();
        syncCoordinatesForDelivery();
    });


    const togglePickupCheckbox = $('#rental_different_pick_up_address');

    const savedPickupState = getCookie('rental_different_pick_up_address');
    if (savedPickupState === '1') {
        togglePickupCheckbox.prop('checked', true);
        $('#rental_pick_up_address_fields').show();
        $('#rental_pick_up_address_fields').find("#rental_pick_up_country").val('US').trigger('change');
        $('#rental_pick_up_address_fields').find("#rental_pick_up_country_field").hide();
    } else {
        togglePickupCheckbox.prop('checked', false);
        $('#rental_pick_up_address_fields').hide();
    }

    // Save state when checkbox changes
    togglePickupCheckbox.on('change', function () {
        const isChecked = $(this).is(':checked') ? '1' : '0';
        setCookie('rental_different_pick_up_address', isChecked);

        if (isChecked === '1') {
            $('#rental_pick_up_address_fields').slideDown();
            $('#rental_pick_up_address_fields').find("#rental_pick_up_country").val('US').trigger('change');
            $('#rental_pick_up_address_fields').find("#rental_pick_up_country_field").hide();
        } else {
            $('#rental_pick_up_address_fields').slideUp();
        }
        
        $('body').trigger('update_checkout');
    });


    if (togglePickupCheckbox.is(":checked")) {

      // Monitor all fields that start with 'rental_pick_up_'
      $('input[name^="rental_pick_up_"], select[name^="rental_pick_up_"]').on('change blur', function() {
          const fieldName = $(this).attr('name');
          const value = $(this).val();

          if (typeof setCookie === 'function') {
              setCookie(fieldName, value);
          }
      });


      $('body').trigger('update_checkout');


      // Restore pickup values if exists in cookies
      $('input[name^="rental_pick_up_"], select[name^="rental_pick_up_"]').each(function() {
          const fieldName = $(this).attr('name');
          if (typeof getCookie === 'function') {
              const savedValue = getCookie(fieldName);
              if (savedValue) {
                  $(this).val(savedValue).trigger('change'); // also triggers select2 etc.
              }
          }
      });
    
    } else {

      $('input[name^="rental_pick_up_"], select[name^="rental_pick_up_"]').each(function() {
          const fieldName = $(this).attr('name');
          if (typeof deleteCookie === 'function') {
              deleteCookie(fieldName, "/")
          }
      });

      $('body').trigger('update_checkout');
    }


    // Pick-up address validation
    var pickupRequiredFields = [
        'rental_pick_up_address_1',
        'rental_pick_up_city',
        'rental_pick_up_state',
        'rental_pick_up_postcode'
    ];

    function validatePickupFields() {
        if (!$('#rental_different_pick_up_address').is(':checked')) {
            return true;
        }
        var valid = true;
        pickupRequiredFields.forEach(function(name) {
            var $wrapper = $('#' + name + '_field');
            var $input   = $('#' + name);
            if (!$wrapper.length || !$input.length) return;
            var isEmpty  = !$input.val() || !String($input.val()).trim();
            if (isEmpty) {
                $wrapper
                    .removeClass('woocommerce-validated')
                    .addClass('woocommerce-invalid woocommerce-invalid-required-field');
                $wrapper.find('.form-error').show();
                valid = false;
            } else {
                $wrapper
                    .removeClass('woocommerce-invalid woocommerce-invalid-required-field')
                    .addClass('woocommerce-validated');
                $wrapper.find('.form-error').hide();
            }
        });
        return valid;
    }

    // Highlight fields inline as the user leaves them
    $(document).on(
        'blur change',
        '#rental_pick_up_address_1, #rental_pick_up_city, #rental_pick_up_state, #rental_pick_up_postcode',
        function() { validatePickupFields(); }
    );

    $('form.woocommerce-checkout').on('checkout_place_order', function() {
        if (!validatePickupFields()) {
            var $section = $('#rental_pick_up_address_fields');
            if ($section.length) {
                $('html, body').animate({ scrollTop: $section.offset().top - 120 }, 400);
            }
        }
    });


    const $deliveryTimeSelect = $('select[name="delivery_time_selections_id"]');
    const summaryWrapperSelector = '#rntp-rental-dates-summary-wrapper';

    /**
     * Write a date value to a modern date input while keeping the calentim
     * calendar's internal selection in step. The dates summary reads the
     * calendar's internal date, and a checkout re-init mirrors the input from
     * that internal date, so writing only the input would let them drift apart.
     * Falls back to a plain value write in classic mode (no calendar attached).
     */
    function setDateInputWithCalendar($input, value) {
        if (!$input || !$input.length) return;
        var cal = $input.data('calentim');
        if (cal && value && typeof moment !== 'undefined') {
            var parsed = moment(value, cal.config.format);
            if (parsed.isValid()) {
                // Server value omits the year; keep the calendar's current year.
                if (cal.config.startDate) parsed.year(cal.config.startDate.year());
                cal.setStart(parsed);
                if ($input.val() !== value) $input.val(value);
                return;
            }
        }
        $input.val(value);
    }

    function updateDeliverySelectedTime(timeId){

      if (typeof setCookie === 'function') {
        setCookie('delivery_time_selections_id', timeId);
      }

      var $summaryWrapper = $(summaryWrapperSelector).first();
      if ($summaryWrapper.length) {
        $summaryWrapper.addClass('updating').css('opacity', '0.5');
      }

      // Determine multi-day status:
      // - If checkbox exists (modern mode): use checkbox state
      // - If checkbox doesn't exist (classic mode): check if end date is set in input
      var $multiDayCheckbox = $('#rental_multi_day_event');
      var multiDay;
      if ($multiDayCheckbox.length) {
        // Modern mode: use checkbox state
        multiDay = $multiDayCheckbox.is(':checked') ? 1 : 0;
      } else {
        // Classic mode: infer from end date presence
        // Use direct selector since input may not be inside .rntp-start-date-block
        var endDateInput = $("input#rental_end_date").val() || '';
        // Cookies are httpOnly, so we can't read them from JS - rely on input only
        multiDay = (endDateInput.trim() !== '') ? 1 : 0;
      }
      
      $.ajax({
        type: 'POST',
        url: rentalObj.url,
        data: {
          action: 'rental_update_selected_start_end_times',
          selected_time_id: timeId,
          time_type: 'delivery_time',
          multi_day: multiDay
        },
        success: function(){},
        error: function(xhr){

          let data = xhr.responseJSON || {};
          let errorBox = '';
          if (data.error) {

            const wcBillingFields = $(".woocommerce-billing-fields");
            wcBillingFields.before(errorBox);

            errorBox = '<div class="rntp-notification rntp-notification--error">' +
              '<a href class="rntp-notification__close color--error">×</a>' +
              '<div class="rntp-notification__status rntp-bg--gradient-red">' +
                ' &times;' +
              '</div>' +
              '<div class="rntp-notification__content">' +
                  '<h4 class="rntp-notification__title"> Please check the following: </h4>' +
                  '<p class="rntp-notification__text">'+ data.error +'</p>' +
              '</div>' +
            '</div>';
            wcBillingFields.before(errorBox);


            $deliveryTimeSelect.addClass('error-input');
            $deliveryTimeSelect.val(0)
            
            // console.log(data);
          }

        },
        complete: function(xhr) {

          let data = xhr.responseJSON || {};
          
          if (data.success) {

            $(summaryWrapperSelector).first().replaceWith(data.checkout_dates_html);
          
            $('.rntp-notification--error').remove();
            $deliveryTimeSelect.removeClass('error-input');
          }

          $(summaryWrapperSelector).first().removeClass('updating').css('opacity', '1');

          // Only update date inputs when server explicitly sent non-empty values.
          // When multi-day is unchecked, do not set end date (delivery time change must not show an end date).
          // Use direct selectors since inputs may not be inside .rntp-start-date-block in classic mode
          var $startInput = $("input#rental_start_date");
          var $endInput = $("input#rental_end_date");
          
          if (data.update_checkout_dates === 1 && ($startInput.length || $endInput.length)) {
            var startVal = data.rental_start_date;
            var endVal   = data.rental_end_date;
            
            // Determine multi-day status for classic mode compatibility
            var $multiDayCheckbox = $('#rental_multi_day_event');
            var isMultiDay;
            if ($multiDayCheckbox.length) {
              // Modern mode: use checkbox state
              isMultiDay = $multiDayCheckbox.is(':checked');
            } else {
              // Classic mode: if server returned an end date, preserve it; otherwise check current input
              var currentEndDate = $endInput.val() || '';
              isMultiDay = (endVal && String(endVal).trim() !== '') || (currentEndDate.trim() !== '');
            }
            
            if (startVal != null && String(startVal).trim() !== '' && $startInput.length) {
              setDateInputWithCalendar($startInput, startVal);
            }
            if (isMultiDay && endVal != null && String(endVal).trim() !== '' && $endInput.length) {
              setDateInputWithCalendar($endInput, endVal);
            } else if ($multiDayCheckbox.length && !isMultiDay && $endInput.length) {
              // Only clear end date if checkbox EXISTS and is unchecked (modern mode)
              $endInput.val('');
            }
            // In classic mode without checkbox, preserve whatever end date is there
          }

          // Delay update_checkout so cookie and inputs are set first; then refresh totals.
          setTimeout(function() {
            $('body').trigger('update_checkout');
          }, 150);
        }
      });
    }


    
    $deliveryTimeSelect.on('change', function() {

      updateDeliverySelectedTime($(this).val());
    });

    const saved_delivery_time_selections_id = getCookie('delivery_time_selections_id');
    if ( saved_delivery_time_selections_id && $deliveryTimeSelect.find(`option[value="${saved_delivery_time_selections_id}"]`).length ) {
      $deliveryTimeSelect.val(saved_delivery_time_selections_id);
      updateDeliverySelectedTime(saved_delivery_time_selections_id);
    }
    

    const $pickupTimeSelect = $('select[name="pickup_time_selections_id"]');

    function updatePickupSelectedTime(timeId){

      if (typeof setCookie === 'function') {
        setCookie('pickup_time_selections_id', timeId);
      }

      var $summaryWrapper = $(summaryWrapperSelector).first();
      if ($summaryWrapper.length) {
        $summaryWrapper.addClass('updating').css('opacity', '0.5');
      }

      $.ajax({
        type: 'POST',
        url: rentalObj.url,
        data: {
          action: 'rental_update_selected_start_end_times',
          selected_time_id: timeId,
          time_type: 'pickup_time'
        },
        success: function(){},
        error: function(xhr){ 

          let data = xhr.responseJSON || {};
          let errorBox = '';
          if (data.error) {

            const wcBillingFields = $(".woocommerce-billing-fields");
            wcBillingFields.before(errorBox);

            errorBox = '<div class="rntp-notification rntp-notification--error">' +
              '<a href class="rntp-notification__close color--error">×</a>' +
              '<div class="rntp-notification__status rntp-bg--gradient-red">' +
                ' &times;' +
              '</div>' +
              '<div class="rntp-notification__content">' +
                  '<h4 class="rntp-notification__title"> Please check the following: </h4>' +
                  '<p class="rntp-notification__text">'+ data.error +'</p>' +
              '</div>' +
            '</div>';
            wcBillingFields.before(errorBox);
            
            $pickupTimeSelect.addClass('error-input');
            $pickupTimeSelect.val(0)

            // console.log(data);
          }

        },
        complete: function(xhr) {
          
          let data = xhr.responseJSON || {};
          if (data.success) {

            $(summaryWrapperSelector).first().replaceWith(data.checkout_dates_html);

            $('.rntp-notification--error').remove();
            $pickupTimeSelect.removeClass('error-input');
          }

          $(summaryWrapperSelector).first().removeClass('updating').css('opacity', '1');

          // Only update date inputs when server explicitly sent non-empty values.
          // When multi-day is unchecked, do not set end date.
          // Use direct selectors since inputs may not be inside .rntp-start-date-block in classic mode
          var $startInput = $("input#rental_start_date");
          var $endInput = $("input#rental_end_date");
          
          if (data.update_checkout_dates === 1 && ($startInput.length || $endInput.length)) {
            var startVal = data.rental_start_date;
            var endVal   = data.rental_end_date;
            
            // Determine multi-day status for classic mode compatibility
            var $multiDayCheckbox = $('#rental_multi_day_event');
            var isMultiDay;
            if ($multiDayCheckbox.length) {
              // Modern mode: use checkbox state
              isMultiDay = $multiDayCheckbox.is(':checked');
            } else {
              // Classic mode: if server returned an end date, preserve it; otherwise check current input
              var currentEndDate = $endInput.val() || '';
              isMultiDay = (endVal && String(endVal).trim() !== '') || (currentEndDate.trim() !== '');
            }
            
            if (startVal != null && String(startVal).trim() !== '' && $startInput.length) {
              setDateInputWithCalendar($startInput, startVal);
            }
            if (isMultiDay && endVal != null && String(endVal).trim() !== '' && $endInput.length) {
              setDateInputWithCalendar($endInput, endVal);
            } else if ($multiDayCheckbox.length && !isMultiDay && $endInput.length) {
              // Only clear end date if checkbox EXISTS and is unchecked (modern mode)
              $endInput.val('');
            }
            // In classic mode without checkbox, preserve whatever end date is there
          }

          // Delay update_checkout so cookie and inputs are set first; then refresh totals.
          setTimeout(function() {
            $('body').trigger('update_checkout');
          }, 150);
        }
      });
    }

    
    $pickupTimeSelect.on('change', function() {

      updatePickupSelectedTime($(this).val());
    });

    const saved_pickup_time_selections_id = getCookie('pickup_time_selections_id');
    if ( saved_pickup_time_selections_id && $pickupTimeSelect.find(`option[value="${saved_pickup_time_selections_id}"]`).length ) {
      $pickupTimeSelect.val(saved_pickup_time_selections_id);
      updatePickupSelectedTime(saved_pickup_time_selections_id);
    }




    function overrideCheckoutFieldsLabels() {

      $.ajax({
        type: 'POST',
        url: rentalObj.url,
        data: {
          action: 'rental_override_checkout_fields_labels'
        },
        success: function(response) {
          
          if (response.success && response.data.label) {

            // Set the label text and append a WooCommerce-style required marker so the
            // asterisk matches the colour/style of the other required fields (instead
            // of a plain-text "*" that .text() would leave behind).
            //
            // Label precedence (3 layers): default → textual (this settings value,
            // response.data.label) → custom (per-field layout override). A custom
            // layout label must win over the textual setting.
            var setRequiredAddressLabel = function (forId) {
              var $label = $('label[for="' + forId + '"]');
              if (!$label.length) { return; }
              var customLabel = (typeof rentopianCheckoutConfig !== 'undefined'
                  && rentopianCheckoutConfig.labelOverrides
                  && rentopianCheckoutConfig.labelOverrides[forId])
                  ? rentopianCheckoutConfig.labelOverrides[forId] : '';
              var labelText = customLabel || response.data.label;
              $label.empty()
                    .append(document.createTextNode(labelText + ' '))
                    .append('<abbr class="required" title="required">*</abbr>');
            };

            setRequiredAddressLabel('billing_address_1');
            setRequiredAddressLabel('shipping_address_1');
            setRequiredAddressLabel('rental_pick_up_address_1');
          }

        },
        error: function(data) {
          console.log(data);
        }
      });

    }

    setTimeout(() => {
      overrideCheckoutFieldsLabels()
    }, 500);


    // if there is dates on checkout form, the time selection must reset
    $(document).on("click", "button#submit_date" , function(e) {
      $deliveryTimeSelect.val(0)
      $pickupTimeSelect.val(0)
      updateDeliverySelectedTime(0)
      updatePickupSelectedTime(0)
    })



    $('#shipping_method').on('change', 'input[name^="shipping_method"]', function(e){
      var $input = $(this);
      var val = $input.val() || '';
      var shipping_method = (val.indexOf('local_pickup') !== -1) ? 2 : 1;

      $.ajax({
        type: 'POST',
        url: rentalObj.url,
        dataType: 'json',
        data: {
          action: 'rental_update_shipping_method',
          rental_shipping_method: shipping_method
          // security: rentalObj.nonce
        },
        // success: function(response) {

        //   $('body').trigger('update_checkout');
          
        // },
        error: function(xhr, status, err) {
          console.error('rental_update_shipping_method error:', xhr, status, err);
        }
      });

    });


    /*--------------------------------------------------------------
    # Rental Dates Summary Updater
    # Updates the checkout rental dates summary section when modern 
    # date picker values change (start date, end date, zip, multi-day)
    # 
    # This module listens for changes on the date picker inputs and
    # makes AJAX calls to update the summary section with properly
    # formatted dates (respecting PHP settings like hide_time_pickers).
    #
    # @since 1.0.0
    --------------------------------------------------------------*/

    /**
     * Rental Dates Summary Module
     * 
     * Handles updating #rntp-rental-dates-summary-wrapper when date picker changes.
     * Uses AJAX to ensure server-side date formatting matches PHP settings.
     */
    var RentalDatesSummary = (function() {

      // ==================================================================================
      // CONFIGURATION
      // ==================================================================================
      var selectors = {
          // Summary wrapper (from checkout_rental_dates() PHP function)
          summaryWrapper: '#rntp-rental-dates-summary-wrapper',
          summaryList: '.rental-dates-summary',

          // Modern date picker inputs
          startDate: '#rental_start_date, [name="start_date"], [name="rental_start_date"]',
          endDate: '#rental_end_date, [name="end_date"], [name="rental_end_date"]',
          zip: '#rental_zip, [name="rental_zip"], #billing_postcode',
          submitButton: '.calentim-apply'
      };

      // Debounce settings
      var debounceTimer = null;
      var debounceDelay = 300;

      // Prevent duplicate requests
      var isUpdating = false;
      
      // Track if checkout update is in progress to avoid conflicts
      var checkoutUpdateInProgress = false;

      /**
       * Initialize the module
       */
      function init() {
          bindEvents();
          
          if (typeof rentalObj !== 'undefined' && rentalObj.debug) {
              console.log('[RentalDatesSummary] Initialized with selectors:', selectors);
          }
      }

      /**
       * Bind all event listeners
       */
      function bindEvents() {

         
          // -----------------------------------------------------------------
          // ZIP Code Changes
          // -----------------------------------------------------------------
          $(document).on('change blur', selectors.zip, function() {
              debouncedUpdate();
          });

         
          // -----------------------------------------------------------------
          // Date Picker Submit Button Click
          // Primary trigger when user confirms date selection
          // Use namespaced event to avoid conflicts with rental-date-form-modern.js
          // Coordinate with checkout update to prevent race conditions
          // -----------------------------------------------------------------
          $(document).on('click.rntpSummaryUpdate', selectors.submitButton, function(e) {
              // Clear any pending debounced updates
              clearTimeout(debounceTimer);
              
              // If checkout update is in progress, wait for it to complete
              // Otherwise, update summary after a delay to allow calentim to update inputs
              if (checkoutUpdateInProgress) {
                  // Will be handled by checkout_update_complete event
                  return;
              }
              
              // Wait for calentim to update input values, then update summary
              // Use a delay to ensure input values are updated
              debounceTimer = setTimeout(function() {
                  if (!isUpdating && !checkoutUpdateInProgress) {
                      updateSummary();
                  }
              }, 300);
          });
          
          // Listen for checkout update completion to update summary
          // This ensures summary updates after the full checkout refresh
          $(document).on('checkout_update_complete', function() {
              checkoutUpdateInProgress = false;
              // Update summary after checkout update completes
              clearTimeout(debounceTimer);
              debounceTimer = setTimeout(function() {
                  if (!isUpdating) {
                      updateSummary();
                  }
              }, 200);
          });
          
          // Track when checkout update starts
          $(document).on('checkout_update_start', function() {
              checkoutUpdateInProgress = true;
              // Cancel any pending summary update to avoid conflicts
              clearTimeout(debounceTimer);
          });         
      }

      /**
       * Debounced update to prevent excessive AJAX calls
       */
      function debouncedUpdate() {
          clearTimeout(debounceTimer);
          debounceTimer = setTimeout(function() {
              updateSummary();
          }, debounceDelay);
      }

      /**
       * Get current values from date picker inputs
       */
      function getDatePickerValues() {

          var values = {
              start_date: '',
              end_date: '',
              zip: '',
              event_date: '',
          };

          // Start date - try to get from calendar instance first (server format)
          var $startDate = $(selectors.startDate).first();
          if ($startDate.length) {
              var startCalendar = $startDate.data('calentim');
              if (startCalendar && startCalendar.config.startDate && !startCalendar.config.startEmpty) {
                  // Get date from calendar in server format (YYYY/MM/DD h:mm A)
                  var enableTime = startCalendar.config.showTimePickers;
                  var serverFormat = 'YYYY/MM/DD' + (enableTime ? ' h:mm A' : '');
                  values.start_date = startCalendar.config.startDate.format(serverFormat);

                  // Event-date offset mode: calendar value is the event; send computed rental range + event for summary/cookies.
                  if (typeof RntpDateOffsets !== 'undefined' && RntpDateOffsets.isActive && RntpDateOffsets.isActive()) {
                      values.event_date = startCalendar.config.startDate.format(serverFormat);
                      var applied = RntpDateOffsets.apply(startCalendar.config.startDate);
                      if (applied && applied.startDate && applied.endDate) {
                          values.start_date = applied.startDate.format(serverFormat);
                          values.end_date = applied.endDate.format(serverFormat);
                      }
                  }
              } else if ($startDate.val()) {
                  // Fallback to input value (display format) - will need conversion in PHP
                  values.start_date = $startDate.val();
              }
          }

          // End date - try to get from calendar instance first (server format)
          var $endDate = $(selectors.endDate).first();
          if ($endDate.length) {
              var endCalendar = $endDate.data('calentim');
              if (endCalendar && endCalendar.config.startDate && !endCalendar.config.startEmpty) {
                  // Get date from calendar in server format (YYYY/MM/DD h:mm A)
                  var enableTime = endCalendar.config.showTimePickers;
                  var serverFormat = 'YYYY/MM/DD' + (enableTime ? ' h:mm A' : '');
                  values.end_date = endCalendar.config.startDate.format(serverFormat);
              } else if ($endDate.val()) {
                  // Fallback to input value (display format) - will need conversion in PHP
                  values.end_date = $endDate.val();
              }
          }

          // ZIP code
          var $zip = $(selectors.zip).first();
          if ($zip.length && $zip.val()) {
              values.zip = $zip.val();
          }

          // Fallback to cookies if calendar values not available
          if (!values.start_date && typeof getCookie === 'function') {
              values.start_date = getCookie('rental_start_date') || '';
          }
          if (!values.end_date && typeof getCookie === 'function') {
              values.end_date = getCookie('rental_end_date') || '';
          }
          if (!values.zip && typeof getCookie === 'function') {
              values.zip = getCookie('rental_zip') || '';
          }

          // When multi-day is unchecked, force single-day so summary and backend stay in sync
          var $multiDay = $('#rental_multi_day_event');
          var offsetMode = typeof RntpDateOffsets !== 'undefined' && RntpDateOffsets.isActive && RntpDateOffsets.isActive();
          if ($multiDay.length && !$multiDay.is(':checked') && !offsetMode) {
              values.end_date = '';
          }

          return values;
      }

      /**
       * Update summary via AJAX
       */
      function updateSummary() {
          if (isUpdating) return;

          var values = getDatePickerValues();

          // Don't update if no dates
          if (!values.start_date && !values.end_date) {
              return;
          }

          isUpdating = true;

          // Visual feedback
          var $summaryList = $(selectors.summaryWrapper).find(selectors.summaryList);
          $summaryList.addClass('updating').css('opacity', '0.5');

          $.ajax({
              type: 'POST',
              url: rentalObj.url,
              data: {
                  action: 'rental_update_dates_summary',
                  start_date: values.start_date,
                  end_date: values.end_date,
                  zip: values.zip,
                  event_date: values.event_date || '',
                  security: rentalObj.nonce || ''
              },
              success: function(response) {
                  if (response.success && response.data) {
                      // Replace entire wrapper 
                      if (response.data.checkout_dates_html) {
                          $(selectors.summaryWrapper).first().replaceWith(response.data.checkout_dates_html);
                      }
                      // Or update just the list
                      else if (response.data.summary_html) {
                          $(selectors.summaryWrapper).find(selectors.summaryList).html(response.data.summary_html);
                      }

                      $(document).trigger('rntp:summary_updated', [values]);
                  }
              },
              error: function(xhr, status, error) {
                  console.error('[RentalDatesSummary] AJAX Error:', error);
              },
              complete: function() {
                  isUpdating = false;
                  var $summaryList = $(selectors.summaryWrapper).find(selectors.summaryList);
                  $summaryList.removeClass('updating').css('opacity', '1');
              }
          });
      }

      /**
       * Update summary with provided data object
       */
      function updateSummaryWithData(data) {
          if (!data) {
              updateSummary();
              return;
          }

          if (isUpdating) return;
          isUpdating = true;

          var $summaryList = $(selectors.summaryWrapper).find(selectors.summaryList);
          $summaryList.addClass('updating').css('opacity', '0.5');

          $.ajax({
              type: 'POST',
              url: rentalObj.url,
              data: {
                  action: 'rental_update_dates_summary',
                  start_date: data.startDate || data.start_date || '',
                  end_date: data.endDate || data.end_date || '',
                  zip: data.zip || '',
                  event_date: data.event_date || data.eventDate || '',
                  security: rentalObj.nonce || ''
              },
              success: function(response) {

                  if (response.success && response.data) {

                      if (response.data.checkout_dates_html) {
                          $(selectors.summaryWrapper).first().replaceWith(response.data.checkout_dates_html);
                      } else if (response.data.summary_html) {
                          $(selectors.summaryWrapper).find(selectors.summaryList).html(response.data.summary_html);
                      }

                      $(document).trigger('rntp:summary_updated', [data]);
                  }

              },
              error: function(xhr, status, error) {
                  console.error('[RentalDatesSummary] AJAX Error:', error);
              },
              complete: function() {
                  isUpdating = false;
                  var $summaryList = $(selectors.summaryWrapper).find(selectors.summaryList);
                  $summaryList.removeClass('updating').css('opacity', '1');
              }
          });
      }

      // Public API
      return {
          init: init,
          update: updateSummary,
          updateWithData: updateSummaryWithData,
          getValues: getDatePickerValues
      };

  })();

  // Initialize the module
  RentalDatesSummary.init();

  // Expose globally for external access
  window.RentalDatesSummary = RentalDatesSummary;

  /**
   * Convenience function for external calls
   * 
   * Usage:
   *   updateRentalDatesSummary(); // Auto-detect from inputs
   *   updateRentalDatesSummary({ startDate: '2025-01-11', endDate: '2025-01-13', zip: '12345' });
   */
  window.updateRentalDatesSummary = function(data) {
      if (data) {
          RentalDatesSummary.updateWithData(data);
      } else {
          RentalDatesSummary.update();
      }
  };

  /*--------------------------------------------------------------
  # End Rental Dates Summary Updater
  --------------------------------------------------------------*/

    

});