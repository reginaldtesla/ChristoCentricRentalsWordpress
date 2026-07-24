<?php

/**
 * Convert Laravel page body partials into WordPress theme PHP includes.
 */
$wpRoot = dirname(__DIR__);
$laravelPartials = dirname($wpRoot) . '/ChristocentricRentals/resources/views/pages/partials';
$themePartials = $wpRoot . '/wp-content/themes/christocentric/template-parts/pages';
$pages = ['help', 'terms', 'privacy'];

if (! is_dir($themePartials)) {
    mkdir($themePartials, 0755, true);
}

$replacements = [
    "{{ route('shop') }}" => "<?php echo esc_url(ccr_shop_url()); ?>",
    "{{ route('terms') }}" => "<?php echo esc_url(home_url('/terms/')); ?>",
    "{{ route('faq') }}" => "<?php echo esc_url(home_url('/faq/')); ?>",
    "{{ route('contact') }}" => "<?php echo esc_url(home_url('/contact/')); ?>",
    "{{ route('help') }}" => "<?php echo esc_url(home_url('/help/')); ?>",
    "{{ route('privacy') }}" => "<?php echo esc_url(home_url('/privacy/')); ?>",
    '{{ config(\'site.contact.address\') }}' => "<?php echo esc_html(ccr_site_config('contact.address')); ?>",
    '{{ config(\'site.contact.city\') }}' => "<?php echo esc_html(ccr_site_config('contact.city')); ?>",
    '{{ config(\'site.contact.support_email\') }}' => "<?php echo esc_html(ccr_site_config('contact.support_email')); ?>",
    '{{ config(\'site.contact.feedback_email\') }}' => "<?php echo esc_html(ccr_site_config('contact.feedback_email')); ?>",
    '{{ config(\'site.contact.phone\') }}' => "<?php echo esc_attr(ccr_site_config('contact.phone')); ?>",
    '{{ config(\'site.contact.phone_display\') }}' => "<?php echo esc_html(ccr_site_config('contact.phone_display')); ?>",
    '{{ config(\'app.name\') }}' => "<?php echo esc_html(get_bloginfo('name')); ?>",
    '{{ parse_url(config(\'app.url\'), PHP_URL_HOST) ?: \'www.christocentricrentals.com\' }}' => 'christocentricrentals.com',
];

foreach ($pages as $page) {
    $source = "{$laravelPartials}/{$page}-body.blade.php";
    $target = "{$themePartials}/{$page}-body.php";

    if (! is_file($source)) {
        echo "Skip missing: {$source}\n";
        continue;
    }

    $html = file_get_contents($source);
    $html = str_replace(array_keys($replacements), array_values($replacements), $html);
    $output = "<?php\n/** Auto-synced from Laravel pages/partials/{$page}-body.blade.php */\n?>\n" . $html;
    file_put_contents($target, $output);
    echo "Synced {$page}-body.php\n";
}

echo "Done.\n";
