<?php
defined('ABSPATH') || exit;
$title = $args['title'] ?? get_the_title();
$subtitle = $args['subtitle'] ?? '';
?>
<div class="border-b border-gray-200 bg-gray-50">
    <div class="container-site py-10 md:py-12">
        <?php if ($subtitle !== '') : ?>
            <p class="page-hero-subtitle"><?php echo esc_html($subtitle); ?></p>
        <?php endif; ?>
        <h1 class="page-hero-title"><?php echo esc_html($title); ?></h1>
    </div>
</div>
