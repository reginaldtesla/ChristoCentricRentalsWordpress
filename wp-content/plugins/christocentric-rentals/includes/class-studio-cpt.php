<?php

defined('ABSPATH') || exit;

/**
 * Studio spaces CPT + package/feature meta.
 */
final class CCR_Studio_Cpt
{
    public const POST_TYPE = 'ccr_studio';

    public static function init(): void
    {
        add_action('init', [self::class, 'register']);
        add_action('init', [self::class, 'maybe_seed_default'], 20);
        add_action('init', [self::class, 'maybe_upgrade_hourly_packages'], 21);
        add_action('add_meta_boxes', [self::class, 'meta_boxes']);
        add_action('save_post_' . self::POST_TYPE, [self::class, 'save'], 10, 2);
        add_action('admin_enqueue_scripts', [self::class, 'admin_assets']);
        add_filter('manage_' . self::POST_TYPE . '_posts_columns', [self::class, 'columns']);
        add_action('manage_' . self::POST_TYPE . '_posts_custom_column', [self::class, 'column_content'], 10, 2);
    }

    public static function register(): void
    {
        register_post_type(self::POST_TYPE, [
            'labels' => [
                'name' => __('Studios', 'christocentric-rentals'),
                'singular_name' => __('Studio', 'christocentric-rentals'),
                'add_new_item' => __('Add New Studio', 'christocentric-rentals'),
                'edit_item' => __('Edit Studio', 'christocentric-rentals'),
                'menu_name' => __('Studios', 'christocentric-rentals'),
            ],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => true,
            'menu_icon' => 'dashicons-camera',
            'menu_position' => 56,
            'supports' => ['title', 'thumbnail', 'page-attributes'],
            'capability_type' => 'post',
            'map_meta_cap' => true,
        ]);
    }

