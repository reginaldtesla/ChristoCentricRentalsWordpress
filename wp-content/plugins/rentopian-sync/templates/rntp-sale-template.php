<?php
/* Template for Rentopian Sale Products */
get_header();
?>

<div class="rental-sale-container">
    <!-- <h1>Sale Products</h1> -->
    
    <form method="GET" class="rental-filters">
        <input type="text" name="search" placeholder="Search Products" value="<?php echo isset($_GET['search']) ? esc_attr($_GET['search']) : ''; ?>" />
        
        <select name="order_by">
            <option value="date" <?php selected($_GET['order_by'] ?? '', 'date'); ?>> Latest </option>
            <option value="price" <?php selected($_GET['order_by'] ?? '', 'price'); ?>> Price: Low to High </option>
            <option value="price-desc" <?php selected($_GET['order_by'] ?? '', 'price-desc'); ?>> Price: High to Low </option>
        </select>

        <select name="category">
            <option value="">All Categories</option>
            <?php
            $categories = get_terms('product_cat');
            foreach ($categories as $category) {
                echo '<option value="' . esc_attr($category->slug) . '" ' . selected($_GET['category'] ?? '', $category->slug, false) . '>' . esc_html($category->name) . '</option>';
            }
            ?>
        </select>

        <button type="submit">Filter</button>
    </form>

    <div class="rntp-sale-products">
        <?php

        $args = [
            'post_type' => 'product',
            'meta_query' => [
                [
                    'key'   => '_rental_is_sale',
                    'value' => '1',
                    'compare' => '='
                ]
            ],
            'posts_per_page' => 12,
            'paged' => get_query_var('paged') ? get_query_var('paged') : 1,
        ];

        if (!empty($_GET['search'])) {

            $args['s'] = sanitize_text_field($_GET['search']);
        }

        if (!empty($_GET['category'])) {

            $args['tax_query'] = [
                [
                    'taxonomy' => 'product_cat',
                    'field' => 'slug',
                    'terms' => sanitize_text_field($_GET['category'])
                ]
            ];
        }

        if (!empty($_GET['order_by'])) {

            if ($_GET['order_by'] == 'price') {

                $args['orderby'] = 'meta_value_num';
                $args['meta_key'] = '_price';
                $args['order'] = 'ASC';

            } elseif ($_GET['order_by'] == 'price-desc') {

                $args['orderby'] = 'meta_value_num';
                $args['meta_key'] = '_price';
                $args['order'] = 'DESC';

            } else {

                $args['orderby'] = 'date';
                $args['order'] = 'DESC';
            }
        }

        $query = new WP_Query($args);

        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                wc_get_template_part('content', 'product');
            }
            wp_reset_postdata();
        } else {
            echo '<p>No sale products found.</p>';
        }
        
        ?>
    </div>

    <div class="pagination">
        <?php
        echo paginate_links([
            'total' => $query->max_num_pages,
        ]);
        ?>
    </div>
</div>

<?php get_footer(); ?>
