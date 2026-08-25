/*--------------------------------------------------------------
Rentopian Products Options Functionality Scripts
Author: Rentopian
Website: https://rentopian.com
Copyright 2019, Rentopian Inc. All Rights Reserved.
----------------------------------------------------------------*/

/**
 * Whether prices must be hidden from the visitor.
 *
 * Option labels and the order-options totals are assembled here from the
 * raw prices the endpoints return, so they need the same gate the PHP
 * renderers use. Localized flags arrive as strings, so compare numerically.
 *
 * @param {string} context 'catalog' (product page) or 'cart' (cart rows).
 * @returns {boolean}
 */
function rentalOptionPricesHidden(context) {
  var flags = window.rentalPriceVisibility || {};
  var flag = context === 'cart' ? flags.hideCartPrices : flags.hideCatalogPrices;
  return parseInt(flag, 10) === 1;
}

/**
 * The single-product Add-to-Cart button.
 *
 * `form.cart .single_add_to_cart_button` matches nothing on builder and custom
 * templates, and an empty jQuery object is still truthy — so guards written
 * against it silently did nothing and left the button live while the options
 * were still loading. Widen the search and let callers check `.length`.
 *
 * @returns {jQuery}
 */
function rentalOptionsCartButton() {
  var $btn = jQuery("form.cart").find(".single_add_to_cart_button");
  if (!$btn.length) {
    $btn = jQuery(".single_add_to_cart_button, form.cart button[type='submit'], form.cart input[type='submit']").first();
  }
  return $btn;
}

/**
 * Whether this page has option selects, and whether they are still arriving.
 *
 * The gate needs to distinguish "no options to answer" from "options have not
 * loaded yet" — before they render there are no selects to inspect, but an add
 * submitted in that window carries no rental_options_selection at all.
 */
var _rentalOptionsGate = {
  loading: false,
  hasOptions: false,
  failed: false
};

/**
 * Lock or unlock Add to Cart.
 *
 * This must never touch the `disabled` class or attribute, and never touch a
 * `wc-*` class. WooCommerce owns those on a variable product: its own click
 * guard is `$(this).is('.disabled')`, and the RentPro theme's AJAX add checks
 * the same class. Removing it to signal "options are fine" also removed
 * WooCommerce's "no variation chosen yet" guard, which let the form submit with
 * variation_id=0 and produced WooCommerce's own "Please choose product options
 * for X." error, or its make-a-selection alert.
 *
 * Our lock is therefore purely additive: a namespaced class for the visual
 * state, and a capture-phase guard that intercepts only when our own condition
 * fails. When it passes we do nothing at all and stock behaviour is untouched.
 *
 * @param {boolean} locked
 * @param {string}  reason Shown as a title attribute while locked.
 */
function rentalOptionsSetCartButtonLocked(locked, reason) {
  var $btn = rentalOptionsCartButton();
  if (!$btn.length) {
    return;
  }
  if (locked) {
    $btn.addClass('rental-options-locked').attr('aria-disabled', 'true').attr('title', reason || '');
  } else {
    $btn.removeClass('rental-options-locked').removeAttr('aria-disabled').removeAttr('title');
  }
}

/**
 * Option selects still showing the "Please select an option" placeholder.
 *
 * @returns {Array<string>} Their labels.
 */
function rentalOptionsOpenChoices() {
  var open = [];
  rentalOptionsFormSelects().each(function() {
    var $select = jQuery(this);
    var $selected = $select.find('option:selected');
    var valueId = $selected.attr('id');
    if (typeof valueId === 'undefined' || valueId === '') {
      valueId = $selected.data('value-id');
    }
    if (typeof valueId === 'undefined' || valueId === '') {
      valueId = $selected.attr('value');
    }
    if (!valueId || String(valueId) === '-1') {
      open.push($select.data('option-title') || '');
    }
  });
  return open;
}

/**
 * The option selects that belong to this product's add-to-cart form.
 *
 * Only these decide whether Add to Cart may proceed. A cart-row select is the
 * one carrying its cart item key, so a cart page — or a mini-cart rendered
 * beside a product — can never be mistaken for an unanswered product option.
 *
 * @returns {jQuery}
 */
function rentalOptionsFormSelects() {
  return jQuery('.rental-product-options-select').not('[data-key]');
}

/**
 * Show or clear the inline message naming the options still to be chosen.
 *
 * @param {Array<string>} open
 */
function rentalOptionsRenderChoiceHint(open) {
  var $holder = jQuery('.rental-product-options');
  var $hint = $holder.find('.rental-product-options-hint');

  if (!open.length) {
    $hint.remove();
    return;
  }

  var text = rentalOptionsChoiceHintText(open);
  if (!$hint.length) {
    $hint = jQuery("<p class='rental-product-options-hint' role='status'></p>");
    $holder.append($hint);
  }
  $hint.text(text);
}

/**
 * @param {Array<string>} open
 * @returns {string}
 */
function rentalOptionsChoiceHintText(open) {
  var labels = open.filter(function(label) { return !!label; });
  var strings = (window.rentalObj && rentalObj.optionsStrings) || {};
  var template = strings.chooseOptions || 'Please choose: %s';
  if (!labels.length) {
    return strings.chooseAnyOption || 'Please choose an option above.';
  }
  return template.replace('%s', labels.join(', '));
}

/**
 * @returns {string}
 */
function rentalOptionsLoadFailedText() {
  var strings = (window.rentalObj && rentalObj.optionsStrings) || {};
  return strings.loadFailed || 'The options for this product could not be loaded. Please reload the page and try again.';
}

/**
 * Variation ids that declare option ids of their own.
 *
 * wp_localize_script casts every scalar to a string, including array members,
 * so these arrive as ["11585"] rather than [11585]. Comparing a number against
 * that never matches, and the mismatch is invisible — it just looks like the
 * product has no per-variation options. Normalize once, here.
 *
 * @returns {Array<number>}
 */
function rentalOptionsVariationOverrides() {
  var raw = (window.rentalProductOptions || {}).variationOverrides || [];
  var out = [];
  for (var i = 0; i < raw.length; i++) {
    var id = parseInt(raw[i], 10);
    if (id) {
      out.push(id);
    }
  }
  return out;
}

/**
 * Why this module would refuse an add right now, or '' when it would not.
 *
 * This is the ONLY thing that decides whether we interfere. It speaks purely
 * about options — never about variations, stock, or anything else WooCommerce
 * already governs.
 *
 * @returns {string}
 */
function rentalOptionsBlockReason() {
  if (_rentalOptionsGate.loading) {
    var strings = (window.rentalObj && rentalObj.optionsStrings) || {};
    return strings.stillLoading || 'One moment — loading the options for this product.';
  }

  if (_rentalOptionsGate.failed) {
    return rentalOptionsLoadFailedText();
  }

  if (!rentalOptionsFormSelects().length) {
    return '';
  }

  var open = rentalOptionsOpenChoices();

  return open.length ? rentalOptionsChoiceHintText(open) : '';
}

/**
 * Keep Add to Cart in step with the selects.
 *
 * The server resolves an option from the submission, the cart line, the
 * session and finally the option default. An option showing the placeholder is
 * one the server could not resolve either, so blocking here shows the customer
 * the same answer the gate would give — before they submit.
 *
 * @returns {boolean} Whether this module would allow the add.
 */
function rentalOptionsSyncCartButton() {
  var reason = rentalOptionsBlockReason();

  if (jQuery('.rental-product-options-select').length) {
    rentalOptionsRenderChoiceHint(rentalOptionsOpenChoices());
  }

  rentalOptionsSetCartButtonLocked(!!reason, reason);

  return !reason;
}

