/*--------------------------------------------------------------
Sets Module — Modern Front-End Controller
Author: Rentopian
Website: https://rentopian.com
Copyright 2023, Rentopian Inc. All Rights Reserved.

Vanilla-JS controller for the modern set product page. Owns:

  - Per-section UI controllers (FixedSection / DropdownSection /
    DropdownQtySection / MultiSection).
  - The Wrapper that aggregates section state, runs the client-mirror
    validator (over window.RentalSetsPrefill.rules), updates inline
    error feedback, and writes the canonical hidden-input payload that
    the server expects on submit.
  - Prefill application: failed_selections > cart_prefill > markup
    defaults (in that priority order).

Shipped POST shapes. Two are emitted simultaneously, by design:

  - rental_set_selections[<group_uid>][group_id]
    rental_set_selections[<group_uid>][selections][<item_uid>][...] —
    consumed by the modern validator.

  - rental_add_ons[<i>][...] including item_type=grouped_child and the
    rental_set_group_* keys — consumed by the legacy
    rental_add_product_to_cart loop and by Cart_Handler. This is the
    backward-compat surface; without it, classic plugin code that
    reads $_POST['rental_add_ons'] cannot see modern picks.

The renderer's hidden-input host (`.rntp-hidden-inputs`) is rewritten
on every change; nothing else writes to it.

No jQuery, no build step. Designed to run on an inline <script> with
defer / footer position. Boots once on DOMContentLoaded; if the script
loads after DOMContentLoaded already fired (`document.readyState !==
'loading'`), it boots immediately so a late-loaded bundle still works.
--------------------------------------------------------------*/

