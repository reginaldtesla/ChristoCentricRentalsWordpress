<?php defined('ABSPATH') || exit; $features = ccr_site_config('footer_features', []); if ($features === []) { return; } ?>
<section class="border-t border-gray-200 bg-gray-50">
    <div class="container-site py-8">
        <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
            <?php foreach ($features as $feature) : ?>
                <div>
                    <p class="text-sm font-semibold text-gray-900"><?php echo esc_html($feature['title'] ?? ''); ?></p>
                    <p class="mt-1 text-sm text-gray-600"><?php echo esc_html($feature['subtitle'] ?? ''); ?></p>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
