/**
 * Rentopian Checkout Visual Builder
 *
 * Drag-and-drop visual builder for the checkout layout.
 * Works with the existing admin template DOM and the rentalCheckoutLayout
 * localized object provided by class-checkout-layout-manager.php.
 *
 * @package    Rentopian_Sync
 * @subpackage Assets/JS
 * @since      2.14.0
 */
(function ($) {
    'use strict';

    var RVB = function () {
        this.layout      = null;
        this.fields       = {};
        this.fieldGroups  = {};
        this.mandatoryFields = [];
        this.placedFields = [];
        this.isSaving     = false;
        this._msgTimeout  = null;
        this._loaded      = false;

        this.hiddenVisualAllowedFields = [
            'billing_address_2',  'billing_city',  'billing_state',  'billing_postcode',  'billing_country',
            'shipping_address_2', 'shipping_city', 'shipping_state', 'shipping_postcode', 'shipping_country',
            'pickup_address_2',   'pickup_city',   'pickup_state',   'pickup_postcode',   'pickup_country',
            // Only hides when activated (checked); see the frontend handler.
            'ship_to_different_address'
        ];

        this.dependsOnParentFields = [
            'ship_to_different_address',
            'rental_different_pickup_address',
            'rental_multi_day_event',
            'billing_address_1'
        ];

        this.widthOptions = [3, 4, 5, 6, 7, 8, 9, 10, 12];
    };

    // =========================================================================
    // INIT
    // =========================================================================

    RVB.prototype.init = function () {
        var self = this;

        if (typeof rentalCheckoutLayout === 'undefined') {
            console.error('[VisualBuilder] rentalCheckoutLayout not found');
            return;
        }

        var cfg = rentalCheckoutLayout;
        this.fields          = cfg.fields || {};
        this.fieldGroups     = cfg.fieldGroups || {};
        this.mandatoryFields = cfg.mandatoryFields || [];

        // Without a Google Address Autocomplete key these address fields cannot be
        // auto-filled, so they may not be hidden — remove them from the allow-list.
        if (!cfg.hasGoogleApiKey) {
            var noHide = [
                'billing_city', 'billing_state', 'billing_postcode',
                'shipping_city', 'shipping_state', 'shipping_postcode'
            ];
            this.hiddenVisualAllowedFields = $.grep(this.hiddenVisualAllowedFields, function (f) {
                return $.inArray(f, noHide) === -1;
            });
        }

        this.buildPalette();
        this.bindEvents();

        $(document).on('rental-tab-switched', function (e, tabId) {
            if (tabId === 'visual-builder' && !self._loaded) {
                self.loadLayout();
            }
        });

        $(document).on('click', '#rvb-load-current', function () {
            self.loadLayout();
        });
    };

    // =========================================================================
    // PALETTE
    // =========================================================================

    RVB.prototype.buildPalette = function () {
        var self  = this;
        var $container = $('#rvb-palette-groups');
        if (!$container.length) return;

        var groupFieldsMap = {};
        $.each(this.fields, function (fieldId, field) {
            var g = field.group || 'other';
            if (!groupFieldsMap[g]) groupFieldsMap[g] = [];
            groupFieldsMap[g].push(fieldId);
        });

        var groupLabels = {
            'billing':          'Billing Fields',
            'shipping':         'Shipping Fields',
            'order':            'Order Fields',
            'rental_component': 'Rental Components',
            'rental_static':    'Rental Static',
            'rental_dynamic':   'Rental Custom / API',
            'other':            'Other'
        };

        $.each(this.fieldGroups, function (gid, g) {
            if (g.label) groupLabels[gid] = g.label;
        });

        var groupOrder = ['billing', 'shipping', 'order', 'rental_component', 'rental_static', 'rental_dynamic', 'other'];
        $.each(groupFieldsMap, function (gid) {
            if ($.inArray(gid, groupOrder) === -1) groupOrder.push(gid);
        });

        var html = '';
        $.each(groupOrder, function (_, gid) {
            var fieldIds = groupFieldsMap[gid];
            if (!fieldIds || fieldIds.length === 0) return;
            var label = groupLabels[gid] || gid;

            html += '<div class="rvb-palette-group" data-group="' + gid + '">';
            html += '<div class="rvb-palette-group-header">';
            html += '<span class="dashicons dashicons-arrow-down-alt2 rvb-group-arrow"></span>';
            html += '<span class="rvb-group-label">' + self.esc(label) + '</span>';
            html += '<span class="rvb-group-count">' + fieldIds.length + '</span>';
            html += '</div>';
            html += '<div class="rvb-palette-group-body">';

            $.each(fieldIds, function (_, fid) {
                var f = self.fields[fid];
                if (!f) return;
                var isMandatory = $.inArray(fid, self.mandatoryFields) !== -1;
                var badgeCls = isMandatory ? 'rvb-badge--mandatory' : 'rvb-badge--optional';
                var badgeTxt = isMandatory ? 'M' : 'O';

                html += '<div class="rvb-palette-field" data-field-id="' + fid + '" draggable="true">';
                html += '<span class="rvb-pf-label">' + self.esc(f.label || fid) + '</span>';
                html += '<code class="rvb-pf-id">' + self.esc(fid) + '</code>';
                html += '<span class="rvb-badge ' + badgeCls + '">' + badgeTxt + '</span>';
                html += '</div>';
            });

            html += '</div></div>';
        });

        $container.html(html);
    };

    // =========================================================================
    // LAYOUT LOADING
    // =========================================================================

    RVB.prototype.loadLayout = function () {
        var self = this;

        var $ta = $('#rental-layout-json');
        if ($ta.length && $.trim($ta.val())) {
            try {
                self.layout = JSON.parse($ta.val());
                self._loaded = true;
                self.buildCanvas();
                return;
            } catch (e) { /* fall through */ }
        }

        if (rentalCheckoutLayout.currentLayout && rentalCheckoutLayout.currentLayout.sections) {
            self.layout = rentalCheckoutLayout.currentLayout;
            self._loaded = true;
            self.buildCanvas();
            return;
        }

        $.post(rentalCheckoutLayout.ajaxUrl, {
            action: 'rental_checkout_layout_get',
            nonce: rentalCheckoutLayout.nonce
        }, function (resp) {
            if (resp.success && resp.data && resp.data.layout) {
                self.layout = resp.data.layout;
            } else {
                self.layout = { sections: [] };
            }
            self._loaded = true;
            self.buildCanvas();
        }).fail(function () {
            self.showMsg('Failed to load layout.', 'error');
        });
    };

    // =========================================================================
    // CANVAS
    // =========================================================================

    RVB.prototype.buildCanvas = function () {
        var self = this;
        var $canvas = $('#rvb-canvas');
        var $empty  = $('#rvb-canvas-empty');

        $canvas.find('.rvb-section').remove();
        this.placedFields = [];

        if (!this.layout || !this.layout.sections || this.layout.sections.length === 0) {
            $empty.show();
            this.updatePaletteState();
            return;
        }

        $empty.hide();

        $.each(this.layout.sections, function (sIdx, section) {
            $canvas.append(self.renderSection(section, sIdx));
        });

        this.initSortable();
        this.updatePaletteState();
        this.updateAllWidthBars();
        this.updateDependencyIndicators();
    };

    // =========================================================================
    // RENDER
    // =========================================================================

    RVB.prototype.renderSection = function (section, sIdx) {
        var self = this;
        var id        = section.id || 'section_' + sIdx;
        var title     = section.title || '';
        var desc      = section.description || '';
        var cls       = section['class'] || '';
        var showTitle = section.show_title !== false;
        var showDesc  = section.show_description === true;

        var $s = $(
            '<div class="rvb-section" data-section-idx="' + sIdx + '" data-section-id="' + self.escAttr(id) + '">' +
              '<div class="rvb-section-header">' +
                '<span class="rvb-drag-handle dashicons dashicons-move" title="Drag to reorder section"></span>' +
                '<div class="rvb-section-info">' +
                  '<div class="rvb-section-row">' +
                    '<input type="text" class="rvb-section-title" value="' + self.escAttr(title) + '" placeholder="Section Title" />' +
                    '<label class="rvb-toggle-sm"><input type="checkbox" class="rvb-show-title" ' + (showTitle ? 'checked' : '') + ' /> Show Title</label>' +
                  '</div>' +
                  '<div class="rvb-section-row">' +
                    '<input type="text" class="rvb-section-desc" value="' + self.escAttr(desc) + '" placeholder="Section description (optional)" />' +
                    '<label class="rvb-toggle-sm"><input type="checkbox" class="rvb-show-desc" ' + (showDesc ? 'checked' : '') + ' /> Show Desc</label>' +
                  '</div>' +
                  '<div class="rvb-section-meta">' +
                    '<span>ID: <code>' + self.esc(id) + '</code></span>' +
                    '<span>Class: <input type="text" class="rvb-section-cls rvb-mini-input" value="' + self.escAttr(cls) + '" placeholder="CSS class" /></span>' +
                  '</div>' +
                '</div>' +
                '<div class="rvb-section-btns">' +
                  '<div class="rvb-order-btns">' +
                    '<button type="button" class="rvb-order-btn rvb-order-higher" title="Move section up"><span class="order-higher-indicator" aria-hidden="true"></span></button>' +
                    '<button type="button" class="rvb-order-btn rvb-order-lower" title="Move section down"><span class="order-lower-indicator" aria-hidden="true"></span></button>' +
                  '</div>' +
                  '<button type="button" class="rvb-icon-btn rvb-toggle-section" title="Collapse/Expand"><span class="dashicons dashicons-arrow-up-alt2"></span></button>' +
                  '<button type="button" class="rvb-icon-btn rvb-add-row-btn" title="Add Row"><span class="dashicons dashicons-plus"></span></button>' +
                  '<button type="button" class="rvb-icon-btn rvb-icon-btn--danger rvb-remove-section" title="Remove Section"><span class="dashicons dashicons-trash"></span></button>' +
                '</div>' +
              '</div>' +
              '<div class="rvb-section-body">' +
                '<div class="rvb-rows-container"></div>' +
                '<div class="rvb-drop-zone rvb-drop-new-row"><span class="dashicons dashicons-plus"></span> Drop field here to create a new row</div>' +
              '</div>' +
            '</div>'
        );

        var $rows = $s.find('.rvb-rows-container');
        if (section.rows && section.rows.length) {
            $.each(section.rows, function (rIdx, row) {
                $rows.append(self.renderRow(row, rIdx));
            });
        }
        return $s;
    };

    RVB.prototype.renderRow = function (row, rIdx) {
        var self  = this;
        var rowId = row.id || 'row_' + rIdx;

        var $r = $(
            '<div class="rvb-row" data-row-idx="' + rIdx + '" data-row-id="' + self.escAttr(rowId) + '">' +
              '<div class="rvb-row-header">' +
                '<span class="rvb-drag-handle dashicons dashicons-move" title="Drag to reorder row"></span>' +
                '<span class="rvb-row-label">' + self.esc(rowId) + '</span>' +
                '<div class="rvb-width-bar"><div class="rvb-width-fill"></div><span class="rvb-width-text"></span></div>' +
                '<div class="rvb-row-btns">' +
                  '<div class="rvb-order-btns">' +
                    '<button type="button" class="rvb-order-btn rvb-order-higher" title="Move row up"><span class="order-higher-indicator" aria-hidden="true"></span></button>' +
                    '<button type="button" class="rvb-order-btn rvb-order-lower" title="Move row down"><span class="order-lower-indicator" aria-hidden="true"></span></button>' +
                  '</div>' +
                  '<button type="button" class="rvb-icon-btn rvb-toggle-row" title="Collapse/Expand row"><span class="dashicons dashicons-arrow-up-alt2"></span></button>' +
                  '<button type="button" class="rvb-icon-btn rvb-icon-btn--danger rvb-remove-row" title="Remove Row"><span class="dashicons dashicons-trash"></span></button>' +
                '</div>' +
              '</div>' +
              '<div class="rvb-row-body">' +
                '<div class="rvb-cols-container"></div>' +
              '</div>' +
            '</div>'
        );

        var $cols = $r.find('.rvb-cols-container');
        if (row.columns && row.columns.length) {
            $.each(row.columns, function (_, col) {
                $cols.append(self.renderColumn(col));
            });
        }
        $cols.append('<div class="rvb-drop-zone rvb-drop-add-col"><span class="dashicons dashicons-plus"></span></div>');
        return $r;
    };

    RVB.prototype.renderColumn = function (col) {
        var self      = this;
        var fid       = col.field || '';
        var field     = self.fields[fid] || {};
        var defaultLabel = field.label || fid || '(empty)';
        // Label priority: col.label_override (VB) → field.label (textual/registry) → fid
        var label     = col.label_override || defaultLabel;
        var w         = col.width || 6;
        var active    = col.active !== 0 && col.active !== false;
        var req       = col.required === true;
        var hv        = col.hidden_visual === 1 || col.hidden_visual === true;
        var dep       = col.depends_on || '';
        var mandatory = $.inArray(fid, self.mandatoryFields) !== -1;
        var canHV     = $.inArray(fid, self.hiddenVisualAllowedFields) !== -1;
        var isCheckbox = field.type === 'checkbox';
        var checkedDefault = col.checked === 1 || col.checked === true;
        var hasOverride = col.label_override ? true : false;

        if (fid) self.placedFields.push(fid);

        var badge;
        if (mandatory) badge = '<span class="rvb-badge rvb-badge--mandatory" title="Mandatory">M</span>';
        else if (req)  badge = '<span class="rvb-badge rvb-badge--required" title="Required">R</span>';
        else           badge = '<span class="rvb-badge rvb-badge--optional" title="Optional">O</span>';

        var depBadge = dep ? '<span class="rvb-dep-child" title="Depends on: ' + self.escAttr(dep) + '">⤴ ' + self.esc(dep) + '</span>' : '';

        var $c = $(
            '<div class="rvb-column' + (!active ? ' rvb-col--inactive' : '') + (hv ? ' rvb-col--hidden-visual' : '') + '" data-field-id="' + self.escAttr(fid) + '" data-width="' + w + '">' +
              '<div class="rvb-col-head">' +
                '<span class="rvb-drag-handle dashicons dashicons-move" title="Drag"></span>' +
                '<span class="rvb-col-label">' + self.esc(label) + '</span>' +
                badge + depBadge +
              '</div>' +
              '<div class="rvb-col-id"><code>' + self.esc(fid) + '</code></div>' +
              '<div class="rvb-col-controls">' +
                '<div class="rvb-ctrl-row rvb-ctrl-label-row">' +
                  '<label class="rvb-ctrl-lbl">Label</label>' +
                  '<input type="text" class="rvb-col-label-override" value="' + self.escAttr(col.label_override || '') + '" placeholder="' + self.escAttr(defaultLabel) + '" title="Custom label override. Leave empty to use default." />' +
                  (hasOverride ? '<button type="button" class="rvb-clear-label-override rvb-icon-btn" title="Clear override"><span class="dashicons dashicons-no-alt"></span></button>' : '') +
                '</div>' +
                '<div class="rvb-ctrl-row">' +
                  '<label class="rvb-ctrl-lbl">Width</label>' +
                  '<select class="rvb-col-width">' + self.widthOpts(w) + '</select>' +
                '</div>' +
                '<div class="rvb-ctrl-row">' +
                  '<label class="rvb-ctrl-inline"><input type="checkbox" class="rvb-col-required" ' + ((req || mandatory) ? 'checked' : '') + (mandatory ? ' disabled' : '') + ' /> Required</label>' +
                  '<label class="rvb-ctrl-inline"><input type="checkbox" class="rvb-col-active" ' + (active ? 'checked' : '') + (mandatory ? ' disabled' : '') + ' /> Active</label>' +
                  (canHV ? '<label class="rvb-ctrl-inline" title="In DOM but visually hidden (for autocomplete)"><input type="checkbox" class="rvb-col-hv" ' + (hv ? 'checked' : '') + ' /> Hidden Visual</label>' : '') +
                  (isCheckbox ? '<label class="rvb-ctrl-inline" title="Checked by default"><input type="checkbox" class="rvb-col-checked" ' + (checkedDefault ? 'checked' : '') + ' /> Checked</label>' : '') +
                '</div>' +
                self.renderDependsOn(fid, dep) +
                '<div class="rvb-ctrl-row rvb-ctrl-actions">' +
                  (!mandatory
                    ? '<button type="button" class="rvb-icon-btn rvb-icon-btn--danger rvb-remove-col" title="Remove"><span class="dashicons dashicons-no-alt"></span></button>'
                    : '<span class="rvb-lock" title="Mandatory"><span class="dashicons dashicons-lock"></span></span>') +
                '</div>' +
              '</div>' +
            '</div>'
        );
        return $c;
    };

    RVB.prototype.widthOpts = function (sel) {
        var h = '';
        $.each(this.widthOptions, function (_, w) {
            h += '<option value="' + w + '"' + (w === sel ? ' selected' : '') + '>' + w + ' col</option>';
        });
        return h;
    };

    RVB.prototype.renderDependsOn = function (fid, cur) {
        var self = this;
        if ($.inArray(fid, self.dependsOnParentFields) !== -1) return '';
        var h = '<div class="rvb-ctrl-row rvb-ctrl-dep">';
        h += '<label class="rvb-ctrl-lbl">Depends on</label>';
        h += '<select class="rvb-col-depends-on">';
        h += '<option value="">— None —</option>';
        $.each(self.dependsOnParentFields, function (_, pid) {
            var pf = self.fields[pid];
            var pl = pf ? pf.label : pid;
            h += '<option value="' + pid + '"' + (cur === pid ? ' selected' : '') + '>' + self.esc(pl) + '</option>';
        });
        h += '</select></div>';
        return h;
    };

    // =========================================================================
    // SORTABLE & DRAG-DROP
    // =========================================================================

    RVB.prototype.initSortable = function () {
        var self = this;

        // Safely destroy existing sortable instances before re-init
        // (prevents duplicate binding when called after adding rows)
        try { $('#rvb-canvas').sortable('destroy'); } catch (e) {}
        try { $('#rvb-canvas .rvb-rows-container').sortable('destroy'); } catch (e) {}
        try { $('#rvb-canvas .rvb-cols-container').sortable('destroy'); } catch (e) {}

        // SECTIONS — handle is matched WITHIN each .rvb-section item
        // (NOT from the container — do NOT prefix with '> .rvb-section >')
        $('#rvb-canvas').sortable({
            handle: '.rvb-section-header .rvb-drag-handle',
            items: '> .rvb-section',
            placeholder: 'rvb-sort-ph rvb-sort-ph--section',
            tolerance: 'pointer',
            opacity: 0.85,
            update: function () {
                self.dirty();
                self.updateOrderButtons();
            }
        });

        // ROWS — handle is matched WITHIN each .rvb-row item
        $('#rvb-canvas .rvb-rows-container').sortable({
            handle: '.rvb-row-header .rvb-drag-handle',
            items: '> .rvb-row',
            connectWith: '#rvb-canvas .rvb-rows-container',
            placeholder: 'rvb-sort-ph rvb-sort-ph--row',
            tolerance: 'pointer',
            opacity: 0.85,
            update: function () {
                self.dirty();
                self.updateAllWidthBars();
                self.updateOrderButtons();
            }
        });

        // COLUMNS — already working (handle doesn't include item class)
        $('#rvb-canvas .rvb-cols-container').sortable({
            handle: '.rvb-col-head > .rvb-drag-handle',
            items: '> .rvb-column',
            connectWith: '#rvb-canvas .rvb-cols-container',
            placeholder: 'rvb-sort-ph rvb-sort-ph--col',
            tolerance: 'pointer',
            update: function () {
                self.dirty();
                self.updateAllWidthBars();
                self.updatePaletteState();
                self.updateDependencyIndicators();
            }
        });

        this.initPaletteDrag();
        this.updateOrderButtons();
    };

    RVB.prototype.initPaletteDrag = function () {
        var self = this;
        $(document).off('dragstart.rvb dragend.rvb');

        $(document).on('dragstart.rvb', '.rvb-palette-field:not(.rvb-pf--placed)', function (e) {
            e.originalEvent.dataTransfer.setData('text/plain', $(this).data('field-id'));
            e.originalEvent.dataTransfer.effectAllowed = 'copy';
            $(this).addClass('rvb-pf--dragging');
            $('.rvb-drop-zone').addClass('rvb-dz--active');
        });
        $(document).on('dragend.rvb', '.rvb-palette-field', function () {
            $(this).removeClass('rvb-pf--dragging');
            $('.rvb-drop-zone').removeClass('rvb-dz--active rvb-dz--hover');
        });

        $(document).off('dragover.rvb dragenter.rvb dragleave.rvb drop.rvb');
        $(document).on('dragover.rvb', '.rvb-drop-zone', function (e) { e.preventDefault(); });
        $(document).on('dragenter.rvb', '.rvb-drop-zone', function (e) { e.preventDefault(); $(this).addClass('rvb-dz--hover'); });
        $(document).on('dragleave.rvb', '.rvb-drop-zone', function () { $(this).removeClass('rvb-dz--hover'); });

        $(document).on('drop.rvb', '.rvb-drop-zone', function (e) {
            e.preventDefault();
            $(this).removeClass('rvb-dz--hover');
            $('.rvb-drop-zone').removeClass('rvb-dz--active');

            var fid = e.originalEvent.dataTransfer.getData('text/plain');
            if (!fid || !self.fields[fid] || $.inArray(fid, self.placedFields) !== -1) return;

            var colData = { field: fid, width: 6, active: 1, required: $.inArray(fid, self.mandatoryFields) !== -1 };

            if ($(this).hasClass('rvb-drop-new-row')) {
                var $rows = $(this).closest('.rvb-section').find('.rvb-rows-container');
                $rows.append(self.renderRow({ id: 'row_' + Date.now(), columns: [colData] }, $rows.children('.rvb-row').length));
                self.initSortable();
            } else if ($(this).hasClass('rvb-drop-add-col')) {
                $(this).before(self.renderColumn(colData));
            }

            self.placedFields.push(fid);
            self.updatePaletteState();
            self.updateAllWidthBars();
            self.dirty();
        });
    };

    // =========================================================================
    // EVENTS
    // =========================================================================

    RVB.prototype.bindEvents = function () {
        var self = this;

        $(document).on('click', '#rvb-add-section', function () { self.addSection(); });

        $(document).on('click', '.rvb-remove-section', function () {
            var $s = $(this).closest('.rvb-section');
            if (!confirm('Remove this section and all its fields?')) return;
            $s.find('.rvb-column').each(function () { self.rmPlaced($(this).data('field-id')); });
            $s.slideUp(200, function () { $(this).remove(); self.updatePaletteState(); self.updateOrderButtons(); self.dirty(); if (!$('#rvb-canvas .rvb-section').length) $('#rvb-canvas-empty').show(); });
        });

        $(document).on('click', '.rvb-toggle-section', function () {
            $(this).closest('.rvb-section').toggleClass('rvb-section--collapsed');
            $(this).find('.dashicons').toggleClass('dashicons-arrow-up-alt2 dashicons-arrow-down-alt2');
        });

        // Row collapse/expand toggle
        $(document).on('click', '.rvb-toggle-row', function () {
            $(this).closest('.rvb-row').toggleClass('rvb-row--collapsed');
            $(this).find('.dashicons').toggleClass('dashicons-arrow-up-alt2 dashicons-arrow-down-alt2');
        });

        //  Move Higher / Move Lower — SECTIONS
        $(document).on('click', '.rvb-section > .rvb-section-header .rvb-order-higher', function () {
            self.moveItemUp($(this).closest('.rvb-section'), '.rvb-section');
        });
        $(document).on('click', '.rvb-section > .rvb-section-header .rvb-order-lower', function () {
            self.moveItemDown($(this).closest('.rvb-section'), '.rvb-section');
        });

        //  Move Higher / Move Lower — ROWS
        $(document).on('click', '.rvb-row > .rvb-row-header .rvb-order-higher', function () {
            self.moveItemUp($(this).closest('.rvb-row'), '.rvb-row');
        });
        $(document).on('click', '.rvb-row > .rvb-row-header .rvb-order-lower', function () {
            self.moveItemDown($(this).closest('.rvb-row'), '.rvb-row');
        });

        $(document).on('click', '.rvb-add-row-btn', function () {
            var $rows = $(this).closest('.rvb-section').find('.rvb-rows-container');
            $rows.append(self.renderRow({ id: 'row_' + Date.now(), columns: [] }, $rows.children('.rvb-row').length));
            self.initSortable();
            self.dirty();
        });

        $(document).on('click', '.rvb-remove-row', function () {
            var $r = $(this).closest('.rvb-row');
            $r.find('.rvb-column').each(function () { self.rmPlaced($(this).data('field-id')); });
            $r.slideUp(200, function () { $(this).remove(); self.updatePaletteState(); self.updateAllWidthBars(); self.updateOrderButtons(); self.dirty(); });
        });

        $(document).on('click', '.rvb-remove-col', function () {
            var $c = $(this).closest('.rvb-column');
            self.rmPlaced($c.data('field-id'));
            $c.slideUp(200, function () { $(this).remove(); self.updatePaletteState(); self.updateAllWidthBars(); self.updateDependencyIndicators(); self.dirty(); });
        });

        $(document).on('change', '.rvb-col-width', function () {
            $(this).closest('.rvb-column').data('width', parseInt($(this).val()));
            self.updateAllWidthBars(); self.dirty();
        });

        $(document).on('change', '.rvb-col-required', function () {
            var $c = $(this).closest('.rvb-column'), fid = $c.data('field-id');
            var m = $.inArray(fid, self.mandatoryFields) !== -1, r = $(this).is(':checked');
            $c.find('.rvb-badge').remove();
            var b = m ? '<span class="rvb-badge rvb-badge--mandatory">M</span>' : r ? '<span class="rvb-badge rvb-badge--required">R</span>' : '<span class="rvb-badge rvb-badge--optional">O</span>';
            $c.find('.rvb-col-label').after(b);
            self.dirty();
        });

        $(document).on('change', '.rvb-col-active', function () { $(this).closest('.rvb-column').toggleClass('rvb-col--inactive', !$(this).is(':checked')); self.dirty(); });
        $(document).on('change', '.rvb-col-hv', function () { $(this).closest('.rvb-column').toggleClass('rvb-col--hidden-visual', $(this).is(':checked')); self.dirty(); });
        $(document).on('change', '.rvb-col-checked', function () { self.dirty(); });
        $(document).on('change', '.rvb-col-depends-on', function () { self.updateDependencyIndicators(); self.dirty(); });

        $(document).on('click', '.rvb-palette-group-header', function () { $(this).closest('.rvb-palette-group').toggleClass('rvb-group--collapsed'); });

        $(document).on('input', '#rvb-field-search', function () {
            var q = $.trim($(this).val()).toLowerCase();
            if (!q) { $('.rvb-palette-field, .rvb-palette-group').show(); return; }
            $('.rvb-palette-field').each(function () { $(this).toggle(($(this).find('.rvb-pf-label').text() + ' ' + $(this).data('field-id')).toLowerCase().indexOf(q) !== -1); });
            $('.rvb-palette-group').each(function () { $(this).toggle($(this).find('.rvb-palette-field:visible').length > 0); });
        });

        $(document).on('input', '.rvb-section-title, .rvb-section-desc, .rvb-section-cls', function () { self.dirty(); });
        $(document).on('change', '.rvb-show-title, .rvb-show-desc', function () { self.dirty(); });

        $(document).on('click', '#rvb-save', function () { self.save(); });

        // Label override: update displayed label in header
        $(document).on('input', '.rvb-col-label-override', function () {
            var $c = $(this).closest('.rvb-column');
            var fid = $c.data('field-id');
            var field = self.fields[fid] || {};
            var val = $.trim($(this).val());
            $c.find('.rvb-col-label').text(val || field.label || fid);
            // Show/hide clear button
            if (val && !$c.find('.rvb-clear-label-override').length) {
                $(this).after('<button type="button" class="rvb-clear-label-override rvb-icon-btn" title="Clear override"><span class="dashicons dashicons-no-alt"></span></button>');
            } else if (!val) {
                $c.find('.rvb-clear-label-override').remove();
            }
            self.dirty();
        });

        $(document).on('click', '.rvb-clear-label-override', function () {
            var $c = $(this).closest('.rvb-column');
            var fid = $c.data('field-id');
            var field = self.fields[fid] || {};
            $c.find('.rvb-col-label-override').val('');
            $c.find('.rvb-col-label').text(field.label || fid);
            $(this).remove();
            self.dirty();
        });

        // Footer buttons (mirror header)
        $(document).on('click', '.rvb-footer-load-current', function () { self.loadLayout(); });
        $(document).on('click', '.rvb-footer-save', function () { self.save(); });
        $(document).on('click', '.rvb-footer-save-template', function () { $('#rvb-save-as-template').trigger('click'); });

        // Reset to default
        $(document).on('click', '.rvb-footer-reset-default', function () {
            if (!confirm('Reset to the default layout? This will replace all current visual builder content with the default layout. Make sure to save afterward.')) return;
            $.post(rentalCheckoutLayout.ajaxUrl, {
                action: 'rental_checkout_layout_reset',
                nonce: rentalCheckoutLayout.nonce
            }, function (resp) {
                if (resp.success && resp.data && resp.data.layout) {
                    self.layout = resp.data.layout;
                    self._loaded = true;
                    self.buildCanvas();
                    // Also update JSON editor
                    var $ta = $('#rental-layout-json');
                    if ($ta.length) $ta.val(JSON.stringify(resp.data.layout, null, 2));
                    self.showMsg('Default layout loaded. Click Save to apply.', 'success');
                } else {
                    self.showMsg('Failed to load default layout: ' + ((resp.data && resp.data.message) || 'Unknown error'), 'error');
                }
            }).fail(function () {
                self.showMsg('Failed to load default layout (server error)', 'error');
            });
        });
    };

    // =========================================================================
    // HELPERS
    // =========================================================================

    RVB.prototype.addSection = function () {
        $('#rvb-canvas-empty').hide();
        $('#rvb-canvas').append(this.renderSection({
            id: 'section_' + Date.now(), title: 'New Section', description: '',
            'class': '', show_title: true, show_description: false, rows: []
        }, $('#rvb-canvas > .rvb-section').length));
        this.initSortable();
        this.dirty();
        this.updateOrderButtons();
    };

    RVB.prototype.updatePaletteState = function () {
        var self = this;
        self.placedFields = [];
        $('#rvb-canvas .rvb-column').each(function () { var f = $(this).data('field-id'); if (f) self.placedFields.push(f); });
        $('.rvb-palette-field').each(function () { $(this).toggleClass('rvb-pf--placed', $.inArray($(this).data('field-id'), self.placedFields) !== -1); });
    };

    RVB.prototype.updateAllWidthBars = function () {
        $('#rvb-canvas .rvb-row').each(function () {
            var t = 0;
            $(this).find('.rvb-cols-container > .rvb-column').each(function () { t += parseInt($(this).find('.rvb-col-width').val()) || parseInt($(this).data('width')) || 6; });
            $(this).find('.rvb-width-fill').css('width', Math.min(t / 12 * 100, 100) + '%').removeClass('rvb-wf--ok rvb-wf--under rvb-wf--over').addClass(t === 12 ? 'rvb-wf--ok' : t < 12 ? 'rvb-wf--under' : 'rvb-wf--over');
            $(this).find('.rvb-width-text').text(t + '/12');
        });
    };

    RVB.prototype.updateDependencyIndicators = function () {
        var depMap = {};
        $('#rvb-canvas .rvb-column').each(function () {
            var $d = $(this).find('.rvb-col-depends-on');
            if ($d.length && $d.val()) { var p = $d.val(), c = $(this).data('field-id'); if (!depMap[p]) depMap[p] = []; depMap[p].push(c); }
        });
        $('#rvb-canvas .rvb-column').each(function () {
            var fid = $(this).data('field-id');
            $(this).find('.rvb-dep-parent').remove();
            if (depMap[fid] && depMap[fid].length) {
                var n = depMap[fid].length;
                $(this).find('.rvb-col-head').append('<span class="rvb-dep-parent" title="Parent of: ' + depMap[fid].join(', ') + '">⤵ ' + n + ' dep' + (n > 1 ? 's' : '') + '</span>');
            }
        });
    };

    RVB.prototype.rmPlaced = function (fid) { var i = $.inArray(fid, this.placedFields); if (i !== -1) this.placedFields.splice(i, 1); };
    RVB.prototype.dirty = function () { $('#rvb-save').addClass('rvb-dirty'); };

    /**
     * Move an item up (swap with previous sibling of same type).
     * Shared by sections and rows — WP dashboard order-higher pattern.
     *
     * @param {jQuery} $item  The element to move
     * @param {string} siblingSel  CSS selector for same-type siblings
     */
    RVB.prototype.moveItemUp = function ($item, siblingSel) {
        var $prev = $item.prev(siblingSel);
        if (!$prev.length) return;
        $prev.before($item);
        this.flashItem($item);
        this.dirty();
        this.updateAllWidthBars();
        this.updateOrderButtons();
    };

    /**
     * Move an item down (swap with next sibling of same type).
     * Shared by sections and rows — WP dashboard order-lower pattern.
     *
     * @param {jQuery} $item  The element to move
     * @param {string} siblingSel  CSS selector for same-type siblings
     */
    RVB.prototype.moveItemDown = function ($item, siblingSel) {
        var $next = $item.next(siblingSel);
        if (!$next.length) return;
        $next.after($item);
        this.flashItem($item);
        this.dirty();
        this.updateAllWidthBars();
        this.updateOrderButtons();
    };

    /**
     * Update disabled state of all order-higher/order-lower buttons.
     * First item's "up" is disabled, last item's "down" is disabled.
     */
    RVB.prototype.updateOrderButtons = function () {
        // Sections
        var $sections = $('#rvb-canvas > .rvb-section');
        $sections.find('> .rvb-section-header .rvb-order-higher, > .rvb-section-header .rvb-order-lower')
            .prop('disabled', false).attr('aria-disabled', 'false');
        if ($sections.length > 0) {
            $sections.first().find('> .rvb-section-header .rvb-order-higher')
                .prop('disabled', true).attr('aria-disabled', 'true');
            $sections.last().find('> .rvb-section-header .rvb-order-lower')
                .prop('disabled', true).attr('aria-disabled', 'true');
        }

        // Rows (within each section)
        $('#rvb-canvas .rvb-rows-container').each(function () {
            var $rows = $(this).children('.rvb-row');
            $rows.find('> .rvb-row-header .rvb-order-higher, > .rvb-row-header .rvb-order-lower')
                .prop('disabled', false).attr('aria-disabled', 'false');
            if ($rows.length > 0) {
                $rows.first().find('> .rvb-row-header .rvb-order-higher')
                    .prop('disabled', true).attr('aria-disabled', 'true');
                $rows.last().find('> .rvb-row-header .rvb-order-lower')
                    .prop('disabled', true).attr('aria-disabled', 'true');
            }
        });
    };

    /**
     * Flash highlight on an element after reorder (visual feedback).
     *
     * @param {jQuery} $item Element to highlight
     */
    RVB.prototype.flashItem = function ($item) {
        if (!$item || !$item.length) return;
        $item.addClass('rvb-flash');
        setTimeout(function () { $item.removeClass('rvb-flash'); }, 700);
    };

    // =========================================================================
    // COLLECT & SAVE
    // =========================================================================

    RVB.prototype.collectLayout = function () {
        var self = this;
        var layout = {
            version: (self.layout && self.layout.version) || '1.0.0',
            description: 'Layout from Visual Builder',
            container_class: (self.layout && self.layout.container_class) || 'rentopian-checkout-form',
            sections: []
        };

        $('#rvb-canvas > .rvb-section').each(function () {
            var $s = $(this);
            var section = {
                id:               $s.data('section-id') || '',
                title:            $s.find('> .rvb-section-header .rvb-section-title').val() || '',
                description:      $s.find('> .rvb-section-header .rvb-section-desc').val() || '',
                'class':          $s.find('> .rvb-section-header .rvb-section-cls').val() || '',
                show_title:       $s.find('> .rvb-section-header .rvb-show-title').is(':checked'),
                show_description: $s.find('> .rvb-section-header .rvb-show-desc').is(':checked'),
                rows: []
            };

            $s.find('.rvb-rows-container > .rvb-row').each(function () {
                var row = { id: $(this).data('row-id') || '', columns: [] };
                $(this).find('.rvb-cols-container > .rvb-column').each(function () {
                    var $c = $(this);
                    var col = {
                        width:    parseInt($c.find('.rvb-col-width').val()) || parseInt($c.data('width')) || 6,
                        field:    $c.data('field-id') || '',
                        active:   $c.find('.rvb-col-active').is(':checked') ? 1 : 0,
                        required: $c.find('.rvb-col-required').is(':checked')
                    };
                    var labelVal = $.trim($c.find('.rvb-col-label-override').val());
                    if (labelVal) col.label_override = labelVal;
                    var $hv = $c.find('.rvb-col-hv');
                    if ($hv.length && $hv.is(':checked')) col.hidden_visual = 1;
                    var $checked = $c.find('.rvb-col-checked');
                    if ($checked.length && $checked.is(':checked')) col.checked = 1;
                    var $do = $c.find('.rvb-col-depends-on');
                    if ($do.length && $do.val()) col.depends_on = $do.val();
                    row.columns.push(col);
                });
                section.rows.push(row);
            });

            layout.sections.push(section);
        });
        return layout;
    };

    RVB.prototype.save = function () {
        var self = this;
        if (self.isSaving) return;
        self.isSaving = true;
        var layout = self.collectLayout();
        var $btn = $('#rvb-save');
        var $footerBtn = $('.rvb-footer-save');
        $btn.addClass('rental-btn-saving').prop('disabled', true);
        $footerBtn.addClass('rental-btn-saving').prop('disabled', true);
        self.showMsg('Saving…', 'info');

        $.post(rentalCheckoutLayout.ajaxUrl, {
            action: 'rental_checkout_layout_save',
            nonce: rentalCheckoutLayout.nonce,
            layout: JSON.stringify(layout)
        }, function (resp) {
            if (resp.success) {
                self.showMsg('Layout saved!', 'success');
                $btn.removeClass('rvb-dirty');
                var $ta = $('#rental-layout-json');
                if ($ta.length) $ta.val(JSON.stringify(layout, null, 2));
                self.layout = layout;
            } else {
                self.showMsg('Save failed: ' + ((resp.data && resp.data.message) || 'Unknown error'), 'error');
            }
        }).fail(function (xhr) {
            self.showMsg('Save failed (server ' + xhr.status + ')', 'error');
        }).always(function () {
            self.isSaving = false;
            $btn.removeClass('rental-btn-saving').prop('disabled', false);
            $footerBtn.removeClass('rental-btn-saving').prop('disabled', false);
        });
    };

    // =========================================================================
    // MESSAGE — uses the existing #rvb-save-status element (inline, no jump)
    // =========================================================================

    RVB.prototype.showMsg = function (text, type) {
        var self = this;
        var $els = $('#rvb-save-status, .rvb-footer-status');
        clearTimeout(this._msgTimeout);
        $els.removeClass('success error saving').text(text);
        if (type === 'success') $els.addClass('success');
        else if (type === 'error') $els.addClass('error');
        else $els.addClass('saving');
        $els.stop(true).fadeIn(150);
        if (type !== 'info') {
            this._msgTimeout = setTimeout(function () { $els.fadeOut(400); }, 4000);
        }
    };

    // =========================================================================
    // UTILS
    // =========================================================================

    RVB.prototype.esc = function (s) {
        if (!s) return '';
        var d = document.createElement('div');
        d.appendChild(document.createTextNode(s));
        return d.innerHTML;
    };

    RVB.prototype.escAttr = function (s) {
        return String(s || '').replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/'/g,'&#39;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    };

    // =========================================================================
    // BOOT
    // =========================================================================

    $(document).ready(function () {
        if ($('#tab-visual-builder').length && typeof rentalCheckoutLayout !== 'undefined') {
            window.rentopianVisualBuilder = new RVB();
            window.rentopianVisualBuilder.init();

            // Visual Builder is now the default active tab — load immediately
            if ($('#tab-visual-builder').hasClass('rental-tab-active')) {
                window.rentopianVisualBuilder.loadLayout();
            }
        }
    });

})(jQuery);
