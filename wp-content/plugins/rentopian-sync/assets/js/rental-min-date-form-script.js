jQuery(document).ready(function($) {

  /**
   * Whether submitting the date form should also add the current product to
   * the cart. Defaults to enabled when the setting is not localized.
   *
   * Localized values arrive as strings, so "0" passes a plain truthiness
   * check — always compare the parsed number.
   */
  function rntpIsAutoAddToCartEnabled() {
    if (typeof rentalAutoCartSettings === 'undefined'
      || typeof rentalAutoCartSettings.autoAddToCartOnDateSelection === 'undefined') {
      return true;
    }
    return parseInt(rentalAutoCartSettings.autoAddToCartOnDateSelection, 10) === 1;
  }

  /**
   * Whether cart amounts must stay hidden from the visitor. The mini-cart
   * total is repainted here from an AJAX response, so it needs the same gate
   * the templates apply. Localized flags arrive as strings.
   */
  function rentalCartPricesHidden() {
    return parseInt((window.rentalPriceVisibility || {}).hideCartPrices, 10) === 1;
  }

  var $min_date_form = $('#rental_min_date_form');
  if ($min_date_form.length) {
    var $minDateInput = $('#rental_min_date_input'), minCalendar,
      $dateRange = $('#rental_min_date_range'),
      startDateDefault = $minDateInput.data('start-date-default'),
      endDateDefault = $minDateInput.data('end-date-default'),
      minStartAllowedDays = parseInt($minDateInput.data('min-start')),
      minRange = parseInt($minDateInput.data('min-rage')),
      maxRange = parseInt($minDateInput.data('max-rage')),
      dateStep = parseInt($minDateInput.data('date-step')),
      disabledWeekDays = $minDateInput.data('disabled-week-days'),
      enableEnd = !$minDateInput.data('hide-end'),
      enableTime = !$minDateInput.data('hide-time'),
      startTimeDefault = $minDateInput.data('default-start-time'),
      endTimeDefault = $minDateInput.data('default-end-time'),
	    selectDayByDefaultAllowed = $minDateInput.data('select-day-by-default'),
      serverDateFormat = 'YYYY/MM/DD' + (enableTime? ' h:mm A': '');

    if (dateStep > 0) {
      var rangeStart = minRange > dateStep? Math.ceil(minRange / dateStep) * dateStep: dateStep;
    }
	  
	

    // Absolute dates resolved server-side, in the store timezone. The floor and
    // the preselected day are separate values: the offset is already inside
    // both, so nothing here may add it again.
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

    var applyTime = function(target, timeString) {
      if (!target || !timeString) {
        return target;
      }
      var parsedTime = moment(timeString, 'h:mm A');
      if (parsedTime.isValid()) {
        target.hour(parsedTime.hour()).minute(parsedTime.minute()).second(0);
      }
      return target;
    };

    // Earliest day that may be selected.
    var floorMoment = parseAbsolute($minDateInput.data('min-start-date'));
    if (floorMoment) {
      if (!enableTime) {
        applyTime(floorMoment, startTimeDefault);
      }
    } else if (minStartAllowedDays > 0) {
      floorMoment = moment().startOf('day').add(minStartAllowedDays, 'd');
      applyTime(floorMoment, startTimeDefault);
    } else {
      floorMoment = moment().startOf('hour').add(1, 'h');
    }

    var isBeforeFloor = function(candidate) {
      return !!candidate && candidate.clone().startOf('day').isBefore(floorMoment.clone().startOf('day'));
    };

    // Day the calendar opens on when nothing is stored yet.
    var defaultStart = parseAbsolute($minDateInput.data('default-start'));
    if (!defaultStart && startDateDefault) {
      defaultStart = moment(startDateDefault, 'MMM D, h:mm A');
      defaultStart = defaultStart.isValid()? defaultStart: null;
    }
    if (isBeforeFloor(defaultStart)) {
      defaultStart = floorMoment.clone();
    }

    var defaultEnd = parseAbsolute($minDateInput.data('default-end'));
    if (!defaultEnd && endDateDefault) {
      defaultEnd = moment(endDateDefault, 'MMM D, h:mm A');
      defaultEnd = defaultEnd.isValid()? defaultEnd: null;
    }

    // Dates already chosen by the customer. A stored start inside the blocked
    // window is dropped rather than preselected — the range it belongs to is
    // no longer bookable, so the end goes with it.
    var savedStart = parseAbsolute($minDateInput.data('saved-start'));
    var savedEnd = parseAbsolute($minDateInput.data('saved-end'));
    if (isBeforeFloor(savedStart)) {
      savedStart = null;
      savedEnd = null;
    }

    var resolvedStart = savedStart || defaultStart;
    var resolvedEnd = savedStart? savedEnd: defaultEnd;

    if (resolvedStart && !enableTime) {
      applyTime(resolvedStart, startTimeDefault);
    }
    if (resolvedEnd && !enableTime) {
      applyTime(resolvedEnd, endTimeDefault);
    }

    // Never hand calentim an inverted range — it silently swaps the two, which
    // would land the earlier day in the start slot, under the floor. A start
    // with no end of its own gets the same treatment, so the pair is complete.
    if (resolvedStart && enableEnd && (!resolvedEnd || resolvedEnd.isBefore(resolvedStart))) {
      resolvedEnd = resolvedStart.clone();
      if (minRange > 0) {
        resolvedEnd.add(minRange, 'd');
      }
      if (!enableTime) {
        applyTime(resolvedEnd, endTimeDefault);
      }
    }

    // Anchor the fixed-interval range list on whichever day the calendar opens.
    var nowMoment = resolvedStart? resolvedStart.clone(): floorMoment.clone();

    var disableWeek = function(date) {
      return disabledWeekDays && disabledWeekDays.hasOwnProperty(date.day());
    };

    var generateRanges = function(start) {
      if (dateStep <= 0 || (dateStep % 7 === 0 && disableWeek(start))) {
        return [];
      }
      var end, ranges = [];
      var days = rangeStart;
      var max = maxRange > 0? maxRange: (100 * dateStep);
      while (ranges.length < 5 && days <= max) {
        end = start.clone().add(days, 'd');
        if ( !disableWeek(end)) {
          ranges.push({
            days: days,
            title: days + ' days',
            startDate: start,
            endDate: end
          });
        }
        days += dateStep;
      }
      return ranges;
    };

    // EVENT DATE OFFSET: Check if offsets are active
    var rntpOffsetsActive = (typeof RntpDateOffsets !== 'undefined' && RntpDateOffsets.isActive());

    var config = {
      format: 'MMM D' + (enableTime? ', h:mm A': ''),
      showTimePickers: enableTime,
      singleDate: $dateRange.length === 1 || !enableEnd,
      // inline: true,
      // arrowOn: 'right',
      showOn: 'bottom',
      autoAlign: false,
      // calendarCount: 1,
      // showHeader: false,
      showFooter: false,
      startEmpty: true,
      // startDate: startDateDefault,
      showButtons: true,
      // rangeOrientation: 'vertical',
      minuteSteps: 15,
      minDate: floorMoment.clone(),
      minSelectedDays: minRange,
      disableDays: function(date) {
        return disableWeek(date) ||
          (dateStep > 0 && this.startDate && !this.endDate &&
            date.diff(this.startDate.startOf('day'), 'days') % dateStep !== 0);
      }
    };

    if (resolvedStart) {
      config.startEmpty = false;
      config.startDate = resolvedStart.clone();
    }
    

    if ($dateRange.length) {
      $dateRange.fadeOut();
      let dateRangeLabel = $('.rental_min_date_range_label');
      dateRangeLabel.fadeOut();
      config.onafterselect = function(calentim, start) {
        $dateRange.fadeIn();
        dateRangeLabel.fadeIn();
        var value = $dateRange.data('value');
        if (value) {
          $dateRange.data('value', '');
        }
        var options = '';
        var i = 0, days = rangeStart;
        while (i < 20) {
          if (maxRange > 0 && days > maxRange) {
            break;
          }
          var end = start.clone().add(days, 'd');
          if ( !disableWeek(end)) {
            var selected = value === end.format('YYYY/MM/DD') ? 'selected' : '';
            options += '<option value="' + end.format(serverDateFormat) + '" ' + selected + '>' +
              days + ' days</option>';
            i++;
          }
          days += dateStep;
        }
        $dateRange.html(options);
      };
      
      // if ($minDateInput.data('start-date') === '' && $minDateInput.data('start-date-default')) {
      //   config.startEmpty = false;
      //   // config.startDate = moment($minDateInput.data('start-date-default'), serverDateFormat);
      //   config.startDate = moment(nowMoment, serverDateFormat);
      //   config.onafterselect(null, config.startDate);
      // }

      if (resolvedStart) {
        config.startEmpty = false;
        config.startDate = resolvedStart.clone();
        config.onafterselect(null, config.startDate);
      }


      $minDateInput.on("change, keyup, blur", function(){
        if ($(this).val() === '') {
          $dateRange.fadeOut();
          dateRangeLabel.fadeOut();
        }
      });

    } else if ( !config.singleDate) {
      config.oninit = function(calentim) {
        if (calentim.config.startDate && calentim.config.endDate) {
          var start = calentim.config.startDate.clone().startOf('day');
          var diff = calentim.config.endDate.diff(start, 'days');
          if ((minRange > 0 && diff < minRange) || (maxRange > 0 && diff > maxRange)|| (dateStep > 0 && diff % dateStep !== 0)
            || disableWeek(start) || disableWeek(calentim.config.endDate)) {
            calentim.clearInput();
          }
        }
      };
      config.onfirstselect = function(calentim, startDate) {
        // calentim.config.minDate = startDate.clone().add(minRange, 'd');
        if (maxRange > 0) {
          calentim.config.maxDate = startDate.clone().add(maxRange, 'd');
        }
        if (dateStep > 0) {
          // calentim.config.disableDays = function(date) {
          //   return disableWeek(date) || date.diff(startDate.startOf('day'), 'days') % dateStep !== 0;
          // };
          calentim.config.ranges = generateRanges(startDate);
          calentim.config.showFooter = !!calentim.config.ranges;
          calentim.reDrawCalendars();
        }
      };
      config.onafterselect = function(calentim) {
        calentim.config.minDate = floorMoment.clone();
        if (maxRange > 0) {
          calentim.config.maxDate = null;
        }
        if (dateStep > 0) {
          // calentim.config.disableDays = disableWeek;
          calentim.reDrawCalendars();
        }
      };
      if (dateStep > 0) {
        var ranges = generateRanges(nowMoment);
        if (ranges.length > 0) {
          config.ranges = ranges;
          config.showFooter = true;
        }
        config.onrangeselect = function() {
          minCalendar.globals.startDateBackup = null;
          minCalendar.globals.endSelected = true;
          minCalendar.globals.startSelected = false;
          minCalendar.globals.hoverDate = null;
          minCalendar.updateInput(false, false);
          minCalendar.footer.find('.calentim-apply').prop('disabled', false);
        };
      }
    }
    $minDateInput.calentim(config);
    minCalendar = $minDateInput.data('calentim');

    // Both panels come from the one resolved pair, in order, so the summary and
    // the edit form can never disagree.
    if (resolvedStart) {
      minCalendar.setStart(resolvedStart.clone());
    }
    if (resolvedEnd && enableEnd) {
      minCalendar.setEnd(resolvedEnd.clone());
    }

    // EVENT DATE OFFSET: Show rental period display
    if (rntpOffsetsActive && minCalendar) {
      (function() {
        var $periodDisplay = $('.rntp-rental-period-details');
        var _origAfterSelect = minCalendar.config.onafterselect;
        minCalendar.config.onafterselect = function(calentim, startDateVal) {
          // Call original handler first
          if (typeof _origAfterSelect === 'function') {
            _origAfterSelect(calentim, startDateVal);
          }
          // Show computed rental period
          var result = RntpDateOffsets.computeDisplay(startDateVal || (minCalendar.config.startDate));
          RntpDateOffsets.updateDisplay($periodDisplay, result);
        };
        // If start date already set on load, show period immediately
        if (minCalendar.config.startDate) {
          var initResult = RntpDateOffsets.computeDisplay(minCalendar.config.startDate);
          RntpDateOffsets.updateDisplay($periodDisplay, initResult);
        }
      })();
    }

    
    var $miniZip = $('#rental_min_zip_input');

    $min_date_form.on('submit', function(e) {
      e.preventDefault();

      if ( !minCalendar.config.startDate) {
        $minDateInput.focus();
        $minDateInput.css('border-bottom-color', '#e2401c');
        $('#rental_min_error_notification').text('Please select start date to proceed');
        return false;
      }


      serverDateFormat = serverDateFormat = 'YYYY/MM/DD h:mm A';
      var eventDateForServer = minCalendar.config.startDate.format(serverDateFormat);
      var data = {start_date: eventDateForServer};
      if ($dateRange.length) {
        data.end_date = $dateRange.val();
        if ( !data.end_date) {
          $dateRange.focus();
          $dateRange.css('border-bottom-color', '#e2401c');
          $('#rental_min_error_notification').text('Please select date range to proceed');
          return false;
        }
      } else if ( !minCalendar.config.singleDate) {
        if ( !minCalendar.config.endDate) {
          $minDateInput.focus();
          $minDateInput.css('border-bottom-color', '#e2401c');
          $('#rental_min_error_notification').text('Please select end date to proceed');
          return false;
        }
        data.end_date = minCalendar.config.endDate.format(serverDateFormat);
        
      }
      if ($miniZip.length) {
        data.zip = $miniZip.val();
        // if (!/^\d{4,6}$/.test(data.zip)) {
        if ( !data.zip) {
          $miniZip.focus();
          $miniZip.css('border-bottom-color', '#e2401c');
          $('#rental_min_error_notification').text('Please select zip code to proceed');
          return false;
        }
      }
      data.address = $min_date_form.find("#rental_address_min").val();

      $min_date_form.hide();
      var $loader = $('.rental-min-loader').show();

      // EVENT DATE OFFSET: Server applies offsets from event_date; send computed range for validation only.
      if (rntpOffsetsActive && minCalendar.config.startDate) {
        data.event_date = eventDateForServer;
        var _offsetResult = RntpDateOffsets.apply(minCalendar.config.startDate);
        if (_offsetResult) {
          data.start_date = _offsetResult.startDate.format(serverDateFormat);
          data.end_date = _offsetResult.endDate.format(serverDateFormat);
          RntpDateOffsets.updateDisplay($('.rntp-rental-period-details'), _offsetResult);
        }
      }

      data.action = 'rental_dates';
      
      // product data
      var $product_div = $('div.product');
      if ($product_div.length && rntpIsAutoAddToCartEnabled()) {
        var $dateSubmitBtn = $(this).find('#rental_min_date_form_submit');
        data.pid = $dateSubmitBtn.data('pid') || 0;
        data.url = $dateSubmitBtn.data('url') || '';
      }

      $.ajax({
        type: 'POST',
        url: $min_date_form.attr('action'),
        data: data,
        success: function(data) {
          // console.log("responseee  : ", data)
          if (data !== undefined && data.data !== '') {
            localStorage.setItem('rental_unavailable_items', JSON.stringify(data));
          }

          if (data.add_to_cart_msg_success === '' && data.add_to_cart_msg_failure !== '') {
            // error
            localStorage.setItem('rental_add_to_cart_error_msg', JSON.stringify(data.add_to_cart_msg_failure));
          } 
          if (data.add_to_cart_msg_success !== '') {
            // success
            localStorage.setItem('rental_add_to_cart_success_msg', JSON.stringify(data.add_to_cart_msg_success));
          }

          if (data.current_url !== '') {
            window.location.replace(data.current_url);
          } else {
            location.reload();
          }

          // // remove card fragment cache
          // if (typeof wc_cart_fragments_params !== 'undefined') {
          //   sessionStorage.removeItem(wc_cart_fragments_params.fragment_name);
          // }

          
        },
        error: function(data) {
          $loader.hide();
          $min_date_form.show();
          data = data.responseJSON;
          console.log(data);
          $('#rental_min_error_notification').text(data.message);
        }
      });
    });

    var $select_dates = $('#rental_min_select_dates');
    $select_dates.on('click', 'input', function() {
      $select_dates.hide();
      $min_date_form.show();
    });

    $('#rental_min_date_form_hide').on('click', function() {
      $min_date_form.hide();
      $select_dates.show();
    });

    var cart_toggle_button = document.querySelector('.header-minicart .toggle');
    var open_mini_cart = document.getElementById('rental_open_mini_cart');
    if (open_mini_cart) {
      cart_toggle_button.click();
      if (open_mini_cart.dataset.showError) {
        document.getElementById('rental_min_error_notification').innerText = open_mini_cart.innerText;
      }
    }


    if (!$('.rental-wishlist-box').length) {
       // click on apply dates form once
      $(document).on({
        'click' : function(e) {
          if (!$miniZip.length && !$("#rental_address_min").length) {
            let $dateSbmtBtn = $("#rental_min_date_form_submit")
            $dateSbmtBtn.trigger("click")
            // $dateSbmtBtn.attr('disabled', true)
            // block($dateSbmtBtn)
          }
        }
      }, '.calentim-apply')
    }
    
    
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
          msgBox = '<div class="rntp-notification rntp-notification--error" style="margin-top:1rem">' +
          '<a href class="rntp-notification__close color--error">×</a>' +
          '<div class="rntp-notification__status rntp-bg--gradient-red">' +
            ' &times;' +
          '</div>' +
          '<div class="rntp-notification__content">' +
              // '<h4 class="rntp-notification__title"> Failure </h4>' +
              '<p class="rntp-notification__text"> ' + JSON.parse(msgError) + ' </p>' +
          '</div>' +
        '</div>';
        $(".woocommerce-notices-wrapper").after(msgBox)
        localStorage.removeItem('rental_add_to_cart_error_msg')
      }

    }

    var $minicartTotal = $('.woocommerce-mini-cart__total');
    if ($minicartTotal.length && !rentalCartPricesHidden()) {
        // Eventorian theme
        // mini cart subtotal value update
        $.ajax({
          type: 'POST',
          url: $min_date_form.attr('action'),
          data: {
            'action' : 'rental_replace_mini_cart_textual_labels_values'
          },
          success: function() {
          },
          error: function() {
          },
          complete: function(data) {

            var data_json = data.responseJSON;
            $minicartTotal.find('.woocommerce-Price-amount bdi').html('<span class="woocommerce-Price-currencySymbol">' + data_json.currency_symbol + '</span>' + data_json.subtotal)
            
          }
        });
    }

  }


});