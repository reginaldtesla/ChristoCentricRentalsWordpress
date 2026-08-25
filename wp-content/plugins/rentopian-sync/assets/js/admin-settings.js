(function ($) {
    'use strict';

    var RentalSettings = {
        init: function () {
            this.bindSectionForms();
            this.bindCheckoutLayoutModeVisibility();
            this.bindSetsLayoutModeVisibility();
        },

        /**
         * Bind submit handler to every section form.
         */
        bindSectionForms: function () {
            $(document).on('submit', '.rental-settings-section-form', function (e) {
                e.preventDefault();

                var $form   = $(this);
                var section = $form.data('section');

                // --- Guard against double submit (Enter, double click, etc.) ---
                if ($form.data('isSaving')) {
                    return;
                }
                $form.data('isSaving', true);

                // Prefer the specific Bootstrap-style button if present
                var $button = $form.find('.btn.btn-success.rental-settings-section-save').first();
                if (!$button.length) {
                    // Fallback to any save button with this class
                    $button = $form.find('.rental-settings-section-save').first();
                }

                var $status = $form.find('.rental-section-status');

                // Rich editor markup lives in TinyMCE, not in the textarea,
                // until it is pushed back (e.g. Special Terms).
                var editorValues = RentalSettings.collectEditorValues($form);

                // --- Build classic form-encoded payload ---
                var formArray        = $form.serializeArray();
                var hasAction        = false;
                var hasSectionField  = false;

                // New rule: if "dates on checkout" is active, force rental_hide_zip = 1 on save
                var hideZipFieldName       = 'rental_hide_zip';
                var hasHideZipField        = false;
                var datesCheckboxSelector  = '#rntp-checkbox-rental_dates_on_checkout';
                var $datesCheckbox         = $(datesCheckboxSelector);
                var datesEnabled           = false;

                if ($datesCheckbox.length) {
                    datesEnabled =
                        $datesCheckbox.is(':checked') &&
                        !$datesCheckbox.is(':disabled');
                }

                $.each(formArray, function (_, field) {
                    if (Object.prototype.hasOwnProperty.call(editorValues, field.name)) {
                        field.value = editorValues[field.name];
                    }

                    if (field.name === 'action') {
                        hasAction = true;
                    } else if (field.name === 'section') {
                        hasSectionField = true;
                    } else if (field.name === hideZipFieldName) {
                        hasHideZipField = true;

                        // If dates are enabled, force hide zip to "1"
                        if (datesEnabled) {
                            field.value = '1';
                        }
                    }
                });

                // If dates are enabled but rental_hide_zip is not present in the form data,
                // add it explicitly as enabled.
                if (datesEnabled && !hasHideZipField) {
                    formArray.push({
                        name: hideZipFieldName,
                        value: '1'
                    });
                }

                if (!hasAction) {
                    formArray.push({
                        name: 'action',
                        value: 'rental_save_settings_section'
                    });
                }

                if (!hasSectionField && section) {
                    formArray.push({
                        name: 'section',
                        value: section
                    });
                }

                var data = $.param(formArray);

                // --- Lock button UI state ---
                if ($button.length) {
                    // Store original HTML once
                    if (!$button.data('originalHtml')) {
                        $button.data('originalHtml', $button.html());
                    }
                    // Store original cursor so we can restore it later
                    if (!$button.data('originalCursor')) {
                        $button.data('originalCursor', $button.css('cursor') || 'pointer');
                    }

                    $button
                        .prop('disabled', true)
                        .addClass('rental-settings-saving')
                        .css('cursor', 'not-allowed');
                }

                if ($status.length) {
                    $status
                        .removeClass('rental-section-status--error rental-section-status--success')
                        .text(
                            (window.rentalSettingsAdmin &&
                                rentalSettingsAdmin.i18n &&
                                rentalSettingsAdmin.i18n.saving) ||
                            'Saving...'
                        );
                }

                $.ajax({
                    url: rentalSettingsAdmin.rentalObj.url,
                    type: 'POST',
                    data: data
                    // Let jQuery use defaults:
                    // processData: true
                    // contentType: 'application/x-www-form-urlencoded; charset=UTF-8'
                })
                    .done(function (response) {

                        console.log('response : ', response);

                        if (response && response.success) {
                            if ($status.length) {
                                $status
                                    .addClass('rental-section-status--success')
                                    .removeClass('rental-section-status--error')
                                    .text(
                                        (response.data && response.data.message)
                                            ? response.data.message
                                            : (
                                                rentalSettingsAdmin.i18n.saved ||
                                                'Settings saved.'
                                            )
                                    );
                            }
                        } else {
                            var msg = (response && response.data && response.data.message)
                                ? response.data.message
                                : (
                                    rentalSettingsAdmin.i18n.error_generic ||
                                    'Error saving settings.'
                                );

                            if ($status.length) {
                                $status
                                    .addClass('rental-section-status--error')
                                    .removeClass('rental-section-status--success')
                                    .text(msg);
                            }
                        }
                    })
                    .fail(function (jqXHR, textStatus) {
                        var msg = (window.rentalSettingsAdmin &&
                            rentalSettingsAdmin.i18n &&
                            rentalSettingsAdmin.i18n.error_network) ||
                            'Network error while saving settings.';

                        if (jqXHR &&
                            jqXHR.responseJSON &&
                            jqXHR.responseJSON.data &&
                            jqXHR.responseJSON.data.message
                        ) {
                            msg = jqXHR.responseJSON.data.message;
                        }

                        if ($status.length) {
                            $status
                                .addClass('rental-section-status--error')
                                .removeClass('rental-section-status--success')
                                .text(msg + ' (' + textStatus + ')');
                        }
                    })
                    .always(function () {
                        // --- Unlock button + form ---
                        if ($button.length) {
                            var originalHtml   = $button.data('originalHtml');
                            var originalCursor = $button.data('originalCursor') || 'pointer';

                            if (originalHtml) {
                                $button.html(originalHtml);
                            }

                            $button
                                .prop('disabled', false)
                                .removeClass('rental-settings-saving')
                                .css('cursor', originalCursor); // back to pointer / original
                        }

                        $form.data('isSaving', false);
                    });
            });
        },

        /**
         * Read every wp_editor field in a form from its editor instance.
         *
         * A textarea only mirrors the visual editor once the editor is asked to
         * save, so serializing the form on its own can submit the value the page
         * was loaded with, or the visual markup stripped down to plain text.
         * Editors on the Code tab, and editors TinyMCE never initialized, keep
         * the authoritative value in the textarea, which is what the core helper
         * falls back to.
         *
         * @param {jQuery} $form Form being submitted.
         * @return {Object} Field name to content.
         */
        collectEditorValues: function ($form) {
            var values = {};
            var canUseCore = !!(window.wp && wp.editor && typeof wp.editor.getContent === 'function');

            $form.find('textarea.wp-editor-area').each(function () {
                var $textarea = $(this);
                var name      = $textarea.attr('name');

                if (!name) {
                    return;
                }

                var content = (canUseCore && this.id)
                    ? wp.editor.getContent(this.id)
                    : null;

                values[name] = (typeof content === 'string') ? content : $textarea.val();
            });

            return values;
        },

        /**
         * Handle visibility for:
         * - rental_dates_on_checkout (checkbox)
         * - rental_checkout_layout_mode (radio)
         *
         * Affects:
         * - #rental_checkout_layout_mode_wrapper
         * - #rental_checkout_modern_blocks_wrapper
         * - #rental-checkout-layout-link-wrapper
         */
        bindCheckoutLayoutModeVisibility: function () {
            var updateVisibility = function () {
                var $datesCheckbox = $('#rntp-checkbox-rental_dates_on_checkout');
                var $modeWrapper   = $('#rental_checkout_layout_mode_wrapper');
                var $modernWrapper = $('#rental_checkout_modern_blocks_wrapper');
                var $layoutLink    = $('#rental-checkout-layout-link-wrapper');

                // If nothing relevant is on the page, do nothing.
                if (
                    !$datesCheckbox.length &&
                    !$modeWrapper.length &&
                    !$modernWrapper.length
                ) {
                    return;
                }

                var datesEnabled = false;

                if ($datesCheckbox.length) {
                    datesEnabled =
                        $datesCheckbox.is(':checked') &&
                        !$datesCheckbox.is(':disabled');
                }

                // If dates are not enabled, hide both wrappers and stop.
                if (!datesEnabled) {
                    if ($modeWrapper.length) {
                        $modeWrapper.hide();
                    }
                    if ($modernWrapper.length) {
                        $modernWrapper.hide();
                    }
                    if ($layoutLink.length) {
                        $layoutLink.hide();
                    }
                    return;
                }

                // Dates enabled => show mode wrapper
                if ($modeWrapper.length) {
                    $modeWrapper.show();
                }

                // Check selected mode radio
                var mode = $('input[name="rental_checkout_layout_mode"]:checked').val();

                if (mode === 'modern') {
                    if ($modernWrapper.length) {
                        $modernWrapper.show();
                    }
                    // Show checkout layout builder link when modern mode is selected
                    if ($layoutLink.length) {
                        $layoutLink.show();
                    }
                } else {
                    if ($modernWrapper.length) {
                        $modernWrapper.hide();
                    }
                    // Hide checkout layout builder link when not in modern mode
                    if ($layoutLink.length) {
                        $layoutLink.hide();
                    }
                }
            };

            // Change handlers (namespaced for safety)
            $(document)
                .on('change.rentalSettings', '#rntp-checkbox-rental_dates_on_checkout', updateVisibility)
                .on('change.rentalSettings', 'input[name="rental_checkout_layout_mode"]', updateVisibility);

            // Initial state on page load
            $(updateVisibility);
        },

        /**
         * Handle visibility for:
         * - rental_sets_layout_mode (radio)
         *
         * Affects:
         * - #rntp-sets-modern-options
         *
         * The settings inside the wrapper only apply to the modern set
         * layout, so they stay hidden while classic is selected. Their
         * stored values are left untouched — hidden fields still submit,
         * so switching back to modern restores the previous choices.
         */
        bindSetsLayoutModeVisibility: function () {
            var updateVisibility = function () {
                var $modernOptions = $('#rntp-sets-modern-options');

                if (!$modernOptions.length) {
                    return;
                }

                var mode = $('input[name="rental_sets_layout_mode"]:checked').val();

                if (mode === 'modern') {
                    $modernOptions.show();
                } else {
                    $modernOptions.hide();
                }
            };

            $(document).on(
                'change.rentalSettings',
                'input[name="rental_sets_layout_mode"]',
                updateVisibility
            );

            // Initial state on page load
            $(updateVisibility);
        }

    };

    $(document).ready(function () {
        RentalSettings.init();
    });

})(jQuery);
