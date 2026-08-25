jQuery(document).ready(function($) {
    var $dimensionsWeightContainer = $('#variation-dimensions-weight');

    // Function to clear the dimensions and weight display.
    function clearDimensionsWeight() {
        $dimensionsWeightContainer.empty();
    }

    // AJAX function to fetch dimensions and weight based on the given product ID.
    function getDimensionsWeight(productId) {
        return new Promise(function(resolve, reject) {
            $.ajax({
                url: variationData.ajax_url, // Provided via wp_localize_script.
                type: 'POST',
                data: {
                    action: 'get_dimensions_weight',
                    variation_id: productId
                },
                success: function(response) {
                    if (response.success) {
                        resolve(response.data.dimensions_weight_html);
                    } else {
                        reject('Error fetching dimensions and weight');
                    }
                },
                error: function() {
                    reject('AJAX request failed');
                }
            });
        });
    }

    // Listen for variation changes on variable product pages.
    $('form.variations_form').on('show_variation', function(event, variation) {
        if (variation) {
            getDimensionsWeight(variation.variation_id)
                .then(function(html) {
                    $dimensionsWeightContainer.html(html);
                })
                .catch(function(error) {
                    console.error(error);
                    clearDimensionsWeight();
                });
        } else {
            clearDimensionsWeight();
        }
    });

    // For simple products, trigger the AJAX call after the page loads.
    if (!$('form.variations_form').length && variationData.simple_product_id) {
        setTimeout(function() {
            getDimensionsWeight(variationData.simple_product_id)
                .then(function(html) {
                    $dimensionsWeightContainer.html(html);
                })
                .catch(function(error) {
                    console.error(error);
                    clearDimensionsWeight();
                });
        }, 200);
    }

    // Clear dimensions and weight HTML when clicking the reset variations link.
    $(document).on('click', '.reset_variations', function(e) {
        e.preventDefault();
        clearDimensionsWeight();
    });
});
