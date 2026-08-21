<?php

defined('ABSPATH') || exit;

/**
 * Two-way catalog with Rentopian: pull products in, push WooCommerce products out.
 * Does not delete existing shop products (unlike the official Rentopian Sync plugin).
 */
final class CCR_Rentopian_Catalog
{
    private const CRON = 'ccr_rentopian_pull_catalog';
    private const LOG_OPTION = 'ccr_rentopian_catalog_log';

    private static bool $syncing = false;

    public static function init(): void
    {
        add_action(self::CRON, [self::class, 'pull']);
        add_action('admin_post_ccr_rentopian_pull', [self::class, 'handle_pull']);
        add_action('admin_post_ccr_rentopian_push', [self::class, 'handle_push']);
        add_action('admin_post_ccr_rentopian_keep_only', [self::class, 'handle_keep_only']);
        add_action('admin_post_ccr_rentopian_categorize', [self::class, 'handle_categorize']);
        add_action('admin_post_ccr_rentopian_apply_fallback', [self::class, 'handle_apply_fallback']);
        add_action('admin_post_ccr_apply_folder_photos', [self::class, 'handle_apply_folder_photos']);
        add_action('woocommerce_update_product', [self::class, 'maybe_push_product'], 40, 1);
        add_action('init', [self::class, 'maybe_schedule']);
    }