    public static function admin_assets(string $hook): void
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (! $screen || $screen->post_type !== self::POST_TYPE) {
            return;
        }
        wp_enqueue_media();
    }

    public static function meta_boxes(): void
    {
        add_meta_box(
            'ccr_studio_details',
            __('Studio details', 'christocentric-rentals'),
            [self::class, 'render_details'],
            self::POST_TYPE,
            'normal',
            'high'
        );
        add_meta_box(
            'ccr_studio_packages',
            __('Packages (hours + price)', 'christocentric-rentals'),
            [self::class, 'render_packages'],
            self::POST_TYPE,
            'normal',
            'default'
        );
        add_meta_box(
            'ccr_studio_features',
            __('What’s included', 'christocentric-rentals'),
            [self::class, 'render_features'],
            self::POST_TYPE,
            'normal',
            'default'
        );
    }

    public static function render_details(WP_Post $post): void
    {
        wp_nonce_field('ccr_studio_save', 'ccr_studio_nonce');
        $blurb = (string) get_post_meta($post->ID, '_ccr_studio_blurb', true);
        $meta = (string) get_post_meta($post->ID, '_ccr_studio_meta', true);
        ?>
        <p>
            <label for="ccr_studio_blurb"><strong><?php esc_html_e('Short description', 'christocentric-rentals'); ?></strong></label><br>
            <textarea id="ccr_studio_blurb" name="ccr_studio_blurb" rows="3" class="large-text"><?php echo esc_textarea($blurb); ?></textarea>
        </p>
        <p>
            <label for="ccr_studio_meta"><strong><?php esc_html_e('Meta line', 'christocentric-rentals'); ?></strong></label><br>
            <input type="text" id="ccr_studio_meta" name="ccr_studio_meta" class="large-text" value="<?php echo esc_attr($meta); ?>" placeholder="<?php esc_attr_e('e.g. Kumasi · Pair with rental gear', 'christocentric-rentals'); ?>">
        </p>
        <p class="description"><?php esc_html_e('Set a Featured Image for the studio thumbnail on the booking page. Use Order (Attributes) to sort studios.', 'christocentric-rentals'); ?></p>
        <?php
    }

    public static function render_packages(WP_Post $post): void
    {
        $packages = self::get_packages($post->ID);
        if ($packages === []) {
            $packages = [
                ['id' => 'pictures', 'label' => 'Pictures', 'hours' => 4, 'price' => 200, 'pricing' => 'hourly'],
                ['id' => 'video', 'label' => 'Video', 'hours' => 4, 'price' => 300, 'pricing' => 'hourly'],
            ];
        }
        ?>
        <table class="widefat striped" id="ccr-studio-packages">
            <thead>
                <tr>
                    <th><?php esc_html_e('Label', 'christocentric-rentals'); ?></th>
                    <th><?php esc_html_e('Default hours', 'christocentric-rentals'); ?></th>
                    <th><?php esc_html_e('Rate / price (GHS)', 'christocentric-rentals'); ?></th>
                    <th><?php esc_html_e('Pricing', 'christocentric-rentals'); ?></th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($packages as $i => $pkg) : ?>
                    <tr>
                        <td>
                            <input type="hidden" name="ccr_studio_packages[<?php echo (int) $i; ?>][id]" value="<?php echo esc_attr((string) ($pkg['id'] ?? ('pkg' . ((int) $i + 1)))); ?>">
                            <input type="text" name="ccr_studio_packages[<?php echo (int) $i; ?>][label]" value="<?php echo esc_attr((string) ($pkg['label'] ?? '')); ?>" class="regular-text">
                        </td>
                        <td><input type="number" min="1" step="1" name="ccr_studio_packages[<?php echo (int) $i; ?>][hours]" value="<?php echo esc_attr((string) ($pkg['hours'] ?? 4)); ?>" style="width:5rem"></td>
                        <td><input type="number" min="0" step="0.01" name="ccr_studio_packages[<?php echo (int) $i; ?>][price]" value="<?php echo esc_attr((string) ($pkg['price'] ?? 0)); ?>" style="width:7rem"></td>
                        <td>
                            <select name="ccr_studio_packages[<?php echo (int) $i; ?>][pricing]">
                                <option value="hourly" <?php selected(($pkg['pricing'] ?? 'hourly'), 'hourly'); ?>><?php esc_html_e('Per hour', 'christocentric-rentals'); ?></option>
                                <option value="flat" <?php selected(($pkg['pricing'] ?? ''), 'flat'); ?>><?php esc_html_e('Flat', 'christocentric-rentals'); ?></option>
                            </select>
                        </td>
                        <td><button type="button" class="button ccr-remove-row"><?php esc_html_e('Remove', 'christocentric-rentals'); ?></button></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p class="description"><?php esc_html_e('For Pictures / Video, use Per hour (e.g. 200 or 300). Guests pick duration on the booking page.', 'christocentric-rentals'); ?></p>
        <p><button type="button" class="button" id="ccr-add-package"><?php esc_html_e('Add package', 'christocentric-rentals'); ?></button></p>
        <script>
        (function(){
            var table = document.getElementById('ccr-studio-packages');
            var addBtn = document.getElementById('ccr-add-package');
            if (!table || !addBtn) return;
            addBtn.addEventListener('click', function(){
                var i = table.tBodies[0].rows.length;
                var tr = document.createElement('tr');
                tr.innerHTML = '<td><input type="hidden" name="ccr_studio_packages['+i+'][id]" value="pkg'+(i+1)+'"><input type="text" name="ccr_studio_packages['+i+'][label]" class="regular-text"></td>'+
                    '<td><input type="number" min="1" step="1" name="ccr_studio_packages['+i+'][hours]" value="4" style="width:5rem"></td>'+
                    '<td><input type="number" min="0" step="0.01" name="ccr_studio_packages['+i+'][price]" value="200" style="width:7rem"></td>'+
                    '<td><select name="ccr_studio_packages['+i+'][pricing]"><option value="hourly" selected>Per hour</option><option value="flat">Flat</option></select></td>'+
                    '<td><button type="button" class="button ccr-remove-row">Remove</button></td>';
                table.tBodies[0].appendChild(tr);
            });
            table.addEventListener('click', function(e){
                if (e.target && e.target.classList.contains('ccr-remove-row')) {
                    var row = e.target.closest('tr');
                    if (row && table.tBodies[0].rows.length > 1) row.remove();
                }
            });
        })();
        </script>
        <?php
    }

    public static function render_features(WP_Post $post): void
    {
        $features = self::get_features($post->ID);
        $text = implode("\n", $features);
        ?>
        <p>
            <label for="ccr_studio_features"><strong><?php esc_html_e('One feature per line', 'christocentric-rentals'); ?></strong></label><br>
            <textarea id="ccr_studio_features" name="ccr_studio_features" rows="6" class="large-text"><?php echo esc_textarea($text); ?></textarea>
        </p>
        <?php
    }

    public static function save(int $postId, WP_Post $post): void
    {
        if (! isset($_POST['ccr_studio_nonce']) || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ccr_studio_nonce'])), 'ccr_studio_save')) { // phpcs:ignore
            return;
        }
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (! current_user_can('edit_post', $postId)) {
            return;
        }

        update_post_meta($postId, '_ccr_studio_blurb', sanitize_textarea_field(wp_unslash($_POST['ccr_studio_blurb'] ?? ''))); // phpcs:ignore
        update_post_meta($postId, '_ccr_studio_meta', sanitize_text_field(wp_unslash($_POST['ccr_studio_meta'] ?? ''))); // phpcs:ignore

        $rawPackages = isset($_POST['ccr_studio_packages']) && is_array($_POST['ccr_studio_packages']) ? wp_unslash($_POST['ccr_studio_packages']) : []; // phpcs:ignore
        $packages = [];
        foreach ($rawPackages as $i => $row) {
            if (! is_array($row)) {
                continue;
            }
            $label = sanitize_text_field((string) ($row['label'] ?? ''));
            if ($label === '') {
                continue;
            }
            $packages[] = [
                'id' => sanitize_key((string) ($row['id'] ?? ('pkg' . ((int) $i + 1)))) ?: ('pkg' . ((int) $i + 1)),
                'label' => $label,
                'hours' => max(1, (int) ($row['hours'] ?? 4)),
                'price' => max(0, (float) ($row['price'] ?? 0)),
                'pricing' => (($row['pricing'] ?? 'hourly') === 'flat') ? 'flat' : 'hourly',
            ];
        }
        update_post_meta($postId, '_ccr_studio_packages', $packages);

        $featuresRaw = sanitize_textarea_field(wp_unslash($_POST['ccr_studio_features'] ?? '')); // phpcs:ignore
        $features = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $featuresRaw) ?: [])));
        update_post_meta($postId, '_ccr_studio_features', $features);
    }

    public static function columns(array $columns): array
    {
        $new = [];
        foreach ($columns as $key => $label) {
            $new[$key] = $label;
            if ($key === 'title') {
                $new['ccr_packages'] = __('Packages', 'christocentric-rentals');
            }
        }

        return $new;
    }

    public static function column_content(string $column, int $postId): void
    {
        if ($column !== 'ccr_packages') {
            return;
        }
        echo esc_html((string) count(self::get_packages($postId)));
    }

    /** @return list<array{id:string,label:string,hours:int,price:float,pricing:string}> */
    public static function get_packages(int $postId): array
    {
        $packages = get_post_meta($postId, '_ccr_studio_packages', true);
        if (! is_array($packages)) {
            return [];
        }
        $out = [];
        foreach ($packages as $pkg) {
            if (! is_array($pkg) || ($pkg['label'] ?? '') === '') {
                continue;
            }
            $pricing = (($pkg['pricing'] ?? 'hourly') === 'flat') ? 'flat' : 'hourly';
            $out[] = [
                'id' => (string) ($pkg['id'] ?? uniqid('pkg', false)),
                'label' => (string) $pkg['label'],
                'hours' => max(1, (int) ($pkg['hours'] ?? 4)),
                'price' => max(0, (float) ($pkg['price'] ?? 0)),
                'pricing' => $pricing,
            ];
        }

        return $out;
    }

    /** @return list<string> */
    public static function get_features(int $postId): array
    {
        $features = get_post_meta($postId, '_ccr_studio_features', true);

        return is_array($features) ? array_values(array_map('strval', $features)) : [];
    }

    /** @return list<array<string,mixed>> */
    public static function get_published_studios(): array
    {
        $posts = get_posts([
            'post_type' => self::POST_TYPE,
            'post_status' => 'publish',
            'numberposts' => 50,
            'orderby' => ['menu_order' => 'ASC', 'title' => 'ASC'],
        ]);
        $out = [];
        foreach ($posts as $post) {
            if (! $post instanceof WP_Post) {
                continue;
            }
            $thumb = get_the_post_thumbnail_url($post, 'medium');
            $out[] = [
                'id' => (string) $post->ID,
                'name' => $post->post_title,
                'blurb' => (string) get_post_meta($post->ID, '_ccr_studio_blurb', true),
                'meta' => (string) get_post_meta($post->ID, '_ccr_studio_meta', true),
                'image' => is_string($thumb) ? $thumb : '',
                'packages' => self::get_packages($post->ID),
                'features' => self::get_features($post->ID),
            ];
        }

        return $out;
    }

    public static function maybe_seed_default(): void
    {
        $existing = get_posts([
            'post_type' => self::POST_TYPE,
            'post_status' => 'any',
            'numberposts' => 1,
            'fields' => 'ids',
        ]);
        if ($existing !== []) {
            return;
        }

        $id = wp_insert_post([
            'post_type' => self::POST_TYPE,
            'post_status' => 'publish',
            'post_title' => 'Main studio',
            'menu_order' => 0,
        ], true);
        if (is_wp_error($id) || ! $id) {
            return;
        }

        update_post_meta((int) $id, '_ccr_studio_blurb', 'Controlled space in Bomso for interviews, portraits, and brand content.');
        update_post_meta((int) $id, '_ccr_studio_meta', 'Kumasi · Pair with rental gear');
        update_post_meta((int) $id, '_ccr_studio_packages', [
            ['id' => 'pictures', 'label' => 'Pictures', 'hours' => 4, 'price' => 200, 'pricing' => 'hourly'],
            ['id' => 'video', 'label' => 'Video', 'hours' => 4, 'price' => 300, 'pricing' => 'hourly'],
        ]);
        update_post_meta((int) $id, '_ccr_studio_features', [
            'Controlled lighting environment',
            'Backdrop / cyclorama area',
            'Power for production gear',
            'Restroom access',
            'On-site support during session',
        ]);
    }

    /** Migrate old fixed packages to Pictures/Video hourly rates. */
    public static function maybe_upgrade_hourly_packages(): void
    {
        $flag = 'ccr_studio_hourly_packages_v1';
        if (get_option($flag) === '1') {
            return;
        }
        $posts = get_posts([
            'post_type' => self::POST_TYPE,
            'post_status' => 'any',
            'numberposts' => 50,
            'fields' => 'ids',
        ]);
        foreach ($posts as $postId) {
            $packages = get_post_meta((int) $postId, '_ccr_studio_packages', true);
            if (! is_array($packages) || $packages === []) {
                update_post_meta((int) $postId, '_ccr_studio_packages', [
                    ['id' => 'pictures', 'label' => 'Pictures', 'hours' => 4, 'price' => 200, 'pricing' => 'hourly'],
                    ['id' => 'video', 'label' => 'Video', 'hours' => 4, 'price' => 300, 'pricing' => 'hourly'],
                ]);
                continue;
            }
            $labels = array_map(static fn ($p) => strtolower((string) ($p['label'] ?? '')), $packages);
            $looksLegacy = in_array('4-hour package', $labels, true) || in_array('8-hour package', $labels, true);
            $hasHourly = false;
            foreach ($packages as $pkg) {
                if (($pkg['pricing'] ?? '') === 'hourly' || in_array(($pkg['id'] ?? ''), ['pictures', 'video'], true)) {
                    $hasHourly = true;
                    break;
                }
            }
            if ($looksLegacy || ! $hasHourly) {
                update_post_meta((int) $postId, '_ccr_studio_packages', [
                    ['id' => 'pictures', 'label' => 'Pictures', 'hours' => 4, 'price' => 200, 'pricing' => 'hourly'],
                    ['id' => 'video', 'label' => 'Video', 'hours' => 4, 'price' => 300, 'pricing' => 'hourly'],
                ]);
            }
        }
        update_option($flag, '1', false);
    }
}
