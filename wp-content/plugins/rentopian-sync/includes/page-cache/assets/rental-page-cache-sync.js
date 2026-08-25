/*--------------------------------------------------------------
Rentopian Sync — page cache reconciliation

The add to cart gate is decided in PHP and baked into the HTML. When a full page
cache answers a request, that HTML can describe a different customer's cookies
than the one reading it: the page offers "select rental dates" to somebody who
has already chosen them, or offers add to cart to somebody who has not.

The date cookies themselves are HttpOnly, but the marker the date form writes
alongside them is not, so the browser can tell which of the two states it is
actually in and compare that against the state the HTML was rendered for.

A disagreement is corrected by reloading once past the cache. It is capped at a
single attempt per page per tab, so a cache that ignores the query string costs
one reload rather than a loop.
----------------------------------------------------------------*/

(function () {
    'use strict';

    var cfg = window.rentalPageCacheSync || {};
    var marker = cfg.marker || 'rental_form_filled';
    var param = cfg.param || 'rntp_nc';

    // Never touch a document that is not the one the customer is browsing:
    // page builder previews and widget iframes render the same markup.
    if (window.top !== window.self) {
        return;
    }

    if (document.body && document.body.className.indexOf('elementor-editor') !== -1) {
        return;
    }

    function cookieExists(name) {
        var parts = (document.cookie || '').split(';');
        for (var i = 0; i < parts.length; i++) {
            if (parts[i].split('=')[0].trim() === name) {
                return true;
            }
        }
        return false;
    }

    function attempted(key) {
        try {
            return window.sessionStorage.getItem(key) === '1';
        } catch (e) {
            // Private modes can refuse storage; without it one reload is still
            // safe, so treat the attempt as already made and correct in place.
            return true;
        }
    }

    function markAttempted(key) {
        try {
            window.sessionStorage.setItem(key, '1');
        } catch (e) {
            /* nothing to do — see attempted() */
        }
    }

    // Drop the cache busting argument once the page it produced is correct, so
    // the customer is not left looking at, or sharing, a one-off URL.
    function tidyUrl() {
        if (window.location.href.indexOf(param + '=') === -1) {
            return;
        }
        if (!window.history || !window.history.replaceState) {
            return;
        }
        try {
            var url = new URL(window.location.href);
            url.searchParams.delete(param);
            window.history.replaceState(null, '', url.pathname + url.search + url.hash);
        } catch (e) {
            /* an untidy address bar is not worth an error */
        }
    }

    function bustedUrl() {
        var url = window.location.href.split('#')[0];
        var hash = window.location.hash || '';
        url = url.replace(new RegExp('([?&])' + param + '=[^&]*&?', 'g'), '$1').replace(/[?&]$/, '');
        return url + (url.indexOf('?') === -1 ? '?' : '&') + param + '=' + Date.now() + hash;
    }

    // Last resort when the reload could not produce a fresh page: a customer
    // without dates must not be able to add to the cart, because the server
    // will reject it later with an error they cannot act on. The other
    // direction is left alone — the date form is still there to be submitted,
    // and that submission reloads onto a URL the cache does not answer.
    function hideCartControls() {
        var forms = document.querySelectorAll('form.cart, .single_add_to_cart_button');
        for (var i = 0; i < forms.length; i++) {
            forms[i].style.display = 'none';
        }
        var selectDates = document.querySelectorAll('.rental-select-dates');
        for (var j = 0; j < selectDates.length; j++) {
            selectDates[j].style.display = '';
        }
    }

    function reconcile() {
        var holder = document.getElementById('rntp-form-holder');
        if (!holder) {
            return;
        }

        // Seeded date cookies say nothing about the customer in this mode, and
        // add to cart is not gated on them.
        if (holder.getAttribute('data-dates-on-checkout') === '1') {
            return;
        }

        var renderedWithDates = holder.getAttribute('data-has-start-date') === '1';
        var visitorHasDates = cookieExists(marker);

        if (renderedWithDates === visitorHasDates) {
            tidyUrl();
            return;
        }

        // A page that has just handled an add to cart carries its result in the
        // URL and its message in storage; reloading would throw both away.
        if (window.location.search.indexOf('added_to_cart=') !== -1) {
            return;
        }

        var key = 'rntp-cache-sync:' + window.location.pathname;

        if (attempted(key)) {
            if (renderedWithDates && !visitorHasDates) {
                hideCartControls();
            }
            return;
        }

        markAttempted(key);
        window.location.replace(bustedUrl());
    }

    reconcile();

    // A back or forward navigation can repaint a copy of the page that was
    // rendered for a different cookie state, and scripts do not run again on
    // their own when that happens.
    window.addEventListener('pageshow', function (event) {
        if (event.persisted) {
            reconcile();
        }
    });
}());
