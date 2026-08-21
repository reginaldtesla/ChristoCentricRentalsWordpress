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

    (function initCategoriesMegaMenu() {
        var header = document.querySelector('[data-ccr-site-header]');
        var dropdown = document.querySelector('[data-ccr-nav-dropdown]');
        var trigger = dropdown ? dropdown.querySelector('[data-ccr-nav-trigger]') : null;
        var panel = document.querySelector('[data-ccr-nav-panel]');
        if (!header || !dropdown || !trigger || !panel) {
            return;
        }

        var closeTimer = null;

        function setOpen(open) {
            header.classList.toggle('is-cats-open', open);
            dropdown.classList.toggle('is-open', open);
            panel.classList.toggle('is-open', open);
            trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
            panel.setAttribute('aria-hidden', open ? 'false' : 'true');
        }

        function scheduleClose() {
            clearTimeout(closeTimer);
            closeTimer = setTimeout(function () {
                setOpen(false);
            }, 160);
        }

        function cancelClose() {
            clearTimeout(closeTimer);
        }

        setOpen(false);

        trigger.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            setOpen(!header.classList.contains('is-cats-open'));
        });

        trigger.addEventListener('mouseenter', function () {
            if (window.matchMedia('(hover: hover) and (pointer: fine)').matches) {
                cancelClose();
                setOpen(true);
            }
        });
        dropdown.addEventListener('mouseleave', function () {
            if (window.matchMedia('(hover: hover) and (pointer: fine)').matches) {
                scheduleClose();
            }
        });
        panel.addEventListener('mouseenter', cancelClose);
        panel.addEventListener('mouseleave', function () {
            if (window.matchMedia('(hover: hover) and (pointer: fine)').matches) {
                scheduleClose();
            }
        });

        document.addEventListener('click', function (e) {
            if (!header.contains(e.target)) {
                setOpen(false);
            }
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                setOpen(false);
            }
        });
    })();

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
        function isDesktopFlyout() {
            return window.matchMedia('(min-width: 1024px)').matches;
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
            if (isDesktopFlyout()) {
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
        window.matchMedia('(min-width: 1024px)').addEventListener('change', function () {
            if (isDesktopFlyout()) {
                clearOpenState();
            } else {
                openActiveForTouch();
            }
        });

        tree.querySelectorAll('.ccr-shop-group-trigger').forEach(function (trigger) {
            trigger.addEventListener('click', function (e) {
                e.preventDefault();
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
                if (isDesktopFlyout()) {
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

    // Studio rental agreement — show on every visit.
    (function () {
        var root = document.querySelector('[data-studio-agreement]');
        if (!root) return;

        var closing = false;
        var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        function openNotice() {
            root.removeAttribute('hidden');
            root.setAttribute('aria-hidden', 'false');
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

        root.querySelectorAll('[data-studio-agreement-close]').forEach(function (el) {
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

    // Cookie consent + browse-interest personalization.
    (function cookieConsentAndInterest() {
        var consentName = (typeof ccrInterest !== 'undefined' && ccrInterest.consentCookie)
            ? ccrInterest.consentCookie
            : 'ccr_cookie_consent';
        var interestName = (typeof ccrInterest !== 'undefined' && ccrInterest.cookie)
            ? ccrInterest.cookie
            : 'ccr_interest';
        var consentMaxAge = (typeof ccrInterest !== 'undefined' && ccrInterest.consentMaxAge)
            ? parseInt(ccrInterest.consentMaxAge, 10)
            : (365 * 24 * 60 * 60);
        var interestMaxAge = (typeof ccrInterest !== 'undefined' && ccrInterest.maxAge)
            ? parseInt(ccrInterest.maxAge, 10)
            : (30 * 24 * 60 * 60);

        function readCookie(name) {
            var parts = (document.cookie || '').split(';');
            for (var i = 0; i < parts.length; i++) {
                var part = parts[i].trim();
                if (part.indexOf(name + '=') === 0) {
                    return decodeURIComponent(part.slice(name.length + 1));
                }
            }
            return '';
        }

        function writeCookie(name, value, maxAge) {
            document.cookie = name + '=' + encodeURIComponent(value)
                + '; path=/; max-age=' + maxAge + '; SameSite=Lax';
        }

        function clearCookie(name) {
            document.cookie = name + '=; path=/; max-age=0; SameSite=Lax';
        }

        function hasPersonalizationConsent() {
            var v = (readCookie(consentName) || '').toLowerCase();
            return v === 'all' || v === '1' || v === 'accepted';
        }

        function trackInterestIfAllowed() {
            if (!hasPersonalizationConsent()) {
                return;
            }
            if (typeof ccrInterest === 'undefined' || !ccrInterest.productId) {
                return;
            }

            var productId = parseInt(ccrInterest.productId, 10);
            if (!productId) {
                return;
            }

            var cats = Array.isArray(ccrInterest.categories) ? ccrInterest.categories : [];
            var data = { p: [], c: [] };
            try {
                var raw = readCookie(interestName);
                if (raw) {
                    var parsed = JSON.parse(raw);
                    if (parsed && typeof parsed === 'object') {
                        data.p = Array.isArray(parsed.p) ? parsed.p : [];
                        data.c = Array.isArray(parsed.c) ? parsed.c : [];
                    }
                }
            } catch (err) { /* ignore */ }

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

            writeCookie(interestName, JSON.stringify(data), interestMaxAge);
        }

        var banner = document.querySelector('[data-ccr-cookie-consent]');
        var existing = (readCookie(consentName) || '').toLowerCase();

        if (!existing && banner) {
            banner.hidden = false;
            var acceptBtn = banner.querySelector('[data-ccr-cookie-accept]');
            var essentialBtn = banner.querySelector('[data-ccr-cookie-essential]');

            if (acceptBtn) {
                acceptBtn.addEventListener('click', function () {
                    writeCookie(consentName, 'all', consentMaxAge);
                    banner.hidden = true;
                    trackInterestIfAllowed();
                });
            }
            if (essentialBtn) {
                essentialBtn.addEventListener('click', function () {
                    writeCookie(consentName, 'essential', consentMaxAge);
                    clearCookie(interestName);
                    banner.hidden = true;
                });
            }
            return;
        }

        if (existing === 'essential' || existing === '0' || existing === 'denied') {
            clearCookie(interestName);
            return;
        }

        trackInterestIfAllowed();
    })();

    (function initStudioBookingWizard() {
        var root = document.querySelector('[data-ccr-studio-book]');
        if (!root || typeof ccrStudio === 'undefined' || !ccrStudio.studios) {
            return;
        }
        if (root.querySelector('.ccr-studio-book-summary-static')) {
            return;
        }

        var data = ccrStudio;
        var settings = data.settings || {};
        var studios = data.studios || [];
        var mount = root.querySelector('[data-ccr-studio-mount]');
        var stepLabel = root.querySelector('[data-ccr-studio-step-label]');
        var stepPct = root.querySelector('[data-ccr-studio-step-pct]');
        var stepSub = root.querySelector('[data-ccr-studio-step-sub]');
        var progress = root.querySelector('[data-ccr-studio-progress]');
        var errorEl = root.querySelector('[data-ccr-studio-error]');
        var prevBtn = root.querySelector('[data-ccr-studio-prev]');
        var nextBtn = root.querySelector('[data-ccr-studio-next]');
        var payBtn = root.querySelector('[data-ccr-studio-pay]');
        var totalSteps = 6;
        var current = 1;
        var state = {
            studioId: '',
            packageId: '',
            sessionHours: 1,
            extendHours: 1,
            date: '',
            hour: null,
            minute: 0,
            start: '',
            end: '',
            addons: [],
            name: '',
            phone: '',
            email: '',
            momo_network: '',
            momo_number: '',
            notes: '',
            depositPct: 100,
            taken: [],
            monthTaken: {},
            calYear: (new Date()).getFullYear(),
            calMonth: (new Date()).getMonth(),
            featuresOpen: false
        };
        var monthCache = {};
        var monthLoadToken = 0;

        var subs = {
            1: 'Select a set to begin — takes under 2 minutes.',
            2: 'Choose your package for the selected set.',
            3: 'Pick a date and start time for your session.',
            4: 'Optional extras for your session.',
            5: 'How should we reach you?',
            6: 'Full payment by Mobile Money — use reference "' + (settings.momo_reference || 'Studio Rentals') + '".'
        };

        function esc(s) {
            return String(s == null ? '' : s)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;');
        }

        function money(n) {
            return 'GHS ' + Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 2 });
        }

        function selectedStudio() {
            return studios.find(function (s) { return String(s.id) === String(state.studioId); }) || null;
        }

        function selectedPackage() {
            var studio = selectedStudio();
            if (!studio) return null;
            return (studio.packages || []).find(function (p) { return p.id === state.packageId; }) || null;
        }

        function sessionRate() {
            var pkg = selectedPackage();
            if (!pkg) return 0;
            return Number(pkg.price || 0);
        }

        function isHourlyPackage(pkg) {
            return !pkg || (pkg.pricing || 'hourly') !== 'flat';
        }

        function packageBasePrice() {
            var pkg = selectedPackage();
            if (!pkg) return 0;
            if (isHourlyPackage(pkg)) {
                return sessionRate() * Number(state.sessionHours || pkg.hours || 4);
            }
            return Number(pkg.price || 0);
        }

        function extendSelected() {
            return state.addons.indexOf('extend') !== -1;
        }

        function effectiveHours() {
            var pkg = selectedPackage();
            var base = Number(state.sessionHours || (pkg && pkg.hours) || 4);
            if (extendSelected()) {
                base += Math.max(1, Number(state.extendHours || 1));
            }
            return base;
        }

        function showError(msg) {
            if (!errorEl) return;
            if (!msg) {
                errorEl.hidden = true;
                errorEl.textContent = '';
                return;
            }
            errorEl.hidden = false;
            errorEl.textContent = msg;
        }

        function fillHero() {
            var map = [
                ['[data-ccr-studio-eyebrow]', settings.eyebrow],
                ['[data-ccr-studio-t1]', settings.title_line_1],
                ['[data-ccr-studio-t2]', settings.title_line_2],
                ['[data-ccr-studio-te]', settings.title_emphasis],
                ['[data-ccr-studio-lead]', settings.lead],
                ['[data-ccr-studio-address]', settings.address]
            ];
            map.forEach(function (pair) {
                var el = root.querySelector(pair[0]);
                if (el) el.textContent = pair[1] || '';
            });
            var stats = root.querySelector('[data-ccr-studio-stats]');
            if (stats) {
                stats.innerHTML = (settings.stats || []).map(function (row) {
                    return '<div><dt>' + esc(row.value) + '</dt><dd>' + esc(row.label) + '</dd></div>';
                }).join('');
            }
        }

        function pad(n) { return (n < 10 ? '0' : '') + n; }

        function formatHourLabel(h) {
            var suffix = h >= 12 ? 'PM' : 'AM';
            var hr = h % 12;
            if (hr === 0) hr = 12;
            return hr + ' ' + suffix;
        }

        function addHours(hhmm, hours) {
            var parts = hhmm.split(':');
            var total = (parseInt(parts[0], 10) * 60) + parseInt(parts[1], 10) + (hours * 60);
            total = ((total % (24 * 60)) + (24 * 60)) % (24 * 60);
            return pad(Math.floor(total / 60)) + ':' + pad(total % 60);
        }

        function overlapsTaken(start, end, takenList) {
            function toMin(t) {
                var p = t.split(':');
                return (parseInt(p[0], 10) * 60) + parseInt(p[1], 10);
            }
            var list = takenList || state.taken || [];
            var as = toMin(start);
            var ae = toMin(end);
            if (ae <= as) ae += 24 * 60;
            return list.some(function (slot) {
                var bs = toMin(slot.start);
                var be = toMin(slot.end);
                if (be <= bs) be += 24 * 60;
                return as < be && bs < ae;
            });
        }

        function todayKey() {
            var d = new Date();
            return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
        }

        function closeTimeStr() {
            return settings.close_time || '20:50';
        }

        function timeToMinutes(t) {
            var p = String(t || '0:0').split(':');
            return (parseInt(p[0], 10) * 60) + parseInt(p[1] || '0', 10);
        }

        /** Session must finish by closing time (no overnight). */
        function sessionEndsByClose(start, hours) {
            var startM = timeToMinutes(start);
            var endM = startM + (Number(hours) * 60);
            return endM <= timeToMinutes(closeTimeStr());
        }

        function hourFitsBeforeClose(h, hours) {
            var offsets = settings.minute_offsets || [0, 15, 30, 45];
            for (var i = 0; i < offsets.length; i++) {
                var start = pad(h) + ':' + pad(offsets[i]);
                if (sessionEndsByClose(start, hours)) {
                    return true;
                }
            }
            return false;
        }

        function hourHasBookableSlot(h, hours, dateStr) {
            var offsets = settings.minute_offsets || [0, 15, 30, 45];
            var takenList = (state.monthTaken && state.monthTaken[dateStr]) || state.taken || [];
            var now = new Date();
            var isToday = dateStr === todayKey();
            for (var i = 0; i < offsets.length; i++) {
                var m = offsets[i];
                if (isToday) {
                    var slotMin = h * 60 + m;
                    var nowMin = now.getHours() * 60 + now.getMinutes();
                    if (slotMin <= nowMin) continue;
                }
                var start = pad(h) + ':' + pad(m);
                if (!sessionEndsByClose(start, hours)) continue;
                if (!overlapsTaken(start, addHours(start, hours), takenList)) {
                    return true;
                }
            }
            return false;
        }

        function dayHasFreeSlot(dateStr) {
            var pkg = selectedPackage();
            if (!pkg) return true;
            if (dateStr < todayKey()) return false;
            var openH = Number(settings.open_hour || 8);
            var hours = Number(state.sessionHours || pkg.hours || 4);
            var closeM = timeToMinutes(closeTimeStr());
            var maxHour = Math.floor((closeM - 1) / 60);
            for (var h = openH; h <= maxHour; h++) {
                if (hourHasBookableSlot(h, hours, dateStr)) {
                    return true;
                }
            }
            return false;
        }

        function syncTakenForDate() {
            state.taken = (state.date && state.monthTaken[state.date]) ? state.monthTaken[state.date] : [];
        }

        function calMonthKey() {
            return String(state.studioId) + '|' + state.calYear + '-' + pad(state.calMonth + 1);
        }

        function applyMonthTaken(byDate) {
            state.monthTaken = byDate || {};
            syncTakenForDate();
        }

        function loadMonthTaken(options) {
            var opts = options || {};
            if (!state.studioId) {
                applyMonthTaken({});
                return Promise.resolve();
            }
            var key = calMonthKey();
            if (Object.prototype.hasOwnProperty.call(monthCache, key)) {
                applyMonthTaken(monthCache[key]);
                return Promise.resolve(monthCache[key]);
            }
            var token = ++monthLoadToken;
            var month = state.calYear + '-' + pad(state.calMonth + 1);
            var body = new FormData();
            body.append('action', 'ccr_studio_taken_slots');
            body.append('nonce', data.nonce);
            body.append('studio_id', state.studioId);
            body.append('month', month);
            return fetch(data.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (json) {
                    var byDate = (json && json.success && json.data && json.data.by_date) ? json.data.by_date : {};
                    monthCache[key] = byDate;
                    if (token !== monthLoadToken || calMonthKey() !== key) {
                        return byDate;
                    }
                    applyMonthTaken(byDate);
                    if (opts.rerender) {
                        render();
                    }
                    return byDate;
                })
                .catch(function () {
                    if (token !== monthLoadToken || calMonthKey() !== key) {
                        return {};
                    }
                    monthCache[key] = {};
                    applyMonthTaken({});
                    if (opts.rerender) {
                        render();
                    }
                    return {};
                });
        }

        function shiftCalendar(delta) {
            state.calMonth += delta;
            if (state.calMonth < 0) {
                state.calMonth = 11;
                state.calYear -= 1;
            } else if (state.calMonth > 11) {
                state.calMonth = 0;
                state.calYear += 1;
            }
            var key = calMonthKey();
            if (Object.prototype.hasOwnProperty.call(monthCache, key)) {
                applyMonthTaken(monthCache[key]);
                render();
                return;
            }
            // Instant month change; availability paints in when ready.
            applyMonthTaken({});
            render();
            loadMonthTaken({ rerender: true });
        }

        function loadTaken() {
            syncTakenForDate();
            if (!state.studioId || !state.date) {
                return Promise.resolve();
            }
            return loadMonthTaken();
        }

        function recomputeEnd() {
            var pkg = selectedPackage();
            if (!pkg || state.hour === null) {
                state.start = '';
                state.end = '';
                return;
            }
            state.start = pad(state.hour) + ':' + pad(state.minute || 0);
            state.end = addHours(state.start, effectiveHours());
        }

        function addonTotal() {
            var total = 0;
            var rate = sessionRate();
            (settings.addons || []).forEach(function (addon) {
                if (state.addons.indexOf(addon.id) === -1) return;
                if (addon.status === 'coming_soon' || addon.status === 'contact') return;
                if (addon.status === 'free') return;
                if (addon.status === 'session_rate' || addon.id === 'extend') {
                    total += rate * Math.max(1, Number(state.extendHours || 1));
                    return;
                }
                total += Number(addon.price || 0);
            });
            return total;
        }

        function fullTotal() {
            return packageBasePrice() + addonTotal();
        }

        function depositDue() {
            return Math.round(fullTotal() * (state.depositPct / 100) * 100) / 100;
        }

        function calendarHtml() {
            var year = state.calYear;
            var month = state.calMonth;
            var first = new Date(year, month, 1);
            var startDow = (first.getDay() + 6) % 7; // Monday-first
            var daysInMonth = new Date(year, month + 1, 0).getDate();
            var monthLabel = first.toLocaleDateString(undefined, { month: 'long', year: 'numeric' });
            var today = todayKey();
            var html = '<div class="ccr-studio-cal" data-studio-cal>';
            html += '<div class="ccr-studio-cal-head">';
            html += '<button type="button" class="ccr-studio-cal-nav" data-cal-prev aria-label="Previous month">‹</button>';
            html += '<div class="ccr-studio-cal-title">' + esc(monthLabel) + '</div>';
            html += '<button type="button" class="ccr-studio-cal-nav" data-cal-next aria-label="Next month">›</button>';
            html += '</div>';
            html += '<div class="ccr-studio-cal-dow">';
            ['Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su'].forEach(function (d) {
                html += '<span>' + d + '</span>';
            });
            html += '</div><div class="ccr-studio-cal-grid">';
            var i;
            for (i = 0; i < startDow; i++) {
                html += '<span class="ccr-studio-cal-day is-empty"></span>';
            }
            for (var day = 1; day <= daysInMonth; day++) {
                var key = year + '-' + pad(month + 1) + '-' + pad(day);
                var past = key < today;
                var free = !past && dayHasFreeSlot(key);
                var selected = state.date === key;
                var cls = 'ccr-studio-cal-day';
                if (past) cls += ' is-past';
                else if (!free) cls += ' is-full';
                else cls += ' is-free';
                if (selected) cls += ' is-selected';
                if (key === today) cls += ' is-today';
                var disabled = past || !free;
                html += '<button type="button" class="' + cls + '" data-pick-date="' + esc(key) + '"' + (disabled ? ' disabled' : '') + '>' + day + '</button>';
            }
            html += '</div>';
            html += '<div class="ccr-studio-cal-legend"><span class="is-free">Available</span><span class="is-past">Past / full</span></div>';
            html += '</div>';
            return html;
        }

        function ensurePackage() {
            var studio = selectedStudio();
            if (!studio) return;
            var pkgs = studio.packages || [];
            if (!pkgs.length) {
                state.packageId = '';
                return;
            }
            if (!pkgs.some(function (p) { return p.id === state.packageId; })) {
                state.packageId = pkgs[0].id;
            }
            var pkg = selectedPackage();
            if (pkg && (!state.sessionHours || state.sessionHours < 1)) {
                state.sessionHours = 1;
            }
        }

        function renderStep() {
            ensurePackage();
            recomputeEnd();
            if (!mount) return;
            var html = '';
            if (current === 1) {
                html = '<div class="ccr-studio-sec"><div class="ccr-studio-sec-head"><span class="ccr-studio-sec-num">1</span> Choose a set</div>';
                studios.forEach(function (studio) {
                    var selected = String(studio.id) === String(state.studioId);
                    html += '<div class="ccr-studio-row' + (selected ? ' is-selected' : '') + '" data-pick-studio="' + esc(studio.id) + '">';
                    html += studio.image
                        ? '<img src="' + esc(studio.image) + '" alt="">'
                        : '<span class="ccr-studio-row-ph" aria-hidden="true"></span>';
                    html += '<span><span class="ccr-studio-row-title">' + esc(studio.name) + '</span>';
                    html += '<span class="ccr-studio-row-text">' + esc(studio.blurb || studio.meta || '') + '</span></span>';
                    html += '<span class="ccr-studio-check" aria-hidden="true"></span></div>';
                });
                html += '</div>';
            } else if (current === 2) {
                var studio = selectedStudio() || { packages: [], features: [], name: '' };
                var rate = sessionRate();
                html = '<div class="ccr-studio-sec"><div class="ccr-studio-sec-head"><span class="ccr-studio-sec-num">2</span> Choose your package</div>';
                html += '<p class="ccr-studio-row-text" style="margin:0 0 .75rem">' + esc(studio.name) + (studio.meta ? ' — ' + esc(studio.meta) : '') + '</p>';
                html += '<p class="ccr-studio-sec-head" style="margin-top:0">Session type</p><div class="ccr-studio-pkg-grid">';
                (studio.packages || []).forEach(function (pkg) {
                    var selected = pkg.id === state.packageId;
                    var hourly = (pkg.pricing || 'hourly') !== 'flat';
                    html += '<div class="ccr-studio-pkg' + (selected ? ' is-selected' : '') + '" data-pick-package="' + esc(pkg.id) + '">';
                    html += '<span class="ccr-studio-pkg-label">' + esc(pkg.label) + '</span>';
                    html += '<span class="ccr-studio-pkg-price">' + esc(hourly ? (money(pkg.price) + '/hr') : money(pkg.price)) + '</span></div>';
                });
                html += '</div>';
                html += '<p class="ccr-studio-sec-head" style="margin-top:1rem">Duration</p><div class="ccr-studio-hour-grid ccr-studio-duration-grid">';
                [1, 2, 3, 4, 5, 6, 7, 8].forEach(function (hrs) {
                    html += '<button type="button" class="ccr-studio-hour' + (Number(state.sessionHours) === hrs ? ' is-selected' : '') + '" data-pick-duration="' + hrs + '">' + hrs + ' hr</button>';
                });
                html += '</div>';
                html += '<div class="ccr-studio-session-bar" style="margin-top:.85rem">Session total · <strong>' + esc(money(packageBasePrice())) + '</strong>';
                if (rate) {
                    html += ' <span style="color:#9ca3af">(' + esc(money(rate)) + '/hr × ' + esc(String(state.sessionHours)) + ')</span>';
                }
                html += '</div>';
                if ((studio.features || []).length) {
                    html += '<button type="button" class="ccr-studio-features-toggle" data-toggle-features>What\'s included · ' + studio.features.length + ' features</button>';
                    html += '<ul class="ccr-studio-features-list"' + (state.featuresOpen ? '' : ' hidden') + '>';
                    studio.features.forEach(function (f) { html += '<li>' + esc(f) + '</li>'; });
                    html += '</ul>';
                }
                html += '</div>';
            } else if (current === 3) {
                var pkg = selectedPackage();
                var openH = Number(settings.open_hour || 8);
                var pkgHours = Number(state.sessionHours || (pkg && pkg.hours) || 4);
                var closeLabel = closeTimeStr();
                html = '<div class="ccr-studio-sec"><div class="ccr-studio-sec-head"><span class="ccr-studio-sec-num">3</span> Date &amp; time</div>';
                html += '<div class="ccr-studio-field"><span>Shoot date</span>' + calendarHtml() + '</div>';
                if (state.date) {
                    try {
                        html += '<p class="ccr-studio-day-label">' + esc(new Date(state.date + 'T12:00:00').toLocaleDateString(undefined, { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' })) + '</p>';
                    } catch (e) { /* ignore */ }
                }
                html += '<p class="ccr-studio-day-label">Studio closes at ' + esc(closeLabel) + ' — sessions must finish by then.</p>';
                if (state.date) {
                    html += '<p class="ccr-studio-sec-head" style="margin-top:1rem">Step 1 — Pick an hour</p><div class="ccr-studio-hour-grid">';
                    var maxHour = Math.floor((timeToMinutes(closeLabel) - 1) / 60);
                    var anyHour = false;
                    var anyBookable = false;
                    for (var h = openH; h <= maxHour; h++) {
                        // Still show hours that fit before close, even when fully booked — grey them out.
                        if (!hourFitsBeforeClose(h, pkgHours)) continue;
                        var hourOk = hourHasBookableSlot(h, pkgHours, state.date);
                        anyHour = true;
                        if (hourOk) anyBookable = true;
                        html += '<button type="button" class="ccr-studio-hour' + (state.hour === h ? ' is-selected' : '') + (hourOk ? '' : ' is-taken') + '" data-pick-hour="' + h + '"' + (hourOk ? '' : ' disabled') + ' aria-disabled="' + (hourOk ? 'false' : 'true') + '">' + esc(formatHourLabel(h)) + '</button>';
                    }
                    html += '</div>';
                    if (anyHour) {
                        html += '<div class="ccr-studio-cal-legend"><span class="is-free">Available</span><span class="is-past">Booked / unavailable</span></div>';
                    }
                    if (!anyHour) {
                        html += '<p class="ccr-studio-day-label">No start times fit a ' + esc(String(pkgHours)) + '-hour session before closing.</p>';
                    } else if (!anyBookable) {
                        html += '<p class="ccr-studio-day-label">All start hours are booked for this day. Pick another date.</p>';
                    }
                } else {
                    html += '<p class="ccr-studio-day-label">Select an available day to continue.</p>';
                }
                if (state.hour !== null && pkg) {
                    html += '<p class="ccr-studio-sec-head" style="margin-top:1rem">Step 2 — Pick the minutes</p><div class="ccr-studio-minute-grid">';
                    (settings.minute_offsets || [0, 15, 30, 45]).forEach(function (m) {
                        var start = pad(state.hour) + ':' + pad(m);
                        var end = addHours(start, pkgHours);
                        var afterClose = !sessionEndsByClose(start, pkgHours);
                        var blocked = afterClose || overlapsTaken(start, end);
                        html += '<button type="button" class="ccr-studio-minute' + (state.minute === m ? ' is-selected' : '') + (blocked ? ' is-taken' : '') + '" data-pick-minute="' + m + '"' + (blocked ? ' disabled' : '') + '>';
                        html += '<strong>' + esc(start) + '</strong><span>→ ' + esc(end) + (afterClose ? ' (after close)' : '') + '</span></button>';
                    });
                    html += '</div>';
                    if (state.start && state.end) {
                        html += '<div class="ccr-studio-session-bar">' + esc(String(pkgHours)) + '-hour session · <strong>' + esc(state.start) + ' → ' + esc(addHours(state.start, pkgHours)) + '</strong></div>';
                    }
                }
                html += '</div>';
            } else if (current === 4) {
                var waDigits = String(settings.whatsapp || '').replace(/\D+/g, '');
                var waBase = waDigits ? ('https://wa.me/' + waDigits + '?text=') : '';
                html = '<div class="ccr-studio-sec"><div class="ccr-studio-sec-head"><span class="ccr-studio-sec-num">4</span> Add-ons <span style="color:#9ca3af;font-weight:600;letter-spacing:0">Optional extras</span></div>';
                (settings.addons || []).forEach(function (addon) {
                    var disabled = addon.status === 'coming_soon';
                    var checked = state.addons.indexOf(addon.id) !== -1;
                    var isExtend = addon.status === 'session_rate' || addon.id === 'extend';
                    var isContact = addon.status === 'contact';
                    html += '<label class="ccr-studio-addon' + ((disabled || isContact) ? ' is-disabled' : '') + '">';
                    html += '<span><span class="ccr-studio-addon-name">' + esc(addon.name) + '</span>';
                    html += '<span class="ccr-studio-addon-hint">' + esc(addon.hint || '') + '</span>';
                    if (isExtend && checked) {
                        html += '<span class="ccr-studio-addon-extend"><span>Extra hours</span><input type="number" min="1" max="8" step="1" data-extend-hours value="' + esc(String(state.extendHours || 1)) + '"></span>';
                    }
                    html += '</span>';
                    if (addon.status === 'coming_soon') {
                        html += '<span class="ccr-studio-addon-price is-soon">COMING SOON</span><span></span>';
                    } else if (isContact) {
                        html += '<span class="ccr-studio-addon-price is-contact">Contact</span>';
                        if (waBase) {
                            html += '<a class="ccr-studio-contact-link" href="' + esc(waBase + encodeURIComponent('Hi — I need a quote for: ' + addon.name + ' with my studio booking.')) + '" target="_blank" rel="noopener noreferrer">WhatsApp</a>';
                        } else {
                            html += '<span></span>';
                        }
                    } else if (addon.status === 'free') {
                        html += '<span class="ccr-studio-addon-price is-free">FREE</span>';
                        html += '<input type="checkbox" data-addon="' + esc(addon.id) + '"' + (checked ? ' checked' : '') + '>';
                    } else if (isExtend) {
                        html += '<span class="ccr-studio-addon-price">' + esc(money(sessionRate())) + '/hr</span>';
                        html += '<input type="checkbox" data-addon="' + esc(addon.id) + '"' + (checked ? ' checked' : '') + '>';
                    } else {
                        html += '<span class="ccr-studio-addon-price">' + esc(money(addon.price)) + '</span>';
                        html += '<input type="checkbox" data-addon="' + esc(addon.id) + '"' + (checked ? ' checked' : '') + '>';
                    }
                    html += '</label>';
                });
                html += '</div>';
            } else if (current === 5) {
                html = '<div class="ccr-studio-sec"><div class="ccr-studio-sec-head"><span class="ccr-studio-sec-num">5</span> Your details</div><div class="ccr-studio-fields-grid">';
                html += '<label class="ccr-studio-field"><span>Full name</span><input type="text" data-field="name" value="' + esc(state.name) + '" placeholder="e.g. Ama Owusu"></label>';
                html += '<label class="ccr-studio-field"><span>Phone (WhatsApp)</span><input type="tel" data-field="phone" value="' + esc(state.phone) + '" placeholder="0XX XXX XXXX"></label>';
                html += '<label class="ccr-studio-field"><span>Email (optional)</span><input type="email" data-field="email" value="' + esc(state.email) + '"></label>';
                html += '<label class="ccr-studio-field ccr-studio-field--full"><span>Notes (optional)</span><textarea rows="3" data-field="notes">' + esc(state.notes) + '</textarea></label>';
                html += '</div></div>';
            } else if (current === 6) {
                var studioName = selectedStudio() ? selectedStudio().name : '';
                var pkgLabel = selectedPackage() ? selectedPackage().label : '';
                var momoNumber = settings.momo_pay_number || '';
                var momoNetwork = settings.momo_pay_network || 'MTN';
                var momoRef = settings.momo_reference || 'Studio Rentals';
                html = '<div class="ccr-studio-sec"><div class="ccr-studio-sec-head"><span class="ccr-studio-sec-num">6</span> Full payment to confirm</div>';
                html += '<div class="ccr-studio-momo-box">';
                html += '<div class="ccr-studio-momo-row"><span>Amount due</span><strong>' + esc(money(fullTotal())) + '</strong></div>';
                html += '<div class="ccr-studio-momo-row"><span>Network</span><strong>' + esc(momoNetwork) + '</strong></div>';
                html += '<div class="ccr-studio-momo-row"><span>MoMo number</span><strong>' + esc(momoNumber) + '</strong></div>';
                html += '<div class="ccr-studio-momo-row"><span>Reference</span><strong>' + esc(momoRef) + '</strong></div>';
                html += '<p class="ccr-studio-momo-note">Send the full amount by Mobile Money using this exact reference. After you confirm, WhatsApp us your payment screenshot.</p>';
                html += '</div>';
                html += '<div class="ccr-studio-book-summary"><h3>Booking summary</h3><dl>';
                html += '<div><dt>Set</dt><dd>' + esc(studioName) + ' — ' + esc(pkgLabel) + ' · ' + esc(String(state.sessionHours)) + ' hr</dd></div>';
                html += '<div><dt>Rate</dt><dd>' + esc(money(sessionRate())) + '/hr</dd></div>';
                html += '<div><dt>Date</dt><dd>' + esc(state.date) + '</dd></div>';
                html += '<div><dt>Time</dt><dd>' + esc(state.start) + ' → ' + esc(state.end) + '</dd></div>';
                if (extendSelected()) {
                    html += '<div><dt>Extension</dt><dd>+' + esc(String(state.extendHours || 1)) + ' hr @ ' + esc(money(sessionRate())) + '/hr</dd></div>';
                }
                html += '<div><dt>Total</dt><dd>' + esc(money(fullTotal())) + '</dd></div>';
                html += '<div><dt>Due now</dt><dd>' + esc(money(fullTotal())) + ' via MoMo (100%)</dd></div>';
                html += '</dl></div></div>';
            }
            mount.innerHTML = html;
        }

        function renderChrome() {
            var pct = Math.round((current / totalSteps) * 100);
            if (stepLabel) stepLabel.textContent = 'Step ' + current + ' of ' + totalSteps;
            if (stepPct) stepPct.textContent = pct + '%';
            if (progress) progress.style.width = pct + '%';
            if (stepSub) stepSub.textContent = subs[current] || '';
            if (prevBtn) prevBtn.hidden = current === 1;
            if (nextBtn) nextBtn.hidden = current === totalSteps;
            if (payBtn) {
                payBtn.hidden = current !== totalSteps;
                if (data.i18n && data.i18n.pay) {
                    payBtn.textContent = data.i18n.pay;
                }
            }
        }

        function validate() {
            showError('');
            if (current === 1 && !state.studioId) { showError('Please select a set.'); return false; }
            if (current === 2 && !state.packageId) { showError('Please select a package.'); return false; }
            if (current === 3) {
                if (!state.date) { showError('Please choose a date.'); return false; }
                if (state.date < todayKey() || !dayHasFreeSlot(state.date)) {
                    showError('Please choose an available day.');
                    return false;
                }
                if (state.hour === null || !state.start) { showError('Please pick a start time.'); return false; }
                if (!sessionEndsByClose(state.start, Number(state.sessionHours || 4))) {
                    showError('That session ends after closing (' + closeTimeStr() + '). Pick an earlier start.');
                    return false;
                }
                if (overlapsTaken(state.start, addHours(state.start, Number(state.sessionHours || 4)))) { showError('That slot is taken. Pick another time.'); return false; }
            }
            if (current === 4 && extendSelected() && state.start) {
                if (!sessionEndsByClose(state.start, effectiveHours())) {
                    showError('Extra hours push the session past closing (' + closeTimeStr() + '). Reduce extension or pick an earlier start.');
                    return false;
                }
            }
            if (current === 5) {
                if (!state.name.trim()) { showError('Please enter your name.'); return false; }
                if (!state.phone.trim()) { showError('Please enter your phone / WhatsApp number.'); return false; }
            }
            return true;
        }

        function render() {
            renderChrome();
            renderStep();
        }

        if (mount) {
            mount.addEventListener('click', function (e) {
                var studioBtn = e.target.closest('[data-pick-studio]');
                if (studioBtn) {
                    state.studioId = studioBtn.getAttribute('data-pick-studio');
                    state.packageId = '';
                    state.date = '';
                    state.hour = null;
                    state.monthTaken = {};
                    monthCache = {};
                    monthLoadToken += 1;
                    ensurePackage();
                    render();
                    return;
                }
                var pkgBtn = e.target.closest('[data-pick-package]');
                if (pkgBtn) {
                    state.packageId = pkgBtn.getAttribute('data-pick-package');
                    state.date = '';
                    state.hour = null;
                    render();
                    return;
                }
                var durBtn = e.target.closest('[data-pick-duration]');
                if (durBtn) {
                    state.sessionHours = parseInt(durBtn.getAttribute('data-pick-duration'), 10) || 1;
                    state.date = '';
                    state.hour = null;
                    render();
                    return;
                }
                if (e.target.closest('.ccr-studio-contact-link')) {
                    return;
                }
                if (e.target.closest('[data-toggle-features]')) {
                    state.featuresOpen = !state.featuresOpen;
                    render();
                    return;
                }
                if (e.target.closest('[data-cal-prev]')) {
                    shiftCalendar(-1);
                    return;
                }
                if (e.target.closest('[data-cal-next]')) {
                    shiftCalendar(1);
                    return;
                }
                var dateBtn = e.target.closest('[data-pick-date]');
                if (dateBtn && !dateBtn.disabled) {
                    state.date = dateBtn.getAttribute('data-pick-date');
                    state.hour = null;
                    state.minute = 0;
                    syncTakenForDate();
                    render();
                    return;
                }
                var hourBtn = e.target.closest('[data-pick-hour]');
                if (hourBtn && !hourBtn.disabled) {
                    state.hour = parseInt(hourBtn.getAttribute('data-pick-hour'), 10);
                    var offsets = settings.minute_offsets || [0, 15, 30, 45];
                    var pkgH = Number(state.sessionHours || (selectedPackage() || {}).hours || 4);
                    state.minute = offsets[0] || 0;
                    for (var oi = 0; oi < offsets.length; oi++) {
                        var cand = pad(state.hour) + ':' + pad(offsets[oi]);
                        if (sessionEndsByClose(cand, pkgH) && !overlapsTaken(cand, addHours(cand, pkgH))) {
                            state.minute = offsets[oi];
                            break;
                        }
                    }
                    recomputeEnd();
                    render();
                    return;
                }
                var minBtn = e.target.closest('[data-pick-minute]');
                if (minBtn && !minBtn.disabled) {
                    state.minute = parseInt(minBtn.getAttribute('data-pick-minute'), 10);
                    recomputeEnd();
                    render();
                    return;
                }
            });

            mount.addEventListener('change', function (e) {
                var t = e.target;
                if (!t) return;
                if (t.getAttribute('data-field')) {
                    state[t.getAttribute('data-field')] = t.value;
                    renderChrome();
                    return;
                }
                if (t.getAttribute('data-addon')) {
                    var id = t.getAttribute('data-addon');
                    if (t.checked) {
                        if (state.addons.indexOf(id) === -1) state.addons.push(id);
                    } else {
                        state.addons = state.addons.filter(function (x) { return x !== id; });
                    }
                    render();
                    return;
                }
                if (t.getAttribute('data-extend-hours') !== null) {
                    state.extendHours = Math.max(1, Math.min(8, parseInt(t.value, 10) || 1));
                    render();
                }
            });
        }

        if (prevBtn) {
            prevBtn.addEventListener('click', function () {
                if (current > 1) {
                    current -= 1;
                    showError('');
                    render();
                }
            });
        }

        if (nextBtn) {
            nextBtn.addEventListener('click', function () {
                if (!validate()) return;
                if (current < totalSteps) {
                    current += 1;
                    if (current === 3) {
                        var now = new Date();
                        state.calYear = now.getFullYear();
                        state.calMonth = now.getMonth();
                        var key = calMonthKey();
                        if (Object.prototype.hasOwnProperty.call(monthCache, key)) {
                            applyMonthTaken(monthCache[key]);
                            render();
                            return;
                        }
                        applyMonthTaken({});
                        render();
                        loadMonthTaken({ rerender: true });
                        return;
                    }
                    render();
                }
            });
        }

        if (payBtn) {
            payBtn.addEventListener('click', function () {
                if (!validate()) return;
                payBtn.disabled = true;
                showError('');
                var body = new FormData();
                body.append('action', 'ccr_studio_create_booking');
                body.append('nonce', data.nonce);
                body.append('studio_id', state.studioId);
                body.append('package_id', state.packageId);
                body.append('session_hours', String(state.sessionHours || 1));
                body.append('extend_hours', extendSelected() ? String(state.extendHours || 1) : '0');
                body.append('date', state.date);
                body.append('start', state.start);
                body.append('end', state.end);
                body.append('deposit_pct', '100');
                body.append('name', state.name);
                body.append('phone', state.phone);
                body.append('email', state.email);
                body.append('notes', state.notes);
                state.addons.forEach(function (id) { body.append('addons[]', id); });

                fetch(data.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (json) {
                        if (!json || !json.success || !json.data || !json.data.confirm_url) {
                            showError((json && json.data && json.data.message) || (data.i18n && data.i18n.error) || 'Could not create booking.');
                            payBtn.disabled = false;
                            return;
                        }
                        window.location.href = json.data.confirm_url;
                    })
                    .catch(function () {
                        showError((data.i18n && data.i18n.error) || 'Could not create booking.');
                        payBtn.disabled = false;
                    });
            });
        }

        fillHero();
        ensurePackage();
        render();
    })();
});
