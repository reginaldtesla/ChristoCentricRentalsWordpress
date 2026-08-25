
function initializeAutocomplete(inputSelector, zipInputSelector, submitSelector) {
    var $input = jQuery(inputSelector);
    if (!$input.length) return;

    var autocomplete = new google.maps.places.Autocomplete(
        $input[0],
        { types: ['geocode'] }
    );

    autocomplete.addListener('place_changed', function () {
        var $zipInput = jQuery(zipInputSelector);
        if ($zipInput.length) {
            fillInZipCode(autocomplete, $zipInput);
        }
        // checkZipCodes();
    });

    if (submitSelector) {
        jQuery(submitSelector).on("click", function () {
            storeMapData(autocomplete);
        });
    }
}

function initAutocompleteDeliveryInputField() {
    initializeAutocomplete('#rental_address', '#rental_zip', '#submit_date');
    initializeAutocomplete('#rental_address_min', '#rental_min_zip_input', '#rental_min_date_form_submit');

    // Initialize other specific autocompletes
    initAutocompleteCheckoutPage();
    // checkZipCodes();
}


function initializeCheckoutAutocomplete(inputSelectorOrElement, type) {
    var $input = (inputSelectorOrElement && inputSelectorOrElement.nodeType)
        ? jQuery(inputSelectorOrElement)
        : jQuery(inputSelectorOrElement);
    if (!$input.length) return;

    var autocomplete = new google.maps.places.Autocomplete(
        $input[0],
        { types: ['geocode'] }
    );

    autocomplete.addListener('place_changed', function () {
        autocompleteCheckoutAddress(autocomplete, type);
    });
}

/**
 * Resolve the billing/shipping address input element that is visible and submitted.
 * When modern checkout is used, prefer the input inside .rentopian-checkout-form.
 */
function getCheckoutAddressInputSelector(fieldId) {
    var $inForm = jQuery('.rentopian-checkout-form [name="' + fieldId + '"]').first();
    if ($inForm.length) return $inForm[0];
    var $byId = jQuery('#' + fieldId).first();
    if ($byId.length) return $byId[0];
    return null;
}

function initAutocompleteCheckoutPage() {
    var billingInput = getCheckoutAddressInputSelector('billing_address_1');
    if (billingInput) {
        initializeCheckoutAutocomplete(billingInput, 'billing');
    } else {
        initializeCheckoutAutocomplete('#billing_address_1', 'billing');
    }

    initializeCheckoutAutocomplete('#shipping_address_1', 'shipping');
    initializeCheckoutAutocomplete('#rental_pick_up_address_1', 'pickup');
    initializeCheckoutAutocomplete('#rntp-wish-location', 'wish');
}

// function initAutocompleteWishlistForm() {
//     initializeCheckoutAutocomplete('#rntp-wish-location', 'wish');
// }

/**
 * Resolve the address field element that is actually submitted (visible in custom layout).
 * When modern checkout is used, duplicate WC fields exist (disabled); we must target
 * the one inside .rentopian-checkout-form so city/state/zip/country are filled correctly.
 */
