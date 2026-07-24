<?php get_header(); ?>
<?php if (have_posts()) : while (have_posts()) : the_post(); ?>
    <?php get_template_part('template-parts/page-hero', null, ['title' => get_the_title()]); ?>
    <div class="container-site py-10">
        <article class="ccr-doc-body max-w-3xl">
            <?php the_content(); ?>
        </article>
    </div>
<?php endwhile; endif; ?>
<?php get_footer(); ?>
