/*--------------------------------------------------------------
A Script For Checking Product Availability
Author: Rentopian
Website: https://rentopian.com
Copyright 2023, Rentopian Inc. All Rights Reserved.
----------------------------------------------------------------*/

jQuery(document).ready(function($) {
  var $cartBtn = $("form.cart").find(".single_add_to_cart_button");

  // single product page related
  function check_product_availability() {

    // Fly-in form present but no date chosen yet -> nothing to check.
    let flyInForm = $("#rental_min_date_input");
    if (flyInForm.length && flyInForm.val() === '') {
        return;
    }

    // Horizontal form present but no start/default date yet -> nothing to check.
    let horizontalForm = $("#rntp-form-holder");
    if (horizontalForm.length && !horizontalForm.data('has-start-date') && !horizontalForm.data('has-default-date')) {
      return;
    }

    if (!$('div.product').length) {
      return;
    }

    // Availability is a UX hint only; the server-side purchasable guard is the real
    // gate. If the endpoint isn't even configured, fail open so the customer is never
    // stranded with a disabled button.
    if (typeof rentalObj === 'undefined' || !rentalObj.url) {
      logAvailability('config-missing', 'rentalObj.url is not defined');
      enableCart();
      return;
    }

    var mainProductId = $cartBtn.val();
    var product_id = 0;

    setTimeout(function() {
      if ($('form.variations_form').length) {
        product_id = $(".woocommerce-variation-add-to-cart").find('input.variation_id').val();
      } else {
        product_id = mainProductId;
      }

      block($cartBtn);
      $.ajax({
        type: 'POST',
        url: rentalObj.url,
        dataType: 'JSON',
        timeout: 12000, // A hung request must never leave the button disabled.
        data: {
          action: 'rental_check_product_availability',
          product_id: product_id
        },
        success: function(response) {
          try {
            applyAvailability(response, product_id);
          } catch (e) {
            // Any unexpected DOM/JS failure must not strand the customer.
            logAvailability('handler-exception', (e && e.message) ? e.message : e);
            enableCart();
          }
        },
        error: function(jqXHR, textStatus, errorThrown) {
          // textStatus is one of: timeout | error | abort | parsererror. None of these
          // mean "unavailable", so the only safe response is to fail open.
          logAvailability('check-failed', {
            reason: textStatus,
            httpStatus: jqXHR ? jqXHR.status : null,
            error: errorThrown || null,
            body: jqXHR ? jqXHR.responseText : null
          });
          enableCart();
        },
        complete: function() {
          unblock($cartBtn);
        }
      });

    }, 300);
  }

  // Map the server's availability code to the button/message state.
  //   1 = available, 0 = unavailable, 2 = awaiting date/variation selection.
  // Anything else is treated as indeterminate and fails open.
  function applyAvailability(response, product_id) {
    var code = (response && typeof response.is_available !== 'undefined')
      ? parseInt(response.is_available, 10)
      : NaN;

    if (code === 1) {
      logAvailability('available', { product_id: product_id });
      enableCart();
      maybeShowAddonModal();
    } else if (code === 0) {
      logAvailability('unavailable', { product_id: product_id });
      showUnavailable();
    } else if (code === 2) {
      // No rental date selected, or no variation chosen yet: leave the button as the
      // page set it (disabled) until the customer completes the selection.
      logAvailability('awaiting-selection', { product_id: product_id });
    } else {
      logAvailability('unexpected-payload', response);
      enableCart();
    }
  }

  function enableCart() {
    errorMsg("");
    $(".rntl-product-err").hide();
    $cartBtn.removeClass("disabled").prop("disabled", false).fadeIn();
  }

  function disableCart() {
    $cartBtn.addClass("disabled").attr("disabled", true).fadeOut();
  }

  function showUnavailable() {
    errorMsg("This product isn't available for your selected rental dates. Please choose different dates.");
    var err = $(".rntl-product-err");
    err.show();
    if (err.length && err.offset()) {
      $([document.documentElement, document.body]).animate({
        scrollTop: err.offset().top - 120
      }, 1000);
    }
    disableCart();
  }

  // On an add-on validation error, open the add-on modal so the choice can be made.
  function maybeShowAddonModal() {
    var $error = $(".woocommerce-notices-wrapper .woocommerce-error");
    if (!$error.length) {
      return;
    }
    var addonErrorMsg = $error.find("li").html();
    var targetErrorMsg = 'Please, select from the provided addon variations.';
    if (addonErrorMsg && addonErrorMsg.trim() === targetErrorMsg) {
      $('#rental_add_ons').data('modal_is_open', 1).show('slow');
    }
  }

  // Single, logic-oriented trace point so the product-page check is easy to debug:
  // each call states which decision was taken and the data behind it.
  function logAvailability(state, detail) {
    if (window.console && typeof console.log === 'function') {
      console.log('[rental-availability] ' + state, detail);
    }
  }


  if (!$("#_rental_is_set_identifier").length) {
    $cartBtn.addClass('disabled');
    $cartBtn.attr("disabled", true);
    // $cartBtn.fadeOut();
    let $inp_var_id = $(".woocommerce-variation-add-to-cart").find('input.variation_id');
    if ($inp_var_id.length) {
      $inp_var_id.on("change", function() {
        check_product_availability();
      });
    } else {
      check_product_availability();
    }
  }
 
});

function block($node) {
  if ( !$node.is('.processing') && !$node.parents('.processing').length) {
    $node.addClass('processing').block({
      message: null,
      overlayCSS: {
        background: '#fff',
        opacity: 0.6
      }
    });
  }
}

function unblock($node) {
  $node.removeClass('processing').unblock();
}

var errorMsg = function (msg) {
  var $div = jQuery("div.woocommerce-notices-wrapper")
  let errorBox = "";
  if (msg !== "") {
    errorBox = '<div class="rntp-notification rntp-notification--error rntl-product-err" style="margin-top:1rem">' +
      // '<a href class="rntp-notification__close color--error">×</a>' +
      '<div class="rntp-notification__status rntp-bg--gradient-red">' +
        ' &times;' +
      '</div>' +
      '<div class="rntp-notification__content">' +
          '<h4 class="rntp-notification__title"> Product Availability </h4>' +
          '<p class="rntp-notification__text"> '+ msg +' </p>' +
      '</div>' +
    '</div>';
    
    if (!(jQuery(".rntl-product-err").length > 0)) {
      $div.append(errorBox)
    }
   
  } else {
    $div.append(errorBox)
  }
  
};