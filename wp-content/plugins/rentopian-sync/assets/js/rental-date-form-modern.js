(function ($) {
    'use strict';

    /**
     * Check if debug mode is enabled via the admin toggle.
     * Reads from the global config set by the checkout layout renderer.
     */
    function isDebugEnabled() {
        if (typeof rentopianCheckoutLayoutConfig !== 'undefined' && rentopianCheckoutLayoutConfig.debug) return true;
        if (typeof rentopianCheckoutConfig !== 'undefined' && rentopianCheckoutConfig.debug) return true;
        return false;
    }

    function initModernCheckoutDates() {
        var $holder = $('#rental_date_form_modern');
        if (!$holder.length) {
            return;
        }

        // Debug: Log PHP configuration from data attributes
        if (isDebugEnabled()) {
            console.log('[RentopianDates] PHP Config:', {
                hideEnd: $holder.data('debug-hide-end'),
                multiDayEnabled: $holder.data('debug-multi-day-enabled'),
                multiDayChecked: $holder.data('debug-multi-day-checked'),
                endDisplay: $holder.data('debug-end-display')
            });
        }

        var $startDate = $('#rental_start_date'),
            $endDate   = $('#rental_end_date'),
            $zip       = $('#rental_zip'),
            $multiDayCheckbox = $('#rental_multi_day_event'),
            startCalendar,
            endCalendar;

        if (!$startDate.length) {
            return;
        }

        // prevent double init
        if ($startDate.data('calentim-initialized')) {
            return;
        }
        $startDate.data('calentim-initialized', true);

        //------------------------------------------------------------------
        // Multi Day Event Toggle Handler with Cookie Persistence
        //------------------------------------------------------------------
        
        /**
         * Cookie helper functions for multi-day preference
         */
        function setMultiDayCookie(isChecked) {
            var value = isChecked ? '1' : '0';
            var expires = new Date();
            expires.setTime(expires.getTime() + (365 * 24 * 60 * 60 * 1000)); // 1 year
            document.cookie = 'rental_multi_day_preference=' + value + ';expires=' + expires.toUTCString() + ';path=/;SameSite=Lax';
        }
        
        /**
         * Handles the show/hide logic for the end date field
         */
        function toggleEndDateVisibility(isChecked, skipValidation, saveCookie) {
            var $endDateField = $('#rental_end_date_field');
            var $endDateInput = $('#rental_end_date');
            
            if (!$endDateField.length) {
                if (isDebugEnabled()) console.log('[RentopianDates] toggleEndDateVisibility: End date field NOT found');
                return;
            }

            // Save preference to cookie
            if (saveCookie !== false) {
                setMultiDayCookie(isChecked);
                if (isDebugEnabled()) console.log('[RentopianDates] Cookie set to:', isChecked ? '1' : '0');
            }

            if (isChecked) {
                if (isDebugEnabled()) console.log('[RentopianDates] Sliding down end date field');
                // Remove any inline style first, then animate
                $endDateField.removeAttr('style');
                $endDateField.slideDown(300);
                $endDateInput.attr('required', 'required');
            } else {
                if (isDebugEnabled()) console.log('[RentopianDates] Sliding up end date field');
                $endDateField.slideUp(300, function() {
                    // After animation completes, ensure it's hidden with !important
                    $(this).attr('style', 'display: none !important');
                });
                $endDateInput.removeAttr('required');
                
                if (!skipValidation) {
                    hideFieldError($endDateInput);
                }
            }
        }

        /**
         * Initialize multi-day checkbox
         * 
         * IMPORTANT: This handles the show/hide logic for the end date field.
         * The PHP already sets the initial display state via inline style,
         * but we need to ensure consistency after JS runs.
         * 
         * Logic:
         * - If multi-day checkbox exists AND is checked → show end date
         * - If multi-day checkbox exists AND is unchecked → hide end date
         * - If multi-day checkbox does NOT exist → do nothing (PHP already handles visibility)
         */
        if ($multiDayCheckbox.length) {
            var initialChecked = $multiDayCheckbox.is(':checked');
            var $endDateField = $('#rental_end_date_field');
            var $endDateInput = $('#rental_end_date');
            
            // Debug logging
            if (isDebugEnabled()) {
                console.log('[RentopianDates] Multi-day checkbox found, checked:', initialChecked);
                console.log('[RentopianDates] End date field found:', $endDateField.length > 0);
                console.log('[RentopianDates] End date current display:', $endDateField.css('display'));
            }
            
            // Only manipulate visibility if end date field exists
            if ($endDateField.length) {
                if (initialChecked) {
                    // Ensure end date is visible and required
                    $endDateField.show().css('display', '').removeAttr('style');
                    $endDateInput.attr('required', 'required');
                    if (isDebugEnabled()) console.log('[RentopianDates] Showing end date (checkbox is checked)');
                } else {
                    // Ensure end date is hidden and not required - use multiple methods to ensure it works
                    $endDateField.hide();
                    $endDateField.css('display', 'none');
                    $endDateField.attr('style', 'display: none !important');
                    $endDateInput.removeAttr('required');
                    if (isDebugEnabled()) {
                        console.log('[RentopianDates] Hiding end date (checkbox is unchecked)');
                        console.log('[RentopianDates] After hide - display is now:', $endDateField.css('display'));
                        console.log('[RentopianDates] After hide - style attr:', $endDateField.attr('style'));
                    }
                }
            }

            // Bind change handler (use off first to prevent duplicate handlers)
            $multiDayCheckbox.off('change.multiDayToggle').on('change.multiDayToggle', function () {
                var isChecked = $(this).is(':checked');
                if (isDebugEnabled()) console.log('[RentopianDates] Checkbox changed, now:', isChecked);
                toggleEndDateVisibility(isChecked, false, true);
                
                // When multi-day is toggled ON, sync end calendar minDate with current start date
                // The onafterselect callback only fires when user actively selects a new start date,
                // so we need to manually sync here when toggle happens with existing start date.
                if (isChecked && endCalendar && startCalendar && startCalendar.config.startDate) {
                    var currentStartDate = startCalendar.config.startDate.clone();
                    if (minRange > 0) {
                        currentStartDate.add(minRange, 'd');
                    }
                    endCalendar.setMinDate(currentStartDate);
                    if (isDebugEnabled()) console.log('[RentopianDates] Synced end calendar minDate to:', currentStartDate.format('YYYY-MM-DD HH:mm'));
                    
                    // Also set maxDate if configured
                    if (maxRange > 0) {
                        endCalendar.setMaxDate(startCalendar.config.startDate.clone().add(maxRange, 'd'));
                    }

                    // Clear a stale end date that now falls before the start (min date)
                    // or on a disabled day, so the customer is never stuck with an
                    // inverted range — they simply re-pick a valid end date.
                    var endSelected = endCalendar.config.startDate;
                    if (endSelected) {
                        var endNowInvalid = endSelected.isBefore(currentStartDate) ||
                            (typeof endCalendar.config.disableDays === 'function' &&
                             endCalendar.config.disableDays(endSelected));
                        if (endNowInvalid) {
                            endCalendar.clearInput();
                        }
                    }
                }

                // Preserve current date values so they can be restored after update_checkout
                // (form refresh can reset delivery/pickup selects and overwrite dates with server defaults).
                var $startInput = $('#rental_start_date');
                var $endInput = $('#rental_end_date');
                window.rentopianCheckoutDates = window.rentopianCheckoutDates || {};
                window.rentopianCheckoutDates.restoreAfterUpdate = true;
                window.rentopianCheckoutDates.startDate = ($startInput.length && $startInput.val()) ? $startInput.val() : '';
                window.rentopianCheckoutDates.endDate = ($endInput.length && $endInput.val()) ? $endInput.val() : '';
                if (!isChecked) {
                    window.rentopianCheckoutDates.endDate = '';
                }

                var $checkoutForm = $('form.checkout');
                if ($checkoutForm.length && $startDate.val()) {
                    setTimeout(function() {
                        triggerCheckoutUpdate();
                    }, 350);
                }
            });
        } else {
            // Debug: checkbox not found
            if (isDebugEnabled()) console.log('[RentopianDates] Multi-day checkbox NOT found - end date visibility controlled by PHP');
        }
        // If multi-day checkbox doesn't exist, the end date visibility is controlled
        // entirely by PHP (rental_hide_end_date option)

        //------------------------------------------------------------------
        // HELPER: Ensure value is a moment object
        //------------------------------------------------------------------
        function ensureMoment(value, format) {
            if (!value) {
                return null;
            }
            // Already a moment object
            if (moment.isMoment(value)) {
                return value;
            }
            // Parse string to moment
            if (typeof value === 'string') {
                var parsed = format ? moment(value, format) : moment(value);
                return parsed.isValid() ? parsed : null;
            }
            return null;
        }

        /**
         * Safely clone a moment object, or return a fallback
         */
        function safeClone(momentObj, fallback) {
            if (momentObj && moment.isMoment(momentObj)) {
                return momentObj.clone();
            }
            if (fallback && moment.isMoment(fallback)) {
                return fallback.clone();
            }
            return moment();
        }

        //------------------------------------------------------------------
        // CALENTIM CONFIGURATION
        //------------------------------------------------------------------

        var startDateDefaultRaw = $startDate.data('start-date-default'),
            endDateDefaultRaw   = $startDate.data('end-date-default'),
            endDateValue        = $startDate.data('end-date'),
            enableEnd           = !$startDate.data('hide-end'),
            enableTime          = !$startDate.data('hide-time'),
            minStartAllowedDays = parseInt($startDate.data('min-start'), 10) || 0,
            disabledWeekDays    = $startDate.data('disabled-week-days'),
            serverDateFormat    = 'YYYY/MM/DD' + (enableTime ? ' h:mm A' : ''),
            displayFormat       = 'MMM D' + (enableTime ? ', h:mm A' : '');

        // Parse date defaults to moment objects
        var startDateDefault = ensureMoment(startDateDefaultRaw, 'MMM D, h:mm A');
        var endDateDefault = ensureMoment(endDateDefaultRaw, 'MMM D, h:mm A');

        // Absolute dates resolved server-side, in the store timezone. The floor
        // and the preselected day are separate values: the offset is already
        // inside both, so nothing here may add it again.
        function parseAbsolute(value) {
            return ensureMoment(value, 'YYYY/MM/DD h:mm A') || ensureMoment(value, 'YYYY/MM/DD');
        }

        // Earliest day that may be selected.
        var floorMoment = parseAbsolute($startDate.data('min-start-date'));
        if (!floorMoment) {
            floorMoment = minStartAllowedDays > 0
                ? moment().startOf('day').add(minStartAllowedDays, 'd')
                : moment().startOf('hour').add(1, 'h');
        }

        function isBeforeFloor(candidate) {
            return !!candidate && candidate.clone().startOf('day').isBefore(floorMoment.clone().startOf('day'));
        }

        // Day the calendar opens on when nothing is stored yet.
        var defaultStart = parseAbsolute($startDate.data('default-start')) ||
            (startDateDefault ? startDateDefault.clone() : null);
        if (isBeforeFloor(defaultStart)) {
            defaultStart = floorMoment.clone();
        }

        var defaultEnd = parseAbsolute($startDate.data('default-end')) ||
            (endDateDefault ? endDateDefault.clone() : null);

        var nowMoment = defaultStart ? defaultStart.clone() : floorMoment.clone();

        // Calculate endNow - always ensure it's a moment object
        var endNow = defaultEnd ? defaultEnd.clone() : nowMoment.clone();

        // Disabled weekdays check
        var hasDisabledWeekDays = disabledWeekDays && typeof disabledWeekDays === 'object' && Object.keys(disabledWeekDays).length > 0;
        
        function checkDisabledWeekDay(date) {
            if (!hasDisabledWeekDays) {
                return false;
            }
            return disabledWeekDays.hasOwnProperty(date.day());
        }

        // Base config for START calendar
        var startConfig = {
            format: displayFormat,
            showTimePickers: enableTime,
            singleDate: true,
            showOn: 'bottom',
            autoAlign: false,
            showFooter: false,
            startEmpty: true,
            showButtons: true,
            minuteSteps: 15,
            minDate: floorMoment.clone()
        };

        // Only add disableDays if we have disabled weekdays
        if (hasDisabledWeekDays) {
            startConfig.disableDays = function(date) {
                return checkDisabledWeekDay(date);
            };
        }

        // PRIORITY: Check current input value FIRST (user's current selection)
        // Only use data-value if input is empty or doesn't match a valid date
        var currentInputVal = $startDate.val();
        var savedStartValue = null;
        var useInputValue = false;
        
        // If input has a value, try to parse it and use it if valid
        if (currentInputVal) {
            var parsedInput = ensureMoment(currentInputVal, displayFormat);
            if (parsedInput && parsedInput.isValid()) {
                // Input has a valid date - use it instead of data-value
                // This preserves user's selection even if data-value is stale
                useInputValue = true;
                savedStartValue = parsedInput.format(serverDateFormat);
            }
        }
        
        // Only use data-value if input is empty or invalid
        if (!useInputValue) {
            // Also check the actual HTML attribute, not just jQuery data, as data-value might be stale
            savedStartValue = $startDate.data('value');
            var htmlDataValue = $startDate.attr('data-value');
            // Use HTML attribute if it exists and is different (more up-to-date)
            if (htmlDataValue && htmlDataValue !== savedStartValue) {
                savedStartValue = htmlDataValue;
            }
        }
        
        // A stored start inside the blocked window is dropped rather than
        // preselected — the floor is never lowered to meet it.
        var savedStartRejected = false;
        if (savedStartValue && isBeforeFloor(ensureMoment(savedStartValue, serverDateFormat))) {
            savedStartValue = null;
            savedStartRejected = true;
        }

        if (savedStartValue) {
            var parsedSavedStart = ensureMoment(savedStartValue, serverDateFormat);
            if (parsedSavedStart) {
                startConfig.startEmpty = false;
                startConfig.startDate = parsedSavedStart;
                // Anchor the end calendar's min/max on the restored start
                nowMoment = parsedSavedStart.clone().startOf('day');
            }
        }

        // Set start date default ONLY if no saved value exists
        if (!savedStartValue && defaultStart) {
            startConfig.startDate = defaultStart.clone();
            startConfig.startEmpty = false;
        }

        var minRange = 0,
            maxRange = 0,
            dateStep = 0;

        if ($endDate.length) {
            minRange = parseInt($endDate.data('min-rage'), 10) || 0;
            maxRange = parseInt($endDate.data('max-rage'), 10) || 0;
            dateStep = parseInt($endDate.data('date-step'), 10) || 0;

            if ($endDate.data('range')) {
                // Range mode: <select> is built based on start
                var rangeStart = minRange > dateStep ? Math.ceil(minRange / dateStep) * dateStep : dateStep;

                startConfig.onafterselect = function (calentim, start) {
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
                        if (!checkDisabledWeekDay(end)) {
                            var endFormatted = end.format(serverDateFormat);
                            options += '<option value="' + endFormatted + '" ' +
                                (value === endFormatted ? 'selected' : '') + '>' +
                                days + ' days</option>';
                            i++;
                        }
                        days += dateStep;
                    }

                    $endDate.html(options);
                };
            } else {
                // Non-range: enforce min/max range on end calendar
                startConfig.onafterselect = function (calentim, startDateVal) {
                    if (!endCalendar) return;
                    
                    var minDate = startDateVal.clone();
                    if (minRange > 0) {
                        minDate.add(minRange, 'd');
                    }
                    endCalendar.setMinDate(minDate);

                    if (maxRange > 0) {
                        endCalendar.setMaxDate(startDateVal.clone().add(maxRange, 'd'));
                    }

                    // Clear a stale end date that now falls before the start (min date)
                    // or on a disabled day, so the customer is never stuck with an
                    // inverted range — they simply re-pick a valid end date.
                    var endSelected = endCalendar.config.startDate;
                    if (endSelected) {
                        var endNowInvalid = endSelected.isBefore(minDate) ||
                            (typeof endCalendar.config.disableDays === 'function' &&
                             endCalendar.config.disableDays(endSelected));
                        if (endNowInvalid) {
                            endCalendar.clearInput();
                        }
                    }
                };
            }
        }

        // Init START calendar
        $startDate.calentim(startConfig);
        startCalendar = $startDate.data('calentim');
        
        // EVENT DATE OFFSET: Show rental period when user selects a date
        if (typeof RntpDateOffsets !== 'undefined' && RntpDateOffsets.isActive()) {
          (function() {
            var $periodDisplay = $('.rntp-rental-period-details');
            var _origAfterSelect = startCalendar.config.onafterselect;
            startCalendar.config.onafterselect = function(calentim, startDateVal) {
              if (typeof _origAfterSelect === 'function') {
                _origAfterSelect(calentim, startDateVal);
              }
              var result = RntpDateOffsets.computeDisplay(startDateVal || startCalendar.config.startDate);
              RntpDateOffsets.updateDisplay($periodDisplay, result);
            };
            if (startCalendar.config.startDate) {
              var initResult = RntpDateOffsets.computeDisplay(startCalendar.config.startDate);
              RntpDateOffsets.updateDisplay($periodDisplay, initResult);
            }
          })();
        }
       
        if (startCalendar && savedStartValue) {
            var currentInputVal = $startDate.val();
            var parsedSavedStart = ensureMoment(savedStartValue, serverDateFormat);
            if (parsedSavedStart) {
                var savedFormatted = parsedSavedStart.format(displayFormat);
                // Only restore if input is empty or doesn't match the saved value
                // This means the HTML was replaced with wrong values (e.g., after updated_checkout)
                if (!currentInputVal || currentInputVal !== savedFormatted) {
                    startCalendar.setStart(parsedSavedStart);
                    startCalendar.updateInput(true);
                }
            }
        }

        // Init END calendar (non-range)
        if ($endDate.length && !$endDate.data('range')) {
            
            // Separate config object for end calendar
            var endConfig = {
                format: displayFormat,
                showTimePickers: enableTime,
                singleDate: true,
                showOn: 'bottom',
                autoAlign: false,
                showFooter: false,
                startEmpty: true,
                showButtons: true,
                minuteSteps: 15,
                minDate: endNow.clone()
            };

            // Build end calendar disableDays function only if needed
            if (dateStep || hasDisabledWeekDays) {
                endConfig.disableDays = function (date) {
                    // Check disabled weekdays first
                    if (checkDisabledWeekDay(date)) {
                        return true;
                    }
                    // Check date step - only if start date is selected and dateStep is set
                    if (dateStep && startCalendar && startCalendar.config.startDate) {
                        var startMoment = startCalendar.config.startDate.clone().startOf('day');
                        var daysDiff = date.clone().startOf('day').diff(startMoment, 'days');
                        return daysDiff % dateStep !== 0;
                    }
                    return false;
                };
            }

            // PRIORITY: Check current input value FIRST (user's current selection)
            // Only use data-value if input is empty or doesn't match a valid date
            var currentEndInputVal = $endDate.val();
            var savedEndValue = null;
            var useEndInputValue = false;
            
            // If input has a value, try to parse it and use it if valid
            if (currentEndInputVal) {
                var parsedEndInput = ensureMoment(currentEndInputVal, displayFormat);
                if (parsedEndInput && parsedEndInput.isValid()) {
                    // Input has a valid date - use it instead of data-value
                    // This preserves user's selection even if data-value is stale
                    useEndInputValue = true;
                    savedEndValue = parsedEndInput.format(serverDateFormat);
                }
            }
            
            // Only use data-value if input is empty or invalid
            if (!useEndInputValue) {
                // Also check the actual HTML attribute, not just jQuery data, as data-value might be stale
                savedEndValue = $endDate.data('value');
                var htmlEndDataValue = $endDate.attr('data-value');
                // Use HTML attribute if it exists and is different (more up-to-date)
                if (htmlEndDataValue && htmlEndDataValue !== savedEndValue) {
                    savedEndValue = htmlEndDataValue;
                }
            }

            // The stored end belongs to a start that is no longer bookable.
            if (savedStartRejected) {
                savedEndValue = null;
            }

            if (savedEndValue) {
                var parsedSavedEnd = ensureMoment(savedEndValue, serverDateFormat);
                if (parsedSavedEnd) {
                    endConfig.startEmpty = false;
                    endConfig.startDate = parsedSavedEnd;
                    // Update endNow to use saved date for minDate calculations
                    endNow = parsedSavedEnd.clone();
                }
            } else if (endDateValue === '' && endDateDefault && moment.isMoment(endDateDefault) && enableEnd) {
                // Only use default if no saved value exists
                endConfig.startDate = endNow.clone();
                endConfig.startEmpty = false;
            }

            // FIX: If start calendar already has a date, use it for minDate instead of endNow
            // This ensures proper minDate when calendars are re-initialized (e.g., after update_checkout)
            if (startCalendar && startCalendar.config.startDate) {
                var startBasedMinDate = startCalendar.config.startDate.clone();
                if (minRange > 0) {
                    startBasedMinDate.add(minRange, 'd');
                }
                endConfig.minDate = startBasedMinDate;
                if (isDebugEnabled()) console.log('[RentopianDates] End calendar minDate set from start date:', startBasedMinDate.format('YYYY-MM-DD HH:mm'));
            }
            
            $endDate.calentim(endConfig);
            endCalendar = $endDate.data('calentim');
            
          
            if (endCalendar && savedEndValue) {
                var currentEndInputVal = $endDate.val();
                var parsedSavedEnd = ensureMoment(savedEndValue, serverDateFormat);
                if (parsedSavedEnd) {
                    var savedEndFormatted = parsedSavedEnd.format(displayFormat);
                    // Only restore if input is empty or doesn't match the saved value
                    // This means the HTML was replaced with wrong values (e.g., after updated_checkout)
                    if (!currentEndInputVal || currentEndInputVal !== savedEndFormatted) {
                        // End calendar uses singleDate: true, so setStart() sets the date
                        endCalendar.setStart(parsedSavedEnd);
                        endCalendar.updateInput(true);
                    }
                }
            }
        } else if ($endDate.length && $endDate.data('range')) {
            // Range mode - auto-click apply
            setTimeout(function () {
                $('button.calentim-apply').trigger('click');
            }, 1000);
        }

        // Update start input if it has a date
        // Only update if the input value doesn't match the calendar's date
        if (startCalendar && startCalendar.config.startDate && !startCalendar.config.startEmpty) {
            var currentInputValue = $startDate.val();
            var calendarFormatted = startCalendar.config.startDate.format(displayFormat);
            // Only update if values don't match to avoid overwriting saved values
            if (!currentInputValue || currentInputValue !== calendarFormatted) {
                startCalendar.updateInput(true);
            }
        }
        
        // Update end input if it has a date
        // Only update if the input value doesn't match the calendar's date
        if (endCalendar && endCalendar.config.startDate && !endCalendar.config.startEmpty) {
            var currentEndInputValue = $endDate.val();
            var endCalendarFormatted = endCalendar.config.startDate.format(displayFormat);
            // Only update if values don't match to avoid overwriting saved values
            if (!currentEndInputValue || currentEndInputValue !== endCalendarFormatted) {
                endCalendar.updateInput(true);
            }
        }

        //------------------------------------------------------------------
        // RECONCILE VISIBLE INPUTS WITH CALENDAR SELECTION
        //
        // The dates summary is built from each calendar's internal selection
        // (config.startDate), so the visible input must mirror it. A checkout
        // refresh can leave an input showing a stale/default value while the
        // calendar still holds the real selection. updateInput() is a no-op
        // while the Apply buttons are shown (delayInputUpdate), so force the
        // value through setStart(), with a direct write as the last resort.
        //------------------------------------------------------------------
        function reconcileInputToCalendar($input, calendar) {
            if (!$input || !$input.length || !calendar) {
                return;
            }
            var selected = calendar.config.startDate;
            if (!selected || calendar.config.startEmpty) {
                return;
            }
            var desired = selected.format(displayFormat);
            if ($input.val() === desired) {
                return;
            }
            // setStart() re-enables the input write even when Apply buttons defer it.
            calendar.setStart(selected.clone());
            // If setStart was constrained (min/disabled day), still show the value.
            if ($input.val() !== desired) {
                $input.val(desired);
            }
            if (isDebugEnabled()) console.log('[RentopianDates] Reconciled input to calendar:', desired);
        }

        reconcileInputToCalendar($startDate, startCalendar);

        // Only mirror the end input when it is an active date field
        // (multi-day enabled / not a range <select> / not hidden).
        if ($endDate.length && !$endDate.data('range')) {
            var endIsActive = !$multiDayCheckbox.length || $multiDayCheckbox.is(':checked');
            if (endIsActive) {
                reconcileInputToCalendar($endDate, endCalendar);
            }
        }

        //------------------------------------------------------------------
        // HELPERS
        //------------------------------------------------------------------

        function showFieldError($input) {
            if (!$input || !$input.length) return;
            $input.addClass('error-input');
            var $row = $input.closest('.form-row');
            $row.find('.form-error').show();
        }

        function hideFieldError($input) {
            if (!$input || !$input.length) return;
            $input.removeClass('error-input');
            var $row = $input.closest('.form-row');
            $row.find('.form-error').hide();
        }

        //------------------------------------------------------------------
        // CHECKOUT AJAX
        //------------------------------------------------------------------

        function set_form_data_checkout(startDate, endDate, zip, address, pid, url, afterSuccess, eventDate) {
            
            var dataObj = {
                rental_form_source: 'checkout_modern',
                action: 'rental_date_form_api',
                start_date: startDate,
                end_date: endDate,
                zip: zip,
                address: address
            };

            if (eventDate) {
                dataObj.event_date = eventDate;
            }

            if (pid !== 0) {
                dataObj.pid = pid;
            }
            if (url !== '') {
                dataObj.url = url;
            }

            var $formNode = $("#rental_date_form_modern");

            if (typeof block === 'function') {
                block($formNode);
            }

            return $.ajax({
                type: 'POST',
                url: rentalObj.url,
                dataType: 'JSON',
                data: dataObj,
                success: function (data) {
                    $formNode.addClass('rntp-form-filled');
                    if (window.rntpDateBlocker) {
                        window.rntpDateBlocker.hide();
                    }

                    var onCheckout = $('body').hasClass('woocommerce-checkout');
                    if (onCheckout && typeof afterSuccess === 'function') {
                        afterSuccess(data);
                    }
                },
                error: function (response) {
                    var statusCode = response.status;
                    var data       = response.responseJSON || {};

                    console.log('error:', data);

                    var msgText = null;
                    if (data.type === 'error') {
                        msgText = data.message_txt;
                    } else if (statusCode === 500 && data.message) {
                        msgText = data.message;
                    }

                    if (msgText && typeof messageTemplate === 'function') {
                        var $wrapper = $('.woocommerce-notices-wrapper').first();
                        $wrapper.find('.rntp-notification').remove();
                        var msgBox = messageTemplate(msgText, 'error');
                        $wrapper.after(msgBox);

                        $formNode.removeClass('rntp-form-filled');
                        if (window.rntpDateBlocker) {
                            window.rntpDateBlocker.show();
                        }
                    }
                },
                complete: function () {
                    if (typeof unblock === 'function') {
                        unblock($formNode);
                    }
                }
            });
        }

        //------------------------------------------------------------------
        // VALIDATION
        //------------------------------------------------------------------

        function triggerCheckoutUpdate() {
            // Signal that checkout update is starting
            $(document).trigger('checkout_update_start');
            
            var valid = true;

            // START date
            var start = startCalendar ? startCalendar.config.startDate : null;
            if (!start) {
                valid = false;
                showFieldError($startDate);
            } else {
                hideFieldError($startDate);
            }

            // END date - only validate if multi-day is checked
            var end      = null;
            var endDate  = '';
            var isMultiDay = $multiDayCheckbox.length ? $multiDayCheckbox.is(':checked') : true;
            
            if ($endDate.length && isMultiDay) {
                if ($endDate.data('range')) {
                    end = $endDate.val();
                } else if (endCalendar) {
                    end = endCalendar.config.startDate;
                }

                if (!end) {
                    valid = false;
                    showFieldError($endDate);
                } else {
                    hideFieldError($endDate);
                }
            } else if ($endDate.length && !isMultiDay) {
                hideFieldError($endDate);
            }

            // ZIP
            if ($zip.length) {
                if (!$zip.val()) {
                    valid = false;
                    showFieldError($zip);
                } else {
                    hideFieldError($zip);
                }
            }

            // Address
            var address  = '';
            var $address = $('#rental_address');
            if ($address.length) {
                address = ($address.val() || '').trim();
                if (address === '') {
                    valid = false;
                    showFieldError($address);
                } else {
                    hideFieldError($address);
                }
            }

            if (!valid) {
                return;
            }

            // Format dates
            var startDate = start.format(serverDateFormat);
            var eventDateForServer = '';
            if (typeof RntpDateOffsets !== 'undefined' && RntpDateOffsets.isActive && RntpDateOffsets.isActive()) {
                eventDateForServer = start.format(serverDateFormat);
            }

            if ($endDate.length && isMultiDay) {
                if ($endDate.data('range')) {
                    endDate = $endDate.val();
                } else if (endCalendar && endCalendar.config.startDate) {
                    endDate = endCalendar.config.startDate.format(serverDateFormat);
                }
            }

            // Product context
            var pid          = 0;
            var url          = '';
            var $product_div = $('div.product');
            if ($product_div.length) {
                var $addToCartBtn = $('.single_add_to_cart_button');
                if ($addToCartBtn.length <= 0) {
                    var $rntp_form_holder = $('#rntp-form-holder');
                    if ($rntp_form_holder.length) {
                        pid = $rntp_form_holder.data('pid') || 0;
                        url = $rntp_form_holder.data('url') || '';
                    }
                }
            }

            // EVENT DATE OFFSET: Apply offsets before sending to server
            if (typeof RntpDateOffsets !== 'undefined' && RntpDateOffsets.isActive()) {
              var _offsetResult = RntpDateOffsets.apply(start);
              if (_offsetResult) {
                startDate = _offsetResult.startDate.format(serverDateFormat);
                endDate   = _offsetResult.endDate.format(serverDateFormat);
                RntpDateOffsets.updateDisplay($('.rntp-rental-period-details'), _offsetResult);
              }
            }

            // In offset mode the ZIP field is hidden ($zip is empty); send '' rather than undefined.
            set_form_data_checkout(startDate, endDate, $zip.length ? $zip.val() : '', address, pid, url, function () {
                $('body').trigger('update_checkout');
                // Signal that checkout update has completed
                // Use a small delay to ensure update_checkout has processed
                setTimeout(function() {
                    $(document).trigger('checkout_update_complete');
                }, 300);
            }, eventDateForServer);
        }

        //------------------------------------------------------------------
        // EVENT BINDINGS
        //------------------------------------------------------------------

        $(document).off('click.rntpCheckoutApply', '.calentim-apply');
        $(document).on('click.rntpCheckoutApply', '.calentim-apply', function () {
            if (!$('#rental_date_form_modern').length) {
                return;
            }
            
            // Reset delivery and pickup time selections when dates are changed
            // These time selections are only for editing times, not full date/time changes
            var $deliveryTimeSelect = $('select[name="delivery_time_selections_id"]');
            var $pickupTimeSelect = $('select[name="pickup_time_selections_id"]');
            var $deliveryTimeField = $('#delivery_time_selections_id_field');
            
            // Reset delivery time select (set value without triggering change to avoid AJAX/reload)
            if ($deliveryTimeSelect.length && $deliveryTimeSelect.val() !== '0') {
                // Set value directly without triggering change event
                $deliveryTimeSelect[0].value = '0';
                $deliveryTimeSelect.removeClass('error-input');
                // Clear cookie if setCookie function exists
                if (typeof setCookie === 'function') {
                    setCookie('delivery_time_selections_id', '0');
                }
            }
            
            // Reset pickup time select (set value without triggering change to avoid AJAX/reload)
            if ($pickupTimeSelect.length && $pickupTimeSelect.val() !== '0') {
                // Set value directly without triggering change event
                $pickupTimeSelect[0].value = '0';
                $pickupTimeSelect.removeClass('error-input');
                // Clear cookie if setCookie function exists
                if (typeof setCookie === 'function') {
                    setCookie('pickup_time_selections_id', '0');
                }
            }
            
            // Also reset the field wrapper if it exists
            if ($deliveryTimeField.length) {
                $deliveryTimeField.find('.error-input').removeClass('error-input');
            }
            
            setTimeout(triggerCheckoutUpdate, 10);
        });

        if ($zip.length) {
            $zip.off('.rntpCheckoutZip');
            $zip.on('change.rntpCheckoutZip blur.rntpCheckoutZip', function () {
                triggerCheckoutUpdate();
            });
        }
    }

    $(document).ready(function () {
        initModernCheckoutDates();
    });
    
    // Re-initialize calendar after WooCommerce checkout update
    // This ensures dates are preserved when checkout form is refreshed via AJAX
    $(document.body).on('updated_checkout', function() {
        // Check if the form still exists (it should after update_checkout)
        var $form = $('#rental_date_form_modern');
        if ($form.length) {
            // Clear initialization flags to allow re-initialization
            $form.find('#rental_start_date, #rental_end_date').removeData('calentim-initialized');
            // Re-initialize to restore saved dates from cookies
            // Use a small delay to ensure DOM is ready
            setTimeout(function() {
                initModernCheckoutDates();
            }, 150);
        }
    });

})(jQuery);