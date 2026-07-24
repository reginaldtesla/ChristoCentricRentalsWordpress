<?php
defined('ABSPATH') || exit;
global $product;
?>
<li <?php wc_product_class('', $product); ?>>
    <?php get_template_part('template-parts/product-card', null, ['product' => $product]); ?>
</li>
