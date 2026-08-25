<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'after_setup_theme', 'conditionally_load_custom_upsells_products' );
function conditionally_load_custom_upsells_products() {
    $theme = wp_get_theme();
    if ( $theme->get( 'Name' ) !== 'RentPro' ) {
        return;
    }

    add_action( 'woocommerce_after_single_product', 'rental_display_upsells_products', 30 );
    function rental_display_upsells_products() {
        global $product;
        if ( ! $product || 'product' !== get_post_type( $product->get_id() ) ) {
            return;
        }
        $upsells_ids = $product->get_upsell_ids( 6 );
        if ( empty( $upsells_ids ) ) {
            return;
        }
        $args = array(
            'post_type'      => 'product',
            'post__in'       => $upsells_ids,
            'posts_per_page' => count( $upsells_ids ),
            'ignore_sticky_posts' => 1,
        );
        $upsells_query = new WP_Query( $args );
        if ( ! $upsells_query->have_posts() ) {
            wp_reset_postdata();
            return;
        }
        ?>
        <section class="crc-upsells-products">
            <h2><?php esc_html_e( 'You may also like', 'my-custom-upsells-products' ); ?></h2>
            <div class="crc-carousel-upsell owl-carousel">
                <?php while ( $upsells_query->have_posts() ) : $upsells_query->the_post(); ?>
                    <div class="crc-item">
                        <a href="<?php the_permalink(); ?>" class="crc-product-link">
                            <?php
                            if ( has_post_thumbnail() ) {
                                the_post_thumbnail( 'medium' );
                            } else {
                                echo wc_placeholder_img( 'medium' );
                            }
                            ?>
                            <h3 class="crc-title"><?php the_title(); ?></h3>
                        </a>
                        <div class="crc-price">
                            <?php echo wc_get_product( get_the_ID() )->get_price_html(); ?>
                        </div>
                        <a href="<?php echo esc_url( wc_get_cart_url() . '?add-to-cart=' . get_the_ID() ); ?>"
                           class="button crc-add-to-cart">
                            <?php esc_html_e( 'Add to cart', 'my-custom-upsells-products' ); ?>
                        </a>
                    </div>
                <?php endwhile; wp_reset_postdata(); ?>
            </div><!-- /.crc-carousel-upsell -->
        </section>
        <?php
    }

    add_action( 'wp_enqueue_scripts', 'crc_enqueue_assets_upsells', 20 );
    function crc_enqueue_assets_upsells() {
        if ( ! function_exists('is_product') || ! is_product() ) {
            return;
        }

        rentpro_enqueue_owlcarousel_core();

        wp_enqueue_style(
            'crc-custom-css-upsell',
            plugin_dir_url( __FILE__ ) . '../assets/css/rental-upsell-products.css',
            array( 'owl-carousel-css' ),
            '1.0'
        );

        // Custom JS for upsells: initialize `.crc-carousel-upsell`
        wp_enqueue_script(
            'crc-custom-js-upsell',
            plugin_dir_url( __FILE__ ) . '../assets/js/rental-upsell-products.js',
            array( 'jquery', 'owl-carousel-js' ),
            '1.0',
            true
        );
    }
}
