<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="<?php echo esc_attr(get_bloginfo('description', 'display') ?: 'Premium camera, lens, lighting and filmmaking gear rentals in Ghana.'); ?>">
    <link rel="icon" href="<?php echo esc_url(ccr_theme_asset('favicon.ico')); ?>" sizes="any">
    <link rel="icon" href="<?php echo esc_url(ccr_theme_asset('images/brand/icon.png')); ?>" type="image/png" sizes="512x512">
    <?php wp_head(); ?>
</head>
<body <?php body_class('flex min-h-screen flex-col bg-white pb-16 md:pb-0'); ?>>
<?php wp_body_open(); ?>
<?php get_template_part('template-parts/header'); ?>
<main class="flex-1">
    <?php if (function_exists('wc_print_notices')) { wc_print_notices(); } ?>
