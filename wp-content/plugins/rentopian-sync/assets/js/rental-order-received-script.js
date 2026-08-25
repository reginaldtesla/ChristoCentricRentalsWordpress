/*--------------------------------------------------------------
Rentopian Sync Order Recieved Scripts
Author: Rentopian
Website: https://rentopian.com
Copyright 2019, Rentopian Inc. All Rights Reserved.
----------------------------------------------------------------*/

jQuery(document).ready(function ($) {

  setTimeout(function () {
      Object.keys(localStorage).forEach(function (key) {

          if (key.startsWith('rental_custom_fields')) {

              localStorage.removeItem(key); // Remove the key from localStorage
              console.log(`Removed localStorage key: ${key}`);
          }
      });
  }, 2000)
});