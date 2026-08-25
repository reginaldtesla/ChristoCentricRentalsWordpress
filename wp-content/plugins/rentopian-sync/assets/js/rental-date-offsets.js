/**
 * RntpDateOffsets — Shared utility for event date offset display and computation.
 *
 * When offset mode is active (start_date_offset > 0 or end_date_offset > 0,
 * with hide_end_date and hide_zip both enabled), the user selects an "event date"
 * and the actual rental period is computed as:
 *   start = event_date - start_offset days (at default_start_time)
 *   end   = event_date + end_offset days   (at default_end_time)
 *
 * This mirrors the core Rentopian admin logic.
 *
 * Dependencies: jQuery, moment.js
 * Localized data: window.rentalDateOffsets (from wp_localize_script)
 */
(function($) {
    'use strict';

    /**
     * Read settings at call time — wp_localize_script prints rentalDateOffsets
     * immediately before rental-script / rental-min-date-form-script, which load
     * after this file. A one-time snapshot at parse time would always be {}.
     */
    function getSettings() {
        return window.rentalDateOffsets || {};
    }

    window.RntpDateOffsets = {

        /**
         * Check if offset mode is active.
         * @returns {boolean}
         */
        isActive: function() {
            var settings = getSettings();
            var startOff = parseInt(settings.startDateOffset || 0, 10);
            var endOff   = parseInt(settings.endDateOffset || 0, 10);
            var hideEnd  = parseInt(settings.hideEndDate || 0, 10);
            var hideZip  = parseInt(settings.hideZip || 0, 10);
            return (startOff > 0 || endOff > 0) && hideEnd === 1 && hideZip === 1;
        },

        /**
         * Compute the offset-adjusted rental start/end from an event date.
         *
         * @param {moment|string|Date} eventDate - The user-selected event date.
         * @returns {Object} { startDate: moment, endDate: moment, eventDate: moment, displayText: string }
         */
        computeDisplay: function(eventDate) {
            var settings = getSettings();
            var m = moment.isMoment(eventDate) ? eventDate.clone() : moment(eventDate);
            if (!m.isValid()) {
                return { startDate: null, endDate: null, eventDate: null, displayText: '' };
            }

            var startOff = parseInt(settings.startDateOffset || 0, 10);
            var endOff   = parseInt(settings.endDateOffset || 0, 10);
            var startTime = settings.defaultStartTime || '9:00 AM';
            var endTime   = settings.defaultEndTime || '5:00 PM';

            // Compute start: event_date minus start_offset days
            var startDate = m.clone().startOf('day').subtract(startOff, 'days');
            // Compute end: event_date plus end_offset days
            var endDate = m.clone().startOf('day').add(endOff, 'days');

            // Apply default times
            var startTimeParsed = moment(startTime, ['h:mm A', 'hh:mm A', 'H:mm']);
            var endTimeParsed   = moment(endTime, ['h:mm A', 'hh:mm A', 'H:mm']);

            if (startTimeParsed.isValid()) {
                startDate.hour(startTimeParsed.hour()).minute(startTimeParsed.minute());
            }
            if (endTimeParsed.isValid()) {
                endDate.hour(endTimeParsed.hour()).minute(endTimeParsed.minute());
            }

            // Match PHP date_i18n: "Saturday, April 18th, 2026 8:00 AM - Sunday, April 26th, 2026 8:00 PM"
            var fmt = 'dddd, MMMM Do, YYYY h:mm A';
            var displayText = startDate.format(fmt) + ' - ' + endDate.format(fmt);

            return {
                startDate: startDate,
                endDate: endDate,
                eventDate: m.clone().startOf('day'),
                displayText: displayText
            };
        },

        /**
         * Apply offsets and return server-formatted dates for AJAX submission.
         *
         * @param {moment|string|Date} eventDate - The user-selected event date.
         * @returns {Object|null} { startDate: moment, endDate: moment, displayText: string } or null
         */
        apply: function(eventDate) {
            if (!this.isActive()) return null;
            return this.computeDisplay(eventDate);
        },

        /**
         * Update the .rntp-rental-period-details display element.
         *
         * @param {jQuery} $el - The blockquote/div element containing .rntp-rental-period-text
         * @param {Object} result - Output from computeDisplay() or apply()
         */
        updateDisplay: function($el, result) {
            if (!$el || !$el.length) return;
            if (!result || !result.displayText) {
                $el.hide();
                return;
            }
            $el.find('.rntp-rental-period-text').text(result.displayText);
            $el.show();
        }
    };

})(jQuery);
