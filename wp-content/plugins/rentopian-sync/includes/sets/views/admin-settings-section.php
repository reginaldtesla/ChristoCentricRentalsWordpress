<?php
/**
 * Sets Module — Admin Settings Section View
 *
 * Renders the "Sets" section inside the Rentopian Sync settings page.
 * Included by {@see Rental_Sets_Admin_Settings::render()} which prepares
 * the following local variables for the template:
 *
 * @var string $opt_set_listing_style Field name of the listing style radio.
 * @var string $opt_hide_set_items    Field name of the hide-items checkbox.
 * @var string $opt_sets_layout_mode  Field name of the layout mode radio.
 * @var string $opt_hide_variant_product_name Field name of the variant label checkbox.
 * @var mixed  $val_set_listing_style Currently saved listing style ("standard"|"minimal").
 * @var mixed  $val_hide_set_items    Currently saved hide-items flag (truthy = checked).
 * @var string $val_sets_layout_mode  Currently saved layout mode ("classic"|"modern").
 * @var mixed  $val_hide_variant_product_name Currently saved variant label flag (truthy = checked).
 *
 * Modern-only settings live in `#rntp-sets-modern-options`, which
 * admin-settings.js shows and hides with the layout mode radio.
 *
 * @package RentopianSync\Sets
 *
 * PHP requirement: 7.4+
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<div class="section-wrapper">
    <!-- Sets section -->
        <div class="rental-inner-title">
            <h3><?php _e('Sets', 'rentopian-sync')?></h3>
        </div>

            <div class="rental-form-group">
                <div class="rntp-sync-form-title">
                    <label class="rental-label">
                        <?php _e('Layout Mode', 'rentopian-sync')?>
                    </label>
                </div>
                <div class="rntp-sync-field">
                    <div class="rntp-radio inline">
                        <input id="rntp-sets-layout-classic"
                            name="<?php echo esc_attr($opt_sets_layout_mode); ?>"
                            required="required"
                            type="radio"
                            value="classic"
                            <?php if( esc_attr( $val_sets_layout_mode ) === 'classic' ):?>checked="checked"<?php endif?>
                        />
                        <label for="rntp-sets-layout-classic" class="radio-label"><?php _e('Classic', 'rentopian-sync')?></label>
                    </div>
                    <div class="rntp-radio inline">
                        <input id="rntp-sets-layout-modern"
                            required="required"
                            name="<?php echo esc_attr($opt_sets_layout_mode); ?>"
                            type="radio"
                            value="modern"
                            <?php if( esc_attr( $val_sets_layout_mode ) === 'modern' ):?>checked="checked"<?php endif?>
                        />
                        <label for="rntp-sets-layout-modern" class="radio-label"><?php _e('Modern', 'rentopian-sync')?></label>
                    </div>
                </div>
                
                <div class="rntp-help-block">
                    <div class="rntp-help-block-content">
                        <?php _e('Classic: Displays set components in a simple table below the product. Composite/grouped items are not shown. Component Listing Style applies only to this mode.
Modern: Replaces the table with an interactive configurator above Add to Cart. Supports items, selectable items, and composite/grouped items.', 'rentopian-sync')?>
                    </div>
                </div>
                <div class="clearfix"></div>
            </div>

            <?php
            // Modern-only settings. Rendered hidden under classic so no
            // flash of an inapplicable field before admin-settings.js
            // takes over the visibility on change.
            ?>
            <div id="rntp-sets-modern-options"<?php if( esc_attr( $val_sets_layout_mode ) !== 'modern' ):?> style="display:none;"<?php endif?>>
                <div class="rental-form-group">
                    <div class="rntp-sync-form-title">
                        <label class="rental-label" for="rntp-checkbox-<?php echo esc_attr($opt_hide_variant_product_name)?>">
                            <?php _e('Hide Repeated Product Name', 'rentopian-sync')?>
                        </label>
                    </div>
                    <div class="rntp-sync-field">
                        <div class="rntp-checkbox inline">
                            <label>
                                <input id="rntp-checkbox-<?php echo esc_attr($opt_hide_variant_product_name)?>"
                                        name="<?php echo esc_attr($opt_hide_variant_product_name); ?>"
                                        type="checkbox"
                                        value="true"
                                    <?php echo $val_hide_variant_product_name? 'checked="checked"': ''; ?>/>
                                <span></span>
                            </label>
                        </div>
                    </div>
                    <div class="rntp-help-block">
                        <div class="rntp-help-block-content">
                            <?php _e('Controls variant labels in the set configurator. When enabled, repeated product names are hidden and only attributes are shown where the product name is already obvious.', 'rentopian-sync')?>
                        </div>
                    </div>
                    <div class="clearfix"></div>
                </div>
            </div>

            <div class="rental-form-group">
                <div class="rntp-sync-form-title">
                    <label class="rental-label">
                        <?php _e('Component Listing Style', 'rentopian-sync')?>
                    </label>
                </div>
                <div class="rntp-sync-field">
                    <div class="rntp-radio inline">
                        <input id="rntp-set-listing-standard"
                            name="<?php echo esc_attr($opt_set_listing_style); ?>"
                            required="required"
                            type="radio"
                            value="standard"
                            <?php if( esc_attr( $val_set_listing_style )  === 'standard' ):?>checked="checked"<?php endif?>
                        />
                        <label for="rntp-set-listing-standard" class="radio-label">Standard</label>
                    </div>
                    <div class="rntp-radio inline">
                        <input id="rntp-set-listing-minimal"
                            required="required"
                            name="<?php echo esc_attr($opt_set_listing_style); ?>"
                            type="radio"
                            value="minimal"
                            <?php if( esc_attr( $val_set_listing_style ) === 'minimal'):?>checked="checked"<?php endif?>
                        />
                        <label for="rntp-set-listing-minimal" class="radio-label">Minimal</label>
                    </div>
                </div>
                <div class="rntp-help-block">
                    <div class="rntp-help-block-content">
                        <?php _e('The standard option displays the image, title and quantity of each set item. The minimal option just the title and quantity right after the title, inside a bracket.', 'rentopian-sync')?>
                    </div>
                </div>
                <div class="clearfix"></div>
            </div>
            <div class="rental-form-group">
                <div class="rntp-sync-form-title">
                    <label class="rental-label" for="rntp-checkbox-<?php echo esc_attr($opt_hide_set_items)?>">
                        <?php _e('Hide Set Items', 'rentopian-sync')?>
                    </label>
                </div>
                <div class="rntp-sync-field">
                    <div class="rntp-checkbox inline">
                        <label>
                            <input id="rntp-checkbox-<?php echo esc_attr($opt_hide_set_items)?>"
                                    name="<?php echo esc_attr($opt_hide_set_items); ?>"
                                    type="checkbox"
                                    value="true"
                                <?php echo $val_hide_set_items? 'checked="checked"': ''; ?>/>
                            <span></span>
                        </label>
                    </div>
                </div>
                <div class="rntp-help-block">
                    <div class="rntp-help-block-content">
                        <?php _e('If enabled Set Items will be hidden in product single page and cart modal/page.', 'rentopian-sync')?>
                    </div>
                </div>
                <div class="clearfix"></div>
            </div>

    <!-- Sets section -->
</div>
