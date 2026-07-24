<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php wp_head(); ?>
</head>
<body <?php body_class('flex min-h-screen flex-col bg-white pb-16 md:pb-0'); ?>>
<?php wp_body_open(); ?>
<?php get_template_part('template-parts/header'); ?>
<main class="flex-1">
<?php if (function_exists('wc_print_notices')) { wc_print_notices(); } ?>
