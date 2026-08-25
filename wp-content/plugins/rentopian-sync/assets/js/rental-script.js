/*--------------------------------------------------------------
Rentopian Sync Frontend Scripts
Author: Rentopian
Website: https://rentopian.com
Copyright 2019, Rentopian Inc. All Rights Reserved.
----------------------------------------------------------------*/

jQuery(document).ready(function($) {

  /**
   * Reads a 0/1 flag from rentalAutoDateSettings.
   *
   * Localized values arrive as strings, so "0" passes a plain truthiness
   * check — always compare the parsed number. Returns the fallback when the
   * settings object or the key is missing.
   */
  function rntpFlagEnabled(key, fallback) {
    var settings = (typeof rentalAutoDateSettings !== 'undefined') ? rentalAutoDateSettings : null;
    if (!settings || typeof settings[key] === 'undefined') {
      return fallback;
    }
    return parseInt(settings[key], 10) === 1;
  }

  /**
   * Whether cart amounts must stay hidden from the visitor. The mini-cart
   * totals are repainted here from an AJAX response, so they need the same
   * gate the templates apply. Localized flags arrive as strings.
   */
  function rentalCartPricesHidden() {
    return parseInt((window.rentalPriceVisibility || {}).hideCartPrices, 10) === 1;
  }

  /**
   * Whether submitting the date form should also add the current product to
   * the cart. Defaults to enabled when the setting is not localized.
   */
  function rntpIsAutoAddToCartEnabled() {
    return rntpFlagEnabled('autoAddToCartOnDateSelection', true);
  }

  /**
   * rntpOverlay — reusable loading overlay utility.
   * Usage:
   *   rntpOverlay.show($el)   — cover $el with a spinner
   *   rntpOverlay.hide($el)   — remove the spinner from $el
   *   rntpOverlay.hide()      — remove all active spinners
   */
  var rntpOverlay = {
    show: function($el) {
      if (!$el || !$el.length) return;
      if ($el.find('.rntp-loading-overlay').length) return; // already showing
      $el.css('position', 'relative');
      $el.append('<div class="rntp-loading-overlay"><div class="rntp-spinner"></div></div>');
    },
    hide: function($el) {
      if ($el && $el.length) {
        $el.find('.rntp-loading-overlay').remove();
      } else {
        $('.rntp-loading-overlay').remove();
      }
    }
  };

  /**
   * rntpDateBlocker — controls the overlay and message that block the page
   * until rental dates are selected.
   *
   * Both are printed server side, so they would only disappear on a full page
   * reload. They are toggled here from the state reported by the date form,
   * which keeps them in sync when the dates are submitted over AJAX.
   */
  var rntpDateBlocker = {
    nodes: '#rntp-date-blocker, #rntp-date-blocker-text, .rental-overlay, .rental-form-text-on-top',
    forms: '#rental_date_form, #rental_date_form_modern',
    hide: function() {
      $(this.nodes).addClass('rntp-blocker-hidden');
    },
    show: function() {
      $(this.nodes).removeClass('rntp-blocker-hidden');
    },
    // Match the blocker to the date form currently in the page
    sync: function() {
      var $form = $(this.forms);
      if ( !$form.length) {
        return;
      }
      if ($form.hasClass('rntp-form-filled')) {
        this.hide();
      } else {
        this.show();
      }
    }
  };
  window.rntpDateBlocker = rntpDateBlocker;

  /**
   * rntpFilledButton — keeps the submit button's colour in step with the form.
   *
   * The colour is written inline with important priority rather than left to
   * the stylesheet. A theme that paints every button in its own brand colour
   * with !important outranks a plugin rule, and the customer is then given no
   * sign that their dates were accepted. The values still come from the
   * stylesheet's custom properties, so restyling the form does not mean
   * editing this.
   */
  var rntpFilledButton = {
    property: function(name, fallback) {
      var value = getComputedStyle(document.documentElement).getPropertyValue(name);
      return (value && value.trim()) || fallback;
    },
    sync: function() {
      var $form = $('#rental_date_form');
      if ( !$form.length) {
        return;
      }
      var button = $form.find('.rntp-submit-button')[0];
      if ( !button) {
        return;
      }
      var filled = $form.hasClass('rntp-form-filled');
      var colour = filled
        ? this.property('--rntp-submit-bg-filled', '#00c363')
        : this.property('--rntp-submit-bg', 'rgb(235, 77, 75)');

      button.style.setProperty('background-color', colour, 'important');
    }
  };
  window.rntpFilledButton = rntpFilledButton;

  // The date form is reloaded on checkout updates; keep the blocker in step
  $(document.body).on('updated_checkout', function() {
    rntpDateBlocker.sync();
  });

  // loading the date form block
  var frm = $("#rntp-form-holder");
  if (frm.length > 0) {

    block(frm);

    $.ajax({
      type: 'POST',
      url: rentalObj.url,
      cache: false,
      data: {
        action: 'rental_get_date_form'
      },
      success: function(data) {

        frm.html(data)
        var date_$form = frm.find('#rental_date_form');
        rntpDateBlocker.sync();
        rntpFilledButton.sync();
        if (date_$form.length) {

          var $startDate = $('#rental_start_date'), startCalendar,
            $endDate = $('#rental_end_date'), endCalendar,
            startDateDefault = $startDate.data('start-date-default'),
            endDateDefault = $startDate.data('end-date-default'),
            endDateValue = $startDate.data('end-date'),
            enableEnd = !$startDate.data('hide-end'),
            enableTime = !$startDate.data('hide-time'),
            minStartAllowedDays = parseInt($startDate.data('min-start')),
            disabledWeekDays = $startDate.data('disabled-week-days'),
            serverDateFormat = 'YYYY/MM/DD' + (enableTime? ' h:mm A': '');

          // Absolute dates resolved server-side, in the store timezone. The
          // floor and the preselected day are separate values: the offset is
          // already inside both, so nothing here may add it again.
          var absoluteFormat = 'YYYY/MM/DD h:mm A';

          var parseAbsolute = function(value) {
            if (!value) {
              return null;
            }
            var parsed = moment(value, absoluteFormat);
            if (!parsed.isValid()) {
              parsed = moment(value, 'YYYY/MM/DD');
            }
            return parsed.isValid()? parsed: null;
          };

          // Earliest day that may be selected.
          var floorMoment = parseAbsolute($startDate.data('min-start-date'));
          if (!floorMoment) {
            floorMoment = minStartAllowedDays > 0
              ? moment().startOf('day').add(minStartAllowedDays, 'd')
              : moment().startOf('hour').add(1, 'h');
          }

          var isBeforeFloor = function(candidate) {
            return !!candidate && candidate.clone().startOf('day').isBefore(floorMoment.clone().startOf('day'));
          };

          if (startDateDefault) {
            startDateDefault = moment(startDateDefault, "MMM D, h:mm A");
          }

          // Day the calendar opens on when nothing is stored yet.
          var defaultStart = parseAbsolute($startDate.data('default-start'));
          if (!defaultStart && startDateDefault && startDateDefault.isValid()) {
            defaultStart = startDateDefault.clone();
          }
          if (isBeforeFloor(defaultStart)) {
            defaultStart = floorMoment.clone();
          }

          var defaultEnd = parseAbsolute($startDate.data('default-end'));
          if (!defaultEnd && endDateDefault) {
            defaultEnd = moment(endDateDefault, "MMM D, h:mm A");
            defaultEnd = defaultEnd.isValid()? defaultEnd: null;
          }

          // A stored start inside the blocked window is dropped rather than
          // preselected; the end it belongs to goes with it.
          var savedStart = parseAbsolute($startDate.data('value'));
          var savedEnd = $endDate.length? parseAbsolute($endDate.data('value')): null;
          if (isBeforeFloor(savedStart)) {
            savedStart = null;
            savedEnd = null;
          }

          var resolvedStart = savedStart || defaultStart;
          var resolvedEnd = savedStart? savedEnd: defaultEnd;

          if (resolvedStart && resolvedEnd && resolvedEnd.isBefore(resolvedStart)) {
            resolvedEnd = resolvedStart.clone();
          }

          var nowMoment = resolvedStart? resolvedStart.clone(): floorMoment.clone();
          var endNow = resolvedEnd? resolvedEnd.clone(): nowMoment.clone();

          var config = {
            format: 'MMM D' + (enableTime? ', h:mm A': ''),
            showTimePickers: enableTime,
            singleDate: true,
            // arrowOn: 'right',
            showOn: 'bottom',
            autoAlign: false,
            // calendarCount: 1,
            // showHeader: false,
            showFooter: false,
            startEmpty: true,
            showButtons: true,
            minuteSteps: 15,
            minDate: floorMoment.clone(),
          };
          if (resolvedStart) {
            config.startDate = resolvedStart.clone();
            config.startEmpty = false;
          }

          var config_end = {
            format: 'MMM D' + (enableTime? ', h:mm A': ''),
            showTimePickers: enableTime,
            singleDate: true,
            showOn: 'bottom',
            autoAlign: false,
            showFooter: false,
            // startEmpty: true,
            // startDate: endDateDefault,
            startDate: endNow.clone(),
            showButtons: true,
            minuteSteps: 15,
            minDate: endNow.clone(),
          };

          if (disabledWeekDays) {
            config.disableDays = function(date) {
              return disabledWeekDays.hasOwnProperty(date.day());
            };
          }
          if ($endDate.length) {

            if (endDateValue === '' && endDateDefault !== '' && enableEnd && !$endDate.data('range')) {
              $endDate.calentim(config_end);
              endCalendar = $endDate.data('calentim');
            } 
            if ($endDate.data('range')) {
                setTimeout(() => {
                  $('button.calentim-apply').trigger('click');
                }, 1000);
            }

            var minRange = parseInt($endDate.data('min-rage')),
              maxRange = parseInt($endDate.data('max-rage')),
              dateStep = parseInt($endDate.data('date-step'));
            if ($endDate.data('range')) {
              var rangeStart = minRange > dateStep? Math.ceil(minRange / dateStep) * dateStep: dateStep;
              config.onafterselect = function(calentim, start) {
                var value = $endDate.data('value');
                if (value) {
                  $endDate.data('value', '');
                }
                var options = '';
                var i = 0, days = rangeStart;
                while (i < 20) {
                  if (maxRange > 0 && days > maxRange) {
                    break;
                  }
                  var end = start.clone().add(days, 'd');
                  if ( !config.disableDays || !config.disableDays(end)) {
                    end = end.format(serverDateFormat);
                    options += '<option value="' + end + '" ' + (value === end? 'selected': '') + '>' +
                      days + ' days</option>';
                    i++;
                  }
                  days += dateStep;
                }
                $endDate.html(options);
              };


            } else {
              config.onafterselect = function(calentim, startDate) {
                var minDate = startDate.clone();
                if (minRange > 0) {
                  minDate.add(minRange, 'd');
                }
                endCalendar.setMinDate(minDate);
                if (maxRange > 0) {
                  endCalendar.setMaxDate(startDate.add(maxRange, 'd'));
                }
                if (endCalendar.config.startDate && endCalendar.config.disableDays(endCalendar.config.startDate)) {
                  endCalendar.clearInput();
                }
              };
            }
          }
          $startDate.calentim(config);
          startCalendar = $startDate.data('calentim');
          // startCalendar.setStart(startDateDefault);

          // EVENT DATE OFFSET: Show rental period when user selects a date
          if (typeof RntpDateOffsets !== 'undefined' && RntpDateOffsets.isActive()) {
            (function() {
              var $periodDisplay = $('.rntp-rental-period-details');
              var _origAfterSelect = startCalendar.config.onafterselect;
              startCalendar.config.onafterselect = function(calentim, startDateVal) {
                // Call original handler first (e.g. end date range builder)
                if (typeof _origAfterSelect === 'function') {
                  _origAfterSelect(calentim, startDateVal);
                }
                // Show computed rental period
                var result = RntpDateOffsets.computeDisplay(startDateVal || startCalendar.config.startDate);
                RntpDateOffsets.updateDisplay($periodDisplay, result);
              };
              // If start date is already set on load, show period immediately
              if (startCalendar.config.startDate) {
                var initResult = RntpDateOffsets.computeDisplay(startCalendar.config.startDate);
                RntpDateOffsets.updateDisplay($periodDisplay, initResult);
              }
            })();
          }

          if ($endDate.length) {
            if (!$endDate.data('range')) {
              if (dateStep) {
                config.disableDays = function(date) {
                  return (disabledWeekDays && disabledWeekDays.hasOwnProperty(date.day()))
                    || (startCalendar.config.startDate && date.diff(startCalendar.config.startDate.clone().startOf('day'), 'days') % dateStep !== 0);
                };
              }
              config.onafterselect = function() {};
              if ($endDate.data('value')) {
                config.startEmpty = false;
                config.startDate = moment($endDate.data('value'), serverDateFormat);
              } else {
                config.startEmpty = true;
                config.startDate = null;
              }
              $endDate.calentim(config);
              endCalendar = $endDate.data('calentim');
            }
            if (startCalendar.config.startDate) {
              startCalendar.updateInput(true);
            }
          }

          var $zip = $('#rental_zip');

          triggerDateSelect();
          
          $(document).on('click', '#submit_date, .rntp-submit-button', function(e) {
            e.preventDefault();

            var pid = 0;
            var url = '';
            // product data
            var $product_div = $('div.product');
            if ($product_div.length && rntpIsAutoAddToCartEnabled()) {
              let $rntp_form_holder = $('#rntp-form-holder');
              pid = $rntp_form_holder.data('pid') || 0;
              url = $rntp_form_holder.data('url') || '';
            }

            var start = startCalendar.config.startDate;
            if ( !start) {
              $startDate.focus();
              $startDate.closest('.rntp-start-date-block').find('label').css('color', '#e2401c');
              e.preventDefault();
              return false;
            }
            if ($endDate.length) {
              var end = endCalendar? endCalendar.config.startDate: $endDate.val();
              if ( !end) {
                $endDate.focus();
                $endDate.closest('.rntp-end-date-block').find('label').css('color', '#e2401c');
                e.preventDefault();
                return false;
              }
            }
            if ($zip.length && !$zip.val()) {
              $zip.focus();
              $zip.prev().css('color', '#e2401c');
              e.preventDefault();
              return false;
            }

            // end date
            var endDate;
            if ($endDate.data('range')) {
              endDate = end;
            } else {
              if (endCalendar) {
                endDate = end.format(serverDateFormat);
              }
            }

            var startDate = start.format(serverDateFormat);
            if ( !startDate) {
              return false;
            }

            var eventDateForServer = '';
            if (typeof RntpDateOffsets !== 'undefined' && RntpDateOffsets.isActive()) {
              eventDateForServer = start.format(serverDateFormat);
            }

            var address = '';
            var $address = $("#rental_address")
            if ($address.length) {
              address = $address.val().trim()
              if (address === '') {
                $address.focus();
                $address.prev().css('color', '#e2401c');
                return false;
              }
            }
          
            // EVENT DATE OFFSET: Server uses event_date; send computed range for validation.
            if (typeof RntpDateOffsets !== 'undefined' && RntpDateOffsets.isActive()) {
              var _offsetResult = RntpDateOffsets.apply(start);
              if (_offsetResult) {
                startDate = _offsetResult.startDate.format(serverDateFormat);
                endDate   = _offsetResult.endDate.format(serverDateFormat);
                RntpDateOffsets.updateDisplay($('.rntp-rental-period-details'), _offsetResult);
              }
            }

            // set start date, end date and zip code
            set_form_data(startDate, endDate, $zip.val(), address, pid, url, eventDateForServer);

          })

          /**
           * Auto-show products on date selection.
           *
           * When the admin setting is enabled, clicking the calentim
           * "Apply" button automatically triggers the "Show Products"
           * (#submit_date) button on any page carrying the date form.
           *
           * A visible ZIP Code field does not switch this off. The field is
           * honoured per click below, so the submit waits for a ZIP Code
           * instead of never happening.
           *
           * Settings are read from rentalAutoDateSettings (a dedicated
           * localized object) to avoid being overwritten by other scripts
           * that re-declare rentalObj.
           */

          var rntpHasDateForm = $('#submit_date').length > 0;

          if (
            rntpFlagEnabled('autoShowProductsOnDateSelection', false) &&
            rntpHasDateForm
          ) {

            var rntpAutoSubmitTimer = null;

            /**
             * Whether "Show Products" would be accepted right now. These are
             * the same checks the submit handler makes, so the automatic click
             * is only sent when a manual one would go through.
             *
             * The question is what the form holds, not which field was touched
             * last. A visitor who already has dates usually re-opens only one
             * of the two calendars, and the other date is still applied.
             */
            function rntpDatesReadyToSubmit() {
              if ( !startCalendar || !startCalendar.config.startDate) {
                return false;
              }
              if ($endDate.length) {
                var end = endCalendar? endCalendar.config.startDate: $endDate.val();
                if ( !end) {
                  return false;
                }
              }
              // Respect the required ZIP Code field: don't auto-submit (or show
              // the overlay) until a valid ZIP Code has been entered.
              var $zipField = $('#rental_zip');
              if ($zipField.length && $zipField.prop('required') && !$.trim($zipField.val())) {
                return false;
              }
              return true;
            }

            // Show spinner on manual submit_date click
            $(document).on("click", "#submit_date", function() {
              rntpOverlay.show($('.rntp-form-wrapper'));
            });

            // .rntpAutoSubmit
            $(document).on("click", ".calentim-apply", function() {
              // A date range is picked from a select after the calendar closes,
              // so Apply is not the point at which the dates are complete.
              if ($endDate.length && $endDate.data('range')) {
                return;
              }
              if ( !rntpDatesReadyToSubmit()) {
                return;
              }

              var $submitBtn = $('#submit_date');
              if ($submitBtn.length && !$submitBtn.prop('disabled')) {
                rntpOverlay.show($('.rntp-form-wrapper'));
                // Collapse a second Apply landing before the first one fired
                clearTimeout(rntpAutoSubmitTimer);
                rntpAutoSubmitTimer = setTimeout(function() {
                  $submitBtn.trigger('click');
                }, 150);
              }
            });
          }

        }

      },
      error: function(response) {
        console.log("error:", response)

        let statusCode = response.status;
        let data = response.responseJSON;
        if (statusCode == 500) {

          if (data.message) {
            const msgBox = messageTemplate(data.message, 'error');
            $('.woocommerce-notices-wrapper').after(msgBox);
          }
        }

      },
      complete: function() {
        //location.reload();
        unblock(frm);
        $("#rntp-form-holder").css('background', 'transparent')
      }
    });
  }

  // submit date form POST request
  function set_form_data(startDate, endDate, zip, address = "", pid = 0, url = '', eventDate = '') {
    
    var dataObj = {
      action: 'rental_date_form_api',
      start_date: startDate,
      end_date: endDate,
      zip: zip,
      address: address,
    }

    if (eventDate) {
      dataObj.event_date = eventDate;
    }

    if (pid !== 0) {
      dataObj.pid = pid;
    }
    if (url !== '') {
      dataObj.url = url;
    }
    
    block($("#rental_date_form"));
    $.ajax({
      type: 'POST',
      url: rentalObj.url,
      dataType: 'JSON',
      data: dataObj,
      success: function(data) {
        if (data !== undefined && data.data !== '') {
          localStorage.setItem('rental_unavailable_items', JSON.stringify(data));
        }
        
        $("#rental_date_form").addClass('rntp-form-filled')
        rntpFilledButton.sync();
        rntpDateBlocker.hide();

        var redirect_to_product_page = true;
        var check_url1 = window.location.href.split("/");
        var check_url2 = window.location.href.split("?");

        var check_product, check_cart;

        if (check_url1.length > 1) {

          for(let i=0; i < check_url1.length; i++) {
            check_product = check_url1[i].includes("product") || check_url1[i].includes("shop") || check_url1[i].includes("page_id=9");
            check_cart = check_url1[i].includes("cart") || check_url1[i].includes("page_id=10");
            if (check_product || check_cart) {
              redirect_to_product_page = false;
              break;
            }
          }
        }

        if (redirect_to_product_page) {
          if (check_url2.length > 1) {

            for(let i=0; i < check_url2.length; i++) {
              check_product = check_url2[i].includes("product") || check_url2[i].includes("shop") || check_url2[i].includes("page_id=9");
              check_cart = check_url2[i].includes("cart") || check_url2[i].includes("page_id=10");
              if (check_product || check_cart) {
                redirect_to_product_page = false;
                break;
              }
            }
          }
        }
        
        if (data.add_to_cart_msg_success === '' && data.add_to_cart_msg_failure !== '') {
          // error
          localStorage.setItem('rental_add_to_cart_error_msg', JSON.stringify(data.add_to_cart_msg_failure));
        } 
        if (data.add_to_cart_msg_success !== '') {
          // success
          localStorage.setItem('rental_add_to_cart_success_msg', JSON.stringify(data.add_to_cart_msg_success));
        }

        if (data.current_url) {
          window.location.replace(data.current_url);
        } else {
          // if not in shop page, product page or cart page, redirect to shop page
          if (redirect_to_product_page) {
            if (data.shop_page_url) {
              window.location.replace(data.shop_page_url)
            }
          }
          window.location.replace(window.location.href);
        }
        
      },
      error: function(response) {

        let statusCode = response.status;     
        let data = response.responseJSON;

        console.log('error:', data);
        
        let msgText = null;
        if (data.type === 'error') {
          msgText = data.message_txt;
        }
        else if (statusCode === 500 && data.message) {
          msgText = data.message;
        }

        if (msgText) {
          const $wrapper = $('.woocommerce-notices-wrapper').first();
      
          // remove old notices inside it
          $wrapper.find('.rntp-notification').remove();
      
          const msgBox = messageTemplate(msgText, 'error');
          $wrapper.after(msgBox);
      
          $("#rental_date_form").removeClass('rntp-form-filled');
          rntpFilledButton.sync();
          rntpDateBlocker.show();
        }
        
      },
      complete: function() {
        unblock($("#rental_date_form"));
        rntpOverlay.hide($('.rntp-form-wrapper'));
      }
    });
  }

  $('.rntp-notification').on('click', '.rntp-notification__close', function(e) {
    e.preventDefault();
    $(this).parents('.rntp-notification').hide('slow');
  });

  // block of loading/processing
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

  // unblock loading/processing
  function unblock($node) {
    $node.removeClass('processing').unblock();
  }


  // close notification
  $(document).on('click', '.rntp-notification .color--error', function(e) {
    e.preventDefault();
    $(this).parent().fadeOut();
  });

  // trigger minicart on date select error
  function triggerDateSelect() {
    var dateErrorMsg = $("#rental_error_select_dates");
    if (dateErrorMsg.length) {
      let $rentalSelectBtn = $('.rental-select-dates');
      let $minicartBtn = $('.mini-cart__button');
      let $minicartHolder = $('.header-minicart');

      let is_automat_openable = $rentalSelectBtn.data('openable');
      if (is_automat_openable == 1) {
        if ($minicartHolder.length) {
          setTimeout(() => {
            $minicartHolder.find('a').trigger("click");
          }, 1000);
        } else if (frm.length > 0 || $minicartBtn.length > 0) {
          setTimeout(() => {
            $rentalSelectBtn.trigger("click");
          }, 1000);
        }
      }
    }
  }
  triggerDateSelect();
    
  // display cart success/failure message
  var urlArray = window.location.href.split("&added_to_cart=");
  var msgBox;

  if (urlArray.length && urlArray[1] !== undefined && urlArray[1] === 1) {
    var msgSuccess = localStorage.getItem('rental_add_to_cart_success_msg');
    
    if (msgSuccess !== '' && msgSuccess != null) {
      msgBox = '<div class="woocommerce-message" role="alert">' + JSON.parse(msgSuccess) + '</div>';
      $(".woocommerce-notices-wrapper").after(msgBox)
      localStorage.removeItem('rental_add_to_cart_success_msg')
    }

  } else if(urlArray.length && urlArray[1] !== undefined && urlArray[1] === 0) {
    var msgError = localStorage.getItem('rental_add_to_cart_error_msg');

    if (msgError !== '' && msgError != null) {
      msgBox = messageTemplate(JSON.parse(msgError), 'error');
      $(".woocommerce-notices-wrapper").after(msgBox)
      localStorage.removeItem('rental_add_to_cart_error_msg')
    }

  }


  $(document.body).trigger('wc_fragment_refresh');


    let $checkoutBtnOnMiniCart = $(".rental_woocommerce-mini-cart__buttons");
    if ($checkoutBtnOnMiniCart.length) {
      // textual labels
      var $minicartCheckoutBtn = $checkoutBtnOnMiniCart.find("a.wc-forward");
      block($minicartCheckoutBtn);
      // Rentpro theme
      // mini cart subtotal, total values update + checkout button custom textual label replacement 
      $.ajax({
        type: 'POST',
        url: rentalObj.url,
        data: {
          'action' : 'rental_replace_mini_cart_textual_labels_values'
        },
        success: function(data) {
          // console.log("success on minicart checkout text update!")
        },
        error: function() {
        },
        complete: function(data) {

          var dataJson = data.responseJSON;
          if (dataJson !== undefined) {
              if (dataJson.checkout_label !== '') {
                $minicartCheckoutBtn.text(dataJson.checkout_label)
              }
              
              if (!rentalCartPricesHidden()) {
                if ($('.cart-totals-row').length) {
                  if (dataJson.subtotal != 0) {
                    $('.cart-subtotal').find('.woocommerce-Price-amount bdi').html('<span class="woocommerce-Price-currencySymbol">' + dataJson.currency_symbol + '</span>' + dataJson.subtotal)

                  }

                  if (dataJson.total != 0) {
                    $('.order-total').find('.woocommerce-Price-amount bdi').html('<span class="woocommerce-Price-currencySymbol">' + dataJson.currency_symbol + '</span>' + dataJson.total)

                  }

                }

                if ($('.woocommerce-mini-cart__total').length) {
                  $('.woocommerce-Price-amount bdi').html('<span class="woocommerce-Price-currencySymbol">' + dataJson.currency_symbol + '</span>' + dataJson.subtotal)
                }
              }

              unblock($minicartCheckoutBtn);
          }
        }
      });

    }

    // Coupons related
    let removeCouponAction = function($removeButton) {
        var coupon = $removeButton.data('coupon');
    
        $.ajax({
            type: 'POST',
            url: rentalObj.url,
            data: {
              'action' : 'rental_remove_coupon',
              coupon_code: coupon
            },
            success: function(response) {

              if (response.message) {
                const msgBox = messageTemplate(response.message, 'success');
                $('.woocommerce-notices-wrapper').after(msgBox);
              }

              $('body').trigger('update_checkout');

              setTimeout(() => {
                $("tr.cart-discount").fadeOut();
              }, 1000);
             

              // console.log("success removeCouponAction")
            }
        });
    }
    
    function updateCouponLabels() {
      $.ajax({
      type: 'POST',
      url: rentalObj.url,
      data: {
        action: 'rental_replace_coupon_text_label'
      },
      success: function(response) {
        if (response.success && response.data && response.data.coupon_label) {
        const label = response.data.coupon_label;
  
        // Modal titles (both RentPro & fly-cart)
        $('.modal-content-wrap .modal-title').each(function() {
          const txt = $(this).text().trim().toLowerCase();
          if (txt.indexOf('coupon') !== -1) {
          $(this).text('Select or input ' + label);
          }
        });
  
           $('.form-coupon').find('.fly-cart-modal-title').text('Select or input ' + label);
  
        // Descriptions
        $('.form-coupon .form-description').each(function() {
          const txt = $(this).text().toLowerCase();
          if (txt.indexOf('coupon') !== -1) {
          $(this).text('If you have a ' + label.toLowerCase() + ' , please apply it below.');
          }
        });
  
        // <label> elements for both inputs
        $('.form-coupon label[for="coupon_code"], .form-coupon label[for="fly-cart-coupon_code"]').each(function() {
          $(this).text(label);
        });
  
        // Placeholders for both inputs
        $('#coupon_code, #fly-cart-coupon_code').attr('placeholder', label);
  
        // Apply buttons
        $('.form-coupon button[name="apply_coupon"]')
          .val('Apply ' + label)
          .text('Apply ' + label);
  
        // Trigger links (both RentPro and fly-cart)
        $('a[data-rentpro-target="#modal-cart-coupon"] span:last-child, \
           a.fly-cart-addon-modal-toggle[data-target="#fly-cart-modal-coupon"] span:last-child, \
           a.fly-cart-addon-modal-toggle span:last-child').each(function() {
          if ($(this).text().trim().toLowerCase() === 'coupon') {
          $(this).text(label);
          }
        });
  
  
        }
      }
      });
    }
  
  
    setTimeout(() => {
      updateCouponLabels()
    }, 100);
    
    $(document).on('click', 'a.fly-cart-addon-modal-toggle', function() {
      setTimeout(() => {
        updateCouponLabels()
      }, 20);
    });


    function getInvalidCouponResponse() {
      $.ajax({
        type: 'POST',
        url: rentalObj.url,
        data: {
          action: 'rental_get_invalid_coupon_response'
        },
        success: function(response) {
          
          if (response.data && response.data.msg != '') {
        
              const msgBox = messageTemplate(response.data.msg, 'error');
              $('.woocommerce-notices-wrapper').after(msgBox);
          }
        }
      });
    }
    getInvalidCouponResponse();


 
    $(document).on('click', '.remove-coupon-link', function(e) {
      e.preventDefault();

      setCookie('rental_coupon_must_be_removed', 1);
      removeCouponAction($(this))
    });

    setTimeout(() => {
      if ($(".remove-coupon-link").length && getCookie('rental_coupon_must_be_removed')) {
        removeCouponAction($(".remove-coupon-link"))
      }
    }, 1500);
   


    setTimeout(() => {
      if (!$(".remove-coupon-link").length) {
        deleteCookie('rental_coupon_must_be_removed')
      }
    }, 100);
    


  if ($('#rental_address_min').length || $('#rental_address').length) {
    
    $.ajax({
      type: 'POST',
      url: rentalObj.url,
      data: {
        'action' : 'update_rental_shipping_cost'
      },
      success: function(data) {
      },
      error: function() {
      },
      complete: function(data) {

        let dataJson = data.responseJSON;
        
        // console.log("shipping data", dataJson)

        if (dataJson !== undefined) {

          setTimeout(() => {
            let lbl = $('#shipping_method li').find('label[for="shipping_method_0_miles_based"]')
            lbl.html('')
            lbl.html(dataJson.shipping_label_cost);

            // console.log(lbl.html())
          }, 700);

        }

      }
    })

  }


  setTimeout(() => {
    var googleMapData = getCookie('rental_google_map_address');

    if (googleMapData !== '' && googleMapData !== undefined) {
        if (isJsonString(googleMapData)) {
            var address_parts = JSON.parse(googleMapData);

            var city = '', state = '', country = '', zip = '', address = '', address_2 = '';
            for (var i in address_parts) {
                if (address_parts[i]['key'] === 'city') city = address_parts[i]['value'];
                if (address_parts[i]['key'] === 'state') state = address_parts[i]['value'];
                if (address_parts[i]['key'] === 'country') country = address_parts[i]['value'];
                if (address_parts[i]['key'] === 'zip') zip = address_parts[i]['value'];
                if (address_parts[i]['key'] === 'address') address = address_parts[i]['value'].trim();
                if (address_parts[i]['key'] === 'address_2') address_2 = address_parts[i]['value'].trim();
            }

            // Send data to PHP via AJAX
            $.post(rentalObj.url, {

                action: 'rental_update_checkout_address_fields',
                city: city,
                state: state,
                country: country,
                zip: zip,
                address: address,
                address_2: address_2
            }, function(response) {

                if (response.success) {
                    // console.log("Address updated in session.");
                }
            });
        }
    }
  }, 700);
  


  var messageTemplate = function(message, type) {
    const errorTypeClasses = {
      'success' : 'rntp-notification--success',
      'error' : 'rntp-notification--error',
    };
    let errorTypeClass = errorTypeClasses[type] || 'rntp-notification--error';
    let errorTypeIcon = '';

    if (type === 'error') {
      errorTypeIcon =  '<div class="rntp-notification__status rntp-bg--gradient-red">' +
        '&times;' +
      '</div>';
    }
  
    const template =
      '<div class="rntp-notification '+ errorTypeClass +'" style="margin-top:1rem">' +
        '<a href="#" class="rntp-notification__close color--error">×</a>' +
        errorTypeIcon +
        '<div class="rntp-notification__content">' +
          '<p class="rntp-notification__text">' + message + '</p>' +
        '</div>' +
      '</div>';

      return template;
  } 

  function openFlyCartOnCartCheckoutPages() {
    if ( ! $('#popup-fly-cart').length || typeof window.open_fly_cart !== 'function' ) {
      return;
    }

    var onCart     = $('body').hasClass( 'woocommerce-cart' );
    var onCheckout = $('body').hasClass( 'woocommerce-checkout' );
 
    if ( onCart || onCheckout ) {

       // Neutralizing the real link so no native navigation fires
       $('.mini-cart__button')
         .attr( 'href', 'javascript:void(0)' )
         .css( 'cursor', 'pointer' );
 
       // (Re)binding the click to open fly‑cart
       $('body')
         .off( 'click.openFlyOnCart', '.mini-cart__button' )
         .on( 'click.openFlyOnCart', '.mini-cart__button', function(e){
           e.preventDefault();
           window.open_fly_cart();
        });

        // edit dates icon click actions
        $(document).on("click", ".dates-edit", function(e) {
          window.open_fly_cart();

          async function triggerDatesClicks() {
            await delaySetTimeOut(250);
            if ($("#select-rental-dates").length)
              $("#select-rental-dates").trigger('click');
          
            await delaySetTimeOut(350);
            if ($("#rental_min_date_input").length)
              $("#rental_min_date_input").trigger('click');
          }
          
          triggerDatesClicks();
        })
    }
  }
  openFlyCartOnCartCheckoutPages();
 
  function delaySetTimeOut(ms) {
    return new Promise(resolve => setTimeout(resolve, ms));
  }

  function showTooltip(selector) {
      var $btn = $(selector);
      var msg  = $btn.attr('title');
      if (!msg) return;

      $btn.removeAttr('title');

      // build tooltip
      var $tip = $(
          '<div class="tooltip">'
        +   '<span class="tooltip-arrow dashicons dashicons-arrow-down"></span>'
        +   '<div class="tooltip-content"></div>'
        + '</div>'
      ).appendTo('body');
      $tip.find('.tooltip-content').text(msg);

      function positionTip() {
        $tip.css({
          visibility: 'hidden',
          display:    'block',
          opacity:    0
        });

        var off  = $btn.offset(),
            w    = $btn.outerWidth(),
            tipW = $tip.outerWidth(),
            tipH = $tip.outerHeight();

        // position and reveal invisibly
        $tip.css({
          left:       off.left + w/2 - tipW/2,
          top:        off.top  - tipH - 8,
          visibility: 'visible'
        });
      }

      // initial show on page load
      positionTip();
      // allow that reflow to happen, then fade in
      setTimeout(function(){
        $tip.css('opacity', 1);
      }, 0);

      // hide after 5s 
      setTimeout(function(){
        $tip.css('opacity', 0);
      }, 5000);

      // hover behavior
      $btn.on('mouseenter', function(){
        positionTip();
        // Use a short delay to prevent flickering if moving mouse quickly
        setTimeout(function(){
          $tip.css('opacity', 1);
        }, 50); 
      }).on('mouseleave', function(){
        $tip.css('opacity', 0);
      });
  }
  showTooltip('.dates-edit');
  
});


