(function ($) {
    function quote() {
        var $wrap = $('.ccr-rental-fields');
        if (!$wrap.length || typeof ccrRental === 'undefined') {
            return;
        }

        var start = $wrap.find('[name="ccr_rental_start"]').val();
        var end = $wrap.find('[name="ccr_rental_end"]').val();
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
            quantity: qty
        }).done(function (response) {
            if (!response.success) {
                return;
            }

            var data = response.data;
            var text = data.days + ' day(s) — ' + data.formatted_total;

            if (data.available <= 0) {
                text += ' — unavailable for these dates';
            } else if (data.available < qty) {
                text += ' — only ' + data.available + ' available';
            }

            $wrap.find('.ccr-quote').text(text);
        });
    }

    $(document).on('change', '.ccr-rental-fields input', quote);
    $(document).on('change', 'input.qty', quote);
})(jQuery);
