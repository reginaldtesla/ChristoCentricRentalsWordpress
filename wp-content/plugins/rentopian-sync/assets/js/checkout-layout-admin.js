/**
 * Checkout Layout Builder - Admin JavaScript
 *
 * Handles JSON editing, validation, import/export functionality.
 *
 * @package    Rentopian_Sync
 * @since      2.13.0
 */

(function($) {
    'use strict';

    /**
     * Checkout Layout Admin Controller
     */
    var CheckoutLayoutAdmin = {

        /**
         * Configuration from localized data
         */
        config: {},

        /**
         * DOM element cache
         */
        $elements: {},

        /**
         * Current state
         */
        state: {
            isDirty: false,
            isLoading: false,
            lastValidation: null
        },

        /**
         * Initialize the admin interface
         */
        init: function() {
            // Get config from localized data
            this.config = window.rentalCheckoutLayout || {};

            // Cache DOM elements
            this.cacheElements();

            // Bind event handlers
            this.bindEvents();

            // Initial state setup
            this.setupInitialState();
        },

        /**
         * Cache frequently used DOM elements
         */
        cacheElements: function() {
            this.$elements = {
                jsonEditor: $('#rental-layout-json'),
                validateBtn: $('#rental-validate-json'),
                formatBtn: $('#rental-format-json'),
                saveBtn: $('#rental-save-layout'),
                importFile: $('#rental-import-file'),
                exportBtn: $('#rental-export-layout'),
                resetBtn: $('#rental-reset-layout'),
                reloadBtn: $('#rental-reload-layout'),
                validationStatus: $('#rental-validation-status'),
                messagesContainer: $('#rental-layout-messages'),
                fieldGroups: $('.rental-field-group'),
                copyFieldBtns: $('.rental-copy-field-id'),
                modal: $('#rental-confirm-modal'),
                // New elements
                tabs: $('.rental-layout-tabs .nav-tab'),
                tabContents: $('.rental-tab-content'),
                processingIndicator: $('#rental-processing-indicator'),
                ieProcessingIndicator: $('#rental-ie-processing-indicator'),
                refreshDynamicFieldsBtn: $('#rental-refresh-dynamic-fields'),
                refreshStatus: $('.rental-refresh-status'),
                fieldsAccordion: $('.rental-fields-accordion'),
                // Template elements
                templatesList: $('#rental-templates-list'),
                templatesCount: $('#rental-templates-count'),
                templatesLoading: $('#rental-templates-loading'),
                templatesEmpty: $('#rental-templates-empty'),
                templatesMessages: $('#rental-templates-messages'),
                saveAsTemplateBtn: $('#rental-save-as-template'),
                templateName: $('#rental-template-name'),
                templateDescription: $('#rental-template-description'),
                templateJson: $('#rental-template-json'),
                templateJsonStatus: $('#rental-template-json-status'),
                copyCurrentLayoutBtn: $('#rental-copy-current-layout'),
                validateTemplateJsonBtn: $('#rental-validate-template-json'),
                clearTemplateFormBtn: $('#rental-clear-template-form'),
                refreshTemplatesBtn: $('#rental-refresh-templates'),
                editTemplateModal: $('#rental-edit-template-modal'),
                editTemplateId: $('#rental-edit-template-id'),
                editTemplateName: $('#rental-edit-template-name'),
                editTemplateDescription: $('#rental-edit-template-description'),
                editTemplateUpdateLayout: $('#rental-edit-template-update-layout'),
                editTemplateSaveBtn: $('#rental-edit-template-save')
            };
        },

        /**
         * Bind event handlers
         */
        bindEvents: function() {
            var self = this;

            // Tab switching
            this.$elements.tabs.on('click', function(e) {
                e.preventDefault();
                self.switchTab($(this).data('tab'));
            });

            // JSON editor changes
            this.$elements.jsonEditor.on('input', function() {
                self.state.isDirty = true;
                self.clearValidationStatus();
            });

            // Validate JSON
            this.$elements.validateBtn.on('click', function() {
                self.validateJson();
            });

            // Format JSON
            this.$elements.formatBtn.on('click', function() {
                self.formatJson();
            });

            // Save layout
            this.$elements.saveBtn.on('click', function() {
                self.saveLayout();
            });

            // Import from file
            this.$elements.importFile.on('change', function(e) {
                self.handleFileImport(e);
            });

            // Export to file
            this.$elements.exportBtn.on('click', function() {
                self.exportLayout();
            });

            // Reset to default
            this.$elements.resetBtn.on('click', function() {
                self.confirmReset();
            });

            // Refresh dynamic fields from Rentopian API
            this.$elements.refreshDynamicFieldsBtn.on('click', function() {
                self.refreshDynamicFields();
            });

            // Reload layout from server (clear cache)
            this.$elements.reloadBtn.on('click', function() {
                self.reloadLayout();
            });

            // Field group toggles
            this.$elements.fieldGroups.find('.rental-field-group-toggle').on('click', function() {
                $(this).closest('.rental-field-group').toggleClass('is-open');
            });

            // Copy field ID
            $(document).on('click', '.rental-copy-field-id', function() {
                var fieldId = $(this).closest('.rental-field-item').data('field-id');
                self.copyToClipboard(fieldId);
            });

            // Modal events
            this.$elements.modal.find('.rental-modal-close, .rental-modal-cancel, .rental-modal-overlay').on('click', function() {
                self.closeModal();
            });

            // Keyboard shortcuts
            $(document).on('keydown', function(e) {
                // Ctrl/Cmd + S to save
                if ((e.ctrlKey || e.metaKey) && e.key === 's') {
                    e.preventDefault();
                    self.saveLayout();
                }
            });

            // Warn on leaving with unsaved changes
            $(window).on('beforeunload', function() {
                if (self.state.isDirty) {
                    return self.config.strings.unsavedChanges || 'You have unsaved changes.';
                }
            });

            // ==========================================
            // Template Management Events
            // ==========================================

            // Save new template
            this.$elements.saveAsTemplateBtn.on('click', function() {
                self.saveNewTemplate();
            });

            // Copy current layout from JSON Editor
            this.$elements.copyCurrentLayoutBtn.on('click', function() {
                self.copyCurrentLayoutToTemplate();
            });

            // Validate template JSON
            this.$elements.validateTemplateJsonBtn.on('click', function() {
                self.validateTemplateJson();
            });

            // Clear template form
            this.$elements.clearTemplateFormBtn.on('click', function() {
                self.clearTemplateForm();
            });

            // Refresh templates list
            this.$elements.refreshTemplatesBtn.on('click', function() {
                self.loadTemplates(true);
            });

            // Template list actions (delegated events)
            this.$elements.templatesList.on('click', '.rental-template-apply', function(e) {
                e.preventDefault();
                var templateId = $(this).data('template-id');
                self.applyTemplate(templateId);
            });

            this.$elements.templatesList.on('click', '.rental-template-preview', function(e) {
                e.preventDefault();
                var templateId = $(this).data('template-id');
                self.previewTemplate(templateId);
            });

            this.$elements.templatesList.on('click', '.rental-template-edit', function(e) {
                e.preventDefault();
                var templateId = $(this).data('template-id');
                self.showEditTemplateModal(templateId);
            });

            this.$elements.templatesList.on('click', '.rental-template-delete', function(e) {
                e.preventDefault();
                var templateId = $(this).data('template-id');
                self.confirmDeleteTemplate(templateId);
            });

            // Edit template modal events
            this.$elements.editTemplateModal.find('.rental-modal-close, .rental-modal-cancel, .rental-modal-overlay').on('click', function() {
                self.closeEditTemplateModal();
            });

            this.$elements.editTemplateSaveBtn.on('click', function() {
                self.saveTemplateChanges();
            });
        },

        /**
         * Switch between tabs
         *
         * @param {string} tabId - Tab ID to switch to
         */
        switchTab: function(tabId) {
            // Update tab navigation
            this.$elements.tabs.removeClass('nav-tab-active');
            this.$elements.tabs.filter('[data-tab="' + tabId + '"]').addClass('nav-tab-active');

            // Update tab content
            this.$elements.tabContents.removeClass('rental-tab-active');
            $('#tab-' + tabId).addClass('rental-tab-active');

            // Load templates when switching to templates tab
            if (tabId === 'templates') {
                this.loadTemplates();
            }

            // Fire event so other modules (e.g. Visual Builder) can react
            $(document).trigger('rental-tab-switched', [tabId]);
        },

        /**
         * Setup initial state
         */
        setupInitialState: function() {
            // Open first field group by default
            this.$elements.fieldGroups.first().addClass('is-open');
            
            // Pre-load templates if templates tab exists
            // (lazy loading - will load when tab is clicked)
        },

        /**
         * Validate JSON in editor
         */
        validateJson: function() {
            var self = this;
            var json = this.$elements.jsonEditor.val().trim();

            if (!json) {
                this.showValidationStatus('error', 'No JSON content to validate.');
                return;
            }

            // First, try to parse locally
            try {
                JSON.parse(json);
            } catch (e) {
                this.showValidationStatus('error', 'Invalid JSON syntax: ' + e.message);
                this.$elements.jsonEditor.addClass('has-error').removeClass('is-valid');
                return;
            }

            // If syntax is valid, validate structure via AJAX
            this.setLoading(true);

            $.ajax({
                url: this.config.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'rental_checkout_layout_validate',
                    nonce: this.config.nonce,
                    json: json
                },
                success: function(response) {
                    if (response.success) {
                        var message = response.data.message;
                        
                        // Check for warnings
                        if (response.data.warnings && response.data.warnings.length > 0) {
                            self.showValidationStatus('warning', message + ' Warnings: ' + response.data.warnings.join('; '));
                            self.$elements.jsonEditor.removeClass('has-error is-valid');
                        } else {
                            self.showValidationStatus('success', message);
                            self.$elements.jsonEditor.addClass('is-valid').removeClass('has-error');
                        }
                    } else {
                        self.showValidationStatus('error', response.data.message || 'Validation failed.');
                        self.$elements.jsonEditor.addClass('has-error').removeClass('is-valid');
                    }
                },
                error: function() {
                    self.showValidationStatus('error', 'Error communicating with server.');
                },
                complete: function() {
                    self.setLoading(false);
                }
            });
        },

        /**
         * Format JSON in editor
         */
        formatJson: function() {
            var json = this.$elements.jsonEditor.val().trim();

            if (!json) {
                return;
            }

            try {
                var parsed = JSON.parse(json);
                var formatted = JSON.stringify(parsed, null, 2);
                this.$elements.jsonEditor.val(formatted);
                this.showMessage('success', 'JSON formatted successfully.');
            } catch (e) {
                this.showMessage('error', 'Cannot format invalid JSON: ' + e.message);
            }
        },

        /**
         * Save layout to server
         */
        saveLayout: function() {
            var self = this;
            var json = this.$elements.jsonEditor.val().trim();

            if (!json) {
                this.showMessage('error', 'No layout configuration to save.');
                return;
            }

            // Validate JSON syntax first
            try {
                JSON.parse(json);
            } catch (e) {
                this.showMessage('error', 'Cannot save invalid JSON: ' + e.message);
                return;
            }

            this.setLoading(true);

            $.ajax({
                url: this.config.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'rental_checkout_layout_save',
                    nonce: this.config.nonce,
                    layout: json
                },
                success: function(response) {
                    if (response.success) {
                        self.showMessage('success', response.data.message || self.config.strings.saveSuccess);
                        self.state.isDirty = false;
                        
                        // Update editor with returned layout (includes metadata)
                        if (response.data.layout) {
                            self.$elements.jsonEditor.val(JSON.stringify(response.data.layout, null, 2));
                        }
                        
                        self.$elements.jsonEditor.addClass('is-valid').removeClass('has-error');
                        self.showValidationStatus('success', 'Configuration saved and validated.');
                    } else {
                        self.showMessage('error', response.data.message || self.config.strings.saveError);
                    }
                },
                error: function(xhr) {
                    var message = self.config.strings.saveError;
                    if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                        message = xhr.responseJSON.data.message;
                    }
                    self.showMessage('error', message);
                },
                complete: function() {
                    self.setLoading(false);
                }
            });
        },

        /**
         * Handle file import
         *
         * @param {Event} e - File input change event
         */
        handleFileImport: function(e) {
            var self = this;
            var file = e.target.files[0];

            if (!file) {
                return;
            }

            // Check file type
            if (file.type !== 'application/json' && !file.name.endsWith('.json')) {
                this.showMessage('error', 'Please select a JSON file.');
                return;
            }

            var reader = new FileReader();

            reader.onload = function(event) {
                var content = event.target.result;

                // Validate JSON
                try {
                    var parsed = JSON.parse(content);
                    var formatted = JSON.stringify(parsed, null, 2);
                    self.$elements.jsonEditor.val(formatted);
                    self.state.isDirty = true;
                    self.showMessage('success', 'File loaded. Click "Save Layout" to apply.');
                    self.clearValidationStatus();
                } catch (ex) {
                    self.showMessage('error', 'Invalid JSON file: ' + ex.message);
                }
            };

            reader.onerror = function() {
                self.showMessage('error', 'Error reading file.');
            };

            reader.readAsText(file);

            // Reset file input
            e.target.value = '';
        },

        /**
         * Export layout to file
         */
        exportLayout: function() {
            var self = this;

            this.setLoading(true);

            $.ajax({
                url: this.config.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'rental_checkout_layout_export',
                    nonce: this.config.nonce
                },
                success: function(response) {
                    if (response.success && response.data.json) {
                        self.downloadJson(
                            response.data.json,
                            self.config.strings.exportFilename || 'checkout-layout.json'
                        );
                        self.showMessage('success', 'Layout exported successfully.');
                    } else {
                        self.showMessage('error', 'Export failed.');
                    }
                },
                error: function() {
                    self.showMessage('error', 'Error exporting layout.');
                },
                complete: function() {
                    self.setLoading(false);
                }
            });
        },

        /**
         * Download JSON as file
         *
         * @param {string} json - JSON content
         * @param {string} filename - Download filename
         */
        downloadJson: function(json, filename) {
            var blob = new Blob([json], { type: 'application/json' });
            var url = URL.createObjectURL(blob);
            var a = document.createElement('a');
            a.href = url;
            a.download = filename;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
        },

        /**
         * Show reset confirmation
         */
        confirmReset: function() {
            var self = this;

            this.showModal(
                'Reset to Default',
                this.config.strings.resetConfirm || 'Are you sure you want to reset to the default layout? This will replace your current layout configuration.',
                function() {
                    self.resetLayout();
                }
            );
        },

        /**
         * Reset layout to default
         */
        resetLayout: function() {
            var self = this;

            this.setLoading(true);

            $.ajax({
                url: this.config.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'rental_checkout_layout_reset',
                    nonce: this.config.nonce
                },
                success: function(response) {
                    if (response.success) {
                        self.showMessage('success', response.data.message || self.config.strings.resetSuccess);
                        
                        // Update editor with default layout
                        if (response.data.layout) {
                            self.$elements.jsonEditor.val(JSON.stringify(response.data.layout, null, 2));
                        }
                        
                        self.state.isDirty = false;
                        self.clearValidationStatus();
                        self.$elements.jsonEditor.removeClass('has-error is-valid');
                    } else {
                        self.showMessage('error', response.data.message || 'Reset failed.');
                    }
                },
                error: function() {
                    self.showMessage('error', 'Error resetting layout.');
                },
                complete: function() {
                    self.setLoading(false);
                }
            });
        },

        /**
         * Copy text to clipboard
         *
         * @param {string} text - Text to copy
         */
        copyToClipboard: function(text) {
            var self = this;

            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(function() {
                    self.showCopyFeedback('Copied: ' + text);
                }).catch(function() {
                    self.fallbackCopy(text);
                });
            } else {
                this.fallbackCopy(text);
            }
        },

        /**
         * Fallback copy method for older browsers
         *
         * @param {string} text - Text to copy
         */
        fallbackCopy: function(text) {
            var textarea = document.createElement('textarea');
            textarea.value = text;
            textarea.style.position = 'fixed';
            textarea.style.opacity = '0';
            document.body.appendChild(textarea);
            textarea.select();
            document.execCommand('copy');
            document.body.removeChild(textarea);
            this.showCopyFeedback('Copied: ' + text);
        },

        /**
         * Show copy feedback toast
         *
         * @param {string} message - Feedback message
         */
        showCopyFeedback: function(message) {
            var $feedback = $('<div class="rental-copy-feedback">' + message + '</div>');
            $('body').append($feedback);
            
            setTimeout(function() {
                $feedback.fadeOut(200, function() {
                    $(this).remove();
                });
            }, 1500);
        },

        /**
         * Show validation status
         *
         * @param {string} type - Status type: success, error, warning
         * @param {string} message - Status message
         */
        showValidationStatus: function(type, message) {
            var iconClass = 'dashicons-yes';
            if (type === 'error') {
                iconClass = 'dashicons-no';
            } else if (type === 'warning') {
                iconClass = 'dashicons-warning';
            }

            this.$elements.validationStatus
                .removeClass('is-valid is-error is-warning')
                .addClass('is-' + type)
                .find('.status-icon').html('<span class="dashicons ' + iconClass + '"></span>');

            this.$elements.validationStatus.find('.status-message').text(message);
            this.$elements.validationStatus.show();
        },

        /**
         * Clear validation status
         */
        clearValidationStatus: function() {
            this.$elements.validationStatus.hide();
            this.$elements.jsonEditor.removeClass('has-error is-valid');
        },

        /**
         * Show message notification
         *
         * @param {string} type - Message type: success, error, warning, info
         * @param {string} message - Message text
         */
        showMessage: function(type, message) {
            var wpType = type === 'success' ? 'notice-success' : 
                        type === 'error' ? 'notice-error' : 
                        type === 'warning' ? 'notice-warning' : 'notice-info';

            var $notice = $('<div class="notice ' + wpType + ' is-dismissible"><p>' + message + '</p></div>');
            
            // Add dismiss button
            $notice.append('<button type="button" class="notice-dismiss"><span class="screen-reader-text">Dismiss this notice.</span></button>');
            
            $notice.find('.notice-dismiss').on('click', function() {
                $notice.fadeOut(200, function() {
                    $(this).remove();
                });
            });

            // Remove existing messages
            this.$elements.messagesContainer.empty().append($notice);

            // Scroll to top to see message
            $('html, body').animate({ scrollTop: 0 }, 200);

            // Auto-dismiss success messages
            if (type === 'success') {
                setTimeout(function() {
                    $notice.fadeOut(200, function() {
                        $(this).remove();
                    });
                }, 5000);
            }
        },

        /**
         * Show confirmation modal
         *
         * @param {string} title - Modal title
         * @param {string} message - Confirmation message
         * @param {function} onConfirm - Callback for confirm action
         * @param {function} onCancel - Callback for cancel action (optional)
         */
        showModal: function(title, message, onConfirm, onCancel) {
            var self = this;

            // Set title
            var $title = this.$elements.modal.find('#rental-modal-title');
            if ($title.length) {
                $title.text(title);
            }
            
            // Set message (use html to allow formatting)
            this.$elements.modal.find('#rental-modal-message').html(message);
            
            // Remove all previous handlers and bind new ones
            this.$elements.modal.find('.rental-modal-confirm').off('click').on('click', function() {
                self.closeModal();
                if (typeof onConfirm === 'function') {
                    onConfirm();
                }
            });

            this.$elements.modal.find('.rental-modal-cancel').off('click').on('click', function() {
                self.closeModal();
                if (typeof onCancel === 'function') {
                    onCancel();
                }
            });
            
            // Close on overlay click
            this.$elements.modal.find('.rental-modal-overlay').off('click').on('click', function() {
                self.closeModal();
            });
            
            // Close button
            this.$elements.modal.find('.rental-modal-close').off('click').on('click', function() {
                self.closeModal();
            });

            this.$elements.modal.show();
        },

        /**
         * Close modal
         */
        closeModal: function() {
            this.$elements.modal.hide();
        },

        /**
         * Set loading state
         *
         * @param {boolean} loading - Whether loading
         * @param {string} indicatorType - Which indicator to show: 'main' or 'ie' (import/export)
         */
        setLoading: function(loading, indicatorType) {
            this.state.isLoading = loading;
            indicatorType = indicatorType || 'main';

            var $indicator = indicatorType === 'ie' 
                ? this.$elements.ieProcessingIndicator 
                : this.$elements.processingIndicator;

            if (loading) {
                this.$elements.saveBtn.prop('disabled', true);
                this.$elements.validateBtn.prop('disabled', true);
                this.$elements.resetBtn.prop('disabled', true);
                this.$elements.exportBtn.prop('disabled', true);
                
                if ($indicator && $indicator.length) {
                    $indicator.show();
                }
            } else {
                this.$elements.saveBtn.prop('disabled', false);
                this.$elements.validateBtn.prop('disabled', false);
                this.$elements.resetBtn.prop('disabled', false);
                this.$elements.exportBtn.prop('disabled', false);
                
                // Hide all processing indicators
                if (this.$elements.processingIndicator && this.$elements.processingIndicator.length) {
                    this.$elements.processingIndicator.hide();
                }
                if (this.$elements.ieProcessingIndicator && this.$elements.ieProcessingIndicator.length) {
                    this.$elements.ieProcessingIndicator.hide();
                }
            }
        },

        /**
         * Refresh dynamic fields from Rentopian API
         * 
         * Calls the Rentopian API to fetch fresh custom fields
         * and updates the available fields list.
         */
        refreshDynamicFields: function() {
            var self = this;
            var $btn = this.$elements.refreshDynamicFieldsBtn;
            var $status = this.$elements.refreshStatus;

            if (this.state.isLoading) {
                return;
            }

            // Set loading state
            $btn.prop('disabled', true).addClass('refreshing');
            $status.removeClass('success error').text(this.config.strings.refreshing || 'Fetching from Rentopian...');

            $.ajax({
                url: this.config.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'rental_checkout_layout_refresh_dynamic_fields',
                    nonce: this.config.nonce
                },
                success: function(response) {
                    $btn.prop('disabled', false).removeClass('refreshing');

                    if (response.success) {
                        $status.addClass('success').text(response.data.message);

                        // Update the local config with new fields
                        if (response.data.fields) {
                            self.config.fields = response.data.fields;
                        }
                        if (response.data.fieldGroups) {
                            self.config.fieldGroups = response.data.fieldGroups;
                        }

                        // Update the dynamic fields group in the UI
                        self.updateDynamicFieldsUI(response.data.fields, response.data.fieldGroups);

                        // Show success message
                        self.showMessage('success', response.data.message);

                        // Clear status after 5 seconds
                        setTimeout(function() {
                            $status.text('');
                        }, 5000);
                    } else {
                        $status.addClass('error').text(response.data.message || 'Refresh failed');
                        self.showMessage('error', response.data.message || 'Failed to refresh dynamic fields');
                    }
                },
                error: function(xhr, status, error) {
                    $btn.prop('disabled', false).removeClass('refreshing');
                    $status.addClass('error').text('Request failed: ' + error);
                    self.showMessage('error', 'AJAX request failed: ' + error);
                }
            });
        },

        /**
         * Update the dynamic fields UI after refresh
         *
         * @param {object} fields - All fields from the server
         * @param {object} fieldGroups - Field groups with counts
         */
        updateDynamicFieldsUI: function(fields, fieldGroups) {
            var self = this;
            var $accordion = this.$elements.fieldsAccordion;
            var $dynamicGroup = $accordion.find('.rental-field-group[data-group-id="rental_dynamic"]');

            if (!fields) {
                console.log('No fields data received');
                return;
            }

            // Filter dynamic fields
            var dynamicFields = {};
            $.each(fields, function(fieldId, field) {
                if (field.group === 'rental_dynamic') {
                    dynamicFields[fieldId] = field;
                }
            });

            var dynamicCount = Object.keys(dynamicFields).length;
            console.log('Found ' + dynamicCount + ' dynamic fields to display');

            // If no dynamic group exists and we have fields, create it
            if (!$dynamicGroup.length && dynamicCount > 0) {
                var groupLabel = (fieldGroups && fieldGroups.rental_dynamic) 
                    ? fieldGroups.rental_dynamic.label 
                    : 'Custom Fields (Rentopian)';
                    
                var groupHtml = '<div class="rental-field-group" data-group-id="rental_dynamic">' +
                    '<button type="button" class="rental-field-group-toggle" data-group="rental_dynamic">' +
                        '<span class="dashicons dashicons-arrow-right-alt2"></span>' +
                        groupLabel +
                        '<span class="field-count">(' + dynamicCount + ')</span>' +
                    '</button>' +
                    '<div class="rental-field-group-content" id="group-rental_dynamic">' +
                        '<ul class="rental-field-list"></ul>' +
                    '</div>' +
                '</div>';
                
                $accordion.append(groupHtml);
                $dynamicGroup = $accordion.find('.rental-field-group[data-group-id="rental_dynamic"]');
                
                // Bind toggle for new group
                $dynamicGroup.find('.rental-field-group-toggle').on('click', function() {
                    $(this).closest('.rental-field-group').toggleClass('is-open');
                });
            }

            if (!$dynamicGroup.length) {
                console.log('No dynamic group container found and no fields to display');
                return;
            }

            // Update the count in the toggle button
            $dynamicGroup.find('.field-count').text('(' + dynamicCount + ')');

            // Rebuild the field list
            var $fieldList = $dynamicGroup.find('.rental-field-list');
            $fieldList.empty();

            if (dynamicCount === 0) {
                $fieldList.append('<li class="rental-no-fields">' + 
                    '<em>No custom fields found. Click "Refresh Custom Fields" to fetch from Rentopian.</em></li>');
                return;
            }

            $.each(dynamicFields, function(fieldId, field) {
                var requiredBadge = field.required ? '<span class="field-mandatory-badge" title="Required">*</span>' : '';
                var $item = $('<li class="rental-field-item" data-field-id="' + fieldId + '">' +
                    '<code class="field-id">' + fieldId + '</code>' +
                    '<span class="field-label">' + self.escapeHtml(field.label || fieldId) + '</span>' +
                    requiredBadge +
                    '<span class="field-badge field-badge-dynamic">API</span>' +
                    '<button type="button" class="rental-copy-field-id" title="Copy field ID">' +
                        '<span class="dashicons dashicons-clipboard"></span>' +
                    '</button>' +
                '</li>');
                
                $fieldList.append($item);
            });

            // Re-bind copy handlers for new elements
            $dynamicGroup.find('.rental-copy-field-id').off('click').on('click', function() {
                var fieldId = $(this).closest('.rental-field-item').data('field-id');
                self.copyToClipboard(fieldId);
            });

            // Auto-expand the group to show the new fields
            $dynamicGroup.addClass('is-open');
        },

        /**
         * Escape HTML to prevent XSS
         *
         * @param {string} text - Text to escape
         * @return {string} Escaped text
         */
        escapeHtml: function(text) {
            if (!text) return '';
            var div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        },

        /**
         * Reload layout from server (clear cache)
         *
         * Fetches fresh layout configuration from the server,
         * bypassing any cached data.
         */
        reloadLayout: function() {
            var self = this;

            if (this.state.isLoading) {
                return;
            }

            // Check for unsaved changes
            if (this.state.isDirty) {
                if (!confirm(this.config.strings.unsavedChanges || 'You have unsaved changes. Are you sure you want to reload?')) {
                    return;
                }
            }

            this.setLoading(true, 'ie');

            $.ajax({
                url: this.config.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'rental_checkout_layout_get',
                    nonce: this.config.nonce,
                    nocache: Date.now() // Bust any browser cache
                },
                success: function(response) {
                    self.setLoading(false);

                    if (response.success && response.data.layout) {
                        // Update editor with fresh data
                        var formatted = JSON.stringify(response.data.layout, null, 2);
                        self.$elements.jsonEditor.val(formatted);
                        
                        // Clear dirty state
                        self.state.isDirty = false;
                        
                        // Show success message
                        self.showMessage('success', self.config.strings.reloadSuccess || 'Layout reloaded from server.');
                        
                        // Clear validation status
                        self.clearValidationStatus();
                    } else {
                        self.showMessage('error', response.data.message || 'Failed to reload layout.');
                    }
                },
                error: function(xhr, status, error) {
                    self.setLoading(false);
                    self.showMessage('error', 'AJAX request failed: ' + error);
                }
            });
        },

        // ==========================================
        // Template Management Methods
        // ==========================================

        /**
         * Load and display templates list
         * 
         * @param {boolean} showFeedback - Whether to show success message (default: false)
         */
        loadTemplates: function(showFeedback) {
            var self = this;

            // Show loading state
            this.$elements.templatesLoading.show();
            this.$elements.templatesList.hide();
            this.$elements.templatesEmpty.hide();
            this.$elements.refreshTemplatesBtn.prop('disabled', true).addClass('updating-message');

            $.ajax({
                url: this.config.ajaxUrl,
                type: 'POST',
                dataType: 'json',
                timeout: 30000, // 30 second timeout
                data: {
                    action: 'rental_list_layout_templates',
                    nonce: this.config.nonce
                },
                success: function(response) {
                    self.$elements.templatesLoading.hide();
                    self.$elements.refreshTemplatesBtn.prop('disabled', false).removeClass('updating-message');

                    if (response.success) {
                        self.renderTemplatesList(response.data.templates);
                        self.$elements.templatesCount.text(response.data.count);
                        
                        if (showFeedback) {
                            self.showTemplateMessage('success', 'Templates list refreshed.');
                        }
                    } else {
                        self.showTemplateMessage('error', response.data.message || 'Failed to load templates.');
                        self.$elements.templatesEmpty.show();
                    }
                },
                error: function(xhr, status, error) {
                    self.$elements.templatesLoading.hide();
                    self.$elements.refreshTemplatesBtn.prop('disabled', false).removeClass('updating-message');
                    
                    var errorMsg = status === 'timeout' ? 'Request timed out. Please try again.' : 'Failed to load templates: ' + error;
                    self.showTemplateMessage('error', errorMsg);
                    self.$elements.templatesEmpty.show();
                }
            });
        },

        /**
         * Render the templates list
         *
         * @param {Array} templates - Array of template objects
         */
        renderTemplatesList: function(templates) {
            var self = this;
            var $list = this.$elements.templatesList;
            
            $list.empty();

            if (!templates || templates.length === 0) {
                this.$elements.templatesEmpty.show();
                $list.hide();
                return;
            }

            this.$elements.templatesEmpty.hide();
            $list.show();

            // Check if wp.template is available
            var templateFn = null;
            if (typeof wp !== 'undefined' && wp.template) {
                templateFn = wp.template('rental-template-item');
            }

            templates.forEach(function(template) {
                var $item;
                
                if (templateFn) {
                    $item = $(templateFn(template));
                } else {
                    // Fallback if wp.template is not available
                    $item = self.buildTemplateItem(template);
                }
                
                $list.append($item);
            });
        },

        /**
         * Fallback method to build template item HTML
         *
         * @param {Object} template - Template data
         * @return {jQuery} Template item element
         */
        buildTemplateItem: function(template) {
            var html = '<div class="rental-template-item" data-template-id="' + template.id + '">' +
                '<div class="rental-template-info">' +
                    '<h4 class="rental-template-name">' + this.escapeHtml(template.name) + '</h4>' +
                    (template.description ? '<p class="rental-template-description">' + this.escapeHtml(template.description) + '</p>' : '') +
                    '<div class="rental-template-meta">' +
                        '<span class="rental-template-version">' +
                            '<span class="dashicons dashicons-info-outline"></span> v' + (template.version || '1.0.0') +
                        '</span>' +
                        '<span class="rental-template-date">' +
                            '<span class="dashicons dashicons-calendar-alt"></span> ' + (template.updated_at || template.created_at || '') +
                        '</span>' +
                    '</div>' +
                '</div>' +
                '<div class="rental-template-actions">' +
                    '<button type="button" class="button rental-template-apply" data-template-id="' + template.id + '">' +
                        '<span class="dashicons dashicons-yes"></span> Apply' +
                    '</button>' +
                    '<button type="button" class="button rental-template-preview" data-template-id="' + template.id + '">' +
                        '<span class="dashicons dashicons-visibility"></span>' +
                    '</button>' +
                    '<button type="button" class="button rental-template-edit" data-template-id="' + template.id + '">' +
                        '<span class="dashicons dashicons-edit"></span>' +
                    '</button>' +
                    '<button type="button" class="button rental-template-delete" data-template-id="' + template.id + '">' +
                        '<span class="dashicons dashicons-trash"></span>' +
                    '</button>' +
                '</div>' +
            '</div>';

            return $(html);
        },

        /**
         * Save a new template from the form
         */
        saveNewTemplate: function() {
            var self = this;
            var name = this.$elements.templateName.val().trim();
            var description = this.$elements.templateDescription.val().trim();
            var layoutJson = this.$elements.templateJson.val().trim();

            // Validate name
            if (!name) {
                this.showTemplateMessage('error', 'Please enter a template name.');
                this.$elements.templateName.focus();
                return;
            }

            // Validate JSON
            if (!layoutJson) {
                this.showTemplateMessage('error', 'Please enter the layout JSON. Use "Copy Current Layout" to get the current configuration.');
                this.$elements.templateJson.focus();
                return;
            }

            try {
                var parsed = JSON.parse(layoutJson);
                if (!parsed.sections) {
                    this.showTemplateMessage('error', 'Invalid layout: Missing "sections" property.');
                    return;
                }
            } catch (e) {
                this.showTemplateMessage('error', 'Invalid JSON format: ' + e.message);
                this.$elements.templateJson.focus();
                return;
            }

            // Show loading state on button
            this.setTemplateSaveLoading(true);

            $.ajax({
                url: this.config.ajaxUrl,
                type: 'POST',
                dataType: 'json',
                timeout: 30000,
                data: {
                    action: 'rental_save_layout_template',
                    nonce: this.config.nonce,
                    name: name,
                    description: description,
                    layout: layoutJson
                },
                success: function(response) {
                    self.setTemplateSaveLoading(false);

                    if (response.success) {
                        self.showTemplateMessage('success', response.data.message || 'Template saved successfully!');
                        // Clear the form
                        self.clearTemplateForm();
                        // Reload templates list
                        self.loadTemplates();
                    } else {
                        self.showTemplateMessage('error', response.data.message || 'Failed to save template.');
                    }
                },
                error: function(xhr, status, error) {
                    self.setTemplateSaveLoading(false);
                    var errorMsg = status === 'timeout' ? 'Request timed out. Please try again.' : 'Failed to save template: ' + error;
                    self.showTemplateMessage('error', errorMsg);
                }
            });
        },

        /**
         * Copy current layout from JSON Editor to template JSON field
         */
        copyCurrentLayoutToTemplate: function() {
            var currentLayout = this.$elements.jsonEditor.val().trim();
            
            if (!currentLayout) {
                this.showTemplateMessageInline('info', 'No layout found in JSON Editor. Please configure a layout first.');
                return;
            }

            // Validate the current layout JSON
            try {
                JSON.parse(currentLayout);
            } catch (e) {
                this.showTemplateMessageInline('error', 'Current layout has invalid JSON. Please fix it in the JSON Editor tab first.');
                return;
            }

            this.$elements.templateJson.val(currentLayout);
            this.showTemplateMessageInline('success', 'Current layout copied! Now enter a name and save.');
            this.$elements.templateName.focus();
        },

        /**
         * Show message inline (no scroll) for template form
         */
        showTemplateMessageInline: function(type, message) {
            var $status = this.$elements.templateJsonStatus;
            $status.removeClass('valid invalid info').addClass(type === 'success' ? 'valid' : type === 'error' ? 'invalid' : 'info');
            $status.text(message).show();
            
            // Auto-hide success/info after 4 seconds
            if (type === 'success' || type === 'info') {
                setTimeout(function() {
                    $status.fadeOut();
                }, 4000);
            }
        },

        /**
         * Validate template JSON field
         */
        validateTemplateJson: function() {
            var json = this.$elements.templateJson.val().trim();
            var $status = this.$elements.templateJsonStatus;
            
            if (!json) {
                $status.removeClass('valid invalid').addClass('invalid').text('Please enter JSON to validate.').show();
                return;
            }

            try {
                var parsed = JSON.parse(json);
                if (!parsed.sections) {
                    $status.removeClass('valid').addClass('invalid').text('Invalid layout: Missing "sections" property.').show();
                    return;
                }
                $status.removeClass('invalid').addClass('valid').text('✓ Valid JSON layout with ' + parsed.sections.length + ' section(s).').show();
            } catch (e) {
                $status.removeClass('valid').addClass('invalid').text('Invalid JSON: ' + e.message).show();
            }
        },

        /**
         * Clear the template form
         */
        clearTemplateForm: function() {
            this.$elements.templateName.val('');
            this.$elements.templateDescription.val('');
            this.$elements.templateJson.val('');
            this.$elements.templateJsonStatus.hide();
        },

        /**
         * Set loading state for template save button
         */
        setTemplateSaveLoading: function(loading) {
            var $btn = this.$elements.saveAsTemplateBtn;
            if (loading) {
                $btn.prop('disabled', true);
                $btn.find('.button-text').hide();
                $btn.find('.button-loading').show();
            } else {
                $btn.prop('disabled', false);
                $btn.find('.button-text').show();
                $btn.find('.button-loading').hide();
            }
        },

        /**
         * Show message in Templates tab (persistent, doesn't auto-dismiss for errors)
         * 
         * @param {string} type - Message type: success, error, warning, info
         * @param {string} message - Message text
         */
        showTemplateMessage: function(type, message) {
            var $container = this.$elements.templatesMessages;
            var typeClass = type === 'success' ? 'notice-success' : 
                           type === 'error' ? 'notice-error' : 
                           type === 'warning' ? 'notice-warning' : 'notice-info';

            var $notice = $('<div class="notice ' + typeClass + ' is-dismissible rental-template-notice"><p>' + this.escapeHtml(message) + '</p></div>');
            
            // Add dismiss button
            $notice.append('<button type="button" class="notice-dismiss"><span class="screen-reader-text">Dismiss</span></button>');
            
            $notice.find('.notice-dismiss').on('click', function() {
                $notice.slideUp(200, function() {
                    $(this).remove();
                });
            });

            // Clear existing messages and add new one
            $container.empty().append($notice);

            // Scroll to message
            var containerOffset = $container.offset();
            if (containerOffset) {
                $('html, body').animate({ scrollTop: containerOffset.top - 50 }, 200);
            }

            // Auto-dismiss success and info messages after 5 seconds
            if (type === 'success' || type === 'info') {
                setTimeout(function() {
                    $notice.slideUp(200, function() {
                        $(this).remove();
                    });
                }, 5000);
            }
        },

        /**
         * Apply a template to the current layout
         *
         * @param {string} templateId - Template ID to apply
         */
        applyTemplate: function(templateId) {
            var self = this;
            var $item = this.$elements.templatesList.find('[data-template-id="' + templateId + '"]');
            var templateName = $item.find('.rental-template-name').text();
            
            this.showModal(
                'Apply Template',
                'Apply "' + this.escapeHtml(templateName) + '" to your checkout? This will replace your current layout configuration and save it immediately.',
                function() {
                    self.doApplyTemplate(templateId);
                }
            );
        },

        /**
         * Execute template application
         *
         * @param {string} templateId - Template ID to apply
         */
        doApplyTemplate: function(templateId) {
            var self = this;
            var $item = this.$elements.templatesList.find('[data-template-id="' + templateId + '"]');

            // Show loading on the item
            $item.addClass('is-loading');
            $item.find('.rental-template-loading').show();
            $item.find('.rental-template-actions button').prop('disabled', true);

            $.ajax({
                url: this.config.ajaxUrl,
                type: 'POST',
                dataType: 'json',
                timeout: 30000,
                data: {
                    action: 'rental_apply_layout_template',
                    nonce: this.config.nonce,
                    template_id: templateId
                },
                success: function(response) {
                    $item.removeClass('is-loading');
                    $item.find('.rental-template-loading').hide();
                    $item.find('.rental-template-actions button').prop('disabled', false);

                    if (response.success) {
                        self.showTemplateMessage('success', response.data.message || 'Template applied successfully! Checkout layout has been updated.');
                        
                        // Update the JSON editor with the new layout
                        if (response.data.layout) {
                            var formatted = JSON.stringify(response.data.layout, null, 2);
                            self.$elements.jsonEditor.val(formatted);
                            self.state.isDirty = false;
                        }
                        
                        // Switch to Visual Builder tab to show applied layout
                        setTimeout(function() {
                            self.switchTab('visual-builder');
                            // Force VB to reload with the new layout
                            if (window.rentopianVisualBuilder) {
                                window.rentopianVisualBuilder._loaded = false;
                                window.rentopianVisualBuilder.loadLayout();
                            }
                            self.showMessage('success', 'Template applied and saved! This layout is now live on your checkout page.');
                        }, 1000);
                    } else {
                        self.showTemplateMessage('error', response.data.message || 'Failed to apply template.');
                    }
                },
                error: function(xhr, status, error) {
                    $item.removeClass('is-loading');
                    $item.find('.rental-template-loading').hide();
                    $item.find('.rental-template-actions button').prop('disabled', false);
                    
                    var errorMsg = status === 'timeout' ? 'Request timed out. Please try again.' : 'Failed to apply template: ' + error;
                    self.showTemplateMessage('error', errorMsg);
                }
            });
        },

        /**
         * Preview a template's JSON in a modal
         *
         * @param {string} templateId - Template ID to preview
         */
        previewTemplate: function(templateId) {
            var self = this;
            var $item = this.$elements.templatesList.find('[data-template-id="' + templateId + '"]');

            // Show loading on the item
            $item.addClass('is-loading');
            $item.find('.rental-template-loading').show();
            $item.find('.rental-template-actions button').prop('disabled', true);

            $.ajax({
                url: this.config.ajaxUrl,
                type: 'POST',
                dataType: 'json',
                timeout: 30000,
                data: {
                    action: 'rental_load_layout_template',
                    nonce: this.config.nonce,
                    template_id: templateId
                },
                success: function(response) {
                    $item.removeClass('is-loading');
                    $item.find('.rental-template-loading').hide();
                    $item.find('.rental-template-actions button').prop('disabled', false);

                    if (response.success && response.data.template) {
                        var template = response.data.template;
                        self.showTemplatePreviewModal(template);
                    } else {
                        self.showTemplateMessage('error', response.data.message || 'Failed to load template.');
                    }
                },
                error: function(xhr, status, error) {
                    $item.removeClass('is-loading');
                    $item.find('.rental-template-loading').hide();
                    $item.find('.rental-template-actions button').prop('disabled', false);
                    
                    var errorMsg = status === 'timeout' ? 'Request timed out. Please try again.' : 'Failed to load template: ' + error;
                    self.showTemplateMessage('error', errorMsg);
                }
            });
        },

        /**
         * Show template preview modal with JSON and Apply button
         *
         * @param {Object} template - Template data with layout
         */
        showTemplatePreviewModal: function(template) {
            var self = this;
            var layoutJson = JSON.stringify(template.layout, null, 2);
            
            // Check if preview modal exists, create if not
            var $modal = $('#rental-template-preview-modal');
            if (!$modal.length) {
                $modal = $(
                    '<div id="rental-template-preview-modal" class="rental-modal rental-modal-large">' +
                        '<div class="rental-modal-overlay"></div>' +
                        '<div class="rental-modal-content">' +
                            '<div class="rental-modal-header">' +
                                '<h3 class="rental-preview-modal-title"></h3>' +
                                '<button type="button" class="rental-modal-close">&times;</button>' +
                            '</div>' +
                            '<div class="rental-modal-body">' +
                                '<div class="rental-preview-meta"></div>' +
                                '<div class="rental-preview-json-wrapper">' +
                                    '<textarea class="rental-preview-json" readonly></textarea>' +
                                '</div>' +
                            '</div>' +
                            '<div class="rental-modal-footer">' +
                                '<button type="button" class="button rental-modal-cancel">Close</button>' +
                                '<button type="button" class="button rental-preview-copy">Copy JSON</button>' +
                                '<button type="button" class="button button-primary rental-preview-apply">Apply This Template</button>' +
                            '</div>' +
                        '</div>' +
                    '</div>'
                );
                $('body').append($modal);
                
                // Bind modal events
                $modal.on('click', '.rental-modal-close, .rental-modal-cancel, .rental-modal-overlay', function() {
                    $modal.hide();
                });
                
                $modal.on('click', '.rental-preview-copy', function() {
                    var $textarea = $modal.find('.rental-preview-json');
                    $textarea.select();
                    document.execCommand('copy');
                    $(this).text('Copied!');
                    setTimeout(function() {
                        $modal.find('.rental-preview-copy').text('Copy JSON');
                    }, 2000);
                });
                
                $modal.on('click', '.rental-preview-apply', function() {
                    var templateId = $modal.data('template-id');
                    $modal.hide();
                    self.doApplyTemplate(templateId);
                });
                
                // ESC key to close
                $(document).on('keydown.templatePreview', function(e) {
                    if (e.keyCode === 27 && $modal.is(':visible')) {
                        $modal.hide();
                    }
                });
            }
            
            // Populate modal content
            $modal.find('.rental-preview-modal-title').text('Preview: ' + template.name);
            $modal.find('.rental-preview-meta').html(
                '<p><strong>Description:</strong> ' + (template.description || '<em>No description</em>') + '</p>' +
                '<p><strong>Version:</strong> ' + (template.version || '1.0.0') + 
                ' &nbsp;|&nbsp; <strong>Last Updated:</strong> ' + (template.updated_at || template.created_at || 'Unknown') + '</p>'
            );
            $modal.find('.rental-preview-json').val(layoutJson);
            $modal.data('template-id', template.id);
            
            // Show modal
            $modal.show();
        },

        /**
         * Show edit template modal
         *
         * @param {string} templateId - Template ID to edit
         */
        showEditTemplateModal: function(templateId) {
            var self = this;

            // Find the template item to get current values
            var $item = this.$elements.templatesList.find('[data-template-id="' + templateId + '"]');
            var name = $item.find('.rental-template-name').text();
            var description = $item.find('.rental-template-description').text();

            // Populate form
            this.$elements.editTemplateId.val(templateId);
            this.$elements.editTemplateName.val(name);
            this.$elements.editTemplateDescription.val(description);
            this.$elements.editTemplateUpdateLayout.prop('checked', false);

            // Show modal
            this.$elements.editTemplateModal.show();
        },

        /**
         * Close edit template modal
         */
        closeEditTemplateModal: function() {
            this.$elements.editTemplateModal.hide();
        },

        /**
         * Save template changes
         */
        saveTemplateChanges: function() {
            var self = this;
            var templateId = this.$elements.editTemplateId.val();
            var name = this.$elements.editTemplateName.val().trim();
            var description = this.$elements.editTemplateDescription.val().trim();
            var updateLayout = this.$elements.editTemplateUpdateLayout.is(':checked');

            if (!name) {
                this.showMessage('error', 'Template name is required.');
                return;
            }

            var data = {
                action: 'rental_update_layout_template',
                nonce: this.config.nonce,
                template_id: templateId,
                name: name,
                description: description
            };

            // Include layout if updating
            if (updateLayout) {
                var layoutJson = this.$elements.jsonEditor.val();
                try {
                    JSON.parse(layoutJson);
                    data.layout = layoutJson;
                } catch (e) {
                    this.showMessage('error', 'Cannot update with invalid JSON. Please fix the JSON editor first.');
                    return;
                }
            }

            this.setLoading(true);

            $.ajax({
                url: this.config.ajaxUrl,
                type: 'POST',
                data: data,
                success: function(response) {
                    self.setLoading(false);

                    if (response.success) {
                        self.showMessage('success', response.data.message || 'Template updated successfully.');
                        self.closeEditTemplateModal();
                        self.loadTemplates();
                    } else {
                        self.showMessage('error', response.data.message || 'Failed to update template.');
                    }
                },
                error: function(xhr, status, error) {
                    self.setLoading(false);
                    self.showMessage('error', 'AJAX request failed: ' + error);
                }
            });
        },

        /**
         * Confirm template deletion
         *
         * @param {string} templateId - Template ID to delete
         */
        confirmDeleteTemplate: function(templateId) {
            var self = this;
            var $item = this.$elements.templatesList.find('[data-template-id="' + templateId + '"]');
            var templateName = $item.find('.rental-template-name').text() || 'this template';
            
            // Store templateId for callback to avoid closure issues
            var idToDelete = templateId;
            
            this.showModal(
                'Delete Template',
                'Are you sure you want to delete "<strong>' + this.escapeHtml(templateName) + '</strong>"?<br><br>This action cannot be undone.',
                function() {
                    self.deleteTemplate(idToDelete);
                }
            );
        },

        /**
         * Delete a template
         *
         * @param {string} templateId - Template ID to delete
         */
        deleteTemplate: function(templateId) {
            var self = this;
            var $item = this.$elements.templatesList.find('[data-template-id="' + templateId + '"]');

            // Show loading on the item
            $item.addClass('is-loading');
            $item.find('.rental-template-loading').show();
            $item.find('.rental-template-actions button').prop('disabled', true);

            $.ajax({
                url: this.config.ajaxUrl,
                type: 'POST',
                dataType: 'json',
                timeout: 30000,
                data: {
                    action: 'rental_delete_layout_template',
                    nonce: this.config.nonce,
                    template_id: templateId
                },
                success: function(response) {
                    if (response.success) {
                        // Animate removal
                        $item.slideUp(300, function() {
                            $(this).remove();
                            // Update count
                            self.loadTemplates();
                        });
                        self.showTemplateMessage('success', response.data.message || 'Template deleted successfully.');
                    } else {
                        $item.removeClass('is-loading');
                        $item.find('.rental-template-loading').hide();
                        $item.find('.rental-template-actions button').prop('disabled', false);
                        self.showTemplateMessage('error', response.data.message || 'Failed to delete template.');
                    }
                },
                error: function(xhr, status, error) {
                    $item.removeClass('is-loading');
                    $item.find('.rental-template-loading').hide();
                    $item.find('.rental-template-actions button').prop('disabled', false);
                    
                    var errorMsg = status === 'timeout' ? 'Request timed out. Please try again.' : 'Failed to delete template: ' + error;
                    self.showTemplateMessage('error', errorMsg);
                }
            });
        },

        /**
         * Escape HTML for safe display
         *
         * @param {string} str - String to escape
         * @return {string} Escaped string
         */
        escapeHtml: function(str) {
            if (!str) return '';
            var div = document.createElement('div');
            div.textContent = str;
            return div.innerHTML;
        },

        /**
         * Initialize Thank You Message tab functionality
         */
        initThankYouTab: function() {
            var self = this;

            // Save button click
            $('#rental-save-thank-you').on('click', function(e) {
                e.preventDefault();
                self.saveThankYouMessage();
            });

            // Preview button click
            $('#rental-preview-thank-you').on('click', function(e) {
                e.preventDefault();
                self.previewThankYouMessage();
            });

            // Modal close
            $(document).on('click', '.rental-modal-close, .rental-modal-overlay', function() {
                $('#rental-thank-you-preview-modal').hide();
            });

            // ESC key to close modal
            $(document).on('keydown', function(e) {
                if (e.keyCode === 27) {
                    $('#rental-thank-you-preview-modal').hide();
                }
            });
        },

        /**
         * Read a wp_editor field, whichever tab it is on.
         *
         * The visual editor only mirrors into the textarea when asked to save,
         * and the Code tab edits that textarea directly, so reading the editor
         * instance alone drops changes made on the Code tab.
         *
         * @param {string} id Editor textarea id.
         * @return {string} Editor content.
         */
        getEditorContent: function(id) {
            if (window.wp && wp.editor && typeof wp.editor.getContent === 'function') {
                var content = wp.editor.getContent(id);

                if (typeof content === 'string') {
                    return content;
                }
            }

            return $('#' + id).val() || '';
        },

        /**
         * Save Thank You Message and Display Options via AJAX
         */
        saveThankYouMessage: function() {
            var self = this;
            var $btn = $('#rental-save-thank-you');
            var btnOrigHtml = $btn.html();
            var $status = $('#rental-thank-you-status');
            var message = CheckoutLayoutAdmin.getEditorContent('rental_thank_you_editor');

            // Get show_only checkbox value
            var showOnly = $('#rental-thank-you-only').is(':checked') ? '1' : '';

            // Show saving status
            $btn.addClass('rental-btn-saving').prop('disabled', true);
            $status.removeClass('success error').addClass('saving').text('Saving...').show();

            $.ajax({
                url: this.config.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'rental_checkout_save_thank_you',
                    nonce: $('#rental_thank_you_nonce').val(),
                    message: message,
                    show_only: showOnly
                },
                success: function(response) {
                    $btn.removeClass('rental-btn-saving').prop('disabled', false).html(btnOrigHtml);
                    if (response.success) {
                        $status.removeClass('saving error').addClass('success').text('Settings Saved!');
                        setTimeout(function() {
                            $status.fadeOut();
                        }, 3000);
                    } else {
                        $status.removeClass('saving success').addClass('error').text(response.data.message || 'Error saving.');
                    }
                },
                error: function(xhr, status, error) {
                    $btn.removeClass('rental-btn-saving').prop('disabled', false).html(btnOrigHtml);
                    $status.removeClass('saving success').addClass('error').text('AJAX error: ' + error);
                }
            });
        },

        /**
         * Preview Thank You Message
         */
        previewThankYouMessage: function() {
            var message = CheckoutLayoutAdmin.getEditorContent('rental_thank_you_editor');

            if (!message.trim()) {
                message = '<p><em>No message configured yet.</em></p>';
            }

            // Show preview modal
            $('#rental-thank-you-preview-content').html(message);
            $('#rental-thank-you-preview-modal').show();
        }
    };

    // Initialize on document ready
    $(document).ready(function() {
        CheckoutLayoutAdmin.init();
        CheckoutLayoutAdmin.initThankYouTab();
        CheckoutLayoutAdmin.initReviewDisplaySettings();

        // Expose globally so Visual Builder and template handlers can call switchTab
        window.RentalCheckoutLayoutAdmin = CheckoutLayoutAdmin;
    });

    /**
     * Review Order Display Settings — save via AJAX
     */
    CheckoutLayoutAdmin.initReviewDisplaySettings = function() {
        $('#rental-save-review-display').on('click', function(e) {
            e.preventDefault();
            var $btn    = $(this);
            var $status = $('#rental-review-display-status');
            var btnOrigHtml = $btn.html();
            var settings = {};

            // Gather all checkbox states
            $('.rental-review-display-checkboxes input[type="checkbox"]').each(function() {
                var key = $(this).data('review-item');
                settings[key] = $(this).is(':checked') ? 1 : 0;
            });

            $btn.addClass('rental-btn-saving').prop('disabled', true);
            $status.hide();

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action:   'rental_checkout_save_review_display',
                    nonce:    $('#rental_checkout_layout_nonce_field').val(),
                    settings: settings
                },
                success: function(response) {
                    $btn.removeClass('rental-btn-saving').prop('disabled', false).html(btnOrigHtml);
                    if (response.success) {
                        $status.text(response.data.message).css('color', '#46b450').show();
                    } else {
                        $status.text(response.data.message || 'Save failed.').css('color', '#dc3232').show();
                    }
                    setTimeout(function() { $status.fadeOut(); }, 3000);
                },
                error: function() {
                    $btn.removeClass('rental-btn-saving').prop('disabled', false).html(btnOrigHtml);
                    $status.text('Network error.').css('color', '#dc3232').show();
                }
            });
        });

        // =====================================================================
        // Enable All / Disable All for Review Display
        // =====================================================================
        $('#rental-review-display-enable-all').on('click', function() {
            $('.rental-review-display-checkboxes input[type="checkbox"]').prop('checked', true);
        });
        $('#rental-review-display-disable-all').on('click', function() {
            $('.rental-review-display-checkboxes input[type="checkbox"]').prop('checked', false);
        });

        // =====================================================================
        // Marketing Section — Form submission via AJAX
        // =====================================================================
        $('#rental-marketing-section-form').on('submit', function(e) {
            e.preventDefault();
            var $btn    = $('#rental-save-marketing-section');
            var $status = $('#rental-marketing-section-status');
            var btnOrigHtml = $btn.html();

            var enabled  = $('#rental-marketing-enabled').is(':checked') ? '1' : '0';
            var bannerId = $('#rental-marketing-banner-id').val();

            var content = CheckoutLayoutAdmin.getEditorContent('rental_marketing_content_editor');

            $btn.addClass('rental-btn-saving').prop('disabled', true);
            $status.hide();

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action:    'rental_checkout_save_marketing',
                    nonce:     $('#rental_marketing_section_nonce').val(),
                    enabled:   enabled,
                    banner_id: bannerId,
                    content:   content
                },
                success: function(response) {
                    $btn.removeClass('rental-btn-saving').prop('disabled', false).html(btnOrigHtml);
                    if (response.success) {
                        $status.text(response.data.message || 'Saved!').css('color', '#46b450').show();
                    } else {
                        $status.text(response.data.message || 'Save failed.').css('color', '#dc3232').show();
                    }
                    setTimeout(function() { $status.fadeOut(); }, 3000);
                },
                error: function() {
                    $btn.removeClass('rental-btn-saving').prop('disabled', false).html(btnOrigHtml);
                    $status.text('Network error.').css('color', '#dc3232').show();
                }
            });
        });

        // Marketing Banner Upload via WP Media
        $('#rental-marketing-upload-banner').on('click', function(e) {
            e.preventDefault();
            var mediaFrame = wp.media({
                title: 'Select Banner Image',
                button: { text: 'Use as Banner' },
                multiple: false,
                library: { type: 'image' }
            });
            mediaFrame.on('select', function() {
                var attachment = mediaFrame.state().get('selection').first().toJSON();
                $('#rental-marketing-banner-id').val(attachment.id);
                var imgUrl = attachment.sizes && attachment.sizes.medium ? attachment.sizes.medium.url : attachment.url;
                $('#rental-marketing-banner-img').attr('src', imgUrl).show();
                $('#rental-marketing-banner-preview').show();
                $('#rental-marketing-upload-banner').html('<span class="dashicons dashicons-format-image" style="margin-top: 4px;"></span> Change Banner Image');
            });
            mediaFrame.open();
        });

        // Marketing Banner Remove
        $('#rental-marketing-remove-banner').on('click', function() {
            $('#rental-marketing-banner-id').val('');
            $('#rental-marketing-banner-img').attr('src', '').hide();
            $('#rental-marketing-banner-preview').hide();
            $('#rental-marketing-upload-banner').html('<span class="dashicons dashicons-format-image" style="margin-top: 4px;"></span> Upload Banner Image');
        });

        // Marketing Preview
        $('#rental-preview-marketing-section').on('click', function() {
            var bannerId = $('#rental-marketing-banner-id').val();
            var bannerSrc = $('#rental-marketing-banner-img').attr('src');
            var content = CheckoutLayoutAdmin.getEditorContent('rental_marketing_content_editor');
            var $banner = $('#rental-marketing-preview-banner');
            if (bannerId && bannerSrc) {
                $banner.html('<img src="' + bannerSrc + '" style="max-width:100%;height:auto;border-radius:4px;" />');
            } else {
                $banner.html('');
            }
            $('#rental-marketing-preview-content').html(content);
            $('#rental-marketing-preview-modal').show();
        });
        $('#rental-marketing-preview-modal').on('click', '.rental-modal-close, .rental-modal-overlay', function() {
            $('#rental-marketing-preview-modal').hide();
        });

        // =====================================================================
        // Visual Builder "Save as Template" button
        // =====================================================================
        $('#rvb-save-as-template').on('click', function() {
            $('#rental-vb-tpl-name').val('');
            $('#rental-vb-tpl-desc').val('');
            $('#rental-vb-save-template-modal').show();
        });

        // Close save-as-template modal
        $('#rental-vb-save-template-modal').on('click', '.rental-modal-close, .rental-modal-cancel, .rental-modal-overlay', function() {
            $('#rental-vb-save-template-modal').hide();
        });

        // Do the save
        $('#rental-vb-tpl-save-btn').on('click', function() {
            var name = $.trim($('#rental-vb-tpl-name').val());
            if (!name) {
                alert('Please enter a template name.');
                return;
            }
            var desc = $.trim($('#rental-vb-tpl-desc').val());
            var $btn = $(this);

            // Collect layout JSON from VB (or fallback to JSON editor)
            var layoutJson = '';
            if (window.rentopianVisualBuilder && typeof window.rentopianVisualBuilder.collectLayout === 'function') {
                layoutJson = JSON.stringify(window.rentopianVisualBuilder.collectLayout(), null, 2);
            } else {
                layoutJson = $('#rental-layout-json').val();
            }

            if (!layoutJson) {
                alert('No layout to save. Load a layout first.');
                return;
            }

            $btn.prop('disabled', true).text('Saving...');

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'rental_save_layout_template',
                    nonce: $('#rental_checkout_layout_nonce_field').val(),
                    name: name,
                    description: desc,
                    layout: layoutJson
                },
                success: function(response) {
                    $btn.prop('disabled', false).text('Save Template');
                    if (response.success) {
                        $('#rental-vb-save-template-modal').hide();
                        // Show success on VB status
                        var $st = $('#rvb-save-status');
                        $st.text('Template saved!').css('color', '#46b450').show();
                        setTimeout(function() { $st.fadeOut(); }, 3000);
                    } else {
                        alert(response.data.message || 'Failed to save template.');
                    }
                },
                error: function() {
                    $btn.prop('disabled', false).text('Save Template');
                    alert('Network error saving template.');
                }
            });
        });

        // =====================================================================
        // Template Edit Modal: "Edit in Visual Builder" / "Edit in JSON Editor"
        // =====================================================================
        $(document).on('click', '.rental-template-open-in-vb', function() {
            var templateId = $('#rental-edit-template-id').val();
            if (!templateId) return;

            // Fetch the template layout, load into VB, switch tab
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'rental_load_layout_template',
                    nonce: $('#rental_checkout_layout_nonce_field').val(),
                    template_id: templateId
                },
                success: function(response) {
                    if (response.success && response.data.template && response.data.template.layout) {
                        var formatted = JSON.stringify(response.data.template.layout, null, 2);
                        // Update JSON editor as source of truth
                        $('#rental-layout-json').val(formatted);
                        // Close modal
                        $('#rental-edit-template-modal').hide();
                        // Switch to VB and force reload
                        if (window.RentalCheckoutLayoutAdmin) {
                            window.RentalCheckoutLayoutAdmin.switchTab('visual-builder');
                        }
                        if (window.rentopianVisualBuilder) {
                            window.rentopianVisualBuilder._loaded = false;
                            window.rentopianVisualBuilder.loadLayout();
                        }
                    } else {
                        alert('Could not load template layout.');
                    }
                }
            });
        });

        $(document).on('click', '.rental-template-open-in-json', function() {
            var templateId = $('#rental-edit-template-id').val();
            if (!templateId) return;

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'rental_load_layout_template',
                    nonce: $('#rental_checkout_layout_nonce_field').val(),
                    template_id: templateId
                },
                success: function(response) {
                    if (response.success && response.data.template && response.data.template.layout) {
                        var formatted = JSON.stringify(response.data.template.layout, null, 2);
                        $('#rental-layout-json').val(formatted);
                        $('#rental-edit-template-modal').hide();
                        if (window.RentalCheckoutLayoutAdmin) {
                            window.RentalCheckoutLayoutAdmin.switchTab('json-editor');
                        }
                    } else {
                        alert('Could not load template layout.');
                    }
                }
            });
        });
    };

    // =========================================================================
    // DEBUG TOGGLE (Visual Builder tab)
    // =========================================================================
    $(function () {
        var $toggle = $('#rvb-debug-toggle');
        if (!$toggle.length) return;

        $toggle.on('change', function () {
            var enabled = $(this).is(':checked') ? '1' : '0';
            $.post(ajaxurl, {
                action: 'rental_checkout_toggle_debug',
                nonce:  $('#rental_checkout_layout_nonce_field').val(),
                enabled: enabled
            }, function (resp) {
                if (resp.success && resp.data && resp.data.message) {
                    // Brief inline flash near the checkbox
                    var $label = $toggle.closest('label');
                    var $msg = $('<span style="margin-left:8px;font-size:11px;color:#00a32a;"></span>').text(resp.data.message);
                    $label.after($msg);
                    $msg.delay(2500).fadeOut(400, function () { $(this).remove(); });
                }
            });
        });
    });

})(jQuery);
