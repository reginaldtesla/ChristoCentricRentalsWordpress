/*--------------------------------------------------------------
Rentopian Sync Admin Scripts
Author: Rentopian
Website: https://rentopian.com
Copyright 2019, Rentopian Inc. All Rights Reserved.
----------------------------------------------------------------*/

var during = 0;

window.onbeforeunload = function () {
    if (during) {
        return 'Changes You made may not be saved.';
    }
};

// jQuery(window).on("beforeunload", function () {
//     if (during) {
//         return 'Changes You made may not be saved.';
//     }
// });

var months = ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];

function getDate(seconds) {
    var d = new Date(seconds * 1000);
    var hr = d.getHours();
    var ampm = "AM";
    if( hr > 12 ) {
        hr -= 12;
        ampm = "PM";
    }

    return months[d.getMonth()] + ' ' + d.getDate() + ' ' + d.getFullYear() + ', '
        + hr + ':' + d.getMinutes() + ':' + d.getSeconds() + ' ' + ampm;
}

jQuery(document).ready(function ($) {

  // i18n shim: avoids "ReferenceError: __ is not defined"
  var __ = (window.wp && wp.i18n && typeof wp.i18n.__ === 'function')
  ? wp.i18n.__
  : function (s/*, domain*/) { return s; };

  $(document).on('click', '.rental-file-log-delete', function (e) {
    e.preventDefault();
    if (!confirm('Delete this file sync run and all its log entries?')) return;
    var sync_id = $(this).data('sync_id');
    var $btn = $(this);
    $btn.prop('disabled', true);
  
    $.ajax({
        url: rentalObj.url,
        type: 'POST',
        dataType: 'json',
        data: {
            action: 'rental_delete_file_sync',
            nonce: rentalObj.nonce,
            sync_id: sync_id
        },
        success: function (resp) {
            if (resp.success) {
                // remove the LI row
                $btn.closest('li').slideUp();
            } else {
                alert('Delete failed: ' + (resp.message || 'unknown'));
                $btn.prop('disabled', false);
            }
        },
        error: function (xhr) {
            alert('Delete failed (server)');
            $btn.prop('disabled', false);
        }
    });
  });
  

    $('#rental_sync, #rental_confirm_resync').on('click', function (e) {
        e.preventDefault();
        during = 1;
        $('#rental_confirmation_resync').hide(0);
        $('.rental-error-block').hide('fast');
        $('.rental-loader-block').show('fast');
        $('#rental_load').show('slow');
        var api_key = $('#rental_api_key').val();
        $.ajax({
            type: 'POST',
            // url: '/wp-admin/admin-ajax.php',
            url: rentalObj.url,
            data: {
                action: 'rental_sync',
                api_key: api_key
            },
            success: function (data) {
                console.log(data);
                loading(1);
                upload_images(data.start);
            },
            error: function (data) {
                data = data.responseJSON;
                console.log("Error:");
                console.log(data);
                during = 0;
                $('.rental-error-message').text(data.message);
                $('.rental-loader-block').hide('fast');
                $('.rental-error-block').show('fast');
            }
        });
    });

  $('#rental_resync').on('click', function (e) {
    e.preventDefault();
    $('#rental_confirmation_resync').show('slow');
  });

  $('.rental-modal').on('click', '.rental-modal-cancel', function () {
    $(this).parents('.rental-modal').hide('slow');
  });


  $('#rental_resync_files_bg').on('click', function (e) {
    e.preventDefault();

    during = 1;
    
    $('#rental_confirmation_resync').hide(0);
    $('.rental-error-block').hide('fast');
    $('.rental-loader-block').show('fast');
    $('#rental_load').show('slow');
    var api_key = $('#rental_api_key').val();
    $.ajax({
        type: 'POST',
        url: rentalObj.url,
        data: {
            action: 'rental_sync',
            api_key: api_key
        },
        success: function (data) {
            console.log(data);

            loading(100);

            during = 0;
            var rentalNotice = $('.rental-notice').removeClass('notice-warning');
            rentalNotice.removeClass('notice-error');
            rentalNotice.addClass('notice-success');
            rentalNotice.html('<p> Data synchronization is done. </p>');
            rentalNotice.html('<p> Starting the files synchronization process in background. </p>');

            $('.rental-loader-block').hide('fast');
            $('#rental_load').hide('slow');

            // call the wp_ajax_rental_resync_files_bg function to start resync processing file chunks
            // Start background file re-sync (server-driven)
            $.ajax({
                type: 'POST',
                url: rentalObj.url,
                data: {
                    action: 'rental_resync_files_bg',
                    nonce: rentalObj.nonce
                },
                success: function (bgResp) {
                    console.log('Background re-sync start response:', bgResp);

                    rentalNotice.html('<p>Files re-sync scheduled (background). Sync ID: ' + (bgResp.sync_id ? bgResp.sync_id : 'n/a') + '</p>');
                },
                error: function (err) {
                    console.error('Failed to start background re-sync', err);

                    rentalNotice.addClass('notice-error');
                    rentalNotice.html('<p>Failed to start file synchronization. Check logs.</p>');
                }
            });

        },
        error: function (data) {
            data = data.responseJSON;
            console.log("Error:");
            console.log(data);
            during = 0;
            $('.rental-error-message').text(data.message);
            $('.rental-loader-block').hide('fast');
            $('.rental-error-block').show('fast');
        }
    });
});



  // Cancel background sync button
  $('#rental_sync_background_cancel').on('click', function (e) {
    e.preventDefault();

    var $btn = $(this);
    $btn.prop('disabled', true);

    $.ajax({
        url: rentalObj.url,
        type: 'POST',
        dataType: 'json',
        data: {
            action: 'rental_cancel_sync_bg',
            nonce: rentalObj.nonce
        },
        success: function (resp) {
            console.log('rental_cancel_sync_bg response:', resp);

            var rentalNotice = $('.rental-notice').removeClass('notice-success notice-warning notice-error');

            if (resp && resp.success) {
                rentalNotice.addClass('notice-success');
                rentalNotice.html('<p>Background synchronization canceled successfully. Sync ID: ' + (resp.sync_id || 'n/a') + '</p>');

                setTimeout(() => {
                  $btn.prop('disabled', false);
                }, 500);
            } else {
                rentalNotice.addClass('notice-error');
                rentalNotice.html('<p>Cancel failed: ' + (resp.message || 'unknown') + '</p>');
            }

        },
        error: function (xhr) {
            console.error('Cancel failed', xhr);

            var rentalNotice = $('.rental-notice').removeClass('notice-success').addClass('notice-error');
            var msg = 'Failed to cancel background sync (network or server error).';
            try {
                var data = xhr.responseJSON || {};
                if (data.message) msg += ' ' + data.message;
            } catch (e) {}
            rentalNotice.html('<p>' + msg + '</p>');

            $btn.prop('disabled', false);
        }
    });
  });

  $('#rental_sync_background_cancel_all').on('click', function (e) {
    e.preventDefault();
    const $btn = $(this);
    $btn.prop('disabled', true);
  
    $.ajax({
      url: rentalObj.url,
      type: 'POST',
      dataType: 'json',
      data: { action: 'rental_cancel_all_sync_bg', nonce: rentalObj.nonce },
      success: function (resp) {
        console.log('rental_cancel_all_sync_bg response:', resp);
  
        var $notice = $('.rental-notice').removeClass('notice-success notice-warning notice-error');
  
        if (resp && resp.success) {
          $notice.addClass('notice-success');
          $notice.html(
            '<p>All background file syncs canceled.</p>' +
            '<p>Sessions updated: ' + resp.sessions_updated + '</p>' +
            '<p>Locks cleared: ' + resp.locks_cleared + '</p>' +
            '<p>Laravel cancels ok: ' + resp.remote_cancel_ok + ', failed: ' + resp.remote_cancel_failed + '</p>'
          );
        } else {
          $notice.addClass('notice-error');
          $notice.html('<p>Cancel-all failed: ' + (resp && resp.message ? resp.message : 'unknown') + '</p>');
        }
  
        setTimeout(() => $btn.prop('disabled', false), 600);
      },
      error: function (xhr) {
        console.error('Cancel-all failed', xhr);
        var $notice = $('.rental-notice').removeClass('notice-success').addClass('notice-error');
        var msg = 'Failed to cancel all background syncs (network or server error).';
        try {
          var data = xhr.responseJSON || {};
          if (data.message) msg += ' ' + data.message;
        } catch (e) {}
        $notice.html('<p>' + msg + '</p>');
        $btn.prop('disabled', false);
      }
    });
  });
  

  // Retry failed images handler (on sync page)
  $('#rental_retry_failed').on('click', function (e) {
      e.preventDefault();
      var $btn = $(this).prop('disabled', true).text('Retrying...');
      var syncId = $('#rental_current_sync_id').val() || rentalObj.current_sync_id;
      $.ajax({
          method: 'POST',
          url: rentalObj.url,
          data: {
              action: 'rental_retry_failed_images',
              nonce: rentalObj.nonce,
              sync_id: syncId,
              limit: 1
          },
          success: function (resp) {

              if (resp.success) {

                  var data = resp.data;
                  // show a message and refresh page summary via AJAX if needed
                  alert('Retry done. Succeeded: '+ (data.succeeded_ids ? data.succeeded_ids.length : 0) + ', Remaining failed: ' + (data.failed_ids ? data.failed_ids.length : 0));
                  location.reload();
              } else {
                  alert('Error: ' + (resp.data && resp.data.message ? resp.data.message : 'Unknown'));
              }
          },
          error: function () {
              alert('Server error while retrying failed images');
          },
          complete: function () {
              $btn.prop('disabled', false).text('Retry failed images');
          }
      });
  });


  // Retry failed images handler (works for any retry button rendered per-run on logs page)
  $(document).on('click', '.rental-retry-failed', function (e) {
    e.preventDefault();

    var $btn = $(this);
    var sync_id = $btn.data('sync_id');
    if (!sync_id) {
      alert('No sync id found.');
      return;
    }

    $btn.prop('disabled', true).text('Retrying...');

    var limit = 2;

    $.ajax({
      url: rentalObj.url,
      type: 'POST',
      dataType: 'json',
      data: {
        action: 'rental_retry_failed_images',
        nonce: rentalObj.nonce,
        sync_id: sync_id,
        limit: limit
      },
      success: function (resp) {
        // resp is what your wp_ajax_rental_retry_failed_images returns (wp_send_json_success($result))
        if (!resp || !resp.success) {
          alert('Retry failed: ' + (resp && resp.data && resp.data.message ? resp.data.message : 'Unknown error'));
          $btn.prop('disabled', false).text('Retry Failed Images');
          return;
        }

        var result = resp.data || resp; // depending on WP/AJAX wrappers
        // result expected: { succeeded_ids:[], failed_ids:[], processed_count: n, ... }

        // update DOM counts using li data attributes
        var $li = $btn.closest('li');
        var processed = parseInt($li.attr('data-processed') || 0, 10);
        var total = parseInt($li.attr('data-total') || 0, 10);

        // processed_count returned is how many succeeded in this call
        var processed_this_call = parseInt(result.processed_count || 0, 10);

        // update processed
        processed = processed + processed_this_call;
        $li.attr('data-processed', processed);
        $li.find('.rental-processed-count').text(processed);
        $li.find('.rental-total-count').text(total);

        // update sessions failed state & button visibility
        var remaining_failed = Array.isArray(result.failed_ids) ? result.failed_ids.length : 0;

        if (remaining_failed <= 0) {
          // no failed ids remaining — hide or disable button
          $btn.replaceWith('<button class="btn btn-success rental-retry-failed-done" disabled>All retried</button>');
        } else {
          // still some failed — re-enable button and show remaining count
          $btn.prop('disabled', false);
          $btn.html('<span class="dashicons dashicons-image-rotate"></span> Retry Failed Images (' + remaining_failed + ')');
        }

        // refresh details table if open
        var $details = $li.find('.rental-log-details');
        if ($details.is(':visible')) {
          // trigger details refresh by clicking details button programmatically
          $li.find('.rental-file-log-details').trigger('click');
        }

      },
      error: function (xhr) {
        var msg = 'Server error while retrying failed images.';
        try {
          var json = JSON.parse(xhr.responseText);
          msg = json && json.data && json.data.message ? json.data.message : msg;
        } catch (ex) {}
        alert(msg);
        $btn.prop('disabled', false).text('Retry Failed Images');
      }
    });

  });




  var offset = 0;
  var processing = false;

  function processBatch() {
      if (processing) return; // Prevent starting a new batch while one is in progress
      processing = true;

      during = 1
      $('.rental-loader-block').show('fast');
      $('#rental_load').show('slow');

      $.ajax({
          url: rentalObj.url,
          type: 'POST',
          data: {
              action: 'rental_sync_yoast_seo_plugin_in_chunks',
              offset: offset
          },
          success: function(response) {

              if (response.next_offset !== null) {

                  offset = response.next_offset;
                  loading(response.progress_percent); 
                  console.log(response.message + ': Processed ' + offset + ' of ' + response.total_items);

                  processing = false;
                  processBatch(); // Process the next batch

              } else if (response.next_offset === null) {

                  loading(100);
                  console.log(response.message);
                  processing = false; // All batches processed, stop processing

                  setTimeout(() => {
                    location.reload()  
                  }, 1000);
                  return;

              } else {
                
                  loading(100);
                  console.error('Error: ' + response.message);
                  processing = false;

                  $('#rental_sync_yoast').attr('disabled', false)
                  return;
              }
          },
          error: function(xhr, status, error) {
              console.error('Error processing batch: ' + error);
              console.log("xhr : ", xhr)
              processing = false; // Reset processing flag in case of error to allow retry

              $('#rental_sync_yoast').attr('disabled', false)
          }
      });
  }

  $('#rental_sync_yoast').on('click', function(e) {
      let $this = $(this)
      e.preventDefault();

      if (!processing) { // Start processing only if not already processing
          offset = 0; // Reset offset in case of multiple runs
          $this.attr('disabled', true)
          processBatch();
      }
  });
  

  $('#rental_sync_files_manual').on('click', function (e) {
    e.preventDefault();
    upload_images_manual($(this).data('index'));
  });
  
    function upload_images_manual(start) {
        during = 1;
        $('.rental-loader-block').show('fast');
        $('#rental_load').show('slow');
        $.ajax({
            type: 'GET',
            url: rentalObj.url,
            data: {
                action: 'rental_upload_images_manual',
                start: start
            },
            success: function (data) {
                if (data.start) {
                  $('#rental_sync_files_manual').attr('data-index', data.start);
                }
                console.log(data);
                loading(100);
                during = 0;
                var rentalNotice = $('.rental-notice').removeClass('notice-warning');
                rentalNotice.removeClass('notice-error');
                rentalNotice.addClass('notice-success');
                rentalNotice.html('<p>' + data.message + '</p>');
                setTimeout(() => {
                  location.reload();
                }, 1000);
            },
            error: function (data) {
                console.log(data)
            }
        });
    }

    function upload_images(start) {
        $.ajax({
            type: 'GET',
            url: rentalObj.url,
            data: {
                action: 'rental_upload_images',
                start: start
            },
            success: function (data) {
                console.log(data);
                var rentalNotice = $('.rental-notice').removeClass('notice-warning');
                if (data.sync_files_manual !== undefined && data.sync_files_manual === 1) {
                  loading(100);
                  during = 0;
                  rentalNotice.addClass('notice-success');
                  rentalNotice.html('<p>' + data.message + '</p>');
                  rentalNotice.html('<p> Please continue to manual file synchronization. </p>');
                  setTimeout(() => {
                    location.reload();
                  }, 1000);
                  return;
                }
                if (data.start) {
                    loading(data.percent);
                    upload_images(data.start)
                } else {
                    loading(100);
                    during = 0;
                    $('#rental_sync').prop('disabled', true);
                    $('#rental_api_key').prop('disabled', true);
                    if (data.completed) {
                        rentalNotice.removeClass('notice-error');
                        rentalNotice.addClass('notice-success');
                    } else {
                        rentalNotice.removeClass('notice-success');
                        rentalNotice.addClass('notice-error');
                    }
                    
                    if (data.show_duration) {
                      rentalNotice.html('<p>' + data.message + '</p> <strong>(File Synchronization took : '+ data.show_duration +' to complete.)</strong>');

                      $(".rntp-sync-panel").find(".panel-footer .submit strong").html('(File Synchronization took : '+ data.show_duration +' to complete.)');

                    } else {
                      rentalNotice.html('<p>' + data.message + '</p>');
                    }

                }
            },
            error: function (data) {
                console.log(data)
            }
        });
    }

    function loading(percent) {
        percent += '%';
        $('.rental-loader-progress').animate({
            width: percent
        });
        $('.rental-loader-percent').html(percent);

        if (percent === '100%') {
            $('#rental_load').hide('slow');
        }
    }

    $('.rental-loading-finish').on('click', function () {
        $('#rental_load').hide('slow');
    });

    $('.rental-btn-details').on('click', function () {
        var parent = $(this).parents('.rental-log-item');
        parent.find('.rental-log-details').toggle('slow');
    });


    $('input[name="sync_type"]').on('change', function () {
      const syncButton = $('#rental_sync');
      const resyncButton = $('#rental_resync');
  
      // Disable buttons
      syncButton.prop('disabled', true);
      syncButton.addClass('disabled');
      resyncButton.prop('disabled', true);
      resyncButton.addClass('disabled');

      let $ajaxMsgBox = $(".ajax-alert");
      $ajaxMsgBox.find("strong").html('');
      $ajaxMsgBox.removeClass("success");
      $ajaxMsgBox.removeClass("danger");
      $ajaxMsgBox.find("strong").html('');

      $.ajax({
        url: rentalObj.url,
        method: 'POST',
        data: {
            action: 'rental_change_file_sync_type',
            file_sync_type: $(this).val()
        },
        success: function (response) {
          
          console.log('Sync type updated successfully. Files clean up report : ', response);

          $ajaxMsgBox.find("strong").append("File Sync type settings saved successfully.");
          $ajaxMsgBox.addClass("success");
          $ajaxMsgBox.fadeIn();
          setTimeout(() => {
            $ajaxMsgBox.fadeOut();
          }, 2500);

        },
        error: function () {

          console.log('An error occurred while updating the sync type.');

          $ajaxMsgBox.find("strong").append("Operation failed!");
          $ajaxMsgBox.addClass("danger");
          $ajaxMsgBox.fadeIn();
          setTimeout(() => {
            $ajaxMsgBox.fadeOut();
          }, 2500);

        },
        complete: function() {

          // Re-enable buttons
          syncButton.prop('disabled', false);
          resyncButton.prop('disabled', false);
          syncButton.removeClass('disabled');
          resyncButton.removeClass('disabled');
        }
      });

    });


    $(document).on('click', '#rental_remove_orphaned_files', function (e) {
      e.preventDefault();
      
      showProcessingModal();

      const syncButton = $('#rental_sync');
      const resyncButton = $('#rental_resync');
  
      // Disable buttons
      syncButton.prop('disabled', true);
      syncButton.addClass('disabled');
      resyncButton.prop('disabled', true);
      resyncButton.addClass('disabled');

      let $ajaxMsgBox = $(".ajax-alert");
      $ajaxMsgBox.find("strong").html('');
      $ajaxMsgBox.removeClass("success");
      $ajaxMsgBox.removeClass("danger");
      $ajaxMsgBox.find("strong").html('');

      $.ajax({
        url: rentalObj.url,
        method: 'POST',
        data: {
            action: 'rental_delete_orphaned_files'
        },
        success: function (response) {
          
          console.log('Deleted files data : ', response);

          let filesDeletedCount = response.count || 0;
          $ajaxMsgBox.find("strong").append(filesDeletedCount + " Files Deleted.");
          $ajaxMsgBox.addClass("success");
          $ajaxMsgBox.fadeIn();
          setTimeout(() => {
            $ajaxMsgBox.fadeOut();
          }, 5000);

        },
        error: function () {

          console.log('An error occurred while deleting orphaned files.');

          $ajaxMsgBox.find("strong").append("Operation failed!");
          $ajaxMsgBox.addClass("danger");
          $ajaxMsgBox.fadeIn();
          setTimeout(() => {
            $ajaxMsgBox.fadeOut();
          }, 2500);

        },
        complete: function() {

          // Re-enable buttons
          syncButton.prop('disabled', false);
          resyncButton.prop('disabled', false);
          syncButton.removeClass('disabled');
          resyncButton.removeClass('disabled');

          setTimeout(() => {
            hideProcessingModal();
          }, 300);

        }
      });

    });


    $(document).on('click', '#rental_remove_all_product_files', function (e) {
      e.preventDefault();
      
      showProcessingModal();

      const syncButton = $('#rental_sync');
      const resyncButton = $('#rental_resync');
  
      // Disable buttons
      syncButton.prop('disabled', true);
      syncButton.addClass('disabled');
      resyncButton.prop('disabled', true);
      resyncButton.addClass('disabled');

      let $ajaxMsgBox = $(".ajax-alert");
      $ajaxMsgBox.find("strong").html('');
      $ajaxMsgBox.removeClass("success");
      $ajaxMsgBox.removeClass("danger");
      $ajaxMsgBox.find("strong").html('');

      $.ajax({
        url: rentalObj.url,
        method: 'POST',
        data: {
            action: 'rental_delete_all_product_files'
        },
        success: function (response) {
          
          console.log('Deleted files data : ', response);

          let filesDeletedCount = response.count || 0;
          $ajaxMsgBox.find("strong").append(filesDeletedCount + " Files Deleted.");
          $ajaxMsgBox.addClass("success");
          $ajaxMsgBox.fadeIn();
          setTimeout(() => {
            $ajaxMsgBox.fadeOut();
          }, 5000);

        },
        error: function () {

          console.log('An error occurred while deleting orphaned files.');

          $ajaxMsgBox.find("strong").append("Operation failed!");
          $ajaxMsgBox.addClass("danger");
          $ajaxMsgBox.fadeIn();
          setTimeout(() => {
            $ajaxMsgBox.fadeOut();
          }, 2500);

        },
        complete: function() {

          // Re-enable buttons
          syncButton.prop('disabled', false);
          resyncButton.prop('disabled', false);
          syncButton.removeClass('disabled');
          resyncButton.removeClass('disabled');

          setTimeout(() => {
            hideProcessingModal();
          }, 300);

        }
      });

    });


  if ($('.rental-sync-log').length) {
    $('.rental-btn-delete').on('click', function () {
      var li = $(this).parents('li');
      console.log(li.data('sync_time'));
      $.ajax({
        type: 'POST',
        url: rentalObj.url,
        data: {
          action: 'rental_delete_log_item',
          sync_time: li.data('sync_time')
        },
        success: function () {
          li.remove();
        },
        error: function (data) {
          data = data.responseJSON;
          console.log(data);
          alert(data.message);
        }
      });
    });

    $('.rental-btn-try-again').on('click', function () {
      var $this = $(this);
      var $tr = $this.parents('tr');
      $.ajax({
        type: 'GET',
        url: rentalObj.url,
        data: {
          action: 'rental_upload_images_try_again',
          id: $tr.data('id')
        },
        success: function (data) {
          console.log(data);
          $tr.find('td:nth-of-type(1)').html(data.status);
          $tr.find('td:nth-of-type(4)').html(data.message);
        },
        error: function (data) {
          data = data.responseJSON;
          console.log(data);
          alert(data.message);
        }
      });
    });
  }

  var $runtimeLogPanel = $('#rental_runtime_log_panel');
  var $ordersLogPanel = $('#rental_orders_log_panel');
  if ($runtimeLogPanel.length || $ordersLogPanel.length) {

    function initPagination($panel, renderPage) {
      var pageNumber = 1;
      var pageCount;
      getPage();

      function getPage() {
        if (!rentalObj) {
          return;
        }
        $.ajax({
          type: 'GET',
          url: rentalObj.url,
          data: {
            action: $panel.data('action'),
            page: pageNumber
          },
          success: function (data) {
            pageCount = data.count;
            if (typeof renderPage === 'function') {
              renderPage(data.log);
            }
            renderPagination();
          },
          error: function (error) {
            // console.log(error.responseText);
          }
        });
      }


      function renderPagination() {
          var html = '';
          if (pageCount) {
              if (pageNumber === 1) {
                  html = '<a class="active" href="#">1</a>';
                  if (pageCount > 1) {
                      html += '<a href="#">2</a>' +
                              '.. <a class="last" href="#">&raquo;</a>';
                  }
              } else if (pageNumber === pageCount) {
                  html = '<a class="first" href="#">&laquo;</a> ..' +
                        '<a href="#">' + (pageCount - 1) + '</a>' +
                        '<a class="active" href="#">' + pageCount + '</a>';
              } else {
                  html = '<a class="first" href="#">&laquo;</a> ..' +
                        '<a href="#">' + (pageNumber - 1) + '</a>' +
                        '<a class="active" href="#">' + pageNumber + '</a>' +
                        '<a href="#">' + (pageNumber + 1) + '</a>' +
                        '.. <a class="last" href="#">&raquo;</a>';
              }
          }
          $panel.find('.rental-pagination').html(html);
      }
    
      $panel.find('.rental-pagination').on('click', 'a', function (e) {
        e.preventDefault();
        var $this = $(this);
        if ($this.hasClass('last')) {
          pageNumber = pageCount;
        } else if ($this.hasClass('first')) {
          pageNumber = 1;
        } else {
          pageNumber = parseInt($this.text());
        }
        getPage();
      });
    }

    var deleteRuntimeLogBtn = $('#rental_delete_runtime_log').on('click', function () {
      var $this = $(this);
      $.ajax({
        type: 'POST',
        url: rentalObj.url,
        data: {
          action: 'rental_delete_runtime_log',
          delete: true
        },
        success: function () {
          $runtimeLogPanel.find('#rental_runtime_log_table tbody').remove();
          $runtimeLogPanel.find('.rental-pagination').remove();
          $this.prop('disabled', true);
        },
        error: function (data) {
          data = data.responseJSON;
          console.log(data);
          alert(data.message);
        }
      });
    });

    initPagination($runtimeLogPanel, function (log) {
      var html = '';
      var len = log.length;
      if (len) {
        deleteRuntimeLogBtn.prop('disabled', false);
        for (var i = 0; i < len; i++) {
          html +=
            '<tr>' +
            '<td>' + log[i].status + '</td>' +
            '<td>' + log[i].file + '</td>' +
            '<td>' + log[i].line + '</td>' +
            '<td>' + log[i].message + '</td>' +
            '<td>' + getDate(log[i].register_time) + '</td>' +
            '</tr>';
        }
      }
      $runtimeLogPanel.find('table > tbody').html(html);
    });

    $ordersLogPanel.on('click', '.rental-resend-order', function () {
      var id = this.dataset.id;
      var $tr = $(this).parents('tr');
      $tr.html('<td colspan="5"><span class="rental-notice rental-notice-success">Processing...</span></td>');
      $.ajax({
        type: 'POST',
        url: rentalObj.url,
        data: {
          action: 'rental_resend_order',
          order_id: id
        },
        success: function (data) {
          var log = data.log;
          if (log.rental_id) {
            $tr.html('<td colspan="5"><span class="rental-notice rental-notice-success">The order successfully registered!</span></td>')
            $tr.hide(3500);
          } else {
            $tr.html(
              '<td>' + id + '</td>' +
              '<td>' + log.http_code + '</td>' +
              '<td>' + log.message + '</td>' +
              '<td>' + getDate(log.register_time) + '</td>' +
              '<td><button class="btn btn-inline-success rental-resend-order" data-id="' + id + '">Resend Order</button></td>'
            );
          }
        },
        error: function (data) {
          data = data.responseJSON;
          console.log(data);
          $tr.html('<td colspan="5"><span class="rental-notice rental-notice-danger">' + data.message + '</span></td>');
        }
      });
    });

    initPagination($ordersLogPanel, function (log) {
      var html = '';
      var len = log.length;
      if (len) {
        deleteRuntimeLogBtn.prop('disabled', false);
        for (var i = 0; i < len; i++) {
          html +=
            '<tr>' +
            '<td>' + log[i].id + '</td>' +
            '<td>' + log[i].http_code + '</td>' +
            '<td>' + log[i].message + '</td>' +
            '<td>' + getDate(log[i].register_time) + '</td>' +
            '<td><button class="btn btn-inline-success rental-resend-order" data-id="' + log[i].id + '">Resend Order</button></td>' +
            '</tr>';
        }
      }
      $ordersLogPanel.find('table > tbody').html(html);
    });
  }


  // --- Utility pagination function ---
  function initPanelPagination($panel, renderPage) {
    var pageNumber = 1;
    var pageCount = 0;
    var perPage = 10; // default

    function getPage() {
      if (!rentalObj) return;
      $.ajax({
        type: 'GET',
        url: rentalObj.url,
        data: {
          action: $panel.data('action'),
          page: pageNumber,
          per_page: perPage
        },
        success: function (data) {
          if (!data || !data.success) return;
          pageCount = data.total_pages || 1;
          if (typeof renderPage === 'function') {
            renderPage(data);
          }
          renderPagination();
        },
        error: function (err) {
          console.error('Pagination error', err);
        }
      });
    }

    function renderPagination() {
      var html = '';
      if (pageCount) {
        if (pageNumber === 1) {
          html = '<a class="active" href="#">1</a>';
          if (pageCount >= 2) {
            html += '<a href="#">2</a>' +
              '.. <a class="last" href="#">&raquo;</a>';
          }
        } else if (pageNumber === pageCount) {
          html = '<a class="first" href="#">&laquo;</a> ..' +
            '<a href="#">' + (pageCount - 1) + '</a>' +
            '<a class="active" href="#">' + pageCount + '</a>';
        } else {
          html = '<a class="first" href="#">&laquo;</a> ..' +
            '<a href="#">' + (pageNumber - 1) + '</a>' +
            '<a class="active" href="#">' + pageNumber + '</a>' +
            '<a href="#">' + (pageNumber + 1) + '</a>' +
            '.. <a class="last" href="#">&raquo;</a>';
        }
      }
      $panel.find('.rental-pagination').html(html);
    }

    $panel.find('.rental-pagination').on('click', 'a', function (e) {
      e.preventDefault();
      var $this = $(this);
      if ($this.hasClass('last')) {
        pageNumber = pageCount;
      } else if ($this.hasClass('first')) {
        pageNumber = 1;
      } else {
        pageNumber = parseInt($this.text());
      }
      getPage();
    });

    // expose refresh
    return { getPage: getPage };
  }

  // --- Render grouped runs into <ul> ---
  var $fileSyncPanel = $('#rental_file_sync_log_panel');
  if ($fileSyncPanel.length) {
      var renderRuns = function(data) {
        var groups = data.groups || [];
        var $ul = $fileSyncPanel.find('ul').first();
        $ul.empty();
        
        groups.forEach(function(g) {
            var sync_id = g.sync_id;
            var mode = g.mode || 1;
            var started = new Date((g.started_at || 0) * 1000);
            var last = new Date((g.last_at || 0) * 1000);
            var processed = g.processed_count || 0;
            var total = g.total_count || 0;
    
            var retryBtnHtml =
              '<button type="button"' +
                ' id="rental_retry_failed_' + sync_id + '"' +
                ' data-sync_id="' + sync_id + '"' +
                ' data-mode="' + mode + '"' +
                ' class="btn btn-warning rental-retry-failed">' +
                '<span class="dashicons dashicons-image-rotate"></span> ' +
                __('Retry Failed Images', 'rentopian-sync') +
              '</button>';
      
            // If backend returns a has_failed boolean, respect it; otherwise include the button (server will no-op if none).
            if (typeof g.has_failed !== 'undefined' && !g.has_failed) {
              retryBtnHtml = '';
            }

            let modeTextual = mode == 1 ? 'SYNC' : "RE-SYNC";
            
            var $li = $(
              '<li data-sync_id="' + sync_id + '"' +
                  ' data-mode="' + mode + '"' +
                  ' data-processed="' + processed + '"' +
                  ' data-total="' + total + '"' +
                  ' data-started_at="' + (g.started_at || 0) + '"' +
                  ' data-last_at="' + (g.last_at || 0) + '">' +
      
                '<div class="rental-log-item">' +
                  '<div class="rental-log-title">' +
                    '<h4>' + __('File Sync Run:', 'rentopian-sync') + ' ' + sync_id + '</h4>' +
                    '<p>' +
                      '<strong>' + __('Mode:', 'rentopian-sync') + '</strong> ' + modeTextual + ' &nbsp;' +
                      '<strong>' + __('Started:', 'rentopian-sync') + '</strong> ' + started.toLocaleString() + ' &nbsp;' +
                      '<strong>' + __('Last update:', 'rentopian-sync') + '</strong> ' + last.toLocaleString() + ' &nbsp;' +
                      '<strong>' + __('Processed:', 'rentopian-sync') + '</strong> ' +
                      '<span class="rental-processed-count">' + processed + '</span> / ' +
                      '<span class="rental-total-count">' + total + '</span>' +
                    '</p>' +
      
                    '<button class="btn btn-icon-fixed btn-inline-danger rental-file-log-delete" data-sync_id="' + sync_id + '">' +
                      '<span class="dashicons dashicons-trash"></span> ' +
                      __('Delete', 'rentopian-sync') +
                    '</button>' +
      
                    '<button class="btn btn-icon-fixed btn-inline-info rental-file-log-details" data-sync_id="' + sync_id + '">' +
                      '<span class="dashicons dashicons-visibility"></span> ' +
                      __('Details', 'rentopian-sync') +
                    '</button>' +
      
                    retryBtnHtml +
      
                    '<div class="clear-both"></div>' +
                  '</div>' +
      
                  '<div class="rental-log-details" style="display:none">' +
                    '<table class="table table-bordered rental-log-table">' +
                      '<thead><tr>' +
                        '<th>' + __('Level', 'rentopian-sync') + '</th>' +
                        '<th>' + __('Message', 'rentopian-sync') + '</th>' +
                        '<th>' + __('Data', 'rentopian-sync') + '</th>' +
                        '<th>' + __('Processed', 'rentopian-sync') + '</th>' +
                        '<th>' + __('Time', 'rentopian-sync') + '</th>' +
                      '</tr></thead>' +
                      '<tbody data-sync_id="' + sync_id + '"></tbody>' +
                    '</table>' +
                    '<div class="rental-details-pagination" style="margin-top:8px"></div>' +
                  '</div>' +
                '</div>' +
              '</li>'
            );
    
            $ul.append($li);
        });
      };
  

    // attach pagination and kick off
    var pager = initPanelPagination($fileSyncPanel, function(data) {
      renderRuns(data);
    });

    // initial fetch (server already rendered page 1, but we call to keep JS-driven nav consistent)
    pager.getPage();
  }


  // File Sync Log's each record's DETAILS pagination 
  $(document).on('click', '.rental-file-log-details', function (e) {
    e.preventDefault();

    var $btn = $(this);
    var sync_id = $btn.data('sync_id');
    var $li = $btn.closest('li');
    var $details = $li.find('.rental-log-details');
    var $tbody = $details.find('tbody');
    var $pager = $details.find('.rental-details-pagination');

    if ($details.is(':visible')) {
      $details.hide();
      return;
    }

    // local pagination state
    var page = 1;
    var per_page = 10;

    function loadPage(p) {
      p = p || 1;
      $tbody.html('<tr><td colspan="5">Loading...</td></tr>');
      $.ajax({
        url: rentalObj.url,
        type: 'GET',
        dataType: 'json',
        data: {
          action: 'rental_get_file_sync_details',
          sync_id: sync_id,
          page: p,
          per_page: per_page
        },
        success: function (resp) {
          if (!resp.success) {
            $tbody.html('<tr><td colspan="5">Error loading details.</td></tr>');
            return;
          }

          var entries = resp.entries || [];
          var html = '';
          if (!entries.length) {
            html = '<tr><td colspan="5">No entries.</td></tr>';
          } else {

            for (var i = 0; i < entries.length; i++) {
              var row = entries[i];
              var dataStr = '';
              try {
                if (typeof row.data === 'object') dataStr = JSON.stringify(row.data);
                else dataStr = row.data;
              } catch (ex) {
                dataStr = String(row.data);
              }
              html += '<tr>' +
                      '<td>' + row.level + '</td>' +
                      '<td>' + escapeHtml(row.message) + '</td>' +
                      '<td><pre style="white-space:pre-wrap;max-width:30rem">' + escapeHtml(dataStr) + '</pre></td>' +
                      '<td>' + (row.processed_count || 0) + ' / ' + (row.total_count || 0) + '</td>' +
                      '<td>' + getDate(row.register_time) + '</td>' +
                      '</tr>';
            }
          }
          $tbody.html(html);

          // render details pager
          var total_pages = resp.total_pages || 1;
          var cur_page = resp.page || 1;
          var pagerHtml = '';
          if (total_pages > 1) {
            if (cur_page === 1) {
              pagerHtml = '<a class="active" href="#">1</a>';
              if (total_pages >= 2) {
                pagerHtml += '<a href="#">2</a> .. <a class="last" href="#">&raquo;</a>';
              }
            } else if (cur_page === total_pages) {
              pagerHtml = '<a class="first" href="#">&laquo;</a> ..' +
                          '<a href="#">' + (total_pages - 1) + '</a>' +
                          '<a class="active" href="#">' + total_pages + '</a>';
            } else {
              pagerHtml = '<a class="first" href="#">&laquo;</a> ..' +
                          '<a href="#">' + (cur_page - 1) + '</a>' +
                          '<a class="active" href="#">' + cur_page + '</a>' +
                          '<a href="#">' + (cur_page + 1) + '</a>' +
                          '.. <a class="last" href="#">&raquo;</a>';
            }
          }

          $pager.html(pagerHtml);
          
        },
        error: function(xhr) {
          $tbody.html('<tr><td colspan="5">Server error while loading details.</td></tr>');
        }
      });
    }

    // show details and load page 1
    $details.show();
    loadPage(1);

    // pager click handler inside this details container
    $details.off('click', '.rental-details-pagination a').on('click', '.rental-details-pagination a', function (e) {
      e.preventDefault();
      var $a = $(this);
      var cur = parseInt($details.data('cur-page') || 1, 10);
      var total = parseInt($details.data('total-pages') || 1, 10);

      // compute clicked page
      var clicked;
      if ($a.hasClass('last')) {
        clicked = parseInt($a.closest('.rental-details-pagination').data('total-pages') || 1, 10);
      } else if ($a.hasClass('first')) {
        clicked = 1;
      } else {
        clicked = parseInt($a.text(), 10);
      }
      // load that page
      loadPage(clicked);
      // update attributes
      $details.data('cur-page', clicked);
    });

  }); // end details click


  $('#rental-checkbox-sync-files-manual').on('click', function (e) {
    $("#rental_sync").attr('disabled', true);
    $("#rental_resync").attr('disabled', true);
    let $this = $(this);
    let syncFilesManual = 0;
    if ($this.is(':checked')) {
      syncFilesManual = 1;
    }

    let $ajaxMsgBox = $(".ajax-alert");
    $ajaxMsgBox.find("strong").html('');
    $ajaxMsgBox.removeClass("success");
    $ajaxMsgBox.removeClass("danger");
    $.ajax({
        type: 'POST',
        url: rentalObj.url,
        data: {
            action: 'rental_update_file_sync_settings',
            sync_files_manual: syncFilesManual
        },
        success: function (data) {
          console.log(data);
          $ajaxMsgBox.find("strong").html('');
          $ajaxMsgBox.find("strong").append("Sync settings saved successfully.");
          $ajaxMsgBox.addClass("success");
          $ajaxMsgBox.fadeIn();
          setTimeout(() => {
            $ajaxMsgBox.fadeOut();
            location.reload();
          }, 2500);
        },
        error: function (data) {
            data = data.responseJSON;
            console.log("Error:");
            console.log(data);
            $ajaxMsgBox.find("strong").append("Operation failed!");
            $ajaxMsgBox.addClass("danger");
            $ajaxMsgBox.fadeIn();
            setTimeout(() => {
              $ajaxMsgBox.fadeOut();
            }, 2500);
        }
    });
});

  var $settings = $('#rental_settings');
  if ($settings.length) {
    $('input[name=rental_direct_only_bookings]').on('change', function () {
      if (this.checked) {
        $allowDiscount.prop('disabled', false);
        $hidePrice.prop('checked', false).prop('disabled', true).trigger('change');
      } else {
        $allowDiscount.prop('checked', false).prop('disabled', true).trigger('change');
        $hidePrice.prop('disabled', false);
      }
    });

    var $allowDiscount = $('input[name=rental_allow_to_pay_deposit]').on('change', function () {
      var $anotherAmount = $('#rental-another-amount-group');
      if (this.checked) {
        $anotherAmount.show();
      } else {
        $anotherAmount.hide();
      }
    });

    var $minOrderAmount = $('input[name=rental_min_order_amount]').on('change', function () {
      var $minOrderGroup = $('.rental-min-order-group');
      var value = parseFloat(this.value);
      if (value > 0) {
        var max = parseFloat($freeShippingAmount.val());
        if (max > 0 && max < value) {
          value = max;
        }
        this.value = value.toFixed(2);
        $minOrderGroup.show();
      } else {
        this.value = '';
        $minOrderGroup.hide();
      }
    });

    var $freeShippingAmount = $('input[name=rental_free_shipping_amount]').on('change', function () {
      var value = parseFloat(this.value);
      if (value > 0) {
        var min = parseFloat($minOrderAmount.val());
        if (min > 0 && min > value) {
          value = min;
        }
        this.value = value.toFixed(2);
      } else {
        this.value = '';
      }
    });

    var $hidePrice = $('input[name=rental_hide_product_price]').on('change', function () {
      var $showPriceInCard = $('input[name=rental_show_product_price_only_in_cart]');
      if (this.checked) {
        $showPriceInCard.prop('checked', false).prop('disabled', true);
      } else {
        $showPriceInCard.prop('disabled', false);
      }
    });

    let $doNotUseDelivery = $('input[name=rental_do_not_use_rentopian_shipping]');
    $doNotUseDelivery.on('change', function () {
      if (this.checked) {
        $(".rental-delivery-form-group").fadeOut();
        $(".track-wc-shipping").fadeIn()
      } else {
        $(".rental-delivery-form-group").fadeIn();
        $(".track-wc-shipping").fadeOut()
      }
    });
    
    setTimeout(() => {
      if ($doNotUseDelivery.is(':checked')) {
        $(".rental-delivery-form-group").fadeOut();
        $(".track-wc-shipping").fadeIn()
      } else {
        $(".track-wc-shipping").fadeOut()
      }
    }, 500);
    

    $('input[name=rental_select_division]').on('change', function () {
      var $hideZip = $('input[name=rental_hide_zip]');
      if ( !$hideZip.length) {
        return;
      }
      if (this.checked) {
        $hideZip.prop('checked', true).prop('disabled', true);
      } else {
        $hideZip.prop('disabled', false);
      }
    });

    $('input[name=rental_date_step]').on('change', function () {
      var $selectRange = $('input[name=rental_select_date_range]');
      if (parseInt(this.value) >= 1) {
        $selectRange.prop('disabled', false);
      } else {
        this.value = '';
        $selectRange.prop('checked', false).prop('disabled', true);
      }
    });

    var $tiers = $('#rental_tiers_container');
    if ($tiers.length) {
      $tiers.on('change', '.min', function () {
        var $this = $(this);
        var $tr = $this.parents('tr');
        var $prev = $tr.prev();
        var $prevMax = $prev.find('.max');
        var val = $this.val() ? parseInt($this.val()) : 0;
        var min = parseInt($this.attr('min'));
        if (val < min) {
          val = min;
        }
        var max = parseInt($this.attr('max'));
        if (val > max) {
          val = max;
        }
        $this.val(val);
        $prevMax.val(val);
        $tr.find('.max').attr('min', val + 1);
        $prev.find('.min').attr('max', val - 1);
        $tr.next().find('.min').attr('min', val + 1);
      }).on('change', '.max', function () {
        var $this = $(this);
        var $tr = $this.parents('tr');
        var $next = $tr.next();
        var $nextMin = $next.find('.min');
        var val = parseInt($this.val());
        var min = parseInt($this.attr('min'));
        if (!Number.isNaN(val)) {
          if (val < min) {
            val = min;
          }
          var max = parseInt($this.attr('max'));
          if (val > max) {
            val = max;
          }
          if ($nextMin.length) {
            $nextMin.val(val);
          }
        } else {
          if ($nextMin.length) {
            val = parseInt($nextMin.val());
          } else {
            val = min;
          }
        }
        $this.val(val ? val : '');
        $tr.find('.min').attr('max', val - 1);
        $next.find('.max').attr('min', val + 1);
        $tr.prev().find('.max').attr('max', val - 1);
      }).on('change', '.day', function () {
        if (!this.value || parseInt(this.value) < 1) {
          this.value = 1;
        }
      }).on('click', '.rental-delete-tier', function () {
        $(this).parents('tr').remove();
        var $lines = $tiers.find('tr');
        var last = $lines.length - 1;
        $lines.eq(last).find('.max').attr('max', '');
        if (last >= 0) {
          $lines.eq(last).find('.action').html('<button class="btn btn-sm btn-icon btn-inline-danger rental-delete-tier"><span class="dashicons dashicons-trash"></span></button>');
        }
      });

      $('#rental_add_tier').on('click', function () {
        var $lastLine = $tiers.find('tr').last();
        var $lastMax = $lastLine.find('.max');
        var lastMax = $lastLine.length ? parseInt($lastMax.val()) : 0;
        if (!$lastLine.length || (lastMax && $lastLine.find('.min').val() && $lastLine.find('.day').val())) {
          $lastLine.find('.action').html('');
          $lastMax.attr('max', lastMax);
          $tiers.append('<tr>' +
            '<td><div class="input-group"><input class="form-control min" step="1" min="' + (lastMax ? $lastMax.attr('min') : 0) + '" max="' + lastMax + '" value="' + lastMax + '" type="number" /></div></td>' +
            '<td><div class="input-group"><input class="form-control max" step="1" min="' + (lastMax + 1) + '" value="' + (lastMax + 1) + '" type="number"></div></td>' +
            '<td><div class="input-group"><input class="form-control day" step="1" min="1" value="1" type="number"></div></td>' +
            '<td class="action text-center"><button class="btn btn-sm btn-icon btn-inline-danger rental-delete-tier"><span class="dashicons dashicons-trash"></span></button></td>' +
            '</tr>');
        }
      });

      $settings.on('submit', function () {
        var tiers = [];
        $tiers.find('tr').each(function (i) {
          var $this = $(this);
          tiers[i] = {
            min: $this.find('.min').val(),
            max: $this.find('.max').val(),
            day: $this.find('.day').val()
          };
        });
        $('#rental_day_tiers').val(JSON.stringify(tiers));
      });

      var items = JSON.parse(document.getElementById('rental_day_tiers').value);
      var len = items.length;
      if (len) {
        var itemsHtml = '';
        for (var i = 0; i < len; i++) {
          items[i].min = parseInt(items[i].min);
          items[i].max = parseInt(items[i].max);
          itemsHtml += '<tr>' +
            '<td><div class="input-group"><input class="min" step="1" min="' + (i ? items[i - 1].min : 0) + '" max="' + (items[i].max ? items[i].max - 1 : '') + '" value="' + items[i].min + '" type="number"></div></td>' +
            '<td><div class="input-group"><input class="max" step="1" min="' + (items[i].min + 1) + '" max="' + (i + 1 < len && items[i + 1].max ? items[i + 1].max - 1 : '') + '" value="' + (items[i].max ? items[i].max : '') + '" type="number" /></div></td>' +
            '<td><div class="input-group"><input class="day" step="1" min="1" value="' + items[i].day + '" type="number" /></div></td>' +
            '<td class="action text-right">';
          if (i + 1 === len) {
            itemsHtml += '<button class="btn btn-sm btn-icon btn-inline-danger rental-delete-tier"><span class="dashicons dashicons-trash"></span></button>';
          }
          itemsHtml += '</td></tr>';
        }
        $tiers.html(itemsHtml);
      }
    }
  }

  let $googleMapKeyWrapper = $(".google-key-wrapper");
  let $googleDistanceKey = $("#rntp-checkbox-rental_google_distance_key");
  let $useGoogleDistanceKey = $("#rntp-checkbox-rental_use_google_distance_key");

  googleAddressAndUseGoogleDistanceInputsRelation($useGoogleDistanceKey, $googleMapKeyWrapper)
  $useGoogleDistanceKey.on("change", function() {
    googleAddressAndUseGoogleDistanceInputsRelation($useGoogleDistanceKey, $googleMapKeyWrapper, true)
  });
  function googleAddressAndUseGoogleDistanceInputsRelation($useGoogleDistanceKey, $googleMapKeyWrapper, focusOnGoogleMapInput = false) {
    if ($useGoogleDistanceKey.is(':checked')) {
      // $googleMapKeyWrapper.find("input[name=rental_google_map_key]").val('')
      $googleMapKeyWrapper.find("input[name=rental_google_map_key]").attr('disabled', true)
    } else {
      $googleMapKeyWrapper.find("input[name=rental_google_map_key]").attr('disabled', false)
      if (focusOnGoogleMapInput) {
        $googleMapKeyWrapper.find("input[name=rental_google_map_key]").focus();
      }
    }
  }

  var $rentalSelectADayByDefault = $("#rntp-checkbox-rental_select_a_day_by_default");
  var $rentalSelectedDayDefaultWrapper = $("#rental_selected_default_day_wrapper");
  var $rentalAutomateWrapper = $("#rental_selected_default_day_automate_wrapper");
  currentDaySelectionToggle($rentalSelectADayByDefault, $rentalSelectedDayDefaultWrapper, $rentalAutomateWrapper);
  $rentalSelectADayByDefault.on("change", function() {
    currentDaySelectionToggle($rentalSelectADayByDefault, $rentalSelectedDayDefaultWrapper, $rentalAutomateWrapper);
  });

  function currentDaySelectionToggle($rentalSelectADayByDefault, $rentalSelectedDayDefaultWrapper, $rentalAutomateWrapper) {
    if ($rentalSelectADayByDefault.is(':checked')) {
      $rentalSelectedDayDefaultWrapper.removeClass("rental-hide");
      $rentalSelectedDayDefaultWrapper.fadeIn();

      $rentalAutomateWrapper.removeClass("rental-hide");
      $rentalAutomateWrapper.fadeIn();
    } else {
      $rentalSelectedDayDefaultWrapper.fadeOut();
      $rentalAutomateWrapper.fadeOut();
    }
  }

  
  $("#rental_selected_default_day-select").on("change", function() {
    let optionSelected = $(this).find("option:selected");
    optionSelected.attr("selected", true)
    optionSelected.siblings().attr("selected", false)
    $("#rntp-input-rental_selected_default_day").val(optionSelected.val())
  });

  // Keep the default-day choices consistent with the orders offset: days inside
  // the blocked window cannot be picked, and the list always reaches the first
  // day the offset allows. Runs on load too, so a pair stored by an earlier
  // version is corrected before it can be saved again.
  var $ordersOffsetInput = $("#rntp-input-rental_min_start_date");
  var $defaultDaySelect = $("#rental_selected_default_day-select");
  var $defaultDayValue = $("#rntp-input-rental_selected_default_day");

  function rentalDefaultDayLabel(value) {
    var days = value - 1;
    if (days <= 0) {
      return "Today";
    }
    return "+" + days + (days === 1 ? " day" : " days");
  }

  function syncDefaultDayWithOffset() {
    if (!$defaultDaySelect.length || !$defaultDayValue.length) {
      return;
    }

    var offset = parseInt($ordersOffsetInput.val(), 10);
    if (isNaN(offset) || offset < 0) {
      offset = 0;
    }

    var minDay = offset + 1;
    var maxDay = Math.max(8, minDay);
    var current = parseInt($defaultDayValue.val(), 10) || 1;

    for (var value = $defaultDaySelect.find("option").length + 1; value <= maxDay; value++) {
      $defaultDaySelect.append(
        $("<option></option>").attr("value", value).text(" " + rentalDefaultDayLabel(value) + " ")
      );
    }

    $defaultDaySelect.find("option").each(function() {
      $(this).prop("disabled", parseInt($(this).attr("value"), 10) < minDay);
    });

    if (current < minDay) {
      current = minDay;
    }

    $defaultDaySelect.val(String(current));
    $defaultDayValue.val(current);
  }

  syncDefaultDayWithOffset();
  $ordersOffsetInput.on("change keyup", syncDefaultDayWithOffset);

  // Delivery / Pickup options
  let $companyClientDeliveryReturn = $("#rntp-company-client-delivery-return");
  $companyClientDeliveryReturn.on("click", displayDeliveryPickupAllowBothOptions);
  $companyClientDeliveryReturn.on("click", function() {
    toggleDisablerMinOrderPickup(0);
    toggleDeliveryPickupRelatedOptions(0);
  });
  if ($companyClientDeliveryReturn.is(":checked")) {
    displayDeliveryPickupAllowBothOptions();
    toggleDisablerMinOrderPickup(0);
    toggleDeliveryPickupRelatedOptions(0);
  }

  let $clientPickupReturn = $("#rntp-client-pickup-return");
  $clientPickupReturn.on("click", hideDeliveryPickupAllowBothOptions);
  $clientPickupReturn.on("click", function() {
    toggleDisablerMinOrderPickup(1);
    toggleDeliveryPickupRelatedOptions(1);
  });
  if ($clientPickupReturn.is(":checked")) {
    toggleDisablerMinOrderPickup(1);
    toggleDeliveryPickupRelatedOptions(1);
  }

  let $companyDeliveryReturn = $("#rntp-company-delivery-return");
  $companyDeliveryReturn.on("click", hideDeliveryPickupAllowBothOptions);
  $companyDeliveryReturn.on("click", function() {
    toggleDisablerMinOrderPickup(1);
    toggleDeliveryPickupRelatedOptions(0);
  });
  if ($companyDeliveryReturn.is(":checked")) {
    $('#rntp-checkbox-rental_min_order_pickup').prop('checked', false).prop('disabled', true);
    toggleDisablerMinOrderPickup(1);
    toggleDeliveryPickupRelatedOptions(0);
  }
 
  function displayDeliveryPickupAllowBothOptions() {
    let $allowBothOptions = $("#rntp-allow-both-options");
    if ($allowBothOptions.hasClass("rental-hide")) {
      $allowBothOptions.removeClass("rental-hide");
      $allowBothOptions.fadeIn();
    }
  }
  function hideDeliveryPickupAllowBothOptions() {
    let $allowBothOptions = $("#rntp-allow-both-options");
    if (!$allowBothOptions.hasClass("rental-hide")) {
      $allowBothOptions.addClass("rental-hide");
      $allowBothOptions.fadeOut();
    }
  }
  function toggleDisablerMinOrderPickup(disable) {
    let $minOrderPickup = $('#rntp-checkbox-rental_min_order_pickup');
    if (disable == 1) {
      $minOrderPickup.prop('checked', false).prop('disabled', true);
    } else {
      $minOrderPickup.prop('disabled', false);
    }
  }
  function toggleDeliveryPickupRelatedOptions(disable) {
    let $difPickup = $("#rntp-checkbox-rental_different_pick_up_addresses");
    let $dblShipping = $("#rntp-checkbox-rental_double_shipping_fee");
    let $combineShipping = $("#rntp-checkbox-rental_combine_shipping_tax");
    let $doNotUseDelivery = $('input[name=rental_do_not_use_rentopian_shipping]');
    if (disable == 1) {
      $difPickup.prop('checked', false).prop('disabled', true);
      $dblShipping.prop('checked', false).prop('disabled', true);
      $combineShipping.prop('checked', false).prop('disabled', true);
      $(".only-pickup-hide").fadeOut();
    } else {
      if (!($doNotUseDelivery.is(':checked'))) {
        $difPickup.prop('disabled', false);
        $dblShipping.prop('disabled', false);
        $combineShipping.prop('disabled', false);
        $(".only-pickup-hide").fadeIn();
      }
    }
  }

  if ($googleDistanceKey.length) {
    $googleDistanceKey.on("keyup change", function() {
      toggleGoogleDistanceEffectOnDeliveryOptions($(this).val());
    });
    toggleGoogleDistanceEffectOnDeliveryOptions($googleDistanceKey.val());

    function toggleGoogleDistanceEffectOnDeliveryOptions(value) {
      let $companyDeliveryReturn = $("#rntp-company-delivery-return");
      let $companyClientDeliveryReturn = $("#rntp-company-client-delivery-return");
      let $clientPickReturn = $("#rntp-client-pickup-return");
      let $useGoogleDistanceKeyInput = $("#rntp-checkbox-rental_use_google_distance_key");
      let $useGoogleDistanceKeyLabel = $(".rntp-use-google-distance");
      
      let isMileBased = $googleDistanceKey.data('is-mile-based');
      if (isMileBased == 1) {
        if (value == '') {
          // if ($clientPickReturn.length && !$clientPickReturn.is(':checked')) {
          //   $clientPickReturn.prop('checked', true);
          // }
  
          // if ($companyDeliveryReturn.length) {
          //   $companyDeliveryReturn.prop('checked', false);
          //   $companyDeliveryReturn.parent().fadeOut();
          // }
          // if ($companyClientDeliveryReturn.length) {
          //   $companyClientDeliveryReturn.prop('checked', false);
          //   $companyClientDeliveryReturn.parent().fadeOut();
          // }

          
         
        } else {
          $companyDeliveryReturn.parent().fadeIn();
          $companyClientDeliveryReturn.parent().fadeIn();
          
        }
      } 

      // conditions working when there is no miles based delivery
      if (value == '') {
        if ($useGoogleDistanceKeyInput.length && $useGoogleDistanceKeyLabel.length) {
          $useGoogleDistanceKeyInput.prop('checked', false).fadeOut();
          $useGoogleDistanceKeyLabel.fadeOut();
        }
        
      } else {
        $useGoogleDistanceKeyInput.fadeIn();
        $useGoogleDistanceKeyLabel.fadeIn();
      }
      
    }
  }

  let restrictedDaysSection = $(".restricted-days-section");
  let fixedRentalInterval = $("#rntp-input-rental_date_step");
  let fixedRentalIntervalGroup = $(".fixed-interval-group");
  toggleRestrictedDays(fixedRentalInterval, restrictedDaysSection);
  fixedRentalInterval.on("change keyup", function() {
    toggleRestrictedDays(fixedRentalInterval, restrictedDaysSection);
  });

  function toggleRestrictedDays(fixedRentalInterval, restrictedDaysSection) {
    if (fixedRentalInterval.val() !== '' && fixedRentalInterval.val() !== 0) {
      restrictedDaysSection.hide();
    } else {
      restrictedDaysSection.show();
    }
  }

  $('[id^="rntp-disabled-week-"]').on('click', function() {
      if ($(this).is(":checked")) {
        togglefixedRentalIntervalGroup(fixedRentalInterval, fixedRentalIntervalGroup, 'hide');
      }

      checkDisabledWeekDays();
  });

  checkDisabledWeekDays();
  function checkDisabledWeekDays() {
    let allElementsUnchecked = true;
    $('[id^="rntp-disabled-week-"]').each(function() {
        if ($(this).is(":checked")) {
          allElementsUnchecked = false;
        }
    });
  
    if (allElementsUnchecked === true) {
      togglefixedRentalIntervalGroup(fixedRentalInterval, fixedRentalIntervalGroup, 'show');
    }
  }
  

  function togglefixedRentalIntervalGroup(fixedRentalInterval, fixedRentalIntervalGroup, action) {
    fixedRentalInterval.val('');
    if (action == 'hide') {
      fixedRentalIntervalGroup.hide();
    } else {
      fixedRentalIntervalGroup.show();
    }
  }
  

  // validation
  function validate() {

    function displayValidateError(input, errorMsgBlock, scrollTopAction = true) {
      if (errorMsgBlock != '') {
        input.after(errorMsgBlock);
        input.addClass('rental-danger');
        if (scrollTopAction) {
          $([document.documentElement, document.body]).animate({
            scrollTop: input.offset().top - 50
          }, 1500);
        }
      } else {
        input.parent().find('span').html('');
      }
    }
  
    function validateMinMaxFixedDates(minDateRange, maxDateRange, fixedRentalInterval) {
      if ((minDateRange.val() !== '' && fixedRentalInterval.val() !== '') && (minDateRange.val() !== 0 && fixedRentalInterval.val() !== 0) && minDateRange.val() > fixedRentalInterval.val()) {
        displayValidateError(minDateRange, '');
        displayValidateError(minDateRange, '<span class="rental-error-msg"> The minimum range cannot be greater than the fixed rental interval! </span>');
        return false;
      }
      if ((maxDateRange.val() !== '' && fixedRentalInterval.val() !== '') && (maxDateRange.val() !== 0 && fixedRentalInterval.val() !== 0) && maxDateRange.val() < fixedRentalInterval.val()) {
        displayValidateError(minDateRange, '');
        displayValidateError(minDateRange, '<span class="rental-error-msg"> The maximum range cannot be less than the fixed rental interval! </span>');
        return false;
      }
      if ((minDateRange.val() !== '' && maxDateRange.val() !== '' && fixedRentalInterval.val() !== '') && (minDateRange.val() !== 0 && maxDateRange.val() !== 0 && fixedRentalInterval.val() !== 0) && (minDateRange.val() === maxDateRange.val()) && (minDateRange.val() > fixedRentalInterval.val() && maxDateRange.val() > fixedRentalInterval.val()) ) {
        displayValidateError(fixedRentalInterval, '');
        displayValidateError(fixedRentalInterval, '<span class="rental-error-msg"> The fixed rental interval cannot be less than the minimum and maximum ranges! </span>');
        return false;
      }
      if ((minDateRange.val() !== '' && maxDateRange.val() !== '' && fixedRentalInterval.val() !== '') && (minDateRange.val() !== 0 && maxDateRange.val() !== 0 && fixedRentalInterval.val() !== 0) && (minDateRange.val() === maxDateRange.val()) && (minDateRange.val() < fixedRentalInterval.val() && maxDateRange.val() < fixedRentalInterval.val()) ) {
        displayValidateError(fixedRentalInterval, '');
        displayValidateError(fixedRentalInterval, '<span class="rental-error-msg"> The fixed rental interval cannot be greater than the both minimum and maximum ranges! </span>');
        return false;
      }
      return true;
    }

    let $minDateRange = $("#rntp-input-rental_min_dates_range");
    let $maxDateRange = $("#rntp-input-rental_max_dates_range");
    let $googleMapAddressKey = $("#rntp-input-rental_google_map_key");
    let $useGoogleDistanceKeyInput = $("#rntp-checkbox-rental_use_google_distance_key");
    let $rentalLocation = $("#rntp-checkbox-rental_show_location");
    if ($minDateRange.length && $minDateRange.val() > 0 && $maxDateRange.val() > 0) {
      if ($minDateRange.val() > $maxDateRange.val()) {
        displayValidateError($minDateRange, '');
        displayValidateError($minDateRange, '<span class="rental-error-msg">The maximum range cannot be less than the minimum range (and vice versa)!</span>')
        return false;
      }
    }
    // when enabling the Show Delivery Input Address, the google map key(Google Address Autocomplete API Key) must be required
    if ($rentalLocation.is(':checked') && $googleMapAddressKey.val() === '' && !$useGoogleDistanceKeyInput.is(':checked')) {
      displayValidateError($googleMapAddressKey, '');
      displayValidateError($googleMapAddressKey, '<span class="rental-error-msg">When using Delivery Address Input, The Google Address Autocomplete API Key field is required!</span>')
      return false;
    }

    // The orders offset and the default selected day are kept consistent by
    // syncDefaultDayWithOffset() and re-checked server-side on save.

    let $fixedRentalInterval = $("#rntp-input-rental_date_step");
    if (!validateMinMaxFixedDates($minDateRange, $maxDateRange, $fixedRentalInterval)) {
      return false;
    }

    return true;
  }
  
  $("#rental_save_settings").on("click", function(e) {
    if (!validate()) {
      e.preventDefault();
    }
  })


  $('#rental_save_log_settings').on('click', function () {
    let rental_runtime_log_setting = $('input[name="rental_runtime_log_setting"]:checked').val();
    var data = {
      action: 'rental_save_log_settings',
      rental_runtime_log_setting : rental_runtime_log_setting
    };

    let $ajaxMsgBox = $(".ajax-alert");
    $ajaxMsgBox.find("strong").html('');
    $ajaxMsgBox.removeClass("success");
    $ajaxMsgBox.removeClass("danger");
    $.ajax({
      type: 'POST',
      url: rentalObj.url,
      data: data,
      success: function () {
        $ajaxMsgBox.find("strong").append("Log settings saved successfully.");
        $ajaxMsgBox.addClass("success");
        $ajaxMsgBox.fadeIn();
        setTimeout(() => {
          $ajaxMsgBox.fadeOut();
        }, 2500);
      },
      error: function (data) {
        data = data.responseJSON;
        console.log(data);
        // alert(data.message);
        $ajaxMsgBox.find("strong").append("Operation failed!");
        $ajaxMsgBox.addClass("danger");
        $ajaxMsgBox.fadeIn();
        setTimeout(() => {
          $ajaxMsgBox.fadeOut();
        }, 2500);
      }
    });
  });

  // default start/end time picker
  let $rental_default_start_time = $('#rental_default_start_time');
  let $rental_default_end_time = $('#rental_default_end_time');
  $rental_default_start_time.calentim({
    showCalendars: false,
    showTimePickers: true,
    format: 'h:mm A',
    singleDate: true,
    minuteSteps: 15
  });
  $rental_default_end_time.calentim({
    showCalendars: false,
    showTimePickers: true,
    format: 'h:mm A',
    singleDate: true,
    minuteSteps: 15
  });

  $rental_default_start_time.on("change", function() {
    if ($(this).val() == '') {
      $(this).val('9:00 AM');
    }
  });
  $rental_default_end_time.on("change", function() {
    if ($(this).val() == '') {
      $(this).val('5:00 PM');
    }
  });
  
});



