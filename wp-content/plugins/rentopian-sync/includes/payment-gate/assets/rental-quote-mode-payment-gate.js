/*--------------------------------------------------------------
Rentopian Sync — quote mode payment gate, browser side

The server already refuses to charge in quote mode and switches off the wallet
button settings of the gateways it knows by name. This is the last layer: it
removes any express checkout button drawn anyway, so a customer is never
offered a payment the server would then refuse.

Removal rather than hiding, because a button that exists can still be reached.
They are injected asynchronously — after the gateway's script loads, and again
whenever WooCommerce refreshes the cart fragments or the blocks re-render — so
an observer covers all of those without knowing which one happened.
----------------------------------------------------------------*/

(function () {
    'use strict';

    var cfg = window.rentalQuoteModePaymentGate || {};
    var selectors = cfg.selectors || [];

    if (!selectors.length || !window.MutationObserver) {
        return;
    }

    // Page builder previews and widget iframes render the same markup, and are
    // not what the customer is checking out in.
    if (window.top !== window.self) {
        return;
    }

    var selector = selectors.join(',');

    function removeWalletButtons() {
        var nodes = document.querySelectorAll(selector);

        for (var i = 0; i < nodes.length; i++) {
            if (nodes[i].parentNode) {
                nodes[i].parentNode.removeChild(nodes[i]);
            }
        }
    }

    removeWalletButtons();

    // Nodes arrive in bursts while a gateway mounts its button. Coalescing into
    // one pass per turn keeps that from becoming one pass per node.
    var scheduled = false;

    function schedule() {
        if (scheduled) {
            return;
        }

        scheduled = true;

        window.setTimeout(function () {
            scheduled = false;
            removeWalletButtons();
        }, 0);
    }

    function observe() {
        new MutationObserver(schedule).observe(document.body, {
            childList: true,
            subtree: true
        });
    }

    if (document.body) {
        observe();
    } else {
        document.addEventListener('DOMContentLoaded', observe);
    }
})();