function setCookie(name, value, days = 7, path = '/'){
  const expires = new Date(Date.now() + days * 864e5).toUTCString()
  document.cookie = name + '=' + encodeURIComponent(value) + '; expires=' + expires + '; path=' + path
}

function getCookie(name){
  return document.cookie.split('; ').reduce((r, v) => {
    const parts = v.split('=')
    return parts[0] === name ? decodeURIComponent(parts[1]) : r
  }, '')
}

function deleteCookie(name, path) {
  setCookie(name, '', -1, path)
}

function showProcessingModal() {
  let modalHtml = `
      <div id="processing-modal" class="processing-modal" >
          <div class="processing-modal-content">
              <p> 
                Processing, please wait...
              </p>
          </div>
      </div>`;
    jQuery("body").append(modalHtml);
}

function hideProcessingModal() {
  jQuery("#processing-modal").remove();
}

function isJsonString(str) {
  try {
      JSON.parse(str);
  } catch (e) {
      return false;
  }
  return true;
}


function validate(data, type = "", checkEmpty = true) {
  if (checkEmpty) {
    if (data.trim() === '') {
      return "empty_err";
    }
  }

  if (type) {
    switch(type) {
      case "not_numeric": 
        if ( (/\D/.test(data))) {
          return true;
        } else {
          return "not_numeric_err";
        }
      case "numeric": 
        if ( !(/\D/.test(data))) {
          return true;
        } else {
          return "numeric_err";
        }
      case "email": 
        if (data.match(
          /^(([^<>()[\]\\.,;:\s@"]+(\.[^<>()[\]\\.,;:\s@"]+)*)|(".+"))@((\[[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}])|(([a-zA-Z\-0-9]+\.)+[a-zA-Z]{2,}))$/
        )) {
          return true;
        } else {
          return "email_err";
        }
    }
  }
  return true;
}

