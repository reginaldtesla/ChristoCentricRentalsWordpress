/** Theme helpers: mobile menu, product tabs */
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.ccr-rental-fields').forEach(function (wrap) {
        wrap.classList.add('mt-4', 'space-y-3');
    });

    var toggle = document.querySelector('[data-mobile-menu-toggle]');
    var menu = document.querySelector('[data-mobile-menu]');
    if (toggle && menu && toggle.parentNode) {
        // App bundle also binds a class-only toggle; replace the node so only this handler runs.
        var cleanToggle = toggle.cloneNode(true);
        toggle.parentNode.replaceChild(cleanToggle, toggle);
        toggle = cleanToggle;

        function setMenuOpen(open) {
            menu.classList.toggle('hidden', !open);
            if (open) {
                menu.removeAttribute('hidden');
            } else {
                menu.setAttribute('hidden', '');
            }
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            toggle.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
        }

        setMenuOpen(false);
        toggle.addEventListener('click', function () {
            var isOpen = toggle.getAttribute('aria-expanded') === 'true';
            setMenuOpen(!isOpen);
        });
    }

    document.querySelectorAll('[data-ccr-nav-dropdown]').forEach(function (dropdown) {
        var trigger = dropdown.querySelector('[data-ccr-nav-trigger]');
        var panel = dropdown.querySelector('[data-ccr-nav-panel]');
        if (!trigger || !panel) {
            return;
        }

        function setOpen(open) {
            dropdown.classList.toggle('is-open', open);
            trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
            panel.hidden = !open;
        }

        trigger.addEventListener('click', function (e) {
            e.preventDefault();
            setOpen(panel.hidden);
        });

        document.addEventListener('click', function (e) {
            if (!dropdown.contains(e.target)) {
                setOpen(false);
            }
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                setOpen(false);
            }
        });
    });

    var mobileCats = document.querySelector('[data-ccr-mobile-cats]');
    if (mobileCats) {
        var catsTrigger = mobileCats.querySelector('[data-ccr-mobile-cats-trigger]');
        var catsPanel = mobileCats.querySelector('[data-ccr-mobile-cats-panel]');
        if (catsTrigger && catsPanel) {
            catsTrigger.addEventListener('click', function () {
                var open = catsPanel.hidden;
                catsPanel.hidden = !open;
                catsTrigger.setAttribute('aria-expanded', open ? 'true' : 'false');
            });
        }
        mobileCats.querySelectorAll('[data-ccr-mobile-group]').forEach(function (group) {
            var gTrigger = group.querySelector('[data-ccr-mobile-group-trigger]');
            var gList = group.querySelector('[data-ccr-mobile-group-list]');
            if (!gTrigger || !gList) {
                return;
            }
            gTrigger.addEventListener('click', function () {
                var open = gList.hidden;
                gList.hidden = !open;
                gTrigger.setAttribute('aria-expanded', open ? 'true' : 'false');
            });
        });
    }

    document.querySelectorAll('.ccr-shop-cats').forEach(function (tree) {
        function isFlyoutMode() {
            return window.matchMedia('(hover: hover) and (pointer: fine)').matches;
        }

        function clearOpenState() {
            tree.querySelectorAll('.ccr-shop-group.is-open, .ccr-shop-item.is-open').forEach(function (node) {
                node.classList.remove('is-open');
            });
            tree.querySelectorAll('.ccr-shop-group-trigger, .ccr-shop-item-toggle').forEach(function (btn) {
                btn.setAttribute('aria-expanded', 'false');
            });
        }

        function openActiveForTouch() {
            if (isFlyoutMode()) {
                clearOpenState();
                return;
            }
            tree.querySelectorAll('[data-ccr-active-group="1"]').forEach(function (group) {
                group.classList.add('is-open');
                var trigger = group.querySelector('.ccr-shop-group-trigger');
                if (trigger) {
                    trigger.setAttribute('aria-expanded', 'true');
                }
            });
            tree.querySelectorAll('[data-ccr-active-item="1"]').forEach(function (item) {
                item.classList.add('is-open');
                var toggle = item.querySelector('.ccr-shop-item-toggle');
                if (toggle) {
                    toggle.setAttribute('aria-expanded', 'true');
                }
            });
        }

        openActiveForTouch();
        window.matchMedia('(hover: hover) and (pointer: fine)').addEventListener('change', openActiveForTouch);

        tree.querySelectorAll('.ccr-shop-group-trigger').forEach(function (trigger) {
            trigger.addEventListener('click', function (e) {
                if (isFlyoutMode()) {
                    e.preventDefault();
                    return;
                }
                var group = trigger.closest('.ccr-shop-group');
                if (!group) {
                    return;
                }
                var open = !group.classList.contains('is-open');
                tree.querySelectorAll('.ccr-shop-group.is-open').forEach(function (other) {
                    if (other !== group) {
                        other.classList.remove('is-open');
                        var otherTrigger = other.querySelector('.ccr-shop-group-trigger');
                        if (otherTrigger) {
                            otherTrigger.setAttribute('aria-expanded', 'false');
                        }
                    }
                });
                group.classList.toggle('is-open', open);
                trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
            });
        });

        tree.querySelectorAll('.ccr-shop-item-toggle').forEach(function (toggle) {
            toggle.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                if (isFlyoutMode()) {
                    return;
                }
                var item = toggle.closest('.ccr-shop-item');
                if (!item) {
                    return;
                }
                var open = !item.classList.contains('is-open');
                item.classList.toggle('is-open', open);
                toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            });
        });

        // Phone: category product links jump down to the product grid after navigation.
        tree.querySelectorAll('a.ccr-shop-item-link').forEach(function (link) {
            link.addEventListener('click', function () {
                if (isFlyoutMode()) {
                    return;
                }
                try {
                    sessionStorage.setItem('ccrScrollShopProducts', '1');
                } catch (err) { /* ignore */ }
            });
        });
    });

    (function scrollShopProductsOnMobile() {
        var target = document.getElementById('ccr-shop-products');
        if (!target) {
            return;
        }

        var mobile = window.matchMedia('(max-width: 1023px)').matches
            || window.matchMedia('(hover: none), (pointer: coarse)').matches;
        if (!mobile) {
            return;
        }

        var shouldScroll = false;
        try {
            shouldScroll = sessionStorage.getItem('ccrScrollShopProducts') === '1';
            if (shouldScroll) {
                sessionStorage.removeItem('ccrScrollShopProducts');
            }
        } catch (err) { /* ignore */ }

        if (!shouldScroll && window.location.hash === '#ccr-shop-products') {
            shouldScroll = true;
            if (window.history && window.history.replaceState) {
                window.history.replaceState(null, '', window.location.pathname + window.location.search);
            }
        }

        if (!shouldScroll) {
            return;
        }

        function easeInOutCubic(t) {
            return t < 0.5 ? 4 * t * t * t : 1 - Math.pow(-2 * t + 2, 3) / 2;
        }

        function smoothScrollTo(el, duration) {
            var headerOffset = 72;
            var startY = window.pageYOffset || document.documentElement.scrollTop || 0;
            var rect = el.getBoundingClientRect();
            var endY = Math.max(0, startY + rect.top - headerOffset);
            var distance = endY - startY;
            if (Math.abs(distance) < 4) {
                return;
            }
            var startTime = null;

            function step(now) {
                if (startTime === null) {
                    startTime = now;
                }
                var progress = Math.min(1, (now - startTime) / duration);
                var y = startY + distance * easeInOutCubic(progress);
                window.scrollTo(0, y);
                if (progress < 1) {
                    window.requestAnimationFrame(step);
                }
            }

            window.requestAnimationFrame(step);
        }

        // Start as soon as layout is ready — keep the wait tiny so it doesn't feel stuck.
        window.setTimeout(function () {
            smoothScrollTo(target, 550);
        }, 16);
    })();

    document.querySelectorAll('[data-product-tabs]').forEach(function (root) {
        var triggers = root.querySelectorAll('[data-tab-trigger]');
        var panels = root.querySelectorAll('[data-tab-panel]');
        triggers.forEach(function (btn) {
            btn.addEventListener('click', function () {
                var id = btn.getAttribute('data-tab-trigger');
                triggers.forEach(function (t) {
                    t.classList.toggle('tab-trigger--active', t === btn);
                });
                panels.forEach(function (panel) {
                    panel.classList.toggle('hidden', panel.getAttribute('data-tab-panel') !== id);
                });
            });
        });
    });

    document.querySelectorAll('[data-product-gallery]').forEach(function (root) {
        var main = root.querySelector('[data-gallery-main]');
        var zoomFrame = root.querySelector('[data-gallery-zoom]');
        var thumbs = root.querySelectorAll('[data-gallery-thumb]');
        var expand = root.querySelector('[data-gallery-expand]');
        var lightbox = root.querySelector('[data-gallery-lightbox]');
        var lightboxImg = root.querySelector('[data-gallery-lightbox-img]');
        var closeBtn = root.querySelector('[data-gallery-close]');
        var zoomScale = 2.25;
        var canHoverZoom = window.matchMedia('(hover: hover) and (pointer: fine)').matches
            && !window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        function setActive(btn) {
            var full = btn.getAttribute('data-full');
            if (!full || !main) return;
            main.src = full;
            if (lightboxImg) lightboxImg.src = full;
            resetZoom();
            thumbs.forEach(function (t) {
                var on = t === btn;
                t.classList.toggle('is-active', on);
                t.setAttribute('aria-pressed', on ? 'true' : 'false');
            });
        }

        thumbs.forEach(function (btn) {
            btn.addEventListener('click', function () {
                setActive(btn);
            });
        });

        function resetZoom() {
            if (!main || !zoomFrame) return;
            zoomFrame.classList.remove('is-zooming');
            main.style.transformOrigin = 'center center';
            main.style.transform = '';
        }

        function updateZoom(e) {
            if (!main || !zoomFrame || !canHoverZoom) return;
            var rect = zoomFrame.getBoundingClientRect();
            if (rect.width <= 0 || rect.height <= 0) return;
            var x = ((e.clientX - rect.left) / rect.width) * 100;
            var y = ((e.clientY - rect.top) / rect.height) * 100;
            x = Math.max(0, Math.min(100, x));
            y = Math.max(0, Math.min(100, y));
            zoomFrame.classList.add('is-zooming');
            main.style.transformOrigin = x + '% ' + y + '%';
            main.style.transform = 'scale(' + zoomScale + ')';
        }

        if (zoomFrame && main && canHoverZoom) {
            zoomFrame.addEventListener('mousemove', updateZoom);
            zoomFrame.addEventListener('mouseenter', updateZoom);
            zoomFrame.addEventListener('mouseleave', resetZoom);
        }

        function openLightbox() {
            if (!lightbox || !main) return;
            if (lightboxImg) lightboxImg.src = main.src;
            resetZoom();
            lightbox.classList.remove('hidden');
            lightbox.removeAttribute('hidden');
            document.body.classList.add('overflow-hidden');
        }

        function closeLightbox() {
            if (!lightbox) return;
            lightbox.classList.add('hidden');
            lightbox.setAttribute('hidden', '');
            document.body.classList.remove('overflow-hidden');
        }

        if (expand) expand.addEventListener('click', openLightbox);
        if (closeBtn) closeBtn.addEventListener('click', closeLightbox);
        if (lightbox) {
            lightbox.addEventListener('click', function (e) {
                if (e.target === lightbox) closeLightbox();
            });
        }
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') closeLightbox();
        });
    });

    // First-visit welcome notice with staged open/close animation.
    (function () {
        var root = document.querySelector('[data-welcome-notice]');
        if (!root) return;

        var storageKey = 'ccr_welcome_notice_v2';
        var closing = false;
        var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        try {
            if (window.localStorage.getItem(storageKey) === '1') {
                return;
            }
        } catch (err) {
            // Private mode / blocked storage — still show once this session.
        }

        function remember() {
            try {
                window.localStorage.setItem(storageKey, '1');
            } catch (err) {
                // ignore
            }
        }

        function openNotice() {
            root.removeAttribute('hidden');
            root.setAttribute('aria-hidden', 'false');
            // Force reflow so CSS transitions run after display.
            void root.offsetWidth;
            root.classList.add('is-open');
            document.body.classList.add('overflow-hidden');
        }

        function closeNotice() {
            if (closing || !root.classList.contains('is-open')) return;
            closing = true;
            root.classList.add('is-closing');
            root.classList.remove('is-open');
            document.body.classList.remove('overflow-hidden');
            remember();

            var finish = function () {
                root.classList.remove('is-closing');
                root.setAttribute('hidden', '');
                root.setAttribute('aria-hidden', 'true');
                closing = false;
            };

            if (reduceMotion) {
                finish();
                return;
            }

            window.setTimeout(finish, 300);
        }

        root.querySelectorAll('[data-welcome-close]').forEach(function (el) {
            el.addEventListener('click', closeNotice);
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && root.classList.contains('is-open')) {
                closeNotice();
            }
        });

        window.setTimeout(openNotice, 450);
    })();

    // Product compare (AJAX + cookie-backed list)
    (function () {
        if (typeof ccrCompare === 'undefined' || !ccrCompare.ajaxUrl) return;

        var checkIcon = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>';
        var barsIcon = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>';
        var pending = {};

        function currentCount() {
            var n = 0;
            document.querySelectorAll('[data-ccr-compare][data-in-compare="1"]').forEach(function () { n += 1; });
            return n;
        }

        function updateHeaderCount(count) {
            document.querySelectorAll('[data-ccr-compare-count]').forEach(function (el) {
                var isBadge = el.classList.contains('rounded-full');
                if (count > 0) {
                    el.textContent = isBadge ? String(count) : ' (' + count + ')';
                    el.hidden = false;
                    el.removeAttribute('hidden');
                } else {
                    el.textContent = '';
                    el.hidden = true;
                    el.setAttribute('hidden', '');
                }
            });
        }

        function syncDisabled(count) {
            document.querySelectorAll('[data-ccr-compare]').forEach(function (el) {
                var already = el.getAttribute('data-in-compare') === '1';
                if (!already && !pending[el.getAttribute('data-product-id')]) {
                    el.disabled = count >= (ccrCompare.max || 4);
                }
            });
        }

        function paintButton(btn, inCompare, count) {
            var compact = btn.getAttribute('data-compact') === '1';
            var label = btn.querySelector('[data-ccr-compare-label]');
            var icon = btn.querySelector('[data-ccr-compare-icon]');
            btn.classList.toggle('is-active', inCompare);
            btn.setAttribute('data-in-compare', inCompare ? '1' : '0');
            btn.disabled = !inCompare && count >= (ccrCompare.max || 4);
            if (label) {
                label.textContent = inCompare
                    ? (ccrCompare.i18n.inCompare || 'In compare')
                    : (compact ? (ccrCompare.i18n.compare || 'Compare') : (ccrCompare.i18n.add || 'Add to compare'));
            }
            if (icon) {
                icon.innerHTML = inCompare ? checkIcon : barsIcon;
            }
            btn.title = inCompare
                ? 'Remove from compare'
                : (btn.disabled ? (ccrCompare.i18n.full || 'Compare list is full') : (ccrCompare.i18n.add || 'Add to compare'));
        }

        function applyProductState(productId, inCompare, count) {
            document.querySelectorAll('[data-ccr-compare][data-product-id="' + productId + '"]').forEach(function (el) {
                paintButton(el, inCompare, count);
            });
            syncDisabled(count);
            updateHeaderCount(count);
        }

        function postToggle(productId) {
            var body = new URLSearchParams();
            body.set('action', 'ccr_compare_toggle');
            body.set('nonce', ccrCompare.nonce);
            body.set('product_id', productId);
            return fetch(ccrCompare.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                body: body.toString()
            }).then(function (res) { return res.json(); });
        }

        document.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-ccr-compare]');
            var removeBtn = e.target.closest('[data-ccr-compare-remove]');
            var target = btn || removeBtn;
            if (!target || target.disabled) return;

            var productId = target.getAttribute('data-product-id');
            if (!productId || pending[productId]) return;

            e.preventDefault();
            e.stopPropagation();

            var wasIn = btn
                ? btn.getAttribute('data-in-compare') === '1'
                : true; // remove controls always remove
            var nextIn = !wasIn;
            var count = currentCount();

            if (btn && nextIn && count >= (ccrCompare.max || 4)) {
                window.alert(ccrCompare.i18n.full || 'Compare list is full');
                return;
            }

            pending[productId] = true;

            // Compare page remove: drop column immediately, sync in background (no full reload).
            if (removeBtn) {
                var remaining = removeCompareColumn(productId);
                updateHeaderCount(remaining);
                writeCompareCookieFromDom();
                showCompareEmptyIfNeeded(remaining);
                postToggle(productId)
                    .then(function () {
                        pending[productId] = false;
                    })
                    .catch(function () {
                        pending[productId] = false;
                        window.alert('Could not remove item. Reloading…');
                        window.location.reload();
                    });
                return;
            }

            var optimisticCount = nextIn ? count + 1 : Math.max(0, count - 1);
            applyProductState(productId, nextIn, optimisticCount);

            postToggle(productId)
                .then(function (data) {
                    pending[productId] = false;
                    if (!data || (data.ok === false && !data.in_compare && nextIn)) {
                        applyProductState(productId, wasIn, count);
                        window.alert((data && data.message) || (ccrCompare.i18n.full || 'Compare list is full'));
                        return;
                    }
                    var serverCount = (data && typeof data.count === 'number') ? data.count : optimisticCount;
                    var serverIn = !!(data && data.in_compare);
                    applyProductState(productId, serverIn, serverCount);
                })
                .catch(function () {
                    pending[productId] = false;
                    applyProductState(productId, wasIn, count);
                    window.alert('Could not update compare list. Please try again.');
                });
        });

        function removeCompareColumn(productId) {
            var header = document.querySelector('th[data-ccr-compare-col][data-product-id="' + productId + '"]');
            if (header) {
                var row = header.parentElement;
                var index = Array.prototype.indexOf.call(row.children, header);
                header.remove();
                document.querySelectorAll('.ccr-compare-table tbody tr').forEach(function (tr) {
                    if (tr.children[index]) {
                        tr.children[index].remove();
                    }
                });
            }
            document.querySelectorAll('td[data-product-id="' + productId + '"]').forEach(function (td) {
                td.remove();
            });
            return document.querySelectorAll('[data-ccr-compare-col]').length;
        }

        function writeCompareCookieFromDom() {
            var ids = [];
            document.querySelectorAll('[data-ccr-compare-col][data-product-id]').forEach(function (el) {
                ids.push(el.getAttribute('data-product-id'));
            });
            var value = ids.join(',');
            var maxAge = ids.length ? 604800 : 0;
            document.cookie = 'ccr_compare=' + encodeURIComponent(value) + '; path=/; max-age=' + maxAge + '; SameSite=Lax';
        }

        function showCompareEmptyIfNeeded(remaining) {
            if (remaining > 0) return;
            var page = document.querySelector('[data-ccr-compare-page]');
            if (!page) return;
            var filled = page.querySelector('[data-ccr-compare-filled]');
            if (filled) filled.remove();
            var shopUrl = page.getAttribute('data-shop-url') || '/shop/';
            var empty = document.createElement('div');
            empty.setAttribute('data-ccr-compare-empty', '');
            empty.innerHTML =
                '<p class="text-gray-600">No products to compare yet.</p>' +
                '<p class="mt-2 text-sm text-gray-500">Browse the shop and click Add to compare on up to 4 items.</p>' +
                '<a href="' + shopUrl + '" class="btn-solid mt-6 inline-flex">Browse shop</a>';
            page.appendChild(empty);
        }
    })();

    // Popular product rail progress bar (Absolute Cinema style).
    document.querySelectorAll('[data-product-scroll]').forEach(function (root) {
        var track = root.querySelector('[data-product-scroll-track]');
        var bar = root.querySelector('[data-product-scroll-progress]');
        if (!track || !bar) return;

        function updateProgress() {
            var max = track.scrollWidth - track.clientWidth;
            var ratio = max > 0 ? track.scrollLeft / max : 0;
            var thumb = Math.max(22, Math.min(100, (track.clientWidth / Math.max(track.scrollWidth, 1)) * 100));
            bar.style.width = thumb + '%';
            bar.style.marginLeft = ((100 - thumb) * ratio) + '%';
        }

        track.addEventListener('scroll', updateProgress, { passive: true });
        window.addEventListener('resize', updateProgress);
        updateProgress();
    });

    document.querySelectorAll('[data-ccr-agreement]').forEach(function (root) {
        var modal = root.querySelector('[data-ccr-agreement-modal]');
        var form = root.querySelector('[data-ccr-agreement-form]');
        if (!modal || !form) {
            return;
        }

        var panes = Array.prototype.slice.call(form.querySelectorAll('[data-ccr-agreement-pane]'));
        var stepLabel = root.querySelector('[data-ccr-agreement-step-label]');
        var progress = root.querySelector('[data-ccr-agreement-progress]');
        var prevBtn = form.querySelector('[data-ccr-agreement-prev]');
        var nextBtn = form.querySelector('[data-ccr-agreement-next]');
        var submitBtn = form.querySelector('[data-ccr-agreement-submit]');
        var required = root.getAttribute('data-ccr-agreement-required') === '1';
        var step = 1;
        var total = panes.length || 5;

        function setStep(n) {
            step = Math.max(1, Math.min(total, n));
            panes.forEach(function (pane) {
                var id = parseInt(pane.getAttribute('data-ccr-agreement-pane'), 10);
                var active = id === step;
                pane.hidden = !active;
                pane.classList.toggle('is-active', active);
            });
            if (stepLabel) {
                stepLabel.textContent = 'Step ' + step + ' of ' + total;
            }
            if (progress) {
                progress.style.width = ((step / total) * 100) + '%';
            }
            if (prevBtn) {
                prevBtn.hidden = step === 1;
            }
            if (nextBtn) {
                nextBtn.hidden = step === total;
            }
            if (submitBtn) {
                submitBtn.hidden = step !== total;
            }
        }

        function openModal() {
            modal.hidden = false;
            document.body.classList.add('ccr-agreement-lock');
            setStep(step);
        }

        function closeModal() {
            if (required) {
                return;
            }
            modal.hidden = true;
            document.body.classList.remove('ccr-agreement-lock');
        }

        function validatePane() {
            var pane = form.querySelector('[data-ccr-agreement-pane="' + step + '"]');
            if (!pane) {
                return true;
            }
            var fields = pane.querySelectorAll('input, select, textarea');
            for (var i = 0; i < fields.length; i++) {
                var field = fields[i];
                if (field.disabled || field.type === 'hidden') {
                    continue;
                }
                if (typeof field.checkValidity === 'function' && !field.checkValidity()) {
                    field.reportValidity();
                    return false;
                }
            }
            return true;
        }

        root.querySelectorAll('[data-ccr-agreement-open]').forEach(function (btn) {
            btn.addEventListener('click', openModal);
        });
        root.querySelectorAll('[data-ccr-agreement-close]').forEach(function (btn) {
            btn.addEventListener('click', closeModal);
        });
        var backdrop = root.querySelector('[data-ccr-agreement-backdrop]');
        if (backdrop) {
            backdrop.addEventListener('click', function () {
                if (!required) {
                    closeModal();
                }
            });
        }
        if (prevBtn) {
            prevBtn.addEventListener('click', function () {
                setStep(step - 1);
            });
        }
        if (nextBtn) {
            nextBtn.addEventListener('click', function () {
                if (!validatePane()) {
                    return;
                }
                setStep(step + 1);
            });
        }

        setStep(1);
        if (required) {
            openModal();
        }

        form.querySelectorAll('.ccr-file-input').forEach(function (input) {
            var nameEl = input.parentNode ? input.parentNode.querySelector('[data-ccr-file-name]') : null;
            if (!nameEl) {
                return;
            }
            input.addEventListener('change', function () {
                if (input.files && input.files.length) {
                    nameEl.textContent = input.files[0].name;
                    nameEl.classList.add('has-file');
                } else if (!nameEl.textContent || nameEl.textContent.indexOf('On file:') !== 0) {
                    nameEl.textContent = 'No file chosen';
                    nameEl.classList.remove('has-file');
                }
            });
        });
    });

    document.querySelectorAll('[data-ccr-quick-add]').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            if (typeof ccrQuickAdd === 'undefined' || !ccrQuickAdd.ajaxUrl) {
                return;
            }
            if (btn.disabled) {
                return;
            }

            var productId = btn.getAttribute('data-product-id');
            if (!productId) {
                return;
            }

            var labelAdd = (ccrQuickAdd.i18n && ccrQuickAdd.i18n.add) || 'Add';
            var labelAdding = (ccrQuickAdd.i18n && ccrQuickAdd.i18n.adding) || 'Adding…';
            var labelAdded = (ccrQuickAdd.i18n && ccrQuickAdd.i18n.added) || 'Added';
            var labelError = (ccrQuickAdd.i18n && ccrQuickAdd.i18n.error) || 'Could not add to cart.';

            btn.disabled = true;
            btn.textContent = labelAdding;

            var body = new FormData();
            body.set('action', 'ccr_quick_add');
            body.set('nonce', ccrQuickAdd.nonce);
            body.set('product_id', productId);

            fetch(ccrQuickAdd.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                body: body
            })
                .then(function (res) { return res.json(); })
                .then(function (payload) {
                    if (!payload || !payload.success) {
                        var msg = (payload && payload.data && payload.data.message) ? payload.data.message : labelError;
                        window.alert(msg);
                        btn.disabled = false;
                        btn.textContent = labelAdd;
                        btn.classList.remove('is-added');
                        return;
                    }

                    var count = payload.data && typeof payload.data.cart_count !== 'undefined'
                        ? parseInt(payload.data.cart_count, 10)
                        : 0;
                    document.querySelectorAll('[data-ccr-cart-count]').forEach(function (badge) {
                        if (count > 0) {
                            badge.hidden = false;
                            badge.removeAttribute('hidden');
                            badge.textContent = String(count);
                        } else {
                            badge.hidden = true;
                            badge.setAttribute('hidden', '');
                            badge.textContent = '';
                        }
                    });

                    btn.textContent = labelAdded;
                    btn.classList.add('is-added');
                    window.setTimeout(function () {
                        btn.disabled = false;
                        btn.textContent = labelAdd;
                        btn.classList.remove('is-added');
                    }, 1600);
                })
                .catch(function () {
                    window.alert(labelError);
                    btn.disabled = false;
                    btn.textContent = labelAdd;
                    btn.classList.remove('is-added');
                });
        });
    });

    document.querySelectorAll('[data-ccr-pop-section]').forEach(function (section) {
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            section.classList.add('is-inview');
            return;
        }
        if (!('IntersectionObserver' in window)) {
            section.classList.add('is-inview');
            return;
        }
        var observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    section.classList.add('is-inview');
                    observer.unobserve(section);
                }
            });
        }, {
            threshold: 0.22,
            rootMargin: '0px 0px -8% 0px'
        });
        observer.observe(section);
    });

    // Browse-interest cookie for homepage personalization.
    (function trackInterest() {
        if (typeof ccrInterest === 'undefined' || !ccrInterest.productId) {
            return;
        }

        var productId = parseInt(ccrInterest.productId, 10);
        if (!productId) {
            return;
        }

        var cookieName = ccrInterest.cookie || 'ccr_interest';
        var maxAge = parseInt(ccrInterest.maxAge, 10) || (30 * 24 * 60 * 60);
        var cats = Array.isArray(ccrInterest.categories) ? ccrInterest.categories : [];

        function readCookie(name) {
            var parts = (';cookie || '').split(';');
            for (var i = 0; i < parts.length; i++) {
                var part = parts[i].trim();
                if (part.indexOf(name + '=') === 0) {
                    return decodeURIComponent(part.slice(name.length + 1));
                }
            }
            return '';
        }

        var data = { p: [], c: [] };
        try {
            var raw = readCookie(cookieName);
            if (raw) {
                var parsed = JSON.parse(raw);
                if (parsed && typeof parsed === 'object') {
                    data.p = Array.isArray(parsed.p) ? parsed.p : [];
                    data.c = Array.isArray(parsed.c) ? parsed.c : [];
                }
            }
        } catch (err) { /* ignore bad cookie */ }

        data.p = data.p
            .map(function (id) { return parseInt(id, 10); })
            .filter(function (id) { return id > 0 && id !== productId; });
        data.p.unshift(productId);
        data.p = data.p.slice(0, 12);

        cats.forEach(function (slug) {
            if (!slug || typeof slug !== 'string') {
                return;
            }
            data.c = data.c.filter(function (existing) { return existing !== slug; });
            data.c.unshift(slug);
        });
        data.c = data.c.slice(0, 8);

        document.cookie = cookieName + '=' + encodeURIComponent(JSON.stringify(data))
            + '; path=/; max-age=' + maxAge + '; SameSite=Lax';
    })();
});
