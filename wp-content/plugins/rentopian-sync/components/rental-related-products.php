<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'after_setup_theme', 'conditionally_load_custom_related_products' );
function conditionally_load_custom_related_products() {
    $theme = wp_get_theme();
    if ( $theme->get( 'Name' ) !== 'RentPro' ) {
        return; // Only load if RentPro theme is active
    }

    // Hook display function
    add_action( 'woocommerce_after_single_product', 'rental_display_related_products', 30 );
    function rental_display_related_products() {
        global $product;
        if ( ! $product || 'product' !== get_post_type( $product->get_id() ) ) {
            return;
        }
        // Get related IDs
        $related_ids = $product->get_related( 6 );
        if ( empty( $related_ids ) ) {
            return;
        }
        $related_ids = array_unique( array_filter( $related_ids ) );
        $args = array(
            'post_type'           => 'product',
            'post__in'            => $related_ids,
            'posts_per_page'      => count( $related_ids ),
            'orderby'             => 'post__in',
            'ignore_sticky_posts' => 1,
            'no_found_rows'       => true,
        );
        $related_query = new WP_Query( $args );
        if ( ! $related_query->have_posts() ) {
            wp_reset_postdata();
            return;
        }
        ?>
        <section class="crc-related-products">
            <h2><?php esc_html_e( 'Related Products', 'my-custom-related-products' ); ?></h2>
            <div class="crc-carousel owl-carousel">
                <?php while ( $related_query->have_posts() ) : $related_query->the_post(); ?>
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
                            <?php esc_html_e( 'Add to cart', 'my-custom-related-products' ); ?>
                        </a>
                    </div>
                <?php endwhile; wp_reset_postdata(); ?>
            </div><!-- /.crc-carousel -->
        </section>
        <?php
    }

    add_action( 'wp_enqueue_scripts', 'crc_enqueue_assets', 20 );
    function crc_enqueue_assets() {
        if ( ! function_exists('is_product') || ! is_product() ) {
            return;
        }
        
        // Enqueue OwlCarousel core
        rentpro_enqueue_owlcarousel_core();

        wp_enqueue_style(
            'crc-custom-css',
            plugin_dir_url( __FILE__ ) . '../assets/css/rental-related-products.css',
            array( 'owl-carousel-css' ),
            '1.0'
        );

        // Initialize carousel via custom JS file
        // In rental-related-products.js, we will target `.crc-carousel`
        wp_enqueue_script(
            'crc-custom-js',
            plugin_dir_url( __FILE__ ) . '../assets/js/rental-related-products.js',
            array( 'jquery', 'owl-carousel-js' ),
            '1.0',
            true
        );
    }
}
