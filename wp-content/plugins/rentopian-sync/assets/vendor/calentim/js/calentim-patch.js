/**
 * Calentim Patch - Fix for calendar showing wrong date on reopen
 * 
 * ISSUES FIXED:
 * 1. Year corruption: Format without year causes moment to default to current year
 * 2. Date corruption: End calendar gets start calendar's date due to fetchInputs
 * 3. Wrong day highlighted: Even in same month, wrong day was selected
 * 
 * FIX: Completely bypass fetchInputs corruption by saving/restoring dates
 * and ALWAYS forcing a redraw after restoration.
 * 
 */

;(function($, window, document, undefined) {
    'use strict';
    
    var originalCalentim = $.fn.calentim;
    
    if (!originalCalentim) {
        console.error('Calentim Patch v3: calentim.js must be loaded before this patch');
        return;
    }
    
    $.fn.calentim = function(options) {
        // Call the original calentim to create the instance
        var result = originalCalentim.apply(this, arguments);
        
        // Now patch each instance
        this.each(function() {
            var $elem = $(this);
            var instance = $elem.data('calentim');
            var elemId = $elem.attr('id') || $elem.attr('name') || 'unknown';
            
            if (instance && !instance.__patchAppliedV3) {
                // Mark as patched to avoid double-patching
                instance.__patchAppliedV3 = true;
                instance.__elemId = elemId;
                
                // Override showDropdown with the fix
                var originalShowDropdown = instance.showDropdown;
                
                instance.showDropdown = function(e) {
                    var self = this;
                    
                    // Save ALL relevant dates BEFORE showDropdown corrupts them
                    var savedStartDate = null;
                    var savedEndDate = null;
                    
                    if (this.config.startDate && moment.isMoment(this.config.startDate) && this.config.startDate.isValid()) {
                        savedStartDate = this.config.startDate.clone();
                    }
                    if (this.config.endDate && moment.isMoment(this.config.endDate) && this.config.endDate.isValid()) {
                        savedEndDate = this.config.endDate.clone();
                    }
                    
                    // Call original showDropdown (this is where corruption happens via fetchInputs)
                    var returnVal = originalShowDropdown.apply(this, arguments);
                    
                    // ALWAYS restore saved dates if we had them
                    var didRestore = false;
                    
                    if (savedStartDate) {
                        // Check if it was corrupted (different day, month, year, hour, or minute)
                        if (!this.config.startDate || !this.config.startDate.isSame(savedStartDate)) {
                            this.config.startDate = savedStartDate.clone();
                            didRestore = true;
                        }
                    }
                    
                    if (savedEndDate) {
                        if (!this.config.endDate || !this.config.endDate.isSame(savedEndDate)) {
                            this.config.endDate = savedEndDate.clone();
                            didRestore = true;
                        }
                    }
                    
                    // If we restored anything, force the calendar to show the correct month and day
                    if (didRestore && savedStartDate) {
                        // Set currentDate to the selected date's month
                        this.globals.currentDate = savedStartDate.clone();
                        
                        // Force complete redraw
                        this.reDrawCalendars();
                    }
                    
                    return returnVal;
                };
                
                // Completely override fetchInputs to prevent ANY corruption
                var originalFetchInputs = instance.fetchInputs;
                
                instance.fetchInputs = function() {
                    // Save the current dates BEFORE fetchInputs runs
                    var savedStartDate = null;
                    var savedEndDate = null;
                    var hadValidStart = false;
                    var hadValidEnd = false;
                    
                    if (this.config.startDate && moment.isMoment(this.config.startDate) && this.config.startDate.isValid()) {
                        savedStartDate = this.config.startDate.clone();
                        hadValidStart = true;
                    }
                    if (this.config.endDate && moment.isMoment(this.config.endDate) && this.config.endDate.isValid()) {
                        savedEndDate = this.config.endDate.clone();
                        hadValidEnd = true;
                    }
                    
                    // Call original fetchInputs - this will corrupt our dates
                    originalFetchInputs.apply(this, arguments);
                    
                    // If we had valid dates before, COMPLETELY restore them
                    // Don't try to be smart about it - just restore everything
                    if (hadValidStart && savedStartDate) {
                        this.config.startDate = savedStartDate.clone();
                    }
                    
                    if (hadValidEnd && savedEndDate) {
                        this.config.endDate = savedEndDate.clone();
                    }
                };
            }
        });
        
        return result;
    };
    
    // Copy over any static properties from the original
    $.extend($.fn.calentim, originalCalentim);
    
    console.log('Successfully Patched Calentim');
    
})(jQuery, window, document);
