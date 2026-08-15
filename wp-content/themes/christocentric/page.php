<?php get_header(); ?>
<?php if (have_posts()) : while (have_posts()) : the_post(); ?>
    <?php
    $isAccountGate = function_exists('is_account_page') && is_account_page() && ! is_user_logged_in();
    $isAccountLoggedIn = function_exists('is_account_page') && is_account_page() && is_user_logged_in();
    $isWooWide = (function_exists('is_checkout') && is_checkout())
        || (function_exists('is_cart') && is_cart());
    if ($isAccountGate) :
        ?>
        <div class="ccr-auth-page">
            <div class="container-site py-12 md:py-16">
                <?php the_content(); ?>
            </div>
        </div>
    <?php elseif ($isAccountLoggedIn) : ?>
        <?php the_content(); ?>
    <?php elseif ($isWooWide) : ?>
        <?php get_template_part('template-parts/page-hero', null, ['title' => get_the_title()]); ?>
        <div class="container-site py-10 ccr-woo-wide">
            <?php the_content(); ?>
        </div>
    <?php else : ?>
        <?php get_template_part('template-parts/page-hero', null, ['title' => get_the_title()]); ?>
        <div class="container-site py-10">
            <article class="ccr-doc-body max-w-3xl">
                <?php the_content(); ?>
            </article>
        </div>
    <?php endif; ?>
<?php endwhile; endif; ?>
<?php get_footer(); ?>