    public static function maybe_schedule(): void
    {
        if (! CCR_Rentopian_Sync::is_configured() || get_option('ccr_rentopian_pull_products', 'yes') !== 'yes') {
            wp_clear_scheduled_hook(self::CRON);

            return;
        }
        if (! wp_next_scheduled(self::CRON)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'twicedaily', self::CRON);
        }
    }

    public static function unschedule(): void
    {
        wp_clear_scheduled_hook(self::CRON);
    }

    public static function last_log(): array
    {
        $log = get_option(self::LOG_OPTION, []);

        return is_array($log) ? $log : [];
    }

    public static function handle_pull(): void
    {
        if (! current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Forbidden', 'christocentric-rentals'));
        }
        check_admin_referer('ccr_rentopian_pull');
        $result = self::pull();
        wp_safe_redirect(add_query_arg([
            'page' => 'christocentric-rentals',
            'ccr_rentopian' => 'pull',
            'created' => (int) ($result['created'] ?? 0),
            'updated' => (int) ($result['updated'] ?? 0),
            'failed' => (int) ($result['failed'] ?? 0),
            'msg' => rawurlencode((string) ($result['message'] ?? '')),
        ], admin_url('admin.php')));
        exit;
    }

    public static function handle_push(): void
    {
        if (! current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Forbidden', 'christocentric-rentals'));
        }
        check_admin_referer('ccr_rentopian_push');
        $result = self::push_all();
        wp_safe_redirect(add_query_arg([
            'page' => 'christocentric-rentals',
            'ccr_rentopian' => 'push',
            'pushed' => (int) ($result['pushed'] ?? 0),
            'failed' => (int) ($result['failed'] ?? 0),
            'msg' => rawurlencode((string) ($result['message'] ?? '')),
        ], admin_url('admin.php')));
        exit;
    }

    public static function handle_keep_only(): void
    {
        if (! current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Forbidden', 'christocentric-rentals'));
        }
        check_admin_referer('ccr_rentopian_keep_only');
        if (empty($_POST['ccr_confirm_keep_rentopian'])) { // phpcs:ignore
            wp_safe_redirect(add_query_arg([
                'page' => 'christocentric-rentals',
                'ccr_rentopian' => 'keep',
                'msg' => rawurlencode(__('Tick the confirmation box first.', 'christocentric-rentals')),
            ], admin_url('admin.php')));
            exit;
        }

        $result = self::trash_local_only_products();
        update_option('ccr_rentopian_push_products', 'no');

        wp_safe_redirect(add_query_arg([
            'page' => 'christocentric-rentals',
            'ccr_rentopian' => 'keep',
            'trashed' => (int) ($result['trashed'] ?? 0),
            'kept' => (int) ($result['kept'] ?? 0),
            'msg' => rawurlencode((string) ($result['message'] ?? '')),
        ], admin_url('admin.php')));
        exit;
    }

    public static function handle_categorize(): void
    {
        if (! current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Forbidden', 'christocentric-rentals'));
        }
        check_admin_referer('ccr_rentopian_categorize');
        $result = self::categorize_rentopian_products();
        wp_safe_redirect(add_query_arg([
            'page' => 'christocentric-rentals',
            'ccr_rentopian' => 'categorize',
            'msg' => rawurlencode((string) ($result['message'] ?? '')),
        ], admin_url('admin.php')));
        exit;
    }

    public static function handle_apply_fallback(): void
    {
        if (! current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Forbidden', 'christocentric-rentals'));
        }
        check_admin_referer('ccr_rentopian_apply_fallback');
        $result = self::apply_saved_fallback();
        wp_safe_redirect(add_query_arg([
            'page' => 'christocentric-rentals',
            'ccr_rentopian' => 'fallback',
            'priced' => (int) ($result['priced'] ?? 0),
            'described' => (int) ($result['described'] ?? 0),
            'msg' => rawurlencode((string) ($result['message'] ?? '')),
        ], admin_url('admin.php')));
        exit;
    }

    /**
     * Fill empty rates and descriptions from the bundled shop catalog (local rates Rentopian does not send).
     *
     * @return array{priced:int,described:int,skipped:int,message:string}
     */
    public static function apply_saved_fallback(): array
    {
        $out = ['priced' => 0, 'described' => 0, 'imaged' => 0, 'skipped' => 0, 'message' => ''];
        foreach (self::product_ids() as $id) {
            $product = wc_get_product((int) $id);
            if (! $product instanceof WC_Product) {
                $out['skipped']++;
                continue;
            }
            $row = self::fallback_for_product($product);
            if ($row === null) {
                $out['skipped']++;
                continue;
            }
            $changed = false;
            $daily = (float) ($row['price'] ?? 0);
            if ($daily > 0) {
                $product->update_meta_data('_ccr_price_per_day', wc_format_decimal($daily));
                $product->set_regular_price((string) $daily);
                $product->set_price((string) $daily);
                $out['priced']++;
                $changed = true;
            }
            $qty = (int) ($row['quantity'] ?? 0);
            if ($qty > 0) {
                $product->update_meta_data('_ccr_rental_quantity', $qty);
                $product->set_manage_stock(false);
                $product->set_stock_status('instock');
                $changed = true;
            }
            $remoteId = trim((string) ($row['rentopian_id'] ?? ''));
            if ($remoteId !== '') {
                $product->update_meta_data('_ccr_rentopian_id', $remoteId);
                $changed = true;
            }
            $existingDesc = trim((string) $product->get_description('edit'));
            $incomingDesc = trim((string) ($row['description'] ?? ''));
            $generic = $existingDesc !== '' && (
                str_contains($existingDesc, 'from Christocentric Rentals')
                || str_contains($existingDesc, 'Add your pickup and return')
                || str_starts_with($existingDesc, 'Rent the ')
                || str_contains($existingDesc, 'in this rental catalog')
            );
            if ($incomingDesc !== '' && ($existingDesc === '' || $generic)) {
                $product->set_description($incomingDesc);
                $out['described']++;
                $changed = true;
            }
            $existingShort = trim((string) $product->get_short_description('edit'));
            $incomingShort = trim((string) ($row['short_description'] ?? ''));
            if ($incomingShort !== '' && ($existingShort === '' || $generic)) {
                $product->set_short_description($incomingShort);
                $changed = true;
            }
            $folderPhotos = self::attach_folder_photos($product);
            if ($folderPhotos > 0) {
                $out['imaged'] = ($out['imaged'] ?? 0) + 1;
                $changed = true;
            } elseif (self::attach_existing_image($product)) {
                $out['imaged'] = ($out['imaged'] ?? 0) + 1;
                $changed = true;
            }
            if ($changed) {
                $product->save();
            } else {
                $out['skipped']++;
            }
        }
        $out['message'] = sprintf(
            /* translators: 1: priced 2: described 3: imaged */
            __('Applied saved shop catalog: %1$d prices, %2$d descriptions, %3$d photos.', 'christocentric-rentals'),
            $out['priced'],
            $out['described'],
            (int) ($out['imaged'] ?? 0)
        );
        self::store_log('fallback', $out);

        return $out;
    }

    /**
     * @return array{updated:int,skipped:int,message:string}
     */
    public static function categorize_rentopian_products(): array
    {
        $out = ['updated' => 0, 'skipped' => 0, 'message' => ''];
        foreach (self::product_ids() as $id) {
            if (! self::is_rentopian_product($id)) {
                continue;
            }
            $product = wc_get_product($id);
            if (! $product instanceof WC_Product) {
                $out['skipped']++;
                continue;
            }
            $slugs = self::guess_category_slugs($product->get_name(), []);
            if ($slugs === []) {
                $out['skipped']++;
                continue;
            }
            self::assign_category_slugs($id, $slugs);
            $out['updated']++;
        }

        $out['message'] = sprintf(
            /* translators: 1: updated 2: skipped */
            __('Categorized %1$d Rentopian products. Skipped %2$d.', 'christocentric-rentals'),
            $out['updated'],
            $out['skipped']
        );
        self::store_log('categorize', $out);

        return $out;
    }

    /**
     * @return array{local:int,rentopian:int}
     */
    public static function catalog_counts(): array
    {
        $counts = ['local' => 0, 'rentopian' => 0];
        foreach (self::product_ids() as $id) {
            if (self::is_rentopian_product((int) $id)) {
                $counts['rentopian']++;
            } else {
                $counts['local']++;
            }
        }

        return $counts;
    }

    /**
     * @return array{trashed:int,kept:int,message:string}
     */
    public static function trash_local_only_products(): array
    {
        $out = ['trashed' => 0, 'kept' => 0, 'message' => ''];
        foreach (self::product_ids() as $id) {
            $id = (int) $id;
            if (self::is_rentopian_product($id)) {
                $out['kept']++;
                continue;
            }
            if (wp_trash_post($id)) {
                $out['trashed']++;
            }
        }

        $out['message'] = sprintf(
            /* translators: 1: trashed 2: kept */
            __('Moved %1$d local products to Trash. Kept %2$d Rentopian products.', 'christocentric-rentals'),
            $out['trashed'],
            $out['kept']
        );
        self::store_log('keep_only', $out);

        return $out;
    }

    /**
     * @return list<int>
     */
    private static function product_ids(): array
    {
        $ids = get_posts([
            'post_type' => 'product',
            'post_status' => ['publish', 'draft', 'pending', 'private'],
            'posts_per_page' => -1,
            'fields' => 'ids',
            'no_found_rows' => true,
        ]);

        return array_map('intval', is_array($ids) ? $ids : []);
    }

    private static function is_rentopian_product(int $productId): bool
    {
        return trim((string) get_post_meta($productId, '_ccr_rentopian_id', true)) !== '';
    }

    public static function maybe_push_product(int $productId): void
    {
        if (self::$syncing || get_option('ccr_rentopian_push_products', 'no') !== 'yes') {
            return;
        }
        if (! CCR_Rentopian_Sync::is_configured()) {
            return;
        }
        $product = wc_get_product($productId);
        if (! $product instanceof WC_Product) {
            return;
        }
        if ($product->get_status() !== 'publish') {
            return;
        }
        self::push_product($product);
    }

    /**
     * @return array{created:int,updated:int,failed:int,message:string}
     */
    public static function pull(): array
    {
        $out = ['created' => 0, 'updated' => 0, 'failed' => 0, 'message' => ''];
        if (! CCR_Rentopian_Sync::is_configured()) {
            $out['message'] = __('Rentopian API key is not set.', 'christocentric-rentals');
            self::store_log('pull', $out);

            return $out;
        }

        $fetched = CCR_Rentopian_Sync::request('GET', '/products');
        if (! $fetched['ok']) {
            $out['message'] = $fetched['error'] !== ''
                ? $fetched['error']
                : sprintf(/* translators: %d: HTTP status */ __('Rentopian GET /products failed (%d).', 'christocentric-rentals'), $fetched['code']);
            self::store_log('pull', $out);

            return $out;
        }

        $rows = self::extract_list($fetched['body']);
        if ($rows === []) {
            $out['message'] = __('Rentopian returned no products.', 'christocentric-rentals');
            self::store_log('pull', $out);

            return $out;
        }

        self::$syncing = true;
        foreach ($rows as $row) {
            if (! is_array($row)) {
                $out['failed']++;
                continue;
            }
            $status = self::upsert_from_rentopian($row);
            if ($status === 'created') {
                $out['created']++;
            } elseif ($status === 'updated') {
                $out['updated']++;
            } elseif ($status !== 'skipped') {
                $out['failed']++;
            }
        }
        self::$syncing = false;

        $out['message'] = sprintf(
            /* translators: 1: created 2: updated 3: failed */
            __('Pull finished. Created %1$d, updated %2$d, failed %3$d.', 'christocentric-rentals'),
            $out['created'],
            $out['updated'],
            $out['failed']
        );
        self::store_log('pull', $out);

        return $out;
    }

    /**
     * @return array{pushed:int,failed:int,message:string}
     */
    public static function push_all(): array
    {
        $out = ['pushed' => 0, 'failed' => 0, 'message' => ''];
        if (! CCR_Rentopian_Sync::is_configured()) {
            $out['message'] = __('Rentopian API key is not set.', 'christocentric-rentals');
            self::store_log('push', $out);

            return $out;
        }

        $ids = wc_get_products([
            'status' => 'publish',
            'limit' => -1,
            'return' => 'ids',
            'type' => ['simple', 'variable'],
        ]);
        foreach ($ids as $id) {
            $product = wc_get_product((int) $id);
            if (! $product instanceof WC_Product) {
                continue;
            }
            if (self::push_product($product)) {
                $out['pushed']++;
            } else {
                $out['failed']++;
            }
        }

        $out['message'] = sprintf(
            /* translators: 1: pushed 2: failed */
            __('Push finished. Sent %1$d, failed %2$d.', 'christocentric-rentals'),
            $out['pushed'],
            $out['failed']
        );
        self::store_log('push', $out);

        return $out;
    }

    public static function push_product(WC_Product $product): bool
    {
        $payload = self::product_payload($product);
        $remoteId = (string) $product->get_meta('_ccr_rentopian_id');
        $path = $remoteId !== '' ? '/products/' . rawurlencode($remoteId) : '/products';
        $method = $remoteId !== '' ? 'PUT' : 'POST';

        $result = CCR_Rentopian_Sync::request($method, $path, $payload);
        if (! $result['ok'] && $method === 'PUT') {
            $result = CCR_Rentopian_Sync::request('POST', '/products', $payload);
        }
        if (! $result['ok']) {
            return false;
        }

        $newId = self::remote_id_from_body($result['body']);
        if ($newId !== '' && $newId !== $remoteId) {
            self::$syncing = true;
            $product->update_meta_data('_ccr_rentopian_id', $newId);
            $product->save();
            self::$syncing = false;
        }

        return true;
    }

    /**
     * @param mixed $body
     */
    private static function remote_id_from_body(mixed $body): string
    {
        if (! is_array($body)) {
            return '';
        }
        $id = self::pick($body, ['id', 'product_id', 'uuid', 'rentopian_id']);
        if (is_scalar($id) && (string) $id !== '') {
            return (string) $id;
        }
        foreach (['data', 'product', 'item'] as $key) {
            if (isset($body[$key]) && is_array($body[$key])) {
                $nested = self::pick($body[$key], ['id', 'product_id', 'uuid', 'rentopian_id']);
                if (is_scalar($nested) && (string) $nested !== '') {
                    return (string) $nested;
                }
            }
        }

        return '';
    }

    /**
     * @param mixed $body
     * @return list<array<string,mixed>>
     */
    private static function extract_list(mixed $body): array
    {
        if (! is_array($body)) {
            return [];
        }
        if (array_is_list($body)) {
            return $body;
        }
        foreach (['data', 'products', 'items', 'inventory'] as $key) {
            if (isset($body[$key]) && is_array($body[$key])) {
                return array_values($body[$key]);
            }
        }

        return [];
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function merge_sku_row(array $row): array
    {
        $row = self::decode_nested($row);
        $skus = $row['SKUs'] ?? $row['skus'] ?? null;
        if (! is_array($skus) || $skus === []) {
            return $row;
        }
        $first = array_is_list($skus) ? ($skus[0] ?? null) : reset($skus);
        if (! is_array($first)) {
            return $row;
        }
        $first = self::decode_nested($first);
        foreach ($first as $key => $value) {
            if (! array_key_exists($key, $row) || $row[$key] === null || $row[$key] === '') {
                $row[$key] = $value;
            }
        }

        return $row;
    }

    /**
     * Rentopian often JSON-encodes nested arrays as strings.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function decode_nested(array $row): array
    {
        foreach (['SKUs', 'skus', 'attributes', 'divisions', 'images', 'photos', 'add_ons'] as $key) {
            if (! isset($row[$key]) || ! is_string($row[$key])) {
                continue;
            }
            $trim = trim($row[$key]);
            if ($trim === '' || ($trim[0] !== '{' && $trim[0] !== '[')) {
                continue;
            }
            $decoded = json_decode($trim, true);
            if (is_array($decoded)) {
                $row[$key] = $decoded;
            }
        }

        return $row;
    }

    private static function normalize_name(string $name): string
    {
        $name = strtolower(wp_strip_all_tags($name));
        $name = preg_replace('/[^a-z0-9]+/', ' ', $name) ?? $name;

        return trim((string) preg_replace('/\s+/', ' ', $name));
    }

    private static function find_product_id_by_name(string $name): int
    {
        $want = self::normalize_name($name);
        if ($want === '') {
            return 0;
        }

        $slug = sanitize_title($name);
        if ($slug !== '') {
            $bySlug = get_posts([
                'post_type' => 'product',
                'post_status' => ['publish', 'draft', 'pending', 'private'],
                'name' => $slug,
                'fields' => 'ids',
                'posts_per_page' => 1,
            ]);
            if (! empty($bySlug[0])) {
                return (int) $bySlug[0];
            }
        }

        $found = get_posts([
            'post_type' => 'product',
            'post_status' => ['publish', 'draft', 'pending', 'private', 'trash'],
            's' => $name,
            'fields' => 'ids',
            'posts_per_page' => 30,
        ]);
        foreach ($found as $id) {
            if (self::normalize_name(get_the_title((int) $id)) === $want) {
                return (int) $id;
            }
        }

        return 0;
    }

    private static function daily_from_product(int $productId): float
    {
        if ($productId <= 0) {
            return 0.0;
        }
        $meta = (float) get_post_meta($productId, '_ccr_price_per_day', true);
        if ($meta > 0) {
            return $meta;
        }
        $regular = get_post_meta($productId, '_regular_price', true);

        return is_numeric($regular) ? (float) $regular : 0.0;
    }

    /**
     * Same-named product (including Trash) with the most shop content: description, photo, price.
     */
    private static function find_donor_id(string $name, int $excludeId): int
    {
        $want = self::normalize_name($name);
        if ($want === '') {
            return 0;
        }
        global $wpdb;
        $like = '%' . $wpdb->esc_like($name) . '%';
        $found = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts}
                WHERE post_type = 'product'
                  AND post_status IN ('publish','draft','pending','private','trash')
                  AND post_title LIKE %s
                LIMIT 80",
                $like
            )
        );
        $bestId = 0;
        $bestScore = 0;
        foreach ($found as $id) {
            $id = (int) $id;
            if ($id === $excludeId) {
                continue;
            }
            if (self::normalize_name(get_the_title($id)) !== $want && ! (
                strlen($want) >= 10
                && (str_starts_with(self::normalize_name(get_the_title($id)), $want)
                    || str_starts_with($want, self::normalize_name(get_the_title($id))))
            )) {
                continue;
            }
            $candidate = wc_get_product($id);
            if (! $candidate instanceof WC_Product) {
                continue;
            }
            $score = min(800, strlen(trim($candidate->get_description('edit'))))
                + min(200, strlen(trim($candidate->get_short_description('edit'))))
                + ((int) $candidate->get_image_id() > 0 ? 150 : 0)
                + (self::daily_from_product($id) > 0 ? 80 : 0);
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestId = $id;
            }
        }

        return $bestScore > 0 ? $bestId : 0;
    }

    /**
     * Keep this shop’s copy. Fill blanks from Trash/duplicates, then Rentopian.
     */
    private static function restore_shop_content(WC_Product $product, string $name, int $productId, array $row): void
    {
        $apiDesc = trim((string) self::pick($row, ['full_description', 'description', 'long_description', 'details']));
        $keepDesc = trim((string) $product->get_description('edit'));
        $keepShort = trim((string) $product->get_short_description('edit'));
        $donor = self::find_donor_id($name, $productId);
        $donorProduct = $donor > 0 ? wc_get_product($donor) : null;

        if ($keepDesc === '' && $donorProduct instanceof WC_Product) {
            $fromDonor = trim($donorProduct->get_description('edit'));
            if ($fromDonor !== '') {
                $product->set_description($fromDonor);
                $keepDesc = $fromDonor;
            }
        }
        if ($keepDesc === '' && $apiDesc !== '') {
            $product->set_description($apiDesc);
            $keepDesc = $apiDesc;
        }
        $pack = self::fallback_for_name($name);
        if ($keepDesc === '' && $pack && trim((string) ($pack['description'] ?? '')) !== '') {
            $product->set_description((string) $pack['description']);
        }
        if ($keepShort === '' && $pack && trim((string) ($pack['short_description'] ?? '')) !== '') {
            $product->set_short_description((string) $pack['short_description']);
        }

        if ($keepShort === '' && $donorProduct instanceof WC_Product) {
            $fromDonor = trim($donorProduct->get_short_description('edit'));
            if ($fromDonor !== '') {
                $product->set_short_description($fromDonor);
            }
        }

        if ((int) $product->get_image_id() === 0 && $donorProduct instanceof WC_Product && (int) $donorProduct->get_image_id() > 0) {
            $product->set_image_id($donorProduct->get_image_id());
            $gallery = $donorProduct->get_gallery_image_ids();
            if ($gallery !== []) {
                $product->set_gallery_image_ids($gallery);
            }
        }
    }

    /**
     * Copy a daily rate from a same-named product (including Trash) when Rentopian sends none.
     */
    private static function donor_daily_price(string $name, int $excludeId): float
    {
        return self::daily_from_product(self::find_donor_id($name, $excludeId));
    }

    /**
     * @param array<string,mixed> $row
     */
    private static function sku_price(array $row): float
    {
        $keys = ['rental_price', 'price_per_day', 'daily_rate', 'day_rate', 'rate', 'price', 'regular_price', 'amount'];
        $skus = $row['SKUs'] ?? $row['skus'] ?? null;
        if (is_array($skus)) {
            foreach (array_values($skus) as $sku) {
                if (! is_array($sku)) {
                    continue;
                }
                $sku = self::decode_nested($sku);
                $price = self::pick($sku, $keys);
                if (is_numeric($price) && (float) $price > 0) {
                    return (float) $price;
                }
            }
        }
        $price = self::pick($row, $keys);

        return is_numeric($price) && (float) $price > 0 ? (float) $price : 0.0;
    }

    /**
     * @return array<string, array{name:string,price:float,sale?:float|null,description:string,short_description:string}>
     */
    private static function fallback_map(): array
    {
        static $map = null;
        if (is_array($map)) {
            return $map;
        }
        $map = [];
        $path = CCR_PLUGIN_DIR . 'data/shop-fallback.json';
        if (! is_readable($path)) {
            return $map;
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        $map = is_array($decoded) ? $decoded : [];

        return $map;
    }

    /**
     * @return array{name?:string,price?:float,sale?:float|null,description?:string,short_description?:string,quantity?:int,rentopian_id?:string}|null
     */
    private static function fallback_for_name(string $name): ?array
    {
        $map = self::fallback_map();
        $want = self::normalize_name($name);
        if ($want === '') {
            return null;
        }
        if (isset($map[$want]) && is_array($map[$want])) {
            return $map[$want];
        }

        $best = null;
        $bestDelta = PHP_INT_MAX;
        foreach ($map as $key => $row) {
            if (! is_string($key) || ! is_array($row) || $key === '') {
                continue;
            }
            $hit = (strlen($want) >= 8 && str_starts_with($key, $want))
                || (strlen($key) >= 8 && str_starts_with($want, $key));
            if (! $hit) {
                continue;
            }
            $delta = abs(strlen($key) - strlen($want));
            if ($delta < $bestDelta) {
                $bestDelta = $delta;
                $best = $row;
            }
        }

        return is_array($best) ? $best : null;
    }

    /**
     * Reuse a photo already in this WordPress (Trash duplicate or media filename).
     */
    private static function attach_existing_image(WC_Product $product): bool
    {
        if ((int) $product->get_image_id() > 0) {
            return false;
        }
        $donorId = self::find_donor_id($product->get_name(), $product->get_id());
        $donor = $donorId > 0 ? wc_get_product($donorId) : null;
        if ($donor instanceof WC_Product && (int) $donor->get_image_id() > 0) {
            $product->set_image_id($donor->get_image_id());
            $gallery = $donor->get_gallery_image_ids();
            if ($gallery !== []) {
                $product->set_gallery_image_ids($gallery);
            }

            return true;
        }

        $attachmentId = self::media_id_for_name($product->get_name());
        if ($attachmentId <= 0) {
            $attachmentId = self::media_id_for_name(str_replace('-', ' ', $product->get_slug()));
        }
        if ($attachmentId <= 0) {
            $attachmentId = self::sideload_pack_image($product);
        }
        if ($attachmentId <= 0) {
            $attachmentId = self::create_name_card_attachment($product);
        }
        if ($attachmentId <= 0) {
            return false;
        }
        $product->set_image_id($attachmentId);

        return true;
    }

    /**
     * @return array<string,int> normalized name/filename => attachment ID
     */
    private static function media_index(): array
    {
        static $index = null;
        if (is_array($index)) {
            return $index;
        }
        $index = [];
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT ID, post_title, post_name, guid
            FROM {$wpdb->posts}
            WHERE post_type = 'attachment'
              AND post_status IN ('inherit','private')
              AND post_mime_type LIKE 'image/%'"
        );
        if (! is_array($rows)) {
            return $index;
        }
        foreach ($rows as $row) {
            $id = (int) $row->ID;
            $keys = [
                self::normalize_name((string) $row->post_title),
                self::normalize_name(str_replace(['-', '_'], ' ', (string) $row->post_name)),
            ];
            $path = (string) parse_url((string) $row->guid, PHP_URL_PATH);
            $file = basename($path);
            $file = (string) preg_replace('/-\d+x\d+(?=\.[a-z0-9]+$)/i', '', $file);
            $file = pathinfo($file, PATHINFO_FILENAME);
            $keys[] = self::normalize_name(str_replace(['-', '_'], ' ', $file));
            foreach ($keys as $key) {
                if ($key !== '' && ! isset($index[$key])) {
                    $index[$key] = $id;
                }
            }
        }

        return $index;
    }

    private static function media_id_for_name(string $name): int
    {
        $want = self::normalize_name($name);
        if ($want === '') {
            return 0;
        }
        $index = self::media_index();
        if (isset($index[$want])) {
            return (int) $index[$want];
        }
        $best = 0;
        $bestDelta = PHP_INT_MAX;
        foreach ($index as $key => $id) {
            if ($key === '' || (strlen($want) < 10 && strlen($key) < 10)) {
                continue;
            }
            $hit = (strlen($want) >= 10 && str_starts_with($key, $want))
                || (strlen($key) >= 10 && str_starts_with($want, $key));
            if (! $hit) {
                continue;
            }
            $delta = abs(strlen($key) - strlen($want));
            if ($delta < $bestDelta) {
                $bestDelta = $delta;
                $best = (int) $id;
            }
        }

        return $best;
    }

    /**
     * @return list<string>
     */
    private static function name_tokens(string $name): array
    {
        $normalized = self::normalize_name($name);
        $parts = preg_split('/\s+/', $normalized) ?: [];
        $out = [];
        $count = count($parts);
        for ($i = 0; $i < $count; $i++) {
            $part = (string) $parts[$i];
            if (strlen($part) >= 2) {
                $out[] = $part;
            }
            if (strlen($part) === 1 && isset($parts[$i + 1]) && strlen((string) $parts[$i + 1]) >= 2) {
                $out[] = $part . $parts[$i + 1];
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @param list<string> $want
     * @param list<string> $have
     */
    private static function token_overlap(array $want, array $have): int
    {
        if ($want === [] || $have === []) {
            return 0;
        }
        $set = array_fill_keys($have, true);
        $hits = 0;
        foreach ($want as $token) {
            if (isset($set[$token])) {
                $hits++;
            }
        }

        return $hits;
    }

    private static function is_distinctive_token(string $token): bool
    {
        return ! in_array($token, [
            'and', 'the', 'for', 'with', 'only', 'body', 'kit', 'pro', 'new', 'old',
            'free', 'added', 'accessory', 'stand', 'cable', 'cables', 'battery',
            'charger', 'monitor', 'tripod', 'light', 'trigger', 'adapter', 'mm',
        ], true);
    }

    private static function sideload_pack_image(WC_Product $product): int
    {
        $dir = CCR_PLUGIN_DIR . 'data/product-images/';
        if (! is_dir($dir)) {
            return 0;
        }
        $want = self::name_tokens($product->get_name());
        if ($want === []) {
            return 0;
        }
        $bestPath = '';
        $bestScore = 0;
        $files = scandir($dir);
        if (! is_array($files)) {
            return 0;
        }
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            $ext = strtolower((string) pathinfo($file, PATHINFO_EXTENSION));
            if (! in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
                continue;
            }
            $stem = (string) pathinfo($file, PATHINFO_FILENAME);
            $score = self::token_overlap($want, self::name_tokens(str_replace(['-', '_'], ' ', $stem)));
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestPath = $dir . $file;
            }
        }
        $distinctWant = array_values(array_filter($want, [self::class, 'is_distinctive_token']));
        $need = $distinctWant === [] ? 99 : max(1, (int) ceil(count($distinctWant) * 0.5));
        if ($bestPath === '' || $bestScore < $need || ! is_readable($bestPath)) {
            return 0;
        }
        $have = self::name_tokens(str_replace(['-', '_'], ' ', (string) pathinfo($bestPath, PATHINFO_FILENAME)));
        $distinctHits = 0;
        $haveSet = array_fill_keys($have, true);
        foreach ($distinctWant as $token) {
            if (isset($haveSet[$token])) {
                $distinctHits++;
            }
        }
        if ($distinctHits < 1) {
            return 0;
        }

        if (! function_exists('media_handle_sideload')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }
        $tmp = wp_tempnam($bestPath);
        if (! is_string($tmp) || ! copy($bestPath, $tmp)) {
            return 0;
        }
        $fileArray = [
            'name' => basename($bestPath),
            'tmp_name' => $tmp,
        ];
        $id = media_handle_sideload($fileArray, $product->get_id());
        if (is_wp_error($id)) {
            @unlink($tmp);

            return 0;
        }

        return (int) $id;
    }

    /**
     * Branded name card when no product photo exists.
     */
    private static function create_name_card_attachment(WC_Product $product): int
    {
        if (! function_exists('imagecreatetruecolor')) {
            return 0;
        }
        $width = 800;
        $height = 800;
        $im = imagecreatetruecolor($width, $height);
        if ($im === false) {
            return 0;
        }
        $bg = imagecolorallocate($im, 15, 58, 102);
        $fg = imagecolorallocate($im, 255, 255, 255);
        $muted = imagecolorallocate($im, 176, 204, 230);
        if ($bg === false || $fg === false || $muted === false) {
            imagedestroy($im);

            return 0;
        }
        imagefilledrectangle($im, 0, 0, $width, $height, $bg);
        imagestring($im, 3, 48, 56, 'CHRISTOCENTRIC RENTALS', $muted);
        $lines = explode("\n", wordwrap($product->get_name(), 28));
        $top = 280;
        foreach (array_slice($lines, 0, 6) as $line) {
            $line = trim($line);
            $px = (int) max(48, (800 - (strlen($line) * 9)) / 2);
            imagestring($im, 5, $px, $top, $line, $fg);
            $top += 36;
        }
        imagestring($im, 3, 48, 720, 'Photo coming soon', $muted);

        $tmp = wp_tempnam('ccr-card.png');
        if (! is_string($tmp) || ! imagepng($im, $tmp)) {
            imagedestroy($im);

            return 0;
        }
        imagedestroy($im);

        if (! function_exists('media_handle_sideload')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }
        $id = media_handle_sideload([
            'name' => 'ccr-' . sanitize_title($product->get_name()) . '.png',
            'tmp_name' => $tmp,
        ], $product->get_id());
        if (is_wp_error($id)) {
            @unlink($tmp);

            return 0;
        }

        update_post_meta((int) $id, '_ccr_placeholder_image', '1');

        return (int) $id;
    }

    public static function handle_apply_folder_photos(): void
    {
        if (! current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Forbidden', 'christocentric-rentals'));
        }
        check_admin_referer('ccr_apply_folder_photos');
        $result = self::apply_folder_photos(true);
        wp_safe_redirect(add_query_arg([
            'page' => 'christocentric-rentals',
            'ccr_rentopian' => 'photos',
            'msg' => rawurlencode((string) ($result['message'] ?? '')),
        ], admin_url('admin.php')));
        exit;
    }

    /**
     * Attach JPG/PNG/WebP files from /Products Images (and 00000) to matching products.
     *
     * @return array{products:int,photos:int,skipped:int,message:string}
     */
    public static function apply_folder_photos(bool $replace = false): array
    {
        @set_time_limit(0);
        $out = ['products' => 0, 'photos' => 0, 'skipped' => 0, 'message' => ''];
        foreach (self::product_ids() as $id) {
            $product = wc_get_product((int) $id);
            if (! $product instanceof WC_Product) {
                $out['skipped']++;
                continue;
            }
            $added = self::attach_folder_photos($product, $replace);
            if ($added > 0) {
                $product->save();
                $out['products']++;
                $out['photos'] += $added;
            } else {
                $out['skipped']++;
            }
        }
        $out['message'] = sprintf(
            /* translators: 1: products 2: photos */
            __('Attached folder photos: %1$d products, %2$d images.', 'christocentric-rentals'),
            $out['products'],
            $out['photos']
        );
        self::store_log('folder-photos', $out);

        return $out;
    }

    /**
     * @return list<string>
     */
    private static function photo_folder_roots(): array
    {
        $roots = [];
        $candidates = [
            trailingslashit(ABSPATH) . 'Products Images',
            dirname(ABSPATH) . DIRECTORY_SEPARATOR . 'Products Images',
        ];
        foreach ($candidates as $root) {
            if (! is_dir($root)) {
                continue;
            }
            $roots[] = $root;
            $nested = $root . DIRECTORY_SEPARATOR . '00000';
            if (is_dir($nested)) {
                $roots[] = $nested;
            }
        }

        return array_values(array_unique($roots));
    }

    /**
     * @return array{by_id: array<string,string>, by_name: array<string,string>, folders: list<array{path:string,name:string,id:string}>}
     */
    private static function photo_folder_index(): array
    {
        static $index = null;
        if (is_array($index)) {
            return $index;
        }
        $index = ['by_id' => [], 'by_name' => [], 'folders' => []];
        foreach (self::photo_folder_roots() as $root) {
            $entries = scandir($root);
            if (! is_array($entries)) {
                continue;
            }
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..' || $entry === '00000') {
                    continue;
                }
                $path = $root . DIRECTORY_SEPARATOR . $entry;
                if (! is_dir($path)) {
                    continue;
                }
                $rid = '';
                $txt = $path . DIRECTORY_SEPARATOR . 'description.txt';
                if (is_readable($txt)) {
                    $raw = (string) file_get_contents($txt);
                    if (preg_match('/Rentopian ID:\s*(\d+)/i', $raw, $m)) {
                        $rid = $m[1];
                    }
                }
                $key = self::normalize_name($entry);
                $index['folders'][] = ['path' => $path, 'name' => $entry, 'id' => $rid];
                if ($rid !== '' && ! isset($index['by_id'][$rid])) {
                    $index['by_id'][$rid] = $path;
                }
                if ($key !== '' && ! isset($index['by_name'][$key])) {
                    $index['by_name'][$key] = $path;
                }
            }
        }

        return $index;
    }

    private static function folder_for_product(WC_Product $product): string
    {
        $index = self::photo_folder_index();
        $rid = trim((string) $product->get_meta('_ccr_rentopian_id'));
        if ($rid !== '' && isset($index['by_id'][$rid])) {
            return (string) $index['by_id'][$rid];
        }
        $want = self::normalize_name($product->get_name());
        if ($want !== '' && isset($index['by_name'][$want])) {
            return (string) $index['by_name'][$want];
        }
        $wantTokens = self::name_tokens($product->get_name());
        $distinctWant = array_values(array_filter($wantTokens, [self::class, 'is_distinctive_token']));
        $bestPath = '';
        $bestScore = 0;
        $bestDistinct = 0;
        foreach ($index['folders'] as $folder) {
            $have = self::name_tokens((string) $folder['name']);
            $score = self::token_overlap($wantTokens, $have);
            $dscore = self::token_overlap($distinctWant, $have);
            $need = $distinctWant === [] ? 99 : max(1, (int) ceil(count($distinctWant) * 0.5));
            if ($score < $need || $dscore < 1) {
                continue;
            }
            if ($dscore > $bestDistinct || ($dscore === $bestDistinct && $score > $bestScore)) {
                $bestDistinct = $dscore;
                $bestScore = $score;
                $bestPath = (string) $folder['path'];
            }
        }

        return $bestPath;
    }

    /**
     * @return list<string>
     */
    private static function folder_image_paths(string $dir): array
    {
        $files = scandir($dir);
        if (! is_array($files)) {
            return [];
        }
        $out = [];
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            $ext = strtolower((string) pathinfo($file, PATHINFO_EXTENSION));
            if (! in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            if (is_readable($path)) {
                $out[] = $path;
            }
        }
        usort($out, static function (string $a, string $b): int {
            return strnatcasecmp(basename($a), basename($b));
        });

        return $out;
    }

    private static function is_placeholder_image(int $attachmentId): bool
    {
        if ($attachmentId <= 0) {
            return false;
        }
        if ((string) get_post_meta($attachmentId, '_ccr_placeholder_image', true) === '1') {
            return true;
        }
        $file = strtolower((string) basename((string) get_attached_file($attachmentId)));

        return str_starts_with($file, 'ccr-') && str_ends_with($file, '.png');
    }

    private static function sideload_local_image(string $path, int $productId): int
    {
        if (! function_exists('media_handle_sideload')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }
        $tmp = wp_tempnam($path);
        if (! is_string($tmp) || ! copy($path, $tmp)) {
            return 0;
        }
        $name = sanitize_file_name(basename($path));
        if ($name === '') {
            $name = 'product-photo.jpg';
        }
        $id = media_handle_sideload([
            'name' => $name,
            'tmp_name' => $tmp,
        ], $productId);
        if (is_wp_error($id)) {
            @unlink($tmp);

            return 0;
        }
        update_post_meta((int) $id, '_ccr_source_photo', wp_normalize_path($path));

        return (int) $id;
    }

    /**
     * @return int Newly attached image count
     */
    private static function attach_folder_photos(WC_Product $product, bool $replace = false): int
    {
        $folder = self::folder_for_product($product);
        if ($folder === '' || ! is_dir($folder)) {
            return 0;
        }
        $paths = self::folder_image_paths($folder);
        if ($paths === []) {
            return 0;
        }
        $folderNorm = wp_normalize_path($folder);
        $existing = [];
        $featured = (int) $product->get_image_id();
        if ($featured > 0) {
            $existing[] = $featured;
        }
        foreach ($product->get_gallery_image_ids() as $gid) {
            $existing[] = (int) $gid;
        }
        $bySource = [];
        $oldFromFolder = [];
        foreach (array_unique($existing) as $aid) {
            $src = wp_normalize_path((string) get_post_meta($aid, '_ccr_source_photo', true));
            if ($src !== '') {
                $bySource[$src] = (int) $aid;
                if (str_starts_with($src, $folderNorm)) {
                    $oldFromFolder[] = (int) $aid;
                }
            }
        }
        $finalIds = [];
        $added = 0;
        foreach ($paths as $path) {
            $norm = wp_normalize_path($path);
            if (isset($bySource[$norm])) {
                $finalIds[] = $bySource[$norm];
                continue;
            }
            $id = self::sideload_local_image($path, $product->get_id());
            if ($id <= 0) {
                continue;
            }
            $finalIds[] = $id;
            $added++;
        }
        if ($finalIds === []) {
            return 0;
        }
        if ($replace || (int) $product->get_image_id() <= 0 || self::is_placeholder_image((int) $product->get_image_id())) {
            $product->set_image_id($finalIds[0]);
            $product->set_gallery_image_ids(array_slice($finalIds, 1));
            foreach (array_unique($oldFromFolder) as $aid) {
                if (! in_array($aid, $finalIds, true)) {
                    wp_delete_attachment($aid, true);
                }
            }
        } elseif ($added > 0) {
            $gallery = array_values(array_unique(array_merge(
                array_filter($existing, static fn (int $id): bool => $id !== $featured && $id > 0),
                $finalIds
            )));
            $product->set_gallery_image_ids($gallery);
        }

        return $added;
    }

    /**
     * @return array{name?:string,price?:float,sale?:float|null,description?:string,short_description?:string,quantity?:int,rentopian_id?:string}|null
     */
    private static function fallback_for_product(WC_Product $product): ?array
    {
        $row = self::fallback_for_name($product->get_name());
        if ($row !== null) {
            return $row;
        }
        $rid = trim((string) $product->get_meta('_ccr_rentopian_id'));
        if ($rid === '') {
            return null;
        }
        foreach (self::fallback_map() as $candidate) {
            if (is_array($candidate) && (string) ($candidate['rentopian_id'] ?? '') === $rid) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * How many physical units Rentopian reports for this product.
     *
     * @param array<string,mixed> $row
     */
    private static function sku_quantity(array $row): int
    {
        $total = 0;
        $skus = $row['SKUs'] ?? $row['skus'] ?? null;
        if (is_array($skus) && $skus !== []) {
            $list = array_is_list($skus) ? $skus : array_values($skus);
            foreach ($list as $sku) {
                if (! is_array($sku)) {
                    continue;
                }
                $qty = self::pick($sku, ['quantity', 'qty', 'stock', 'stock_quantity', 'inventory', 'qoh', 'on_hand', 'available']);
                if (is_numeric($qty)) {
                    $total += (int) $qty;
                }
            }
        }
        if ($total > 0) {
            return $total;
        }
        $qty = self::pick($row, ['quantity', 'qty', 'stock', 'stock_quantity', 'inventory']);

        return is_numeric($qty) && (int) $qty > 0 ? (int) $qty : 1;
    }

    /**
     * @param array<string,mixed> $row
     */
    private static function upsert_from_rentopian(array $row): string
    {
        if (! empty($row['hidden_from_api']) || ! empty($row['is_add_on'])) {
            return 'skipped';
        }

        $row = self::merge_sku_row($row);
        $remoteId = (string) self::pick($row, ['id', 'product_id', 'uuid', 'rentopian_id']);
        $sku = (string) self::pick($row, ['sku', 'SKU', 'code']);
        $name = (string) self::pick($row, ['name', 'title', 'product_name']);
        if ($name === '') {
            return 'failed';
        }

        $productId = 0;
        if ($remoteId !== '') {
            $found = get_posts([
                'post_type' => 'product',
                'post_status' => 'any',
                'fields' => 'ids',
                'posts_per_page' => 1,
                'meta_key' => '_ccr_rentopian_id',
                'meta_value' => $remoteId,
            ]);
            $productId = (int) ($found[0] ?? 0);
        }
        if ($productId === 0 && $sku !== '') {
            $existing = wc_get_product_id_by_sku($sku);
            $productId = $existing > 0 ? $existing : 0;
        }
        if ($productId === 0) {
            $productId = self::find_product_id_by_name($name);
        }

        $created = $productId === 0;
        $product = $created ? new WC_Product_Simple() : wc_get_product($productId);
        if (! $product instanceof WC_Product) {
            return 'failed';
        }
        if (! $created && $product->get_status() === 'trash') {
            $product->set_status('publish');
        }

        $product->set_name($name);
        self::restore_shop_content($product, $name, $created ? 0 : $productId, $row);
        if ($sku !== '') {
            $product->set_sku($sku);
        }
        $qty = self::sku_quantity($row);
        $pack = self::fallback_for_name($name);
        if ($pack && (int) ($pack['quantity'] ?? 0) > 0) {
            $qty = (int) $pack['quantity'];
        }
        $product->set_manage_stock(false);
        $product->set_stock_status($qty > 0 ? 'instock' : 'outofstock');
        $product->update_meta_data('_ccr_rental_quantity', max(1, $qty));

        $apiDaily = self::sku_price($row);
        $keepDaily = self::daily_from_product($created ? 0 : $productId);
        $daily = $apiDaily > 0 ? $apiDaily : $keepDaily;
        if ($daily <= 0) {
            $daily = self::donor_daily_price($name, $created ? 0 : $productId);
        }
        if ($daily <= 0) {
            $pack = self::fallback_for_name($name);
            $daily = $pack ? (float) ($pack['price'] ?? 0) : 0.0;
        }
        if ($daily > 0) {
            $product->update_meta_data('_ccr_price_per_day', wc_format_decimal($daily));
            $product->set_regular_price((string) $daily);
            $product->set_price((string) $daily);
        }
        $sale = self::pick($row, ['sale_price', 'on_sale_price', 'sale_price_per_day']);
        if (is_numeric($sale) && (float) $sale > 0) {
            $product->update_meta_data('_ccr_sale_price_per_day', wc_format_decimal((float) $sale));
            $product->set_sale_price((string) $sale);
            $product->set_price((string) $sale);
        }
        if ($remoteId !== '') {
            $product->update_meta_data('_ccr_rentopian_id', $remoteId);
        }
        $featured = self::pick($row, ['featured', 'is_featured']);
        if ($featured === true || $featured === 1 || $featured === '1' || $featured === 'yes') {
            $product->update_meta_data('_ccr_is_featured', 'yes');
        }

        $product->set_status('publish');
        $product->set_catalog_visibility('visible');
        $newId = $product->save();
        if ($newId <= 0) {
            return 'failed';
        }

        $imageUrl = self::first_image_url($row);
        if ($imageUrl !== '' && (int) $product->get_image_id() === 0) {
            self::sideload_image($newId, $imageUrl);
        }

        $cats = $row['categories'] ?? $row['category'] ?? [];
        $slugs = self::guess_category_slugs($name, $row);
        if ($slugs !== []) {
            self::assign_category_slugs($newId, $slugs);
        } else {
            self::assign_categories($newId, $cats);
        }

        return $created ? 'created' : 'updated';
    }

    /**
     * @param mixed $cats
     */
    private static function assign_categories(int $productId, mixed $cats): void
    {
        $names = [];
        if (is_string($cats) && $cats !== '') {
            $names[] = $cats;
        } elseif (is_array($cats)) {
            foreach ($cats as $cat) {
                if (is_string($cat) && $cat !== '') {
                    $names[] = $cat;
                } elseif (is_array($cat)) {
                    $label = (string) self::pick($cat, ['name', 'title', 'slug']);
                    if ($label !== '') {
                        $names[] = $label;
                    }
                }
            }
        }
        if ($names === []) {
            return;
        }
        $ids = [];
        foreach ($names as $name) {
            $term = term_exists($name, 'product_cat');
            if (! $term) {
                $term = wp_insert_term($name, 'product_cat');
            }
            if (is_array($term) && isset($term['term_id'])) {
                $ids[] = (int) $term['term_id'];
            }
        }
        if ($ids !== []) {
            wp_set_object_terms($productId, $ids, 'product_cat');
        }
    }

    /**
     * @param array<string,mixed> $row
     * @return list<string>
     */
    private static function guess_category_slugs(string $name, array $row): array
    {
        $hay = strtolower($name);
        foreach (['divisions', 'attributes', 'categories', 'category'] as $key) {
            $hay .= ' ' . strtolower((string) wp_json_encode($row[$key] ?? ''));
        }

        $slugs = [];
        $add = static function (array $more) use (&$slugs): void {
            foreach ($more as $slug) {
                if ($slug !== '' && ! in_array($slug, $slugs, true)) {
                    $slugs[] = $slug;
                }
            }
        };

        if (preg_match('/\b(lens|lenses|prime|zoom|\d+\s*mm|f\/\d|rf\s|ef\s|fe\s|e-mount|gm\b|g master)\b/', $hay)) {
            $add(['lens']);
            if (str_contains($hay, 'canon') || preg_match('/\b(rf|ef)\b/', $hay)) {
                $add(['canon-lenses']);
            }
            if (str_contains($hay, 'sony') || preg_match('/\b(fe|gm|g master|e-mount)\b/', $hay)) {
                $add(['sony-lenses']);
            }
            if (str_contains($hay, 'sigma')) {
                $add(['sigma-lenses']);
            }
        } elseif (preg_match('/\b(gimbal|ronin|rs3|rs4|rsc|crane 3|weebill)\b/', $hay)) {
            $add(['gimbals']);
        } elseif (preg_match('/\b(drone|mavic|air 2|air 3|mini 3|mini 4|inspire|phantom)\b/', $hay)) {
            $add(['drone']);
        } elseif (preg_match('/\b(mic|microphone|lav|lavalier|shotgun|sennheiser|rode|wireless go|recorder|zoom h[456]|tascam|audio-technica)\b/', $hay)) {
            $add(['audio-gears']);
        } elseif (preg_match('/\b(projector|epson|benq|optoma)\b/', $hay)) {
            $add(['projectors']);
        } elseif (preg_match('/\b(ssd|sd card|cfexpress|cfast|memory card|card reader|storage)\b/', $hay)) {
            $add(['storage']);
        } elseif (preg_match('/\b(switcher|atem|v-8hd|video hub)\b/', $hay)) {
            $add(['video-switcher']);
        } elseif (preg_match('/\b(transmitter|teradek|hollyland|mars |bolt |video tx|wireless tx)\b/', $hay)) {
            $add(['transmitter']);
        } elseif (preg_match('/\b(strobe|monolight|ad600|ad200|ad400|sk400)\b/', $hay)) {
            $add(['strobes']);
        } elseif (preg_match('/\b(speedlight|speedlite|flash|v1 |tt685)\b/', $hay)) {
            $add(['flash']);
        } elseif (preg_match('/\b(light|led|aputure|nanlite|godox|amaran|panel|fresnel|tube light|softbox|cob )\b/', $hay)) {
            $add(['continuous-light']);
        } elseif (preg_match('/\b(stream deck|capture card|live stream|streaming)\b/', $hay)) {
            $add(['live-streaming-gears']);
        } elseif (preg_match('/\b(camera|body only|cinema|mirrorless|dslr|eos |fx3|fx6|fx30|a7|a9|zv-e|r5|r6|r8|r10|c70|c300|lumix|bmpcc)\b/', $hay)) {
            $add(['cameras']);
            if (str_contains($hay, 'canon') || preg_match('/\b(eos |r5|r6|r8|r10|c70|c300)\b/', $hay)) {
                $add(['canon-cameras']);
            }
            if (str_contains($hay, 'sony') || preg_match('/\b(a7|a9|fx3|fx6|fx30|zv-e)\b/', $hay)) {
                $add(['sony-cameras']);
            }
        } else {
            $add(['accessories']);
        }

        return $slugs;
    }

    /**
     * @param list<string> $slugs
     */
    private static function assign_category_slugs(int $productId, array $slugs): void
    {
        $ids = [];
        foreach ($slugs as $slug) {
            $term = get_term_by('slug', $slug, 'product_cat');
            if (! $term instanceof WP_Term) {
                $created = wp_insert_term(ucwords(str_replace('-', ' ', $slug)), 'product_cat', ['slug' => $slug]);
                if (is_array($created) && isset($created['term_id'])) {
                    $term = get_term((int) $created['term_id'], 'product_cat');
                }
            }
            if ($term instanceof WP_Term) {
                $ids[] = (int) $term->term_id;
                if ((int) $term->parent > 0) {
                    $ids[] = (int) $term->parent;
                }
            }
        }
        $ids = array_values(array_unique($ids));
        if ($ids !== []) {
            wp_set_object_terms($productId, $ids, 'product_cat');
        }
    }

    /**
     * @param array<string,mixed> $row
     */
    private static function first_image_url(array $row): string
    {
        $direct = self::pick($row, ['image', 'image_url', 'thumbnail', 'photo']);
        if (is_string($direct) && filter_var($direct, FILTER_VALIDATE_URL)) {
            return $direct;
        }
        $images = $row['images'] ?? $row['photos'] ?? [];
        if (! is_array($images) || $images === []) {
            return '';
        }
        $first = $images[0];
        if (is_string($first) && filter_var($first, FILTER_VALIDATE_URL)) {
            return $first;
        }
        if (is_array($first)) {
            $url = self::pick($first, ['url', 'src', 'image', 'full']);
            if (is_string($url) && filter_var($url, FILTER_VALIDATE_URL)) {
                return $url;
            }
        }

        return '';
    }

    private static function sideload_image(int $productId, string $url): void
    {
        if (! function_exists('media_sideload_image')) {
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }
        $id = media_sideload_image($url, $productId, null, 'id');
        if (! is_wp_error($id) && (int) $id > 0) {
            set_post_thumbnail($productId, (int) $id);
        }
    }

    /**
     * @return array<string,mixed>
     */
    private static function product_payload(WC_Product $product): array
    {
        $daily = (string) $product->get_meta('_ccr_price_per_day');
        if ($daily === '') {
            $daily = (string) $product->get_regular_price();
        }
        $sale = (string) $product->get_meta('_ccr_sale_price_per_day');
        if ($sale === '') {
            $sale = (string) $product->get_sale_price();
        }
        $cats = wp_get_post_terms($product->get_id(), 'product_cat', ['fields' => 'names']);

        return [
            'website_url' => home_url('/'),
            'woo_product_id' => $product->get_id(),
            'rentopian_id' => (string) $product->get_meta('_ccr_rentopian_id'),
            'name' => $product->get_name(),
            'sku' => $product->get_sku(),
            'description' => $product->get_description(),
            'quantity' => (int) $product->get_stock_quantity(),
            'rental_price' => $daily !== '' ? (float) $daily : 0,
            'sale_price' => $sale !== '' ? (float) $sale : null,
            'image_url' => (string) wp_get_attachment_url((int) $product->get_image_id()),
            'categories' => is_array($cats) ? array_values($cats) : [],
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @param list<string> $keys
     */
    private static function pick(array $row, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $row) && $row[$key] !== null && $row[$key] !== '') {
                return $row[$key];
            }
        }

        return null;
    }

    /**
     * @param array<string,mixed> $result
     */
    private static function store_log(string $action, array $result): void
    {
        update_option(self::LOG_OPTION, [
            'action' => $action,
            'at' => current_time('mysql'),
            'result' => $result,
        ], false);
    }
}
