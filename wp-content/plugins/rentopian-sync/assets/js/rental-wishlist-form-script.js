/*--------------------------------------------------------------
Rentopian Sync Frontend Scripts
Author: Rentopian
Website: https://rentopian.com
Copyright 2019, Rentopian Inc. All Rights Reserved.
----------------------------------------------------------------*/

jQuery(document).ready(function($) {

  function initializeCalentim(selector) {
      var now = moment();
      var config = {
          format: 'MMM D' + (', Y'),
          showTimePickers: false,
          singleDate: true,
          showOn: 'bottom',
          autoAlign: false,
          calendarCount: 1,
          showFooter: false,
          startEmpty: true,
          showButtons: true,
          minDate: now,
          startOnMonday: true
      };

      $(selector).calentim(config);
      return $(selector).data('calentim');
  }

  // loading the date form block
  var wishCalendar;
  var start;
  // var $errorsList = "";
  var $successMsg = $("#rntp-wish-success");
  var $errorsList = $("#rntp-wish-errors ul");
  // $errorsList.hide();
  var $placeholder = $("#rntp-wishlist-form-placeholder");
  if ($placeholder.length > 0) {

      wishCalendar = initializeCalentim("#rntp-wish-date");
      sendFormData(wishCalendar);
    }


    // submit form POST request
    function sendFormData(calendarInstance) {
      var $sbmtBtn = $("#rntp-wish-submit");
      $sbmtBtn.on("click", function(e) {
        e.preventDefault()

        $successMsg.html("").hide();
        var errors = [];
        var name = $("#rntp-wish-name").val();
        var email = $("#rntp-wish-email").val();

        // date
        var date = "";
        var serverDateFormat = 'YYYY/MM/DD';
        // start = wishCalendar.config.startDate;
        start = calendarInstance.config.startDate;
        if (start) {
          date = start.format(serverDateFormat);
        }

        var location = $("#rntp-wish-location").val();
        var address_2 = $("#rntp-wish-address-2").val();
        var state = $("#rntp-wish-state").val();
        var city = $("#rntp-wish-city").val();
        var zip = $("#rntp-wish-zip").val();
        var country = $("#rntp-wish-country").val();

        var phone = $("#rntp-wish-phone").val();
        var guestCount = $("#rntp-wish-guest").val();
        var note = $("#rntp-wish-note").val();
        

        var nameValid = validate(name, "not_numeric");
        if (nameValid !== true) {
          errors.push(validateMsg(nameValid, "Name"))
        }
        var emailValid = validate(email, "email");
        if (emailValid !== true) { 
          errors.push(validateMsg(emailValid, "Email"))
        }
        var dateValid = validate(date);
        if (dateValid !== true) { 
          errors.push(validateMsg("empty_err", "Date"))
        }
        var locationValid = validate(location);
        if (locationValid !== true) { 
          errors.push(validateMsg("empty_err", "Location"))
        }
        // var phoneValid = validate(phone, "numeric", false);
        // if (phoneValid !== true) { 
        //   errors.push(validateMsg(phoneValid, "Phone"))
        // }
        if (guestCount) {
          var guestCountValid = validate(guestCount, "numeric");
          if (guestCountValid !== true) { 
            errors.push(validateMsg(guestCountValid, "Guest Count"))
          }
        }
        
        if (errors.length) {
          $errorsList.html("");
          for(var i=0; i<errors.length; i++) {
            $errorsList.show();
            $errorsList.append('<li>' +errors[i]+ '</li>');
          }
          
        } else {
          $errorsList.html("");
          $errorsList.hide();
          $successMsg.html("").hide();

          // console.log("post data:", name, email, date, location, phone, guestCount )
          block($sbmtBtn);
          $sbmtBtn.attr('disable');
          showProcessingModal()
          
          $.ajax({
            type: 'POST',
            url: rentalObj.url,
            dataType: 'JSON',
            data: {
              action: 'rental_send_wishlist_form',
              name: name,
              email: email,
              date: date,
              location: location,
              address_2: address_2,
              state: state,
              city: city,
              zip: zip,
              country: country,
              phone: phone,
              guest_count: guestCount,
              note: note,
            },
            success: function(data) {

              console.log("success wish data:", data)

              let $wishlist_box = $(".rental-wishlist-box");
              $successMsg.html("Wishlist submitted successfully.").show();
              $wishlist_box.find('input').val('');
              $wishlist_box.find('textarea').val('');

            },
            error: function(data) {

              $successMsg.html("").hide();
              data = data.responseJSON;
              
              if (data !== undefined && data.err !== true) {

                var errors = [];
                if (data.field.trim() !== '') {

                  errors.push(validateMsg(data.err, data.field))
                  
                  if (errors.length) {
                    $errorsList.html("");
                    for(var i=0; i<errors.length; i++) {
                      $errorsList.show();
                      if (errors[i] !== undefined) {
                        $errorsList.append('<li>' +errors[i]+ '</li>');
                      }
                    }
                  }

                } else {

                  $errorsList.show();
                  $errorsList.append('<li>' + data.msg + '</li>');
                }
              
              }

            },
            complete: function() {

              unblock($sbmtBtn);
              $sbmtBtn.removeAttr('disable');

              setTimeout(() => {

                hideProcessingModal()

                deleteCookie("rental_client_wish_address_lat", "/")  
                deleteCookie("rental_client_wish_address_lng", "/")  
              }, 1000)
              
            }
          });

        }
        
      });
    }


});