<?php
/**
 * Rental Photo Upload Component
 *
 * Renders the photo upload field on the checkout page.
 * Allows customers to upload up to 5 inspiration/event photos
 * that get attached to the order and sent to the Rentopian core system.
 *
 * - Rendered by Rental_Checkout_Field_Renderer::render_component()
 * - Controlled via admin setting: rental_checkout_photo_upload_enabled
 * - Integrable in JSON layout builder as field "rental_photo_upload"
 *
 * @package    Rentopian_Sync
 * @subpackage Components
 * @since      2.14.0
 */

if (!defined('ABSPATH')) {
    exit;
}

// Guard: only render if the setting is enabled
if (!get_option('rental_checkout_photo_upload_enabled', 0)) {
    return;
}

$max_files    = apply_filters('rental_photo_upload_max_files', 5);
$max_size_mb  = apply_filters('rental_photo_upload_max_size_mb', 2);
$allowed_types = apply_filters('rental_photo_upload_allowed_types', array('image/jpeg', 'image/jpg', 'image/png'));
$upload_label  = get_option('rental_checkout_photo_upload_label', '');
if (empty($upload_label)) {
    $upload_label = __('Upload Inspiration Photos', 'rentopian-sync');
}
$upload_description = get_option('rental_checkout_photo_upload_description', '');
if (empty($upload_description)) {
    $upload_description = __('Share photos that capture the look and feel you\'re going for.', 'rentopian-sync');
}

$allowed_extensions_display = 'JPG, JPEG, PNG';
?>

<div id="rental-photo-upload-wrapper" class="rental-photo-upload-wrapper rentopian-component">
    <div class="rental-photo-upload-header">
        <label class="rental-photo-upload-label">
            <?php echo esc_html($upload_label); ?>
            <?php if (isset($rental_photo_upload_required) ? $rental_photo_upload_required : false) : ?>
                <span class="required" aria-hidden="true">*</span>
            <?php endif; ?>
        </label>
        <?php if (!empty($upload_description)) : ?>
            <p class="rental-photo-upload-description"><?php echo esc_html($upload_description); ?></p>
        <?php endif; ?>
    </div>

    <div id="rental-photo-upload-dropzone" class="rental-photo-upload-dropzone" role="button" tabindex="0"
         aria-label="<?php esc_attr_e('Click or drag photos here to upload', 'rentopian-sync'); ?>">
        <div class="rental-photo-upload-dropzone-content">
            <svg class="rental-photo-upload-icon" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                <polyline points="17 8 12 3 7 8"/>
                <line x1="12" y1="3" x2="12" y2="15"/>
            </svg>
            <span class="rental-photo-upload-dropzone-text">
                <?php esc_html_e('Drag & drop images here or click to browse', 'rentopian-sync'); ?>
            </span>
            <span class="rental-photo-upload-dropzone-meta">
                <?php
                printf(
                    /* translators: 1: max number of files, 2: max file size in MB, 3: allowed file types */
                    esc_html__('Up to %1$d photos · Max %2$d MB each · %3$s', 'rentopian-sync'),
                    $max_files,
                    $max_size_mb,
                    $allowed_extensions_display
                );
                ?>
            </span>
        </div>
    </div>

    <input type="file"
           id="rental-photo-upload-input"
           name="rental_checkout_photos[]"
           multiple
           accept=".jpg,.jpeg,.png"
           style="display:none;"
           aria-hidden="true" />

    <div id="rental-photo-upload-preview" class="rental-photo-upload-preview" style="display:none;">
        <div class="rental-photo-upload-preview-header">
            <span id="rental-photo-upload-count" class="rental-photo-upload-count"></span>
            <button type="button" id="rental-photo-upload-clear-all" class="rental-photo-upload-clear-all">
                <?php esc_html_e('Clear all', 'rentopian-sync'); ?>
            </button>
        </div>
        <div id="rental-photo-upload-thumbnails" class="rental-photo-upload-thumbnails"></div>
    </div>

    <div id="rental-photo-upload-errors" class="rental-photo-upload-errors" style="display:none;" role="alert"></div>

    <!-- Hidden inputs to track uploaded file IDs for order submission -->
    <div id="rental-photo-upload-hidden-fields"></div>
</div>
