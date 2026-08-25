<?php
/**
 * Admin View: Checkout Layout Builder
 *
 * This template renders the admin page for managing checkout layout.
 * Tabs:
 * - Visual Builder: Drag-and-drop interface
 * - Order Display Settings: Review order display toggles
 * - Marketing Section: Marketing content configuration
 * - Thank You Message: Order confirmation message editor
 * - JSON Editor: Manual JSON configuration
 * - Templates: Layout template management
 *
 * Available variables:
 * - $is_enabled (bool) - Whether custom layout is enabled (based on settings)
 * - $current_layout (array) - Current layout configuration
 * - $fields (array) - Available fields from registry
 * - $field_groups (array) - Field groups from registry
 * - $requirements_status (array) - Status of each requirement for enabling
 * - $mandatory_fields (array) - List of mandatory field IDs
 * - $review_display_settings (array) - Review order display settings
 *
 * @package    Rentopian_Sync
 * @subpackage Rentopian_Sync/templates/checkout
 * @since      2.13.0
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

// Get settings page URL
$settings_url = admin_url('admin.php?page=rentopian-settings');
?>

<div class="wrap rental-checkout-layout-wrap">
    <h1 class="wp-heading-inline">
        <?php esc_html_e('Checkout Layout Builder', 'rentopian-sync'); ?>
    </h1>
    
    <hr class="wp-header-end">

    <?php wp_nonce_field('rental_checkout_layout_nonce', 'rental_checkout_layout_nonce_field'); ?>

    <?php if (!$is_enabled) : ?>
        <!-- Disabled State Message -->
        <div class="rental-layout-disabled-notice">
            <div class="rental-notice-icon">
                <span class="dashicons dashicons-info-outline"></span>
            </div>
            <div class="rental-notice-content">
                <h2><?php esc_html_e('Checkout Layout Builder is Disabled', 'rentopian-sync'); ?></h2>
                <p><?php esc_html_e('To enable the Checkout Layout Builder, the following settings must be configured in Rentopian Sync Settings:', 'rentopian-sync'); ?></p>
                
                <ul class="rental-requirements-list">
                    <?php foreach ($requirements_status as $req_id => $requirement) : ?>
                        <li class="<?php echo $requirement['enabled'] ? 'requirement-met' : 'requirement-unmet'; ?>">
                            <span class="dashicons <?php echo $requirement['enabled'] ? 'dashicons-yes' : 'dashicons-no-alt'; ?>"></span>
                            <span class="requirement-label"><?php echo esc_html($requirement['label']); ?></span>
                            <span class="requirement-status">
                                <?php echo $requirement['enabled'] 
                                    ? esc_html__('Enabled', 'rentopian-sync') 
                                    : esc_html__('Not Enabled', 'rentopian-sync'); ?>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
                
                <p class="rental-notice-action">
                    <a href="<?php echo esc_url($settings_url); ?>" class="button button-primary">
                        <span class="dashicons dashicons-admin-settings"></span>
                        <?php esc_html_e('Go to Rentopian Sync Settings', 'rentopian-sync'); ?>
                    </a>
                </p>
            </div>
        </div>
    <?php else : ?>
        <!-- Status Messages Container -->
        <div id="rental-layout-messages"></div>

        <!-- ================================================================
             TAB NAVIGATION
             Order: Visual Builder → Order Display → Marketing → Thank You → Templates → JSON Editor
             ================================================================ -->
        <nav class="rental-layout-tabs nav-tab-wrapper">
            <a href="#tab-visual-builder" class="nav-tab nav-tab-active" data-tab="visual-builder">
                <span class="dashicons dashicons-layout"></span>
                <?php esc_html_e('Visual Builder', 'rentopian-sync'); ?>
            </a>
            <a href="#tab-order-display" class="nav-tab" data-tab="order-display">
                <span class="dashicons dashicons-visibility"></span>
                <?php esc_html_e('Order Display Settings', 'rentopian-sync'); ?>
            </a>
            <a href="#tab-marketing-section" class="nav-tab" data-tab="marketing-section">
                <span class="dashicons dashicons-megaphone"></span>
                <?php esc_html_e('Marketing Section', 'rentopian-sync'); ?>
            </a>
            <a href="#tab-thank-you" class="nav-tab" data-tab="thank-you">
                <span class="dashicons dashicons-heart"></span>
                <?php esc_html_e('Thank You Message', 'rentopian-sync'); ?>
            </a>
            <a href="#tab-templates" class="nav-tab" data-tab="templates">
                <span class="dashicons dashicons-portfolio"></span>
                <?php esc_html_e('Templates', 'rentopian-sync'); ?>
            </a>
            <a href="#tab-json-editor" class="nav-tab" data-tab="json-editor">
                <span class="dashicons dashicons-editor-code"></span>
                <?php esc_html_e('JSON Editor', 'rentopian-sync'); ?>
            </a>
        </nav>

        <!-- ================================================================
             TAB 1: VISUAL BUILDER (default active)
             ================================================================ -->
        <div id="tab-visual-builder" class="rental-tab-content rental-tab-active">
            <div class="rvb-container">
                <!-- Header with sync info -->
                <div class="rvb-header">
                    <div class="rvb-header-left">
                        <h2><?php esc_html_e('Visual Layout Builder', 'rentopian-sync'); ?></h2>
                        <p class="description"><?php esc_html_e('Drag and drop fields to build your checkout layout. Changes here produce the same JSON used by the JSON Editor — whichever tab you save last becomes the active layout.', 'rentopian-sync'); ?></p>
                    </div>
                    <div class="rvb-header-actions">
                        <button type="button" id="rvb-load-current" class="button" title="<?php esc_attr_e('Load the current active layout into the visual builder', 'rentopian-sync'); ?>">
                            <span class="dashicons dashicons-download"></span>
                            <?php esc_html_e('Load Current Layout', 'rentopian-sync'); ?>
                        </button>
                        <button type="button" id="rvb-save-as-template" class="button" title="<?php esc_attr_e('Save current visual builder layout as a reusable template', 'rentopian-sync'); ?>">
                            <span class="dashicons dashicons-portfolio"></span>
                            <?php esc_html_e('Save as Template', 'rentopian-sync'); ?>
                        </button>
                        <button type="button" id="rvb-save" class="button button-primary button-large">
                            <span class="dashicons dashicons-saved" style="margin-top:4px;"></span>
                            <?php esc_html_e('Save Layout', 'rentopian-sync'); ?>
                        </button>
                        <span class="rental-save-status" id="rvb-save-status"></span>
                    </div>
                </div>

                <!-- Main 2-column layout: field palette left, canvas right -->
                <div class="rvb-workspace">
                    <!-- LEFT: Field Palette -->
                    <div class="rvb-palette">
                        <div class="rvb-palette-header">
                            <h3><?php esc_html_e('Available Fields', 'rentopian-sync'); ?></h3>
                            <input type="text" id="rvb-field-search" class="rvb-field-search" placeholder="<?php esc_attr_e('Search fields...', 'rentopian-sync'); ?>">
                        </div>
                        <div class="rvb-palette-groups" id="rvb-palette-groups">
                            <!-- Populated by JS from rentalCheckoutLayout.fields / fieldGroups -->
                            <p class="rvb-palette-loading"><span class="spinner is-active"></span> <?php esc_html_e('Loading fields...', 'rentopian-sync'); ?></p>
                        </div>
                    </div>

                    <!-- RIGHT: Builder Canvas -->
                    <div class="rvb-canvas-wrapper">
                        <div class="rvb-canvas" id="rvb-canvas">
                            <!-- Sections rendered by JS -->
                            <div class="rvb-canvas-empty" id="rvb-canvas-empty">
                                <span class="dashicons dashicons-layout" style="font-size:48px;color:#ccc;"></span>
                                <p><?php esc_html_e('Click "Load Current Layout" to start, or add a section below.', 'rentopian-sync'); ?></p>
                            </div>
                        </div>
                        <div class="rvb-canvas-actions">
                            <button type="button" id="rvb-add-section" class="button">
                                <span class="dashicons dashicons-plus-alt2"></span>
                                <?php esc_html_e('Add Section', 'rentopian-sync'); ?>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Legend -->
                <div class="rvb-legend">
                    <span class="rvb-legend-item"><span class="rvb-badge rvb-badge--mandatory">M</span> <?php esc_html_e('Mandatory (always required)', 'rentopian-sync'); ?></span>
                    <span class="rvb-legend-item"><span class="rvb-badge rvb-badge--required">R</span> <?php esc_html_e('Required (set via layout)', 'rentopian-sync'); ?></span>
                    <span class="rvb-legend-item"><span class="rvb-badge rvb-badge--optional">O</span> <?php esc_html_e('Optional', 'rentopian-sync'); ?></span>
                    <span class="rvb-legend-item rvb-legend-widths"><?php esc_html_e('Column widths: 12=full, 6=half, 4=third, 3=quarter', 'rentopian-sync'); ?></span>
                    <span class="rvb-legend-item rvb-legend-debug" style="margin-left:auto;">
                        <label style="display:inline-flex;align-items:center;gap:5px;cursor:pointer;font-size:12px;color:#646970;">
                            <input type="checkbox" id="rvb-debug-toggle" <?php checked(get_option('rental_checkout_debug_mode', '0'), '1'); ?> style="margin:0;" />
                            <?php esc_html_e('JS Debug Mode', 'rentopian-sync'); ?>
                        </label>
                    </span>
                </div>

                <!-- Footer actions (mirrors header) -->
                <div class="rvb-footer">
                    <div class="rvb-footer-actions">
                        <button type="button" class="button rvb-footer-load-current" title="<?php esc_attr_e('Load the current active layout into the visual builder', 'rentopian-sync'); ?>">
                            <span class="dashicons dashicons-download"></span>
                            <?php esc_html_e('Load Current Layout', 'rentopian-sync'); ?>
                        </button>
                        <button type="button" class="button rvb-footer-reset-default" title="<?php esc_attr_e('Reset to the default hard-coded layout (validated before applying)', 'rentopian-sync'); ?>">
                            <span class="dashicons dashicons-image-rotate"></span>
                            <?php esc_html_e('Reset to Default', 'rentopian-sync'); ?>
                        </button>
                        <button type="button" class="button rvb-footer-save-template" title="<?php esc_attr_e('Save current visual builder layout as a reusable template', 'rentopian-sync'); ?>">
                            <span class="dashicons dashicons-portfolio"></span>
                            <?php esc_html_e('Save as Template', 'rentopian-sync'); ?>
                        </button>
                        <button type="button" class="button button-primary button-large rvb-footer-save" title="<?php esc_attr_e('Save Layout', 'rentopian-sync'); ?>">
                            <span class="dashicons dashicons-saved" style="margin-top:4px;"></span>
                            <?php esc_html_e('Save Layout', 'rentopian-sync'); ?>
                        </button>
                        <span class="rental-save-status rvb-footer-status"></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- ================================================================
             TAB 2: ORDER DISPLAY SETTINGS
             (Moved from inside JSON Editor tab to its own tab)
             ================================================================ -->
        <div id="tab-order-display" class="rental-tab-content">
            <div class="rental-order-display-container">
                <div class="rental-order-display-header">
                    <h2><?php esc_html_e('Order Display Settings', 'rentopian-sync'); ?></h2>
                    <p class="description">
                        <?php esc_html_e('Toggle which items appear in the checkout review order column. Disabled items will also be hidden on the thank-you page, order details, and order emails.', 'rentopian-sync'); ?>
                    </p>
                </div>

                <div class="rental-layout-section rental-review-display-section">
                    <h3><?php esc_html_e('Visible Sections', 'rentopian-sync'); ?></h3>
                    
                    <!-- Enable All / Disable All buttons -->
                    <div class="rental-review-display-bulk-actions" style="margin-bottom: 12px; display: flex; gap: 8px;">
                        <button type="button" id="rental-review-display-enable-all" class="button button-small">
                            <span class="dashicons dashicons-yes-alt" style="margin-top: 3px; font-size: 14px;"></span>
                            <?php esc_html_e('Enable All', 'rentopian-sync'); ?>
                        </button>
                        <button type="button" id="rental-review-display-disable-all" class="button button-small">
                            <span class="dashicons dashicons-dismiss" style="margin-top: 3px; font-size: 14px;"></span>
                            <?php esc_html_e('Disable All', 'rentopian-sync'); ?>
                        </button>
                    </div>

                    <div class="rental-review-display-checkboxes">
                        <?php
                        $review_defaults = Rental_Checkout_Layout_Manager::get_review_display_defaults();
                        foreach ($review_defaults as $key => $def) :
                            $checked = isset($review_display_settings[$key]) ? $review_display_settings[$key] : $def['default'];
                        ?>
                        <label class="rental-review-display-checkbox">
                            <input type="checkbox"
                                   name="rental_review_display[<?php echo esc_attr($key); ?>]"
                                   value="1"
                                   <?php checked($checked); ?>
                                   data-review-item="<?php echo esc_attr($key); ?>" />
                            <?php echo esc_html($def['label']); ?>
                        </label>
                        <?php endforeach; ?>
                    </div>

                    <div class="rental-review-display-actions" style="margin-top: 16px;">
                        <button type="button" id="rental-save-review-display" class="button button-primary button-large">
                            <span class="dashicons dashicons-saved" style="margin-top: 4px;"></span>
                            <?php esc_html_e('Save Display Settings', 'rentopian-sync'); ?>
                        </button>
                        <span id="rental-review-display-status" class="rental-inline-status" style="display:none;"></span>
                    </div>
                </div>
                
                <div class="rental-layout-section" style="background: #fcf0f0; border: 1px solid #d9c3c3; border-radius: 4px; padding: 16px; margin-top: 16px;">
                    <p style="margin: 0; font-size: 13px; color: #555;">
                        <span class="dashicons dashicons-warning" style="color: #d63638;"></span>
                        <strong><?php esc_html_e('Note:', 'rentopian-sync'); ?></strong>
                        <?php esc_html_e('Hiding "Payment Information" will hide payment method options but will always keep the order submit button visible. The submit button cannot be hidden.', 'rentopian-sync'); ?>
                    </p>
                </div>
            </div>
        </div>

        <!-- ================================================================
             TAB 3: MARKETING SECTION
             ================================================================ -->
        <div id="tab-marketing-section" class="rental-tab-content">
            <div class="rental-marketing-section-container">
                <div class="rental-marketing-section-header">
                    <h2><?php esc_html_e('Checkout Marketing Section', 'rentopian-sync'); ?></h2>
                    <p class="description">
                        <?php esc_html_e('Configure a marketing banner and message that appears in the right column of the checkout page, above the order review section. This feature requires the modern checkout layout to be active.', 'rentopian-sync'); ?>
                    </p>
                </div>

                <form id="rental-marketing-section-form" method="post" action="">
                    <?php wp_nonce_field('rental_checkout_marketing_section_save', 'rental_marketing_section_nonce'); ?>
                    
                    <!-- Enable/Disable Toggle -->
                    <div class="rental-layout-section">
                        <h3><?php esc_html_e('Enable Marketing Section', 'rentopian-sync'); ?></h3>
                        <div class="rental-form-row">
                            <?php $marketing_enabled = get_option('rental_checkout_marketing_enabled', '0'); ?>
                            <label class="rental-checkbox-label" style="display: flex; align-items: flex-start; gap: 10px; cursor: pointer;">
                                <input type="checkbox" id="rental-marketing-enabled" name="rental_checkout_marketing_enabled" value="1" <?php checked($marketing_enabled, '1'); ?> style="margin-top: 3px;">
                                <span>
                                    <strong><?php esc_html_e('Show marketing section on checkout page', 'rentopian-sync'); ?></strong>
                                    <p class="description" style="margin: 5px 0 0 0; font-weight: normal;">
                                        <?php esc_html_e('When enabled, the marketing section with banner image and content will be displayed in the right column of the checkout page, above the order review / cart details. Only works when the modern checkout layout is active.', 'rentopian-sync'); ?>
                                    </p>
                                </span>
                            </label>
                        </div>
                    </div>

                    <!-- Banner Image -->
                    <div class="rental-layout-section">
                        <h3><?php esc_html_e('Banner Image', 'rentopian-sync'); ?></h3>
                        <p class="description">
                            <?php esc_html_e('Upload or select an image to display as the banner at the top of the marketing section. Recommended size: 800×500px or similar landscape ratio.', 'rentopian-sync'); ?>
                        </p>
                        <div class="rental-form-row rental-marketing-banner-row">
                            <?php $banner_id = get_option('rental_checkout_marketing_banner_id', ''); ?>
                            <div class="rental-marketing-banner-preview" id="rental-marketing-banner-preview"<?php echo empty($banner_id) ? ' style="display:none;"' : ''; ?>>
                                <?php if (!empty($banner_id)) : ?>
                                    <img src="<?php echo esc_url(wp_get_attachment_url($banner_id)); ?>" alt="Banner preview" id="rental-marketing-banner-img">
                                <?php else : ?>
                                    <img src="" alt="Banner preview" id="rental-marketing-banner-img" style="display:none;">
                                <?php endif; ?>
                                <button type="button" class="button rental-marketing-banner-remove" id="rental-marketing-remove-banner" title="<?php esc_attr_e('Remove banner image', 'rentopian-sync'); ?>">
                                    <span class="dashicons dashicons-no-alt"></span>
                                    <?php esc_html_e('Remove', 'rentopian-sync'); ?>
                                </button>
                            </div>
                            <div class="rental-marketing-banner-upload" id="rental-marketing-banner-upload">
                                <button type="button" class="button button-secondary" id="rental-marketing-upload-banner">
                                    <span class="dashicons dashicons-format-image" style="margin-top: 4px;"></span>
                                    <?php echo empty($banner_id) ? esc_html__('Upload Banner Image', 'rentopian-sync') : esc_html__('Change Banner Image', 'rentopian-sync'); ?>
                                </button>
                            </div>
                            <input type="hidden" id="rental-marketing-banner-id" name="rental_checkout_marketing_banner_id" value="<?php echo esc_attr($banner_id); ?>">
                        </div>
                    </div>

                    <!-- Content Editor -->
                    <div class="rental-layout-section">
                        <h3><?php esc_html_e('Marketing Content', 'rentopian-sync'); ?></h3>
                        <p class="description">
                            <?php esc_html_e('Use the editor below to create the marketing message displayed below the banner. You can add text, links, buttons, and formatting.', 'rentopian-sync'); ?>
                        </p>
                        <div class="rental-form-row rental-marketing-editor-wrapper">
                            <?php
                            $marketing_content = get_option('rental_checkout_marketing_content', '');
                            $marketing_editor_settings = array(
                                'textarea_name' => 'rental_checkout_marketing_content',
                                'textarea_rows' => 14,
                                'media_buttons' => true,
                                'teeny'         => false,
                                'quicktags'     => true,
                                // Open the visual editor regardless of the account-wide
                                // editor preference, so formatting is always available
                                'default_editor' => 'tinymce',
                                'tinymce'       => array(
                                    'toolbar1' => 'formatselect,bold,italic,underline,strikethrough,|,bullist,numlist,|,link,unlink,|,alignleft,aligncenter,alignright,|,forecolor,backcolor,|,undo,redo',
                                    'toolbar2' => '',
                                ),
                            );
                            wp_editor($marketing_content, 'rental_marketing_content_editor', $marketing_editor_settings);
                            ?>
                        </div>
                    </div>

                    <!-- Save Actions -->
                    <div class="rental-layout-section" style="background: #f0f6fc; border: 1px solid #c3d9ed; border-radius: 4px; padding: 20px;">
                        <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 15px;">
                            <div>
                                <p style="margin: 0; font-weight: 500; color: #1d2327;">
                                    <span class="dashicons dashicons-info" style="color: #2271b1;"></span>
                                    <?php esc_html_e('Click "Save Settings" to save the marketing section configuration.', 'rentopian-sync'); ?>
                                </p>
                            </div>
                            <div class="rental-form-actions" style="margin: 0;">
                                <button type="submit" id="rental-save-marketing-section" class="button button-primary button-large">
                                    <span class="dashicons dashicons-saved" style="margin-top: 4px;"></span>
                                    <?php esc_html_e('Save Settings', 'rentopian-sync'); ?>
                                </button>
                                <button type="button" id="rental-preview-marketing-section" class="button button-large">
                                    <span class="dashicons dashicons-visibility" style="margin-top: 4px;"></span>
                                    <?php esc_html_e('Preview', 'rentopian-sync'); ?>
                                </button>
                                <span class="rental-save-status" id="rental-marketing-section-status"></span>
                            </div>
                        </div>
                    </div>
                </form>

                <!-- Preview Modal -->
                <div id="rental-marketing-preview-modal" class="rental-modal" style="display: none;">
                    <div class="rental-modal-overlay"></div>
                    <div class="rental-modal-content rental-modal-wide">
                        <div class="rental-modal-header">
                            <h3><?php esc_html_e('Marketing Section Preview', 'rentopian-sync'); ?></h3>
                            <button type="button" class="rental-modal-close">&times;</button>
                        </div>
                        <div class="rental-modal-body" style="background: #f9f9f9;">
                            <div class="rentopian-marketing-section-preview" style="max-width: 500px; margin: 0 auto; padding: 30px; background: #fff; text-align: center;">
                                <div id="rental-marketing-preview-banner" style="margin-bottom: 20px;"></div>
                                <div id="rental-marketing-preview-content"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ================================================================
             TAB 5: THANK YOU MESSAGE 
             ================================================================ -->
        <div id="tab-thank-you" class="rental-tab-content">
            <div class="rental-thank-you-container">
                <div class="rental-thank-you-header">
                    <h2><?php esc_html_e('Order Confirmation Message', 'rentopian-sync'); ?></h2>
                    <p class="description">
                        <?php esc_html_e('Customize the thank you message that appears on the order confirmation (order-received) page.', 'rentopian-sync'); ?>
                    </p>
                </div>

                <form id="rental-thank-you-form" method="post" action="">
                    <?php wp_nonce_field('rental_checkout_thank_you_save', 'rental_thank_you_nonce'); ?>
                    
                    <div class="rental-layout-section">
                        <h3><?php esc_html_e('Message Content', 'rentopian-sync'); ?></h3>
                        <p class="description">
                            <?php esc_html_e('Use the editor below to create a personalized message for your customers after they complete their order.', 'rentopian-sync'); ?>
                        </p>
                        
                        <div class="rental-form-row rental-thank-you-editor-wrapper">
                            <?php
                            $thank_you_message = get_option('rental_checkout_thank_you_message', '');
                            if ($thank_you_message === '') {
                                $thank_you_message = get_option('rental_thank_you_message', __('Thank you. Your order has been received.', 'rentopian-sync'));
                            }
                            
                            // TinyMCE editor settings
                            $editor_settings = array(
                                'textarea_name' => 'rental_checkout_thank_you_message',
                                'textarea_rows' => 12,
                                'media_buttons' => true,
                                'teeny'         => false,
                                'quicktags'     => true,
                                // Open the visual editor regardless of the account-wide
                                // editor preference, so formatting is always available
                                'default_editor' => 'tinymce',
                                'tinymce'       => array(
                                    'toolbar1' => 'formatselect,bold,italic,underline,strikethrough,|,bullist,numlist,|,link,unlink,|,alignleft,aligncenter,alignright,|,forecolor,backcolor,|,undo,redo',
                                    'toolbar2' => '',
                                ),
                            );
                            
                            wp_editor($thank_you_message, 'rental_thank_you_editor', $editor_settings);
                            ?>
                        </div>
                    </div>

                    <div class="rental-layout-section">
                        <h3><?php esc_html_e('Display Options', 'rentopian-sync'); ?></h3>
                        
                        <div class="rental-form-row">
                            <?php $thank_you_only = get_option('rental_checkout_thank_you_only', false); ?>
                            <label class="rental-checkbox-label" style="display: flex; align-items: flex-start; gap: 10px; cursor: pointer;">
                                <input type="checkbox" id="rental-thank-you-only" name="rental_checkout_thank_you_only" value="1" <?php checked($thank_you_only, '1'); ?> style="margin-top: 3px;">
                                <span>
                                    <strong><?php esc_html_e('Show only thank you message', 'rentopian-sync'); ?></strong>
                                    <p class="description" style="margin: 5px 0 0 0; font-weight: normal;">
                                        <?php esc_html_e('When enabled, ONLY the thank you message will be displayed on the order confirmation page. Order details, billing/shipping addresses, rental dates, quote numbers, and all other WooCommerce content will be hidden.', 'rentopian-sync'); ?>
                                    </p>
                                </span>
                            </label>
                        </div>
                    </div>

                    <div class="rental-layout-section rental-thank-you-actions-section" style="background: #f0f6fc; border: 1px solid #c3d9ed; border-radius: 4px; padding: 20px;">
                        <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 15px;">
                            <div>
                                <p style="margin: 0; font-weight: 500; color: #1d2327;">
                                    <span class="dashicons dashicons-info" style="color: #2271b1;"></span>
                                    <?php esc_html_e('Click "Save Settings" to save both the message content and display options.', 'rentopian-sync'); ?>
                                </p>
                            </div>
                            <div class="rental-form-actions" style="margin: 0;">
                                <button type="submit" id="rental-save-thank-you" class="button button-primary button-large">
                                    <span class="dashicons dashicons-saved" style="margin-top: 4px;"></span>
                                    <?php esc_html_e('Save Settings', 'rentopian-sync'); ?>
                                </button>
                                <button type="button" id="rental-preview-thank-you" class="button button-large">
                                    <span class="dashicons dashicons-visibility" style="margin-top: 4px;"></span>
                                    <?php esc_html_e('Preview', 'rentopian-sync'); ?>
                                </button>
                                <span class="rental-save-status" id="rental-thank-you-status"></span>
                            </div>
                        </div>
                    </div>
                </form>

                <!-- Preview Modal -->
                <div id="rental-thank-you-preview-modal" class="rental-modal" style="display: none;">
                    <div class="rental-modal-overlay"></div>
                    <div class="rental-modal-content">
                        <div class="rental-modal-header">
                            <h3><?php esc_html_e('Thank You Message Preview', 'rentopian-sync'); ?></h3>
                            <button type="button" class="rental-modal-close">&times;</button>
                        </div>
                        <div class="rental-modal-body">
                            <div class="rentopian-thank-you-message rentopian-thank-you-preview">
                                <div id="rental-thank-you-preview-content"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ================================================================
             TAB 5: JSON EDITOR (preserved exactly, minus review display which moved to own tab)
             ================================================================ -->
        <div id="tab-json-editor" class="rental-tab-content">
            <div class="rental-checkout-layout-container">
                
                <p class="description rental-section-description">
                    <?php esc_html_e('Customize the checkout form layout by editing the JSON configuration below. You can control field placement, column widths, row organization, and field visibility.', 'rentopian-sync'); ?>
                </p>

                <!-- Main Content Area -->
                <div class="rental-layout-main-content">
                    
                    <!-- Left Column: JSON Editor -->
                    <div class="rental-layout-editor-column">
                        <div class="rental-layout-section">
                            <h2><?php esc_html_e('Layout Configuration (JSON)', 'rentopian-sync'); ?></h2>
                            
                            <div class="rental-json-editor-wrapper">
                                <textarea id="rental-layout-json" 
                                          class="rental-json-editor" 
                                          rows="25" 
                                          placeholder="<?php esc_attr_e('Paste your JSON configuration here...', 'rentopian-sync'); ?>"
                                ><?php echo esc_textarea(wp_json_encode($current_layout, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></textarea>
                            </div>
                            
                            <div class="rental-editor-actions">
                                <button type="button" id="rental-validate-json" class="button">
                                    <span class="dashicons dashicons-yes-alt"></span>
                                    <?php esc_html_e('Validate', 'rentopian-sync'); ?>
                                </button>
                                <button type="button" id="rental-format-json" class="button">
                                    <span class="dashicons dashicons-editor-code"></span>
                                    <?php esc_html_e('Format JSON', 'rentopian-sync'); ?>
                                </button>
                                <button type="button" id="rental-save-layout" class="button button-primary">
                                    <span class="dashicons dashicons-saved"></span>
                                    <?php esc_html_e('Save Layout', 'rentopian-sync'); ?>
                                </button>
                            </div>
                            
                            <!-- Processing Indicator -->
                            <div id="rental-processing-indicator" class="rental-processing-indicator" style="display: none;">
                                <span class="spinner is-active"></span>
                                <span class="processing-text"><?php esc_html_e('Processing...', 'rentopian-sync'); ?></span>
                            </div>
                            
                            <!-- Validation Status -->
                            <div id="rental-validation-status" class="rental-validation-status" style="display: none;">
                                <span class="status-icon"></span>
                                <span class="status-message"></span>
                            </div>
                        </div>

                        <!-- Import/Export Section -->
                        <div class="rental-layout-section rental-import-export-section">
                            <h2><?php esc_html_e('Import / Export / Reload', 'rentopian-sync'); ?></h2>
                            
                            <div class="rental-import-export-actions">
                                <div class="rental-import-group">
                                    <label for="rental-import-file" class="button">
                                        <span class="dashicons dashicons-upload"></span>
                                        <?php esc_html_e('Import from File', 'rentopian-sync'); ?>
                                    </label>
                                    <input type="file" 
                                           id="rental-import-file" 
                                           accept=".json,application/json" 
                                           style="display: none;">
                                </div>
                                
                                <button type="button" id="rental-export-layout" class="button">
                                    <span class="dashicons dashicons-download"></span>
                                    <?php esc_html_e('Export to File', 'rentopian-sync'); ?>
                                </button>
                                
                                <button type="button" id="rental-reload-layout" class="button">
                                    <span class="dashicons dashicons-update"></span>
                                    <?php esc_html_e('Reload from Server', 'rentopian-sync'); ?>
                                </button>
                                
                                <button type="button" id="rental-reset-layout" class="button button-link-delete">
                                    <span class="dashicons dashicons-image-rotate"></span>
                                    <?php esc_html_e('Reset to Default', 'rentopian-sync'); ?>
                                </button>
                            </div>
                            
                            <!-- Processing Indicator for Import/Export -->
                            <div id="rental-ie-processing-indicator" class="rental-processing-indicator" style="display: none;">
                                <span class="spinner is-active"></span>
                                <span class="processing-text"><?php esc_html_e('Processing...', 'rentopian-sync'); ?></span>
                            </div>
                        </div>
                    </div>

                    <!-- Right Column: Documentation & Available Fields -->
                    <div class="rental-layout-info-column">
                        
                        <!-- Available Fields Reference -->
                        <div class="rental-layout-section rental-fields-reference">
                            <h2><?php esc_html_e('Available Fields', 'rentopian-sync'); ?></h2>
                            <p class="description">
                                <?php esc_html_e('Click on a field ID to copy it. Fields marked with * are mandatory and cannot be disabled.', 'rentopian-sync'); ?>
                            </p>

                            <!-- Refresh Dynamic Fields Button -->
                            <div class="rental-refresh-fields-wrap">
                                <button type="button" id="rental-refresh-dynamic-fields" class="button">
                                    <span class="dashicons dashicons-update"></span>
                                    <?php esc_html_e('Refresh Custom Fields from Rentopian', 'rentopian-sync'); ?>
                                </button>
                                <span class="rental-refresh-status"></span>
                            </div>
                            
                            <div class="rental-fields-accordion">
                                <?php foreach ($field_groups as $group_id => $group) : ?>
                                    <div class="rental-field-group" data-group-id="<?php echo esc_attr($group_id); ?>">
                                        <button type="button" class="rental-field-group-toggle" data-group="<?php echo esc_attr($group_id); ?>">
                                            <span class="dashicons dashicons-arrow-right-alt2"></span>
                                            <?php echo esc_html($group['label']); ?>
                                            <span class="field-count">(<?php echo esc_html($group['count']); ?>)</span>
                                        </button>
                                        <div class="rental-field-group-content" id="group-<?php echo esc_attr($group_id); ?>">
                                            <ul class="rental-field-list">
                                                <?php 
                                                // Filter fields by group
                                                $group_fields = array_filter($fields, function($f) use ($group_id) {
                                                    return $f['group'] === $group_id;
                                                });
                                                foreach ($group_fields as $field_id => $field) : 
                                                    $is_mandatory = in_array($field_id, $mandatory_fields, true);
                                                ?>
                                                    <li class="rental-field-item <?php echo $is_mandatory ? 'rental-field-mandatory' : ''; ?>" data-field-id="<?php echo esc_attr($field_id); ?>">
                                                        <code class="field-id"><?php echo esc_html($field_id); ?></code>
                                                        <span class="field-label"><?php echo esc_html($field['label']); ?></span>
                                                        <?php if ($is_mandatory) : ?>
                                                            <span class="field-mandatory-badge" title="<?php esc_attr_e('Mandatory - Cannot be disabled', 'rentopian-sync'); ?>">*</span>
                                                        <?php endif; ?>
                                                        <button type="button" class="rental-copy-field-id" title="<?php esc_attr_e('Copy field ID', 'rentopian-sync'); ?>">
                                                            <span class="dashicons dashicons-clipboard"></span>
                                                        </button>
                                                    </li>
                                                <?php endforeach; ?>
                                            </ul>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- JSON Structure Documentation -->
                        <div class="rental-layout-section rental-json-docs">
                            <h2><?php esc_html_e('JSON Structure Guide', 'rentopian-sync'); ?></h2>
                            
                            <div class="rental-docs-content">
                                <h4><?php esc_html_e('Basic Structure', 'rentopian-sync'); ?></h4>
                                <pre class="rental-code-block">{
  "sections": [
    {
      "id": "section_id",
      "title": "Section Title",
      "rows": [
        {
          "id": "row_id",
          "columns": [
            {
              "width": 6,
              "field": "field_id",
              "active": 1,
              "hidden_visual": 0,
              "depends_on": ""
            }
          ]
        }
      ]
    }
  ]
}</pre>

                                <h4><?php esc_html_e('Column Properties', 'rentopian-sync'); ?></h4>
                                <ul>
                                    <li><code>width</code> - <?php esc_html_e('Column width (1-12, based on 12-column grid)', 'rentopian-sync'); ?></li>
                                    <li><code>field</code> - <?php esc_html_e('Field ID from the available fields list', 'rentopian-sync'); ?></li>
                                    <li><code>active</code> - <?php esc_html_e('1 = visible, 0 = hidden (mandatory fields always show)', 'rentopian-sync'); ?></li>
                                    <li><code>hidden_visual</code> - <?php esc_html_e('1 = rendered but visually hidden (for autocomplete fields)', 'rentopian-sync'); ?></li>
                                    <li><code>depends_on</code> - <?php esc_html_e('Parent field ID this column depends on', 'rentopian-sync'); ?></li>
                                    <li><code>class</code> - <?php esc_html_e('Optional CSS class for styling', 'rentopian-sync'); ?></li>
                                </ul>

                                <h4><?php esc_html_e('Hidden Visual Fields', 'rentopian-sync'); ?></h4>
                                <p><?php esc_html_e('Use hidden_visual: 1 for fields that need to exist in the form but should not be displayed. This is useful for:', 'rentopian-sync'); ?></p>
                                <ul>
                                    <li><?php esc_html_e('Google Places autocomplete (city, state, zip filled automatically)', 'rentopian-sync'); ?></li>
                                    <li><?php esc_html_e('Fields set programmatically by JavaScript', 'rentopian-sync'); ?></li>
                                </ul>

                                <h4><?php esc_html_e('Field Dependencies', 'rentopian-sync'); ?></h4>
                                <p><?php esc_html_e('Use depends_on to establish relationships between fields:', 'rentopian-sync'); ?></p>
                                <ul>
                                    <li><code>rental_multi_day_event</code> → <code>rental_date_form_modern</code></li>
                                    <li><code>rental_outdoor</code> → <code>billing_address_1</code></li>
                                    <li><code>billing_city</code> → <code>billing_address_1</code></li>
                                    <li><code>rental_flexible_delivery</code> → <code>rental_delivery_time</code></li>
                                </ul>

                                <h4><?php esc_html_e('Column Width Examples', 'rentopian-sync'); ?></h4>
                                <ul>
                                    <li><code>12</code> - <?php esc_html_e('Full width (1 column)', 'rentopian-sync'); ?></li>
                                    <li><code>6</code> - <?php esc_html_e('Half width (2 columns)', 'rentopian-sync'); ?></li>
                                    <li><code>4</code> - <?php esc_html_e('Third width (3 columns)', 'rentopian-sync'); ?></li>
                                    <li><code>3</code> - <?php esc_html_e('Quarter width (4 columns)', 'rentopian-sync'); ?></li>
                                </ul>
                                
                                <h4><?php esc_html_e('Important Notes', 'rentopian-sync'); ?></h4>
                                <ul>
                                    <li><?php esc_html_e('Total column widths in a row should not exceed 12', 'rentopian-sync'); ?></li>
                                    <li><?php esc_html_e('Field IDs must match the available fields listed above', 'rentopian-sync'); ?></li>
                                    <li><?php esc_html_e('Mandatory fields (*) cannot be disabled even if active is set to 0', 'rentopian-sync'); ?></li>
                                    <li><?php esc_html_e('Dynamic fields from Rentopian API are prefixed with rental_custom_', 'rentopian-sync'); ?></li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ================================================================
             TAB 6: TEMPLATES (preserved + enhanced with edit-in-VB/JSON option)
             ================================================================ -->
        <div id="tab-templates" class="rental-tab-content">
            <div class="rental-templates-container">
                <div class="rental-templates-header">
                    <h2><?php esc_html_e('Layout Templates', 'rentopian-sync'); ?></h2>
                    <p class="description">
                        <?php esc_html_e('Manage checkout layout templates. Save new templates, apply, or edit previously saved templates. When applying a template, it will be loaded into the Visual Builder.', 'rentopian-sync'); ?>
                    </p>
                </div>

                <!-- Templates Tab Status Messages -->
                <div id="rental-templates-messages" class="rental-templates-messages"></div>

                <!-- Templates List Section -->
                <div class="rental-template-list-section rental-layout-section">
                    <div class="rental-templates-list-header">
                        <h3>
                            <?php esc_html_e('Available Templates', 'rentopian-sync'); ?>
                            <span id="rental-templates-count" class="rental-badge"></span>
                            <span class="rental-templates-limit-notice"><?php esc_html_e('(max 20)', 'rentopian-sync'); ?></span>
                        </h3>
                        <button type="button" id="rental-refresh-templates" class="button" title="<?php esc_attr_e('Refresh templates list', 'rentopian-sync'); ?>">
                            <span class="dashicons dashicons-update"></span>
                            <?php esc_html_e('Refresh', 'rentopian-sync'); ?>
                        </button>
                    </div>
                    
                    <div id="rental-templates-loading" class="rental-templates-loading">
                        <span class="spinner is-active"></span>
                        <span><?php esc_html_e('Loading templates...', 'rentopian-sync'); ?></span>
                    </div>

                    <div id="rental-templates-empty" class="rental-templates-empty" style="display: none;">
                        <span class="dashicons dashicons-portfolio"></span>
                        <p><?php esc_html_e('No saved templates yet.', 'rentopian-sync'); ?></p>
                        <p class="description"><?php esc_html_e('Create your first template using the form below.', 'rentopian-sync'); ?></p>
                    </div>

                    <div id="rental-templates-list" class="rental-templates-list" style="display: none;"></div>
                </div>

                <!-- Save New Template Section -->
                <div class="rental-template-save-section rental-layout-section">
                    <h3><?php esc_html_e('Save New Template', 'rentopian-sync'); ?></h3>
                    <p class="description">
                        <?php esc_html_e('Create a new template from the current active layout, or paste custom JSON.', 'rentopian-sync'); ?>
                    </p>
                    <div class="rental-template-save-form">
                        <div class="rental-form-row">
                            <label for="rental-template-name"><?php esc_html_e('Template Name', 'rentopian-sync'); ?> <span class="required">*</span></label>
                            <input type="text" id="rental-template-name" class="regular-text" placeholder="<?php esc_attr_e('e.g., Wedding Layout, Corporate Events', 'rentopian-sync'); ?>">
                        </div>
                        <div class="rental-form-row">
                            <label for="rental-template-description"><?php esc_html_e('Description (optional)', 'rentopian-sync'); ?></label>
                            <textarea id="rental-template-description" class="large-text" rows="2" placeholder="<?php esc_attr_e('Brief description of this layout template...', 'rentopian-sync'); ?>"></textarea>
                        </div>
                        <div class="rental-form-row">
                            <label for="rental-template-json"><?php esc_html_e('Layout JSON', 'rentopian-sync'); ?> <span class="required">*</span></label>
                            <div class="rental-template-json-actions">
                                <button type="button" id="rental-copy-current-layout" class="button button-small" title="<?php esc_attr_e('Copy current layout from JSON Editor tab', 'rentopian-sync'); ?>">
                                    <span class="dashicons dashicons-admin-page"></span>
                                    <?php esc_html_e('Copy Current Layout', 'rentopian-sync'); ?>
                                </button>
                                <button type="button" id="rental-validate-template-json" class="button button-small">
                                    <span class="dashicons dashicons-yes-alt"></span>
                                    <?php esc_html_e('Validate JSON', 'rentopian-sync'); ?>
                                </button>
                            </div>
                            <textarea id="rental-template-json" class="large-text code" rows="10" placeholder="<?php esc_attr_e('Paste your layout JSON here. Use "Copy Current Layout" to get the current configuration.', 'rentopian-sync'); ?>"></textarea>
                            <div id="rental-template-json-status" class="rental-json-status" style="display: none;"></div>
                        </div>
                        <div class="rental-form-actions">
                            <button type="button" id="rental-save-as-template" class="button button-primary">
                                <span class="dashicons dashicons-saved"></span>
                                <span class="button-text"><?php esc_html_e('Save Template', 'rentopian-sync'); ?></span>
                                <span class="button-loading" style="display: none;">
                                    <span class="spinner is-active" style="float: none; margin: 0;"></span>
                                    <?php esc_html_e('Saving...', 'rentopian-sync'); ?>
                                </span>
                            </button>
                            <button type="button" id="rental-clear-template-form" class="button">
                                <span class="dashicons dashicons-dismiss"></span>
                                <?php esc_html_e('Clear Form', 'rentopian-sync'); ?>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- Confirmation Modal Template -->
<div id="rental-confirm-modal" class="rental-modal" style="display: none;">
    <div class="rental-modal-overlay"></div>
    <div class="rental-modal-content">
        <div class="rental-modal-header">
            <h3 id="rental-modal-title"><?php esc_html_e('Confirm Action', 'rentopian-sync'); ?></h3>
            <button type="button" class="rental-modal-close">&times;</button>
        </div>
        <div class="rental-modal-body">
            <p id="rental-modal-message"></p>
        </div>
        <div class="rental-modal-footer">
            <button type="button" class="button rental-modal-cancel">
                <?php esc_html_e('Cancel', 'rentopian-sync'); ?>
            </button>
            <button type="button" class="button button-primary rental-modal-confirm">
                <?php esc_html_e('Confirm', 'rentopian-sync'); ?>
            </button>
        </div>
    </div>
</div>

<!-- Edit Template Modal (enhanced: choose edit in VB or JSON Editor) -->
<div id="rental-edit-template-modal" class="rental-modal" style="display: none;">
    <div class="rental-modal-overlay"></div>
    <div class="rental-modal-content rental-modal-wide">
        <div class="rental-modal-header">
            <h3><?php esc_html_e('Edit Template', 'rentopian-sync'); ?></h3>
            <button type="button" class="rental-modal-close">&times;</button>
        </div>
        <div class="rental-modal-body">
            <input type="hidden" id="rental-edit-template-id">
            <div class="rental-form-row">
                <label for="rental-edit-template-name"><?php esc_html_e('Template Name', 'rentopian-sync'); ?> <span class="required">*</span></label>
                <input type="text" id="rental-edit-template-name" class="regular-text" style="width: 100%;">
            </div>
            <div class="rental-form-row">
                <label for="rental-edit-template-description"><?php esc_html_e('Description', 'rentopian-sync'); ?></label>
                <textarea id="rental-edit-template-description" class="large-text" rows="2" style="width: 100%;"></textarea>
            </div>
            <div class="rental-form-row">
                <label>
                    <input type="checkbox" id="rental-edit-template-update-layout">
                    <?php esc_html_e('Update template with current layout', 'rentopian-sync'); ?>
                </label>
                <p class="description"><?php esc_html_e('Check this to replace the template\'s layout with the current JSON Editor layout.', 'rentopian-sync'); ?></p>
            </div>
            <div class="rental-form-row rental-template-edit-open-in" style="border-top: 1px solid #dcdcde; padding-top: 12px; margin-top: 8px;">
                <p class="description" style="margin-bottom: 8px;"><?php esc_html_e('Open this template\'s layout for editing:', 'rentopian-sync'); ?></p>
                <button type="button" class="button rental-template-open-in-vb" title="<?php esc_attr_e('Load template layout into the Visual Builder tab', 'rentopian-sync'); ?>">
                    <span class="dashicons dashicons-layout"></span>
                    <?php esc_html_e('Edit in Visual Builder', 'rentopian-sync'); ?>
                </button>
                <button type="button" class="button rental-template-open-in-json" title="<?php esc_attr_e('Load template layout into the JSON Editor tab', 'rentopian-sync'); ?>">
                    <span class="dashicons dashicons-editor-code"></span>
                    <?php esc_html_e('Edit in JSON Editor', 'rentopian-sync'); ?>
                </button>
            </div>
        </div>
        <div class="rental-modal-footer">
            <button type="button" class="button rental-modal-cancel">
                <?php esc_html_e('Cancel', 'rentopian-sync'); ?>
            </button>
            <button type="button" class="button button-primary" id="rental-edit-template-save">
                <?php esc_html_e('Save Changes', 'rentopian-sync'); ?>
            </button>
        </div>
    </div>
</div>

<!-- Save As Template Modal (used by Visual Builder "Save as Template" button) -->
<div id="rental-vb-save-template-modal" class="rental-modal" style="display: none;">
    <div class="rental-modal-overlay"></div>
    <div class="rental-modal-content">
        <div class="rental-modal-header">
            <h3><?php esc_html_e('Save as Template', 'rentopian-sync'); ?></h3>
            <button type="button" class="rental-modal-close">&times;</button>
        </div>
        <div class="rental-modal-body">
            <div class="rental-form-row">
                <label for="rental-vb-tpl-name"><?php esc_html_e('Template Name', 'rentopian-sync'); ?> <span class="required">*</span></label>
                <input type="text" id="rental-vb-tpl-name" class="regular-text" style="width: 100%;" placeholder="<?php esc_attr_e('e.g., Wedding Layout', 'rentopian-sync'); ?>">
            </div>
            <div class="rental-form-row">
                <label for="rental-vb-tpl-desc"><?php esc_html_e('Description (optional)', 'rentopian-sync'); ?></label>
                <textarea id="rental-vb-tpl-desc" class="large-text" rows="2" style="width: 100%;" placeholder="<?php esc_attr_e('Brief description...', 'rentopian-sync'); ?>"></textarea>
            </div>
        </div>
        <div class="rental-modal-footer">
            <button type="button" class="button rental-modal-cancel">
                <?php esc_html_e('Cancel', 'rentopian-sync'); ?>
            </button>
            <button type="button" class="button button-primary" id="rental-vb-tpl-save-btn">
                <?php esc_html_e('Save Template', 'rentopian-sync'); ?>
            </button>
        </div>
    </div>
</div>

<!-- Template Item Template (used by JS to render each template) -->
<script type="text/html" id="tmpl-rental-template-item">
<div class="rental-template-item" data-template-id="{{ data.id }}">
    <div class="rental-template-info">
        <h4 class="rental-template-name">{{ data.name }}</h4>
        <# if (data.description) { #>
        <p class="rental-template-description">{{ data.description }}</p>
        <# } #>
        <div class="rental-template-meta">
            <span class="rental-template-version">
                <span class="dashicons dashicons-info-outline"></span>
                v{{ data.version }}
            </span>
            <span class="rental-template-date">
                <span class="dashicons dashicons-calendar-alt"></span>
                {{ data.updated_at || data.created_at }}
            </span>
        </div>
    </div>
    <div class="rental-template-actions">
        <button type="button" class="button button-primary rental-template-apply" data-template-id="{{ data.id }}" title="<?php esc_attr_e('Apply this template to checkout', 'rentopian-sync'); ?>">
            <span class="dashicons dashicons-yes"></span>
            <span class="btn-text"><?php esc_html_e('Apply', 'rentopian-sync'); ?></span>
        </button>
        <button type="button" class="button rental-template-preview" data-template-id="{{ data.id }}" title="<?php esc_attr_e('Preview template JSON', 'rentopian-sync'); ?>">
            <span class="dashicons dashicons-visibility"></span>
        </button>
        <button type="button" class="button rental-template-edit" data-template-id="{{ data.id }}" title="<?php esc_attr_e('Edit template', 'rentopian-sync'); ?>">
            <span class="dashicons dashicons-edit"></span>
        </button>
        <button type="button" class="button rental-template-delete button-link-delete" data-template-id="{{ data.id }}" title="<?php esc_attr_e('Delete template', 'rentopian-sync'); ?>">
            <span class="dashicons dashicons-trash"></span>
        </button>
    </div>
    <div class="rental-template-loading" style="display: none;">
        <span class="spinner is-active"></span>
    </div>
</div>
</script>
