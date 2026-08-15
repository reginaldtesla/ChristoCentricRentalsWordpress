(function ($) {
    var returnAuto = true;

    function $fields() {
        return $('.ccr-rental-fields');
    }

    function latestReturn() {
        return (typeof ccrRental !== 'undefined' && ccrRental.latestReturnTime) ? ccrRental.latestReturnTime : '20:50';
    }

    function periodHours() {
        return (typeof ccrRental !== 'undefined' && ccrRental.defaultPeriodHours) ? parseInt(ccrRental.defaultPeriodHours, 10) : 24;
    }

    function normalizeTime(value) {
        if (!value) {
            return '';
        }
        var parts = String(value).split(':');
        var h = parseInt(parts[0], 10);
        var m = parseInt(parts[1] || '0', 10);
        if (isNaN(h) || isNaN(m)) {
            return '';
        }
        return String(h).padStart(2, '0') + ':' + String(m).padStart(2, '0');
    }

    function pad(n) {
        return String(n).padStart(2, '0');
    }

    function addHours(dateStr, timeStr, hours) {
        if (!dateStr || !timeStr) {
            return null;
        }
        var parts = dateStr.split('-');
        var t = normalizeTime(timeStr).split(':');
        var d = new Date(
            parseInt(parts[0], 10),
            parseInt(parts[1], 10) - 1,
            parseInt(parts[2], 10),
            parseInt(t[0], 10),
            parseInt(t[1], 10),
            0
        );
        if (isNaN(d.getTime())) {
            return null;
        }
        d.setHours(d.getHours() + hours);
        var outTime = pad(d.getHours()) + ':' + pad(d.getMinutes());
        var max = latestReturn();
        if (outTime > max) {
            outTime = max;
        }
        return {
            date: d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()),
            time: outTime
        };
    }

    function syncReturnFromPickup() {
        var $wrap = $fields();
        if (!$wrap.length || !returnAuto) {
            return;
        }

        var start = $wrap.find('[name="ccr_rental_start"]').val();
        var pickup = $wrap.find('[name="ccr_pickup_time"]').val();
        var next = addHours(start, pickup, periodHours());
        if (!next) {
            return;
        }

        $wrap.find('[name="ccr_rental_end"]').val(next.date);
        $wrap.find('[name="ccr_return_time"]').val(next.time);
    }

    function enforceReturnClosing() {
        var $wrap = $fields();
        if (!$wrap.length) {
            return true;
        }

        var $return = $wrap.find('[name="ccr_return_time"]');
        if (!$return.length) {
            return true;
        }

        var max = latestReturn();
        $return.attr('max', max);

        var value = normalizeTime($return.val());
        if (value && value > max) {
            $return.val(max);
            var msg = (ccrRental.i18n && ccrRental.i18n.returnTooLate) ? ccrRental.i18n.returnTooLate : ('Return time must be by ' + max);
            $wrap.find('.ccr-quote').text(msg).removeClass('text-gray-600').addClass('text-red-600');
            return false;
        }

        return true;
    }

    function syncReturnMin() {
        var $wrap = $fields();
        if (!$wrap.length) {
            return;
        }

        var $start = $wrap.find('[name="ccr_rental_start"]');
        var $end = $wrap.find('[name="ccr_rental_end"]');
        var start = $start.val();

        if (!start) {
            return;
        }

        $end.attr('min', start);

        if (!$end.val() || $end.val() < start) {
            $end.val(start);
        }
    }

    function setAddButtonEnabled(enabled) {
        var $btn = $('form.cart button.single_add_to_cart_button, form.cart .single_add_to_cart_button');
        if (!$btn.length) {
            return;
        }
        $btn.prop('disabled', !enabled);
        $btn.toggleClass('opacity-50 cursor-not-allowed', !enabled);
    }

    function quote() {
        var $wrap = $fields();
        if (!$wrap.length || typeof ccrRental === 'undefined') {
            return;
        }

        syncReturnMin();
        if (!enforceReturnClosing()) {
            setAddButtonEnabled(false);
            return;
        }

        var start = $wrap.find('[name="ccr_rental_start"]').val();
        var end = $wrap.find('[name="ccr_rental_end"]').val();
        var pickup = $wrap.find('[name="ccr_pickup_time"]').val();
        var ret = $wrap.find('[name="ccr_return_time"]').val();
        var qty = $('input.qty').val() || 1;

        if (!start || !end) {
            return;
        }

        $.post(ccrRental.ajaxUrl, {
            action: 'ccr_rental_quote',
            nonce: ccrRental.nonce,
            product_id: ccrRental.productId,
            start: start,
            end: end,
            pickup: pickup,
            return: ret,
            quantity: qty
        }).done(function (response) {
            if (!response.success) {
                return;
            }

            var data = response.data;
            var days = parseInt(data.days, 10) || 1;
            var periodText;
            if (days === 1 && ccrRental.i18n && ccrRental.i18n.quoteOne) {
                periodText = ccrRental.i18n.quoteOne;
            } else if (ccrRental.i18n && ccrRental.i18n.quoteMany) {
                periodText = ccrRental.i18n.quoteMany.replace('%d', String(days));
            } else {
                periodText = days + ' × 24 hours';
            }
            var text = periodText + ' — ' + data.formatted_total;
            var ok = data.available > 0 && data.available >= qty;

            if (!ok) {
                if (data.is_kit && data.unavailable_items && data.unavailable_items.length) {
                    text += ' — unavailable (' + data.unavailable_items.join(', ') + ' booked)';
                } else if (data.available <= 0) {
                    text += ' — unavailable for these dates';
                } else {
                    text += ' — only ' + data.available + ' available';
                }
            } else if (data.is_kit) {
                text += ' — kit available';
            }

            $wrap.find('.ccr-quote').text(text);
            $wrap.find('.ccr-quote').toggleClass('text-red-600', true).toggleClass('text-gray-600', false);
            setAddButtonEnabled(ok);
        });
    }

    $(document).on('change', '.ccr-rental-fields [name="ccr_rental_start"], .ccr-rental-fields [name="ccr_pickup_time"]', function () {
        syncReturnFromPickup();
        quote();
    });

    $(document).on('change', '.ccr-rental-fields [name="ccr_rental_end"], .ccr-rental-fields [name="ccr_return_time"]', function () {
        returnAuto = false;
        $fields().attr('data-return-auto', '0');
        quote();
    });

    $(document).on('change', '.ccr-rental-fields input', function () {
        var name = $(this).attr('name');
        if (name === 'ccr_rental_start' || name === 'ccr_pickup_time' || name === 'ccr_rental_end' || name === 'ccr_return_time') {
            return;
        }
        quote();
    });
    $(document).on('change', 'input.qty', quote);
    $(document).on('submit', 'form.cart', function (e) {
        if (!enforceReturnClosing()) {
            e.preventDefault();
            setAddButtonEnabled(false);
        }
    });

    $(function () {
        returnAuto = $fields().attr('data-return-auto') !== '0';
        syncReturnFromPickup();
        syncReturnMin();
        enforceReturnClosing();
        quote();
    });
})(jQuery);