// Tabs Navigation
function tabify( element ){
    const header = element.querySelector('.rental-tabs__list');
    const content = element.querySelector('.rental-tabs__tab-content');
    const tab_headers = [...header.children];
    const tab_contents = [...content.children];
    tab_contents.forEach( x => x.style.display = 'none');
    let current_tab_index = -1;

    function setTab( index ){
        if( current_tab_index > -1 ){
            tab_headers[ current_tab_index ].className = '';
            tab_contents[ current_tab_index ].style.display = 'none';
        }
        tab_headers[ index ].className = 'active';
        tab_contents[ index ].style.display = 'block';
        current_tab_index = index;
    }

    default_tab_index = tab_headers.findIndex( x => {
        return [...x.classList].indexOf('default-rental-tab') > -1;
    });

    default_tab_index = default_tab_index === -1 ? 0 : default_tab_index;
    setTab( default_tab_index );
    tab_headers.forEach((x,i) => x.onclick = event => setTab(i));
}

// this is where the magic happens!
[...document.querySelectorAll('.rental-tabs')].forEach(x => tabify(x));



function showProcessingModal() {
  let modalHtml = `
      <div id="processing-modal" class="processing-modal" >
          <div class="processing-modal-content">
              <p> 
                Processing, please wait...
              </p>
          </div>
      </div>`;
    jQuery("body").append(modalHtml);
}

function hideProcessingModal() {
  jQuery("#processing-modal").remove();
}

function escapeHtml(str) {
  if (str === null || str === undefined) return '';
  return String(str).replace(/[&<>"'\/]/g, function (s) {
    var entityMap = {
      "&": "&amp;",
      "<": "&lt;",
      ">": "&gt;",
      '"': '&quot;',
      "'": '&#39;',
      "/": '&#x2F;'
    };
    return entityMap[s];
  });
}
