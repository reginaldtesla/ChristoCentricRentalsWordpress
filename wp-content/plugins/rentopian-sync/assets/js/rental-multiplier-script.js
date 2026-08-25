/*--------------------------------------------------------------
Rentopian Sync Price Multiplier Functionality Scripts
Author: Rentopian
Website: https://rentopian.com
Copyright 2019, Rentopian Inc. All Rights Reserved.
----------------------------------------------------------------*/

jQuery(document).ready(function ($) {

  $(".single_variation_wrap").on("show_variation", function (e, variation) {
    $.ajax({
      type: 'GET',
      url: rentalObj.url,
      dataType: 'JSON',
      data: {
        action: 'rental_get_multiplier',
        product_id: $("form.variations_form").data("product_id"),
        variant_id: variation.variation_id
      },
      success: function(data) {
        $('#rental_multipliers_table').remove();
        if (data.days && variation.display_price) {
          var days = data.days;
          var html = '<table id="rental_multipliers_table" class="rntp-table">' +
            '<thead>' +
            '<tr class="rate-header">' +
            '<th class="period-text">Period</th>' +
            '<th class="rate-text">Rate</th>' +
            '</tr>' +
            '</thead>' +
            '<tbody>';
          for (var i in days) {
            html += '<tr>' +
              '<td class="">' + i + ' Days</td>' +
              '<td class=""><span class="woocommerce-Price-amount amount"><span class="woocommerce-Price-currencySymbol">$</span>' +
              (variation.display_price * days[i]).toFixed(2) +
              '</span></td>' +
              '</tr>';
          }
          html += '</tbody></table>';
          $('.summary.entry-summary').after(html);
        }
      },
      error: function(error) {
        error = error.responseJSON;
        console.log(error);
      }
    });
  } );

});