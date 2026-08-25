/*--------------------------------------------------------------
Rentopian Sync Hourly Products Modal Functionality Scripts
Author: Rentopian
Website: https://rentopian.com
Copyright 2019, Rentopian Inc. All Rights Reserved.
----------------------------------------------------------------*/

jQuery(document).ready(function($) {

  // loading the modal block
  var $modal_holder = $("#rental_hourly_modal_holder");
  var start;
  var startDateServer;
  var rental_interval_steps;
  var rental_interval;
  var hourlyCalendar;
  var serverDateFormat = 'YYYY/MM/DD';
  if ($modal_holder.length > 0) {

    function addToCartHandler(e) {
      if ($modal_holder.data('modal_is_open')) {
        $modal_holder.hide('slow');
        return;
      }

      e.preventDefault();
      e.stopImmediatePropagation();
      $modal_holder.data('modal_is_open', 1).show('slow');

      displayHourlyCalendar()
    }

    var $att_to_cart_form = $modal_holder.closest('form.cart');
    $att_to_cart_form.on('submit', addToCartHandler)
      .on('click', '.single_add_to_cart_button, .add_to_cart_button', addToCartHandler);

    $modal_holder.on('click', '.rental-modal-cancel', function() {
      $modal_holder.data('modal_is_open', '').hide('slow');
      location.reload()
    });


    function displayHourlyCalendar() {
      // calendar 
      var $hourly_calendar_wrapper = $("#rental-hourly-calendar-wrapper")
      if ($hourly_calendar_wrapper.length) {

        var $rental_date_hourly = $("#rental_date_hourly");
        var now = moment();
        var disabledWeekDays = $rental_date_hourly.data('disabled-week-days')


        var config = {
          format: 'MMM D' + (', h:mm A'),
          showTimePickers: false,
          singleDate: true,
          showOn: 'bottom',
          oneCalendarWidth : 500,
          autoAlign: false,
          calendarCount: 1,
          showFooter: false,
          startEmpty: true,
          showButtons: false,
          inline: true,
          minDate: now,
          startOnMonday: true
        };

        // disabled working days
        if (disabledWeekDays) {
          config.disableDays = function(date) {
            for(var key in disabledWeekDays) {
              if (disabledWeekDays[key] === 7) {
                disabledWeekDays[key] = 0
              }
              if (disabledWeekDays[key] === date.day()) {
                return true;
              }
            }
          };

        }
        

        if ($rental_date_hourly.data('work-days')) {
          config.onafterselect = function(calentim, startDate) {

          
            var work_day_hours = $rental_date_hourly.data('work-days')
            var selectedDay = startDate._d.getDay()

            var end_time, start_time, week_day;
            for (var key in work_day_hours) {
              if (work_day_hours[key].week_day === selectedDay) {
                end_time = work_day_hours[key].end_time
                start_time = work_day_hours[key].start_time
                week_day = work_day_hours[key].week_day
              } 
            }

            if (start_time !== undefined) {

               // start/end time without spaces
               var start_time_stripped = start_time.replace(/\s/g, '');
               var end_time_stripped = end_time.replace(/\s/g, '');

              if ($rental_date_hourly.data('hourly')) {
                var hourlyData = $rental_date_hourly.data('hourly')
  
                // console.log(hourlyData)

                if (hourlyData.rental_by_interval) {
                  // rental by interval type
                 
                  var start_time_index=0;
                  var end_time_index=0;

                  const move_step_30 = 15 * 60
                  const move_1_step_45 = 15 * 60
                  const move_2_step_45 = 30 * 60
                  const move_1_step_60 = 15 * 60
                  const move_2_step_60 = 30 * 60
                  const move_3_step_60 = 45 * 60

                  // set the steps 
                  if (hourlyData.rental_interval >= hourlyData.rental_interval_steps) {
                    hourlyData.rental_interval_steps = hourlyData.rental_interval;
                  }

                  var interval_step = parseInt(hourlyData.rental_interval_steps); //minutes interval

                  // to use in order calculation
                  rental_interval_steps = interval_step
                  rental_interval = parseInt(hourlyData.rental_interval)

                  var times = []; // time array
                  var tt = 0; // start time
                  var ap = ['AM', 'PM']; // AM-PM

                  //loop to increment the time and push results in array
                  for (var i=0; tt<24*60; i++) {
                    var hh = Math.floor(tt/60); // getting hours of day in 0-24 format
                    var mm = (tt%60); // getting minutes of the hour in 0-55 format
                    var hour = ("0" + (hh % 12));
                    hour = hour === "00" ? "12" : hour;
                    var minute = ("0" + mm);
                    times[i] = hour.slice(-2) + ':' + minute.slice(-2) + ap[Math.floor(hh/12)]; // pushing data in array in [00:00 - 12:00 AM/PM format]
                    tt = tt + interval_step;

                    // finding start time index
                    var time_ready_to_compare = times[i][0] === 0 ? times[i].substring(1) : times[i];
                    if ( time_ready_to_compare === start_time_stripped) {
                      start_time_index = i
                    }

                    // finding end time index
                    if ( time_ready_to_compare === end_time_stripped) {
                      end_time_index = i
                    }
                  }

                  // finding nearest start/end time index (if we didn't find the exact time in array of times)
                  if (start_time_index === 0 || end_time_index === 0) {

                    var start_time_stripped_am;
                    var start_time_stripped_pm;
                    var start_time_seconds;
                    var start_time_type = "";

                    var end_time_stripped_am;
                    var end_time_stripped_pm;
                    var end_time_seconds;
                    var end_time_type = "";

                    // get start time in seconds
                    start_time_stripped_am = start_time_stripped.split("AM")
                    if (start_time_stripped_am.length > 1) {
                      // am
                      start_time_stripped_am_hour = start_time_stripped_am[0].split(":")[0];
                      start_time_stripped_am_minute = start_time_stripped_am[0].split(":")[1];
                      start_time_seconds = (+start_time_stripped_am_hour) * 60 * 60 + (+start_time_stripped_am_minute) * 60
                      start_time_type = "AM"
                    } else {
                      // pm
                      start_time_stripped_pm = start_time_stripped.split("PM")
                      start_time_stripped_pm_hour = start_time_stripped_pm[0].split(":")[0];
                      start_time_stripped_pm_minute = start_time_stripped_pm[0].split(":")[1];
                      start_time_seconds = (+start_time_stripped_pm_hour + 12) * 60 * 60 + (+start_time_stripped_pm_minute) * 60
                      start_time_type = "PM"
                    }

                    // get end time in seconds
                    end_time_stripped_am = end_time_stripped.split("AM")
                    if (end_time_stripped_am.length > 1) {
                      // am
                      end_time_stripped_am_hour = end_time_stripped_am[0].split(":")[0];
                      end_time_stripped_am_minute = end_time_stripped_am[0].split(":")[1];
                      end_time_seconds = (+end_time_stripped_am_hour) * 60 * 60 + (+end_time_stripped_am_minute) * 60
                      end_time_type = "AM"
                    } else {
                      // pm
                      end_time_stripped_pm = end_time_stripped.split("PM")
                      end_time_stripped_pm_hour = end_time_stripped_pm[0].split(":")[0];
                      end_time_stripped_pm_minute = end_time_stripped_pm[0].split(":")[1];
                      end_time_seconds = (+end_time_stripped_pm_hour + 12) * 60 * 60 + (+end_time_stripped_pm_minute) * 60
                      end_time_type = "PM"
                    }

                    var digitsOfTime_am;
                    var digitsOfTime_am_hour;
                    var digitsOfTime_am_minute;
                    var digitsOfTime_pm;
                    var digitsOfTime_pm_hour;
                    var digitsOfTime_pm_minute;
                    for(var i=0; i<times.length; i++) {
                      
                      var time_ready_to_compare = times[i][0] === 0 ? times[i].substring(1) : times[i];
                      digitsOfTime_am = time_ready_to_compare.split("AM");
                      digitsOfTime_pm = time_ready_to_compare.split("PM");

                      // getting start time index
                      if (digitsOfTime_am.length > 1 && start_time_type === "AM") {
                        // start time type is AM

                        digitsOfTime_am_hour = digitsOfTime_am[0].split(":")[0];
                        digitsOfTime_am_minute = digitsOfTime_am[0].split(":")[1];
                        var current_time_seconds = (+digitsOfTime_am_hour) * 60 * 60 + (+digitsOfTime_am_minute) * 60

                        var start_time_seconds_reset = 0;
                        // check different steps (30-45-60)
                        if (parseInt(hourlyData.rental_interval_steps) === 30) {
                          
                          // plus 15 minutes to start time to reach a valid work day time
                          start_time_seconds_reset = start_time_seconds + move_step_30

                          // check if start time is equal to valid work day time then return index of that time in array                      
                          if (start_time_seconds_reset === current_time_seconds) {
                            start_time_index = i
                            continue;
                          }
                          
                        } else if (parseInt(hourlyData.rental_interval_steps) === 45) {

                          // plus 15 minutes to start time to reach a valid work day time
                          start_time_seconds_reset = start_time_seconds + move_1_step_45;

                          // check if start time is equal to valid work day time then return index of that time in array                      
                          if (start_time_seconds_reset === current_time_seconds) {
                            start_time_index = i
                            continue;
                          }

                          start_time_seconds_reset = 0;

                          // plus 30 minutes to start time to reach a valid work day time
                          start_time_seconds_reset = start_time_seconds + move_2_step_45

                          // check if start time is equal to valid work day time then return index of that time in array                      
                          if (start_time_seconds_reset === current_time_seconds) {
                            start_time_index = i
                            continue;
                          }

                        } else if (parseInt(hourlyData.rental_interval_steps) === 60) {


                          // plus 15 minutes to start time to reach a valid work day time
                          start_time_seconds_reset = start_time_seconds + move_1_step_60

                          // check if start time is equal to valid work day time then return index of that time in array                      
                          if (start_time_seconds_reset === current_time_seconds) {
                            start_time_index = i
                            continue;
                          }

                          start_time_seconds_reset = 0;

                          // plus 30 minutes to start time to reach a valid work day time
                          start_time_seconds_reset = start_time_seconds + move_2_step_60

                          // check if start time is equal to valid work day time then return index of that time in array                      
                          if (start_time_seconds_reset === current_time_seconds) {
                            start_time_index = i
                            continue;
                          }

                          start_time_seconds_reset = 0;

                          // plus 45 minutes to start time to reach a valid work day time
                          start_time_seconds_reset = start_time_seconds + move_3_step_60

                          // check if start time is equal to valid work day time then return index of that time in array                      
                          if (start_time_seconds_reset === current_time_seconds) {
                            start_time_index = i
                            continue;
                          }

                        }

                      } else {
                        // start time type is PM
                        // calculate PM format (PM hours must be plused to 12 to calculate correct seconds)
                        
                        digitsOfTime_pm_hour = digitsOfTime_pm[0].split(":")[0];
                        digitsOfTime_pm_minute = digitsOfTime_pm[0].split(":")[1];
                        var current_time_seconds = (+digitsOfTime_pm_hour + 12) * 60 * 60 + (+digitsOfTime_pm_minute) * 60

                        var start_time_seconds_reset = 0;
                        // check different steps (30-45-60)
                        if (parseInt(hourlyData.rental_interval_steps) === 30) {
                          
                          // plus 15 minutes to start time to reach a valid work day time
                          start_time_seconds_reset = start_time_seconds + move_step_30

                          // check if start time is equal to valid work day time then return index of that time in array                      
                          if (start_time_seconds_reset === current_time_seconds) {
                            start_time_index = i
                            continue;
                          }
                          
                        } else if (parseInt(hourlyData.rental_interval_steps) === 45) {

                          // plus 15 minutes to start time to reach a valid work day time
                          start_time_seconds_reset = start_time_seconds + move_1_step_45

                          // check if start time is equal to valid work day time then return index of that time in array                      
                          if (start_time_seconds_reset === current_time_seconds) {
                            start_time_index = i
                            continue;
                          }

                          start_time_seconds_reset = 0;

                          // plus 30 minutes to start time to reach a valid work day time
                          start_time_seconds_reset = start_time_seconds + move_2_step_45

                          // check if start time is equal to valid work day time then return index of that time in array                      
                          if (start_time_seconds_reset === current_time_seconds) {
                            start_time_index = i
                            continue;
                          }

                        } else if (parseInt(hourlyData.rental_interval_steps) === 60) {


                          // plus 15 minutes to start time to reach a valid work day time
                          start_time_seconds_reset = start_time_seconds + move_1_step_60

                          // check if start time is equal to valid work day time then return index of that time in array                      
                          if (start_time_seconds_reset === current_time_seconds) {
                            start_time_index = i
                            continue;
                          }

                          start_time_seconds_reset = 0;

                          // plus 30 minutes to start time to reach a valid work day time
                          start_time_seconds_reset = start_time_seconds + move_2_step_60

                          // check if start time is equal to valid work day time then return index of that time in array                      
                          if (start_time_seconds_reset === current_time_seconds) {
                            start_time_index = i
                            continue;
                          }

                          start_time_seconds_reset = 0;

                          // plus 45 minutes to start time to reach a valid work day time
                          start_time_seconds_reset = start_time_seconds + move_3_step_60

                          // check if start time is equal to valid work day time then return index of that time to be the start point
                          if (start_time_seconds_reset === current_time_seconds) {
                            start_time_index = i
                            continue;
                          }

                        }

                      }

                      // getting end time index
                      if (digitsOfTime_am.length > 1 && end_time_type === "AM") {
                        // start time type is AM

                        digitsOfTime_am_hour = digitsOfTime_am[0].split(":")[0];
                        digitsOfTime_am_minute = digitsOfTime_am[0].split(":")[1];
                        var current_time_seconds = (+digitsOfTime_am_hour) * 60 * 60 + (+digitsOfTime_am_minute) * 60

                        var end_time_seconds_reset = 0;
                        // check different steps (30-45-60)
                        if (parseInt(hourlyData.rental_interval_steps) === 30) {
                          
                          // minus 15 minutes to end time to reach a valid work day time
                          end_time_seconds_reset = end_time_seconds - move_step_30

                          // check if end time is equal to valid work day time then return index of that time in array                      
                          if (end_time_seconds_reset === current_time_seconds) {
                            end_time_index = i
                            break;
                          }
                          
                        } else if (parseInt(hourlyData.rental_interval_steps) === 45) {

                          // minus 15 minutes from end time to reach a valid work day time
                          end_time_seconds_reset = end_time_seconds - move_1_step_45

                          // check if end time is equal to valid work day time then return index of that time in array                      
                          if (end_time_seconds_reset === current_time_seconds) {
                            end_time_index = i
                            break;
                          }

                          end_time_seconds_reset = 0;

                          // minus 30 minutes from end time to reach a valid work day time
                          end_time_seconds_reset = end_time_seconds - move_2_step_45

                          // check if start time is equal to valid work day time then return index of that time in array                      
                          if (end_time_seconds_reset === current_time_seconds) {
                            end_time_index = i
                            break;
                          }

                        } else if (parseInt(hourlyData.rental_interval_steps) === 60) {

                          // minus 15 minutes from end time to reach a valid work day time
                          end_time_seconds_reset = end_time_seconds - move_1_step_60

                          // check if start time is equal to valid work day time then return index of that time in array                      
                          if (end_time_seconds_reset === current_time_seconds) {
                            end_time_index = i
                            break;
                          }

                          end_time_seconds_reset = 0;

                          // minus 30 minutes to start time to reach a valid work day time
                          end_time_seconds_reset = end_time_seconds - move_2_step_60

                          // check if end time is equal to valid work day time then return index of that time in array                      
                          if (end_time_seconds_reset === current_time_seconds) {
                            end_time_index = i
                            break;
                          }

                          end_time_seconds_reset = 0;

                          // minus 45 minutes from end time to reach a valid work day time
                          end_time_seconds_reset = end_time_seconds - move_3_step_60

                          // check if end time is equal to valid work day time then return index of that time in array                      
                          if (end_time_seconds_reset === current_time_seconds) {
                            end_time_index = i
                            break;
                          }

                        }

                      } else {
                        // end time type is PM
                        // calculate PM format (PM hours must be plused to 12 to calculate correct seconds)
                        
                        digitsOfTime_pm_hour = digitsOfTime_pm[0].split(":")[0];
                        digitsOfTime_pm_minute = digitsOfTime_pm[0].split(":")[1];
                        var current_time_seconds = (+digitsOfTime_pm_hour + 12) * 60 * 60 + (+digitsOfTime_pm_minute) * 60

                        var end_time_seconds_reset = 0;
                        // check different steps (30-45-60)
                        if (parseInt(hourlyData.rental_interval_steps) === 30) {
                          
                          // minus 15 minutes from end time to reach a valid work day time
                          end_time_seconds_reset = end_time_seconds - move_step_30

                          // check if end time is equal to valid work day time then return index of that time in array                      
                          if (end_time_seconds_reset === current_time_seconds) {
                            end_time_index = i
                            break;
                          }
                          
                        } else if (parseInt(hourlyData.rental_interval_steps) === 45) {

                          // minus 15 minutes from end time to reach a valid work day time
                          end_time_seconds_reset = end_time_seconds - move_1_step_45

                         
                          // check if end time is equal to valid work day time then return index of that time in array                      
                          if (end_time_seconds_reset === current_time_seconds) {
                            end_time_index = i
                            break;
                          }

                          end_time_seconds_reset = 0;

                          // minus 30 minutes from end time to reach a valid work day time
                          end_time_seconds_reset = end_time_seconds - move_2_step_45

                          // check if end time is equal to valid work day time then return index of that time in array                      
                          if (end_time_seconds_reset === current_time_seconds) {
                            end_time_index = i
                            break;
                          }

                        } else if (parseInt(hourlyData.rental_interval_steps) === 60) {


                          // minus 15 minutes from end time to reach a valid work day time
                          end_time_seconds_reset = end_time_seconds - move_1_step_60

                          // check if end time is equal to valid work day time then return index of that time in array                      
                          if (end_time_seconds_reset === current_time_seconds) {
                            end_time_index = i
                            break;
                          }

                          end_time_seconds_reset = 0;

                          // minus 30 minutes from end time to reach a valid work day time
                          end_time_seconds_reset = end_time_seconds - move_2_step_60

                          // check if end time is equal to valid work day time then return index of that time in array                      
                          if (end_time_seconds_reset === current_time_seconds) {
                            end_time_index = i
                            break;
                          }

                          end_time_seconds_reset = 0;

                          // minus 45 minutes from end time to reach a valid work day time
                          end_time_seconds_reset = end_time_seconds - move_3_step_60

                          // check if end time is equal to valid work day time then return index of that time to be the start point
                          if (end_time_seconds_reset === current_time_seconds) {
                            end_time_index = i
                            break;
                          }

                        }

                      }

                    }

                  }

                  
                  // filter the start and end time range
                  var times_filtered = times.filter(function(value, index){
                    if (start_time_index <= index && end_time_index >= index) {
                      return value
                    }
                  });


                  var $price;
                  if (hourlyData.rental_interval_steps > hourlyData.rental_interval) {
                    var steps = parseInt(hourlyData.rental_interval_steps); 
                    var intervals = parseInt(hourlyData.rental_interval);

                    // to use in order calculation
                    rental_interval_steps = steps
                    rental_interval = intervals
                    
                    if ( (steps % intervals) === 0 ) {
                      $price = (steps/intervals) * (hourlyData.rental_interval_sale_price !== 0 ? hourlyData.rental_interval_sale_price : hourlyData.rental_interval_price);
                    } else {
                      $price = hourlyData.rental_interval_sale_price !== 0 ? hourlyData.rental_interval_sale_price : hourlyData.rental_interval_price;
                    }

                    intervalPriceItems(times_filtered, $price)

                  } else {

                    // to use in order calculation
                    rental_interval_steps = parseInt(hourlyData.rental_interval_steps)
                    rental_interval = parseInt(hourlyData.rental_interval)

                    $price = hourlyData.rental_interval_sale_price !== 0 ? hourlyData.rental_interval_sale_price : hourlyData.rental_interval_price;
                    intervalPriceItems(times_filtered, $price)

                  }

                  // put the selected day's interval and price combined items under calendar
                  function intervalPriceItems(times_filtered, $price) {
                    let $rental_intervals = $("#rental_intervals");
                    $rental_intervals.attr("data-times-filtered", times_filtered)
                    $rental_intervals.attr("data-selected-day", selectedDay)
                    // $rental_intervals.attr("data-selected-date", startDate._d)
                    var $item;
                    $rental_intervals.html("")
                    for(var i=0; i<times_filtered.length; i++) {

                      var time_spaced=times_filtered[i];
                      if ( times_filtered[i].indexOf("PM") > -1) {
                        time_spaced = times_filtered[i].replace("PM", " PM");
                      } else {
                        time_spaced = times_filtered[i].replace("AM", " AM");
                      }
                      
                      $item = "<div id='_rental_"+i+"' data-time='"+times_filtered[i]+"' data-price='"+$price+"' class='rental_interval_price'> "+ time_spaced +" ($"+ $price +") </div>";
                      $rental_intervals.append($item);
                    }
                  }

                  // console.log(times)
                  // console.log("start_time_index:", start_time_index)
                  // console.log("end_time_index:", end_time_index)
                  // console.log("filtered_times:", times_filtered)
                  // console.log("rental_interval_steps:", hourlyData.rental_interval_steps)
                  // console.log("rental_interval:", hourlyData.rental_interval)
                  // console.log("rental_interval_price:", hourlyData.rental_interval_price)
                  // console.log("rental_interval_sale_price:", hourlyData.rental_interval_sale_price)
  
                } else {
                  // rental by slot type

                  var slots = hourlyData.rental_time_slots
                  var slots_to_display = []

                  // get start time in seconds
                  start_time_stripped_am = start_time_stripped.split("AM")
                  if (start_time_stripped_am.length > 1) {
                    // am
                    start_time_stripped_am_hour = start_time_stripped_am[0].split(":")[0];
                    start_time_stripped_am_minute = start_time_stripped_am[0].split(":")[1];
                    start_time_seconds = (+start_time_stripped_am_hour) * 60 * 60 + (+start_time_stripped_am_minute) * 60;
                    start_time_type = "AM";
                  } else {
                    // pm
                    start_time_stripped_pm = start_time_stripped.split("PM")
                    start_time_stripped_pm_hour = start_time_stripped_pm[0].split(":")[0];
                    start_time_stripped_pm_minute = start_time_stripped_pm[0].split(":")[1];
                    start_time_seconds = (+start_time_stripped_pm_hour + 12) * 60 * 60 + (+start_time_stripped_pm_minute) * 60
                    start_time_type = "PM";
                  }

                  // get end time in seconds
                  end_time_stripped_am = end_time_stripped.split("AM")
                  if (end_time_stripped_am.length > 1) {
                    // am
                    end_time_stripped_am_hour = end_time_stripped_am[0].split(":")[0];
                    end_time_stripped_am_minute = end_time_stripped_am[0].split(":")[1];
                    end_time_seconds = (+end_time_stripped_am_hour) * 60 * 60 + (+end_time_stripped_am_minute) * 60
                    end_time_type = "AM";
                  } else {
                    // pm
                    end_time_stripped_pm = end_time_stripped.split("PM")
                    end_time_stripped_pm_hour = end_time_stripped_pm[0].split(":")[0];
                    end_time_stripped_pm_minute = end_time_stripped_pm[0].split(":")[1];
                    end_time_seconds = (+end_time_stripped_pm_hour + 12) * 60 * 60 + (+end_time_stripped_pm_minute) * 60
                    end_time_type = "PM";
                  }

                  var slot_start_time_stripped_am, slot_start_time_stripped_am_hour
                  , slot_start_time_stripped_am_minute;

                  var slot_start_time_stripped_pm, slot_start_time_stripped_pm_hour
                  , slot_start_time_stripped_pm_minute;

                  var slot_end_time_stripped_am, slot_end_time_stripped_am_hour
                  , slot_end_time_stripped_am_minute;

                  var slot_end_time_stripped_pm, slot_end_time_stripped_pm_hour
                  , slot_end_time_stripped_pm_minute;

                  var slot_start_time_seconds , slot_start_time_type;
                  var slot_end_time_seconds , slot_end_time_type;

                  for(var i=0; i<slots.length; i++) {

                    // var start_time_final_in_seconds, end_time_final_in_seconds;
                    var slot_start_time_stripped = slots[i]["start_time"].replace(/\s/g, '');
                    var slot_end_time_stripped = slots[i]["end_time"].replace(/\s/g, '');

                    // get start time in seconds
                    slot_start_time_stripped_am = slot_start_time_stripped.split("AM")
                    if (slot_start_time_stripped_am.length > 1) {
                      // am
                      slot_start_time_stripped_am_hour = slot_start_time_stripped_am[0].split(":")[0];
                      slot_start_time_stripped_am_minute = slot_start_time_stripped_am[0].split(":")[1];
                      slot_start_time_seconds = (+slot_start_time_stripped_am_hour) * 60 * 60 + (+slot_start_time_stripped_am_minute) * 60
                      slot_start_time_type = "AM"
                    } else {
                      // pm
                      slot_start_time_stripped_pm = slot_start_time_stripped.split("PM")
                      slot_start_time_stripped_pm_hour = slot_start_time_stripped_pm[0].split(":")[0];
                      slot_start_time_stripped_pm_minute = slot_start_time_stripped_pm[0].split(":")[1];
                      slot_start_time_seconds = (+slot_start_time_stripped_pm_hour + 12) * 60 * 60 + (+slot_start_time_stripped_pm_minute) * 60
                      slot_start_time_type = "PM"
                    }

                    // get end time in seconds
                    slot_end_time_stripped_am = slot_end_time_stripped.split("AM")
                    if (end_time_stripped_am.length > 1) {
                      // am
                      slot_end_time_stripped_am_hour = slot_end_time_stripped_am[0].split(":")[0];
                      slot_end_time_stripped_am_minute = slot_end_time_stripped_am[0].split(":")[1];
                      slot_end_time_seconds = (+slot_end_time_stripped_am_hour) * 60 * 60 + (+slot_end_time_stripped_am_minute) * 60
                      slot_end_time_type = "AM"
                    } else {
                      // pm
                      slot_end_time_stripped_pm = slot_end_time_stripped.split("PM")
                      slot_end_time_stripped_pm_hour = slot_end_time_stripped_pm[0].split(":")[0];
                      slot_end_time_stripped_pm_minute = slot_end_time_stripped_pm[0].split(":")[1];
                      slot_end_time_seconds = (+slot_end_time_stripped_pm_hour + 12) * 60 * 60 + (+slot_end_time_stripped_pm_minute) * 60
                      slot_end_time_type = "PM"
                    }

                    // if the clicked day is equal to any of the week days of each slot
                    if (slots[i]["week_day"] === week_day) {
                      
                      // compare company start/end time with product current slot start/end time
                      if (
                        (slot_start_time_seconds >= start_time_seconds)
                        && (end_time_seconds >= slot_end_time_seconds)
                        ) {

                        slots_to_display.push(slots[i])
                        
                      }

                    }

                    if (slots[i]["week_day"] === 0) {
                      // 0 = everyday

                      // compare company start/end time with product current slot start/end time
                      if (
                        (slot_start_time_seconds >= start_time_seconds)
                        && (end_time_seconds >= slot_end_time_seconds)
                        ) {

                        slots_to_display.push(slots[i])
                        
                      }

                    }
                    
                  }


                  // console.log(slots_to_display)
                  // hourlyData.rental_additional_hourly_price

                  slotPriceItems(slots_to_display)

                  // put the selected day's time slots and price combined items under calendar
                  function slotPriceItems(slots_to_display) {
                    var $rental_intervals = $("#rental_intervals");
                    $rental_intervals.attr("data-slots", JSON.stringify(slots_to_display))
                    $rental_intervals.attr("data-selected-day", selectedDay)

                    var $item;
                    var $price;
                    $rental_intervals.html("")
                    if (slots_to_display.length > 0) {
                      for(var i=0; i<slots_to_display.length; i++) {
                        $price = slots_to_display[i]['sale_price'] !== 0 ? slots_to_display[i]['sale_price'] : slots_to_display[i]['price']
                        $item = "<div id='_rental_"+i+"' data-slot-id='"+slots_to_display[i]['id']+"'  data-start-time='"+slots_to_display[i]['start_time']+"' data-end-time='"+slots_to_display[i]['end_time']+"' data-price='"+$price+"' class='rental_slot_price'> "+ slots_to_display[i]['start_time'] +" - "+ slots_to_display[i]['end_time'] +" ($"+ $price +") </div>";
                        $rental_intervals.append($item)
                      }
                    } else {
                      $rental_intervals.html("<p>No time slots on this day!</p>")
                    }
                   
                  }
                 
                  
                }

  
              }


            }
            
            // console.log("end_time:", end_time)
            // console.log("start_time:", start_time)
            // console.log("week_day:", week_day)

          };
        }
       
        $rental_date_hourly.calentim(config)
        hourlyCalendar = $rental_date_hourly.data('calentim');
       
      }
    }



    // collecting a day's selected time intervals(with prices) data
    var selected_times_prices = [];
    $(document).on({
      'click' : function(e) {
        var item = $("#"+e.target.id);
        var total ;
        var id = e.target.id.replace(/\D/g, "")
        if (!item.hasClass('rental_clicked')) {

          item.addClass("rental_clicked")
          selected_times_prices.push({
            'id' : id,
            'time' : e.currentTarget.dataset.time,
            'price' : e.currentTarget.dataset.price
          })

          item.prevAll().each(function() {
            var $this = $(this)
            if (!$this.hasClass('rental_clicked')) {
              var id = $this.attr("id").replace(/\D/g, "")
              selected_times_prices.unshift({
                'id' : id,
                'time' : $this.data('time'),
                'price' : $this.data('price')
              })

              $this.addClass("rental_clicked")
            }
          }) 

        } else {
          
          item.removeClass("rental_clicked")
          selected_times_prices.find((o, i) => {
            if (o.id === id) {
              selected_times_prices.splice(i, 1);
              return true; // stop searching
            }
          });

          item.nextAll().each(function() {
            var $this = $(this)
            if ($this.hasClass('rental_clicked')) {
              id = $this.attr("id").replace(/\D/g, "")
              selected_times_prices.find((o, i) => {
                if (o.id === id) {
                  selected_times_prices.splice(i, 1);
                  return true; // stop searching
                }
              });

              $this.removeClass("rental_clicked")
            }
          }) 

        }

        // sorting array numerically
        selected_times_prices.sort(function(a, b) { 
          return a.id > b.id;
        });

        // send data to server to save in a cookie
        total = selected_times_prices.length * (+e.currentTarget.dataset.price);
        var $rental_intervals = $("#rental_intervals");

        saveIntervalsData(
            total,
            selected_times_prices,
            $rental_intervals.data('times-filtered'),
            $("#rental_date_hourly").data('product-id'),
            $rental_intervals.data('selected-day'),
            "rental_by_interval",
            rental_interval_steps,
            rental_interval
        );

      }
    }, '.rental_interval_price')


    // collecting a day's selected time slots(with prices) data
    var selected_times_prices = []
    $(document).on({
      'click' : function(e) {
        var item = $("#"+e.target.id);
        var total;
        var id = e.target.id.replace(/\D/g, "")
        if (!item.hasClass('rental_clicked')) {

          item.addClass("rental_clicked")
          selected_times_prices.push({
            'id' : id,
            'start_time' : e.currentTarget.dataset.startTime,
            'end_time' : e.currentTarget.dataset.endTime,
            'price' : e.currentTarget.dataset.price
          })

          item.siblings().each(function() {
            var $this = $(this)
            if ($this.hasClass('rental_clicked')) {
              id = $this.attr("id").replace(/\D/g, "")
              selected_times_prices.find((o, i) => {
                if (o.id === id) {
                  selected_times_prices.splice(i, 1);
                  return true; // stop searching
                }
              });

              $this.removeClass("rental_clicked")
            }
          }) 


        } else {
          
          item.removeClass("rental_clicked")
          selected_times_prices.find((o, i) => {
            if (o.id === id) {
              selected_times_prices.splice(i, 1);
              return true; // stop searching
            }
          });

        }

        // sorting array numerically
        selected_times_prices.sort(function(a, b) { 
          return a.id > b.id;
        });

        // console.log("selected_times_prices", selected_times_prices)
        // console.log("e.currentTarget.dataset.slotId", e.currentTarget.dataset.slotId)

        // send data to server to save in a cookie
        total = e.currentTarget.dataset.price
        let $rental_intervals = $("#rental_intervals");
        saveIntervalsData(
            total,
            selected_times_prices,
            $rental_intervals.data('slots'),
            $("#rental_date_hourly").data('product-id'),
            $rental_intervals.data('selected-day'),
            "rental_by_slot",
            0,
            0,
            e.currentTarget.dataset.slotId
        );

      }
    }, '.rental_slot_price')



    // send data to the server side to save in a cookie
    function saveIntervalsData(total, selected_times_prices, times, product_id, selected_day, type, interval_steps
      , rental_interval, rental_time_slot_id) {

      start = hourlyCalendar.config.startDate;
      startDateServer = start.format(serverDateFormat);

      $.ajax({
        type: 'POST',
        url: rentalObj.url,
        dataType: 'JSON',
        data: {
          action: 'rental_collect_hourly_product_data',
          type: type ,
          total: total,
          selected_times_prices: selected_times_prices,
          times: times, // intervals / time slots
          product_id: product_id,
          selected_day: selected_day,
          start_date: startDateServer,
          interval_steps: type === "rental_by_interval" ? interval_steps : "",
          rental_interval: type === "rental_by_interval" ? rental_interval : "",
          time_slot_id: type === "rental_by_slot" ? rental_time_slot_id : "",
        },
        success: function(data) {
          // console.log("success save:", data)

          // unset_interval_data()
        },
        error: function(data) {
          console.log("error:", data )

          // unset_interval_data()
        },
        complete: function() {

          // unset_interval_data()
        }
      });
      
    }


    // function unset_interval_data() {
    //   // interval unset
    //   sessionStorage.setItem("clicked_interval_"+product_id, 0)
    //   sessionStorage.setItem("clicked_interval_total_"+product_id, 0)
    //   sessionStorage.setItem("clicked_interval_selected_times_prices_"+product_id, "")
    //   // slot unset
    //   sessionStorage.setItem("clicked_slot_"+product_id, 0)
    //   sessionStorage.setItem("clicked_slot_total_"+product_id, 0)
    //   sessionStorage.setItem("clicked_slot_selected_times_prices_"+product_id, "")
    //   sessionStorage.setItem("clicked_slot_id_"+product_id, 0)
    // }



    // check to see if customer selected any intervals
    $(document).on("click", ".rental-hourly-modal-submit", function(e) {
      // if (!$("#rental_intervals").find('#_rental_0').hasClass("rental_clicked")) {
      if (!$("#rental_intervals").find('div[id^=_rental_]').hasClass("rental_clicked")) {
        var $woo_breadcrumb = $(".woocommerce-breadcrumb");
        if ( !($woo_breadcrumb.siblings('.rntp-notification').length > 0) ) {
          errorBox = '<div class="rntp-notification rntp-notification--error" style="margin-top:1rem">' +
            '<a href class="rntp-notification__close color--error">×</a>' +
            '<div class="rntp-notification__status rntp-bg--gradient-red">' +
              ' &times;' +
            '</div>' +
            '<div class="rntp-notification__content">' +
                '<h4 class="rntp-notification__title"> Rental By Interval Product </h4>' +
                '<p class="rntp-notification__text"> Please, select from the provided intervals. </p>' +
            '</div>' +
          '</div>';
          $woo_breadcrumb.after(errorBox);
        }
        
        e.preventDefault();
        e.stopImmediatePropagation();

        $modal_holder.data('modal_is_open', 1).show('slow');
        displayHourlyCalendar()

      } else {
        // send selected data


        // var interval_item
        // var slot_item
        // product_id = $("#rental_date_hourly").data('product-id')
        // interval_item = sessionStorage.getItem("clicked_interval_"+product_id)
        // slot_item = sessionStorage.getItem("clicked_slot_"+product_id)

        // if (interval_item !== null && interval_item === 1) {
        //   // it is interval item
          
        //   var selected_time_prices = sessionStorage.getItem("clicked_interval_selected_times_prices_"+product_id)
        //   var total = sessionStorage.getItem("clicked_interval_total_"+product_id)

        //   saveIntervalsData(total, selected_time_prices, $("#rental_intervals").data('times-filtered'), product_id,
        //   $("#rental_intervals").data('selected-day'), "rental_by_interval", rental_interval_steps, rental_interval)

        //   // sessionStorage.setItem("clicked_interval_"+product_id, 0)
        //   // sessionStorage.setItem("clicked_interval_total_"+product_id, 0)
        //   // sessionStorage.setItem("clicked_interval_selected_times_prices_"+product_id, "")

        // } else if (slot_item !== null && slot_item === 1) {
        //   // it is slot item

        //   var total = sessionStorage.getItem("clicked_slot_total_"+product_id)
        //   var selected_times_prices = sessionStorage.getItem("clicked_slot_selected_times_prices_"+product_id)
        //   var slotId = sessionStorage.getItem("clicked_slot_id_"+product_id)

        //   saveIntervalsData(total, selected_times_prices, $("#rental_intervals").data('slots'), product_id,
        //   $("#rental_intervals").data('selected-day'), "rental_by_slot", 0, 0, slotId)

        //   // sessionStorage.setItem("clicked_slot_"+product_id, 0)
        //   // sessionStorage.setItem("clicked_slot_total_"+product_id, 0)
        //   // sessionStorage.setItem("clicked_slot_selected_times_prices_"+product_id, "")
        //   // sessionStorage.setItem("clicked_slot_id_"+product_id, 0)

        // }


      }
    })

  }


  $( 'input.variation_id' ).change( function(){
    if( '' !== $(this).val() ) {
        var var_id = $(this).val();

        $.ajax({
            type: 'POST',
            url: rentalObj.url,
            dataType: 'JSON',
            data: {
                action: 'rental_get_hourly_product_variation_id',
                var_id: var_id,
            },
            success: function(data) {

              // console.log('disabled_week_days:', data.disabled_week_days)
              // console.log('work_days:', data.work_days)
              // console.log('hourly:', data.hourly)
              // console.log(' data.product_id:',  data.product_id)

              var $rental_date_hourly = $("#rental_date_hourly");
              $rental_date_hourly.data('disabled-week-days', data.disabled_week_days)
              $rental_date_hourly.data('work-days', data.work_days)
              $rental_date_hourly.data('hourly', data.hourly)
              $rental_date_hourly.data('product-id', data.product_id)
              
              $('button[name=add-to-cart]').val(data.product_id)
              
            },
            error: function(data) {
              console.log('error:', data )
            },
            complete: function() {
    
            }
          });
    }
  });

  // hide calculate shipping of miles based shipping in hourly mode
  if ($(".woocommerce-shipping-totals").length > 0) {
    $(this).find(".woocommerce-shipping-calculator").hide();
  }

});
