<?php defined('ABSPATH') || exit; ?>
<?php get_template_part('template-parts/footer-features'); ?>
<?php get_template_part('template-parts/newsletter-band'); ?>
<footer class="border-t border-gray-200 bg-white">
    <div class="container-site py-10">
        <div class="grid gap-8 md:grid-cols-2 lg:grid-cols-4">
            <div>
                <img src="<?php echo esc_url(ccr_theme_asset('images/brand/logo.png')); ?>" alt="<?php echo esc_attr(get_bloginfo('name')); ?>" class="mb-3 h-12 w-auto">
                <p class="text-sm leading-relaxed text-gray-600">Camera, lens, lighting and production gear for rent in Kumasi, Ghana.</p>
                <p class="mt-3 text-sm text-gray-600">
                    <a href="tel:<?php echo esc_attr(ccr_site_config('contact.phone', '')); ?>" class="hover:text-primary"><?php echo esc_html(ccr_site_config('contact.phone_display', ccr_site_config('contact.phone', ''))); ?></a><br>
                    <a href="mailto:<?php echo esc_attr(ccr_site_config('contact.support_email', '')); ?>" class="hover:text-primary"><?php echo esc_html(ccr_site_config('contact.support_email', '')); ?></a>
                </p>
            </div>
            <div>
                <h3 class="mb-3 text-sm font-semibold text-gray-900">Company</h3>
                <ul class="space-y-2 text-sm text-gray-600">
                    <li><a href="<?php echo esc_url(home_url('/about/')); ?>" class="hover:text-primary">About</a></li>
                    <li><a href="<?php echo esc_url(home_url('/studio/')); ?>" class="hover:text-primary">Studio</a></li>
                    <li><a href="<?php echo esc_url(home_url('/contact/')); ?>" class="hover:text-primary">Contact</a></li>
                </ul>
            </div>
            <div>
                <h3 class="mb-3 text-sm font-semibold text-gray-900">Customer Service</h3>
                <ul class="space-y-2 text-sm text-gray-600">
                    <li><a href="<?php echo esc_url(home_url('/help/')); ?>" class="hover:text-primary">Help Center</a></li>
                    <li><a href="<?php echo esc_url(home_url('/faq/')); ?>" class="hover:text-primary">FAQs</a></li>
                    <li><a href="<?php echo esc_url(home_url('/contact/')); ?>" class="hover:text-primary">Locate Us</a></li>
                </ul>
            </div>
            <div>
                <h3 class="mb-3 text-sm font-semibold text-gray-900">Policies</h3>
                <ul class="space-y-2 text-sm text-gray-600">
                    <li><a href="<?php echo esc_url(home_url('/privacy/')); ?>" class="hover:text-primary">Privacy</a></li>
                    <li><a href="<?php echo esc_url(home_url('/terms/')); ?>" class="hover:text-primary">Terms &amp; conditions</a></li>
                </ul>
            </div>
        </div>
        <p class="mt-8 border-t border-gray-200 pt-6 text-center text-sm text-gray-500">&copy; <?php echo esc_html(gmdate('Y')); ?> <?php echo esc_html(get_bloginfo('name')); ?></p>
    </div>
</footer>
