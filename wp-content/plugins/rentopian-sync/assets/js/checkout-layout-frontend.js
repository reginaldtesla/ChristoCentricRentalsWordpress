/**
 * Rentopian Checkout Layout - Frontend JavaScript
 * 
 * Handles field dependencies, visibility toggling, validation feedback,
 * loading states, error ordering, and Google autocomplete for checkout.
 * 
 * @package Rentopian_Sync
 * @subpackage Checkout_Layout
 * @since 1.4.1
 */

(function($) {
    'use strict';

    /**
     * Rentopian Checkout Layout Module
     */
    var RentopianCheckoutLayout = {

        /**
         * Configuration
         */
        config: {
            debug: false,
            animationDuration: 200,
            scrollOffset: 200, // 200px above the element when scrolling
            googleAutocompleteTriggerDelay: 1000,
            deliveryFieldsLogDelay: 3000,
            // Force recovery if the checkout request never completes; must exceed
            // the server-side API timeouts so slow-but-valid orders are not aborted
            submitWatchdogTimeout: 150000,
            selectors: {
                form: '.rentopian-checkout-form',
                checkoutForm: 'form.checkout, form.woocommerce-checkout',
                section: '.rentopian-section',
                row: '.rentopian-row',
                column: '.rentopian-col',
                dependentField: '[data-depends-on]',
                hiddenVisual: '.rentopian-hidden-visual-wrapper',
                shippingSection: '.rentopian-shipping-section',
                shipToDifferent: '#ship_to_different_address',
                errorNotice: '.woocommerce-error, .woocommerce-NoticeGroup-checkout',
                loadingOverlay: '.rentopian-checkout-loading',
                placeOrderBtn: '#place_order'
            },
            errorFieldClass: 'rentopian-field-error',
            loadingClass: 'rentopian-checkout-processing'
        },

        /**
         * Track if we're currently submitting to prevent stale error display
         */
        isSubmitting: false,
        lastSubmitTime: 0,

        /**
         * Live error registry: tracked errors from the last server validation,
         * each linked to its summary list item and field so the summary can be
         * updated in real time as the user corrects fields.
         */
        activeErrors: [],
        lastErrorsProcessedAt: 0,

        /**
         * Checkout request tracking: detects a hung/blocked submission so the
         * form never deadlocks behind WooCommerce's "processing" lock.
         */
        checkoutXhr: null,
        checkoutRequestActive: false,
        submitWatchdogTimer: null,

        /**
         * Pending address values from Google autocomplete that should be restored after update_checkout
         */
        pendingAddressValues: null,

        /**
         * Field order priority - LOWER number = appears FIRST in error list.
         * Filled from rentopianCheckoutConfig.fieldOrderPriority when modern layout is enabled; otherwise fallback.
         */
        fieldOrderPriority: {
            'billing_first_name': 1,
            'billing_last_name': 2,
            'billing_phone': 3,
            'billing_email': 4,
            'billing_address_1': 5,
            'billing_address_2': 6,
            'billing_city': 7,
            'billing_state': 8,
            'billing_postcode': 9,
            'billing_country': 10,
            'rental_referral_source_id': 20,
            'rental_event_types_id': 21,
            'delivery_time_selections_id': 22,
            'pickup_time_selections_id': 23,
            'rental_event_time': 24,
            // Pickup address fields
            'rental_pick_up_address_1': 50,
            'rental_pick_up_city': 51,
            'rental_pick_up_state': 52,
            'rental_pick_up_postcode': 53,
            'rental_pick_up_country': 54,
            'order_comments': 100
        },

        /**
         * Hidden visual field keywords - errors containing these are filtered for DISPLAY only.
         * Filled from rentopianCheckoutConfig.hiddenVisualKeywords when modern layout is enabled; otherwise fallback.
         */
        hiddenVisualKeywords: [
            'country',
            'region',
            'state',
            'province',
            'city',
            'town',
            'zip',
            'postcode',
            'postal code',
            'zip code'
        ],

        /**
         * Custom field label overrides from the layout (field_id -> label).
         * Re-applied after WooCommerce's address i18n, which rewrites state/postcode
         * (and country) labels on load and on country change.
         */
        labelOverrides: {},

        /**
         * Field mappings
         */
        fieldMappings: {
            // PICKUP ADDRESS FIELDS - must be BEFORE billing fields for correct matching
            'pickup_address': {
                selectors: ['#rental_pick_up_address_1', '[name="rental_pick_up_address_1"]'],
                keywords: ['pick up street address', 'pickup street address', 'pick up address', 'pickup address', 'rental_pick_up_address_1'],
                fieldId: 'rental_pick_up_address_1'
            },
            'pickup_city': {
                selectors: ['#rental_pick_up_city', '[name="rental_pick_up_city"]'],
                keywords: ['pick up city', 'pickup city', 'rental_pick_up_city'],
                fieldId: 'rental_pick_up_city'
            },
            'pickup_state': {
                selectors: ['#rental_pick_up_state', '[name="rental_pick_up_state"]'],
                keywords: ['pick up state', 'pickup state', 'rental_pick_up_state'],
                fieldId: 'rental_pick_up_state'
            },
            'pickup_postcode': {
                selectors: ['#rental_pick_up_postcode', '[name="rental_pick_up_postcode"]'],
                keywords: ['pick up zip', 'pickup zip', 'pick up postcode', 'pickup postcode', 'rental_pick_up_postcode'],
                fieldId: 'rental_pick_up_postcode'
            },
            'pickup_country': {
                selectors: ['#rental_pick_up_country', '[name="rental_pick_up_country"]'],
                keywords: ['pick up country', 'pickup country', 'rental_pick_up_country'],
                fieldId: 'rental_pick_up_country'
            },
            // BILLING FIELDS
            'first name': {
                selectors: ['#billing_first_name', '[name="billing_first_name"]'],
                keywords: ['first name', 'billing first name'],
                fieldId: 'billing_first_name'
            },
            'last name': {
                selectors: ['#billing_last_name', '[name="billing_last_name"]'],
                keywords: ['last name', 'billing last name'],
                fieldId: 'billing_last_name'
            },
            'email': {
                selectors: ['#billing_email', '[name="billing_email"]'],
                keywords: ['email', 'e-mail', 'billing email', 'email address'],
                fieldId: 'billing_email'
            },
            'phone': {
                selectors: ['#billing_phone', '[name="billing_phone"]'],
                keywords: ['phone', 'telephone', 'billing phone'],
                fieldId: 'billing_phone'
            },
            'address': {
                selectors: ['#billing_address_1', '[name="billing_address_1"]'],
                keywords: ['delivery address', 'street address', 'address', 'venue', 'billing address', 'venue / address', 'billing_address_1'],
                fieldId: 'billing_address_1'
            },
            'address_2': {
                selectors: ['#billing_address_2', '[name="billing_address_2"]'],
                keywords: ['apartment', 'suite', 'unit', 'address line 2'],
                fieldId: 'billing_address_2'
            },
            'city': {
                selectors: ['#billing_city', '[name="billing_city"]'],
                keywords: ['city', 'town', 'billing city'],
                fieldId: 'billing_city'
            },
            'state': {
                selectors: ['#billing_state', '[name="billing_state"]'],
                keywords: ['state', 'county', 'province', 'billing state'],
                fieldId: 'billing_state'
            },
            'postcode': {
                selectors: ['#billing_postcode', '[name="billing_postcode"]'],
                keywords: ['postcode', 'zip', 'zip code', 'postal code', 'billing postcode'],
                fieldId: 'billing_postcode'
            },
            'country': {
                selectors: ['#billing_country', '[name="billing_country"]'],
                keywords: ['country', 'billing country'],
                fieldId: 'billing_country'
            },
            'referral_source': {
                selectors: ['#rental_referral_source_id', '[name="rental_referral_source_id"]'],
                keywords: ['how did you find us', 'referral source', 'where did you find us', 'referral', 'find us'],
                fieldId: 'rental_referral_source_id'
            },
            'event_type': {
                selectors: ['#rental_event_types_id', '[name="rental_event_types_id"]'],
                keywords: ['event type'],
                fieldId: 'rental_event_types_id'
            },
            'delivery_time': {
                selectors: ['#delivery_time_selections_id', '[name="delivery_time_selections_id"]'],
                keywords: ['delivery time', 'delivery window', '2 hour delivery'],
                fieldId: 'delivery_time_selections_id'
            },
            'pickup_time': {
                selectors: ['#pickup_time_selections_id', '[name="pickup_time_selections_id"]'],
                keywords: ['pickup time', 'strike time', 'pickup window', '2 hour strike', '2 hour pickup'],
                fieldId: 'pickup_time_selections_id'
            },
            'order_notes': {
                selectors: ['#order_comments', '[name="order_comments"]'],
                keywords: ['order notes', 'additional notes', 'order comments'],
                fieldId: 'order_comments'
            }
        },

        /**
         * Initialize
         */
        init: function() {
            var self = this;

            if (!$(this.config.selectors.form).length && !$(this.config.selectors.checkoutForm).length) {
                return;
            }

            // Suppress Google Maps error dialog when no API key is configured
            if (typeof rentopianCheckoutLayoutConfig !== 'undefined' && !rentopianCheckoutLayoutConfig.hasGoogleApiKey) {
                // Prevent Google Maps "can't load correctly" popup
                window.gm_authFailure = function () {};
                // Hide any existing Google error dialogs
                var styleEl = document.createElement('style');
                styleEl.textContent = '.dismissButton,.gm-err-container,.gm-style-pbc{display:none!important}div[style*="maps.gstatic.com"]{display:none!important}';
                document.head.appendChild(styleEl);
            }

            // Apply debug mode from server config (admin toggle or WP_DEBUG)
            if (typeof rentopianCheckoutLayoutConfig !== 'undefined' && rentopianCheckoutLayoutConfig.debug) {
                this.config.debug = true;
            } else if (typeof rentopianCheckoutConfig !== 'undefined' && rentopianCheckoutConfig.debug) {
                this.config.debug = true;
            }

            this.log('=== Initializing RentopianCheckoutLayout v1.4.1 ===');

            // Apply dynamic config from server (field order and hidden-visual keywords from layout)
            if (typeof rentopianCheckoutConfig !== 'undefined') {
                if (rentopianCheckoutConfig.fieldOrderPriority && typeof rentopianCheckoutConfig.fieldOrderPriority === 'object') {
                    this.fieldOrderPriority = rentopianCheckoutConfig.fieldOrderPriority;
                }
                if (Array.isArray(rentopianCheckoutConfig.hiddenVisualKeywords)) {
                    this.hiddenVisualKeywords = rentopianCheckoutConfig.hiddenVisualKeywords;
                }
                if (rentopianCheckoutConfig.labelOverrides && typeof rentopianCheckoutConfig.labelOverrides === 'object') {
                    this.labelOverrides = rentopianCheckoutConfig.labelOverrides;
                }
            }

            // Checkout is validated via AJAX server-side; native browser validation
            // could silently block submission when hidden required fields exist
            $(this.config.selectors.checkoutForm).attr('novalidate', 'novalidate');

            this.createLoadingOverlay();
            this.bindCheckoutRequestTracking();
            this.bindDependencyEvents();
            this.bindShipToDifferentEvents();
            this.bindValidationEvents();
            this.bindSubmitEvents();
            this.bindPlaceOrderSync();
            this.bindAddressUpdateCheckout();
            this.bindShippingLoadingIndicator();
            this.syncAllBillingFieldsBeforeSubmit();
            this.setInitialStates();
            this.bindWooCommerceEvents();
            this.bindLabelOverrides();

            // Modern checkout only features
            if ($(this.config.selectors.form).length) {
                this.log('Modern checkout layout detected');
                
                // CRITICAL: Disable WC's hidden duplicate inputs to prevent them from overwriting
                // our visible input values during form serialization
                this.disableWcDuplicateInputs();
                
                setTimeout(function() {
                    self.triggerGoogleAutocompleteIfNeeded();
                }, this.config.googleAutocompleteTriggerDelay);

                setTimeout(function() {
                    self.logDeliveryFields('3 seconds after page load');
                }, this.config.deliveryFieldsLogDelay);

                this.bindDeliveryFieldChangeEvents();
                
                // Pickup address persistence feature
                this.bindPickupAddressPersistence();
                this.restorePickupAddressData();
            }

            this.log('=== Initialization complete ===');
        },

        /**
         * Resolve the address field element that is actually submitted (visible in custom layout).
         * When modern checkout is used, duplicate WC fields exist (disabled); we must read/write
         * the one inside .rentopian-checkout-form so values and logs are correct.
         */
        getVisibleAddressField: function(selector) {
            var $form = $(this.config.selectors.form);
            if (!$form.length) return $(selector).first();
            var name = (selector || '').replace(/^#/, '');
            var $el = $form.find('[name="' + name + '"]').first();
            if (!$el.length) $el = $form.find(selector).first();
            if (!$el.length) $el = $(selector).filter(function() { return $(this).closest('.rentopian-checkout-form').length; }).first();
            if (!$el.length) $el = $(selector).first();
            return $el;
        },

        /**
         * Log delivery field values (from visible/submitted fields only)
         */
        logDeliveryFields: function(reason) {
            if (!this.config.debug) return;

            var self = this;
            var fields = {
                'billing_address_1': self.getVisibleAddressField('#billing_address_1').val() || '(empty)',
                'billing_city': self.getVisibleAddressField('#billing_city').val() || '(empty)',
                'billing_state': self.getVisibleAddressField('#billing_state').val() || '(empty)',
                'billing_postcode': self.getVisibleAddressField('#billing_postcode').val() || '(empty)',
                'billing_country': self.getVisibleAddressField('#billing_country').val() || '(empty)'
            };

            console.log('');
            console.log('========================================');
            console.log('[RentopianCheckout] DELIVERY FIELDS LOG');
            console.log('Reason: ' + reason);
            console.log('Time: ' + new Date().toLocaleTimeString());
            console.log('========================================');
            console.log('Street Address:', fields.billing_address_1);
            console.log('City:', fields.billing_city);
            console.log('State:', fields.billing_state);
            console.log('ZIP:', fields.billing_postcode);
            console.log('Country:', fields.billing_country);
            console.log('========================================');
            console.log('');
        },

        /**
         * Bind delivery field change events
         */
        bindDeliveryFieldChangeEvents: function() {
            var self = this;
            var deliveryFields = '#billing_address_1, #billing_city, #billing_state, #billing_postcode, #billing_country';

            $(document).on('change.rentopianDeliveryLog', deliveryFields, function() {
                var fieldId = $(this).attr('id') || $(this).attr('name');
                self.logDeliveryFields('Field changed: ' + fieldId);
            });
        },

        /**
         * Trigger Google autocomplete if needed
         */
        triggerGoogleAutocompleteIfNeeded: function() {
            var self = this;

            // Bail if no Google Maps API key is configured — prevents error popup spam
            if (typeof rentopianCheckoutLayoutConfig !== 'undefined' && !rentopianCheckoutLayoutConfig.hasGoogleApiKey) {
                this.log('No Google Maps API key configured, skipping autocomplete');
                return;
            }

            var addressValue = self.getVisibleAddressField('#billing_address_1').val();
            if (!addressValue || $.trim(addressValue) === '') {
                this.log('No billing address value, skipping autocomplete trigger');
                return;
            }

            var cityVal = self.getVisibleAddressField('#billing_city').val();
            var stateVal = self.getVisibleAddressField('#billing_state').val();
            var countryVal = self.getVisibleAddressField('#billing_country').val();
            var postcodeVal = self.getVisibleAddressField('#billing_postcode').val();

            if (cityVal && stateVal && countryVal && postcodeVal) {
                this.log('All hidden address fields have values, skipping autocomplete trigger');
                return;
            }

            this.log('Attempting to trigger Google autocomplete for: ' + addressValue);

            this.waitForGoogleMapsAPI(function() {
                self.performGooglePlacesLookup(addressValue);
            });
        },

        /**
         * Wait for Google Maps API
         */
        waitForGoogleMapsAPI: function(callback, attempts) {
            var self = this;
            attempts = attempts || 0;
            var maxAttempts = 20;

            if (typeof google !== 'undefined' && google.maps && google.maps.places) {
                callback();
                return;
            }

            if (attempts >= maxAttempts) {
                this.log('Google Maps API not available');
                return;
            }

            setTimeout(function() {
                self.waitForGoogleMapsAPI(callback, attempts + 1);
            }, 500);
        },

        /**
         * Perform Google Places lookup
         */
        performGooglePlacesLookup: function(address) {
            var self = this;

            try {
                var service = new google.maps.places.AutocompleteService();

                service.getPlacePredictions({
                    input: address,
                    types: ['geocode']
                }, function(predictions, status) {
                    if (status !== google.maps.places.PlacesServiceStatus.OK || !predictions || !predictions.length) {
                        return;
                    }

                    self.getPlaceDetailsAndFill(predictions[0].place_id);
                });
            } catch (e) {
                this.log('Error in performGooglePlacesLookup: ' + e.message);
            }
        },

        /**
         * Get place details and fill fields
         */
        getPlaceDetailsAndFill: function(placeId) {
            var self = this;

            try {
                var tempDiv = document.createElement('div');
                var placesService = new google.maps.places.PlacesService(tempDiv);

                placesService.getDetails({
                    placeId: placeId,
                    fields: ['address_components', 'geometry', 'formatted_address']
                }, function(place, status) {
                    if (status !== google.maps.places.PlacesServiceStatus.OK || !place) {
                        return;
                    }

                    self.fillAddressFieldsFromPlace(place);
                });
            } catch (e) {
                this.log('Error in getPlaceDetailsAndFill: ' + e.message);
            }
        },

        /**
         * Fill address fields from place
         */
        fillAddressFieldsFromPlace: function(place) {
            var self = this;
            if (!place || !place.address_components) {
                this.log('fillAddressFieldsFromPlace: No place or address_components');
                return;
            }

            var components = place.address_components;
            this.log('fillAddressFieldsFromPlace: Processing ' + components.length + ' components');

            function extractComponent(type, nameType) {
                for (var i = 0; i < components.length; i++) {
                    if (components[i].types.indexOf(type) !== -1) {
                        return nameType === 'short' ? components[i].short_name : components[i].long_name;
                    }
                }
                return '';
            }

            var city = extractComponent('locality', 'long') || 
                       extractComponent('administrative_area_level_2', 'long') ||
                       extractComponent('sublocality_level_1', 'long');
            var state = extractComponent('administrative_area_level_1', 'short');
            var country = extractComponent('country', 'short');
            var postcode = extractComponent('postal_code', 'short');

            this.log('Extracted values - city: ' + city + ', state: ' + state + ', country: ' + country + ', postcode: ' + postcode);

            // Store pending values so we can restore them after update_checkout replaces the form
            this.pendingAddressValues = {
                city: city,
                state: state,
                country: country,
                postcode: postcode,
                timestamp: Date.now()
            };

            // IMPORTANT: Set country FIRST, then trigger update, then set state
            // Because state options depend on country selection
            if (country) {
                this.setFieldValueDirect('#billing_country', country);
            }
            if (city) {
                this.setFieldValueDirect('#billing_city', city);
            }
            if (postcode) {
                this.setFieldValueDirect('#billing_postcode', postcode);
            }
            // Set state after a small delay to allow country change to populate state options
            if (state) {
                var stateToSet = state;
                setTimeout(function() {
                    self.setFieldValueDirect('#billing_state', stateToSet);
                }, 100);
            }

            if (place.geometry && place.geometry.location) {
                var lat = place.geometry.location.lat();
                var lng = place.geometry.location.lng();
                
                // Always save billing-specific coordinates
                this.setCookie('rental_client_billing_address_lat', lat);
                this.setCookie('rental_client_billing_address_lng', lng);
                
                // Only update generic coordinates if NOT shipping to different address
                // This ensures delivery calculation uses the correct address source
                var $shipDiff = $('#ship_to_different_address');
                if (!$shipDiff.length) $shipDiff = $('#ship-to-different-address-checkbox');
                if (!$shipDiff.length || !$shipDiff.is(':checked')) {
                    this.setCookie('rental_client_address_lat', lat);
                    this.setCookie('rental_client_address_lng', lng);
                }
            }

            this.log('Address fields filled from Google Places');
            
            // Log fields after a delay to see if they were set
            var logSelf = this;
            setTimeout(function() {
                logSelf.logDeliveryFields('After Google autocomplete fill');
            }, 300);
            
            // Trigger update_checkout with a small delay to let field values settle
            setTimeout(function() {
                $(document.body).trigger('update_checkout');
            }, 200);
        },

        /**
         * Restore pending address values after WooCommerce updates the checkout
         * This handles the case where update_checkout replaces form elements and clears our values
         */
        restorePendingAddressValues: function() {
            var self = this;
            
            if (!this.pendingAddressValues) {
                return;
            }
            
            // Only restore if values are recent (within 5 seconds)
            var age = Date.now() - this.pendingAddressValues.timestamp;
            if (age > 5000) {
                this.pendingAddressValues = null;
                return;
            }
            
            var pending = this.pendingAddressValues;
            this.log('Restoring pending address values after update_checkout');
            
            // Check if values need to be restored
            var currentCity = this.getVisibleAddressField('#billing_city').val();
            var currentState = this.getVisibleAddressField('#billing_state').val();
            var currentCountry = this.getVisibleAddressField('#billing_country').val();
            var currentPostcode = this.getVisibleAddressField('#billing_postcode').val();
            
            // Only restore if fields are empty or different
            if (pending.country && !currentCountry) {
                this.setFieldValueDirect('#billing_country', pending.country);
            }
            if (pending.city && !currentCity) {
                this.setFieldValueDirect('#billing_city', pending.city);
            }
            if (pending.postcode && !currentPostcode) {
                this.setFieldValueDirect('#billing_postcode', pending.postcode);
            }
            
            // Restore state after a delay to allow country options to load
            if (pending.state && !currentState) {
                setTimeout(function() {
                    self.setFieldValueDirect('#billing_state', pending.state);
                }, 100);
            }
            
            // Clear pending values after restore
            this.pendingAddressValues = null;
        },

        /**
         * Set field value directly - handles both input and select elements
         * Also looks in multiple locations and handles disabled/readonly states
         */
        setFieldValueDirect: function(selector, value) {
            var self = this;
            if (value === undefined || value === null || value === '') {
                return;
            }
            
            var fieldName = (selector || '').replace(/^#/, '');
            
            // Find ALL fields with this name/id, not just first
            var $fields = $();
            
            // 1. Inside our modern checkout form
            var $formField = $('.rentopian-checkout-form').find('[name="' + fieldName + '"], ' + selector);
            if ($formField.length) {
                $fields = $fields.add($formField);
            }
            
            // 2. Standard WooCommerce fields  
            var $wcField = $(selector);
            if ($wcField.length) {
                $fields = $fields.add($wcField);
            }
            
            // 3. Any field with this name
            $fields = $fields.add($('[name="' + fieldName + '"]'));
            
            this.log('setFieldValueDirect: selector=' + selector + ', value=' + value + ', found ' + $fields.length + ' field(s)');
            
            $fields.each(function() {
                var $el = $(this);
                var tagName = ($el.prop('tagName') || '').toLowerCase();
                var isDisabled = $el.prop('disabled');
                
                self.log('  -> Setting ' + tagName + ' (disabled=' + isDisabled + '): ' + ($el.attr('name') || $el.attr('id')));
                
                // Temporarily enable if disabled (for hidden visual fields)
                if (isDisabled) {
                    $el.prop('disabled', false);
                }
                
                if (tagName === 'select') {
                    // For select elements, check if the option exists
                    var $option = $el.find('option[value="' + value + '"]');
                    if ($option.length) {
                        $el.val(value);
                        self.log('    Set select to: ' + value);
                    } else {
                        // Try to match by text content for state names
                        var found = false;
                        $el.find('option').each(function() {
                            var optVal = $(this).val();
                            var optText = $(this).text();
                            if (optVal === value || optText === value || 
                                optVal.toUpperCase() === value.toUpperCase() ||
                                optText.toUpperCase() === value.toUpperCase()) {
                                $el.val(optVal);
                                found = true;
                                self.log('    Set select to (matched): ' + optVal);
                                return false;
                            }
                        });
                        if (!found) {
                            self.log('    Option not found for value: ' + value);
                        }
                    }
                } else {
                    // For input elements
                    $el.val(value);
                    self.log('    Set input to: ' + value);
                }
                
                // Trigger change event
                $el.trigger('change');
                
                // Re-disable if it was disabled (don't re-disable, let form handle it)
                // Actually, we should keep it enabled so value is submitted
            });
        },

        setFieldValue: function(selector, value) {
            // Redirect to the improved method
            this.setFieldValueDirect(selector, value);
        },

        setCookie: function(name, value) {
            var expires = new Date();
            expires.setTime(expires.getTime() + (365 * 24 * 60 * 60 * 1000));
            document.cookie = name + '=' + encodeURIComponent(value) + ';expires=' + expires.toUTCString() + ';path=/;SameSite=Lax';
        },

        /**
         * Create loading overlay
         */
        createLoadingOverlay: function() {
            if ($(this.config.selectors.loadingOverlay).length) return;

            var loadingText = 'Processing...';
            if (typeof rentopianCheckoutConfig !== 'undefined' && rentopianCheckoutConfig.loadingText) {
                loadingText = rentopianCheckoutConfig.loadingText;
            }

            var $overlay = $(
                '<div class="rentopian-checkout-loading" style="display:none;">' +
                    '<div class="rentopian-loading-content">' +
                        '<div class="rentopian-loading-spinner"></div>' +
                        '<div class="rentopian-loading-text">' + loadingText + '</div>' +
                    '</div>' +
                '</div>'
            );

            $('body').append($overlay);
        },

        showLoading: function() {
            $(this.config.selectors.loadingOverlay).fadeIn(200);
            $('body').addClass(this.config.loadingClass);
        },

        hideLoading: function() {
            $(this.config.selectors.loadingOverlay).fadeOut(200);
            $('body').removeClass(this.config.loadingClass);
        },

        /**
         * Before submit: copy current value from visible billing field to ALL inputs
         * with that name so serialization sends one consistent (current) value.
         * Fixes: hidden WC duplicate fields (display:none) keep stale values so e.g.
         * clearing phone still sent old value; sync ensures every same-name input
         * has the visible value before WooCommerce serializes.
         * Runs in submit handler and capture-phase listener so it runs before WC.
         */
        syncAllBillingFieldsBeforeSubmit: function() {
            var self = this;
            var $form = $(this.config.selectors.checkoutForm);
            if (!$form.length) return;
            var fieldIds = [
                'billing_first_name', 'billing_last_name', 'billing_company', 'billing_email', 'billing_phone',
                'billing_address_1', 'billing_address_2', 'billing_city', 'billing_state', 'billing_postcode', 'billing_country',
                'shipping_first_name', 'shipping_last_name', 'shipping_address_1', 'shipping_address_2', 'shipping_city', 'shipping_state', 'shipping_postcode', 'shipping_country',
                'order_comments', 'rental_referral_source_id', 'rental_event_types_id', 'delivery_time_selections_id', 'pickup_time_selections_id'
            ];
            self.syncBillingFieldsToVisible = function() {
                fieldIds.forEach(function(id) {
                    var $visible = $form.find('#' + id).filter(function() { return $(this).closest('.rentopian-checkout-form').length || $(this).is(':visible'); }).first();
                    if (!$visible.length) return;
                    var type = ($visible.attr('type') || '').toLowerCase();
                    var val = type === 'checkbox' || type === 'radio' ? $visible.prop('checked') : $visible.val();

                    $form.find('input[name="' + id + '"], select[name="' + id + '"], textarea[name="' + id + '"]').each(function() {
                        var $el = $(this);
                        if ($el.attr('type') === 'checkbox' || $el.attr('type') === 'radio') {
                            $el.prop('checked', !!val);
                        } else {
                            $el.val(val);
                        }
                    });
                });
            };
            $form.on('submit.rentopianSyncFields', function() {
                self.syncBillingFieldsToVisible();
            });
            var formEl = $form[0];
            if (formEl && formEl.addEventListener) {
                formEl.addEventListener('submit', function syncCapture(e) {
                    self.syncBillingFieldsToVisible();
                }, true);
            }
        },

        /**
         * CRITICAL: Neutralize WooCommerce's hidden duplicate billing/shipping inputs.
         * When modern checkout is enabled, we render our own billing/shipping fields.
         * WooCommerce also renders its default fields (hidden by CSS but still in DOM).
         * 
         * Problem: CSS display:none does NOT prevent form serialization. jQuery serialize()
         * includes all non-disabled named inputs. PHP takes the LAST value for duplicate
         * names, and WC's empty hidden input comes AFTER our filled one in DOM order,
         * so PHP receives the empty value → validation fails.
         * 
         * Fix: REMOVE the name attribute entirely (not just disable). Without a name,
         * the input is never serialized. This is immune to updated_checkout re-enabling.
         */
        /**
         * CRITICAL FIX: Neutralize WooCommerce's hidden duplicate billing/shipping inputs.
         *
         * ROOT CAUSE: WC renders default billing fields hidden by CSS, but CSS display:none
         * does NOT prevent jQuery .serialize(). PHP takes the LAST value for duplicate names.
         * WC's hidden empty input appears AFTER ours → PHP gets empty → validation fails.
         *
         * WHY previous attempts failed:
         * - prop('disabled') → WC's updated_checkout re-enables them via fragment replacement
         * - removeAttr('name') on updated_checkout → WC replaces fragments AGAIN right before
         *   submit, restoring name attrs in the gap before our handler fires
         *
         * DEFINITIVE FIX (3-layer defense):
         * 1. neutralizeInputs() runs on page load
         * 2. neutralizeInputs() re-runs on every updated_checkout
         * 3. On form submit CAPTURE PHASE (runs BEFORE WC's jQuery .on('submit')):
         *    - Re-neutralize any fresh WC inputs
         *    - Copy our visible field values INTO any surviving WC hidden inputs as defense
         */
        disableWcDuplicateInputs: function() {
            var self = this;

            var wcSelectors = [
                '.woocommerce-billing-fields',
                '.woocommerce-shipping-fields',
                '.woocommerce-additional-fields'
            ];

            function neutralizeInputs() {
                wcSelectors.forEach(function(selector) {
                    var $wrapper = $(selector);
                    if (!$wrapper.length) return;

                    $wrapper.find('input, select, textarea').each(function() {
                        var $input = $(this);
                        var name = $input.attr('name');
                        if (!name) return;
                        // Only neutralize if our custom layout has a field with the same name
                        if ($('.rentopian-checkout-form').find('[name="' + name + '"]').length) {
                            $input.attr('data-original-name', name);
                            $input.removeAttr('name');
                            $input.prop('disabled', true);
                        }
                    });
                });
            }

            /**
             * Defense-in-depth: before WC serializes the form, re-neutralize AND
             * copy our visible values into any surviving WC hidden inputs.
             */
            function syncAndNeutralizeBeforeSubmit() {
                // Re-neutralize (catches fragment replacements since last updated_checkout)
                neutralizeInputs();

                // For any WC inputs that STILL have a name attr (race condition),
                // copy our visible field's value so PHP at least gets the right data.
                wcSelectors.forEach(function(selector) {
                    var $wrapper = $(selector);
                    if (!$wrapper.length) return;
                    $wrapper.find('input[name], select[name], textarea[name]').each(function() {
                        var $wcInput = $(this);
                        var name = $wcInput.attr('name');
                        if (!name) return;
                        var $ourInput = $('.rentopian-checkout-form').find('[name="' + name + '"]').first();
                        if ($ourInput.length) {
                            $wcInput.val($ourInput.val());
                        }
                    });
                });
            }

            // Layer 1: Run immediately on page load
            neutralizeInputs();

            // Layer 2: Re-run after every WC checkout update (fragment replacement)
            $(document.body).on('updated_checkout', function() {
                neutralizeInputs();

                setTimeout(function() {
                    self.restorePendingAddressValues();
                }, 50);

                if (typeof window.initAutocompleteCheckoutPage === 'function' &&
                    (typeof rentopianCheckoutLayoutConfig === 'undefined' || rentopianCheckoutLayoutConfig.hasGoogleApiKey)) {
                    setTimeout(function() {
                        window.initAutocompleteCheckoutPage();
                    }, 100);
                }
            });

            // Layer 3: On form submit CAPTURE PHASE — runs BEFORE WC's jQuery handlers
            var formEl = $('form.checkout')[0] || $('form.woocommerce-checkout')[0];
            if (formEl && formEl.addEventListener) {
                formEl.addEventListener('submit', function() {
                    syncAndNeutralizeBeforeSubmit();
                }, true); // capture = true
            }
        },

        /**
         * Sync address field values before submit so a fast click sends current values.
         * On mousedown (before click) blur the active element to commit its value and copy
         * visible values into duplicate inputs.
         *
         * IMPORTANT: This must NOT trigger 'change' on the address fields. Doing so spawns a
         * burst of update_order_review requests that block the payment/review area with a
         * blockUI overlay exactly as the user clicks Place Order — the overlay then covers
         * the button and the click never reaches WooCommerce's submit handler, so the order
         * appears stuck. Value syncing alone keeps serialization correct without an update.
         */
        bindPlaceOrderSync: function() {
            var self = this;
            var $form = $(this.config.selectors.checkoutForm);
            if (!$form.length) return;

            $(document).on('mousedown.rentopianSync', this.config.selectors.placeOrderBtn, function() {
                if (document.activeElement && document.activeElement.blur) {
                    document.activeElement.blur();
                }
                // Earliest opportunity to release a stale lock from a prior attempt
                if (self.unstickStaleProcessing()) {
                    self.reportCheckoutDiag('unstuck_on_mousedown');
                }
                self.ensurePaymentMethodSelected();
                if (self.syncBillingFieldsToVisible) {
                    self.syncBillingFieldsToVisible();
                }
            });
        },

        /**
         * Ensure a payment method radio is selected before submit.
         *
         * Fragment replacement during the validation-error flow can momentarily
         * leave every payment_method radio unchecked, so the form serializes an
         * empty payment_method. That empty value has been observed to crash order
         * processing server-side. If none is checked, select the first available
         * method (matching WooCommerce's own default-selection behavior).
         */
        ensurePaymentMethodSelected: function() {
            var $methods = $('form.checkout input[name="payment_method"]');
            if (!$methods.length || $methods.filter(':checked').length) {
                return;
            }
            $methods.eq(0).prop('checked', true).trigger('change');
            this.log('No payment method selected - selected first available');
        },

        /**
         * Trigger update_checkout when billing address changes so miles-based shipping recalculates.
         */
        bindAddressUpdateCheckout: function() {
            var self = this;
            var timer = null;
            var addressFieldSelectors = '#billing_address_1, #billing_city, #billing_state, #billing_postcode, #billing_country';
            var $form = $(this.config.selectors.checkoutForm);
            if (!$form.length) return;

            function triggerUpdate() {
                clearTimeout(timer);
                timer = setTimeout(function() {
                    $(document.body).trigger('update_checkout');
                    self.log('Address changed - triggered update_checkout');
                }, 300);
            }

            $form.on('change.rentopianAddress input.rentopianAddress', addressFieldSelectors, triggerUpdate);
        },

        /**
         * Return true if miles-based "please enter your address to calculate price" is visible.
         */
        hasMilesNeedsAddressMessage: function() {
            var text = 'please enter your address to calculate price';
            var $area = $(this.config.selectors.checkoutForm).find('#shipping_method, .woocommerce-shipping-methods, .woocommerce-checkout-review-order-table');
            return $area.length && $area.text().toLowerCase().indexOf(text) !== -1;
        },

        /**
         * Track the checkout AJAX request lifecycle. Used to detect a hung
         * request (server never responds) and a stale "processing" lock on
         * the form, both of which otherwise block all further submissions.
         */
        bindCheckoutRequestTracking: function() {
            var self = this;

            $(document).ajaxSend(function(event, jqXHR, settings) {
                if (settings.url && settings.url.indexOf('wc-ajax=checkout') !== -1) {
                    self.checkoutXhr = jqXHR;
                    self.checkoutRequestActive = true;
                    self.startSubmitWatchdog();
                    self.log('Checkout request started');
                }
            });
        },

        isCheckoutRequestInFlight: function() {
            return this.checkoutRequestActive;
        },

        /**
         * Report a checkout-stuck/recovery event to the server log so the
         * deadlock condition is captured in production (wc-logs) without
         * needing console access. Fire-and-forget; failures are ignored.
         *
         * @param {string} reason Short event identifier.
         */
        reportCheckoutDiag: function(reason) {
            try {
                var cfg = (typeof rentopianCheckoutLayoutConfig !== 'undefined') ? rentopianCheckoutLayoutConfig : null;
                if (!cfg || !cfg.ajaxUrl) {
                    return;
                }

                var $form = $(this.config.selectors.checkoutForm);
                var state = {
                    reason: reason,
                    processing: $form.length ? $form.is('.processing') : null,
                    formBlocked: ($form.length && $form.data('blockUI.isBlocked')) ? 1 : 0,
                    paymentBlocked: $('.woocommerce-checkout-payment').data('blockUI.isBlocked') ? 1 : 0,
                    reviewBlocked: $('.woocommerce-checkout-review-order-table').data('blockUI.isBlocked') ? 1 : 0,
                    checkoutRequestActive: this.checkoutRequestActive ? 1 : 0,
                    visibleError: $('.woocommerce-error:visible').length,
                    url: window.location.pathname
                };

                $.ajax({
                    type: 'POST',
                    url: cfg.ajaxUrl,
                    data: {
                        action: 'rental_checkout_diag',
                        nonce: cfg.nonce || '',
                        state: JSON.stringify(state)
                    }
                });

                this.log('Checkout diagnostic reported: ' + reason, state);
            } catch (e) {}
        },

        startSubmitWatchdog: function() {
            var self = this;
            this.clearSubmitWatchdog();
            this.submitWatchdogTimer = setTimeout(function() {
                self.recoverFromStalledCheckout();
            }, this.config.submitWatchdogTimeout);
        },

        clearSubmitWatchdog: function() {
            if (this.submitWatchdogTimer) {
                clearTimeout(this.submitWatchdogTimer);
                this.submitWatchdogTimer = null;
            }
        },

        /**
         * Last-resort recovery when the checkout request never completes.
         * Aborting the request triggers WooCommerce's own error handler, which
         * shows a notice, removes the form's processing lock, and fires
         * checkout_error, so the user can simply try again.
         */
        recoverFromStalledCheckout: function() {
            this.log('Checkout request stalled - forcing recovery');
            this.reportCheckoutDiag('watchdog_recovery');

            var xhr = this.checkoutXhr;
            this.checkoutXhr = null;
            this.checkoutRequestActive = false;

            if (xhr) {
                try { xhr.abort(); } catch (e) {}
            }

            this.isSubmitting = false;
            this.hideLoading();

            var $form = $(this.config.selectors.checkoutForm);
            if ($form.length) {
                $form.removeClass('processing');
                if ($.fn.unblock) {
                    $form.unblock();
                }
            }
        },

        /**
         * Clear stale WooCommerce locks before a new submission so the click is
         * never silently swallowed.
         *
         * Two locks can survive from a previous attempt and block the submit:
         *  1. The form's "processing" class — WooCommerce's submit() returns early
         *     while it is present, and it is only removed when a response arrives.
         *  2. A blockUI overlay left on the payment / review-order area by an
         *     update_order_review request that was aborted or never completed.
         *     That overlay sits on top of the Place Order button, so the click
         *     lands on the overlay instead of the button and no request fires.
         *
         * Never runs while a checkout request is genuinely in flight (that would
         * defeat WooCommerce's own double-submit protection).
         *
         * @return {boolean} True if anything was unstuck.
         */
        unstickStaleProcessing: function() {
            if (this.isCheckoutRequestInFlight()) {
                return false;
            }

            var unstuck = false;
            var $form = $(this.config.selectors.checkoutForm);

            if ($form.length && $form.is('.processing')) {
                $form.removeClass('processing');
                unstuck = true;
            }

            // Remove blockUI overlays that can cover the Place Order button
            if ($.fn.unblock) {
                if ($form.length && $form.data('blockUI.isBlocked')) {
                    $form.unblock();
                    unstuck = true;
                }
                $('.woocommerce-checkout-payment, .woocommerce-checkout-review-order-table, #order_review')
                    .filter(function() { return $(this).data('blockUI.isBlocked'); })
                    .each(function() {
                        $(this).unblock();
                        unstuck = true;
                    });
            }

            if (unstuck) {
                this.log('Cleared stale checkout lock(s) before submit');
            }

            return unstuck;
        },

        /**
         * Show the loading overlay only when a submission actually went out
         * (request in flight or form locked by WooCommerce). If the submit was
         * blocked before any request, recover instead of trapping the user
         * under a spinner that nothing will ever hide.
         */
        scheduleLoadingForSubmission: function() {
            var self = this;

            setTimeout(function() {
                var $form = $(self.config.selectors.checkoutForm);
                var processing = $form.length && $form.is('.processing');

                if (self.isCheckoutRequestInFlight() || processing) {
                    if (!$('.woocommerce-error:visible').length) {
                        self.showLoading();
                    }
                } else {
                    self.isSubmitting = false;
                    self.hideLoading();
                }
            }, 150);
        },

        /**
         * CRITICAL: Bind submit events - clear ALL previous errors before submission
         */
        bindSubmitEvents: function() {
            var self = this;
            var $form = $(this.config.selectors.checkoutForm);

            if (!$form.length) return;

            // Capture phase runs before WooCommerce's submit handler: release a
            // stale processing lock so the submission is not silently ignored.
            // Covers Enter-key and programmatic submits in addition to clicks.
            var formEl = $form[0];
            if (formEl && formEl.addEventListener) {
                formEl.addEventListener('submit', function() {
                    self.unstickStaleProcessing();
                }, true);
            }

            // Before form submission - COMPLETELY clear all errors
            $form.on('submit.rentopianLayout', function(e) {
                self.log('Form submitting - clearing all previous errors');
                
                if (self.hasMilesNeedsAddressMessage()) {
                    e.preventDefault();
                    e.stopImmediatePropagation();
                    self.hideLoading();
                    self.isSubmitting = false;
                    var msg = 'Please enter your full billing address so we can calculate delivery.';
                    if ($('.woocommerce-error').filter(function() { return $(this).text().indexOf('calculate delivery') !== -1; }).length === 0) {
                        $form.prepend('<ul class="woocommerce-error" role="alert"><li>' + msg + '</li></ul>');
                    }
                    return false;
                }
                
                // Set submitting flag
                self.isSubmitting = true;
                self.lastSubmitTime = Date.now();
                
                // CRITICAL: Completely remove ALL error notices from DOM
                self.removeAllErrorNotices();

                // Clear all field error highlights
                self.clearAllFieldErrors();

                // Show loading once the submission is confirmed in flight
                self.scheduleLoadingForSubmission();

                // Log delivery fields
                self.logDeliveryFields('Form submit');
            });

            // Place order button click
            $(document).on('click.rentopianLayout', this.config.selectors.placeOrderBtn, function() {
                self.log('Place order clicked');

                // Release a stale lock before the submit event fires. If anything
                // was stuck, this click would otherwise have been swallowed.
                if (self.unstickStaleProcessing()) {
                    self.reportCheckoutDiag('unstuck_on_click');
                }

                self.isSubmitting = true;
                self.lastSubmitTime = Date.now();

                // Remove all error notices before submission
                self.removeAllErrorNotices();
                self.clearAllFieldErrors();

                self.scheduleLoadingForSubmission();
            });
        },

        /**
         * CRITICAL: Remove ALL error notices from DOM completely
         */
        removeAllErrorNotices: function() {
            // Reset live error tracking - a new validation cycle starts
            this.activeErrors = [];
            this.serverErrorsActive = false;

            // Remove WooCommerce error notices
            $('.woocommerce-error').remove();
            $('.woocommerce-NoticeGroup-checkout').remove();
            $('.woocommerce-NoticeGroup').remove();
            
            // Remove our custom error wrappers
            $('.rentopian-error-wrapper').remove();
            
            // Remove any error notices in the checkout form
            $('form.checkout .woocommerce-error').remove();
            
            this.log('All error notices removed from DOM');
        },

        /**
         * Bind validation events
         */
        bindValidationEvents: function() {
            var self = this;

            // Listen for checkout errors
            $(document.body).on('checkout_error', function() {
                self.log('checkout_error event fired');
                self.hideLoading();
                self.isSubmitting = false;
                
                // Small delay to let WooCommerce render errors first
                setTimeout(function() {
                    self.handleValidationErrors();
                }, 100);
            });

            // Listen for AJAX complete
            $(document).ajaxComplete(function(event, xhr, settings) {
                if (settings.url && (
                    settings.url.indexOf('wc-ajax=checkout') !== -1 ||
                    settings.url.indexOf('?wc-ajax=checkout') !== -1
                )) {
                    // Request finished (success, error, or abort) - stop tracking it
                    self.checkoutRequestActive = false;
                    self.checkoutXhr = null;
                    self.clearSubmitWatchdog();

                    self.hideLoading();

                    // Check if this is a success (redirect) or error
                    try {
                        var response = JSON.parse(xhr.responseText);
                        if (response.result === 'success') {
                            self.log('Checkout successful - redirecting');
                            // Don't process errors on success
                            return;
                        }
                    } catch (e) {
                        // Not JSON or parse error - continue to process errors
                    }
                    
                    self.isSubmitting = false;
                    
                    setTimeout(function() {
                        self.handleValidationErrors();
                    }, 150);
                }
            });

            // Re-evaluate field state on every edit so highlights and the
            // error summary always reflect the current form values
            $(document).on('change.rentopianLayout input.rentopianLayout keyup.rentopianLayout',
                'form.checkout input, form.checkout select, form.checkout textarea',
                function() {
                    self.handleFieldLiveValidation($(this));
                }
            );

            // Select2 change
            $(document).on('select2:select select2:clear', 'form.checkout select', function() {
                self.handleFieldLiveValidation($(this));
            });
        },

        /**
         * Handle validation errors - filter, sort, and display
         */
        handleValidationErrors: function() {
            var self = this;
            
            // Don't process if we just submitted (prevents stale errors)
            if (this.isSubmitting) {
                this.log('Still submitting, skipping error processing');
                return;
            }

            // checkout_error and ajaxComplete both schedule processing for the same
            // response; process it once so the notice is rebuilt and scrolled once
            if (Date.now() - this.lastErrorsProcessedAt < 500) {
                this.log('Errors already processed for this response, skipping');
                return;
            }

            var $errorNotice = $('.woocommerce-error').first();

            // Clear previous highlights
            this.clearAllFieldErrors();

            if (!$errorNotice.length) {
                this.log('No error notices found');
                return;
            }

            this.log('Processing validation errors...');

            var $errorItems = $errorNotice.find('li');
            if (!$errorItems.length) {
                return;
            }

            // Collect all errors
            var errors = [];
            $errorItems.each(function() {
                var $li = $(this);
                var errorText = $li.text().trim();
                if (errorText) {
                    errors.push({
                        text: errorText,
                        $element: $li,
                        // Exact target field id from the server marker (billing vs
                        // shipping share labels, so text alone is ambiguous).
                        targetId: self.extractErrorTargetId($li)
                    });
                }
            });

            this.log('Found ' + errors.length + ' total errors');

            // Resolve each error's field first (via the exact server marker) so
            // hidden-visual filtering can key off the real field instead of the
            // label text, which is ambiguous and can leak keywords.
            errors.forEach(function(error) {
                var fieldInfo = self.resolveErrorField(error);
                error.$field = fieldInfo.$field;
                error.fieldId = fieldInfo.fieldId;
            });

            // Filter out errors whose field is genuinely hidden-visual (autocomplete-
            // filled, not user-editable). Visible fields always show their error.
            var filteredErrors = this.filterHiddenVisualErrors(errors);
            this.log('After filtering: ' + filteredErrors.length + ' errors');

            filteredErrors.forEach(function(error) {
                error.position = self.getFieldPosition(error.fieldId);
                error.textLower = error.text.toLowerCase();
                error.resolved = false;
            });

            // Sort by position
            filteredErrors.sort(function(a, b) {
                return a.position - b.position;
            });

            // Track errors for live resolution as the user corrects fields
            this.activeErrors = filteredErrors;
            this.lastErrorsProcessedAt = Date.now();
            // Server errors stay until the next submit or until the field is edited;
            // an automatic update_checkout must not silently wipe them.
            this.serverErrorsActive = true;

            // Rebuild error notice
            this.rebuildErrorNotice($errorNotice, filteredErrors);

            // Highlight error fields
            filteredErrors.forEach(function(error) {
                if (error.$field && error.$field.length) {
                    self.setFieldError(error.$field);
                }
            });

            // Scroll to error notice with 200px offset
            if ($errorNotice.is(':visible') && $errorNotice.offset()) {
                var scrollTarget = $errorNotice.offset().top - this.config.scrollOffset;
                $('html, body').animate({
                    scrollTop: Math.max(0, scrollTarget)
                }, 500);
                this.log('Scrolled to error notice with ' + this.config.scrollOffset + 'px offset');
            }
        },

        /**
         * Filter out errors the customer cannot act on: those whose field is
         * hidden-visual (in the DOM but visually hidden for autocomplete). Prefer the
         * resolved field; only fall back to keyword text matching for markerless
         * errors (e.g. WooCommerce core / classic checkout).
         */
        filterHiddenVisualErrors: function(errors) {
            var self = this;

            return errors.filter(function(error) {
                if (error.$field && error.$field.length) {
                    var isHidden = error.$field.closest(self.config.selectors.hiddenVisual).length > 0;
                    if (isHidden) {
                        self.log('Filtering out (hidden-visual field): ' + error.text);
                        return false;
                    }
                    return true;
                }

                // No field resolved — fall back to keyword text matching.
                var errorTextLower = error.text.toLowerCase();
                for (var i = 0; i < self.hiddenVisualKeywords.length; i++) {
                    if (errorTextLower.indexOf(self.hiddenVisualKeywords[i]) !== -1) {
                        self.log('Filtering out (keyword): ' + error.text);
                        return false;
                    }
                }

                return true;
            });
        },

        /**
         * Get field position for sorting
         */
        getFieldPosition: function(fieldId) {
            if (fieldId && this.fieldOrderPriority[fieldId]) {
                return this.fieldOrderPriority[fieldId];
            }

            if (fieldId) {
                var $field = $('#' + fieldId);
                if ($field.length && $field.offset()) {
                    return 1000 + $field.offset().top;
                }
            }

            return 9999;
        },

        /**
         * Read the exact target field id from a server error marker, if present.
         * The validator embeds <span class="rentopian-error-target rentopian-target-{id}">
         * so billing vs shipping (which share labels) resolve to the right input.
         */
        extractErrorTargetId: function($li) {
            var $marker = $li.find('.rentopian-error-target').first();
            if (!$marker.length) {
                return null;
            }
            var cls = $marker.attr('class') || '';
            var m = cls.match(/rentopian-target-(\S+)/);
            return m ? m[1] : null;
        },

        /**
         * Resolve the field an error points to. Prefer the exact server marker id;
         * fall back to keyword/label matching for messages without a marker
         * (WooCommerce core notices, classic checkout).
         */
        resolveErrorField: function(error) {
            if (error.targetId) {
                var $byId = $('#' + error.targetId);
                if ($byId.length) {
                    return { $field: $byId.first(), fieldId: error.targetId };
                }
            }
            return this.findFieldAndId(error.text.toLowerCase());
        },

        /**
         * Find field by error text
         */
        findFieldAndId: function(errorText) {
            var self = this;
            var result = { $field: null, fieldId: null };

            $.each(this.fieldMappings, function(key, mapping) {
                var keywords = mapping.keywords || [];
                var selectors = mapping.selectors || [];

                for (var i = 0; i < keywords.length; i++) {
                    if (errorText.indexOf(keywords[i]) !== -1) {
                        for (var j = 0; j < selectors.length; j++) {
                            var $found = $(selectors[j]);
                            if ($found.length) {
                                result.$field = $found.first();
                                result.fieldId = mapping.fieldId;
                                return false;
                            }
                        }
                    }
                }
            });

            if (!result.$field) {
                var labelResult = this.findFieldByLabelMatch(errorText);
                if (labelResult) {
                    result.$field = labelResult;
                    result.fieldId = labelResult.attr('id') || labelResult.attr('name');
                }
            }

            return result;
        },

        /**
         * Find field by label match
         */
        findFieldByLabelMatch: function(errorText) {
            var $field = null;

            var patterns = [
                /^(.+?)\s+is a required field/i,
                /^(.+?)\s+is required/i,
                /^please enter (?:a |an |your )?(.+)/i,
                /^please select (?:a |an |your )?(.+)/i
            ];

            var fieldLabel = null;
            for (var i = 0; i < patterns.length; i++) {
                var match = errorText.match(patterns[i]);
                if (match) {
                    // Strip HTML, special chars, and normalize whitespace (including &nbsp;)
                    fieldLabel = match[1]
                        .replace(/<[^>]*>/g, '')
                        .replace(/&nbsp;/gi, ' ')
                        .replace(/\u00A0/g, ' ')  // Non-breaking space character
                        .replace(/[*:?]/g, '')
                        .replace(/\s+/g, ' ')
                        .trim()
                        .toLowerCase();
                    break;
                }
            }

            if (!fieldLabel) return null;

            // Search in checkout forms, dynamic field wrappers, and any form-row
            var labelSelectors = [
                'form.checkout label',
                '.rentopian-checkout-form label',
                '#rentopian-checkout-form label',
                '.rentopian-dynamic-field-wrapper label',
                '.form-row label'
            ];
            
            $(labelSelectors.join(', ')).each(function() {
                var $label = $(this);
                // Normalize label text: strip HTML from children, handle &nbsp;, normalize whitespace
                var labelText = $label.text()
                    .replace(/\u00A0/g, ' ')  // Non-breaking space
                    .replace(/[*:?]/g, '')
                    .replace(/\s+/g, ' ')
                    .trim()
                    .toLowerCase();

                if (labelText.indexOf(fieldLabel) !== -1 || fieldLabel.indexOf(labelText) !== -1) {
                    var forAttr = $label.attr('for');
                    if (forAttr) {
                        var $foundField = $('#' + forAttr);
                        if ($foundField.length) {
                            $field = $foundField;
                            return false;
                        }
                    }

                    // Try to find field in wrapper
                    var $wrapper = $label.closest('.form-row, .rentopian-col, .rentopian-dynamic-field-wrapper');
                    var $foundField = $wrapper.find('input, select, textarea').first();
                    if ($foundField.length) {
                        $field = $foundField;
                        return false;
                    }
                }
            });

            return $field;
        },

        /**
         * Rebuild error notice with sorted/filtered errors
         */
        rebuildErrorNotice: function($notice, errors) {
            var self = this;
            
            if (!errors.length) {
                $notice.hide();
                return;
            }

            // Clear and rebuild
            $notice.empty();

            // Wrap if needed
            if (!$notice.parent().hasClass('rentopian-error-wrapper')) {
                $notice.wrap('<div class="rentopian-error-wrapper"></div>');
            }

            // Add close button
            $notice.parent().find('.rentopian-error-close').remove();
            var $closeBtn = $('<button type="button" class="rentopian-error-close" aria-label="Close">&times;</button>');
            $notice.parent().prepend($closeBtn);

            $closeBtn.on('click', function(e) {
                e.preventDefault();
                self.activeErrors = [];
                self.serverErrorsActive = false;
                $(this).closest('.rentopian-error-wrapper').fadeOut(300, function() {
                    $(this).remove();
                    self.clearAllFieldErrors();
                });
            });

            // Add error items with click handlers
            errors.forEach(function(error) {
                var $li = $('<li class="rentopian-error-clickable" title="Click to jump to this field"></li>');
                $li.text(error.text);
                $li.on('click', function(e) {
                    e.preventDefault();
                    self.scrollToErrorField(error.text.toLowerCase(), error.$field, error.fieldId);
                });
                error.$li = $li;
                $notice.append($li);
            });

            // Ensure visible
            $notice.css({
                'display': 'block',
                'opacity': '1',
                'visibility': 'visible'
            }).show();

            this.log('Error notice rebuilt with ' + errors.length + ' items');
        },

        /**
         * Scroll to field with 200px offset
         */
        scrollToErrorField: function(errorText, $field, fieldId) {
            var self = this;

            // Re-find the field if the stored reference was detached by an AJAX fragment update
            if ($field && $field.length && !$.contains(document.documentElement, $field[0])) {
                $field = null;
            }

            // Prefer the exact field id (from the server marker) over ambiguous text matching.
            if ((!$field || !$field.length) && fieldId) {
                var $byId = $('#' + fieldId);
                if ($byId.length) {
                    $field = $byId.first();
                }
            }

            if (!$field || !$field.length) {
                var fieldInfo = this.findFieldAndId(errorText);
                $field = fieldInfo.$field;
            }

            if ($field && $field.length) {
                // Find target wrapper - include dynamic field wrapper
                var $target = $field.closest('.form-row, .rentopian-col, .rentopian-field-wrapper, .rentopian-dynamic-field-wrapper');
                if (!$target.length) {
                    $target = $field;
                }

                // Highlight the field
                this.setFieldError($field);

                // Scroll with 200px offset
                $('html, body').animate({
                    scrollTop: $target.offset().top - this.config.scrollOffset
                }, 400, function() {
                    if ($field.is(':visible')) {
                        $field.focus();
                    }
                });

                this.log('Scrolled to field with ' + this.config.scrollOffset + 'px offset');
            }
        },

        /**
         * Set field error state
         */
        setFieldError: function($field) {
            if (!$field || !$field.length) return;

            $field.addClass(this.config.errorFieldClass);
            
            // Find wrapper - check multiple possible wrapper classes
            var $wrapper = $field.closest('.form-row, .rentopian-col, .rentopian-dynamic-field-wrapper');
            if ($wrapper.length) {
                $wrapper.addClass(this.config.errorFieldClass);
                // Also add to the form-row if it exists within the dynamic wrapper
                var $formRow = $wrapper.find('.form-row').first();
                if ($formRow.length) {
                    $formRow.addClass(this.config.errorFieldClass);
                }
            }

            $field.siblings('.select2-container').addClass(this.config.errorFieldClass);
        },

        /**
         * Clear field error state
         */
        clearFieldError: function($field) {
            if (!$field || !$field.length) return;

            $field.removeClass(this.config.errorFieldClass);
            
            // Clear wrapper - check multiple possible wrapper classes
            var $wrapper = $field.closest('.form-row, .rentopian-col, .rentopian-dynamic-field-wrapper');
            if ($wrapper.length) {
                $wrapper.removeClass(this.config.errorFieldClass);
                // Also clear from the form-row if it exists within the dynamic wrapper
                $wrapper.find('.form-row').removeClass(this.config.errorFieldClass);
            }

            $field.siblings('.select2-container').removeClass(this.config.errorFieldClass);
            $field.closest('.form-row').removeClass('woocommerce-invalid woocommerce-invalid-required-field');
        },

        /**
         * Clear all field errors
         */
        clearAllFieldErrors: function() {
            $('.' + this.config.errorFieldClass).removeClass(this.config.errorFieldClass);
            $('.woocommerce-invalid').removeClass('woocommerce-invalid');
            $('.woocommerce-invalid-required-field').removeClass('woocommerce-invalid-required-field');
        },

        /**
         * Re-evaluate a field's tracked errors when the user edits it.
         * Resolved errors are removed from the summary and the field highlight
         * is cleared immediately; emptying a corrected field brings its error
         * back. Never scrolls.
         */
        handleFieldLiveValidation: function($field) {
            var self = this;
            var trackedErrors = this.getTrackedErrorsForField($field);

            if (!trackedErrors.length) {
                this.clearFieldError($field);
                return;
            }

            var changed = false;
            trackedErrors.forEach(function(error) {
                var resolved = self.isErrorResolved(error, $field);
                if (resolved === error.resolved) {
                    return;
                }
                error.resolved = resolved;
                changed = true;

                if (resolved) {
                    self.clearFieldError($field);
                    if (error.$field && error.$field.length) {
                        self.clearFieldError(error.$field);
                    }
                    if (error.$li) {
                        error.$li.stop(true, true).slideUp(150);
                    }
                } else {
                    self.setFieldError($field);
                    if (error.$li) {
                        error.$li.stop(true, true).slideDown(150);
                    }
                }
            });

            if (changed) {
                this.refreshErrorSummaryVisibility();
            }
        },

        /**
         * Find tracked errors belonging to a field (matched by id, name, or element)
         */
        getTrackedErrorsForField: function($field) {
            if (!this.activeErrors.length || !$field || !$field.length) {
                return [];
            }

            var el = $field[0];
            var id = $field.attr('id') || '';
            var name = ($field.attr('name') || '').replace(/\[\]$/, '');

            return this.activeErrors.filter(function(error) {
                if (error.fieldId && (error.fieldId === id || error.fieldId === name)) {
                    return true;
                }
                if (error.$field && error.$field.length) {
                    if (error.$field[0] === el) {
                        return true;
                    }
                    var errorName = (error.$field.attr('name') || '').replace(/\[\]$/, '');
                    if (name !== '' && errorName === name) {
                        return true;
                    }
                }
                return false;
            });
        },

        /**
         * Decide whether a tracked error is resolved by the field's current value.
         * Required errors resolve when the field has a value; email/phone format
         * errors resolve when the value passes the format check. Errors that
         * cannot be safely re-checked client-side wait for server validation.
         */
        isErrorResolved: function(error, $field) {
            var text = error.textLower || error.text.toLowerCase();
            var $el = ($field && $field.length) ? $field : error.$field;

            if (!$el || !$el.length) {
                return false;
            }

            if (text.indexOf('not a valid') !== -1 || text.indexOf('invalid') !== -1) {
                var value = $.trim(String($el.val() || ''));
                if (value === '') {
                    return false;
                }
                if (text.indexOf('email') !== -1 || text.indexOf('e-mail') !== -1) {
                    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value);
                }
                if (text.indexOf('phone') !== -1 || text.indexOf('telephone') !== -1) {
                    // Same allowed characters as WC_Validation::is_phone
                    return /^[\s\#0-9_\-\+\/\(\)\.]+$/.test(value);
                }
                return false;
            }

            return this.fieldHasValue($el);
        },

        /**
         * Check whether a field currently has a non-empty value.
         * Mirrors the server-side empty check ('' and '0' count as empty).
         */
        fieldHasValue: function($field) {
            var type = ($field.attr('type') || '').toLowerCase();

            if (type === 'checkbox') {
                return $field.is(':checked');
            }
            if (type === 'radio') {
                var name = $field.attr('name');
                return $('input[name="' + name + '"]:checked').length > 0;
            }

            var value = $field.val();
            if (value === null || value === undefined) {
                return false;
            }
            if ($.isArray(value)) {
                return value.length > 0;
            }

            value = $.trim(String(value));
            return value !== '' && value !== '0';
        },

        /**
         * Show or hide the error summary based on unresolved tracked errors.
         * The summary disappears when every error is resolved and reappears
         * (without scrolling) when a corrected field becomes invalid again.
         */
        refreshErrorSummaryVisibility: function() {
            var $wrapper = $('.rentopian-error-wrapper');
            if (!$wrapper.length) {
                return;
            }

            var unresolved = 0;
            this.activeErrors.forEach(function(error) {
                if (!error.resolved) {
                    unresolved++;
                }
            });

            $wrapper.stop(true, true);
            if (unresolved === 0) {
                // All errors fixed by the customer — stop re-asserting them.
                this.serverErrorsActive = false;
                $wrapper.fadeOut(250);
            } else if (!$wrapper.is(':visible')) {
                $wrapper.fadeIn(150);
            }

            this.log('Error summary refreshed: ' + unresolved + ' unresolved error(s)');
        },

        /**
         * Re-assert server validation errors after an automatic update_checkout.
         *
         * Errors must remain until the next submit or until the customer edits the
         * offending field. Some update_checkout cycles remove or hide the notice on
         * their own, so we re-check each tracked error against the current field
         * value and restore the notice for any that are still invalid.
         */
        reassertServerErrors: function() {
            var self = this;
            if (!this.serverErrorsActive || this.isSubmitting) {
                return;
            }
            if (!this.activeErrors || !this.activeErrors.length) {
                this.serverErrorsActive = false;
                return;
            }

            var unresolved = [];
            this.activeErrors.forEach(function(error) {
                var $f = (error.$field && error.$field.length && $.contains(document.documentElement, error.$field[0]))
                    ? error.$field
                    : (error.fieldId ? $('#' + error.fieldId) : $());
                error.resolved = ($f && $f.length) ? self.isErrorResolved(error, $f) : false;
                if (!error.resolved) {
                    unresolved.push(error);
                }
            });

            if (!unresolved.length) {
                this.serverErrorsActive = false;
                return;
            }

            var $wrapper = $('.rentopian-error-wrapper');
            if ($wrapper.length) {
                // Notice still present — just make sure it is visible.
                if (!$wrapper.is(':visible')) {
                    $wrapper.stop(true, true).fadeIn(150);
                }
                return;
            }

            // Notice was removed by the re-render — rebuild it from the unresolved errors.
            var $form = $(this.config.selectors.checkoutForm);
            if (!$form.length) {
                return;
            }
            var $notice = $('<ul class="woocommerce-error" role="alert"></ul>').prependTo($form);
            this.activeErrors = unresolved;
            this.rebuildErrorNotice($notice, unresolved);
            unresolved.forEach(function(error) {
                if (error.$field && error.$field.length) {
                    self.setFieldError(error.$field);
                }
            });
            this.log('Re-asserted ' + unresolved.length + ' server error(s) after update_checkout');
        },

        /**
         * Resolve tracked errors for fields inside a container being hidden.
         * Hidden dependent fields are skipped by server validation, so their
         * errors must not linger in the summary.
         */
        resolveTrackedErrorsIn: function($container) {
            var self = this;
            if (!this.activeErrors.length || !$container || !$container.length) {
                return;
            }

            var changed = false;
            $container.find('input, select, textarea').each(function() {
                self.getTrackedErrorsForField($(this)).forEach(function(error) {
                    if (!error.resolved) {
                        error.resolved = true;
                        changed = true;
                        if (error.$li) {
                            error.$li.stop(true, true).slideUp(150);
                        }
                    }
                });
            });

            if (changed) {
                this.refreshErrorSummaryVisibility();
            }
        },

        /**
         * Re-evaluate tracked errors for fields inside a container being shown
         * (their requirements apply again once visible)
         */
        reevaluateTrackedErrorsIn: function($container) {
            var self = this;
            if (!this.activeErrors.length || !$container || !$container.length) {
                return;
            }

            $container.find('input, select, textarea').each(function() {
                self.handleFieldLiveValidation($(this));
            });
        },

        /**
         * Bind dependency events
         */
        bindDependencyEvents: function() {
            var self = this;

            $(this.config.selectors.dependentField).each(function() {
                var $dependent = $(this);
                var parentFieldId = $dependent.data('depends-on');
                
                if (!parentFieldId) return;

                var $parent = self.findParentField(parentFieldId);

                if ($parent.length) {
                    $parent.on('change.rentopianLayout input.rentopianLayout', function() {
                        self.updateDependentVisibility($dependent, $parent);
                    });
                }
            });
        },

        findParentField: function(fieldId) {
            var $field = $('#' + fieldId);
            if ($field.length) return $field;

            $field = $('[name="' + fieldId + '"]');
            if ($field.length) return $field;

            $field = $('#' + fieldId + '-checkbox');
            if ($field.length) return $field;

            $field = $('#' + fieldId + '_field').find('input, select, textarea').first();
            return $field;
        },

        updateDependentVisibility: function($dependent, $parent) {
            var isVisible = this.checkParentValue($parent);

            if (isVisible) {
                this.showDependent($dependent);
            } else {
                this.hideDependent($dependent);
            }
        },

        checkParentValue: function($parent) {
            var type = $parent.attr('type');
            var tagName = $parent.prop('tagName') ? $parent.prop('tagName').toLowerCase() : '';

            if (type === 'checkbox') {
                return $parent.is(':checked');
            }

            if (type === 'radio') {
                var name = $parent.attr('name');
                return $('input[name="' + name + '"]:checked').length > 0;
            }

            if (tagName === 'select') {
                var value = $parent.val();
                return value !== '' && value !== '0' && value !== null;
            }

            var value = $parent.val();
            return value !== '' && value !== null && value !== undefined;
        },

        showDependent: function($dependent) {
            $dependent.slideDown(this.config.animationDuration, function() {
                $dependent.removeClass('rentopian-field-dependency-hidden');
            });

            $dependent.find('input, select, textarea').each(function() {
                var $input = $(this);
                if ($input.data('was-required')) {
                    $input.prop('required', true);
                }
            });

            this.reevaluateTrackedErrorsIn($dependent);
        },

        hideDependent: function($dependent) {
            $dependent.slideUp(this.config.animationDuration, function() {
                $dependent.addClass('rentopian-field-dependency-hidden');
            });

            $dependent.find('input, select, textarea').each(function() {
                var $input = $(this);
                if ($input.prop('required')) {
                    $input.data('was-required', true);
                    $input.prop('required', false);
                }
            });

            this.resolveTrackedErrorsIn($dependent);
        },

        bindShipToDifferentEvents: function() {
            var self = this;
            var $checkbox = $(this.config.selectors.shipToDifferent);

            if (!$checkbox.length) {
                $checkbox = $('#ship-to-different-address-checkbox');
            }

            if (!$checkbox.length) return;

            $checkbox.on('change.rentopianLayout', function() {
                var isChecked = $(this).is(':checked');
                
                if (isChecked) {
                    self.showShippingFields();
                } else {
                    self.hideShippingFields();
                }
            });
        },

        showShippingFields: function() {
            var self = this;

            $('[data-depends-on="ship_to_different_address"]').each(function() {
                $(this).removeClass('rentopian-field-dependency-hidden').slideDown(self.config.animationDuration);
                self.reevaluateTrackedErrorsIn($(this));
            });

            $(this.config.selectors.shippingSection).removeClass('rentopian-section-hidden');
        },

        hideShippingFields: function() {
            var self = this;

            $('[data-depends-on="ship_to_different_address"]').each(function() {
                $(this).addClass('rentopian-field-dependency-hidden').slideUp(self.config.animationDuration);
                self.resolveTrackedErrorsIn($(this));
            });
        },

        setInitialStates: function() {
            var self = this;

            $(this.config.selectors.dependentField).each(function() {
                var $dependent = $(this);
                var parentFieldId = $dependent.data('depends-on');
                var $parent = self.findParentField(parentFieldId);

                // Hidden when the parent control is absent, or present but not active.
                var keepHidden = !$parent.length || !self.checkParentValue($parent);

                if (keepHidden) {
                    $dependent.addClass('rentopian-field-dependency-hidden').hide();

                    $dependent.find('input, select, textarea').each(function() {
                        var $input = $(this);
                        if ($input.prop('required')) {
                            $input.data('was-required', true);
                            $input.prop('required', false);
                        }
                    });
                }
            });

            var $shipCheckbox = $(this.config.selectors.shipToDifferent);
            if (!$shipCheckbox.length) {
                $shipCheckbox = $('#ship-to-different-address-checkbox');
            }

            if ($shipCheckbox.length && !$shipCheckbox.is(':checked')) {
                $('[data-depends-on="ship_to_different_address"]').each(function() {
                    $(this).addClass('rentopian-field-dependency-hidden').hide();
                });
            }
        },

        /**
         * Apply and keep the layout's custom field labels.
         *
         * WooCommerce's address i18n rewrites state/postcode/country labels on load
         * and whenever the country changes, so custom labels must be re-applied after
         * those events as well as on initial render.
         */
        bindLabelOverrides: function() {
            var self = this;

            this.applyLabelOverrides();

            // WC i18n fires this when it swaps state field / relabels per country.
            $(document.body).on('country_to_state_changed', function() {
                setTimeout(function() { self.applyLabelOverrides(); }, 0);
            });

            $(document).on('change', 'form.checkout select#billing_country, form.checkout select#shipping_country', function() {
                setTimeout(function() { self.applyLabelOverrides(); }, 0);
            });
        },

        /**
         * Replace field labels with the configured overrides, preserving the
         * required asterisk. Text is set via a text node (no HTML injection).
         */
        applyLabelOverrides: function() {
            var self = this;
            if (!this.labelOverrides) {
                return;
            }

            $.each(this.labelOverrides, function(fieldId, label) {
                if (!label) {
                    return;
                }
                var $label = $('label[for="' + fieldId + '"]');
                if (!$label.length) {
                    return;
                }

                // Already correct? leave it (avoids needless DOM churn).
                var current = $label.contents().filter(function() {
                    return this.nodeType === 3;
                }).text().replace(/ /g, ' ').replace(/\s+/g, ' ').trim();
                // Keep the required asterisk if the field is required (by current marker,
                // the WC required form-row class, or aria-required on the input).
                var $row = $label.closest('.form-row, .rentopian-col');
                var $input = $('#' + fieldId);
                var hasStar = $label.find('.required, abbr.required').length > 0
                    || $row.hasClass('validate-required')
                    || $input.attr('aria-required') === 'true';
                if (current === label) {
                    return;
                }

                $label.empty();
                $label.append(document.createTextNode(label));
                if (hasStar) {
                    $label.append(document.createTextNode(' '));
                    $label.append('<span class="required" aria-hidden="true">*</span>');
                }
            });
        },

        bindWooCommerceEvents: function() {
            var self = this;

            $(document.body).on('updated_checkout', function() {
                self.refreshDependencyStates();
                self.applyLabelOverrides();
                // An automatic update_checkout can wipe a still-valid error notice;
                // restore it (for fields the customer hasn't fixed yet).
                setTimeout(function() {
                    self.reassertServerErrors();
                }, 200);
            });

            $(document.body).on('checkout_error', function() {
                self.hideLoading();
            });
        },

        refreshDependencyStates: function() {
            var self = this;

            $(this.config.selectors.dependentField).each(function() {
                var $dependent = $(this);
                var parentFieldId = $dependent.data('depends-on');
                var $parent = self.findParentField(parentFieldId);

                if ($parent.length) {
                    self.updateDependentVisibility($dependent, $parent);
                } else {
                    // Parent control not on the form → keep the dependent hidden.
                    self.hideDependent($dependent);
                }
            });
        },

        /**
         * Bind events to save pickup address data to sessionStorage when fields change
         */
        bindPickupAddressPersistence: function() {
            var self = this;
            var pickupFields = [
                '#rental_pick_up_address_1',
                '#rental_pick_up_address_2',
                '#rental_pick_up_city',
                '#rental_pick_up_state',
                '#rental_pick_up_postcode',
                '#rental_pick_up_country'
            ];
            
            // Save pickup data when fields change
            $(document).on('change.rentopianPickup input.rentopianPickup', pickupFields.join(', '), function() {
                self.savePickupAddressData();
            });
            
            // Save when "different pickup address" checkbox changes
            $(document).on('change.rentopianPickup', '#rental_different_pickup_address, [name="rental_different_pickup_address"]', function() {
                var isChecked = $(this).is(':checked');
                try {
                    sessionStorage.setItem('rentopian_pickup_checkbox', isChecked ? '1' : '0');
                } catch (e) {}
                
                if (isChecked) {
                    self.savePickupAddressData();
                }
            });
            
            this.log('Pickup address persistence bound');
        },
        
        /**
         * Save pickup address data to sessionStorage
         */
        savePickupAddressData: function() {
            try {
                var data = {
                    address_1: $('#rental_pick_up_address_1').val() || '',
                    address_2: $('#rental_pick_up_address_2').val() || '',
                    city: $('#rental_pick_up_city').val() || '',
                    state: $('#rental_pick_up_state').val() || '',
                    postcode: $('#rental_pick_up_postcode').val() || '',
                    country: $('#rental_pick_up_country').val() || '',
                    timestamp: Date.now()
                };
                sessionStorage.setItem('rentopian_pickup_address', JSON.stringify(data));
                this.log('Pickup address data saved to sessionStorage');
            } catch (e) {
                this.log('Failed to save pickup address data: ' + e.message);
            }
        },
        
        /**
         * Restore pickup address data from sessionStorage after page load
         */
        restorePickupAddressData: function() {
            var self = this;
            
            try {
                var savedCheckbox = sessionStorage.getItem('rentopian_pickup_checkbox');
                var savedData = sessionStorage.getItem('rentopian_pickup_address');
                
                if (!savedData) {
                    this.log('No saved pickup address data found');
                    return;
                }
                
                var data = JSON.parse(savedData);
                
                // Only restore if data is less than 1 hour old
                var oneHour = 60 * 60 * 1000;
                if (Date.now() - data.timestamp > oneHour) {
                    this.log('Pickup address data expired, clearing');
                    sessionStorage.removeItem('rentopian_pickup_address');
                    sessionStorage.removeItem('rentopian_pickup_checkbox');
                    return;
                }
                
                // Check if the different pickup checkbox should be checked
                var $checkbox = $('#rental_different_pickup_address, [name="rental_different_pickup_address"]').first();
                
                // If checkbox exists and was previously checked, restore it
                if ($checkbox.length && savedCheckbox === '1' && !$checkbox.is(':checked')) {
                    $checkbox.prop('checked', true).trigger('change');
                    this.log('Restored pickup address checkbox state');
                }
                
                // Only restore field values if checkbox is checked and fields exist
                if ($checkbox.length && $checkbox.is(':checked')) {
                    // Wait a bit for dependent fields to become visible
                    setTimeout(function() {
                        if (data.address_1) $('#rental_pick_up_address_1').val(data.address_1);
                        if (data.address_2) $('#rental_pick_up_address_2').val(data.address_2);
                        if (data.city) $('#rental_pick_up_city').val(data.city);
                        if (data.state) $('#rental_pick_up_state').val(data.state).trigger('change');
                        if (data.postcode) $('#rental_pick_up_postcode').val(data.postcode);
                        if (data.country) $('#rental_pick_up_country').val(data.country).trigger('change');
                        
                        self.log('Pickup address data restored from sessionStorage');
                    }, 300);
                }
            } catch (e) {
                this.log('Failed to restore pickup address data: ' + e.message);
            }
        },

        /**
         * Shipping loading indicator - shows spinner on the shipping row
         * while update_checkout recalculates delivery price.
         */
        bindShippingLoadingIndicator: function() {
            var self = this;

            $(document.body).on('update_checkout.rentopianShippingLoading', function() {
                self.showShippingLoading();
            });

            $(document.body).on('updated_checkout.rentopianShippingLoading', function() {
                self.hideShippingLoading();
            });
        },

        showShippingLoading: function() {
            var $shippingRow = $('tr.woocommerce-shipping-totals, tr.shipping, .woocommerce-shipping-totals');
            if (!$shippingRow.length) return;

            $shippingRow.each(function() {
                var $row = $(this);
                // Ensure the row has relative positioning for the overlay
                if ($row.css('position') === 'static') {
                    $row.css('position', 'relative');
                }
                // Reuse existing overlay or create a new one
                var $overlay = $row.find('.rentopian-shipping-loading-overlay');
                if (!$overlay.length) {
                    $overlay = $(
                        '<div class="rentopian-shipping-loading-overlay">' +
                            '<div class="rentopian-shipping-spinner"></div>' +
                            '<span class="rentopian-shipping-loading-text">Calculating...</span>' +
                        '</div>'
                    );
                    $row.append($overlay);
                }
                // Trigger reflow then activate to animate opacity
                $overlay[0].offsetHeight; // force reflow
                $overlay.addClass('is-active');
            });
        },

        hideShippingLoading: function() {
            var $overlays = $('.rentopian-shipping-loading-overlay.is-active');
            $overlays.removeClass('is-active');
            // Remove overlay elements after transition completes
            setTimeout(function() {
                $overlays.remove();
            }, 200);
        },

        log: function() {
            if (this.config.debug && window.console) {
                var args = Array.prototype.slice.call(arguments);
                args.unshift('[RentopianCheckout]');
                console.log.apply(console, args);
            }
        }
    };

    $(document).ready(function() {
        RentopianCheckoutLayout.init();
    });

    window.RentopianCheckoutLayout = RentopianCheckoutLayout;

})(jQuery);
