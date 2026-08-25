/**
 * Rental Checkout Photo Upload Module
 *
 * jQuery-based module for handling photo uploads on the checkout page.
 *
 * Features:
 * - Drag & drop support
 * - Click to browse
 * - File validation (type, size, count)
 * - Thumbnail preview with remove
 * - AJAX upload to server
 * - Hidden field management for order submission
 * - Accessible keyboard support
 *
 * @package    Rentopian_Sync
 * @subpackage Assets/JS
 * @since      2.14.0
 */
(function ($) {
    'use strict';

    /**
     * Default configuration - overridden by wp_localize_script data
     */
    var defaults = {
        maxFiles: 5,
        maxSizeMB: 2,
        allowedTypes: ['image/jpeg', 'image/jpg', 'image/png'],
        allowedExtensions: ['jpg', 'jpeg', 'png'],
        ajaxUrl: '',
        nonce: '',
        i18n: {
            dropzoneText: 'Drag & drop images here or click to browse',
            uploading: 'Uploading...',
            uploadSuccess: 'Photo uploaded successfully.',
            uploadFailed: 'Upload failed. Please try again.',
            maxFilesReached: 'Maximum number of photos reached.',
            fileTooLarge: 'File is too large. Maximum size is {max} MB.',
            invalidType: 'Invalid file type. Accepted: JPG, JPEG, PNG.',
            photoCount: '{count} of {max} photos',
            clearAll: 'Clear all',
            removePhoto: 'Remove photo',
            networkError: 'Network error. Please check your connection.',
        }
    };

    /**
     * RentalPhotoUpload constructor
     *
     * @param {object} config Configuration from wp_localize_script
     */
    function RentalPhotoUpload(config) {
        this.config = $.extend(true, {}, defaults, config || {});
        this.photos = [];
        this.uploading = false;

        // DOM references
        this.$wrapper    = $('#rental-photo-upload-wrapper');
        this.$dropzone   = $('#rental-photo-upload-dropzone');
        this.$input      = $('#rental-photo-upload-input');
        this.$preview    = $('#rental-photo-upload-preview');
        this.$thumbnails = $('#rental-photo-upload-thumbnails');
        this.$count      = $('#rental-photo-upload-count');
        this.$errors     = $('#rental-photo-upload-errors');
        this.$clearAll   = $('#rental-photo-upload-clear-all');
        this.$hiddenFields = $('#rental-photo-upload-hidden-fields');

        if (this.$wrapper.length === 0) {
            return; // Component not present on page
        }

        this.init();
    }

    /**
     * Initialize the module
     */
    RentalPhotoUpload.prototype.init = function () {
        var self = this;

        // Block uploads until we've checked the server for existing session photos.
        this.ready = false;

        this.bindEvents();
        this.updateUI();

        // On page load, ask the server for any photos already in the session.
        // If photos exist (user uploaded then refreshed the page), restore them
        // into the JS state so the user sees their thumbnails and can continue.
        // If no photos exist, we're immediately ready for fresh uploads.
        this.restoreSessionPhotos(function () {
            self.ready = true;
        });
    };

    /**
     * Restore server-side session photos into the JS state on page load.
     *
     * This fixes the "reload loses photos" bug: previously, init() would
     * NUKE the server session on every page load. Now, it restores them
     * into this.photos[] with thumbnails, so the user sees exactly what
     * they uploaded before the reload.
     *
     * Session clearing is done ONLY by:
     *   - attach_photos_to_order() after order placement
     *   - The thank-you page handler
     *
     * @param {Function} onReady Called when restoration (or empty check) completes.
     */
    RentalPhotoUpload.prototype.restoreSessionPhotos = function (onReady) {
        var self = this;
        $.ajax({
            url: this.config.ajaxUrl,
            type: 'POST',
            data: {
                action: 'rental_get_session_photos',
                nonce: this.config.nonce
            },
            success: function (response) {
                if (response && response.success && response.data && response.data.photos) {
                    var serverPhotos = response.data.photos;
                    if (serverPhotos.length > 0) {
                        // Restore each photo into JS state + render thumbnail + hidden field
                        for (var i = 0; i < serverPhotos.length; i++) {
                            var photo = serverPhotos[i];
                            if (!photo || !photo.id) continue;
                            self.photos.push(photo);
                            self.addThumbnail(photo);
                            self.addHiddenField(photo.id);
                        }
                        self.updateUI();
                    }
                }
                if (typeof onReady === 'function') onReady();
            },
            error: function () {
                // Network error — proceed anyway, user can still upload fresh
                if (typeof onReady === 'function') onReady();
            }
        });
    };

    /**
     * Bind all event handlers
     */
    RentalPhotoUpload.prototype.bindEvents = function () {
        var self = this;

        // Dropzone click -> trigger file input
        this.$dropzone.on('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            if (!self.uploading) {
                self.$input.trigger('click');
            }
        });

        // Keyboard accessibility for dropzone
        this.$dropzone.on('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                self.$input.trigger('click');
            }
        });

        // File input change
        this.$input.on('change', function () {
            var files = this.files;
            if (files && files.length > 0) {
                self.handleFiles(files);
                // Reset input so same file can be re-selected
                $(this).val('');
            }
        });

        // Drag & drop events
        this.$dropzone
            .on('dragenter dragover', function (e) {
                e.preventDefault();
                e.stopPropagation();
                $(this).addClass('rental-photo-upload-dropzone--active');
            })
            .on('dragleave drop', function (e) {
                e.preventDefault();
                e.stopPropagation();
                $(this).removeClass('rental-photo-upload-dropzone--active');
            })
            .on('drop', function (e) {
                var files = e.originalEvent.dataTransfer.files;
                if (files && files.length > 0) {
                    self.handleFiles(files);
                }
            });

        // Clear all button
        this.$clearAll.on('click', function (e) {
            e.preventDefault();
            self.clearAllPhotos();
        });

        // Delegate: remove individual photo
        this.$thumbnails.on('click', '.rental-photo-upload-thumb-remove', function (e) {
            e.preventDefault();
            e.stopPropagation();
            var photoId = $(this).closest('.rental-photo-upload-thumb').data('photo-id');
            self.removePhoto(photoId);
        });

        // Prevent form submission while uploading
        $('form.checkout').on('checkout_place_order', function () {
            if (self.uploading) {
                self.showError(self.config.i18n.uploading);
                return false;
            }
            return true;
        });
    };

    /**
     * Process selected files
     *
     * @param {FileList} files
     */
    RentalPhotoUpload.prototype.handleFiles = function (files) {
        // Block uploads until stale server session is cleared
        if (!this.ready) {
            this.showError('Preparing upload, please try again in a moment.');
            return;
        }

        var filesToUpload = [];

        // Validate each file
        for (var i = 0; i < files.length; i++) {
            var file = files[i];
            var validation = this.validateFile(file);

            if (validation !== true) {
                this.showError(validation);
                continue;
            }

            // Check max count
            if (this.photos.length + filesToUpload.length >= this.config.maxFiles) {
                this.showError(this.config.i18n.maxFilesReached);
                break;
            }

            filesToUpload.push(file);
        }

        // Upload valid files sequentially
        if (filesToUpload.length > 0) {
            this.clearError();
            this.uploadFilesSequentially(filesToUpload, 0);
        }
    };

    /**
     * Validate a single file
     *
     * @param {File} file
     * @return {true|string} True if valid, error message string otherwise
     */
    RentalPhotoUpload.prototype.validateFile = function (file) {
        // Check type
        if (this.config.allowedTypes.indexOf(file.type) === -1) {
            var ext = file.name.split('.').pop().toLowerCase();
            if (this.config.allowedExtensions.indexOf(ext) === -1) {
                return this.config.i18n.invalidType;
            }
        }

        // Check size
        var maxBytes = this.config.maxSizeMB * 1024 * 1024;
        if (file.size > maxBytes) {
            return this.config.i18n.fileTooLarge.replace('{max}', this.config.maxSizeMB);
        }

        // Check count
        if (this.photos.length >= this.config.maxFiles) {
            return this.config.i18n.maxFilesReached;
        }

        return true;
    };

    /**
     * Upload files one at a time to avoid overwhelming the server
     *
     * @param {Array} files
     * @param {number} index Current file index
     */
    RentalPhotoUpload.prototype.uploadFilesSequentially = function (files, index) {
        if (index >= files.length) {
            this.uploading = false;
            this.updateUI();
            return;
        }

        this.uploading = true;
        var self = this;
        var file = files[index];

        // Show a temporary uploading thumbnail
        var tempId = 'temp_' + Date.now() + '_' + index;
        this.addUploadingThumbnail(tempId, file);

        // Create FormData
        var formData = new FormData();
        formData.append('action', 'rental_upload_checkout_photo');
        formData.append('nonce', this.config.nonce);
        formData.append('rental_checkout_photo', file);

        $.ajax({
            url: this.config.ajaxUrl,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            timeout: 30000,
            success: function (response) {
                self.removeUploadingThumbnail(tempId);

                if (response.success && response.data && response.data.photo) {
                    var photo = response.data.photo;
                    if (response.data.duplicate) {
                        var dupMsg = (response.data.message) ? response.data.message : 'This photo was already added.';
                        self.showError(dupMsg);
                    } else {
                        self.photos.push(photo);
                        self.addThumbnail(photo);
                        self.addHiddenField(photo.id);
                    }
                } else {
                    var msg = (response.data && response.data.message)
                        ? response.data.message
                        : self.config.i18n.uploadFailed;
                    self.showError(msg);
                }

                // Continue with next file
                self.uploadFilesSequentially(files, index + 1);
            },
            error: function (xhr, status) {
                self.removeUploadingThumbnail(tempId);

                var msg = self.config.i18n.networkError;
                if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                    msg = xhr.responseJSON.data.message;
                }
                self.showError(msg);

                // Continue with next file despite error
                self.uploadFilesSequentially(files, index + 1);
            }
        });
    };

    /**
     * Add an "uploading" placeholder thumbnail
     *
     * @param {string} tempId
     * @param {File} file
     */
    RentalPhotoUpload.prototype.addUploadingThumbnail = function (tempId, file) {
        var $thumb = $(
            '<div class="rental-photo-upload-thumb rental-photo-upload-thumb--uploading" data-temp-id="' + tempId + '">' +
                '<div class="rental-photo-upload-thumb-loading">' +
                    '<div class="rental-photo-upload-spinner"></div>' +
                '</div>' +
                '<span class="rental-photo-upload-thumb-name">' + this.escapeHtml(this.truncateFilename(file.name, 20)) + '</span>' +
            '</div>'
        );

        // Show local preview if possible
        if (window.FileReader) {
            var reader = new FileReader();
            reader.onload = function (e) {
                $thumb.find('.rental-photo-upload-thumb-loading').css({
                    'background-image': 'url(' + e.target.result + ')',
                    'background-size': 'cover',
                    'background-position': 'center'
                });
            };
            reader.readAsDataURL(file);
        }

        this.$thumbnails.append($thumb);
        this.$preview.show();
        this.updateCount();
    };

    /**
     * Remove uploading placeholder
     *
     * @param {string} tempId
     */
    RentalPhotoUpload.prototype.removeUploadingThumbnail = function (tempId) {
        this.$thumbnails.find('[data-temp-id="' + tempId + '"]').remove();
    };

    /**
     * Add a completed photo thumbnail
     *
     * @param {object} photo Photo data from server
     */
    RentalPhotoUpload.prototype.addThumbnail = function (photo) {
        var $thumb = $(
            '<div class="rental-photo-upload-thumb" data-photo-id="' + this.escapeHtml(photo.id) + '">' +
                '<div class="rental-photo-upload-thumb-img" style="background-image:url(' + this.escapeHtml(photo.url) + ')"></div>' +
                '<button type="button" class="rental-photo-upload-thumb-remove" ' +
                    'aria-label="' + this.escapeHtml(this.config.i18n.removePhoto) + '" title="' + this.escapeHtml(this.config.i18n.removePhoto) + '">' +
                    '<svg width="12" height="12" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">' +
                        '<line x1="1" y1="1" x2="11" y2="11"/>' +
                        '<line x1="11" y1="1" x2="1" y2="11"/>' +
                    '</svg>' +
                '</button>' +
                '<span class="rental-photo-upload-thumb-name">' + this.escapeHtml(this.truncateFilename(photo.original_name, 20)) + '</span>' +
            '</div>'
        );

        this.$thumbnails.append($thumb);
        this.updateUI();
    };

    /**
     * Add a hidden input field for a photo ID
     *
     * @param {string} photoId
     */
    RentalPhotoUpload.prototype.addHiddenField = function (photoId) {
        var $field = $('<input type="hidden" name="rental_checkout_photo_ids[]" value="' + this.escapeHtml(photoId) + '" />');
        this.$hiddenFields.append($field);
    };

    /**
     * Remove a hidden input field for a photo ID
     *
     * @param {string} photoId
     */
    RentalPhotoUpload.prototype.removeHiddenField = function (photoId) {
        this.$hiddenFields.find('input[value="' + photoId + '"]').remove();
    };

    /**
     * Remove a photo by ID
     *
     * @param {string} photoId
     */
    RentalPhotoUpload.prototype.removePhoto = function (photoId) {
        var self = this;

        // Remove from DOM immediately for responsiveness
        this.$thumbnails.find('[data-photo-id="' + photoId + '"]').fadeOut(200, function () {
            $(this).remove();
            self.updateUI();
        });

        // Remove from local array
        this.photos = this.photos.filter(function (p) {
            return p.id !== photoId;
        });

        // Remove hidden field
        this.removeHiddenField(photoId);

        // Send single AJAX call to remove server-side
        $.post(this.config.ajaxUrl, {
            action: 'rental_remove_checkout_photo',
            nonce: this.config.nonce,
            photo_id: photoId
        });

        this.updateUI();
    };

    /**
     * Clear all photos
     */
    RentalPhotoUpload.prototype.clearAllPhotos = function () {
        var self = this;

        // Remove each photo from server
        this.photos.forEach(function (photo) {
            $.post(self.config.ajaxUrl, {
                action: 'rental_remove_checkout_photo',
                nonce: self.config.nonce,
                photo_id: photo.id
            });
        });

        this.photos = [];
        this.$thumbnails.empty();
        this.$hiddenFields.empty();
        this.clearError();
        this.updateUI();
    };

    /**
     * Update the UI state (counts, visibility)
     */
    RentalPhotoUpload.prototype.updateUI = function () {
        this.updateCount();

        // Show/hide preview section
        if (this.photos.length > 0) {
            this.$preview.show();
        } else {
            this.$preview.hide();
        }

        // Disable dropzone if max reached
        if (this.photos.length >= this.config.maxFiles) {
            this.$dropzone.addClass('rental-photo-upload-dropzone--disabled');
        } else {
            this.$dropzone.removeClass('rental-photo-upload-dropzone--disabled');
        }
    };

    /**
     * Update the photo count display
     */
    RentalPhotoUpload.prototype.updateCount = function () {
        var text = this.config.i18n.photoCount
            .replace('{count}', this.photos.length)
            .replace('{max}', this.config.maxFiles);
        this.$count.text(text);
    };

    /**
     * Show an error message
     *
     * @param {string} message
     */
    RentalPhotoUpload.prototype.showError = function (message) {
        this.$errors.html('<p>' + this.escapeHtml(message) + '</p>').show();

        // Auto-dismiss after 6 seconds
        var self = this;
        clearTimeout(this._errorTimeout);
        this._errorTimeout = setTimeout(function () {
            self.clearError();
        }, 6000);
    };

    /**
     * Clear the error display
     */
    RentalPhotoUpload.prototype.clearError = function () {
        this.$errors.empty().hide();
        clearTimeout(this._errorTimeout);
    };

    /**
     * Escape HTML entities
     *
     * @param {string} str
     * @return {string}
     */
    RentalPhotoUpload.prototype.escapeHtml = function (str) {
        if (!str) return '';
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    };

    /**
     * Truncate a filename for display
     *
     * @param {string} name
     * @param {number} maxLength
     * @return {string}
     */
    RentalPhotoUpload.prototype.truncateFilename = function (name, maxLength) {
        if (!name || name.length <= maxLength) return name;
        var ext = name.split('.').pop();
        var base = name.substring(0, name.lastIndexOf('.'));
        var truncated = base.substring(0, maxLength - ext.length - 4) + '...' + ext;
        return truncated;
    };

    // =========================================================================
    // Initialize on document ready
    // =========================================================================
    $(document).ready(function () {
        // Only initialize if the component is on the page and config is available
        if ($('#rental-photo-upload-wrapper').length > 0 && typeof rentalPhotoUploadConfig !== 'undefined') {
            window.rentalPhotoUploadInstance = new RentalPhotoUpload(rentalPhotoUploadConfig);
        }
    });

})(jQuery);
