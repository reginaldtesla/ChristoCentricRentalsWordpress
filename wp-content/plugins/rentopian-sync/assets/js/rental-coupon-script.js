// /*--------------------------------------------------------------
// Rentopian Coupons Functionality Scripts
// Author: Rentopian
// Website: https://rentopian.com
// Copyright 2019, Rentopian Inc. All Rights Reserved.
// ----------------------------------------------------------------*/

// jQuery(document).ready(function($) {

//   function block($node) {
//     if ( !$node.is('.processing') && !$node.parents('.processing').length) {
//       $node.addClass('processing').block({
//         message: null,
//         overlayCSS: {
//           background: '#fff',
//           opacity: 0.6
//         }
//       });
//     }
//   }

//   function unblock($node) {
//     $node.removeClass('processing').unblock();
//   }

//   function show_notice(message, remove_notices, status) {
//     if (remove_notices) {
//       $('.woocommerce-error, .woocommerce-message').remove();
//     }
//     if (status) {
//       message = '<div class="woocommerce-' + status + '" role="alert">' + message + '</div>';
//     }
//     if (typeof wc_add_to_cart_params === 'object' && wc_add_to_cart_params.is_cart) {
//       var $t = $('.woocommerce-notices-wrapper:first') || $('.cart-empty').closest('.woocommerce') || $('.woocommerce-cart-form');
//       $t.prepend(message);
//     } else {
//       var $form = $('form.checkout_coupon');
//       $form.before(message);
//       // $form.slideUp();
//     }
//     $.scroll_to_notices($('[role="alert"]'));
//   }

//   function update_cart() {
//     var $form = $('.woocommerce-cart-form');

//     block($form);
//     block($('div.cart_totals'));

//     // Make call to actual form post URL.
//     $.ajax({
//       type: $form.attr('method'),
//       url: $form.attr('action'),
//       data: $form.serialize(),
//       dataType: 'html',
//       success: function(html_str) {
//         var $html = $.parseHTML(html_str);
//         var $new_form = $('.woocommerce-cart-form', $html);
//         var $new_totals = $('.cart_totals', $html);
//         var $notices = $('.woocommerce-error, .woocommerce-message, .woocommerce-info', $html);

//         // No form, cannot do this.
//         if ($('.woocommerce-cart-form').length === 0) {
//           window.location.reload();
//           return;
//         }

//         if ($new_form.length === 0) {
//           // If the checkout is also displayed on this page, trigger reload instead.
//           if ($('.woocommerce-checkout').length) {
//             window.location.reload();
//             return;
//           }

//           // No items to display now! Replace all cart content.
//           var $cart_html = $('.cart-empty', $html).closest('.woocommerce');
//           $('.woocommerce-cart-form__contents').closest('.woocommerce').replaceWith($cart_html);

//           // Display errors
//           if ($notices.length > 0) {
//             show_notice($notices);
//           }

//           // Notify plugins that the cart was emptied.
//           $(document.body).trigger('wc_cart_emptied');
//         } else {
//           // If the checkout is also displayed on this page, trigger update event.
//           if ($('.woocommerce-checkout').length) {
//             $(document.body).trigger('update_checkout');
//           }

//           // $( '.woocommerce-cart-form' ).replaceWith( $new_form );
//           // $( '.woocommerce-cart-form' ).find( ':input[name="update_cart"]' ).prop( 'disabled', true );

//           if ($notices.length > 0) {
//             show_notice($notices);
//           }

//           $('.cart_totals').replaceWith($new_totals);
//           $(document.body).trigger('updated_cart_totals');
//         }

//         $(document.body).trigger('updated_wc_div');
//       },
//       complete: function() {
//         unblock($form);
//         unblock($('div.cart_totals'));
//       }
//     });
//   }

//   $(document).on('click', 'input[name=apply_coupon], button[name=apply_coupon]', function(e) {
//     e.preventDefault();
//     var $couponCode = $('#coupon_code');
//     var couponCode = $couponCode.val();
//     if ( !couponCode) {
//       return false;
//     }
//     $.ajax({
//       type: 'POST',
//       url: rentalObj.url,
//       dataType: 'JSON',
//       data: {
//         action: 'rental_apply_coupon',
//         coupon_code: couponCode
//       },
//       success: function(data) {
//         show_notice(data.message, true, 'message');
//         $(document.body).trigger('applied_coupon', [couponCode]);
//       },
//       error: function(data) {
//         data = data.responseJSON;
//         show_notice(data.message, true, 'error');
//       },
//       complete: function() {
//         $couponCode.val('');
//         if (typeof wc_add_to_cart_params === 'object' && wc_add_to_cart_params.is_cart) {
//           // $(document.body).trigger('wc_update_cart');
//           update_cart();
//         } else {
//           $(document.body).trigger('update_checkout');
//         }
//       }
//     });
//   }).on('click', '.rentopian-remove-coupon', function(e) {
//     e.preventDefault();
//     $.ajax({
//       type: 'POST',
//       url: rentalObj.url,
//       dataType: 'JSON',
//       data: {
//         action: 'rental_remove_coupon'
//       },
//       success: function(data) {
//         show_notice(data.message, true, 'message');
//       },
//       error: function(data) {
//         data = data.responseJSON;
//         show_notice(data.message, true, 'error');
//       },
//       complete: function() {
//         if (typeof wc_add_to_cart_params === 'object' && wc_add_to_cart_params.is_cart) {
//           // $(document.body).trigger('wc_update_cart');
//           update_cart();
//         } else {
//           $(document.body).trigger('update_checkout');
//         }
//       }
//     });
//   });

// });