function validateMsg(hint, fieldName) {
  switch(hint) {
    case "empty_err": 
        return "The " + fieldName + " field is required.";
    case "not_numeric_err": 
      return fieldName + " must not be numeric.";
    case "numeric_err": 
      return fieldName + " must be numeric.";
    case "email_err": 
      return "Please, insert a correct email address.";
    case "serverside": 
      return "Something went wrong, try again later.";
      
  }
}

jQuery('body').on('click', '.rental-select-dates', function(e) {
  e.stopPropagation();
  let trigger_type = jQuery(this).data('type');
  if(trigger_type === 'in-cart') {
    var cart_toggle_button = document.querySelector('.header-minicart .toggle, .mini-cart__button');
    // mini-cart__button
    cart_toggle_button.click();
  }
  else{

    if (jQuery("#rntp-form-holder").length > 0) {
      let is_quickview = jQuery(this).parents('.quick-view-col-summary').length;
      if(is_quickview){
        jQuery('.button-close-modal').trigger('click');
      }
      setTimeout(() => {
        if (jQuery("#rental_date_form").length > 0) {
          document.getElementById('rental_date_form').scrollIntoView({ behavior: 'smooth'});
          //window.scrollBy(0, -30);
          var calentim = jQuery("#rental_start_date").data("calentim");
          calentim.showDropdown(e);
        }
      }, 1000);
    }
    
  }
});
