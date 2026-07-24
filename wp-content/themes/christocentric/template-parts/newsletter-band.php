<?php
defined('ABSPATH') || exit;

$n = ccr_site_config('newsletter', []);
$flash = class_exists('CCR_Newsletter') ? CCR_Newsletter::flash() : null;
$success = is_array($flash) && ! empty($flash['success']);
$errors = is_array($flash['errors'] ?? null) ? $flash['errors'] : [];
$oldEmail = is_array($flash) ? ($flash['old_email'] ?? '') : '';
?>
<section id="newsletter" class="newsletter-band">
    <div class="container-site">
        <div class="newsletter-card">
            <div class="newsletter-signup">
                <p class="newsletter-eyebrow"><?php echo esc_html($n['eyebrow'] ?? ''); ?></p>
                <h2 class="newsletter-heading"><?php echo esc_html($n['heading'] ?? ''); ?></h2>
                <p class="newsletter-subtext"><?php echo esc_html($n['subtext'] ?? ''); ?></p>

                <?php if ($success) : ?>
                    <p class="newsletter-flash newsletter-flash--success" role="status"><?php echo esc_html($flash['success']); ?></p>
                <?php endif; ?>

                <?php if ($errors !== []) : ?>
                    <?php foreach ($errors as $error) : ?>
                        <p class="newsletter-flash newsletter-flash--error" role="alert"><?php echo esc_html($error); ?></p>
                    <?php endforeach; ?>
                <?php endif; ?>

                <form class="newsletter-form" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post">
                    <input type="hidden" name="action" value="<?php echo esc_attr(CCR_Newsletter::SUBSCRIBE_ACTION); ?>">
                    <?php wp_nonce_field('ccr_newsletter'); ?>
                    <input type="text" name="ccr_company" value="" tabindex="-1" autocomplete="off" class="hidden" aria-hidden="true">
                    <label for="newsletter-email" class="sr-only">Email address</label>
                    <input
                        id="newsletter-email"
                        type="email"
                        name="email"
                        value="<?php echo esc_attr($oldEmail); ?>"
                        placeholder="Your email address"
                        required
                        class="newsletter-input<?php echo $errors !== [] ? ' newsletter-input--error' : ''; ?>"
                    >
                    <button type="submit" class="newsletter-submit"><?php echo esc_html($n['button'] ?? 'Subscribe'); ?></button>
                </form>

                <p class="newsletter-legal">
                    <?php echo esc_html($n['privacy_note'] ?? ''); ?>
                    <a href="<?php echo esc_url(home_url('/privacy/')); ?>">Privacy Policy</a>.
                </p>
            </div>
            <div class="newsletter-note">
                <div class="newsletter-note-inner">
                    <img src="<?php echo esc_url(ccr_image_url($n['founder_avatar'] ?? 'brand/icon.png')); ?>" alt="" class="newsletter-avatar" width="72" height="72">
                    <blockquote class="newsletter-quote"><p><?php echo esc_html($n['founder_quote'] ?? ''); ?></p></blockquote>
                    <p class="newsletter-signature">
                        <span class="newsletter-signature-name"><?php echo esc_html($n['founder_name'] ?? ''); ?></span>
                        <span class="newsletter-signature-role"><?php echo esc_html($n['founder_role'] ?? ''); ?></span>
                    </p>
                </div>
            </div>
        </div>
    </div>
</section>
