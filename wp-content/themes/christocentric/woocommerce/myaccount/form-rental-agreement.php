<?php
/**
 * Client data verification — multi-step popup matching the Google Form.
 *
 * Variables from CCR_Rental_Agreement::render(): $values, $complete
 */

defined('ABSPATH') || exit;

$values = is_array($values ?? null) ? $values : [];
$complete = ! empty($complete);
$termsUrl = home_url('/terms/');
$supportPhone = '+233546268719';
$user = wp_get_current_user();

$v = static function (string $key) use ($values): string {
    return (string) ($values[$key] ?? '');
};

$fileLabel = static function (string $attachId): string {
    if (class_exists('CCR_Client_Store') && CCR_Client_Store::parse_file_id($attachId) > 0) {
        return CCR_Client_Store::file_display_name($attachId);
    }
    $id = (int) $attachId;
    if ($id <= 0) {
        return '';
    }
    $url = wp_get_attachment_url($id);

    return $url ? basename((string) get_attached_file($id)) : '';
};

$idTypes = [
    'ghana_card' => __('Ghana Card', 'christocentric'),
    'drivers_license' => __('Driver\'s License', 'christocentric'),
    'passport' => __('National Passport', 'christocentric'),
];
$g1Rels = [
    'Parent' => __('Parent', 'christocentric'),
    'Spouse or Partner' => __('Spouse or Partner', 'christocentric'),
    'Family Member' => __('Family Member', 'christocentric'),
    'Friend' => __('Friend', 'christocentric'),
];
$g2Rels = [
    'Parent or Guardian' => __('Parent or Guardian', 'christocentric'),
    'Family Member' => __('Family Member', 'christocentric'),
    'Spouse or Partner' => __('Spouse or Partner', 'christocentric'),
    'Friend' => __('Friend', 'christocentric'),
];
?>
<div class="ccr-agreement" data-ccr-agreement <?php echo $complete ? '' : 'data-ccr-agreement-required="1"'; ?>>
    <?php if ($complete) : ?>
        <div class="ccr-agreement-summary">
            <p class="ccr-agreement-banner"><?php esc_html_e('Your client verification is on file. Staff can see it on your orders.', 'christocentric'); ?></p>
            <dl class="ccr-agreement-summary-list">
                <div><dt><?php esc_html_e('Name', 'christocentric'); ?></dt><dd><?php echo esc_html($v('full_name')); ?></dd></div>
                <div><dt><?php esc_html_e('ID', 'christocentric'); ?></dt><dd><?php echo esc_html($v('id_card_number')); ?></dd></div>
                <div><dt><?php esc_html_e('Phone', 'christocentric'); ?></dt><dd><?php echo esc_html($v('phone')); ?></dd></div>
            </dl>
            <button type="button" class="button" data-ccr-agreement-open><?php esc_html_e('Update verification form', 'christocentric'); ?></button>
        </div>
    <?php else : ?>
        <div class="ccr-agreement-summary">
            <p class="ccr-agreement-banner"><?php esc_html_e('Complete Client Data Verification once before you can checkout. Have your Ghana Card, a guarantor\'s Ghana Card, and a GPS address photo ready.', 'christocentric'); ?></p>
            <button type="button" class="button" data-ccr-agreement-open><?php esc_html_e('Open verification form', 'christocentric'); ?></button>
        </div>
    <?php endif; ?>

    <div class="ccr-agreement-modal" data-ccr-agreement-modal hidden>
        <div class="ccr-agreement-modal-backdrop" data-ccr-agreement-backdrop></div>
        <div class="ccr-agreement-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="ccr-agreement-title">
            <header class="ccr-agreement-modal-head">
                <p class="ccr-agreement-kicker"><?php esc_html_e('Christocentric Rentals', 'christocentric'); ?></p>
                <h2 id="ccr-agreement-title"><?php esc_html_e('Client Data Verification', 'christocentric'); ?></h2>
                <p class="ccr-agreement-step-label" data-ccr-agreement-step-label><?php esc_html_e('Step 1 of 5', 'christocentric'); ?></p>
                <?php if ($complete) : ?>
                    <button type="button" class="ccr-agreement-close" data-ccr-agreement-close aria-label="<?php esc_attr_e('Close', 'christocentric'); ?>">×</button>
                <?php endif; ?>
            </header>

            <div class="ccr-agreement-progress" aria-hidden="true">
                <span data-ccr-agreement-progress></span>
            </div>

            <form class="ccr-agreement-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data" data-ccr-agreement-form>
                <input type="hidden" name="action" value="ccr_save_rental_agreement">
                <?php wp_nonce_field('ccr_rental_agreement'); ?>

                <section class="ccr-agreement-pane is-active" data-ccr-agreement-pane="1">
                    <h3><?php esc_html_e('Data verification & system upgrade', 'christocentric'); ?></h3>
                    <div class="ccr-agreement-notice">
                        <strong><?php esc_html_e('Please have ready before you start:', 'christocentric'); ?></strong>
                        <ul>
                            <li><?php esc_html_e('A scanned copy or clear photo of your Ghana Card (front & back)', 'christocentric'); ?></li>
                            <li><?php esc_html_e('A Ghana Card of a trusted contact to serve as guarantor (front & back)', 'christocentric'); ?></li>
                            <li><?php esc_html_e('A photo of your residential GPS address', 'christocentric'); ?></li>
                        </ul>
                    </div>
                    <p><?php esc_html_e('This initiative strengthens equipment security, improves turnaround time, and builds a more efficient rental system. Your data is treated with strict confidentiality under our privacy protocols.', 'christocentric'); ?></p>
                    <p><?php esc_html_e('Personal information is collected to verify client identity, create customer accounts, and enhance service — in compliance with the Ghana Data Protection Act, 2012 (Act 843).', 'christocentric'); ?></p>
                    <h4><?php esc_html_e('Your rights', 'christocentric'); ?></h4>
                    <ul class="ccr-agreement-rights">
                        <li><?php esc_html_e('You may access, update, or request deletion of your data at any time.', 'christocentric'); ?></li>
                        <li><?php echo esc_html(sprintf(__('You may withdraw consent by contacting %s.', 'christocentric'), $supportPhone)); ?></li>
                        <li><?php esc_html_e('We will not share your data without your explicit consent.', 'christocentric'); ?></li>
                        <li><?php esc_html_e('Data is stored securely and kept only as long as needed for rental operations.', 'christocentric'); ?></li>
                    </ul>
                    <p class="ccr-agreement-muted"><?php esc_html_e('Thank you for choosing Christocentric Rentals — where excellence meets integrity.', 'christocentric'); ?></p>
                </section>

                <section class="ccr-agreement-pane" data-ccr-agreement-pane="2" hidden>
                    <h3><?php esc_html_e('Personal information', 'christocentric'); ?></h3>
                    <p class="ccr-agreement-muted"><?php esc_html_e('Provide accurate details so we can verify your identity and keep rentals secure.', 'christocentric'); ?></p>
                    <div class="ccr-agreement-grid">
                        <p class="form-row form-row-wide">
                            <label for="ccr_company_name"><?php esc_html_e('Company name', 'christocentric'); ?> <span class="required">*</span></label>
                            <input type="text" class="input-text" id="ccr_company_name" name="company_name" value="<?php echo esc_attr($v('company_name')); ?>" required>
                        </p>
                        <p class="form-row form-row-wide">
                            <label for="ccr_full_name"><?php esc_html_e('Full name', 'christocentric'); ?> <span class="required">*</span></label>
                            <input type="text" class="input-text" id="ccr_full_name" name="full_name" value="<?php echo esc_attr($v('full_name')); ?>" required>
                        </p>
                        <p class="form-row form-row-wide">
                            <label for="ccr_popular_name"><?php esc_html_e('Popular name in your area of residence', 'christocentric'); ?> <span class="required">*</span></label>
                            <input type="text" class="input-text" id="ccr_popular_name" name="popular_name" value="<?php echo esc_attr($v('popular_name')); ?>" required>
                        </p>
                        <p class="form-row">
                            <label for="ccr_email"><?php esc_html_e('Active email address', 'christocentric'); ?> <span class="required">*</span></label>
                            <input type="email" class="input-text" id="ccr_email" name="email" value="<?php echo esc_attr($v('email') ?: $user->user_email); ?>" required>
                        </p>
                        <p class="form-row">
                            <label for="ccr_phone"><?php esc_html_e('Active phone number', 'christocentric'); ?> <span class="required">*</span></label>
                            <input type="tel" class="input-text" id="ccr_phone" name="phone" value="<?php echo esc_attr($v('phone')); ?>" required>
                        </p>
                        <p class="form-row">
                            <label for="ccr_emergency_phone"><?php esc_html_e('Active emergency number', 'christocentric'); ?> <span class="required">*</span></label>
                            <input type="tel" class="input-text" id="ccr_emergency_phone" name="emergency_phone" value="<?php echo esc_attr($v('emergency_phone')); ?>" required>
                        </p>
                        <p class="form-row">
                            <label for="ccr_occupation"><?php esc_html_e('Occupation / profession', 'christocentric'); ?> <span class="required">*</span></label>
                            <input type="text" class="input-text" id="ccr_occupation" name="occupation" value="<?php echo esc_attr($v('occupation')); ?>" required>
                        </p>
                        <p class="form-row form-row-wide">
                            <span class="ccr-field-label"><?php esc_html_e('ID card type (client)', 'christocentric'); ?> <span class="required">*</span></span>
                            <span class="ccr-radio-list">
                                <?php foreach ($idTypes as $val => $label) : ?>
                                    <label><input type="radio" name="id_card_type" value="<?php echo esc_attr($val); ?>" <?php checked($v('id_card_type'), $val); ?> required> <?php echo esc_html($label); ?></label>
                                <?php endforeach; ?>
                            </span>
                        </p>
                        <p class="form-row form-row-wide">
                            <label for="ccr_id_card_number"><?php esc_html_e('ID card number', 'christocentric'); ?> <span class="required">*</span></label>
                            <input type="text" class="input-text" id="ccr_id_card_number" name="id_card_number" value="<?php echo esc_attr($v('id_card_number')); ?>" required>
                        </p>
                        <p class="form-row form-row-wide">
                            <span class="ccr-field-label" id="ccr_id_card_file_label"><?php esc_html_e('Scanned copy / clear photo of your ID (front & back)', 'christocentric'); ?> <span class="required">*</span></span>
                            <span class="ccr-file-field">
                                <input type="file" class="ccr-file-input" id="ccr_id_card_file" name="id_card_file" accept=".jpg,.jpeg,.png,.webp,.pdf,image/*,application/pdf" aria-labelledby="ccr_id_card_file_label" <?php echo $v('id_card_file') ? '' : 'required'; ?>>
                                <label for="ccr_id_card_file" class="ccr-file-btn"><?php esc_html_e('Choose file', 'christocentric'); ?></label>
                                <span class="ccr-file-name" data-ccr-file-name><?php echo $v('id_card_file') ? esc_html(sprintf(__('On file: %s', 'christocentric'), $fileLabel($v('id_card_file')))) : esc_html__('No file chosen', 'christocentric'); ?></span>
                            </span>
                        </p>
                        <p class="form-row form-row-wide">
                            <label for="ccr_place_of_residence"><?php esc_html_e('Place of residence', 'christocentric'); ?> <span class="required">*</span></label>
                            <input type="text" class="input-text" id="ccr_place_of_residence" name="place_of_residence" value="<?php echo esc_attr($v('place_of_residence')); ?>" required>
                        </p>
                        <p class="form-row">
                            <label for="ccr_gps_address"><?php esc_html_e('GPS address', 'christocentric'); ?> <span class="required">*</span></label>
                            <input type="text" class="input-text" id="ccr_gps_address" name="gps_address" value="<?php echo esc_attr($v('gps_address')); ?>" required>
                        </p>
                        <p class="form-row">
                            <label for="ccr_nearest_landmark"><?php esc_html_e('Nearest landmark to place of residence', 'christocentric'); ?> <span class="required">*</span></label>
                            <input type="text" class="input-text" id="ccr_nearest_landmark" name="nearest_landmark" value="<?php echo esc_attr($v('nearest_landmark')); ?>" required>
                        </p>
                        <p class="form-row form-row-wide">
                            <span class="ccr-field-label" id="ccr_gps_photo_label"><?php esc_html_e('Photo of your residential GPS address', 'christocentric'); ?> <span class="required">*</span></span>
                            <span class="ccr-file-field">
                                <input type="file" class="ccr-file-input" id="ccr_gps_photo" name="gps_photo" accept=".jpg,.jpeg,.png,.webp,.pdf,image/*,application/pdf" aria-labelledby="ccr_gps_photo_label" <?php echo $v('gps_photo') ? '' : 'required'; ?>>
                                <label for="ccr_gps_photo" class="ccr-file-btn"><?php esc_html_e('Choose file', 'christocentric'); ?></label>
                                <span class="ccr-file-name" data-ccr-file-name><?php echo $v('gps_photo') ? esc_html(sprintf(__('On file: %s', 'christocentric'), $fileLabel($v('gps_photo')))) : esc_html__('No file chosen', 'christocentric'); ?></span>
                            </span>
                        </p>
                    </div>
                </section>

                <section class="ccr-agreement-pane" data-ccr-agreement-pane="3" hidden>
                    <h3><?php esc_html_e('Social media handles', 'christocentric'); ?></h3>
                    <p class="ccr-agreement-muted"><?php esc_html_e('Provide names and links so we can connect with you on other platforms.', 'christocentric'); ?></p>
                    <div class="ccr-agreement-grid">
                        <p class="form-row form-row-wide">
                            <label for="ccr_instagram"><?php esc_html_e('Personal Instagram handle', 'christocentric'); ?> <span class="required">*</span></label>
                            <input type="text" class="input-text" id="ccr_instagram" name="instagram" value="<?php echo esc_attr($v('instagram')); ?>" placeholder="@yourhandle" required>
                        </p>
                        <p class="form-row">
                            <label for="ccr_tiktok"><?php esc_html_e('Personal TikTok handle', 'christocentric'); ?></label>
                            <input type="text" class="input-text" id="ccr_tiktok" name="tiktok" value="<?php echo esc_attr($v('tiktok')); ?>" placeholder="@yourhandle">
                        </p>
                        <p class="form-row">
                            <label for="ccr_facebook"><?php esc_html_e('Personal Facebook handle', 'christocentric'); ?></label>
                            <input type="text" class="input-text" id="ccr_facebook" name="facebook" value="<?php echo esc_attr($v('facebook')); ?>">
                        </p>
                    </div>
                </section>

                <section class="ccr-agreement-pane" data-ccr-agreement-pane="4" hidden>
                    <h3><?php esc_html_e('Guarantor / trustee information', 'christocentric'); ?></h3>
                    <p class="ccr-agreement-muted"><?php esc_html_e('Provide a contact Christocentric Rentals may reach for verification or if you are unavailable. At least one trustee must upload a valid Ghana Card.', 'christocentric'); ?></p>

                    <h4><?php esc_html_e('Guarantor 1', 'christocentric'); ?></h4>
                    <div class="ccr-agreement-grid">
                        <p class="form-row form-row-wide">
                            <label for="ccr_g1_name"><?php esc_html_e('Full name of guarantor 1', 'christocentric'); ?> <span class="required">*</span></label>
                            <span class="ccr-field-hint"><?php esc_html_e('Exactly as on their Ghana Card. Parent, family, or trusted person we can call if needed.', 'christocentric'); ?></span>
                            <input type="text" class="input-text" id="ccr_g1_name" name="g1_name" value="<?php echo esc_attr($v('g1_name')); ?>" required>
                        </p>
                        <p class="form-row form-row-wide">
                            <span class="ccr-field-label"><?php esc_html_e('Relationship to you', 'christocentric'); ?> <span class="required">*</span></span>
                            <span class="ccr-radio-list">
                                <?php foreach ($g1Rels as $val => $label) : ?>
                                    <label><input type="radio" name="g1_relationship" value="<?php echo esc_attr($val); ?>" <?php checked($v('g1_relationship'), $val); ?> required> <?php echo esc_html($label); ?></label>
                                <?php endforeach; ?>
                            </span>
                        </p>
                        <p class="form-row">
                            <label for="ccr_g1_phone"><?php esc_html_e('Active phone number of guarantor', 'christocentric'); ?> <span class="required">*</span></label>
                            <input type="tel" class="input-text" id="ccr_g1_phone" name="g1_phone" value="<?php echo esc_attr($v('g1_phone')); ?>" required>
                        </p>
                        <p class="form-row">
                            <label for="ccr_g1_residence"><?php esc_html_e('Place of residence of guarantor', 'christocentric'); ?> <span class="required">*</span></label>
                            <input type="text" class="input-text" id="ccr_g1_residence" name="g1_residence" value="<?php echo esc_attr($v('g1_residence')); ?>" required>
                        </p>
                        <p class="form-row form-row-wide">
                            <span class="ccr-field-label" id="ccr_g1_id_file_label"><?php esc_html_e('Guarantor\'s Ghana Card (front & back)', 'christocentric'); ?> <span class="required">*</span></span>
                            <span class="ccr-file-field">
                                <input type="file" class="ccr-file-input" id="ccr_g1_id_file" name="g1_id_file" accept=".jpg,.jpeg,.png,.webp,.pdf,image/*,application/pdf" aria-labelledby="ccr_g1_id_file_label" <?php echo $v('g1_id_file') ? '' : 'required'; ?>>
                                <label for="ccr_g1_id_file" class="ccr-file-btn"><?php esc_html_e('Choose file', 'christocentric'); ?></label>
                                <span class="ccr-file-name" data-ccr-file-name><?php echo $v('g1_id_file') ? esc_html(sprintf(__('On file: %s', 'christocentric'), $fileLabel($v('g1_id_file')))) : esc_html__('No file chosen', 'christocentric'); ?></span>
                            </span>
                        </p>
                    </div>

                    <h4><?php esc_html_e('Guarantor 2 (optional)', 'christocentric'); ?></h4>
                    <div class="ccr-agreement-grid">
                        <p class="form-row form-row-wide">
                            <label for="ccr_g2_name"><?php esc_html_e('Full name of guarantor 2', 'christocentric'); ?></label>
                            <input type="text" class="input-text" id="ccr_g2_name" name="g2_name" value="<?php echo esc_attr($v('g2_name')); ?>">
                        </p>
                        <p class="form-row form-row-wide">
                            <span class="ccr-field-label"><?php esc_html_e('Relationship to you', 'christocentric'); ?></span>
                            <span class="ccr-radio-list">
                                <?php foreach ($g2Rels as $val => $label) : ?>
                                    <label><input type="radio" name="g2_relationship" value="<?php echo esc_attr($val); ?>" <?php checked($v('g2_relationship'), $val); ?>> <?php echo esc_html($label); ?></label>
                                <?php endforeach; ?>
                            </span>
                        </p>
                        <p class="form-row">
                            <label for="ccr_g2_phone"><?php esc_html_e('Active phone number of guarantor', 'christocentric'); ?></label>
                            <input type="tel" class="input-text" id="ccr_g2_phone" name="g2_phone" value="<?php echo esc_attr($v('g2_phone')); ?>">
                        </p>
                        <p class="form-row">
                            <label for="ccr_g2_residence"><?php esc_html_e('Place of residence of guarantor', 'christocentric'); ?></label>
                            <input type="text" class="input-text" id="ccr_g2_residence" name="g2_residence" value="<?php echo esc_attr($v('g2_residence')); ?>">
                        </p>
                        <p class="form-row form-row-wide">
                            <span class="ccr-field-label" id="ccr_g2_id_file_label"><?php esc_html_e('Guarantor 2 Ghana Card (front & back)', 'christocentric'); ?></span>
                            <span class="ccr-file-field">
                                <input type="file" class="ccr-file-input" id="ccr_g2_id_file" name="g2_id_file" accept=".jpg,.jpeg,.png,.webp,.pdf,image/*,application/pdf" aria-labelledby="ccr_g2_id_file_label">
                                <label for="ccr_g2_id_file" class="ccr-file-btn"><?php esc_html_e('Choose file', 'christocentric'); ?></label>
                                <span class="ccr-file-name" data-ccr-file-name><?php echo $v('g2_id_file') ? esc_html(sprintf(__('On file: %s', 'christocentric'), $fileLabel($v('g2_id_file')))) : esc_html__('No file chosen', 'christocentric'); ?></span>
                            </span>
                        </p>
                    </div>
                </section>

                <section class="ccr-agreement-pane" data-ccr-agreement-pane="5" hidden>
                    <h3><?php esc_html_e('Agreement and declaration', 'christocentric'); ?></h3>
                    <p><?php esc_html_e('By submitting this form, you confirm that all details provided are accurate and you agree to abide by Christocentric Rentals\' rental terms and agreement.', 'christocentric'); ?></p>
                    <p><a href="<?php echo esc_url($termsUrl); ?>" target="_blank" rel="noopener"><?php esc_html_e('Read the full Terms & Conditions', 'christocentric'); ?></a></p>
                    <p class="ccr-agreement-muted"><?php esc_html_e('By submitting this form, you confirm that:', 'christocentric'); ?></p>
                    <p class="form-row">
                        <label class="woocommerce-form__label woocommerce-form__label-for-checkbox">
                            <input type="checkbox" name="declare_read" value="1" required>
                            <span><?php esc_html_e('You have read and understood this form.', 'christocentric'); ?></span>
                        </label>
                    </p>
                    <p class="form-row">
                        <label class="woocommerce-form__label woocommerce-form__label-for-checkbox">
                            <input type="checkbox" name="declare_consent" value="1" required>
                            <span><?php esc_html_e('You consent to the collection, storage, and processing of your personal data as outlined above.', 'christocentric'); ?></span>
                        </label>
                    </p>
                    <p class="form-row">
                        <label class="woocommerce-form__label woocommerce-form__label-for-checkbox">
                            <input type="checkbox" name="declare_rights" value="1" required>
                            <span><?php esc_html_e('You understand your rights under the Ghana Data Protection Act, 2012.', 'christocentric'); ?></span>
                        </label>
                    </p>
                </section>

                <footer class="ccr-agreement-modal-foot">
                    <button type="button" class="button ccr-agreement-btn-secondary" data-ccr-agreement-prev hidden><?php esc_html_e('Back', 'christocentric'); ?></button>
                    <button type="button" class="button" data-ccr-agreement-next><?php esc_html_e('Continue', 'christocentric'); ?></button>
                    <button type="submit" class="button" data-ccr-agreement-submit hidden><?php echo $complete ? esc_html__('Update & save', 'christocentric') : esc_html__('Submit verification', 'christocentric'); ?></button>
                </footer>
            </form>
        </div>
    </div>
</div>
