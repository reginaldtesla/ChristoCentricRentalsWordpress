/*--------------------------------------------------------------
Rentopian Sync Frontend Scripts
Author: Rentopian
Website: https://rentopian.com
Copyright 2019, Rentopian Inc. All Rights Reserved.
----------------------------------------------------------------*/

jQuery(document).ready(function($) {

  // set customer input full address in cart page
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
    }, complete: function(datas) {
      var $totals_column = $(".woocommerce-shipping-totals td");
      var $fullAddressPlaceHolder = $totals_column.find("strong");
      var $fullAddressDestinationPlaceHolder = $totals_column.find(".woocommerce-shipping-destination");
      var wcCalc = $(".woocommerce-shipping-calculator");

      var data = datas.responseJSON;
      if (data !== undefined && data) {
        var theZip = '';

        // priority with full delivery address input
        // if (data.user_logged_in === 0) {

          if (data.address === '') {
            // var address_parts = JSON.parse(getCookie('rental_google_map_address'));
            // var city, state, country, zip, address;
            
            // for(var i in address_parts) {
            //   if (address_parts[i]['key'] === 'city') {
            //     city = address_parts[i]['value'];
            //   } else if (address_parts[i]['key'] === 'state') {
            //     state = address_parts[i]['value'];
            //   } else if (address_parts[i]['key'] === 'country') {
            //     country = address_parts[i]['value'];
            //   } else if (address_parts[i]['key'] === 'zip') {
            //     zip = address_parts[i]['value'];
            //   } else if (address_parts[i]['key'] === 'address') {
            //     address = address_parts[i]['value'];
            //   }
            // }
    
            
            // if (city !== undefined && city !== '') {
            //   wcCalc.find("#calc_shipping_city").val(city);
            // }
            // if (state !== '') {
            //   wcCalc.find("#calc_shipping_state").val(state).trigger('change');
            // }
            // if (country !== '') {
            //   wcCalc.find("#calc_shipping_country").val(country).trigger('change');
            // }

            // if (data.zip !== '') {
            //   wcCalc.find("#calc_shipping_postcode").val(data.zip);
            //   theZip = data.zip;
            // } else {
            //   if (zip !== undefined && zip !== '') {
            //     wcCalc.find("#calc_shipping_postcode").val(zip);
            //     theZip = zip;
            //   }
            // }

            // if (address !== '') {
            //   $fullAddressPlaceHolder.html("");
            //   $fullAddressPlaceHolder.append(address + " " + city + " " + state + " " + country + " " + theZip)
              
            //   $fullAddressDestinationPlaceHolder.html(" Shipping to " + "<strong>" + address + " " + city + " " + state + " " + country + " " + theZip + "</strong>.");
            // }

          } else {
            // custom address/zip code(not from google map)
            wcCalc.find("#calc_shipping_city").val('');
            wcCalc.find("#calc_shipping_state").val('').trigger('change');
            wcCalc.find("#calc_shipping_country").val('US').trigger('change');
            

            if (data.zip !== '') {
              wcCalc.find("#calc_shipping_postcode").val(data.zip);
              theZip = data.zip;
            }
            $fullAddressPlaceHolder.html("");
            $fullAddressPlaceHolder.append(data.address + " " + theZip)

            $fullAddressDestinationPlaceHolder.html(" Shipping to " + "<strong>" + data.address + " " + theZip + "</strong>.");
          }
         
        // }
        
        
      } else {

        wcCalc.find("#calc_shipping_city").val('');
        wcCalc.find("#calc_shipping_state").val('').trigger('change');
        wcCalc.find("#calc_shipping_country").val('US').trigger('change');
        wcCalc.find("#calc_shipping_postcode").val('');
        $fullAddressPlaceHolder.html("");
        $fullAddressDestinationPlaceHolder.html("");
        $("ul #shipping_method").css({'display' : 'none'});
        
      }

    }
  });


  // textual labels
  var $cartHeader = $(".entry-title");
  block($cartHeader);
  $.ajax({
    type: 'GET',
    url: rentalObj.url,
    data: {
      action: 'rental_replace_cart_page_textual_labels',
    },
    success: function(data) {
    },
    error: function(data) {
      console.log(data);
    }, complete: function(data) {

      var data_json = data.responseJSON;
      if (data_json.cart_label !== '') {
        
        $cartHeader.text(data_json.cart_label)

        var $updateCartBtn = $("button[name=update_cart]");
        if ($updateCartBtn.length) {
          $updateCartBtn.text("Update "+data_json.cart_label);
        }
      }

      unblock($cartHeader);
    }
  });


  // damage waiver

  // The choice is stored by ajax, so the totals are inconsistent until it lands.
  // Keep the checkout button under a loader and swallow its clicks until then.
  var damageWaiverPending = false;

  function blockCheckoutButton() {
    damageWaiverPending = true;
    $('.wc-proceed-to-checkout').block({
      message: null,
      overlayCSS: {
        background: '#fff',
        opacity: 0.6
      }
    });
  }

  function unblockCheckoutButton() {
    damageWaiverPending = false;
    $('.wc-proceed-to-checkout').unblock();
  }

  $(document).on('click', '.wc-proceed-to-checkout a', function(event) {
    if (damageWaiverPending) {
      event.preventDefault();
    }
  });

  // Re-render the totals panel so the damage waiver fee and the selector stay in sync,
  // then refresh the fragments that carry the cart total into the header
  function refreshCartTotals($blocked) {
    var wcAjaxUrl = null;
    if (typeof wc_cart_params !== 'undefined') {
      wcAjaxUrl = wc_cart_params.wc_ajax_url;
    } else if (typeof wc_cart_fragments_params !== 'undefined') {
      wcAjaxUrl = wc_cart_fragments_params.wc_ajax_url;
    }

    if (!wcAjaxUrl) {
      window.location.reload();
      return;
    }

    $.ajax({
      type: 'GET',
      url: wcAjaxUrl.toString().replace('%%endpoint%%', 'get_cart_totals'),
      dataType: 'html',
      success: function(response) {
        // Replacing the panel drops the old overlays with it; unblocking afterwards
        // still clears the button if the replacement did not happen
        $('div.cart_totals').replaceWith(response);
        unblockCheckoutButton();
        $(document.body).trigger('updated_cart_totals');
        $(document.body).trigger('wc_fragment_refresh');
      },
      error: function(data) {
        console.log(data);
        unblock($blocked);
        unblockCheckoutButton();
      }
    });
  }

  $(document).on('change', '#rntp-cart-damage-waiver input[type=radio][name="damage_waiver"]', function() {
    // Blocking the totals panel also covers the radios, so the choice cannot be
    // changed again while the previous one is still saving
    var $totals = $('div.cart_totals');
    block($totals);
    blockCheckoutButton();

    $.ajax({
      type: 'POST',
      url: rentalObj.url,
      data: {
        action: 'rental_damage_waiver',
        damage_waiver: this.value
      },
      success: function() {
        refreshCartTotals($totals);
      },
      error: function(data) {
        console.log(data);
        // Re-render from the stored choice so the radio never shows a value
        // that was not saved
        refreshCartTotals($totals);
      }
    });
  });

});