jQuery(document).ready(function($) {

  // Determine page context
  var isSingleProductPage = $('body').hasClass('single-product');
  // var isCartPage = $('body').hasClass('woocommerce-cart') || $('.woocommerce-cart-form').length > 0;
  // var isCheckoutPage = $('body').hasClass('woocommerce-checkout');

  // Single product page specific variables (only initialize if on single product page)
  var $cartBtn = null;
  var $product_div = null;
  var $selectOptionHolder = null;

  if (isSingleProductPage) {
    $cartBtn = $("form.cart").find(".single_add_to_cart_button");
  // Find the main product wrapper
  // We look for 'type-product' which is injected by WP core post_class()
  var $product_div = $('.type-product').closest('#brx-content, #main, .site-main, body').find('.type-product').first();

  // Fallback: if the builder is very messy, just find the first .product
  if (!$product_div.length) {
      $product_div = $('.product').first();
  }

  var $selectOptionHolder = $product_div.find(".rental-product-options");

  /**
   * The id to ask for options with.
   *
   * On a variable product this used to be the variation id, which is 0 until
   * every attribute has been chosen — so the options stayed invisible until
   * the customer had picked a size and a colour, for no reason: options hang
   * off the parent and a variation resolves to the same set.
   *
   * The parent is therefore used from the start. A variation id is used only
   * once one is chosen AND that variation declares options of its own, which
   * the server tells us up front.
   *
   * @returns {number}
   */
  function rentalOptionsRequestId() {
    var page = window.rentalProductOptions || {};
    var overrides = rentalOptionsVariationOverrides();

    if (overrides.length) {
      var variationId = parseInt($('input.variation_id').val(), 10) || 0;
      if (variationId && overrides.indexOf(variationId) !== -1) {
        return variationId;
      }
    }

    var parentId = parseInt(page.parentId, 10) || 0;
    if (parentId) {
      return parentId;
    }

    // Fallbacks for templates that do not go through the module's enqueue.
    var formId = parseInt($('form.variations_form').data('product_id'), 10) || 0;
    if (formId) {
      return formId;
    }

    return parseInt($cartBtn.val(), 10) || 0;
  }

  // single product page related
  // get all options of a product including selected ones
  function get_options_of_single_product() {
    if ($product_div.length) {

      // Lock immediately. The selects do not exist yet, so an add submitted
      // during this window carries no rental_options_selection at all.
      _rentalOptionsGate.loading = true;
      rentalOptionsSyncCartButton();

      (function() {

        let $isSetOptions = $("div.rental-is-set");
        var product_id = rentalOptionsRequestId();

        if (!product_id) {
          _rentalOptionsGate.loading = false;
          rentalOptionsSyncCartButton();
          return;
        }

        block($selectOptionHolder);
        $selectOptionHolder.addClass('is-loading');
        $.ajax({
          type: 'POST',
          url: rentalObj.url,
          dataType: 'JSON',
          data: {
              action: 'rental_get_all_options_of_product',
              product_id: product_id,
              is_set: $isSetOptions.length // check if it is a SET
          },
          success: function(data) {
          },
          error: function(data) {
            console.log('error:', data )
          },
          complete: function(data) {
            var selectedOptions = [];
            if (data.responseText != '' && data.responseJSON) {
              selectedOptions = data.responseJSON;
            }
           
            if (selectedOptions.length) {

              $selectOptionHolder.html("");
              for(var i=0; i<selectedOptions.length; i++) {
                if (selectedOptions[i]["once_per_order"] === 1) {
                  var $once_per_order_selected_msg = "<label for='" + selectedOptions[i]["option_id"] + "'> " + selectedOptions[i]["option_title"] + " </label>";
                  $once_per_order_selected_msg += "<small> The Option is a once per order type. </small>";
                  $selectOptionHolder.append($once_per_order_selected_msg);
                  continue;
                }
                var $select = "<label for='" + selectedOptions[i]["option_id"] + "'> " + selectedOptions[i]["option_title"] + " </label>";
                // name="rental_options_selection[<option_id>]" makes the select
                // submit WITH the add-to-cart form, so the customer's pick is
                // captured at add directly from $_POST — no dependency on the
                // debounced AJAX having completed (race-free single source).
                $select += "<select name='rental_options_selection[" + selectedOptions[i]["option_id"] + "]' onchange='update_option_data_of_single_product(this)' data-is-set='" + $isSetOptions.length + "' data-product-id='" + selectedOptions[i]["product_id"] + "' data-option-title='" + jQuery('<div/>').text(selectedOptions[i]["option_title"]).html() + "' data-requires-choice='" + (selectedOptions[i]["requires_choice"] ? 1 : 0) + "' id='" + selectedOptions[i]["option_id"] + "' class='rental-product-options-select'>";
                var $option = "";
                // The server already resolved this option from the submission,
                // the cart line, the session and the option default, and sends
                // the answer as selected_value_id. Deciding again here — the
                // old strict is_default check — is what let the page show one
                // value selected while the gate considered the option unset.
                var $selectedValueId = Number(selectedOptions[i]["selected_value_id"]) || 0;
                for(var v=0; v<selectedOptions[i]['option_values'].length; v++) {

                  var $item = "<option data-selected='0' id='" + selectedOptions[i]['option_values'][v]["id"] + "'  data-value='" + selectedOptions[i]['option_values'][v]["price"] + "' value='" + selectedOptions[i]['option_values'][v]["id"] + "' ";

                    if ($selectedValueId && Number(selectedOptions[i]['option_values'][v]["id"]) === $selectedValueId) {
                      $item += " selected='' data-selected='1' ";
                    }

                  var $price = Number(selectedOptions[i]['option_values'][v]["price"]);
                  if ($price != 0 && $price != -1 && !rentalOptionPricesHidden('catalog')) {
                    $item += "> " + selectedOptions[i]['option_values'][v]["title"] + " (" + selectedOptions[i]["currency"] + $price + ")";
                  } else {
                    $item += "> " + selectedOptions[i]['option_values'][v]["title"];
                  }
                  $item += " </option>";
                  $option += $item;
                }
                $select += $option + "</select></div>";
                $selectOptionHolder.append($select);

              }

              _rentalOptionsGate.hasOptions = true;
              _rentalOptionsGate.loading = false;
              _rentalOptionsGate.failed = false;
              rentalOptionsSyncCartButton();

            } else if (data.status >= 200 && data.status < 300) {
              // The endpoint answered and this product has no options.
              _rentalOptionsGate.hasOptions = false;
              _rentalOptionsGate.loading = false;
              _rentalOptionsGate.failed = false;
              rentalOptionsSyncCartButton();

            } else {
              // The endpoint failed, so no selects exist to submit. Releasing
              // the gate here would hand the customer a button whose add the
              // server rejects with an options error they cannot satisfy.
              _rentalOptionsGate.loading = false;
              _rentalOptionsGate.hasOptions = false;
              _rentalOptionsGate.failed = true;
              rentalOptionsSyncCartButton();
              $selectOptionHolder.html("<p class='rental-product-options-error' role='alert'>" + rentalOptionsLoadFailedText() + "</p>");
            }

            $selectOptionHolder.removeClass('is-loading');
            unblock($selectOptionHolder);
          }
        });

      })();
    }
  }

  // Last line of defence in the browser: never let an add go out while one of
  // OUR options is unanswered. The server would reject it anyway, and the
  // customer would read an error about a select they can see on screen.
  //
  // Capture phase, so we run before WooCommerce's variation guard and before
  // the theme's AJAX add. We intercept ONLY when rentalOptionsBlockReason()
  // has something to say; otherwise the event is passed through untouched and
  // both of those behave exactly as they do without this plugin. In
  // particular, a missing variation is WooCommerce's business, not ours — it
  // already blocks that, and we must not speak for it.
  function rentalOptionsInterceptAdd(event) {
    var target = event.target;
    if (!target || !target.closest) {
      return;
    }

    // Match the real add-to-cart button when the theme provides one, and only
    // fall back to a generic submit when it does not — a broad selector would
    // otherwise catch quantity steppers that happen to be submit buttons.
    var button = target.closest('.single_add_to_cart_button');
    if (!button && !document.querySelector('.single_add_to_cart_button')) {
      button = target.closest('form.cart button[type="submit"], form.cart input[type="submit"]');
    }
    if (!button) {
      return;
    }

    var reason = rentalOptionsBlockReason();
    if (!reason) {
      return; // Nothing of ours is outstanding. Stay out of the way.
    }

    event.preventDefault();
    event.stopPropagation();

    rentalOptionsSyncCartButton();

    var hint = document.querySelector('.rental-product-options-hint');
    if (hint && hint.scrollIntoView) {
      hint.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
  }

  document.addEventListener('click', rentalOptionsInterceptAdd, true);

  // A form can also be submitted by keyboard without a button click.
  document.addEventListener('submit', function(event) {
    var form = event.target;
    if (!form || !form.classList || !form.classList.contains('cart')) {
      return;
    }
    if (!rentalOptionsBlockReason()) {
      return;
    }
    event.preventDefault();
    event.stopPropagation();
    rentalOptionsSyncCartButton();
  }, true);

  if ($(".rental-product-options").length) {

    // Load from the parent straight away, on every product type. A variable
    // product no longer waits for its attributes to be chosen.
    get_options_of_single_product();

    // Only variations that carry option ids of their own justify a reload;
    // otherwise the set is identical to the parent's and the selects already
    // on screen stay valid.
    var $variationInput = $(".woocommerce-variation-add-to-cart").find('input.variation_id');
    var rentalOptionsOverrides = rentalOptionsVariationOverrides();

    if ($variationInput.length && rentalOptionsOverrides.length) {
      $variationInput.on("change", function() {
        $(".rental-product-options").empty();
        get_options_of_single_product();
      });
    }
  }


  } // End of isSingleProductPage block

  // cart page related
  // get all options of a all cart products including selected ones
  function get_options_of_cart_products() {
    // indicate we are in cart page
    if ($('.woocommerce-cart-form').length) {

      var $productOptionsBlock = $(".woocommerce-cart-form table tbody").find(".cart_item td.product-options");
      block($productOptionsBlock);
      $.ajax({
        type: 'POST',
        url: rentalObj.url,
        dataType: 'JSON',
        data: {
            action: 'rental_get_all_options_of_cart_products'
        },
        success: function(data) {
        },
        error: function(data) {
          console.log('error:', data )
        },
        complete: function(data) {
          var selectedOptions = [];
          if (data.responseText !== '' && data.responseJSON) {
            selectedOptions = data.responseJSON;
          }
         
          if (selectedOptions.length) {
            
              $cartItem = $(".woocommerce-cart-form table tbody").find(".cart_item");
              $cartItem.each(function(){
                var $productOptions = $(this).find("td.product-options");
                $productOptions.each(function(){
                  var $currentOption = $(this);
                  var cellCartKey = $currentOption.data("cart-key");
                  for(var i=0; i<selectedOptions.length; i++) {
                    // Use loose equality (==) to handle string/number type differences
                    if ($currentOption.data("product-id") != selectedOptions[i]["product_id"]) {
                      continue;
                    }
                    // Also check cart_item_key to ensure options only appear in their correct row
                    if (cellCartKey && selectedOptions[i]["key"] && cellCartKey != selectedOptions[i]["key"]) {
                      continue;
                    }
                    $(this).append(rentalOptionsBuildCartOptionHtml(selectedOptions[i]));
                  }
                });
              })

            
          }
          unblock($productOptionsBlock);
        }
      });
    }
  }
  get_options_of_cart_products();

  // product specific once per order options (cart page related)
  function get_order_options_of_cart_page() {
    if ($('tr.order-options').length) {

        var $orderOptionsRow =      $("tr.order-options");
        var $orderOptionsBlock =    $orderOptionsRow.find("td.order-options-td");
        var $orderOptionsPrice =    $orderOptionsRow.find("td.order-options-price");
        var $orderOptionsSubtotal = $orderOptionsRow.find("td.order-options-subtotal");

        block($orderOptionsBlock);
        block($orderOptionsPrice);
        block($orderOptionsSubtotal);
        $.ajax({
          type: 'POST',
          url: rentalObj.url,
          dataType: 'JSON',
          data: {
              action: 'rental_get_order_options'
          },
          success: function(data) {
          },
          error: function(data) {
            console.log('error:', data )
          },
          complete: function(data) {
            var selectedOptions = [];
            if (data.responseText !== '' && data.responseJSON) {
              selectedOptions = data.responseJSON;
            }
           
            if (selectedOptions.length) {
              $orderOptionsBlock.html("");
              $orderOptionsPrice.html("");
              $orderOptionsSubtotal.html("");
              var totalPrice = 0;
              var currency = selectedOptions[0]["currency"] || '';
              for(var i=0; i<selectedOptions.length; i++) {
                var $select = "<div class='rental-product-options'>"; 
                $select += "<label> " + selectedOptions[i]["option_title"] + " </label>";
                $select += "<select onchange='update_order_option_data(this)'  id='" + selectedOptions[i]["option_id"] + "' class='rental-order-options-select'>";
                var $option = "";
                var alreadySelectedValue = false;
                for(var v=0; v<selectedOptions[i]['option_values'].length; v++) {
                  var $item = "<option data-selected='0' id='" + selectedOptions[i]['option_values'][v]["id"] + "'  data-value='" + selectedOptions[i]['option_values'][v]["price"] + "' value='" + selectedOptions[i]['option_values'][v]["id"] + "' ";

                  var $price = Number(selectedOptions[i]['option_values'][v]["price"]);
                  if (selectedOptions[i]['is_selected'] === 1 && selectedOptions[i]['option_values'][v]["id"] === selectedOptions[i]["selected_value_id"]) {
                    $item += " selected='' data-selected='1' ";
                    alreadySelectedValue = true;

                    // calculating price/subtotal of order specific options
                    totalPrice += $price >= 0 ? $price : 0;
                    currency = selectedOptions[i]["currency"];

                  } else {
                    if (selectedOptions[i]['option_values'][v]["is_default"] === 1 && !alreadySelectedValue) {
                      $item += " selected='' data-selected='1' ";
                      alreadySelectedValue = true;
                    } 
                    // else {
                    //   if (selectedOptions[i]['option_values'][v]["id"] == -1) {
                    //     $item += " selected='' data-selected='1' ";
                    //   }
                    // }
                  }

                  if (!isNaN($price) && $price !== 0 && $price !== -1 && !rentalOptionPricesHidden('cart')) {
                    $item += "> " + selectedOptions[i]['option_values'][v]["title"] + " (" + selectedOptions[i]["currency"] + $price.toFixed(2) + ")";
                  } else {
                    $item += "> " + selectedOptions[i]['option_values'][v]["title"];
                  }
                  $item += " </option>";
                  $option += $item;
                }
                $select += $option + "</select></div>";
                $orderOptionsBlock.append($select);
              }
              if (!rentalOptionPricesHidden('cart')) {
                $orderOptionsPrice.append('<b>'+ currency + totalPrice.toFixed(2) +'</b>');
                $orderOptionsSubtotal.append('<b>'+ currency + totalPrice.toFixed(2) +'</b>');
              }

            }
            unblock($orderOptionsBlock); 
            unblock($orderOptionsPrice);
            unblock($orderOptionsSubtotal);
          }
        });
    }
  }
  get_order_options_of_cart_page();

   // product specific once per order options (cart page related)
  function get_sets_order_options_of_cart_page() {
    if ($('tr.sets-order-options').length) {

        var $orderOptionsRow =      $("tr.sets-order-options");
        var $orderOptionsBlock =    $orderOptionsRow.find("td.order-options-td");
        var $orderOptionsPrice =    $orderOptionsRow.find("td.order-options-price");
        var $orderOptionsSubtotal = $orderOptionsRow.find("td.order-options-subtotal");

        block($orderOptionsBlock);
        block($orderOptionsPrice);
        block($orderOptionsSubtotal);
        $.ajax({
          type: 'POST',
          url: rentalObj.url,
          dataType: 'JSON',
          data: {
              action: 'rental_get_order_options_of_sets'
          },
          success: function(data) {
          },
          error: function(data) {
            console.log('error:', data )
          },
          complete: function(data) {
            var selectedOptions = [];
            if (data.responseText !== '' && data.responseJSON) {
              selectedOptions = data.responseJSON;
            }
           
            if (selectedOptions.length) {
              $orderOptionsBlock.html("");
              $orderOptionsPrice.html("");
              $orderOptionsSubtotal.html("");
              var totalPrice = 0;
              var currency = selectedOptions[0]["currency"] || '';
              for(var i=0; i<selectedOptions.length; i++) {
                var $select = "<div class='rental-product-options'>"; 
                $select += "<label> " + selectedOptions[i]["option_title"] + " </label>";
                $select += "<select onchange='update_order_option_data_of_sets(this)'  id='" + selectedOptions[i]["option_id"] + "' class='rental-order-options-select'>";
                var $option = "";
                var alreadySelectedValue = false;
                for(var v=0; v<selectedOptions[i]['option_values'].length; v++) {
                  var $item = "<option data-selected='0' id='" + selectedOptions[i]['option_values'][v]["id"] + "'  data-value='" + selectedOptions[i]['option_values'][v]["price"] + "' value='" + selectedOptions[i]['option_values'][v]["id"] + "' ";

                  var $price = Number(selectedOptions[i]['option_values'][v]["price"]);
                  if (selectedOptions[i]['is_selected'] === 1 && selectedOptions[i]['option_values'][v]["id"] === selectedOptions[i]["selected_value_id"]) {
                    $item += " selected='' data-selected='1' ";
                    alreadySelectedValue = true;

                    // calculating price/subtotal of order specific options
                    totalPrice += $price >= 0 ? $price : 0;
                    currency = selectedOptions[i]["currency"];

                  } else {
                    if (selectedOptions[i]['option_values'][v]["is_default"] === 1 && !alreadySelectedValue) {
                      $item += " selected='' data-selected='1' ";
                      alreadySelectedValue = true;
                    } 
                    // else {
                    //   if (selectedOptions[i]['option_values'][v]["id"] == -1) {
                    //     $item += " selected='' data-selected='1' ";
                    //   }
                    // }
                  }

                  if (!isNaN($price) && $price !== 0 && $price !== -1 && !rentalOptionPricesHidden('cart')) {
                    $item += "> " + selectedOptions[i]['option_values'][v]["title"] + " (" + selectedOptions[i]["currency"] + $price.toFixed(2) + ")";
                  } else {
                    $item += "> " + selectedOptions[i]['option_values'][v]["title"];
                  }
                  $item += " </option>";
                  $option += $item;
                }
                $select += $option + "</select></div>";
                $orderOptionsBlock.append($select);
              }
              if (!rentalOptionPricesHidden('cart')) {
                $orderOptionsPrice.append('<b>'+ currency + totalPrice.toFixed(2) +'</b>');
                $orderOptionsSubtotal.append('<b>'+ currency + totalPrice.toFixed(2) +'</b>');
              }

            }
            unblock($orderOptionsBlock); 
            unblock($orderOptionsPrice);
            unblock($orderOptionsSubtotal);
          }
        });
    }
  }
  get_sets_order_options_of_cart_page();
  
  function get_order_options_effected_subtotal() {
    if ($('tr.order-options').length) {
      var subtotalPriceHolder = jQuery(".cart_totals").find('[data-title="Subtotal"] .woocommerce-Price-amount')
      
        block(subtotalPriceHolder);
        jQuery.ajax({
          type: 'POST',
          url: rentalObj.url,
          dataType: 'JSON',
          data: {
              action: 'rental_get_order_options_effected_subtotal'
          },
          success: function(data) {
          },
          error: function(data) {
            console.log('error:', data )
          },
          complete: function(data) {
            var serverData = data.responseJSON;
    
            if (serverData !== '') {
                if (serverData.subtotal !== undefined && serverData.subtotal !== '' ) {
                  subtotalPriceHolder.html('<bdi><span class="woocommerce-Price-currencySymbol">'+serverData.currency+'</span>'+serverData.subtotal+'</bdi>')
                }
            }
    
            unblock(subtotalPriceHolder);
          }
        });
    }
  }
  setTimeout(() => {
    get_order_options_effected_subtotal();
  }, 200);
  
  // cart page related (trigger on cart item quantity update)
  $(document.body).on( 'updated_cart_totals', function(){
      get_options_of_cart_products();
      get_order_options_of_cart_page();
      get_sets_order_options_of_cart_page();
      get_order_options_effected_subtotal();
  });

  // Mini-cart (fly-in cart) options display
  // DISABLED: Client-side options injection is no longer needed.
  // Options are now rendered server-side via woocommerce_get_item_data filter
  // which outputs proper <dl class="variation"> markup in the WC fragments.
  // No AJAX call needed here - the fragment refresh already contains the correct HTML.
  // function display_mini_cart_options() {
  //   // Nothing to do - server-side rendering handles this now
  //   return;

  //   // DISABLED - all original code removed; server-side rendering handles mini-cart options now
  // }

  // // Trigger mini-cart options display on various events
  // display_mini_cart_options();
  
  // // When mini-cart is updated via AJAX fragments
  // $(document.body).on('wc_fragments_refreshed wc_fragments_loaded', function(e) {
  //   display_mini_cart_options();
  // });
  
  // // When fly-in cart is opened (for themes that lazy-load mini-cart)
  // $(document).on('click', '.mini-cart__button, .header-minicart .toggle, [data-toggle="minicart"]', function() {
  //   setTimeout(function() {
  //     display_mini_cart_options();
  //   }, 300);
  // });

  // // Listen for custom event when options are updated on single product page
  // // This allows the global function update_option_data_of_single_product to trigger a mini-cart refresh
  // $(document.body).on('rental_product_options_updated', function() {
  //   display_mini_cart_options();
  // });

  $miniCartCheckoutBtn = $(".minicart-dropdown-wrapper .woocommerce-mini-cart__buttons a.checkout")
  $miniCartCheckoutBtn.on("click", function(e) {
    e.preventDefault();

    block($miniCartCheckoutBtn);
    $.ajax({
      type: 'POST',
      url: rentalObj.url,
      dataType: 'JSON',
      data: {
          action: 'rental_check_selected_options'
      },
      success: function(data) {
        // console.log(' check:',  data)
  
        if (data.check !== true) {

          // inside cart page
          if (window.location.href.indexOf("cart") > -1) {
            // customer needs to select a value for each products' options
            errorMsg("Please, review product options before checking out.");
            $([document.documentElement, document.body]).animate({
              scrollTop: $(".rntl-opt-err").offset().top - 120
            }, 1000);
          } else {
            // inside other pages
            window.location = data.url_cart;
          }
         
        } else {
          errorMsg("");
          window.location = data.url;
        }
  
      },
      error: function(data) {
        console.log('error:', data )
      },
      complete: function() {
        unblock($miniCartCheckoutBtn);
        $(".minicart-dropdown-wrapper").find(".close-btn").click()
      }
    });
  })

  
});

/**
 * Overlay a node while it is being refreshed.
 *
 * `.block()` comes from blockUI, which WooCommerce enqueues — but not on every
 * template, and not when another plugin deregisters it. An exception here used
 * to abort whatever was running, which on the product page meant the add-to-cart
 * guard below never got registered. It is a purely cosmetic overlay, so it must
 * never be able to take anything else down with it.
 */
function block($node) {
  try {
    if (!$node.is('.processing') && !$node.parents('.processing').length) {
      $node.addClass('processing');
      if (typeof $node.block === 'function') {
        $node.block({
          message: null,
          overlayCSS: {
            background: '#fff',
            opacity: 0.6
          }
        });
      }
    }
  } catch (e) {}
}

function unblock($node) {
  try {
    $node.removeClass('processing');
    if (typeof $node.unblock === 'function') {
      $node.unblock();
    }
  } catch (e) {}
}

$checkoutBtn = jQuery(".wc-proceed-to-checkout").find(".checkout-button")

// DOM id for one option on one cart row. Scoping by cart key keeps two rows
// that share an option definition from colliding on the same id.
function rentalOptionsCartFieldId(cartKey, optionId) {
  return 'rental-cart-option-' + String(cartKey || 'x') + '-' + String(optionId);
}

/**
 * The value a cart row shows for one option.
 *
 * Decided once, before any markup is written, so exactly one option element
 * ends up carrying `selected`.
 *
 * @param {Object} entry One option of one cart row, as the server sent it.
 * @returns {number|null} The value id, or null when the row has no answer.
 */
function rentalOptionsCartSelectedValueId(entry) {
  if (entry.is_selected === 1) {
    return entry.selected_value_id;
  }
  for (var d = 0; d < entry.option_values.length; d++) {
    if (entry.option_values[d].is_default === 1) {
      return entry.option_values[d].id;
    }
  }
  return null;
}

/**
 * The markup for one option of one cart row.
 *
 * Every control is addressed by the cart key as well as the option id: two rows
 * can share an option definition, so the option id alone is not unique on the
 * page, and an edit to one row must never be able to reach another.
 *
 * @param {Object} entry One option of one cart row, as the server sent it.
 * @returns {string} HTML.
 */
function rentalOptionsBuildCartOptionHtml(entry) {
  var fieldId = rentalOptionsCartFieldId(entry.key, entry.option_id);

  if (entry.once_per_order === 1) {
    return "<label for='" + fieldId + "'> " + entry.option_title + " </label>"
      + "<small> The Option is a once per order type. </small>";
  }

  var selectedValueId = rentalOptionsCartSelectedValueId(entry);
  var html = "<div class='rental-product-options'>";

  html += "<label for='" + fieldId + "'> " + entry.option_title + " </label>";
  html += "<select onchange='update_option_data(this)'"
    + " data-key='" + entry.key + "'"
    + " data-product-id='" + entry.product_id + "'"
    + " data-option-id='" + entry.option_id + "'"
    + " id='" + fieldId + "' class='rental-product-options-select'>";

  for (var v = 0; v < entry.option_values.length; v++) {
    var value = entry.option_values[v];
    var isSelected = selectedValueId !== null && value.id === selectedValueId;

    html += "<option data-selected='" + (isSelected ? 1 : 0) + "'"
      + " data-value-id='" + value.id + "'"
      + " data-value='" + value.price + "'"
      + " value='" + value.id + "'"
      + (isSelected ? " selected=''" : "") + ">";

    var price = Number(value.price);
    if (!isNaN(price) && price !== 0 && price !== -1 && !rentalOptionPricesHidden('cart')) {
      html += " " + value.title + " (" + entry.currency + price.toFixed(2) + ")";
    } else {
      html += " " + value.title;
    }
    html += " </option>";
  }

  return html + "</select></div>";
}

function update_option_data(element) {
  var target = jQuery(element);
  var key = target.data('key');
  var product_id = target.data('product-id');
  var $selected = target.find('option:selected');
  var value_id = $selected.data('value-id');
  var price = $selected.data('value');
  var option_id = target.data('option-id');

  // Markup written before the per-row ids carried the option id in `id` and the
  // value id in the option's `id`.
  if (typeof option_id === 'undefined' || option_id === null || option_id === '') {
    option_id = target.attr('id');
  }
  if (typeof value_id === 'undefined' || value_id === null || value_id === '') {
    value_id = $selected.attr('value') || $selected.attr('id');
  }

  var $cartTotals = jQuery(".cart_totals");

  var productTotalPriceHolder = jQuery("#"+key+"_subtotal").find('.woocommerce-Price-amount');
  var productTotalPriceHolder_alternative = jQuery("#"+product_id+"_subtotal").find('.woocommerce-Price-amount');

  var subtotalPriceHolder = $cartTotals.find('[data-title="Subtotal"] .woocommerce-Price-amount');
  var subtotalPriceHolder_alternative = $cartTotals.find('.cart-subtotal .cart-totals-value .woocommerce-Price-amount');

  var totalPriceHolder = $cartTotals.find('[data-title="Total"] .woocommerce-Price-amount');
  var totalPriceHolder_alternative = $cartTotals.find('.order-total .cart-totals-value .woocommerce-Price-amount');
  
    block($checkoutBtn);
    block(productTotalPriceHolder);
    block(productTotalPriceHolder_alternative);
    block(subtotalPriceHolder);
    block(subtotalPriceHolder_alternative);
    block(totalPriceHolder);
    block(totalPriceHolder_alternative);

    jQuery.ajax({
      type: 'POST',
      url: rentalObj.url,
      dataType: 'JSON',
      data: {
          action: 'rental_update_product_option',
          product_id: product_id,
          option_id: option_id,
          value_id: value_id,
          price: price,
          // The cart row already knows which line it is. Sending it stops the
          // server from having to guess by product id, which picks the first
          // matching line and can land on an add-on child.
          cart_item_key: key || '',
      },
      success: function(data) {
      },
      error: function(data) {
        console.log('error:', data )
      },
      complete: function(data) {
        var serverData="";
        if (data.responseText !== '' &&  data.responseJSON) {
          serverData = data.responseJSON;
        }

        if (serverData !== '') {
            if (serverData.total !== undefined 
              && serverData.subtotal !== undefined 
              && serverData.price_total !== undefined 
            ) {
              totalPriceHolder.html('<bdi><span class="woocommerce-Price-currencySymbol">'+serverData.currency+'</span>'+serverData.total+'</bdi>')
              totalPriceHolder_alternative.html('<bdi><span class="woocommerce-Price-currencySymbol">'+serverData.currency+'</span>'+serverData.total+'</bdi>')
              subtotalPriceHolder.html('<bdi><span class="woocommerce-Price-currencySymbol">'+serverData.currency+'</span>'+serverData.subtotal+'</bdi>')
              subtotalPriceHolder_alternative.html('<bdi><span class="woocommerce-Price-currencySymbol">'+serverData.currency+'</span>'+serverData.subtotal+'</bdi>')
              productTotalPriceHolder.html('<bdi><span class="woocommerce-Price-currencySymbol">'+serverData.currency+'</span>'+serverData.price_total+'</bdi>')
              productTotalPriceHolder_alternative.html('<bdi><span class="woocommerce-Price-currencySymbol">'+serverData.currency+'</span>'+serverData.price_total+'</bdi>')
            }

            var $cartTotals = jQuery(".cart_totals");

            if (serverData.security_deposit_title !== undefined && serverData.security_deposit_title.trim() !== '') {
              $cartTotals.find('[data-title="'+serverData.security_deposit_title.trim()+'"] .woocommerce-Price-amount').html('<bdi><span class="woocommerce-Price-currencySymbol">'+serverData.currency+'</span>'+serverData.security_deposit_value+'</bdi>')
            }
    
            if ( serverData.is_auto_tax === 1) {
              if (serverData.tax_title !== undefined && serverData.tax_title.trim() !== '') {
                $cartTotals.find('tr.auto-tax-key').html('<th>' + serverData.tax_title.trim() + '</th><td class="auto-tax-value"></td>')
                $cartTotals.find('td.auto-tax-value').html(serverData.currency+serverData.tax_value)
              }
            } else {
              if (serverData.tax_title !== undefined && serverData.tax_title.trim() !== '') {
                $cartTotals.find('[data-title="'+serverData.tax_title.trim()+'"] .woocommerce-Price-amount').html('<bdi><span class="woocommerce-Price-currencySymbol">'+serverData.currency+'</span>'+serverData.tax_value+'</bdi>')
              }
            }
            
            
            if (serverData.damage_waiver_title !== undefined && serverData.damage_waiver_title.trim() !== '') {
              $cartTotals.find('[data-title="'+serverData.damage_waiver_title.trim()+'"] .woocommerce-Price-amount').html('<bdi><span class="woocommerce-Price-currencySymbol">'+serverData.currency+'</span>'+serverData.damage_waiver_value+'</bdi>')
            }
    
            if (serverData.damage_waiver_tax_title !== undefined && serverData.damage_waiver_tax_title.trim() !== '') {
              $cartTotals.find('[data-title="'+serverData.damage_waiver_tax_title.trim()+'"] .woocommerce-Price-amount').html('<bdi><span class="woocommerce-Price-currencySymbol">'+serverData.currency+'</span>'+serverData.damage_waiver_tax_value+'</bdi>')
            }
    
            if (serverData.rush_fee_title !== undefined && serverData.rush_fee_title.trim() !== '') {
              $cartTotals.find('[data-title="'+serverData.rush_fee_title.trim()+'"] .woocommerce-Price-amount').html('<bdi><span class="woocommerce-Price-currencySymbol">'+serverData.currency+'</span>'+serverData.rush_fee_value+'</bdi>')
            }

            if (serverData.auto_applied_fees !== undefined && serverData.auto_applied_fees.length > 0) {
              for(var i=0; i < serverData.auto_applied_fees.length; i++) {
                $cartTotals.find('[data-title="'+serverData.auto_applied_fees[i]["title"].trim()+'"] .woocommerce-Price-amount').html('<bdi><span class="woocommerce-Price-currencySymbol">'+serverData.currency+'</span>'+serverData.auto_applied_fees[i]["amount"]+'</bdi>')
              }
            }
        }

        unblock(productTotalPriceHolder_alternative);
        unblock(productTotalPriceHolder);
        unblock(subtotalPriceHolder);
        unblock(subtotalPriceHolder_alternative);
        unblock(totalPriceHolder);
        unblock(totalPriceHolder_alternative);
        unblock($checkoutBtn);

        // Robust WooCommerce cart fragment refresh with proper timing
        jQuery(document.body).trigger('wc_fragment_refresh');
        if (typeof wc_cart_fragments_params !== 'undefined') {
          jQuery.ajax({
            url: wc_cart_fragments_params.wc_ajax_url.toString().replace('%%endpoint%%', 'get_refreshed_fragments'),
            type: 'POST',
            data: { time: new Date().getTime() },
            success: function(fragmentData) {
              if (fragmentData && fragmentData.fragments) {
                jQuery.each(fragmentData.fragments, function(key, value) {
                  jQuery(key).replaceWith(value);
                });
                jQuery(document.body).trigger('wc_fragments_refreshed');
                // Refresh options AFTER fragment DOM replacement
                setTimeout(function() {
                  jQuery(document.body).trigger('rental_product_options_updated');
                }, 100);
              }
            },
            error: function() {
              jQuery(document.body).trigger('rental_product_options_updated');
            }
          });
        } else {
          jQuery(document.body).trigger('rental_product_options_updated');
        }
        // Delayed fallback
        setTimeout(function() {
          jQuery(document.body).trigger('rental_product_options_updated');
        }, 800);
      }
    });
}

// product specific order options - cart page related
function update_order_option_data(element) {
  var target = jQuery(element);
  var value_id = target.find('option:selected').attr('id');
  var price = target.find('option:selected').data('value');
  var option_id = target.attr('id');
  var $cart_totals = jQuery(".cart_totals");

  var orderOptionPriceHolder = jQuery(".order-options td.order-options-price")
  var orderOptionTotalPriceHolder = jQuery(".order-options td.order-options-subtotal")
  var subtotalPriceHolder = $cart_totals.find('[data-title="Subtotal"] .woocommerce-Price-amount')
  var totalPriceHolder = $cart_totals.find('[data-title="Total"] .woocommerce-Price-amount')
  
    block($checkoutBtn);
    block(orderOptionPriceHolder);
    block(orderOptionTotalPriceHolder);
    block(subtotalPriceHolder);
    block(totalPriceHolder);
    jQuery.ajax({
      type: 'POST',
      url: rentalObj.url,
      dataType: 'JSON',
      data: {
          action: 'rental_update_order_option',
          option_id: option_id,
          value_id: value_id,
          price: price,
      },
      success: function(data) {
      },
      error: function(data) {
        console.log('error:', data )
      },
      complete: function(data) {
        // console.log(' update order option dataaaaa:',  data)
        var serverData = data.responseJSON;
        var $cart_totals = jQuery(".cart_totals");

        if (serverData !== '') {
            if (serverData.total !== undefined 
              && serverData.subtotal !== undefined 
              && serverData.price_total !== undefined 
            ) {
              totalPriceHolder.html('<bdi><span class="woocommerce-Price-currencySymbol">'+serverData.currency+'</span>'+serverData.total+'</bdi>')
              subtotalPriceHolder.html('<bdi><span class="woocommerce-Price-currencySymbol">'+serverData.currency+'</span>'+serverData.subtotal+'</bdi>')
              orderOptionPriceHolder.html('<bdi><span class="woocommerce-Price-currencySymbol">'+serverData.currency+'</span>'+serverData.price_total+'</bdi>')
              orderOptionTotalPriceHolder.html('<bdi><span class="woocommerce-Price-currencySymbol">'+serverData.currency+'</span>'+serverData.price_total+'</bdi>')
            }

            if (serverData.security_deposit_title !== undefined && serverData.security_deposit_title.trim() !== '') {
              $cart_totals.find('[data-title="'+serverData.security_deposit_title.trim()+'"] .woocommerce-Price-amount').html('<bdi><span class="woocommerce-Price-currencySymbol">'+serverData.currency+'</span>'+serverData.security_deposit_value+'</bdi>')
            }
    
            if (serverData.tax_title !== undefined && serverData.tax_title.trim() !== '') {
              $cart_totals.find('[data-title="'+serverData.tax_title.trim()+'"] .woocommerce-Price-amount').html('<bdi><span class="woocommerce-Price-currencySymbol">'+serverData.currency+'</span>'+serverData.tax_value+'</bdi>')
            }
            
            if (serverData.damage_waiver_title !== undefined && serverData.damage_waiver_title.trim() !== '') {
              $cart_totals.find('[data-title="'+serverData.damage_waiver_title.trim()+'"] .woocommerce-Price-amount').html('<bdi><span class="woocommerce-Price-currencySymbol">'+serverData.currency+'</span>'+serverData.damage_waiver_value+'</bdi>')
            }
    
            if (serverData.damage_waiver_tax_title !== undefined && serverData.damage_waiver_tax_title.trim() !== '') {
              $cart_totals.find('[data-title="'+serverData.damage_waiver_tax_title.trim()+'"] .woocommerce-Price-amount').html('<bdi><span class="woocommerce-Price-currencySymbol">'+serverData.currency+'</span>'+serverData.damage_waiver_tax_value+'</bdi>')
            }

            if (serverData.rush_fee_title !== undefined && serverData.rush_fee_title.trim() !== '') {
              $cart_totals.find('[data-title="'+serverData.rush_fee_title.trim()+'"] .woocommerce-Price-amount').html('<bdi><span class="woocommerce-Price-currencySymbol">'+serverData.currency+'</span>'+serverData.rush_fee_value+'</bdi>')
            }
    
            if (serverData.auto_applied_fees !== undefined && serverData.auto_applied_fees.length > 0) {
              for(var i=0; i < serverData.auto_applied_fees.length; i++) {
                $cart_totals.find('[data-title="'+serverData.auto_applied_fees[i]["title"].trim()+'"] .woocommerce-Price-amount').html('<bdi><span class="woocommerce-Price-currencySymbol">'+serverData.currency+'</span>'+serverData.auto_applied_fees[i]["amount"]+'</bdi>')
              }
            }
        }

        unblock(orderOptionPriceHolder);
        unblock(orderOptionTotalPriceHolder);
        unblock(subtotalPriceHolder);
        unblock(totalPriceHolder);
        unblock($checkoutBtn);

        // Robust WooCommerce cart fragment refresh with proper timing
        jQuery(document.body).trigger('wc_fragment_refresh');
        if (typeof wc_cart_fragments_params !== 'undefined') {
          jQuery.ajax({
            url: wc_cart_fragments_params.wc_ajax_url.toString().replace('%%endpoint%%', 'get_refreshed_fragments'),
            type: 'POST',
            data: { time: new Date().getTime() },
            success: function(fragmentData) {
              if (fragmentData && fragmentData.fragments) {
                jQuery.each(fragmentData.fragments, function(key, value) {
                  jQuery(key).replaceWith(value);
                });
                jQuery(document.body).trigger('wc_fragments_refreshed');
                // Refresh options AFTER fragment DOM replacement
                setTimeout(function() {
                  jQuery(document.body).trigger('rental_product_options_updated');
                }, 100);
              }
            },
            error: function() {
              jQuery(document.body).trigger('rental_product_options_updated');
            }
          });
        } else {
          jQuery(document.body).trigger('rental_product_options_updated');
        }
        // Delayed fallback
        setTimeout(function() {
          jQuery(document.body).trigger('rental_product_options_updated');
        }, 800);
      }
    });
}
// sets specific order options - cart page related
function update_order_option_data_of_sets(element) {
  var target = jQuery(element);
  var value_id = target.find('option:selected').attr('id');
  var price = target.find('option:selected').data('value');
  var option_id = target.attr('id');
  var $cart_totals = jQuery(".cart_totals");

  var orderOptionPriceHolder = jQuery(".sets-order-options td.order-options-price")
  var orderOptionTotalPriceHolder = jQuery(".sets-order-options td.order-options-subtotal")
  // var subtotalPriceHolder = $cart_totals.find('[data-title="Subtotal"] .woocommerce-Price-amount')
  var subtotalPriceHolder = $cart_totals.find('.cart-subtotal .cart-totals-value .woocommerce-Price-amount')
  // var totalPriceHolder = $cart_totals.find('[data-title="Total"] .woocommerce-Price-amount')
  var totalPriceHolder = $cart_totals.find('.order-total .cart-totals-value .woocommerce-Price-amount')
  
    block($checkoutBtn);
    block(orderOptionPriceHolder);
    block(orderOptionTotalPriceHolder);
    block(subtotalPriceHolder);
    block(totalPriceHolder);
    jQuery.ajax({
      type: 'POST',
      url: rentalObj.url,
      dataType: 'JSON',
      data: {
          action: 'rental_update_order_option_of_set',
          option_id: option_id,
          value_id: value_id,
          price: price,
      },
      success: function(data) {
      },
      error: function(data) {
        console.log('error:', data )
      },
      complete: function(data) {
        // console.log(' update order option dataaaaa:',  data)
        var serverData = data.responseJSON;
        var $cart_totals = jQuery(".cart_totals");

        if (serverData !== '') {
            if (serverData.total !== undefined 
              && serverData.subtotal !== undefined 
              && serverData.price_total !== undefined 
            ) {
              totalPriceHolder.html('<bdi><span class="woocommerce-Price-currencySymbol">'+serverData.currency+'</span>'+serverData.total+'</bdi>')
              subtotalPriceHolder.html('<bdi><span class="woocommerce-Price-currencySymbol">'+serverData.currency+'</span>'+serverData.subtotal+'</bdi>')
              orderOptionPriceHolder.html('<bdi><span class="woocommerce-Price-currencySymbol">'+serverData.currency+'</span>'+serverData.price_total+'</bdi>')
              orderOptionTotalPriceHolder.html('<bdi><span class="woocommerce-Price-currencySymbol">'+serverData.currency+'</span>'+serverData.price_total+'</bdi>')
            }

            if (serverData.security_deposit_title !== undefined && serverData.security_deposit_title.trim() !== '') {
              $cart_totals.find('[data-title="'+serverData.security_deposit_title.trim()+'"] .woocommerce-Price-amount').html('<bdi><span class="woocommerce-Price-currencySymbol">'+serverData.currency+'</span>'+serverData.security_deposit_value+'</bdi>')
            }
    
            if (serverData.tax_title !== undefined && serverData.tax_title.trim() !== '') {
              $cart_totals.find('[data-title="'+serverData.tax_title.trim()+'"] .woocommerce-Price-amount').html('<bdi><span class="woocommerce-Price-currencySymbol">'+serverData.currency+'</span>'+serverData.tax_value+'</bdi>')
            }
            
            if (serverData.damage_waiver_title !== undefined && serverData.damage_waiver_title.trim() !== '') {
              $cart_totals.find('[data-title="'+serverData.damage_waiver_title.trim()+'"] .woocommerce-Price-amount').html('<bdi><span class="woocommerce-Price-currencySymbol">'+serverData.currency+'</span>'+serverData.damage_waiver_value+'</bdi>')
            }
    
            if (serverData.damage_waiver_tax_title !== undefined && serverData.damage_waiver_tax_title.trim() !== '') {
              $cart_totals.find('[data-title="'+serverData.damage_waiver_tax_title.trim()+'"] .woocommerce-Price-amount').html('<bdi><span class="woocommerce-Price-currencySymbol">'+serverData.currency+'</span>'+serverData.damage_waiver_tax_value+'</bdi>')
            }

            if (serverData.rush_fee_title !== undefined && serverData.rush_fee_title.trim() !== '') {
              $cart_totals.find('[data-title="'+serverData.rush_fee_title.trim()+'"] .woocommerce-Price-amount').html('<bdi><span class="woocommerce-Price-currencySymbol">'+serverData.currency+'</span>'+serverData.rush_fee_value+'</bdi>')
            }
    
            if (serverData.auto_applied_fees !== undefined && serverData.auto_applied_fees.length > 0) {
              for(var i=0; i < serverData.auto_applied_fees.length; i++) {
                $cart_totals.find('[data-title="'+serverData.auto_applied_fees[i]["title"].trim()+'"] .woocommerce-Price-amount').html('<bdi><span class="woocommerce-Price-currencySymbol">'+serverData.currency+'</span>'+serverData.auto_applied_fees[i]["amount"]+'</bdi>')
              }
            }
        }

        unblock(orderOptionPriceHolder);
        unblock(orderOptionTotalPriceHolder);
        unblock(subtotalPriceHolder);
        unblock(totalPriceHolder);
        unblock($checkoutBtn);

        // Robust WooCommerce cart fragment refresh with proper timing
        jQuery(document.body).trigger('wc_fragment_refresh');
        if (typeof wc_cart_fragments_params !== 'undefined') {
          jQuery.ajax({
            url: wc_cart_fragments_params.wc_ajax_url.toString().replace('%%endpoint%%', 'get_refreshed_fragments'),
            type: 'POST',
            data: { time: new Date().getTime() },
            success: function(fragmentData) {
              if (fragmentData && fragmentData.fragments) {
                jQuery.each(fragmentData.fragments, function(key, value) {
                  jQuery(key).replaceWith(value);
                });
                jQuery(document.body).trigger('wc_fragments_refreshed');
                // Refresh options AFTER fragment DOM replacement
                setTimeout(function() {
                  jQuery(document.body).trigger('rental_product_options_updated');
                }, 100);
              }
            },
            error: function() {
              jQuery(document.body).trigger('rental_product_options_updated');
            }
          });
        } else {
          jQuery(document.body).trigger('rental_product_options_updated');
        }
        // Delayed fallback
        setTimeout(function() {
          jQuery(document.body).trigger('rental_product_options_updated');
        }, 800);
      }
    });
}

// Race condition protection: BATCH all option changes and send together after debounce
var _rentalOptionUpdateState = {
  pendingChanges: {},         // Queue of pending option changes: { option_id: {value_id, price, product_id, is_set} }
  pendingXHR: null,           // Current pending AJAX request
  pendingFragmentXHR: null,   // Current pending fragment refresh request
  debounceTimer: null,        // Debounce timer
  requestId: 0,               // Request counter for logging
  DEBOUNCE_MS: 250            // Debounce delay - wait for all changes to accumulate
};

function update_option_data_of_single_product(element) {
  // Reflect the new value immediately: a select moved off the placeholder can
  // unlock the button right away, because the value is already in the form and
  // the server reads the form before anything else.
  rentalOptionsSyncCartButton();

  var target = jQuery(element);
  var product_id;
  var variation_id = jQuery('input.variation_id');
  if (variation_id.length) {
    product_id = variation_id.val();
  } else {
    product_id = target.data('product-id');
  }
  var is_set = target.data('is-set');
  var value_id = target.find('option:selected').attr('id');
  var price = target.find('option:selected').data('value');
  var option_id = target.attr('id');

  // ADD this change to the pending queue (will overwrite previous value for same option)
  _rentalOptionUpdateState.pendingChanges[option_id] = {
    value_id: value_id,
    price: price,
    product_id: product_id,
    is_set: is_set
  };

  // Clear any pending debounce timer (reset the wait)
  if (_rentalOptionUpdateState.debounceTimer) {
    clearTimeout(_rentalOptionUpdateState.debounceTimer);
  }

  // Debounce: Wait for rapid changes to settle, then send ALL queued changes
  _rentalOptionUpdateState.debounceTimer = setTimeout(function() {
    // Capture all pending changes and clear the queue
    var changesToSend = _rentalOptionUpdateState.pendingChanges;
    _rentalOptionUpdateState.pendingChanges = {};
    
    var changeCount = Object.keys(changesToSend).length;
    if (changeCount === 0) {
      // Nothing to send - re-evaluate so the button doesn't stay stuck
      rentalOptionsSyncCartButton();
      return;
    }

    _rentalOptionUpdateState.requestId++;
    var thisRequestId = _rentalOptionUpdateState.requestId;

    // Build the batch data array
    var batchData = [];
    var lastProductId = null;
    var lastIsSet = 0;
    for (var optId in changesToSend) {
      if (changesToSend.hasOwnProperty(optId)) {
        var change = changesToSend[optId];
        batchData.push({
          option_id: optId,
          value_id: change.value_id,
          price: change.price
        });
        lastProductId = change.product_id;
        lastIsSet = change.is_set;
      }
    }

    _rentalOptionUpdateState.pendingXHR = jQuery.ajax({
      type: 'POST',
      url: rentalObj.url,
      dataType: 'JSON',
      data: {
          action: 'rental_update_product_options_batch',
          product_id: lastProductId,
          options: JSON.stringify(batchData),
          single_product: 1,
          is_set: lastIsSet,
          request_id: thisRequestId
      },
      success: function(data) {
        // A product-page change only STAGES a selection; it never edits a line
        // already in the cart. This used to fire a confirmation call that
        // scraped the selects and force-wrote them onto the cart line, then
        // refresh the cart fragments — which is how changing an option on the
        // product page altered an item the customer had already added, and did
        // so inconsistently across checkout, cart and mini-cart.
        //
        // Nothing the customer can see elsewhere has changed, so nothing is
        // refreshed. The staged selection is committed on add-to-cart, which
        // replaces the line.
        jQuery(document.body).trigger('rental_product_options_updated');
      },
      error: function(xhr, status, error) {
        if (status === 'abort') return;
        console.log('error:', error);
      },
      complete: function(xhr, status) {
        // Always check/re-enable the button, even on abort or error.
        // checkAddToCartPossibility handles its own block/unblock cycle.
        checkAddToCartPossibility(lastIsSet);
      }
    });
  }, _rentalOptionUpdateState.DEBOUNCE_MS);
}

/**
 * Bring the Add-to-Cart button in line with the current selection.
 *
 * Kept for the existing callers. It used to unconditionally enable the button,
 * which meant the client could never notice that an option was still open;
 * it now defers to the same check the server performs.
 *
 * @param {number} is_set  Retained for signature compatibility; unused.
 */
function checkAddToCartPossibility(is_set = 0) {
  rentalOptionsSyncCartButton();
}

// product options selection check on cart page checkout btn 
$checkoutBtn.on("click", function(e) {
  e.preventDefault();
  
  block($checkoutBtn);
  jQuery.ajax({
    type: 'POST',
    url: rentalObj.url,
    dataType: 'JSON',
    data: {
        action: 'rental_check_selected_options'
    },
    success: function(data) {
      // console.log(' check order/non order options:',  data)
      
      if (data.check !== true) {
        // customer needs to select a value for each products' options

        errorMsg("Please review the product options before checking out.");
        jQuery([document.documentElement, document.body]).animate({
          scrollTop: jQuery(".rntl-opt-err").offset().top - 120
        }, 1000);

      } else {
        errorMsg("");
        window.location = data.url;
      }

    },
    error: function(data) {
      // console.log('error:', data )
    }, complete: function() {
      unblock($checkoutBtn);
    }
  });

})



var errorMsg = function (msg) {
  var $div = jQuery("div.woocommerce-notices-wrapper")
  var errorBox = "";
  if (msg !== "") {
    errorBox = '<div class="rntp-notification rntp-notification--error rntl-opt-err" style="margin-top:1rem">' +
      // '<a href class="rntp-notification__close color--error">×</a>' +
      '<div class="rntp-notification__status rntp-bg--gradient-red">' +
        ' &times;' +
      '</div>' +
      '<div class="rntp-notification__content">' +
          '<h4 class="rntp-notification__title"> Product Options </h4>' +
          '<p class="rntp-notification__text"> '+ msg +' </p>' +
      '</div>' +
    '</div>';
    
    if (!(jQuery(".rntl-opt-err").length > 0)) {
      $div.append(errorBox)
    }
   
  } else {
    $div.append(errorBox)
  }
  
};

if (window.location.href.indexOf("rntp_opt_err") > -1) {
  errorMsg("Please review the product options before checking out.");
  jQuery([document.documentElement, document.body]).animate({
    scrollTop: jQuery(".rntl-opt-err").offset().top - 120
  }, 1000);
}