function getVisibleAddressElement(fieldSelector) {
    if (!fieldSelector) return jQuery();
    var name = String(fieldSelector).replace(/^#/, '');
    var $el = jQuery('.rentopian-checkout-form [name="' + name + '"]').first();
    if (!$el.length) $el = jQuery('.rentopian-checkout-form ' + fieldSelector).first();
    if (!$el.length) $el = jQuery(fieldSelector).first();
    return $el;
}

/**
 * Set address field value - handles both input and select elements
 * Also handles disabled fields (temporarily enables them)
 */
function setAddressField(fieldSelector, value) {
    if (value === undefined || value === null || value === '') {
        return;
    }
    
    var fieldName = String(fieldSelector).replace(/^#/, '');
    
    // Find ALL matching fields (our modern form fields + WC fields)
    var $fields = jQuery();
    
    // 1. Inside modern checkout form (priority)
    var $formField = jQuery('.rentopian-checkout-form').find('[name="' + fieldName + '"], ' + fieldSelector);
    if ($formField.length) {
        $fields = $fields.add($formField);
    }
    
    // 2. Standard selector
    var $stdField = jQuery(fieldSelector);
    if ($stdField.length) {
        $fields = $fields.add($stdField);
    }
    
    // 3. By name attribute
    $fields = $fields.add(jQuery('[name="' + fieldName + '"]'));
    
    $fields.each(function() {
        var $el = jQuery(this);
        var tagName = ($el.prop('tagName') || '').toLowerCase();
        var wasDisabled = $el.prop('disabled');
        
        // Temporarily enable if disabled
        if (wasDisabled) {
            $el.prop('disabled', false);
        }
        
        if (tagName === 'select') {
            // For select elements, try to find matching option
            var $option = $el.find('option[value="' + value + '"]');
            if ($option.length) {
                $el.val(value);
            } else {
                // Try case-insensitive match or by text
                var found = false;
                $el.find('option').each(function() {
                    var optVal = jQuery(this).val();
                    var optText = jQuery(this).text();
                    if (optVal === value || optText === value ||
                        (optVal && optVal.toUpperCase() === value.toUpperCase()) ||
                        (optText && optText.toUpperCase() === value.toUpperCase())) {
                        $el.val(optVal);
                        found = true;
                        return false;
                    }
                });
            }
        } else {
            // For input elements
            $el.val(value);
        }
        
        // Trigger change to update WooCommerce
        $el.trigger('change');
        
        // Keep enabled so value is submitted
        // (Don't re-disable - form serialization needs the value)
    });
}

function extractAddressComponent(components, type) {
    for (let i = 0; i < components.length; i++) {
        if (components[i].types.includes(type)) {

            if (type === 'locality') {
                return components[i]['long_name']
            } else if (type === 'administrative_area_level_1') {
                return components[i]['short_name']
            } else if (type === 'postal_code') {
                return components[i]['short_name']
            } else if (type === 'country') {
                return components[i]['short_name']
            } else if (type === 'route') {
                return components[i]['long_name'] || components[i]['short_name']
            } else if (type === 'street_number') {
                return components[i]['long_name'] || components[i]['short_name']
            } else if (type === 'neighborhood') {
                return components[i]['long_name'] || components[i]['short_name']
            } else if (type === 'colloquial_area') {
                return components[i]['short_name']
            } else if (type === 'subpremise') {
                return components[i]['long_name'] || components[i]['short_name']
            } else if (type === 'administrative_area_level_2') {
                return components[i]['short_name']
            }
            
            // return components[i]['long_name'] || components[i]['short_name'];
        }
    }
    return '';
}

function autocompleteCheckoutAddress(autocomplete, type) {
    const place = autocomplete.getPlace();
    if (!place) return;

    // Autocomplete getPlace() often does NOT return address_components; fetch full details by place_id
    function fillFromPlace(placeWithComponents) {
        if (!placeWithComponents || !placeWithComponents.geometry) return;
        const components = placeWithComponents.address_components;
        if (!components || !components.length) return;

        const lat = placeWithComponents.geometry.location.lat();
        const lng = placeWithComponents.geometry.location.lng();
    const addressSelectors = {
        billing: {
            state: "#billing_state",
            country: "#billing_country",
            postcode: "#billing_postcode",
            address2: "#billing_address_2",
            city: "#billing_city",
            address1: "#billing_address_1",
        },
        shipping: {
            state: "#shipping_state",
            country: "#shipping_country",
            postcode: "#shipping_postcode",
            address2: "#shipping_address_2",
            city: "#shipping_city",
            address1: "#shipping_address_1",
        },
        pickup: {
            state: "#rental_pick_up_state",
            country: "#rental_pick_up_country",
            postcode: "#rental_pick_up_postcode",
            address2: "#rental_pick_up_address_2",
            city: "#rental_pick_up_city",
            address1: "#rental_pick_up_address_1",
        },
        wish: {
            state: "#rntp-wish-state",
            country: "#rntp-wish-country",
            postcode: "#rntp-wish-zip",
            address2: "#rntp-wish-address-2",
            city: "#rntp-wish-city",
            address1: "#rntp-wish-location",
        }
    };

    const selectors = addressSelectors[type];

    // Extract components
    const city = extractAddressComponent(components, 'locality') || 
                extractAddressComponent(components, 'administrative_area_level_2') || 
                extractAddressComponent(components, 'sublocality_level_1');

    const state = extractAddressComponent(components, 'administrative_area_level_1');
    const country = extractAddressComponent(components, 'country');
    const postcode = extractAddressComponent(components, 'postal_code');
    const address2 = extractAddressComponent(components, 'subpremise');

    let address1 = '';
    const route = extractAddressComponent(components, 'route');
    const streetNumber = extractAddressComponent(components, 'street_number');
    const neighborhood = extractAddressComponent(components, 'neighborhood');
    const colloquialArea = extractAddressComponent(components, 'colloquial_area');

    if (route || streetNumber) {
        address1 = `${streetNumber} ${route}`.trim();
    } else if (neighborhood || colloquialArea) {
        address1 = `${neighborhood} ${colloquialArea}`.trim();
    } else if (components.length > 1) {
        address1 = `${components[0].long_name} ${components[1].long_name}`.trim();
    }

    // CRITICAL: Set COUNTRY first, then wait for WooCommerce to update state options
    // Then set state after a delay. The order matters because state options
    // are dynamically populated based on country selection.
    
    // Set country first (this triggers state options to load)
    setAddressField(selectors.country, country);
    
    // Set city and postcode immediately (they don't depend on country)
    setAddressField(selectors.city, city);
    setAddressField(selectors.postcode, postcode);
    setAddressField(selectors.address2, address2);
    setAddressField(selectors.address1, address1);
    
    // Set state AFTER a delay to allow country change to populate state options
    if (state) {
        setTimeout(function() {
            setAddressField(selectors.state, state);
            // Trigger update_checkout after all fields are set
            jQuery(document.body).trigger('update_checkout');
        }, 150);
    } else {
        // Still trigger update_checkout even without state
        jQuery(document.body).trigger('update_checkout');
    }

    var $shipDiff = jQuery('#ship-to-different-address-checkbox');
    if (type === 'pickup') {
        // Store latitude and longitude in cookies
        setCookie("rental_client_pickup_address_lat", lat);
        setCookie("rental_client_pickup_address_lng", lng);

    } else if (type === 'wish') {
        // Store latitude and longitude in cookies
        setCookie("rental_client_wish_address_lat", lat);
        setCookie("rental_client_wish_address_lng", lng);

    } else if (type === 'billing') {

        // Billing address lat/lng - always save to billing-specific cookies
        setCookie("rental_client_billing_address_lat", lat);
        setCookie("rental_client_billing_address_lng", lng);

        // Only update generic coordinates if NOT shipping to different address
        // This ensures delivery calculation uses billing address when checkbox is unchecked
        if (!$shipDiff.length || !$shipDiff.is(':checked')) {
            setCookie("rental_client_address_lat", lat);
            setCookie("rental_client_address_lng", lng);
        }

    } else if (type === 'shipping') {

        // Shipping address lat/lng - always save to shipping-specific cookies
        setCookie("rental_client_shipping_address_lat", lat);
        setCookie("rental_client_shipping_address_lng", lng);

        // Only update generic coordinates if shipping to different address is CHECKED
        // This ensures delivery calculation uses shipping address when checkbox is checked
        if ($shipDiff.length && $shipDiff.is(':checked')) {
            setCookie("rental_client_address_lat", lat);
            setCookie("rental_client_address_lng", lng);
        }
    } else {
        // Fallback for any unexpected type
        setCookie("rental_client_address_lat", lat);
        setCookie("rental_client_address_lng", lng);
    }
    }

    // Use address_components if already present (some environments return them)
    if (place.address_components && place.address_components.length) {
        fillFromPlace(place);
        return;
    }
    // Autocomplete getPlace() often omits address_components; fetch via PlacesService.getDetails
    if (place.place_id && typeof google !== 'undefined' && google.maps && google.maps.places) {
        var service = new google.maps.places.PlacesService(document.createElement('div'));
        service.getDetails(
            { placeId: place.place_id, fields: ['address_components', 'geometry', 'formatted_address'] },
            function(detailPlace, status) {
                if (status === google.maps.places.PlacesServiceStatus.OK && detailPlace) {
                    fillFromPlace(detailPlace);
                }
            }
        );
    }
}

function getPlaceComponent(components, type) {
    for (let i = 0; i < components.length; i++) {
        if (components[i].types.includes(type)) {
            return components[i]['long_name'] || components[i]['short_name'];
        }
    }
    return '';
}

function addToAddressParts(parts, key, value) {
    if (value) {
        parts.push({ key, value });
    }
}

function fillInZipCode(autocomplete, $inputZip) {
    const place = autocomplete.getPlace();
    if (!place || !place.address_components) return;

    const zip = getPlaceComponent(place.address_components, 'postal_code');
    $inputZip.val(zip || '');
}

function storeMapData(autocomplete) {
    const place = autocomplete.getPlace();
    if (!place || !place.geometry || !place.address_components) return;

    const lat = place.geometry.location.lat();
    const lng = place.geometry.location.lng();
    const components = place.address_components;
    const addressParts = [];

    const city = getPlaceComponent(components, 'locality') || 
                getPlaceComponent(components, 'administrative_area_level_2') || 
                getPlaceComponent(components, 'sublocality_level_1');
    const state = getPlaceComponent(components, 'administrative_area_level_1');
    const country = getPlaceComponent(components, 'country');
    const zip = getPlaceComponent(components, 'postal_code');
    const address2 = getPlaceComponent(components, 'subpremise');

    let address = '';
    const route = getPlaceComponent(components, 'route');
    const streetNumber = getPlaceComponent(components, 'street_number');
    const neighborhood = getPlaceComponent(components, 'neighborhood');
    const colloquialArea = getPlaceComponent(components, 'colloquial_area');

    if (route || streetNumber) {
        address = `${streetNumber} ${route}`.trim();
    } else if (neighborhood || colloquialArea) {
        address = `${neighborhood} ${colloquialArea}`.trim();
    } else if (components.length > 1) {
        address = `${components[0].long_name} ${components[1].long_name}`.trim();
    }

    // Add components to address parts
    addToAddressParts(addressParts, 'city', city);
    addToAddressParts(addressParts, 'state', state);
    addToAddressParts(addressParts, 'country', country);
    addToAddressParts(addressParts, 'zip', zip);
    addToAddressParts(addressParts, 'address_2', address2);
    addToAddressParts(addressParts, 'address', address);

    // Store data in cookies
    setCookie("rental_google_map_address", JSON.stringify(addressParts));
    setCookie("rental_client_address_lat", lat);
    setCookie("rental_client_address_lng", lng);
}


function loadGoogleMapsScript(callbackName) {
    const scriptId = "google-maps-api";
    if (document.getElementById(scriptId)) return;

    const script = document.createElement("script");
    script.id = scriptId;
    script.type = "text/javascript";
    script.src = `https://maps.googleapis.com/maps/api/js?key=${rentalGoogleMapConfig.google_map_key}&libraries=places&language=en&callback=${callbackName}&loading=async`;
    script.async = true;
    script.defer = true; // Prevents the script from blocking rendering

    // Handle errors
    script.onerror = () => {
        console.error("Failed to load Google Maps script.");
    };

    document.body.appendChild(script);
}


function isCheckoutPage() {
    return document.querySelector('form.checkout') !== null ||
           document.querySelector('#billing_address_1') !== null ||
           (document.body && document.body.classList.contains('woocommerce-checkout'));
}

function initScriptLoadingBasedOnFormLayout() {
    const { form_layout: formLayout, show_location: showLocation } = rentalGoogleMapConfig;
    const callbackName = showLocation ? "initAutocompleteDeliveryInputField" : "initAutocompleteCheckoutPage";

    function doLoad() {
        if (document.getElementById("google-maps-api")) return;
        loadGoogleMapsScript(callbackName);
    }

    document.addEventListener("DOMContentLoaded", function() {
        // In-cart: load on DOMContentLoaded
        if (formLayout === "in-cart") {
            doLoad();
            return;
        }
        // Checkout page: load immediately so autocomplete is available when user types address
        if (isCheckoutPage()) {
            doLoad();
            return;
        }
        // Other pages (e.g. cart): load when first AJAX completes
        if (typeof jQuery !== 'undefined') {
            jQuery(document).ajaxStop(function() {
                doLoad();
            });
        }
    });
}

initScriptLoadingBasedOnFormLayout();