(function () {
    'use strict';

    /*--------------------------------------------------------------
    Utility helpers
    --------------------------------------------------------------*/
    function $(selector, root) {
        return (root || document).querySelector(selector);
    }
    function $$(selector, root) {
        return Array.prototype.slice.call((root || document).querySelectorAll(selector));
    }
    function el(tag, attrs) {
        var n = document.createElement(tag);
        if (attrs) {
            for (var k in attrs) {
                if (Object.prototype.hasOwnProperty.call(attrs, k)) {
                    n.setAttribute(k, attrs[k]);
                }
            }
        }
        return n;
    }
    function toInt(value, fallback) {
        var n = parseInt(value, 10);
        return isNaN(n) ? (fallback || 0) : n;
    }
    function clamp(n, min, max) {
        if (typeof min === 'number' && n < min) { return min; }
        if (typeof max === 'number' && n > max) { return max; }
        return n;
    }

    /*--------------------------------------------------------------
    sprintf-lite — supports %s, %d and positional %1$s / %2$d.
    Used only for the human-readable error_codes_dictionary strings
    shipped from PHP.
    --------------------------------------------------------------*/
    function format(template, args) {
        if (!template) { return ''; }
        var i = 0;
        return template.replace(/%(?:(\d+)\$)?([sd])/g, function (_, posIdx, type) {
            var idx = posIdx ? toInt(posIdx, 1) - 1 : i++;
            var v = args[idx];
            if (typeof v === 'undefined' || v === null) { v = ''; }
            return type === 'd' ? toInt(v, 0) : String(v);
        });
    }

    /*--------------------------------------------------------------
    sectionLabel — read the human-readable title of a section straight
    from the DOM (`.rntp-section-title`), stripping the required-marker
    "*". This is the single source for the label used in validation
    messages, so a selectable item / group / addon that ships an empty
    `name` in the rules export still produces a message that names the
    actual item the customer sees.
    --------------------------------------------------------------*/
    function sectionLabel(root) {
        if (!root || !root.querySelector) { return ''; }
        var titleEl = root.querySelector('.rntp-section-title');
        if (!titleEl) { return ''; }
        var clone = titleEl.cloneNode(true);
        var marker = clone.querySelector('.rntp-required-marker');
        if (marker && marker.parentNode) { marker.parentNode.removeChild(marker); }
        return (clone.textContent || '').replace(/\s+/g, ' ').trim();
    }

    /*--------------------------------------------------------------
    wireCustomSelect — turns the `.rntp-cs` shell next to a native
    <select> into a clickable, thumbnail-aware dropdown. The native
    select stays as the form-bound value source; this layer only
    drives presentation. Activating an option syncs the native select
    and dispatches a 'change' event so every existing handler
    (DropdownSection.onChange, persistSectionChange, the WC-price
    repainter, etc.) fires unchanged. If the JS never runs the user
    still gets a working native dropdown.
    --------------------------------------------------------------*/
    function wireCustomSelect(container) {
        if (!container) { return; }
        var selectId = container.getAttribute('data-for-select');
        var nativeSelect = selectId ? document.getElementById(selectId) : null;
        if (!nativeSelect) { return; }

        var trigger      = container.querySelector('.rntp-cs-trigger');
        var panel        = container.querySelector('.rntp-cs-panel');
        var triggerThumb = container.querySelector('[data-role="cs-trigger-thumb"]');
        var triggerLabel = container.querySelector('[data-role="cs-trigger-label"]');
        if (!trigger || !panel) { return; }

        // Mark the native select itself + the shell as "custom-active"
        // so CSS can hide the select and lay out the shell. Doing this
        // from JS (rather than always in CSS) preserves graceful
        // degradation when JS doesn't load — without these classes the
        // native select stays visible and usable.
        nativeSelect.classList.add('rntp-cs-bound');
        container.classList.add('rntp-cs-active');

        function openPanel() {
            panel.hidden = false;
            trigger.setAttribute('aria-expanded', 'true');
            container.classList.add('rntp-cs--open');
        }
        function closePanel() {
            panel.hidden = true;
            trigger.setAttribute('aria-expanded', 'false');
            container.classList.remove('rntp-cs--open');
        }

        function syncTriggerFromSelect() {
            var opt = nativeSelect.options[nativeSelect.selectedIndex];
            if (!opt) { return; }
            var value = opt.value || '';
            var panelOption = panel.querySelector('.rntp-cs-option[data-value="' + cssEscape(value) + '"]');
            if (!panelOption) { return; }

            // Move panel-option contents into the trigger (thumb +
            // label) so the closed state mirrors the live selection.
            var srcThumb = panelOption.querySelector('.rntp-cs-thumb');
            var srcText  = panelOption.querySelector('.rntp-cs-option-text');
            if (triggerThumb) {
                if (srcThumb) {
                    triggerThumb.innerHTML = srcThumb.innerHTML;
                    triggerThumb.removeAttribute('hidden');
                } else {
                    // No-Thanks (or any option that opts out of the
                    // thumb column): clear the trigger's thumb and
                    // collapse its space. Without this branch the
                    // previously-selected option's image stays visible
                    // next to "No Thanks, I don't need this".
                    triggerThumb.innerHTML = '';
                    triggerThumb.setAttribute('hidden', '');
                }
            }
            if (triggerLabel && srcText) {
                triggerLabel.innerHTML = srcText.innerHTML;
            }

            var isNoThanks = panelOption.classList.contains('rntp-cs-option--no-thanks')
                || panelOption.getAttribute('data-no-thanks') === '1';
            container.classList.toggle('rntp-cs--no-thanks', isNoThanks);

            // Mark the selected panel option for styling + a11y.
            $$('.rntp-cs-option', panel).forEach(function (li) {
                li.setAttribute('aria-selected', li === panelOption ? 'true' : 'false');
            });
        }

        // Expose the sync helper on the container so external callers
        // (notably applyPrefill, which sets the underlying <select>.value
        // programmatically without dispatching 'change') can refresh the
        // trigger's visual state. Without this the trigger keeps showing
        // the option the page rendered with, even after prefill swapped
        // the form-bound value to the customer's actual cart pick.
        container._rntpResync = syncTriggerFromSelect;

        trigger.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            if (panel.hidden) { openPanel(); } else { closePanel(); }
        });

        panel.addEventListener('click', function (e) {
            // External-link click — let the browser open the product
            // page in a new tab and DON'T select the option that
            // contains the link.
            if (e.target && e.target.closest && e.target.closest('[data-role="external-link"]')) {
                return;
            }
            var li = e.target && e.target.closest ? e.target.closest('.rntp-cs-option') : null;
            if (!li) { return; }
            var value = li.getAttribute('data-value') || '';
            if (nativeSelect.value !== value) {
                nativeSelect.value = value;
                nativeSelect.dispatchEvent(new Event('change', { bubbles: true }));
            }
            syncTriggerFromSelect();
            closePanel();
        });

        // Outside click closes the panel.
        document.addEventListener('click', function (e) {
            if (!panel.hidden && !container.contains(e.target)) {
                closePanel();
            }
        }, true);

        // Esc closes the panel; the native select still receives focus.
        trigger.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && !panel.hidden) {
                closePanel();
                trigger.focus();
            }
        });

        // External code may set nativeSelect.value programmatically
        // (prefill, edit-mode restore). Reflect that into the trigger.
        nativeSelect.addEventListener('change', syncTriggerFromSelect);

        syncTriggerFromSelect();
    }

    // CSS.escape polyfill — needed for the panel selector when option
    // values contain colons / hyphens that querySelector can choke on.
    function cssEscape(value) {
        if (typeof CSS !== 'undefined' && typeof CSS.escape === 'function') {
            return CSS.escape(value);
        }
        return String(value).replace(/[^a-zA-Z0-9_\-]/g, function (ch) {
            return '\\' + ch;
        });
    }

    /*--------------------------------------------------------------
    Section controllers
    --------------------------------------------------------------*/

    /**
     * Base — every controller exposes:
     *   - root      (HTMLElement)
     *   - uid       (group_uid; synthetic for selectable)
     *   - origin    ('group' | 'selectable' | 'simple')
     *   - required  (bool)
     *   - groupId   (server inventory_sets_groups_relation.id; 0 for selectable)
     *   - getSelections() → array of pick records
     *   - applyPrefill(picksByUid)
     *   - showError(message) / clearError()
     */
    function BaseSection(root) {
        this.root = root;
        this.uid = root.getAttribute('data-section-uid') || '';
        this.origin = root.getAttribute('data-section-origin') || root.getAttribute('data-origin') || '';
        this.required = root.getAttribute('data-required') === '1';
        this.groupId = toInt(root.getAttribute('data-group-id'), 0);
        this.groupQuantity = toInt(root.getAttribute('data-group-quantity'), 1);
        this.errorEl = $('[data-role="section-error"]', root);
        this.parentProductId = toInt(root.getAttribute('data-parent-product-id'), 0);
    }
    BaseSection.prototype.showError = function (message) {
        if (!this.errorEl) { return; }
        this.errorEl.textContent = message;
        this.errorEl.hidden = false;
        this.root.setAttribute('data-has-error', '1');
    };
    BaseSection.prototype.clearError = function () {
        if (!this.errorEl) { return; }
        this.errorEl.textContent = '';
        this.errorEl.hidden = true;
        this.root.removeAttribute('data-has-error');
    };

    /*-------- FixedSection ----------------------------------------*/
    // Required / "Included" simple item. The package fixes its quantity
    function FixedSection(root) {
        BaseSection.call(this, root);
        var state = $('[data-role="section-state"]', root);
        this.pick = state ? {
            itemUid: state.getAttribute('data-item-uid') || this.uid,
            productId: toInt(state.getAttribute('data-product-id'), 0),
            variantId: toInt(state.getAttribute('data-variant-id'), 0),
            invId: toInt(state.getAttribute('data-inv-id'), 0),
            quantity: toInt(state.getAttribute('data-quantity'), 1),
            // Per-item price for the Summary rollup. Surfaced by the
            // partial as `data-price` on the section-state node so the
            // JS doesn't need to re-resolve it from the engine.
            price: state.getAttribute('data-price') || ''
        } : null;
    }
    FixedSection.prototype = Object.create(BaseSection.prototype);
    FixedSection.prototype.constructor = FixedSection;
    FixedSection.prototype.bind = function () { /* fixed: nothing interactive */ };
    FixedSection.prototype.getSelections = function () {
        return this.pick ? [this.pick] : [];
    };
    FixedSection.prototype.applyPrefill = function () { /* fixed: ignore */ };
    // Required/included items can't be declined 
    FixedSection.prototype.selectNoThanks = function () { /* not declinable */ };

    /*-------- DropdownSection ------------------------------------*/
    function DropdownSection(root) {
        BaseSection.call(this, root);
        this.select = $('[data-role="section-select"]', root);
        this.onChange = null;
    }
    DropdownSection.prototype = Object.create(BaseSection.prototype);
    DropdownSection.prototype.constructor = DropdownSection;
    DropdownSection.prototype.bind = function (fire) {
        this.onChange = fire;
        if (this.select) {
            this.select.addEventListener('change', fire);
        }
    };
    DropdownSection.prototype.getSelections = function () {
        if (!this.select) { return []; }
        var opt = this.select.options[this.select.selectedIndex];
        if (!opt || !opt.value) { return []; }
        // "No Thanks" opt-out for non-required sections. The synthetic
        // value `__no_thanks__` (or any option flagged
        // `data-no-thanks="1"`) means the customer declined this
        // section — return EMPTY so refreshState skips the section in
        // payload, summary, and validation. The addon cascade in
        // refreshState mirrors this for any addon-card nested inside
        // the section's root, so a declined parent excludes its addons
        // too.
        if (opt.value === '__no_thanks__' || opt.getAttribute('data-no-thanks') === '1') {
            return [];
        }
        return [{
            itemUid: opt.getAttribute('data-item-uid') || opt.value,
            productId: toInt(opt.getAttribute('data-product-id'), 0),
            variantId: toInt(opt.getAttribute('data-variant-id'), 0),
            invId: toInt(opt.getAttribute('data-inv-id'), 0),
            quantity: toInt(opt.getAttribute('data-quantity'), 1),
            price: opt.getAttribute('data-price') || ''
        }];
    };
    DropdownSection.prototype.applyPrefill = function (picks) {
        if (!this.select || !picks || !picks.length) { return; }
        var pick = picks[0];

        // Priority-ranked match. 
        var matchByUid = '';
        var matchByVariant = '';
        var matchByProduct = '';
        for (var i = 0; i < this.select.options.length; i++) {
            var o = this.select.options[i];
            if (pick.itemUid && '' === matchByUid
                && o.getAttribute('data-item-uid') === pick.itemUid) {
                matchByUid = o.value;
                break; // strongest signal — no need to keep scanning
            }
            if ('' === matchByVariant && pick.variantId
                && toInt(o.getAttribute('data-variant-id'), 0) === toInt(pick.variantId, 0)) {
                matchByVariant = o.value;
            }
            if ('' === matchByProduct && pick.productId
                && toInt(o.getAttribute('data-product-id'), 0) === toInt(pick.productId, 0)) {
                matchByProduct = o.value;
            }
        }
        var match = matchByUid || matchByVariant || matchByProduct;
        if (match !== '') {
            this.select.value = match;
        }
    };
    // Force the section to its "No Thanks" opt-out.
    DropdownSection.prototype.selectNoThanks = function () {
        if (!this.select || this.required) { return; }
        for (var i = 0; i < this.select.options.length; i++) {
            var o = this.select.options[i];
            if (o.value === '__no_thanks__' || o.getAttribute('data-no-thanks') === '1') {
                this.select.value = o.value;
                return;
            }
        }
    };

    /*-------- DropdownQtySection ---------------------------------*/
    function DropdownQtySection(root) {
        DropdownSection.call(this, root);
        this.qmin = toInt(root.getAttribute('data-quantity-min'), 0);
        this.qmax = toInt(root.getAttribute('data-quantity-max'), 99);
        this.qtyInput = $('[data-role="qty-input"]', root);
        this.minusBtn = root.querySelector('[data-action="decrement"]');
        this.plusBtn  = root.querySelector('[data-action="increment"]');
    }
    DropdownQtySection.prototype = Object.create(DropdownSection.prototype);
    DropdownQtySection.prototype.constructor = DropdownQtySection;
    DropdownQtySection.prototype.bind = function (fire) {
        var self = this;
        // Wrap the fire callback so any select/stepper change also
        // refreshes the stepper's enabled state (disabled while
        // "No Thanks" is the active pick — the qty is meaningless then).
        var fireAndSync = function () {
            self.syncStepperEnabled();
            fire();
        };
        DropdownSection.prototype.bind.call(this, fireAndSync);
        if (this.qtyInput) {
            this.qtyInput.addEventListener('input', function () { self.normaliseQty(); fire(); });
            this.qtyInput.addEventListener('change', fire);
        }
        if (this.minusBtn) {
            this.minusBtn.addEventListener('click', function () {
                self.bumpQty(-1);
                fire();
            });
        }
        if (this.plusBtn) {
            this.plusBtn.addEventListener('click', function () {
                self.bumpQty(+1);
                fire();
            });
        }
        // Initial state.
        this.syncStepperEnabled();
    };
    /**
     * Disable the stepper while "No Thanks" is selected (qty doesn't
     * apply to an excluded item) and re-enable it for a real pick. The
     * `data-disabled` attribute drives the CSS dimming; the input +
     * buttons are also DOM-disabled so keyboard/touch can't change a
     * value that won't be used.
     */
    DropdownQtySection.prototype.syncStepperEnabled = function () {
        var stepper = this.root.querySelector('[data-role="qty-stepper"]');
        if (!stepper) { return; }
        var opt = (this.select && this.select.options[this.select.selectedIndex]) || null;
        var isNoThanks = opt && (opt.value === '__no_thanks__' || opt.getAttribute('data-no-thanks') === '1');
        stepper.setAttribute('data-disabled', isNoThanks ? '1' : '0');
        if (this.qtyInput) { this.qtyInput.disabled = !!isNoThanks; }
        if (this.minusBtn) { this.minusBtn.disabled = !!isNoThanks; }
        if (this.plusBtn) { this.plusBtn.disabled = !!isNoThanks; }
    };
    DropdownQtySection.prototype.bumpQty = function (delta) {
        if (!this.qtyInput) { return; }
        var current = toInt(this.qtyInput.value, 1);
        // A SELECTED item's qty is always >= 1; excluding it is the
        // "No Thanks" path (which returns [] from getSelections), not
        // qty 0. So the stepper bottoms out at 1 regardless of the
        // section's validation min (which may be 0 for optional).
        this.qtyInput.value = clamp(current + delta, 1, this.qmax);
    };
    DropdownQtySection.prototype.normaliseQty = function () {
        if (!this.qtyInput) { return; }
        var v = toInt(this.qtyInput.value, 1);
        var clamped = clamp(v, 1, this.qmax);
        if (clamped !== v) {
            this.qtyInput.value = clamped;
        }
    };
    DropdownQtySection.prototype.getSelections = function () {
        var base = DropdownSection.prototype.getSelections.call(this);
        if (!base.length) { return []; }
        var qty = this.qtyInput ? toInt(this.qtyInput.value, 0) : base[0].quantity;
        if (qty <= 0) { return []; }
        base[0].quantity = qty;
        return base;
    };
    DropdownQtySection.prototype.applyPrefill = function (picks) {
        DropdownSection.prototype.applyPrefill.call(this, picks);
        if (this.qtyInput && picks && picks.length) {
            var qty = toInt(picks[0].quantity, 0);
            this.qtyInput.value = clamp(qty, 0, this.qmax);
        }
    };

    /*-------- MultiSection ---------------------------------------*/
    function MultiSection(root) {
        BaseSection.call(this, root);
        this.cards = $$('[data-role="multi-card"]', root);
        this.qmin = toInt(root.getAttribute('data-quantity-min'), 0);
        this.qmax = toInt(root.getAttribute('data-quantity-max'), 99);
        this.runningTotalEl = $('[data-role="running-total"]', root);
    }
    MultiSection.prototype = Object.create(BaseSection.prototype);
    MultiSection.prototype.constructor = MultiSection;
    MultiSection.prototype.bind = function (fire) {
        var self = this;
        this.cards.forEach(function (card) {
            var input = $('[data-role="qty-input"]', card);
            var minus = card.querySelector('[data-action="decrement"]');
            var plus  = card.querySelector('[data-action="increment"]');

            function onInput() {
                self.normaliseCard(card);
                self.refreshSelectedClass(card);
                self.refreshRunningTotal();
                fire();
            }
            if (input) {
                input.addEventListener('input', onInput);
                input.addEventListener('change', onInput);
            }
            if (minus) {
                minus.addEventListener('click', function () { self.bump(card, -1); onInput(); });
            }
            if (plus) {
                plus.addEventListener('click', function () { self.bump(card, +1); onInput(); });
            }
            // Initial paint: mark default-selected cards (rendered with a
            // positive quantity) as selected so the outline + qty badge
            // reflect the default before the customer interacts.
            self.refreshSelectedClass(card);
        });
        this.refreshRunningTotal();
    };
    MultiSection.prototype.bump = function (card, delta) {
        var input = $('[data-role="qty-input"]', card);
        if (!input) { return; }
        input.value = clamp(toInt(input.value, 0) + delta, 0, this.qmax);
    };
    MultiSection.prototype.normaliseCard = function (card) {
        var input = $('[data-role="qty-input"]', card);
        if (!input) { return; }
        var v = toInt(input.value, 0);
        var clamped = clamp(v, 0, this.qmax);
        if (clamped !== v) { input.value = clamped; }
    };
    MultiSection.prototype.refreshSelectedClass = function (card) {
        var input = $('[data-role="qty-input"]', card);
        var qty = input ? toInt(input.value, 0) : 0;
        if (qty > 0) {
            card.setAttribute('data-selected', '1');
        } else {
            card.removeAttribute('data-selected');
        }
    };
    MultiSection.prototype.refreshRunningTotal = function () {
        // Total qty across all cards (e.g. "selected: 12").
        var total = 0;
        // Distinct items with qty > 0 (e.g. "Selected: 2 of 5").
        var distinct = 0;
        this.cards.forEach(function (card) {
            var input = $('[data-role="qty-input"]', card);
            var v = input ? Math.max(0, toInt(input.value, 0)) : 0;
            total += v;
            if (v > 0) { distinct++; }
            // Update the per-card "Qty: N" label so each card surfaces
            // its current quantity without the customer having to read
            // the stepper input.
            var cardQtyValue = $('[data-role="card-qty-value"]', card);
            if (cardQtyValue) { cardQtyValue.textContent = String(v); }
        });
        if (this.runningTotalEl) {
            this.runningTotalEl.textContent = '(selected: ' + total + ')';
        }
        var selectedCountEl = $('[data-role="selected-count"]', this.root);
        if (selectedCountEl) {
            selectedCountEl.textContent = String(distinct);
        }
    };
    MultiSection.prototype.getSelections = function () {
        var picks = [];
        this.cards.forEach(function (card) {
            var input = $('[data-role="qty-input"]', card);
            var qty = input ? toInt(input.value, 0) : 0;
            if (qty <= 0) { return; }
            picks.push({
                itemUid: card.getAttribute('data-item-uid')
                    || card.getAttribute('data-option-key') || '',
                productId: toInt(card.getAttribute('data-product-id'), 0),
                variantId: toInt(card.getAttribute('data-variant-id'), 0),
                invId: toInt(card.getAttribute('data-inv-id'), 0),
                quantity: qty,
                price: card.getAttribute('data-price') || ''
            });
        });
        return picks;
    };
    MultiSection.prototype.applyPrefill = function (picks) {
        if (!picks || !picks.length) { return; }
        // Reset all to 0 first; then write the prefill picks.
        this.cards.forEach(function (card) {
            var input = $('[data-role="qty-input"]', card);
            if (input) { input.value = 0; }
        });
        var self = this;
        picks.forEach(function (pick) {
            // Priority-ranked match: itemUid > variantId > productId.
            var byUid = null, byVariant = null, byProduct = null;
            for (var i = 0; i < self.cards.length; i++) {
                var card = self.cards[i];
                if (pick.itemUid && !byUid
                    && card.getAttribute('data-item-uid') === pick.itemUid) {
                    byUid = card;
                    break;
                }
                if (!byVariant && pick.variantId
                    && toInt(card.getAttribute('data-variant-id'), 0) === toInt(pick.variantId, 0)) {
                    byVariant = card;
                }
                if (!byProduct && pick.productId
                    && toInt(card.getAttribute('data-product-id'), 0) === toInt(pick.productId, 0)) {
                    byProduct = card;
                }
            }
            var match = byUid || byVariant || byProduct;
            if (match) {
                var input = $('[data-role="qty-input"]', match);
                if (input) { input.value = clamp(toInt(pick.quantity, 0), 0, self.qmax); }
            }
        });
        this.cards.forEach(function (card) { self.refreshSelectedClass(card); });
        this.refreshRunningTotal();
    };
    // Decline the whole group — zero every card. Mirrors the dropdown
    // opt-out for the failed-submission restore pass.
    MultiSection.prototype.selectNoThanks = function () {
        if (this.required) { return; }
        var self = this;
        this.cards.forEach(function (card) {
            var input = $('[data-role="qty-input"]', card);
            if (input) { input.value = 0; }
            self.refreshSelectedClass(card);
        });
        this.refreshRunningTotal();
    };

    /*--------------------------------------------------------------
    WC-style price formatter (mirrors wc_price() output in JS)
    --------------------------------------------------------------*/
    /**
     * Escape a string for safe HTML insertion. Tiny helper to avoid
     * pulling a full templating layer in for one node update.
     *
     * @param {string} s
     * @returns {string}
     */
    function htmlEscape(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    /**
     * Whether the site hides prices from visitors. The configurator paints
     * the running total and the per-item price labels client-side, so it
     * carries the same gate the PHP partials apply. The localized flag is a
     * string, hence the numeric comparison.
     *
     * @returns {boolean}
     */
    function pricesHidden() {
        return parseInt((window.RentalSetsModern || {}).hidePrices, 10) === 1;
    }

    /**
     * Format a numeric amount as WC's price HTML: the bdi/amount/
     * currencySymbol structure that `wc_price()` emits in PHP. Uses
     * the localized RentalSetsModern.currency settings (symbol,
     * position, separators, decimals).
     *
     * Returns '' for non-numeric input so callers can decide whether
     * to hide the wrapping element.
     *
     * @param {string|number} amount
     * @returns {string}
     */
    function formatWcPriceHtml(amount) {
        if (pricesHidden()) { return ''; }
        var n = parseFloat(amount);
        if (!isFinite(n)) { return ''; }
        var cfg = (window.RentalSetsModern && window.RentalSetsModern.currency) || {};
        var symbol = cfg.symbol || '$';
        var decimals = (typeof cfg.decimals === 'number') ? cfg.decimals : 2;
        var ds = cfg.decimal_separator || '.';
        var ts = cfg.thousand_separator || ',';
        var pos = cfg.position || 'left';

        var fixed = n.toFixed(decimals);
        var parts = fixed.split('.');
        var intPart = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ts);
        var formatted = intPart + (parts[1] ? ds + parts[1] : '');

        var symbolHtml = '<span class="woocommerce-Price-currencySymbol">' + htmlEscape(symbol) + '</span>';
        var amountHtml = htmlEscape(formatted);
        var inner;
        switch (pos) {
            case 'right':       inner = amountHtml + symbolHtml; break;
            case 'left_space':  inner = symbolHtml + '&nbsp;' + amountHtml; break;
            case 'right_space': inner = amountHtml + '&nbsp;' + symbolHtml; break;
            case 'left':
            default:            inner = symbolHtml + amountHtml; break;
        }
        return '<span class="woocommerce-Price-amount amount"><bdi>' + inner + '</bdi></span>';
    }

    /**
     * Repaint the `.rntp-selected-price` block inside one section
     * with the currently-chosen <option>'s `data-price`. Hides the
     * block when the chosen option carries no price.
     *
     * @param {Element} section A `.rntp-section` element.
     * @param {HTMLSelectElement} sel  The `<select>` inside it.
     * @returns {void}
     */
    function updateSelectedPriceForSection(section, sel) {
        var wrap = section.querySelector('[data-role="selected-price"]');
        var amt  = section.querySelector('[data-role="selected-price-amount"]');
        if (!wrap || !amt) { return; }
        var chosen = sel.options[sel.selectedIndex];
        var price = chosen ? chosen.getAttribute('data-price') : '';
        var html = (price && price !== '') ? formatWcPriceHtml(price) : '';
        if (html) {
            amt.innerHTML = html;
            wrap.removeAttribute('hidden');
        } else {
            amt.innerHTML = '';
            wrap.setAttribute('hidden', '');
        }
    }

    /**
     * Keep the section-level external-link icon's href in sync with
     * the currently selected option. The icon is rendered next to the
     * custom shell + (optionally) the qty stepper; clicking it opens
     * the picked item's product page in a new tab.
     *
     *
     * @param {Element} section  `.rntp-section` element.
     * @param {HTMLSelectElement} sel  Section's native `<select>`.
     * @returns {void}
     */
    function updateSectionExternalLink(section, sel) {
        var trigger = section.querySelector('[data-role="section-external-link"]');
        if (!trigger) { return; }
        var value = (sel && sel.value) || '';
        var href = '';
        // Prefer the currently-selected option's product link.
        if (value && value !== '__no_thanks__') {
            var li = section.querySelector('.rntp-cs-option[data-value="' + cssEscape(value) + '"]');
            var optionLink = li ? li.querySelector('[data-role="external-link"]') : null;
            href = optionLink ? optionLink.getAttribute('href') : '';
            // A real pick that exposes no product link is a hidden-on-website
            // item (hidden add-on / catalog-hidden). It must show NO external
            // link — never fall back to a sibling's page.
            if (!href) {
                trigger.setAttribute('hidden', '');
                return;
            }
        }
        // No real pick yet (e.g. "No Thanks") — fall back to the first
        // option that exposes a product link so the icon stays in place and
        // still opens a relevant product page.
        if (!href) {
            var firstLink = section.querySelector('.rntp-cs-option [data-role="external-link"]');
            href = firstLink ? firstLink.getAttribute('href') : '';
        }
        if (!href) {
            trigger.setAttribute('hidden', '');
            return;
        }
        trigger.setAttribute('href', href);
        trigger.removeAttribute('hidden');
    }

    /**
     * Show/hide the optional-item "Qty: N" note based on the current
     * selection. The note (qty == 1 optional simple items) should read
     * just "Qty: N" when the item is chosen and disappear entirely when
     * "No Thanks" is selected — the customer isn't adding anything then.
     *
     * @param {Element} section
     * @param {HTMLSelectElement} sel
     * @returns {void}
     */
    function updateOptionalQtyNote(section, sel) {
        var note = section.querySelector('[data-role="optional-qty-note"]');
        if (!note) { return; }
        var opt = sel && sel.options[sel.selectedIndex];
        var isNoThanks = !sel.value || sel.value === '__no_thanks__'
            || (opt && opt.getAttribute('data-no-thanks') === '1');
        note.hidden = !!isNoThanks;
    }

    /**
     * Repaint the addon-card's selected-price block. Same logic as
     * the section variant but scoped to a single `[data-role=
     * "addon-card"]`.
     *
     * @param {Element} card The `.rntp-addon-card` element.
     * @param {HTMLSelectElement} sel  The addon's `<select>`.
     * @returns {void}
     */
    function updateSelectedPriceForAddon(card, sel) {
        var wrap = card.querySelector('[data-role="addon-selected-price"]');
        var amt  = card.querySelector('[data-role="addon-selected-price-amount"]');
        if (!wrap || !amt) { return; }
        var chosen = sel.options[sel.selectedIndex];
        var price = chosen ? chosen.getAttribute('data-price') : '';
        var html = (price && price !== '') ? formatWcPriceHtml(price) : '';
        if (html) {
            amt.innerHTML = html;
            wrap.removeAttribute('hidden');
        } else {
            amt.innerHTML = '';
            wrap.setAttribute('hidden', '');
        }
    }

    /**
     * Mirror an addon `<select>`'s current value onto its parent card so
     * every read site (refreshState, updateSummary, writeHiddenInputs,
     * server push) has a single source of truth. A "No Thanks" pick (an
     * empty value, `__no_thanks__`, or an option flagged data-no-thanks)
     * flags the card opted-out and zeroes its variant id, so all those
     * sites skip the addon entirely.
     *
     * @param {Element} card             The `.rntp-addon-card`.
     * @param {HTMLSelectElement} sel    The addon's `<select>`.
     * @returns {void}
     */
    function syncAddonCardFromSelect(card, sel) {
        if (!card || !sel) { return; }
        var opt = sel.options[sel.selectedIndex];
        var isNoThanks = !sel.value || sel.value === '__no_thanks__'
            || (opt && opt.getAttribute('data-no-thanks') === '1');
        if (isNoThanks) {
            card.setAttribute('data-no-thanks', '1');
            card.setAttribute('data-variant-id', '0');
        } else {
            card.setAttribute('data-no-thanks', '0');
            card.setAttribute('data-variant-id', sel.value || '0');
        }
    }

    /*--------------------------------------------------------------
    Wrapper — orchestrates sections, validates, writes hidden inputs
    --------------------------------------------------------------*/
    function Wrapper(root) {
        this.root = root;
        this.setId = toInt(root.getAttribute('data-set-id'), 0);
        this.editMode = root.getAttribute('data-edit-mode') === '1';
        this.errorsBox = $('.rntp-errors', root);
        this.errorsList = $('.rntp-errors-list', root);
        this.hiddenHost = $('[data-role="hidden-inputs"]', root);
        this.sections = [];
        this.prefill = (typeof window !== 'undefined' && window.RentalSetsPrefill) || null;

        // Server-state sync (classic AJAX bridge).
        // We re-use the same wp-admin/admin-ajax.php endpoints classic
        // mode uses (rental_update_set_items,
        // rental_update_set_items_addon_with_variants_optional, etc.)
        // so the `_rental_set_items` postmeta — which the legacy
        // add-to-cart validator inspects — stays in sync with whatever
        // the customer has picked in the modern UI.
        this.config = (typeof window !== 'undefined' && window.RentalSetsModern) || null;
        this.pendingAjax = 0;          // outstanding AJAX requests
        this.pendingResolvers = [];    // queued submits awaiting drain
        this.lastPushedSelectable = {}; // parent_product_id → variant_id we last persisted
        this.lastPushedAddon = {};      // rental_inv_id → variant_id we last persisted
        this.totalRecalcTimer = null;   // debounce handle for price recompute
        this.bootstrapDone = false;

        // Validation visibility gate. Errors are computed on every sync()
        // (including the boot sync, which writes hidden inputs) but they
        // are NOT rendered until the customer has actually interacted with
        // the form or attempted to submit. This keeps the product page
        // pristine on first load — no "Please choose an option" before the
        // user has done anything. Flipped true by section/addon change
        // handlers and the submit guard.
        this.interacted = false;

        // requestAnimationFrame coalescing for sync()
        this.syncRafScheduled = false;

        // Last JSON-serialized payload we actually wrote into the form.
        this.lastWrittenPayloadJson = '';

        // Centralized snapshot of the configurator's current selections,
        // refreshed on every sync().
        this.state = {
            setId:        this.setId,
            rentalSetId:  (this.config && this.config.rentalSetId) || 0,
            parentQty:    1,
            sections:     {}, // keyed by section uid
            addons:       [],
            validation:   { errors: [], interacted: false },
            lastSyncedAt: 0
        };
    }

    Wrapper.prototype.boot = function () {
        var self = this;

        // Build section controllers.
        $$('.rntp-section', this.root).forEach(function (sectionEl) {
            var type = sectionEl.getAttribute('data-section-type');
            var controller;
            switch (type) {
                case 'fixed_item':   controller = new FixedSection(sectionEl); break;
                case 'dropdown':     controller = new DropdownSection(sectionEl); break;
                case 'dropdown_qty': controller = new DropdownQtySection(sectionEl); break;
                case 'multi_select': controller = new MultiSection(sectionEl); break;
                default: return;
            }
            controller.bind(function () {
                self.interacted = true;
                self.persistSectionChange(controller);
                self.requestSync();
            });
            self.sections.push(controller);
        });

        // Bind every addon dropdown to a sync re-run. Addons are PHP-
        // rendered inside the section partial as data-only DOM nodes;
        // the JS doesn't own their state beyond translating the
        // current <select>.value into a rental_add_ons[i] entry on
        // writeHiddenInputs.
        $$('[data-role="addon-select"]', this.root).forEach(function (sel) {
            var card = sel.closest ? sel.closest('[data-role="addon-card"]') : null;
            // Initial paint: mirror the server-rendered selection (which may
            // be "No Thanks" for an optional addon with no default) onto the
            // card so the first sync/summary already reflects the opt-out.
            syncAddonCardFromSelect(card, sel);

            sel.addEventListener('change', function () {
                self.interacted = true;
                // Mirror onto the parent card so every read site reads the
                // live value from one source (the data-* attributes) and
                // stays in sync across re-renders. "No Thanks" flags the
                // card opted-out so it's skipped everywhere.
                if (card) {
                    syncAddonCardFromSelect(card, sel);
                    self.persistAddonChange(card);
                    // Repaint the bolded WC-HTML price under this addon.
                    updateSelectedPriceForAddon(card, sel);
                }
                self.requestSync();
            });
        });

        // Selected-price updaters for selectable / group / dropdown-qty
        // sections. Reads `data-price` off the currently-selected
        // <option> and rebuilds the WC-style price HTML so the
        // customer always sees what their pick will charge.
        $$('[data-role="section-select"]', this.root).forEach(function (sel) {
            var applySel = function () {
                var section = sel.closest ? sel.closest('.rntp-section') : null;
                if (section) {
                    updateSelectedPriceForSection(section, sel);
                    updateSectionExternalLink(section, sel);
                    updateOptionalQtyNote(section, sel);
                }
            };
            sel.addEventListener('change', applySel);
            // Initial paint so the qty note matches the default selection.
            applySel();
        });

        // Wire the custom dropdown shell (thumbnail + name + price + qty)
        $$('.rntp-cs[data-role="custom-select"]', this.root).forEach(function (cs) {
            wireCustomSelect(cs);
        });

        // Install the cart-form submit guard BEFORE first sync so the
        // very first add-to-cart can't outrun the bootstrap AJAX chain.
        this.installSubmitGuard();

        // Move the product-page marker + edit key INTO the cart form
        this.relocateFormFields();

        // Apply prefill, in priority order.
        this.applyPrefill();

        // First render — populate hidden inputs and run validator once.
        this.sync();

        // Fire the classic-style page-load AJAX chain. Runs async;
        // submit-guard above blocks form submission until it drains.
        this.bootstrapServerState();

        // Race-loss safety: the legacy `rental-sets.js` file fires a
        // one-shot AJAX (`rental_calculate_set_price_based_on_items_price`)
        // that ALSO writes into `.entry-price-wrap`. Now the rental_calculate_set_price_based_on_items_price 
        // endpoint is retired and is considered deprecated.
        var self = this;
        setTimeout(function () {
            if (self && typeof self.updateSummary === 'function') {
                try { self.updateSummary(); } catch (e) {}
            }
        }, 700);

        // Title-truncation detection. Reveal the (i) affordance only
        // on titles whose text actually overflows. Runs after layout
        // settles and re-runs on resize (debounced) since available width
        // changes the truncation state.
        this.detectTitleTruncation();
        if (!this._titleResizeBound) {
            this._titleResizeBound = true;
            var rzTimer = null;
            window.addEventListener('resize', function () {
                if (rzTimer) { clearTimeout(rzTimer); }
                rzTimer = setTimeout(function () { self.detectTitleTruncation(); }, 150);
            });
        }
    };

    /**
     * Toggle `.rntp-title--truncated` on every section title whose text is
     * clipped (scrollWidth > clientWidth). CSS shows the sibling (i) icon
     * only when this class is present, so short titles stay clean while
     * long ones get the hover-for-full-title affordance.
     */
    Wrapper.prototype.detectTitleTruncation = function () {
        $$('.rntp-section-title', this.root).forEach(function (titleEl) {
            // +1 px slack absorbs sub-pixel rounding in some browsers.
            var truncated = titleEl.scrollWidth > ( titleEl.clientWidth + 1 );
            titleEl.classList.toggle('rntp-title--truncated', truncated);
        });
    };

    /**
     * Is there a section controller under this uid?
     *
     * @param {string} uid
     * @returns {boolean}
     */
    Wrapper.prototype.hasSection = function (uid) {
        if (!uid) { return false; }
        for (var i = 0; i < this.sections.length; i++) {
            if (this.sections[i].uid === uid) { return true; }
        }
        return false;
    };

    /**
     * Find the section that renders a given product. Used to route cart
     * children back to their section when the child carries no uid of its
     * own. A variant match wins over a bare product match, so a product
     * that appears in more than one section lands on the right one.
     *
     * Addon cards are skipped — they are prefilled separately and are not
     * section picks.
     *
     * @param {number} productId
     * @param {number} variantId
     * @returns {object|null} The section controller, or null.
     */
    Wrapper.prototype.findSectionForProduct = function (productId, variantId) {
        if (!productId) { return null; }
        var exact = null;
        var loose = null;
        this.sections.forEach(function (section) {
            if (exact || !section.root) { return; }
            var nodes = $$('[data-product-id="' + productId + '"]', section.root);
            for (var i = 0; i < nodes.length; i++) {
                if (nodes[i].getAttribute('data-role') === 'addon-card') { continue; }
                if (variantId && toInt(nodes[i].getAttribute('data-variant-id'), 0) === variantId) {
                    exact = section;
                    return;
                }
                if (!loose) { loose = section; }
            }
        });
        return exact || loose;
    };

    Wrapper.prototype.applyPrefill = function () {
        if (!this.prefill) { return; }
        var self = this;
        var failed = this.prefill.failed_selections;
        var cart = this.prefill.cart_prefill;
        var picksByUid = {};

        function indexFromShape(shape) {
            // Modern shape: { rental_set_selections[<uid>] : { selections: { <item_uid>: {...} } } }
            if (!shape || typeof shape !== 'object') { return; }
            var ms = shape.modern_selections || shape.rental_set_selections || shape.selections || null;
            if (!ms) { return; }
            for (var uid in ms) {
                if (!Object.prototype.hasOwnProperty.call(ms, uid)) { continue; }
                var bucket = ms[uid];
                if (!bucket || !bucket.selections) { continue; }
                var picks = [];
                for (var iu in bucket.selections) {
                    if (!Object.prototype.hasOwnProperty.call(bucket.selections, iu)) { continue; }
                    var s = bucket.selections[iu] || {};
                    picks.push({
                        itemUid: iu,
                        productId: toInt(s.product_id, 0),
                        variantId: toInt(s.variant_id, 0),
                        invId: toInt(s.inv_id, 0),
                        quantity: toInt(s.quantity, 1),
                        price: s.price || ''
                    });
                }
                picksByUid[uid] = picks;
            }
        }

        function indexFromCartChildren(children) {
            // Cart-prefill shape: array of cart children. A child is one of
            // three kinds, distinguished by which meta keys it carries:
            //
            //   1. Composite-group child → has group_meta.rental_set_group_uid.
            //      Keyed by the group's uid so the matching section's
            //      applyPrefill picks it up.
            //
            //   2. Set-item addon → has parent_set_item_product_id (non-zero).
            //      Addons aren't section controllers; they get their own
            //      DOM-level prefill pass in applyAddonPrefill() below.
            //      We skip them here so they don't accidentally collide
            //      with selectable/simple matching.
            //
            //   3. Top-level set child (selectable / simple) → no group
            //      meta, no parent_set_item_product_id. Keyed by the
            //      section's synthetic uid `sel-{rental_set_id}-{product_id}`
            //      (matches Rules_Exporter::selectable_items_payload and
            //      the section presenter's synthetic_uid_for_selectable).
            if (!Array.isArray(children)) { return; }
            // A grouped child's cart line quantity is `parent_qty × units`
            // (the per-card UNITS the customer picked × the set quantity —
            // per_set_quantity is stamped as the picked units, NOT the
            // group_quantity). To restore the card stepper we divide the
            // line quantity back by parent_qty only. (group_quantity is the
            // stepper default + order/quote metadata, NOT a multiplier on the
            // line qty, so it must NOT be in this divisor — dividing by it
            // would restore a 4-unit pick as 1.)
            var parentQty = Math.max(1, toInt((cart && cart.parent_qty), 1));
            children.forEach(function (child) {
                var meta     = child.group_meta || {};
                var groupUid = meta.rental_set_group_uid || '';
                if (groupUid) {
                    if (!picksByUid[groupUid]) { picksByUid[groupUid] = []; }
                    var lineQty  = toInt(child.quantity, 1);
                    var unitQty  = parentQty > 0 ? Math.round(lineQty / parentQty) : lineQty;
                    if (unitQty < 1) { unitQty = 1; }
                    picksByUid[groupUid].push({
                        itemUid: meta.rental_set_group_item_uid || '',
                        productId: toInt(child.product_id, 0),
                        variantId: toInt(child.variation_id, 0),
                        invId: 0,
                        quantity: unitQty,
                        price: ''
                    });
                    return;
                }
                if (toInt(child.parent_set_item_product_id, 0) > 0) {
                    return; // addon — handled in applyAddonPrefill
                }
                var rentalSetId = toInt(child.rental_set_id, 0);
                var productId   = toInt(child.product_id, 0);
                var variantId   = toInt(child.variation_id, 0);
                if (!productId) { return; }

                // Only a SELECTABLE section can be keyed synthetically. A
                // simple section keys on its own uid, which no cart field
                // carries — so fall back to the section that renders this
                // product. Getting this right is what lets the caller treat
                // "absent from the cart" as "declined": an unrecognised
                // child would otherwise look like a declined section.
                var uidKey = rentalSetId ? ('sel-' + rentalSetId + '-' + productId) : '';
                if (!uidKey || !self.hasSection(uidKey)) {
                    var owner = self.findSectionForProduct(productId, variantId);
                    uidKey = owner ? owner.uid : '';
                }
                if (!uidKey) { return; }

                if (!picksByUid[uidKey]) { picksByUid[uidKey] = []; }
                picksByUid[uidKey].push({
                    itemUid: '',
                    productId: productId,
                    variantId: variantId,
                    invId: 0,
                    quantity: toInt(child.quantity, 1),
                    price: ''
                });
            });
        }

        var root = this.root;
        function applyAddonPrefill(children) {
            // Apply each cart-child addon's chosen variant onto the matching
            // `[data-role="addon-card"]` 
            if (!Array.isArray(children)) { return; }
            children.forEach(function (child) {
                var parentPid = toInt(child.parent_set_item_product_id, 0);
                if (!parentPid) { return; }
                var addonPid = toInt(child.product_id, 0);
                var addonVid = toInt(child.variation_id, 0);
                if (!addonPid || !addonVid) { return; }

                var card = root.querySelector(
                    '[data-role="addon-card"][data-product-id="' + addonPid +
                    '"][data-parent-product-id="' + parentPid + '"]'
                );
                if (!card) { return; }

                var sel = card.querySelector('[data-role="addon-select"]');
                if (sel) {
                    sel.value = String(addonVid);
                    // Restore both data-variant-id AND clear data-no-thanks so
                    // an addon the customer actually had in the cart isn't left
                    // flagged opted-out from the boot default.
                    syncAddonCardFromSelect(card, sel);
                } else {
                    card.setAttribute('data-variant-id', String(addonVid));
                    card.setAttribute('data-no-thanks', '0');
                }
                var cs = card.querySelector('[data-role="custom-select"]');
                if (cs && typeof cs._rntpResync === 'function') {
                    cs._rntpResync();
                }
                if (sel && typeof updateSelectedPriceForAddon === 'function') {
                    updateSelectedPriceForAddon(card, sel);
                }
            });
        }

        // failed_selections wins over cart_prefill (per the validator's
        // "abort but keep selections" contract).
        if (failed) {
            indexFromShape(failed);
        }
        if (!Object.keys(picksByUid).length && cart && cart.children) {
            indexFromCartChildren(cart.children);
        }

        // Hand picks to each controller.
        //
        // A failed submission and an existing cart line are both
        // authoritative snapshots of what the customer chose. A declined
        // optional section emits no inputs and creates no cart child, so it
        // is absent from `picksByUid` — restoring it to the PHP default
        // (which may be a positive option) would show the customer an item
        // they had skipped and re-add it on the next submit.
        //
        // So any optional section missing from the snapshot is actively
        // re-declined. A cart snapshot only counts once it resolved at
        // least one section: an unmatched set of children means the
        // matching failed, and declining the whole set on that basis would
        // be worse than falling back to the defaults.
        var restoring = !!failed
            || ( !!cart && Object.keys(picksByUid).length > 0 );
        this.sections.forEach(function (section) {
            if (picksByUid[section.uid]) {
                section.applyPrefill(picksByUid[section.uid]);
            } else if (restoring
                && !section.required
                && typeof section.selectNoThanks === 'function') {
                section.selectNoThanks();
            }
        });

        // Apply addon variants directly (no controllers in self.sections).
        if (cart && Array.isArray(cart.children)) {
            applyAddonPrefill(cart.children);
        }

        // Final visual resync: section controllers set <select>.value
        // without dispatching 'change', so the shell, the price line, the
        // product link and the qty note are all still showing the page's
        // initial PHP-rendered selection. Repaint them from the
        // (possibly just-mutated) native select.
        $$('[data-role="custom-select"]', this.root).forEach(function (cs) {
            if (typeof cs._rntpResync === 'function') {
                cs._rntpResync();
            }
        });
        this.sections.forEach(function (section) {
            var sel = section.root ? $('[data-role="section-select"]', section.root) : null;
            if (!sel) { return; }
            updateSelectedPriceForSection(section.root, sel);
            updateSectionExternalLink(section.root, sel);
            updateOptionalQtyNote(section.root, sel);
        });
    };

    /**
     * Build the per-section pick map consumed by validate() and
     * writeHiddenInputs().
     */
    Wrapper.prototype.buildPickMap = function () {
        var pickMap = {};
        this.sections.forEach(function (s) {
            pickMap[s.uid] = {
                origin: s.origin,
                groupId: s.groupId,
                required: s.required,
                groupQuantity: s.groupQuantity,
                parentProductId: s.parentProductId || 0,
                root: s.root,
                picks: s.getSelections()
            };
        });
        return pickMap;
    };

    /**
     * Schedule a sync() to run on the next animation frame, coalescing
     * any other requestSync() calls made in the same frame into a single
     * rebuild.
     */
    Wrapper.prototype.requestSync = function () {
        if (this.syncRafScheduled) { return; }
        this.syncRafScheduled = true;
        var self = this;
        var schedule = (typeof window !== 'undefined' && typeof window.requestAnimationFrame === 'function')
            ? function (cb) { return window.requestAnimationFrame(cb); }
            : function (cb) { return setTimeout(cb, 16); };
        this._syncRafId = schedule(function () {
            self.syncRafScheduled = false;
            self._syncRafId = null;
            self.sync();
        });
    };

    /**
     * Force any pending requestSync() to flush NOW and synchronously
     * write the current state into the form.
     */
    Wrapper.prototype.flushSync = function () {
        if (this._syncRafId
            && typeof window !== 'undefined'
            && typeof window.cancelAnimationFrame === 'function') {
            window.cancelAnimationFrame(this._syncRafId);
        }
        this._syncRafId = null;
        this.syncRafScheduled = false;
        this.sync();
    };

    /**
     * Aggregate, validate, write hidden inputs.
     */
    Wrapper.prototype.sync = function () {
        var pickMap = this.buildPickMap();

        // Validate via the client mirror.
        var errors = this.validate(pickMap);
        this.applyErrorVisuals(errors, pickMap);

        // Refresh the central state snapshot. Strictly additive — every
        // existing flow keeps reading the live DOM / pickMap as before;
        // this is the inspectable single-source view the normalized JSON
        // payload consume.
        this.refreshState(pickMap, errors);

        // Write hidden inputs every time, errors or no errors. The
        // server validator is the source of truth — letting the form
        // submit with a known-bad payload is fine; the server will
        // reject and persist for re-prefill.
        this.writeHiddenInputs(pickMap);
    };

    /**
     * Rebuild `this.state` from the live pickMap + addon cards + cart
     * form. Pure — never mutates the DOM, never triggers events. Always
     * runs after validate() so the latest `errors` array is captured.
     *
     * @param {Object} pickMap  The Wrapper.buildPickMap() snapshot.
     * @param {Array}  errors   Output of validate(pickMap).
     * @returns {void}
     */
    Wrapper.prototype.refreshState = function (pickMap, errors) {
        var sections = {};
        for (var uid in pickMap) {
            if (!Object.prototype.hasOwnProperty.call(pickMap, uid)) { continue; }
            var entry = pickMap[uid];
            sections[uid] = {
                uid:               uid,
                origin:            entry.origin,
                groupId:           entry.groupId,
                groupQuantity:     entry.groupQuantity,
                required:          entry.required,
                parentProductId:   entry.parentProductId,
                picks:             entry.picks.map(function (p) {
                    return {
                        itemUid:    p.itemUid || '',
                        productId:  toInt(p.productId, 0),
                        variantId:  toInt(p.variantId, 0),
                        invId:      toInt(p.invId, 0),
                        quantity:   toInt(p.quantity, 0),
                        price:      p.price || ''
                    };
                })
            };
        }

        var addons = [];
        // "No Thanks" cascade. Build the set of section uids whose
        // host customer declined ("No Thanks, I don't need this") so
        // their nested addon-cards skip the payload too.
        var noThanksSectionUids = {};
        for (var skipUid in pickMap) {
            if (!Object.prototype.hasOwnProperty.call(pickMap, skipUid)) { continue; }
            var skipEntry = pickMap[skipUid];
            if (skipEntry && skipEntry.required === false
                && skipEntry.picks && skipEntry.picks.length === 0) {
                noThanksSectionUids[skipUid] = true;
            }
        }
        $$('[data-role="addon-card"]', this.root).forEach(function (card) {
            // Opted-out optional addon ("No Thanks") — excluded from state.
            if (card.getAttribute('data-no-thanks') === '1') { return; }
            // Cascade: drop the addon if its enclosing section is in
            // No-Thanks state.
            var hostSection = card.closest ? card.closest('.rntp-section') : null;
            if (hostSection) {
                var hostUid = hostSection.getAttribute('data-section-uid') || '';
                if (hostUid && noThanksSectionUids[hostUid]) {
                    return;
                }
            }
            addons.push({
                productId:               toInt(card.getAttribute('data-product-id'), 0),
                variantId:               toInt(card.getAttribute('data-variant-id'), 0),
                invId:                   toInt(card.getAttribute('data-rental-inv-id'), 0),
                quantity:                toInt(card.getAttribute('data-quantity'), 1),
                required:                card.getAttribute('data-required') === '1',
                parentSetItemProductId:  toInt(card.getAttribute('data-parent-product-id'), 0)
            });
        });

        // Parent qty — read from the cart form's quantity input when the
        // shell has been wired into a form; defaults to 1 elsewhere
        // (single-product-page first paint, before the customer touches
        // the qty control).
        var parentQty = 1;
        if (this.cartForm) {
            var qtyInput = this.cartForm.querySelector('input.qty, input[name="quantity"]');
            if (qtyInput) {
                parentQty = Math.max(1, toInt(qtyInput.value, 1));
            }
        }

        this.state.parentQty    = parentQty;
        this.state.sections     = sections;
        this.state.addons       = addons;
        this.state.validation   = {
            errors:     (errors || []).map(function (e) { return { uid: e.uid, message: e.message }; }),
            interacted: !!this.interacted
        };
        this.state.lastSyncedAt = Date.now();

        // Roll up the live Summary (Total Price + Selected count) so
        // the panel beneath the configurator stays in lockstep with
        // every change the customer makes. See updateSummary() for the
        // sum formula.
        this.updateSummary();
    };

    /**
     * Refresh the bottom Summary panel from the current pickMap +
     * addon state. Math:
     *
     *   total  = Σ_section( Σ_pick(  pick.price × pick.quantity ) )
     *          + Σ_addon( addon.price × addon.quantity )
     *          ; then × parentQty
     *
     *   count  = Σ_section( Σ_pick(  pick.quantity ) )
     *          + Σ_addon( addon.quantity )
     *          ; then × parentQty
     *
     * Per-pick prices come from the same sources the cart engine reads
     * (group_price → in-set stamp → live variant chain), so the
     * Summary number matches what the cart will subtotal once the
     * customer adds the set. Duration multiplier is NOT applied here —
     * the configurator shows the per-rental-day rollup; the cart
     * surfaces the duration-multiplied total separately.
     */
    Wrapper.prototype.updateSummary = function () {
        var totalAmount = 0;
        var totalCount  = 0;

        this.sections.forEach(function (sec) {
            var sels = sec.getSelections ? sec.getSelections() : [];
            (sels || []).forEach(function (pick) {
                if (!pick) { return; }
                var price = parseFloat(pick.price);
                if (!isFinite(price)) { price = 0; }
                var qty = parseInt(pick.quantity, 10);
                if (!isFinite(qty) || qty < 0) { qty = 0; }
                totalAmount += price * qty;
                totalCount  += qty;
            });
        });

        // Addons: read price from selected variant <option data-price>
        // for variable addons, or from the card's own data-price for
        // non-variant addons (the admin-configured custom add-on price).
        // Skip addons whose enclosing section is in No-Thanks state —
        // their parent section returned empty picks so the addons must
        // not contribute to the summary either (mirrors the cascade in
        // refreshState that strips them from `state.addons`).
        var noThanksSummary = {};
        this.sections.forEach(function (s) {
            if (!s || s.required === true) { return; }
            var sels = s.getSelections ? s.getSelections() : [];
            if (sels && sels.length === 0) {
                noThanksSummary[s.uid] = true;
            }
        });
        $$('[data-role="addon-card"]', this.root).forEach(function (card) {
            // Opted-out optional addon ("No Thanks") — no price, no count.
            if (card.getAttribute('data-no-thanks') === '1') { return; }
            var qty = toInt(card.getAttribute('data-quantity'), 0);
            if (qty <= 0) { return; }
            var hostSection = card.closest ? card.closest('.rntp-section') : null;
            if (hostSection) {
                var hostUid = hostSection.getAttribute('data-section-uid') || '';
                if (hostUid && noThanksSummary[hostUid]) { return; }
            }
            var price = 0;
            var sel = card.querySelector('[data-role="addon-select"]');
            if (sel && sel.options && sel.options.length) {
                var opt = sel.options[sel.selectedIndex];
                if (opt) {
                    price = parseFloat(opt.getAttribute('data-price'));
                    if (!isFinite(price)) { price = 0; }
                }
            }
            if (price <= 0) {
                // Non-variant addon — read the in-set custom price the
                // partial stamped on the card.
                price = parseFloat(card.getAttribute('data-price'));
                if (!isFinite(price)) { price = 0; }
            }
            totalAmount += price * qty;
            totalCount  += qty;
        });

        // Parent base price. A fixed-bundle set bills its own fixed price
        // ON TOP of any billing children; an aggregated set carries the
        // whole total in the children (parent base = 0). This mirrors the
        // cart engine's parent_unit_price so the configurator total equals
        // what the cart will charge. Without it the configurator summed
        // only the children — a fixed-price set showed its included items'
        // prices instead of its fixed bundle price.
        var parentBase = parseFloat(this.config && this.config.parentBasePrice);
        if (!isFinite(parentBase)) { parentBase = 0; }

        // Scale by parent set quantity. The parent base AND each child
        // belong to ONE set unit; bumping set qty multiplies the total.
        var parentQty = this.state.parentQty || 1;
        totalAmount = (totalAmount + parentBase) * parentQty;
        totalCount  *= parentQty;

        var amountStr = (Math.round(totalAmount * 100) / 100).toFixed(2);

        var amountEl = this.root.querySelector('[data-role="summary-amount"]');
        var countEl  = this.root.querySelector('[data-role="summary-count"]');
        if (amountEl) {
            amountEl.textContent = amountStr;
        }
        if (countEl) {
            countEl.textContent = String(totalCount);
        }

        // Mirror the same number into the TOP total at the entry
        // summary (`.entry-price-wrap` — rendered by the theme's
        // product-summary template, originally driven by the legacy
        // AJAX `rental_calculate_set_price_based_on_items_price`).
        // Two displays, ONE source: the JS computation here. They can
        // never disagree because they read the same closure-local
        // `totalAmount` and write in the same tick.
        this.writeTopTotal(amountStr);
    };

    /**
     * Format `amount` using the active WooCommerce currency conventions
     * and write into every `.entry-price-wrap` on the page. The HTML
     * shape mirrors `wc_price()` so theme styling (font-size, color
     * accents on .woocommerce-Price-amount, etc.) stays intact.
     *
     * @param {string} amountStr  Already-formatted "1234.56" string.
     */
    Wrapper.prototype.writeTopTotal = function (amountStr) {
        // Prices hidden — leave whatever the theme rendered (an empty
        // price wrap) untouched instead of painting a total into it.
        if (pricesHidden()) { return; }

        // Currency symbol: pull from the partial-injected currency
        // span the summary already renders so JS doesn't need its own
        // localized symbol. Falls back to '$' if absent (defensive —
        // the partial always sets it).
        var currencyEl = this.root.querySelector('.rntp-summary-currency');
        var currency = (currencyEl && currencyEl.textContent) || '$';

        // Tiny HTML escape — defence in depth even though both inputs
        // (the WC currency symbol and the .toFixed(2) amount string)
        // are controlled values that should never contain markup.
        var esc = function (s) {
            return String(s)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        };

        var html =
            '<span class="woocommerce-Price-amount amount">' +
                '<bdi>' +
                    '<span class="woocommerce-Price-currencySymbol">' + esc(currency) + '</span>' +
                    esc(amountStr) +
                '</bdi>' +
            '</span>';

        var wraps = document.querySelectorAll('.entry-price-wrap');
        for (var i = 0; i < wraps.length; i++) {
            wraps[i].innerHTML = html;
        }
    };

    /**
     * Project `this.state` into the canonical wire shape (see
     * docs/sets/README.md). Keeps PII / DOM nodes out of the payload —
     * only ids, prices, quantities. Returned object is safe to
     * JSON.stringify and survive a session round-trip.
     *
     * @returns {Object}
     */
    Wrapper.prototype.serializeStatePayload = function () {
        var s = this.state;
        var groups      = [];
        var selectables = [];
        var simples     = [];
        for (var uid in s.sections) {
            if (!Object.prototype.hasOwnProperty.call(s.sections, uid)) { continue; }
            var sec = s.sections[uid];
            var bucket = {
                uid:           sec.uid,
                origin:        sec.origin,
                group_id:      sec.groupId || 0,
                required:      !!sec.required,
                group_quantity: sec.groupQuantity || 1,
                selections:    sec.picks
            };
            if (sec.origin === 'group') {
                groups.push(bucket);
            } else if (sec.origin === 'simple') {
                simples.push(bucket);
            } else {
                selectables.push(bucket);
            }
        }
        return {
            schema_version: 2,
            set_id:         s.setId,
            rental_set_id:  s.rentalSetId,
            parent_qty:     s.parentQty,
            groups:         groups,
            selectables:    selectables,
            simples:        simples,
            addons:         s.addons.map(function (a) {
                return {
                    product_id:                  a.productId,
                    variant_id:                  a.variantId,
                    inv_id:                      a.invId,
                    quantity:                    a.quantity,
                    required:                    a.required,
                    parent_set_item_product_id:  a.parentSetItemProductId
                };
            })
        };
    };

    /**
     * Client-mirror validation. Mirrors the server rule set imperfectly
     * — by design, the server is authoritative. The mirror exists to
     * surface the obvious problems before the round-trip:
     *
     *   - group_required (required group, no picks)
     *   - group_min_qty / group_max_qty (multi-select bounds)
     *   - group_multiple_selection (single-select with >1 picks)
     *
     * Unknown groups (not in rules) get no rules-driven errors; they
     * still pass through to hidden inputs and round-trip to the server.
     */
    Wrapper.prototype.validate = function (pickMap) {
        var errors = [];
        var rules = (this.prefill && this.prefill.rules) || null;
        if (!rules) { return errors; }

        var byUid = {};
        (rules.groups || []).forEach(function (g) { byUid[g.uid] = g; });
        (rules.selectable_items || []).forEach(function (s) { byUid[s.synth_uid] = {
            uid: s.synth_uid,
            name: '',
            required: !!s.requires_choice,
            multiple_selection: false,
            // Selectable items are single-pick. The chosen option carries
            // its OWN bundle quantity (data-quantity) — that is never a
            // "max selectable options" limit, so there is no unit min/max
            // here. (The old quantity_max:1 conflated the two and produced
            // "pick at most 1, you've picked 6" on a 6-qty dropdown.)
            quantity_min: 0,
            quantity_max: 0
        }; });

        var dict = (rules.error_codes) || {};

        for (var uid in pickMap) {
            if (!Object.prototype.hasOwnProperty.call(pickMap, uid)) { continue; }
            var entry = pickMap[uid];
            var rule = byUid[uid];
            if (!rule) { continue; }

            // Human-readable label for the message. Prefer the rule's
            // exported name; fall back to the section's rendered title
            // (covers selectable items / addons that export an empty
            // name) so we never emit 'Please choose an option for ""'.
            var label = (rule.name && String(rule.name).trim())
                ? String(rule.name)
                : sectionLabel(entry.root);

            var pickCount = entry.picks.reduce(function (sum, p) {
                return sum + Math.max(0, toInt(p.quantity, 0));
            }, 0);

            // group_required — applies to every section.
            if (rule.required && pickCount === 0) {
                errors.push({
                    uid: uid,
                    message: format(dict.group_required || 'Please choose an option for "%s" (required).', [label])
                });
                continue;
            }

            // Empty section → no further checks. An OPTIONAL section the
            // customer left empty (or skipped via "No Thanks") is always
            // valid — min/max bounds only apply once the customer has
            // begun picking.
            if (pickCount === 0) {
                continue;
            }

            // Selection-COUNT and unit-QUANTITY are different concepts and
            // must be validated against different sources:
            //
            //   - single-select (multiple_selection !== true): at most ONE
            //     option may be chosen. The chosen option's own quantity
            //     (data-quantity) is the bundle size and is NEVER bounded
            //     here — a dropdown configured at qty 6 is valid. Only the
            //     number of distinct picks is checked.
            //   - multi-select (multiple_selection === true): the customer
            //     decides HOW MANY units, so the unit total is what's
            //     bounded by quantity_min / quantity_max.
            if (rule.multiple_selection === true) {
                if (rule.quantity_min && pickCount < rule.quantity_min) {
                    errors.push({
                        uid: uid,
                        message: format(dict.group_min_qty || 'Please pick at least %2$d for "%1$s" (you\'ve picked %3$d).', [label, rule.quantity_min, pickCount])
                    });
                    continue;
                }
                if (rule.quantity_max && pickCount > rule.quantity_max) {
                    errors.push({
                        uid: uid,
                        message: format(dict.group_max_qty || 'Please pick at most %2$d for "%1$s" (you\'ve picked %3$d).', [label, rule.quantity_max, pickCount])
                    });
                    continue;
                }
            } else {
                var distinct = entry.picks.filter(function (p) { return toInt(p.quantity, 0) > 0; }).length;
                if (distinct > 1) {
                    errors.push({
                        uid: uid,
                        message: format(dict.group_multiple_selection || 'Please choose only one option for "%s".', [label])
                    });
                    continue;
                }
            }
        }
        return errors;
    };

    Wrapper.prototype.applyErrorVisuals = function (errors, pickMap) {
        // Clear all section errors first.
        this.sections.forEach(function (s) { s.clearError(); });

        // Pristine until interaction: never render validation on first
        // load. Also collapse everything when there are no errors (e.g.
        // a previously-invalid selection just became valid).
        if (!this.interacted || !errors.length) {
            if (this.errorsBox) { this.errorsBox.hidden = true; }
            this.root.removeAttribute('data-has-errors');
            if (this.errorsList) { this.errorsList.innerHTML = ''; }
            return;
        }

        // Per-section inline errors.
        var byUid = {};
        errors.forEach(function (err) {
            byUid[err.uid] = (byUid[err.uid] || []).concat(err.message);
        });
        this.sections.forEach(function (s) {
            if (byUid[s.uid] && byUid[s.uid].length) {
                s.showError(byUid[s.uid][0]);
            }
        });

        // Top-level rollup.
        if (this.errorsBox && this.errorsList) {
            this.errorsList.innerHTML = '';
            errors.forEach(function (err) {
                var li = el('li');
                li.textContent = err.message;
                this.errorsList.appendChild(li);
            }, this);
            this.errorsBox.hidden = false;
        }
        this.root.setAttribute('data-has-errors', '1');
    };

    /**
     * Bring the first errored section (or the rollup box) into view after
     * a blocked submit, so feedback isn't off-screen below the fold.
     */
    Wrapper.prototype.scrollToFirstError = function () {
        var target = this.root.querySelector('.rntp-section[data-has-error="1"]')
            || (this.errorsBox && !this.errorsBox.hidden ? this.errorsBox : null);
        if (target && typeof target.scrollIntoView === 'function') {
            target.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    };

    /**
     * Rewrite the hidden-input host with both POST shapes:
     *
     *   rental_set_selections[<group_uid>][group_id]
     *   rental_set_selections[<group_uid>][selections][<item_uid>][...]
     *   rental_add_ons[<i>][...]
     *
     * Idempotent — full replace on every call.
     */
    Wrapper.prototype.writeHiddenInputs = function (pickMap) {
        if (!this.hiddenHost) { return; }

        // Re-resolve the form-internal host every pass. Boot-time
        // relocation only works if the cart form was already in the DOM
        // and stayed there — a date gate that swaps the add-to-cart
        // button in later, or a theme that re-renders the form, leaves
        // `formHost` null or detached. Without a host every input below
        // lands outside the form and is silently never submitted, which
        // reads server-side as "the customer chose nothing".
        if (!this.formHost || !this.formHost.parentNode
            || !document.body.contains(this.formHost)) {
            this.formHost = null;
            this.relocateFormFields();
        }

        // Delta skip — if the canonical state payload is byte-identical
        // to what we wrote last time, the existing hidden inputs are
        // already correct and rebuilding them would be pure churn. Cheap
        // string compare guarded by try/catch (JSON failure is treated as
        // "not equal" so we always write on the safe side).
        //
        // The cache tracks STATE, so it must never be trusted when the
        // destination has no inputs in it: a host that is missing, or
        // freshly created for a different form, needs a full write even
        // though nothing the customer picked has changed.
        var newPayloadJson = '';
        try {
            newPayloadJson = JSON.stringify(this.serializeStatePayload());
        } catch (e) { /* leave empty → forces a rebuild this pass */ }
        var hostReady = !!this.formHost && !!this.formHost.firstChild;
        if (hostReady && newPayloadJson !== '' && newPayloadJson === this.lastWrittenPayloadJson) {
            return;
        }
        this.lastWrittenPayloadJson = this.formHost ? newPayloadJson : '';

        this.hiddenHost.innerHTML = '';
        // The form-internal rental_add_ons host (when relocated into the
        // cart form) is repopulated in place each call.
        if (this.formHost) { this.formHost.innerHTML = ''; }
        var self = this;
        var addonIndex = 0;
        var rules = (this.prefill && this.prefill.rules) || null;
        var rulesByUid = {};
        if (rules && Array.isArray(rules.groups)) {
            rules.groups.forEach(function (g) { rulesByUid[g.uid] = g; });
        }

        for (var uid in pickMap) {
            if (!Object.prototype.hasOwnProperty.call(pickMap, uid)) { continue; }
            var entry = pickMap[uid];
            if (!entry.picks.length) { continue; }

            // ── Modern shape ─────────────────────────────────────
            this.appendInput('rental_set_selections[' + uid + '][group_id]', entry.groupId || 0);

            entry.picks.forEach(function (pick) {
                var key = pick.itemUid || ('opt-' + pick.productId + '-' + pick.variantId);
                var base = 'rental_set_selections[' + uid + '][selections][' + key + ']';
                this.appendInput(base + '[inv_id]', pick.invId);
                this.appendInput(base + '[product_id]', pick.productId);
                this.appendInput(base + '[variant_id]', pick.variantId);
                this.appendInput(base + '[quantity]', pick.quantity);
                this.appendInput(base + '[price]', pick.price || '');
            }, this);

            // ── Legacy shape ─────────────────────────────────────
            // Selectable origin maps to the legacy parent_set_item_*
            // pattern, NOT to grouped_child. The cart-handler treats
            // grouped_child as the marker for entity-3 children only.
            //
            // Each row carries the five fields the legacy
            // rental_add_product_to_cart price resolver consults
            // (see functions.php:4178-4195):
            //
            //   set_id          → Rentopian rental set id (entry into the
            //                     "set item" branch). Resolved server-side
            //                     and shipped in RentalSetsModern.rentalSetId.
            //   parent_set_id   → WP set post id (= RentalSetsModern.setId).
            //   inherit_price   → '1' to force the classic addon branch
            //                     to look up `_price` postmeta of the
            //                     variant — the correct WC variation
            //                     price — instead of falling back to
            //                     $add_on['price'] (which we set too,
            //                     as a defence-in-depth in case the
            //                     variation's _price is empty).
            //   price           → snapshot of the variant's WC price at
            //                     selection time.
            //   rental_*_id     → the Rentopian-side ids so order
            //                     creation can map back upstream.
            var cfg = self.config || {};
            var wpSetId = toInt(cfg.setId, 0);
            var rentalSetId = toInt(cfg.rentalSetId, 0);
            entry.picks.forEach(function (pick) {
                var addonBase = 'rental_add_ons[' + addonIndex + ']';
                this.appendInput(addonBase + '[product_id]', pick.productId);
                this.appendInput(addonBase + '[variant_id]', pick.variantId);
                this.appendInput(addonBase + '[inv_id]', pick.invId);
                this.appendInput(addonBase + '[quantity]', pick.quantity);
                this.appendInput(addonBase + '[required]', entry.required ? 1 : 0);
                // Price resolution hints — see comment above.
                this.appendInput(addonBase + '[set_id]', rentalSetId);
                this.appendInput(addonBase + '[parent_set_id]', wpSetId);
                this.appendInput(addonBase + '[inherit_price]', 1);
                this.appendInput(addonBase + '[price]', pick.price || 0);
                this.appendInput(addonBase + '[rental_product_id]', pick.rentalProductId || 0);
                this.appendInput(addonBase + '[rental_variant_id]', pick.rentalVariantId || 0);

                if (entry.origin === 'group') {
                    var rule = rulesByUid[uid] || {};
                    this.appendInput(addonBase + '[item_type]', 'grouped_child');
                    this.appendInput(addonBase + '[rental_set_group_id]', entry.groupId || 0);
                    this.appendInput(addonBase + '[rental_set_group_uid]', uid);
                    this.appendInput(addonBase + '[rental_set_group_item_uid]', pick.itemUid || '');
                    this.appendInput(addonBase + '[rental_set_group_required]', entry.required ? 1 : 0);
                    this.appendInput(addonBase + '[rental_set_group_multiple_selection]', rule.multiple_selection ? 1 : 0);
                    this.appendInput(addonBase + '[rental_set_group_qty]', entry.groupQuantity || 1);
                    this.appendInput(addonBase + '[rental_set_group_name]', rule.name || '');
                    this.appendInput(addonBase + '[rental_set_group_price]', (rule.group_price === null || typeof rule.group_price === 'undefined') ? '' : rule.group_price);
                } else if (entry.origin === 'selectable') {
                    // Mirrors the classic "parent set item product id"
                    // hint that rental_validate_cart_item / Cart_Handler
                    // already understand from the legacy form.
                    this.appendInput(addonBase + '[parent_set_item_product_id]', entry.parentProductId || 0);
                }
                // 'simple' origin: nothing extra — legacy code finds it
                // via product_id alone.
                addonIndex++;
            }, this);

            // ── Addons attached to the section's parent set item ───
            // Classic mode submits each addon as a separate
            // rental_add_ons[i] row; the legacy validator (the one
            // that emits "Please, choose a variation from the following
            // addons:") iterates that array looking for matching
            // addon entries. We mirror that shape per addon-card the
            // partial rendered for this section.
            //
            // Addon rows DO NOT carry `set_id` — the legacy code uses
            // its presence as the "set item vs product addon" branch
            // discriminator. Addons go through the product-addon path
            // which honours `inherit_price` and resolves `_price` from
            // the variant postmeta, which is what we want.
            if (entry.root) {
                var addonCards = $$('[data-role="addon-card"]', entry.root);
                for (var ai = 0; ai < addonCards.length; ai++) {
                    var card = addonCards[ai];
                    // Opted-out optional addon ("No Thanks") — not submitted.
                    if (card.getAttribute('data-no-thanks') === '1') { continue; }
                    var addonProductId = toInt(card.getAttribute('data-product-id'), 0);
                    if (!addonProductId) { continue; }
                    var addonVariantId = toInt(card.getAttribute('data-variant-id'), 0);
                    var addonInvId     = toInt(card.getAttribute('data-rental-inv-id'), 0);
                    var addonQty       = toInt(card.getAttribute('data-quantity'), 1);
                    var addonRequired  = card.getAttribute('data-required') === '1';
                    var addonParentId  = toInt(card.getAttribute('data-parent-product-id'), 0);

                    // Look at the addon's currently-selected option
                    // to pull its `data-price` snapshot. We pass that
                    // to the legacy price resolver as a defence-in-
                    // depth in case the variation's _price is empty.
                    var addonSelectEl = card.querySelector('[data-role="addon-select"]');
                    var addonPrice = 0;
                    if (addonSelectEl && addonSelectEl.options.length) {
                        var optAddon = addonSelectEl.options[addonSelectEl.selectedIndex];
                        if (optAddon) {
                            var rawPrice = optAddon.getAttribute('data-price') || '';
                            addonPrice = rawPrice === '' ? 0 : parseFloat(rawPrice) || 0;
                        }
                    }

                    var addonBase2 = 'rental_add_ons[' + addonIndex + ']';
                    this.appendInput(addonBase2 + '[product_id]', addonProductId);
                    this.appendInput(addonBase2 + '[variant_id]', addonVariantId);
                    this.appendInput(addonBase2 + '[inv_id]', addonInvId);
                    this.appendInput(addonBase2 + '[quantity]', addonQty);
                    this.appendInput(addonBase2 + '[required]', addonRequired ? 1 : 0);
                    // Force the addon branch to use the variant's WC
                    // `_price` postmeta. `price` is the fallback when
                    // _price is empty.
                    this.appendInput(addonBase2 + '[inherit_price]', 1);
                    this.appendInput(addonBase2 + '[price]', addonPrice);
                    this.appendInput(addonBase2 + '[parent_set_id]', wpSetId);
                    // The classic validator uses parent_set_item_product_id
                    // to link the addon to its parent set item.
                    this.appendInput(addonBase2 + '[parent_set_item_product_id]', addonParentId);
                    addonIndex++;
                }
            }
        }

        // Normalized JSON payload
        if (this.formHost && '' !== newPayloadJson) {
            this.appendInput('rental_set_payload', newPayloadJson);
        }

        // No-Thanks opt-out list. For every
        // non-required section whose customer picked "No Thanks", emit
        // a hidden input carrying the section UID. The server-side
        // synthesizer reads `$_POST['rental_set_opt_outs']` and skips
        // every set item whose UID is in that list — both the item
        // itself AND its nested addons.
        var optOutIndex = 0;
        this.sections.forEach(function (s) {
            if (!s || s.required === true) { return; }
            var sels = s.getSelections ? s.getSelections() : [];
            if (sels && sels.length === 0 && s.uid) {
                self.appendInput('rental_set_opt_outs[' + optOutIndex + ']', s.uid);
                optOutIndex++;
            }
        });

        // ADDON-level No-Thanks opt-outs. Skipping the addon's
        // rental_add_ons row is not enough — the server-side synthesizer
        // rebuilds addons from postmeta and would re-add it. Emit one key
        // per declined OPTIONAL addon,
        // "{parent_item_product_id}:{addon_product_id}:{addon_rental_inv_id}",
        // which the synthesizer reads to drop the addon before validation
        // and cart insertion.
        var addonOptOutIndex = 0;
        $$('[data-role="addon-card"]', this.root).forEach(function (card) {
            if (card.getAttribute('data-no-thanks') !== '1') { return; }
            if (card.getAttribute('data-required') === '1') { return; }
            var addonOptOutKey = toInt(card.getAttribute('data-parent-product-id'), 0)
                + ':' + toInt(card.getAttribute('data-product-id'), 0)
                + ':' + toInt(card.getAttribute('data-rental-inv-id'), 0);
            self.appendInput('rental_set_addon_opt_outs[' + addonOptOutIndex + ']', addonOptOutKey);
            addonOptOutIndex++;
        });
    };

    Wrapper.prototype.appendInput = function (name, value) {
        var input = el('input', { type: 'hidden', name: name });
        input.value = (value === null || typeof value === 'undefined') ? '' : String(value);
        // Inputs that need to ride the cart-form submission go into the
        // form-internal host. The list:
        //
        //   - `rental_add_ons[...]`        — legacy children carrier
        //                                     (incl. composite-group children)
        //   - `rental_set_selections[...]` — modern group/selectable payload.
        //                                     Required so the server-side
        //                                     `Rental_Sets_Cart_Validator` can
        //                                     enforce min/max/required rules
        //                                     (it keys off
        //                                     $_POST['rental_set_selections']).
        //                                     Activated 
        //                                     backend-enforcement work.
        //   - `rental_set_payload`         — normalized snapshot
        //                                     (observation-only).
        //
        // Everything else (no current uses) falls back to the outside host.
        var routeToForm = false;
        if (this.formHost) {
            if (name.indexOf('rental_add_ons') === 0
                || name.indexOf('rental_set_selections') === 0
                || name === 'rental_set_payload'
                || name.indexOf('rental_set_opt_outs') === 0
                || name.indexOf('rental_set_addon_opt_outs') === 0) {
                routeToForm = true;
            }
        }
        if (routeToForm) {
            this.formHost.appendChild(input);
        } else {
            this.hiddenHost.appendChild(input);
        }
    };

    /*--------------------------------------------------------------
    Server-state bridge — mirrors classic AJAX persistence
    --------------------------------------------------------------*/

    /**
     * Vanilla fetch wrapper that maintains a per-Wrapper "pending"
     * counter and resolves the submit-guard drain queue when the
     * counter reaches zero. Always resolves (never throws) so a 5xx
     * from one endpoint doesn't lock the form for the customer; the
     * server-side validator remains the final word.
     *
     * @param {string} action  WordPress AJAX action slug.
     * @param {object} payload Body fields (action is added separately).
     * @returns {Promise<object>} parsed JSON, or {} on failure.
     */
    Wrapper.prototype.ajaxPost = function (action, payload) {
        var self = this;
        var url = (self.config && self.config.ajaxUrl) || '/wp-admin/admin-ajax.php';
        var body = new URLSearchParams();
        body.append('action', action);
        if (payload) {
            for (var k in payload) {
                if (Object.prototype.hasOwnProperty.call(payload, k)) {
                    body.append(k, payload[k] == null ? '' : String(payload[k]));
                }
            }
        }
        self.pendingAjax++;
        return fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            body: body
        })
        .then(function (resp) {
            return resp.ok ? resp.json().catch(function () { return {}; }) : {};
        })
        .catch(function () { return {}; })
        .then(function (data) {
            self.pendingAjax--;
            if (self.pendingAjax <= 0) {
                self.pendingAjax = 0;
                self.flushSubmitDrain();
            }
            return data;
        });
    };

    /**
     * Drain the queued submit promises once outstanding AJAX completes.
     * `installSubmitGuard` queues a resolver here when it intercepts a
     * submit while AJAX is in flight.
     *
     * @returns {void}
     */
    Wrapper.prototype.flushSubmitDrain = function () {
        var resolvers = this.pendingResolvers;
        this.pendingResolvers = [];
        resolvers.forEach(function (resolve) {
            try { resolve(); } catch (e) { /* noop */ }
        });
    };

    /**
     * Wait until every outstanding AJAX completes. Returns immediately
     * when the queue is already empty.
     *
     * @returns {Promise<void>}
     */
    Wrapper.prototype.awaitPendingAjax = function () {
        var self = this;
        if (self.pendingAjax <= 0) {
            return Promise.resolve();
        }
        return new Promise(function (resolve) {
            self.pendingResolvers.push(resolve);
        });
    };

    /**
     * Hook the parent `<form class="cart">` so the customer can't
     * out-race the server-state sync. When AJAX is still in flight we
     * prevent the native submit, await drain, then call
     * `form.submit()` (which doesn't re-fire the submit event, so the
     * guard isn't re-entered).
     *
     * @returns {void}
     */
    Wrapper.prototype.installSubmitGuard = function () {
        var self = this;

        // Backstop for a cart form that isn't there yet. The date gate
        // removes the add-to-cart template until dates are picked, and
        // some themes re-render the form — either way the boot-time
        // binding below finds nothing and the configurator would submit
        // none of its fields, which the server reads as "nothing chosen".
        // Watching submits at the document level catches whatever form
        // eventually appears. It only does the work the payload depends
        // on (marker, relocation, flush) and defers the full guard to the
        // next submit, so it can never double-submit the one in flight.
        if (!self.docSubmitBound) {
            self.docSubmitBound = true;
            document.addEventListener('submit', function (e) {
                var target = e.target;
                if (!target || target.tagName !== 'FORM') { return; }
                if (!target.classList || !target.classList.contains('cart')) { return; }
                if (self.cartForm === target) { return; } // the real guard owns it
                self.cartForm = target;
                self.interacted = true;
                self.ensureMarkerInForm(target);
                self.relocateFormFields();
                self.flushSync();
                setTimeout(function () { self.bindSubmitGuard(target); }, 0);
            }, true);
        }

        // The configurator renders on woocommerce_before_add_to_cart_form,
        // i.e. as a SIBLING just above its cart form, so there is no
        // enclosing form to walk up to. resolveCartForm() picks the form
        // that actually belongs to this set.
        var form = self.resolveCartForm();
        if (!form || form.tagName !== 'FORM') {
            return;
        }
        self.bindSubmitGuard(form);
    };

    /**
     * Attach the submit-time validation + AJAX-drain guard to a cart form.
     * Idempotent — re-binding the same form is a no-op.
     *
     * @param {HTMLFormElement} form
     * @returns {void}
     */
    Wrapper.prototype.bindSubmitGuard = function (form) {
        var self = this;
        if (!form || form.tagName !== 'FORM') { return; }
        if (form.rntpSubmitGuardBound) {
            self.cartForm = form;
            return;
        }
        form.rntpSubmitGuardBound = true;
        self.cartForm = form;
        form.addEventListener('submit', function (e) {
            // A submit attempt counts as interaction — from here on the
            // form is allowed to render validation feedback.
            self.interacted = true;

            // Belt-and-suspenders: guarantee the product-page marker is in
            // the form at the moment of submit, even if boot-time
            // relocation didn't run. This is what tells the server the add
            // came from the product page (not an archive), so it never
            // wrongly demands re-configuration or resets selections.
            self.ensureMarkerInForm(form);

            // Flush any pending rAF-scheduled sync NOW so the hidden
            // inputs reflect the customer's most recent change, not the
            // last rAF write. Without this, picking a variant and
            // immediately clicking Add to Cart could submit the previous
            // selection because rAF batching delayed the writeHiddenInputs.
            self.flushSync();

            // Run the client mirror. If it finds problems, surface them
            // and block the round-trip so the customer can fix them in
            // place instead of bouncing off the server validator.
            var errors = self.validate(self.buildPickMap());
            if (errors.length) {
                e.preventDefault();
                e.stopPropagation();
                self.sync();
                self.scrollToFirstError();
                return;
            }

            if (self.pendingAjax <= 0) {
                return; // valid + nothing to wait on
            }
            e.preventDefault();
            e.stopPropagation();
            self.awaitPendingAjax().then(function () {
                // HTMLFormElement.submit() bypasses the submit event,
                // so we don't recurse into this listener.
                form.submit();
            });
        }, true);

        // Click-capture guard on the add-to-cart button itself
        var addToCartBtn = form.querySelector(
            'button[name="add-to-cart"], .single_add_to_cart_button, button.single_add_to_cart_button'
        );
        if (addToCartBtn) {
            addToCartBtn.addEventListener('click', function (e) {
                self.interacted = true;
                self.ensureMarkerInForm(form);
                // Critical: flush any pending rAF-scheduled sync BEFORE
                // we (or the theme's AJAX handler downstream) read the
                // form. Without this the customer's latest pick may not
                // have been written into the hidden inputs yet and the theme's AJAX would post the
                // previous selection
                self.flushSync();
                var errors = self.validate(self.buildPickMap());
                if (errors.length) {
                    e.preventDefault();
                    e.stopImmediatePropagation();
                    self.sync();
                    self.scrollToFirstError();
                }
            }, true);
        }

        // Parent-qty change listener — scales every multi-select
        // group's per-card qty by the parent ratio. This implements
        // the customer spec: "When the parent set quantity changes,
        // the selected quantity must be recalculated as
        // group_quantity × set_quantity." Each card's picked qty
        // doubles when set qty doubles, halves when set qty halves
        // (clamped to 0). Cards at qty 0 (unpicked) stay at 0.
        // Single-pick sections (dropdown / dropdown_qty / fixed_item)
        // already have their per-set qty baked in by the partial; the
        // refreshState read of input.qty handles their scaling at
        // sync time via the parentQty multiplier in the Summary +
        // hidden-input writers.
        var qtyInputForBind = form.querySelector('input.qty, input[name="quantity"]');
        if (qtyInputForBind) {
            // Snapshot the qty AT BIND TIME so the first change event
            // has a baseline to compute the ratio from. Updated after
            // each change so successive scaling is multiplicative
            // against the new baseline.
            self.lastParentQty = Math.max(1, toInt(qtyInputForBind.value, 1));
            qtyInputForBind.addEventListener('change', function () {
                var newQty = Math.max(1, toInt(qtyInputForBind.value, 1));
                var oldQty = self.lastParentQty || 1;
                if (newQty === oldQty) { return; }
                self.scaleMultiSelectGroupsByRatio(newQty, oldQty);
                self.lastParentQty = newQty;
                // Trigger a sync so the Summary + hidden inputs pick
                // up the new pick qtys + parentQty.
                self.requestSync();
            });
        }
    };

    /**
     * Scale every MultiSection group's per-card picked quantity by
     * the parent-qty ratio. Card qty at 0 (unpicked) stays 0 —
     * scaling an empty pick to non-zero would silently auto-select
     * an item the customer didn't ask for. Rounded to nearest
     * integer (qty inputs are integer-only).
     *
     * @param {number} newParentQty
     * @param {number} oldParentQty
     */
    Wrapper.prototype.scaleMultiSelectGroupsByRatio = function (newParentQty, oldParentQty) {
        if (!oldParentQty || oldParentQty < 1) { return; }
        var ratio = newParentQty / oldParentQty;
        if (!isFinite(ratio) || ratio <= 0) { return; }
        this.sections.forEach(function (sec) {
            if (!(sec instanceof MultiSection)) { return; }
            if (!sec.cards || !sec.cards.forEach) { return; }
            sec.cards.forEach(function (card) {
                var input = card.querySelector('[data-role="qty-input"]');
                if (!input) { return; }
                var current = toInt(input.value, 0);
                if (current <= 0) { return; }
                var scaled = Math.max(0, Math.round(current * ratio));
                if (scaled === current) { return; }
                input.value = String(scaled);
                // Fire a change event so MultiSection's own bound
                // handler (and the WC qty-stepper UI) refreshes.
                try { input.dispatchEvent(new Event('change', { bubbles: true })); } catch (e) {}
            });
        });
    };

    /**
     * Move the configurator's submitted fields INTO the cart form so they
     * actually reach $_POST. The configurator renders outside the form
     * (woocommerce_before_add_to_cart_form), so left in place none of its
     * inputs are submitted. Two things move:
     *
     *   1. The product-page marker + edit-cart key (archive detection /
     *      edit-mode replacement).
     *   2. A form-internal host (`this.formHost`) for the rental_add_ons[...]
     *      inputs (legacy children carrier, incl. composite-group
     *      children), the rental_set_selections[...] inputs (modern
     *      payload — activates server-side Rental_Sets_Cart_Validator
     *      enforcement of min/max/required group rules), and
     *      rental_set_payload. Without
     *      these in the form the configurator's selections never reach
     *      the server and either get dropped or bypass enforcement.
     *      writeHiddenInputs routes inputs to the right host (see
     *      appendInput's routeToForm logic).
     *
     * Idempotent — skips anything already inside the form.
     *
     * @returns {void}
     */
    Wrapper.prototype.relocateFormFields = function () {
        // A remembered form that has since left the document would take
        // the fields with it — re-look one up instead.
        if (this.cartForm && !document.body.contains(this.cartForm)) {
            this.cartForm = null;
        }
        var form = this.cartForm || this.resolveCartForm();
        if (!form || form.tagName !== 'FORM') { return; }
        this.cartForm = form;

        var names = ['_rental_set_from_product_page', '_rental_edit_cart_key'];
        for (var i = 0; i < names.length; i++) {
            var input = this.root.querySelector('input[name="' + names[i] + '"]');
            if (input && input.form !== form) {
                form.appendChild(input);
            }
        }

        // Ensure a form-internal container for the submitted rental_add_ons
        // payload. Created once; writeHiddenInputs repopulates it in place.
        if (!this.formHost || !form.contains(this.formHost)) {
            var host = form.querySelector('[data-role="rntp-form-add-ons"]');
            if (!host) {
                host = el('div');
                host.setAttribute('data-role', 'rntp-form-add-ons');
                host.style.display = 'none';
                form.appendChild(host);
            }
            this.formHost = host;
            // A different (empty) host means nothing has been written to
            // THIS form yet. The delta cache tracks state, not
            // destination, so it has to be dropped or the next
            // writeHiddenInputs would skip as "already written".
            this.lastWrittenPayloadJson = '';
        }
    };

    /**
     * Resolve the cart form this configurator belongs to.
     *
     * A product page can hold several `form.cart` elements — a sticky
     * add-to-cart bar, a quick-view modal, related-product loops. Picking
     * the wrong one fails silently: the hidden inputs are created and
     * populated, they just live in a form that never submits, so the
     * server sees an add with no selections at all.
     *
     * The add-to-cart control names its own product, so match this set's
     * id first; fall back to the nearest form that follows the
     * configurator in document order (it renders immediately above its
     * own form), then to the first one on the page.
     *
     * @returns {HTMLFormElement|null}
     */
    Wrapper.prototype.resolveCartForm = function () {
        // An enclosing form is unambiguously ours.
        var node = this.root;
        while (node && node.nodeType === 1) {
            if (node.tagName === 'FORM') { return node; }
            node = node.parentNode;
        }

        var forms = document.querySelectorAll('form.cart');
        if (!forms.length) { return null; }

        // Ranked, because a sticky bar can name the same product as the
        // main form: prefer this set's id AND a position after the
        // configurator, then either signal alone, then whatever exists.
        var idMatch = null;
        var following = null;
        for (var i = 0; i < forms.length; i++) {
            var btn = forms[i].querySelector('[name="add-to-cart"]');
            var isMine = !!btn && !!this.setId && toInt(btn.value, 0) === this.setId;
            // DOCUMENT_POSITION_FOLLOWING
            var isAfter = !!(this.root.compareDocumentPosition(forms[i]) & 4);
            if (isMine && isAfter) { return forms[i]; }
            if (isMine && !idMatch) { idMatch = forms[i]; }
            if (isAfter && !following) { following = forms[i]; }
        }
        return idMatch || following || forms[0];
    };

    /**
     * Guarantee the product-page marker exists inside the given form at
     * submit time. Creates it if neither the template nor relocation put
     * it there. This is the last line of defence for the archive-misdetect
     * bug — once this runs the server always sees the add as a product-page
     * add.
     *
     * @param {HTMLFormElement} form
     * @returns {void}
     */
    Wrapper.prototype.ensureMarkerInForm = function (form) {
        if (!form || form.tagName !== 'FORM') { return; }
        if (!form.querySelector('input[name="_rental_set_from_product_page"]')) {
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = '_rental_set_from_product_page';
            input.value = '1';
            form.appendChild(input);
        }
    };

    /**
     * Fire the classic page-load AJAX chain. Done sequentially because
     * each endpoint mutates `_rental_set_items` and we don't want
     * later writes to clobber earlier ones.
     *
     *   1. rental_set_items_default_update
     *        Picks defaults for hidden set items (when the whole set
     *        or specific items are flagged hide_on_website).
     *   2. rental_update_set_already_selected_items
     *        Locks prices for items the API pre-selected.
     *   3. rental_set_items_addons_with_variants_optional_default_update
     *        Picks defaults for hidden / single-variant addons.
     *   4. pushAllCurrentSelections
     *        Push whatever the modern UI is currently showing as the
     *        default selection so the post-bootstrap postmeta matches
     *        the rendered DOM. Without this step a non-hidden addon
     *        with multiple variants would still read variant_id=0 in
     *        the validator until the customer manually changed the
     *        dropdown.
     *   5. (optional) recomputeTotal
     *        Refresh the displayed total when item_based_total = 1.
     *
     * @returns {Promise<void>}
     */
    Wrapper.prototype.bootstrapServerState = function () {
        var self = this;
        if (self.bootstrapDone || !self.setId) {
            return Promise.resolve();
        }
        self.bootstrapDone = true;

        var setId = self.setId;
        // Hidden set-item defaults are resolved server-side at add-to-cart
        // (rental_set_apply_hidden_defaults), so the page-load
        // rental_set_items_default_update call is no longer needed here.
        return self.ajaxPost('rental_update_set_already_selected_items', { set_id: setId })
            .then(function () {
                return self.ajaxPost('rental_set_items_addons_with_variants_optional_default_update', { set_id: setId });
            })
            .then(function () {
                return self.pushAllCurrentSelections();
            })
            .then(function () {
                self.queueTotalRecalc();
            });
    };

    /**
     * After bootstrap, iterate every section + addon card and POST
     * whichever selection the customer is staring at. The modern UI
     * pre-selects variants in the DOM, but the classic AJAX endpoints
     * have no way to know that until we tell them. This brings the
     * `_rental_set_items` postmeta into agreement with the rendered UI
     * before the customer ever clicks anything.
     *
     * @returns {Promise<void>}
     */
    Wrapper.prototype.pushAllCurrentSelections = function () {
        var self = this;
        var chain = Promise.resolve();

        // Selectable sections — push their currently-picked variant.
        self.sections.forEach(function (section) {
            if (section.origin !== 'selectable') { return; }
            var picks = section.getSelections() || [];
            if (!picks.length) { return; }
            var pick = picks[0];
            var parentProductId = section.parentProductId || 0;
            var variantId = pick.variantId || 0;
            if (!parentProductId || !variantId) { return; }
            chain = chain.then(function () {
                return self.ajaxPost('rental_update_set_items', {
                    set_id:     self.setId,
                    product_id: parentProductId,
                    variant_id: variantId
                }).then(function () {
                    self.lastPushedSelectable[parentProductId] = variantId;
                });
            });
        });

        // Addon cards — push their default variant_id so addons with
        // variants_optional get a real variant_id in postmeta.
        $$('[data-role="addon-card"]', self.root).forEach(function (card) {
            var addonProductId = toInt(card.getAttribute('data-product-id'), 0);
            var addonVariantId = toInt(card.getAttribute('data-variant-id'), 0);
            var addonInvId     = toInt(card.getAttribute('data-rental-inv-id'), 0);
            var parentProductId = toInt(card.getAttribute('data-parent-product-id'), 0);
            if (!addonProductId || !addonVariantId || !addonInvId) { return; }
            chain = chain.then(function () {
                return self.ajaxPost('rental_update_set_items_addon_with_variants_optional', {
                    set_id:        self.setId,
                    set_item_id:   parentProductId,
                    product_id:    addonProductId,
                    variant_id:    addonVariantId,
                    rental_inv_id: addonInvId
                }).then(function () {
                    self.lastPushedAddon[addonInvId] = addonVariantId;
                });
            });
        });

        return chain;
    };

    /**
     * Push a selectable section's current pick to the server, but only
     * when it differs from what we last persisted (so dragging a
     * stepper or any other non-variant change doesn't fire AJAX).
     *
     * @param {object} controller The section controller.
     * @returns {void}
     */
    Wrapper.prototype.persistSectionChange = function (controller) {
        if (!this.setId || !controller || controller.origin !== 'selectable') {
            return;
        }
        var picks = controller.getSelections() || [];
        if (!picks.length) { return; }
        var pick = picks[0];
        var parentProductId = controller.parentProductId || 0;
        var variantId = pick.variantId || 0;
        if (!parentProductId || !variantId) { return; }
        if (this.lastPushedSelectable[parentProductId] === variantId) {
            return; // no delta
        }
        var self = this;
        this.lastPushedSelectable[parentProductId] = variantId;
        this.ajaxPost('rental_update_set_items', {
            set_id:     self.setId,
            product_id: parentProductId,
            variant_id: variantId
        }).then(function () {
            self.queueTotalRecalc();
        });
    };

    /**
     * Push an addon variant change to the server. Reads its identity
     * straight from the card's data-* attributes so this stays in
     * sync with the partial's emitted DOM contract.
     *
     * @param {Element} card  The .rntp-addon-card element.
     * @returns {void}
     */
    Wrapper.prototype.persistAddonChange = function (card) {
        if (!this.setId || !card) { return; }
        var addonProductId = toInt(card.getAttribute('data-product-id'), 0);
        var addonVariantId = toInt(card.getAttribute('data-variant-id'), 0);
        var addonInvId     = toInt(card.getAttribute('data-rental-inv-id'), 0);
        var parentProductId = toInt(card.getAttribute('data-parent-product-id'), 0);
        if (!addonProductId || !addonVariantId || !addonInvId) { return; }
        if (this.lastPushedAddon[addonInvId] === addonVariantId) {
            return; // no delta
        }
        var self = this;
        this.lastPushedAddon[addonInvId] = addonVariantId;
        this.ajaxPost('rental_update_set_items_addon_with_variants_optional', {
            set_id:        self.setId,
            set_item_id:   parentProductId,
            product_id:    addonProductId,
            variant_id:    addonVariantId,
            rental_inv_id: addonInvId
        }).then(function () {
            self.queueTotalRecalc();
        });
    };

    /**
     * Debounced total-price recompute. Only fires when the set has
     * item_based_total = 1; flat-priced sets short-circuit so we
     * don't burn the server on pointless requests.
     *
     * @returns {void}
     */
    Wrapper.prototype.queueTotalRecalc = function () {
        // [DEPRECATED] Server-side total recompute retired.
        // The `.entry-price-wrap` is now written client-side by
        // writeTopTotal() from updateSummary() — same number as the
        // bottom panel, no race against an AJAX response. This stub
        // stays so any internal caller that still invokes
        // queueTotalRecalc() doesn't error; it just no-ops. Original
        // body kept inside the block below for grep-able provenance
        // (and easy revert if the JS-only path ever needs a server
        // fallback). To restore: un-comment the block and remove the
        // early return below.
        return;
        /*
        var self = this;
        if (!self.config || !self.config.itemBasedTotal || !self.setId) {
            return;
        }
        if (self.totalRecalcTimer) {
            clearTimeout(self.totalRecalcTimer);
        }
        self.totalRecalcTimer = setTimeout(function () {
            self.totalRecalcTimer = null;
            self.ajaxPost('rental_calculate_set_price_based_on_items_price', {
                set_id: self.setId
            }).then(function (data) {
                if (!data || data.msg !== 'success' || !data.set_total_html) {
                    return;
                }
                var sel = (self.config && self.config.priceSelector) || '.entry-price-wrap';
                var wrap = document.querySelector(sel);
                if (wrap) {
                    wrap.innerHTML = data.set_total_html;
                }
            });
        }, 250);
        */
    };

    /*--------------------------------------------------------------
    Boot
    --------------------------------------------------------------*/
    function boot() {
        $$('.rental-sets-modern').forEach(function (root) {
            try {
                new Wrapper(root).boot();
            } catch (e) {
                // Defensive: never let a JS error break the legacy
                // add-to-cart form. Surface in console for debugging.
                if (window.console && window.console.error) {
                    window.console.error('[rental-sets-modern] boot failed:', e);
                }
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
