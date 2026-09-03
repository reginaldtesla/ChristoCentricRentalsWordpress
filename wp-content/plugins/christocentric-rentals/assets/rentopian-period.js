(function ($) {
    'use strict';

    function pad(n) {
        return String(n).padStart(2, '0');
    }

    function parseTimeToParts(value) {
        var raw = String(value || '').trim();
        if (!raw) {
            return { h: 9, m: 0 };
        }
        var m = raw.match(/^(\d{1,2}):(\d{2})/);
        if (!m) {
            return { h: 9, m: 0 };
        }
        return { h: Math.min(23, parseInt(m[1], 10)), m: Math.min(59, parseInt(m[2], 10)) };
    }

    function formatRentopianDateTime(dateStr, timeStr) {
        if (!dateStr) {
            return '';
        }
        var parts = dateStr.split('-');
        if (parts.length !== 3) {
            return '';
        }
        var y = parseInt(parts[0], 10);
        var mo = parseInt(parts[1], 10);
        var d = parseInt(parts[2], 10);
        var t = parseTimeToParts(timeStr);
        var dt = new Date(y, mo - 1, d, t.h, t.m, 0);
        if (isNaN(dt.getTime())) {
            return '';
        }
        // Rentopian server format (see rental-script.js serverDateFormat): YYYY/MM/DD h:mm A
        var hour24 = dt.getHours();
        var minute = dt.getMinutes();
        var ampm = hour24 >= 12 ? 'PM' : 'AM';
        var hour12 = hour24 % 12;
        if (hour12 === 0) {
            hour12 = 12;
        }
        return y + '/' + pad(mo) + '/' + pad(d) + ' ' + hour12 + ':' + pad(minute) + ' ' + ampm;
    }

    function ajaxUrl() {
        if (typeof rentalObj !== 'undefined' && rentalObj.url) {
            return rentalObj.url;
        }
        if (typeof ccrRentopianPeriod !== 'undefined' && ccrRentopianPeriod.ajaxUrl) {
            return ccrRentopianPeriod.ajaxUrl;
        }
        return '';
    }

    function setStatus($wrap, text, ok) {
        var $status = $wrap.find('[data-ccr-period-status]');
        if (!$status.length) {
            return;
        }
        $status.text(text || '');
        $status.toggleClass('is-ok', !!ok);
        $status.toggleClass('is-error', !ok && !!text);
        $status.toggleClass('is-muted', !text || (!ok && !text));
    }

    function syncReturnMin($wrap) {
        var start = $wrap.find('[data-ccr-pickup-date]').val();
        var $end = $wrap.find('[data-ccr-return-date]');
        if (start) {
            $end.attr('min', start);
            if ($end.val() && $end.val() < start) {
                $end.val(start);
            }
        }
    }

    function hasSavedDates() {
        return /(?:^|; )rental_form_filled=/.test(document.cookie);
    }

    function markReady($wrap) {
        setStatus($wrap, 'Dates saved — you can add to cart.', true);
        $wrap.addClass('is-filled');
        $('.rntp-out-of-stock, .rntl-product-err').hide();
        var $btn = $('form.cart .single_add_to_cart_button');
        $btn.removeClass('disabled').prop('disabled', false).fadeIn();
    }

    function submitPeriod($wrap, opts) {
        opts = opts || {};
        var reloadOnSuccess = opts.reload !== false;

        var url = ajaxUrl();
        if (!url) {
            setStatus($wrap, 'Date service is not available.', false);
            return;
        }

        syncReturnMin($wrap);

        var startDate = $wrap.find('[data-ccr-pickup-date]').val();
        var startTime = $wrap.find('[data-ccr-pickup-time]').val();
        var endDate = $wrap.find('[data-ccr-return-date]').val();
        var endTime = $wrap.find('[data-ccr-return-time]').val();
        var hideEnd = $wrap.data('hide-end') === 1 || $wrap.data('hide-end') === '1';
        var hideZip = $wrap.data('hide-zip') === 1 || $wrap.data('hide-zip') === '1';
        var zip = $wrap.data('zip') || '';

        if (!startDate || !startTime) {
            return;
        }
        if (!hideEnd && (!endDate || !endTime)) {
            return;
        }

        var startFormatted = formatRentopianDateTime(startDate, startTime);
        var endFormatted = hideEnd ? '' : formatRentopianDateTime(endDate, endTime);
        if (!startFormatted || (!hideEnd && !endFormatted)) {
            setStatus($wrap, 'Please enter valid dates.', false);
            return;
        }

        var payload = {
            action: 'rental_date_form_api',
            start_date: startFormatted,
            end_date: endFormatted
        };

        if (!hideZip && zip) {
            payload.zip = String(zip);
        }

        setStatus($wrap, 'Saving dates…', true);
        $wrap.addClass('is-saving');

        $.ajax({
            type: 'POST',
            url: url,
            dataType: 'JSON',
            data: payload
        }).done(function (data) {
            if (data && data.message === 'success') {
                if (reloadOnSuccess) {
                    setStatus($wrap, 'Dates saved — refreshing availability…', true);
                    window.setTimeout(function () {
                        window.location.reload();
                    }, 250);
                } else {
                    markReady($wrap);
                }
            } else {
                var msg = (data && (data.message_txt || data.message)) || 'Could not save dates.';
                setStatus($wrap, msg, false);
            }
        }).fail(function (xhr) {
            var msg = 'Could not save dates.';
            try {
                var body = xhr.responseJSON || JSON.parse(xhr.responseText || '{}');
                if (body && (body.message_txt || body.message)) {
                    msg = body.message_txt || body.message;
                }
            } catch (e) { /* ignore */ }
            setStatus($wrap, msg, false);
        }).always(function () {
            $wrap.removeClass('is-saving');
        });
    }

    var timer = null;
    function queueSubmit($wrap, opts) {
        clearTimeout(timer);
        timer = setTimeout(function () {
            submitPeriod($wrap, opts);
        }, 350);
    }

    $(function () {
        var $wrap = $('[data-ccr-rental-period]');
        if (!$wrap.length) {
            return;
        }

        syncReturnMin($wrap);

        $wrap.on('click', '.ccr-rental-period-icon', function (e) {
            e.preventDefault();
            var input = $(this).siblings('input.ccr-rental-period-input').get(0);
            if (!input) {
                return;
            }
            if (typeof input.showPicker === 'function') {
                try {
                    input.showPicker();
                    return;
                } catch (err) { /* ignore */ }
            }
            input.focus();
            input.click();
        });

        $wrap.on('change', 'input', function () {
            var isPickup = $(this).is('[data-ccr-pickup-date], [data-ccr-pickup-time]');
            if (isPickup) {
                var start = $wrap.find('[data-ccr-pickup-date]').val();
                var pickup = $wrap.find('[data-ccr-pickup-time]').val();
                var $endDate = $wrap.find('[data-ccr-return-date]');
                var $endTime = $wrap.find('[data-ccr-return-time]');
                if (start && pickup && (!$endDate.val() || $wrap.attr('data-return-auto') === '1')) {
                    var parts = start.split('-');
                    var t = parseTimeToParts(pickup);
                    var dt = new Date(
                        parseInt(parts[0], 10),
                        parseInt(parts[1], 10) - 1,
                        parseInt(parts[2], 10),
                        t.h,
                        t.m,
                        0
                    );
                    dt.setHours(dt.getHours() + 24);
                    $endDate.val(dt.getFullYear() + '-' + pad(dt.getMonth() + 1) + '-' + pad(dt.getDate()));
                    $endTime.val(pad(dt.getHours()) + ':' + pad(dt.getMinutes()));
                    $wrap.attr('data-return-auto', '1');
                }
            }
            if ($(this).is('[data-ccr-return-date], [data-ccr-return-time]')) {
                $wrap.attr('data-return-auto', '0');
            }
            queueSubmit($wrap, { reload: true });
        });

        // Always re-save once so cookies use YYYY/MM/DD (old display-format cookies
        // make Rentopian availability return empty → “not available” on every product).
        if (hasSavedDates()) {
            markReady($wrap);
            queueSubmit($wrap, { reload: false });
        } else {
            queueSubmit($wrap, { reload: true });
        }
    });
})(jQuery);
