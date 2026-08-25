/*--------------------------------------------------------------
Sets Module — Front-end behaviour
Author: Rentopian
Website: https://rentopian.com
Copyright 2023, Rentopian Inc. All Rights Reserved.

Owns every interactive piece tied to the Set product type:
  - Default-selection of optional set items / addons on page load.
  - Re-pricing the set based on chosen items (rental_calculate_set_price_based_on_items_price).
  - The variant-picker modal (#myModalRental) used by both the main
    set item and its add-ons.
  - The flash-message UI surfaced after a set is updated.

Migrated from /assets/js/rental-sets.js into the Sets module so all
set-related code lives in one place. Behaviour is unchanged; the
script handle and the localized rentalObj contract are preserved
(see Rental_Sets_Assets::enqueue()).
----------------------------------------------------------------*/

jQuery(document).ready(function($) {
    var $cartBtn = $("form.cart").find(".single_add_to_cart_button");

    function getProductIdFromDOM() {
        // Try button first
        var btn = document.querySelector('form.cart button[name="add-to-cart"]');
        if (btn && btn.value) {
            return btn.value;
        }
        // Fallback: hidden input
        var inp = document.querySelector('form.cart input[name="add-to-cart"]');
        if (inp && inp.value) {
            return inp.value;
        }
        // Fallback: body class “postid-123”
        var classes = document.body.className.split(/\s+/);
        for (var i = 0; i < classes.length; i++) {
            var cls = classes[i];
            if (cls.indexOf('postid-') === 0) {
                var id = cls.replace('postid-', '');
                if (/^\d+$/.test(id)) {
                    return id;
                }
            }
        }
        return null;
    }


    if ($('div.product').length) {
        // var mainProductId = $cartBtn.val();
        var mainProductId = getProductIdFromDOM();

        if ($('div#_rental_is_set_identifier').length) {

            /* Sets Items' Optional Items already selected action
            *  description : rental set items' optional items' already selected action for when there are
            *  selectable items that have already a selected item (or set items with only one option) from the Rentopian system
            */
            block($cartBtn);
                $.ajax({
                type: 'POST',
                url: rentalObj.url,
                dataType: 'JSON',
                data: {
                    action: 'rental_update_set_already_selected_items',
                    set_id: mainProductId
                },
                success: function(data) {
                    // console.log(" Already Selected optional set item (or set items with only one option) ", data )
                },
                error: function(data) {
                    console.log('error:', data )
                },
                complete: function(data) {
                    unblock($cartBtn); 
                    
                }
            });


            /* Sets Items' Addons' Optional Items default selection for hidden addon items 
            *  description : rental set items' addons' optional items' default selection 
            */
            block($cartBtn);
            $.ajax({
                type: 'POST',
                url: rentalObj.url,
                dataType: 'JSON',
                data: {
                    action: 'rental_set_items_addons_with_variants_optional_default_update',
                    set_id: mainProductId
                },
                success: function(data) {
                  console.log(" set hidden addons default selected ", data )
                },
                error: function(data) {
                  console.log('error on set hidden addons default selected:', data )
                },
                complete: function(data) {
                unblock($cartBtn); 
                }
            });



            // [DEPRECATED] setMainPrice() retired.
            // The top total `.entry-price-wrap` is now driven by
            // rental-sets-modern.js → writeTopTotal(), invoked from
            // updateSummary() on every selection / qty change. Both
            // the top and bottom panels share a single computation,
            // so the two displays can never disagree. The AJAX
            // endpoint `rental_calculate_set_price_based_on_items_price`
            // is also retired (see functions.php + rentopian-sync.php
            // for the matching stubs). Original setMainPrice body
            // kept inside the block comment below for grep-able
            // provenance; re-enable + un-comment the call to restore
            // the legacy path.
            /*
            function setMainPrice() {
              // Calculate and replace Set price based on items price
              $.ajax({
                type: 'POST',
                url: rentalObj.url,
                dataType: 'JSON',
                data: {
                    action: 'rental_calculate_set_price_based_on_items_price',
                    set_id: mainProductId
                },
                success: function(data) {
                  if ( data.msg == 'success' && data.set_total ) {
                      var wrap = $('.entry-price-wrap');
                      if (wrap.length) {
                          wrap.html(data.set_total_html);
                          wrap.find('.woocommerce-Price-amount').css({
                            'font-size' : 'x-large'
                          })
                      }
                  }
                },
                error: function(data) {
                  console.log('error rental_calculate_set_price_based_on_items_price :', data )
                },
                complete: function(data) {
                  unblock($cartBtn);
                }
              });
            }
            setMainPrice()
            */
            
            
        }
    }


    const $modalRental = $('#myModalRental');

    $(document).on("click", ".choose-item-variant", function(e) {
      // Get raw string using .attr()
      let rawData = $(this).attr('data-items');
      let items = [];
  
      try {
          // Parse the JSON safely
          items = (typeof rawData === 'string') ? JSON.parse(rawData) : rawData;
      } catch (err) {
          console.error("JSON Parsing failed for Main Variant:", err);
          return;
      }
  
      if (items && items.length) {
          let listItems = '';
          
          for (let i = 0; i < items.length; i++) {
              let item = items[i];
              
              // Handle Attributes text safely
              let attrsText = (item.attrs && item.attrs.length) ? `( ${item.attrs.join(', ')} )` : '';
  
              let isSelected = item.is_selected || 0;
              let checkedAttribute = (isSelected === 1) ? 'checked' : '';
  
              // Build HTML
              listItems += `
                  <li>
                      <div class="variant-item-wrapper" data-pid="${item.product_id}" data-vid="${item.variant_id}" data-set-item-id="0">
                          <div class="variant-item-img-wrapper">${item.image_url}</div>
                          <label class="variant-item-label" for="variant-${i}">
                              <div class="variant-item-content-wrapper">
                                  <h6>${item.name}</h6>
                                  <span>${attrsText}</span>
                              </div>
                          </label>
                          <input type="radio" name="variant-selection" value="variant-${i}" id="variant-${i}" ${checkedAttribute}>
                      </div>
                  </li>`;
          }
  
          $modalRental.find('.variants-list').html(`<ul>${listItems}</ul>`);
          
          let hasSelected = $(this).attr('data-has-selected');
          if (hasSelected == 0) {
              setTimeout(() => { $("#variant-0").trigger('click'); }, 150);
          }
          $modalRental.show();
      }
  });
  
  $(document).on("click", ".choose-item-variant-addon", function(e) {
      // Get raw data and other attributes
      let rawData = $(this).attr('data-items');
      let hasSelected = $(this).attr('data-has-selected');
      let parentSetItemProductId = $(this).attr('data-parent-set-item-product-id');
      let addonRentalInvId = $(this).attr('data-addon-rental-inv-id');
      let items = [];
  
      try {
          // Parse the JSON safely
          items = (typeof rawData === 'string') ? JSON.parse(rawData) : rawData;
      } catch (err) {
          console.error("JSON Parsing failed for Add-on:", err);
          return;
      }
  
      if (items && items.length) {
          let listItems = '';
  
          for (let i = 0; i < items.length; i++) {
              let item = items[i];
              
              // Handle Attributes text safely
              let attrsText = (item.attrs && item.attrs.length) ? `( ${item.attrs.join(', ')} )` : '';
  
              let isSelected = item.is_selected || 0;
              let checkedAttribute = (isSelected === 1) ? 'checked' : '';
  
              // Build HTML
              listItems += `
                  <li>
                      <div class="variant-item-wrapper" 
                           data-pid="${item.product_id}" 
                           data-vid="${item.variant_id}" 
                           data-set-item-id="${parentSetItemProductId}" 
                           data-inv-id="${addonRentalInvId}">
                          <div class="variant-item-img-wrapper">${item.image_url}</div>
                          <label class="variant-item-label" for="variant-${i}">
                              <div class="variant-item-content-wrapper">
                                  <h6>${item.name}</h6>
                                  <span>${attrsText}</span>
                              </div>
                          </label>
                          <input type="radio" name="variant-selection" value="variant-${i}" id="variant-${i}" ${checkedAttribute}>
                      </div>
                  </li>`;
          }
  
          $modalRental.find('.variants-list').html(`<ul>${listItems}</ul>`);
          
          if (hasSelected == 0) {
              setTimeout(() => { $("input#variant-0").trigger('click'); }, 150);
          }
          $modalRental.show();
      }
  });


    $modalRental.on("click", ".choose-variant", function(e) {

      let setId = $modalRental.find("input#set-id").val()

      let selectedWrapper = $modalRental.find('input[name="variant-selection"]:checked').closest('.variant-item-wrapper');
      let productId = selectedWrapper.data('pid');
      let variantId = selectedWrapper.data('vid');
      
      let actionFunc = 'rental_update_set_items'
      let setItemId = 0
      if (selectedWrapper.data('set-item-id')) {

        setItemId = selectedWrapper.data('set-item-id')  
        actionFunc = 'rental_update_set_items_addon_with_variants_optional'
      }

      let rentalInvId = 0
      if (selectedWrapper.data('inv-id')) {
        rentalInvId = selectedWrapper.data('inv-id')
      }

      var dataObj = {
        action: actionFunc,
        set_id: setId,
        set_item_id: setItemId,
        product_id: productId,
        variant_id: variantId,
        rental_inv_id: rentalInvId,
      }

      block($modalRental.find(".modal-rental-content"));
      $(".choose-variant").attr('disabled', true)

      showProcessingModal();

      $.ajax({
        type: 'POST',
        url: rentalObj.url,
        dataType: 'JSON',
        data: dataObj,
        success: function(data) {

          sessionStorage.setItem('rental-set-update-message', 'Set item selected successfully');
          sessionStorage.setItem('rental-set-update-message-type', 'success');

          if (data.item_remove_notif !== undefined && data.item_remove_notif) {
            sessionStorage.setItem('rental-set-update-message-remove-notif', data.item_remove_notif);
          }

          location.reload();  
          
        },
        error: function(data) {

          console.log('error:',data);
        },
        complete: function() {
          
          // setTimeout(() => {
            //   unblock($modalRental.find(".modal-rental-content"));  
            // $(".choose-variant").attr('disabled', false)
            // hideProcessingModal();

          // }, 5000);
        }
      });
      
    })

    $modalRental.on("click", ".remove-variant", function(e) {
      $modalRental.hide();
    })

    

    $('.modal-rental-close').on('click', function() {
      $modalRental.hide();
    });

    $(window).on('click', function(event) {
        if ($(event.target).is('#myModalRental')) {
          $modalRental.hide();
        }
    });

    function showModalMessage(message, type = 'success') {
        let messageBox = $('<div class="modal-message"></div>')
            .text(message)
            .addClass(type)
            .appendTo('#modalMessageContainer');
    
        messageBox.fadeIn();
    
        setTimeout(function() {
            messageBox.fadeOut(function() {
                $(this).remove();
            });
        }, 8000);
    }

    let setUpdateMessage = sessionStorage.getItem('rental-set-update-message');
    let setUpdateMessageType = sessionStorage.getItem('rental-set-update-message-type');

    if (setUpdateMessage) {
        showModalMessage(setUpdateMessage, setUpdateMessageType);
        sessionStorage.removeItem('rental-set-update-message');
    }

    let setUpdateMessageRemoveNotif = sessionStorage.getItem('rental-set-update-message-remove-notif');
    if (setUpdateMessageRemoveNotif) {
        showModalMessage(setUpdateMessageRemoveNotif, setUpdateMessageType);
        sessionStorage.removeItem('rental-set-update-message-remove-notif');
    }

    sessionStorage.removeItem('rental-set-update-message-type');

    // function showProcessingModal() {
    //     let modalHtml = `
    //         <div id="processing-modal" class="processing-modal" >
    //             <div class="processing-modal-content">
    //                 <p> 
    //                   Processing, please wait...
    //                 </p>
    //             </div>
    //         </div>`;
    //     $("body").append(modalHtml);
    // }
    
    // function hideProcessingModal() {
    //     $("#processing-modal").remove();
    // }
    
})
