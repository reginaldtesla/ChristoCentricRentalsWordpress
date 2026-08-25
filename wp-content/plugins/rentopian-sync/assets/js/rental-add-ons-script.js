/*--------------------------------------------------------------
Rentopian Sync Add Ons Modal Functionality Scripts
Author: Rentopian
Website: https://rentopian.com
Copyright 2019, Rentopian Inc. All Rights Reserved.
----------------------------------------------------------------*/

jQuery(document).ready(function($) {

  var $rental_add_ons_modal = $('#rental_add_ons');
  if ($rental_add_ons_modal.length) {

    // Set when Confirm is clicked; checked in added_to_cart and page-load handlers.
    // Using a flag instead of modal_is_open because the submit event fires
    // synchronously (clearing modal_is_open) before the async added_to_cart fires.
    var pendingConfirm = false;
    var pendingToastData = null;

    function showAddOnsModal() {
      $rental_add_ons_modal.data('modal_is_open', 1).css('display', 'flex').hide(0).fadeIn(250);
    }

    function hideAddOnsModal() {
      $rental_add_ons_modal.data('modal_is_open', '').fadeOut(200, function() {
        $(this).css('display', '');
      });
    }

    // Show an error message at the top of the modal body.
    // Replaces any existing inline error so duplicates don't stack.
    function showModalError(msg) {
      $rental_add_ons_modal.find('.rntp-modal-inline-error').remove();
      var $err = $(
        '<div class="rntp-modal-inline-error">' +
          '<span class="rntp-modal-inline-error__icon">&times;</span>' +
          '<span class="rntp-modal-inline-error__msg">' + msg + '</span>' +
        '</div>'
      );
      $rental_add_ons_modal.find('.rental-modal-body').prepend($err);
    }

    function clearModalError() {
      $rental_add_ons_modal.find('.rntp-modal-inline-error').remove();
    }

    // JS validation: every visible addon group must have a radio selected.
    // Returns true if valid, false if not (also shows inline errors).
    function validateSelections() {
      var valid = true;
      $rental_add_ons_modal.find('ul.rental-product-variants').each(function() {
        if ($(this).find('input[type="radio"]:checked').length === 0) {
          valid = false;
        }
      });
      if (!valid) {
        showModalError('Please select an option for each add-on before confirming.');
      }
      return valid;
    }

    // Snapshot the toast content while radios are still checked.
    function captureToastData() {
      var cartUrl = (typeof rentalAddOnsParams !== 'undefined') ? rentalAddOnsParams.cartUrl : '/cart';
      var productName = $('h1.product_title').first().text().trim();

      var variantLabel = '';
      var $variationData = $('.woocommerce-variation-summary .woocommerce-variation__label, .woocommerce-variation-summary .woocommerce-variation__value');
      if ($variationData.length) {
        variantLabel = $variationData.map(function() { return $(this).text().trim(); }).get().join(' ');
      }

      var addonLines = [];
      $rental_add_ons_modal.find('ul.rental-product-variants').each(function() {
        var $checked = $(this).find('input[type="radio"]:checked');
        if ($checked.length) {
          var labelText = $('label[for="' + $checked.attr('id') + '"]').text().trim();
          if (labelText) addonLines.push(labelText);
        }
      });

      return { cartUrl: cartUrl, productName: productName, variantLabel: variantLabel, addonLines: addonLines };
    }

    function showAddedToCartToast(data) {
      if (!data) return;
      var addonHtml = data.addonLines.length
        ? '<ul class="rntp-toast-addons">' + data.addonLines.map(function(l) { return '<li>' + l + '</li>'; }).join('') + '</ul>'
        : '';

      var html =
        '<div id="rntp-added-toast" class="rntp-toast">' +
          '<div class="rntp-toast-icon">&#10003;</div>' +
          '<div class="rntp-toast-body">' +
            '<p class="rntp-toast-title">' + data.productName + (data.variantLabel ? ' &mdash; ' + data.variantLabel : '') + '</p>' +
            '<p class="rntp-toast-sub">Added to cart with selected add-ons.</p>' +
            addonHtml +
            '<a href="' + data.cartUrl + '" class="rntp-toast-cart-link">View cart &rarr;</a>' +
          '</div>' +
          '<button class="rntp-toast-close" aria-label="Dismiss">&times;</button>' +
          '<div class="rntp-toast-progress"><div class="rntp-toast-progress-bar"></div></div>' +
        '</div>';

      $('#rntp-added-toast').remove();
      $('body').append(html);

      var $toast = $('#rntp-added-toast');
      var duration = 10000;
      $toast.find('.rntp-toast-progress-bar').css('transition', 'width ' + duration + 'ms linear').css('width', '0%');
      var timer = setTimeout(function() { dismissToast($toast); }, duration);
      $toast.on('click', '.rntp-toast-close', function() {
        clearTimeout(timer);
        dismissToast($toast);
      });
    }

    function dismissToast($toast) {
      $toast.addClass('rntp-toast--hiding').delay(300).queue(function() { $(this).remove().dequeue(); });
    }

    function addToCartHandler(e) {
      // Confirm button — handled by its own dedicated click handler below
      if ($(e.target).closest('.rental-modal-submit').length) return;

      // If Confirm was just clicked, don't intercept the resulting submit event —
      // let WC continue. The submit fires synchronously right after the click,
      // so pendingConfirm will be true here.
      if (pendingConfirm) return;

      // a sign to know it is a product with variable addons
      setCookie("rental_addon_product", true, 1);
      if ($rental_add_ons_modal.data('modal_is_open')) {
        hideAddOnsModal();
        return;
      }
      var variant_id = parseInt($('input[name=variation_id]').val());
      var selected_variants = $rental_add_ons_modal.data('selected_variants');
      if (variant_id && selected_variants && selected_variants.includes(variant_id)) {
        return;
      }
      e.preventDefault();
      e.stopImmediatePropagation();
      showAddOnsModal();
    }

    var $att_to_cart_form = $rental_add_ons_modal.closest('form.cart');
    $att_to_cart_form.on('submit', addToCartHandler)
      .on('click', '.single_add_to_cart_button', addToCartHandler);

    // Confirm click: validate first; if valid, snapshot data and set pending flag.
    $rental_add_ons_modal.on('click', '.rental-modal-submit', function(e) {
      clearModalError();

      // --- JS validation (primary gate) ---
      if (!validateSelections()) {
        // Block both the click and any bubbling submit
        e.preventDefault();
        e.stopImmediatePropagation();
        return;
      }

      pendingToastData = captureToastData();
      pendingConfirm = true;

      // Store two things in sessionStorage for the page-reload fallback:
      //   rntp_pending_toast  — toast content (shown only on success)
      //   rntp_addon_pending  — flag used to re-open modal on server rejection
      try {
        sessionStorage.setItem('rntp_pending_toast', JSON.stringify(pendingToastData));
        sessionStorage.setItem('rntp_addon_pending', '1');
      } catch(ex) {}
    });

    // Clear inline errors when user interacts with radios
    $rental_add_ons_modal.on('change', 'input[type="radio"]', function() {
      clearModalError();
    });

    // Clicking anywhere on a variant row selects its radio
    $rental_add_ons_modal.on('click', '.rental-product-variants li', function() {
      $(this).find('input[type="radio"]').prop('checked', true).trigger('change');
    });

    $rental_add_ons_modal.on('click', '.rental-modal-cancel', function() {
      deleteCookie("rental_addon_product", "/");
      pendingConfirm = false;
      pendingToastData = null;
      clearModalError();
      try {
        sessionStorage.removeItem('rntp_pending_toast');
        sessionStorage.removeItem('rntp_addon_pending');
      } catch(ex) {}
      hideAddOnsModal();
    });

    // ---- AJAX success: WC fires added_to_cart ----
    $(document).on('added_to_cart', function() {
      if (pendingConfirm) {
        var data = pendingToastData;
        pendingConfirm = false;
        pendingToastData = null;
        try {
          sessionStorage.removeItem('rntp_pending_toast');
          sessionStorage.removeItem('rntp_addon_pending');
        } catch(ex) {}
        hideAddOnsModal();
        setTimeout(function() { showAddedToCartToast(data); }, 250);
      }
    });

    // ---- AJAX failure: WC fires wc_add_to_cart_error ----
    // Modal is already open; extract the WC error message and show it inside.
    $(document).on('wc_add_to_cart_error', function(_e, data) {
      if (!pendingConfirm) return;
      // Reset pending so a retry works cleanly
      pendingConfirm = false;
      pendingToastData = null;
      try {
        sessionStorage.removeItem('rntp_pending_toast');
        sessionStorage.removeItem('rntp_addon_pending');
      } catch(ex) {}

      // Extract text from WC error HTML (data may be a string or object)
      var errMsg = 'There was a problem adding to cart. Please check your selections.';
      if (data && typeof data === 'string') {
        var $parsed = $('<div>').html(data);
        var txt = $parsed.find('li, p').first().text().trim();
        if (txt) errMsg = txt;
      }
      showModalError(errMsg);
      // Ensure modal is still visible (it should be, but guard anyway)
      if (!$rental_add_ons_modal.is(':visible')) {
        showAddOnsModal();
      }
    });
  }

  // ---- Page-reload failure recovery ----
  // If a non-AJAX form submit happened and the server rejected the add,
  // the page reloads without added_to_cart in the URL.
  // Re-open the modal and surface the WC error notice inside it.
  try {
    var _rntpAddonPending = sessionStorage.getItem('rntp_addon_pending');
    var _rntpStoredToast  = sessionStorage.getItem('rntp_pending_toast');

    if (_rntpAddonPending) {
      var _rntpWcSuccess = /[?&]added_to_cart=/.test(window.location.href);

      // Always clear both flags immediately
      sessionStorage.removeItem('rntp_addon_pending');
      sessionStorage.removeItem('rntp_pending_toast');

      var $modal = $('#rental_add_ons');

      if (_rntpWcSuccess) {
        // ---- SUCCESS (page-redirect path) ----
        if (_rntpStoredToast) {
          var _rntpToastData = JSON.parse(_rntpStoredToast);
          setTimeout(function() {
            if (!_rntpToastData || !_rntpToastData.productName) return;
            var cartUrl  = _rntpToastData.cartUrl || '/cart';
            var addonHtml = (_rntpToastData.addonLines && _rntpToastData.addonLines.length)
              ? '<ul class="rntp-toast-addons">' + _rntpToastData.addonLines.map(function(l) { return '<li>' + l + '</li>'; }).join('') + '</ul>'
              : '';
            var html =
              '<div id="rntp-added-toast" class="rntp-toast">' +
                '<div class="rntp-toast-icon">&#10003;</div>' +
                '<div class="rntp-toast-body">' +
                  '<p class="rntp-toast-title">' + _rntpToastData.productName + (_rntpToastData.variantLabel ? ' &mdash; ' + _rntpToastData.variantLabel : '') + '</p>' +
                  '<p class="rntp-toast-sub">Added to cart with selected add-ons.</p>' +
                  addonHtml +
                  '<a href="' + cartUrl + '" class="rntp-toast-cart-link">View cart &rarr;</a>' +
                '</div>' +
                '<button class="rntp-toast-close" aria-label="Dismiss">&times;</button>' +
                '<div class="rntp-toast-progress"><div class="rntp-toast-progress-bar"></div></div>' +
              '</div>';
            jQuery('#rntp-added-toast').remove();
            jQuery('body').append(html);
            var $toast = jQuery('#rntp-added-toast');
            var duration = 10000;
            $toast.find('.rntp-toast-progress-bar').css('transition', 'width ' + duration + 'ms linear').css('width', '0%');
            var timer = setTimeout(function() {
              $toast.addClass('rntp-toast--hiding').delay(300).queue(function() { jQuery(this).remove().dequeue(); });
            }, duration);
            $toast.on('click', '.rntp-toast-close', function() {
              clearTimeout(timer);
              $toast.addClass('rntp-toast--hiding').delay(300).queue(function() { jQuery(this).remove().dequeue(); });
            });
          }, 600);
        }

      } else if ($modal.length) {
        // ---- FAILURE (server rejected, page reloaded) ----
        // Re-open modal and show the WC error notice inside it.
        // setTimeout(function() {
        //   // Collect error text from WC notices already rendered on the page
        //   var errMsg = '';
        //   jQuery('.woocommerce-error li, .woocommerce-notices-wrapper .woocommerce-error li').each(function() {
        //     var t = jQuery(this).text().trim();
        //     if (t) { errMsg = t; return false; }
        //   });
        //   if (!errMsg) {
        //     errMsg = 'Please check your selections and try again.';
        //   }

        //   // Open modal
        //   $modal.data('modal_is_open', 1).css('display', 'flex').hide(0).fadeIn(250);

        //   // Inject error at top of modal body
        //   $modal.find('.rntp-modal-inline-error').remove();
        //   var $err = jQuery(
        //     '<div class="rntp-modal-inline-error">' +
        //       '<span class="rntp-modal-inline-error__icon">&times;</span>' +
        //       '<span class="rntp-modal-inline-error__msg">' + errMsg + '</span>' +
        //     '</div>'
        //   );
        //   $modal.find('.rental-modal-body').prepend($err);

        //   // Hide the original WC error notice to avoid duplicate display
        //   jQuery('.woocommerce-notices-wrapper .woocommerce-error').hide();
        // }, 400);
      }
    }
  } catch(ex) {}


  var $cartBtn = $("form.cart").find(".single_add_to_cart_button");

  if ($('div.product').length) {
      var mainProductId = $cartBtn.val();

      if ($('div#product_has_rental_add_ons').length) {

          /* Product with addons' items variant_id fixing
          *
          */
          block($cartBtn);
          $.ajax({
              type: 'POST',
              url: rentalObj.url,
              dataType: 'JSON',
              data: {
                  action: 'rental_product_with_addons_fix_variants',
                  product_id: mainProductId
              },
              success: function() {
                // console.log(" addons variants fix ", data )
              },
              error: function(data) {
                console.log('error:', data)
              },
              complete: function() {
                unblock($cartBtn);
              }
          });
      }
  }

});
