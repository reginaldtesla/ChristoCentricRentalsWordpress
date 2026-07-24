<?php
defined('ABSPATH') || exit;

$contact = ccr_site_config('contact', []);
$flash = class_exists('CCR_Contact_Form') ? CCR_Contact_Form::flash() : null;
$old = is_array($flash['old'] ?? null) ? $flash['old'] : [];
$errors = is_array($flash['errors'] ?? null) ? $flash['errors'] : [];
$success = is_array($flash) && ! empty($flash['success']);
$form_action = class_exists('CCR_Contact_Form') ? CCR_Contact_Form::action_url() : home_url('/contact/');
$form_action_name = class_exists('CCR_Contact_Form') ? CCR_Contact_Form::ACTION : 'ccr_contact_form';

get_header();
get_template_part('template-parts/page-hero', null, [
    'title' => 'Contact Us',
    'subtitle' => 'Pickup, rentals, and support',
]);
?>
<div class="container-site py-10 md:py-12">
    <div class="grid gap-10 lg:grid-cols-2">
        <div>
            <h2 class="mb-5 text-lg font-semibold text-gray-900">Get in touch</h2>
            <dl class="space-y-5 text-sm text-gray-600">
                <div>
                    <dt class="font-medium text-gray-900">Pickup location</dt>
                    <dd class="mt-1"><?php echo esc_html($contact['address'] ?? ''); ?><br><?php echo esc_html($contact['city'] ?? ''); ?></dd>
                </div>
                <div>
                    <dt class="font-medium text-gray-900">Phone</dt>
                    <dd class="mt-1"><a href="tel:<?php echo esc_attr($contact['phone'] ?? ''); ?>" class="hover:text-primary"><?php echo esc_html($contact['phone_display'] ?? ''); ?></a></dd>
                </div>
                <div>
                    <dt class="font-medium text-gray-900">Customer support</dt>
                    <dd class="mt-1"><a href="mailto:<?php echo esc_attr($contact['support_email'] ?? ''); ?>" class="hover:text-primary"><?php echo esc_html($contact['support_email'] ?? ''); ?></a></dd>
                </div>
                <div>
                    <dt class="font-medium text-gray-900">General inquiries</dt>
                    <dd class="mt-1"><a href="mailto:<?php echo esc_attr($contact['email'] ?? ''); ?>" class="hover:text-primary"><?php echo esc_html($contact['email'] ?? ''); ?></a></dd>
                </div>
                <div>
                    <dt class="font-medium text-gray-900">Feedback</dt>
                    <dd class="mt-1"><a href="mailto:<?php echo esc_attr($contact['feedback_email'] ?? ''); ?>" class="hover:text-primary"><?php echo esc_html($contact['feedback_email'] ?? ''); ?></a></dd>
                </div>
            </dl>
            <p class="mt-6 text-sm text-gray-600">
                We respond within 24 hours on business days.
                <a href="<?php echo esc_url(home_url('/help/')); ?>" class="text-primary hover:underline">Help &amp; Support guide</a>
            </p>
        </div>

        <form class="border border-gray-200 bg-white p-6" action="<?php echo esc_url($form_action); ?>" method="post">
            <?php wp_nonce_field($form_action_name, 'ccr_contact_nonce'); ?>
            <input type="hidden" name="action" value="<?php echo esc_attr($form_action_name); ?>">
            <input type="text" name="ccr_website" value="" tabindex="-1" autocomplete="off" class="hidden" aria-hidden="true">

            <h2 class="mb-4 text-lg font-semibold text-gray-900">Send a message</h2>

            <?php if ($success) : ?>
                <div class="mb-4 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
                    Thank you! Your message has been sent.
                </div>
            <?php endif; ?>

            <?php if ($errors !== []) : ?>
                <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                    <ul class="list-disc space-y-1 pl-4">
                        <?php foreach ($errors as $error) : ?>
                            <li><?php echo esc_html($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <div class="space-y-4">
                <div>
                    <label for="ccr-contact-name" class="mb-1 block text-sm font-medium text-gray-700">Name</label>
                    <input
                        type="text"
                        id="ccr-contact-name"
                        name="name"
                        value="<?php echo esc_attr($old['name'] ?? ''); ?>"
                        class="ccr-field w-full rounded border border-gray-300 px-3 py-2.5 text-sm outline-none focus:border-primary focus:ring-1 focus:ring-primary"
                        required
                    >
                </div>
                <div>
                    <label for="ccr-contact-email" class="mb-1 block text-sm font-medium text-gray-700">Email</label>
                    <input
                        type="email"
                        id="ccr-contact-email"
                        name="email"
                        value="<?php echo esc_attr($old['email'] ?? ''); ?>"
                        class="ccr-field w-full rounded border border-gray-300 px-3 py-2.5 text-sm outline-none focus:border-primary focus:ring-1 focus:ring-primary"
                        required
                    >
                </div>
                <div>
                    <label for="ccr-contact-message" class="mb-1 block text-sm font-medium text-gray-700">Message</label>
                    <textarea
                        id="ccr-contact-message"
                        name="message"
                        rows="5"
                        class="ccr-field w-full rounded border border-gray-300 px-3 py-2.5 text-sm outline-none focus:border-primary focus:ring-1 focus:ring-primary"
                        required
                    ><?php echo esc_textarea($old['message'] ?? ''); ?></textarea>
                </div>
                <button type="submit" class="btn-solid w-full">Send message</button>
            </div>
        </form>
    </div>
</div>
<?php get_footer(); ?>
