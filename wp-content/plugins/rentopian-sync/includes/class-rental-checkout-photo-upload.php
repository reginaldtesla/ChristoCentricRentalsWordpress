<?php
/**
 * Rental Photo Upload Handler
 *
 * Session Storage: write-through to WC session + transient.
 * Clearing: nuclear – wipes WC session, transient, session ID, and cookie.
 * Clear points: attach_photos_to_order (pri 1), safety-net (pri 20),
 *               woocommerce_thankyou, checkout page load (stale check).
 *
 * @package    Rentopian_Sync
 * @subpackage Includes
 * @since      2.14.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Rental_Checkout_Photo_Upload {

    private static $instance = null;
    private $max_files = 5;
    private $max_file_size = 2097152;
    private $allowed_mime_types = array('image/jpeg', 'image/jpg', 'image/png');
    private $allowed_extensions = array('jpg', 'jpeg', 'png');
    private $upload_dir_name = 'rental-checkout-photos';

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        if (!get_option('rental_checkout_photo_upload_enabled', 0)) {
            return;
        }
        $this->max_files     = apply_filters('rental_photo_upload_max_files', 5);
        $this->max_file_size = apply_filters('rental_photo_upload_max_size_mb', 2) * 1024 * 1024;
        $this->register_hooks();
    }

    private function register_hooks() {
        // AJAX handlers
        add_action('wp_ajax_rental_upload_checkout_photo', array($this, 'ajax_upload_photo'));
        add_action('wp_ajax_nopriv_rental_upload_checkout_photo', array($this, 'ajax_upload_photo'));
        add_action('wp_ajax_rental_remove_checkout_photo', array($this, 'ajax_remove_photo'));
        add_action('wp_ajax_nopriv_rental_remove_checkout_photo', array($this, 'ajax_remove_photo'));

        // AJAX: get current session photos (for JS to check on checkout load)
        add_action('wp_ajax_rental_get_session_photos', array($this, 'ajax_get_session_photos'));
        add_action('wp_ajax_nopriv_rental_get_session_photos', array($this, 'ajax_get_session_photos'));

        // AJAX: clear session photos (for JS to clear stale state)
        add_action('wp_ajax_rental_clear_session_photos', array($this, 'ajax_clear_session_photos'));
        add_action('wp_ajax_nopriv_rental_clear_session_photos', array($this, 'ajax_clear_session_photos'));

        // Attach session photos to order (priority 1, before rental_create_order at 10)
        add_action('woocommerce_new_order', array($this, 'attach_photos_to_order_on_new_order'), 1, 1);

        // Safety-net clear at priority 20
        add_action('woocommerce_new_order', array($this, 'clear_photo_session_after_order_created'), 20, 1);

        // Clear on thank-you page load (belt-and-suspenders)
        add_action('woocommerce_thankyou', array($this, 'clear_session_on_thankyou'), 1);

        // Add photos to order API data
        add_filter('rental_order_data_before_send', array($this, 'add_photos_to_order_data'), 10, 2);

        // Cron cleanup
        add_action('rental_cleanup_temp_checkout_photos', array($this, 'cleanup_expired_temp_files'));
        if (!wp_next_scheduled('rental_cleanup_temp_checkout_photos')) {
            wp_schedule_event(time(), 'daily', 'rental_cleanup_temp_checkout_photos');
        }
    }

    // =========================================================================
    // Upload Directory
    // =========================================================================

    private function get_upload_dir() {
        $session_id = $this->get_session_id();
        $upload_dir = wp_upload_dir();
        if ($upload_dir['error']) {
            return new WP_Error('upload_dir_error', $upload_dir['error']);
        }
        $target_dir = $upload_dir['basedir'] . '/' . $this->upload_dir_name . '/' . $session_id;
        $target_url = $upload_dir['baseurl'] . '/' . $this->upload_dir_name . '/' . $session_id;
        if (!file_exists($target_dir)) {
            if (!wp_mkdir_p($target_dir)) {
                return new WP_Error('mkdir_failed', __('Failed to create upload directory.', 'rentopian-sync'));
            }
            @file_put_contents($target_dir . '/.htaccess', "Options -Indexes\n");
            @file_put_contents($target_dir . '/index.php', "<?php\n// Silence is golden.\n");
        }
        return array('path' => $target_dir, 'url' => $target_url);
    }

    // =========================================================================
    // Session ID
    // =========================================================================

    private function get_session_id() {
        if (function_exists('WC') && WC()->session) {
            $session_id = WC()->session->get('rental_photo_upload_session_id');
            if ($session_id) {
                return $session_id;
            }
            $session_id = wp_generate_password(16, false);
            WC()->session->set('rental_photo_upload_session_id', $session_id);
            return $session_id;
        }
        if (isset($_COOKIE['rental_photo_session'])) {
            return sanitize_text_field($_COOKIE['rental_photo_session']);
        }
        $session_id = wp_generate_password(16, false);
        setcookie('rental_photo_session', $session_id, time() + DAY_IN_SECONDS, '/', '', is_ssl(), true);
        $_COOKIE['rental_photo_session'] = $session_id;
        return $session_id;
    }

    /**
     * Get existing session ID without creating a new one.
     */
    private function get_raw_session_id() {
        if (function_exists('WC') && WC()->session) {
            $id = WC()->session->get('rental_photo_upload_session_id');
            if ($id) {
                return $id;
            }
        }
        if (isset($_COOKIE['rental_photo_session'])) {
            return sanitize_text_field($_COOKIE['rental_photo_session']);
        }
        return '';
    }

    // =========================================================================
    // Session Storage — Write-through (WC session + transient)
    // =========================================================================

    /**
     * Read: check WC session first, then transient fallback.
     */
    private function get_session_photos() {
        // Primary: WC session
        if (function_exists('WC') && WC()->session) {
            $photos = WC()->session->get('rental_checkout_photos', array());
            if (!empty($photos) && is_array($photos)) {
                return $photos;
            }
        }
        // Fallback: transient
        $sid = $this->get_raw_session_id();
        if ($sid) {
            $photos = get_transient('rental_photos_' . $sid);
            if (!empty($photos) && is_array($photos)) {
                return $photos;
            }
        }
        return array();
    }

    /**
     * Write: ALWAYS write to BOTH backends. No early return.
     */
    private function set_session_photos($photos) {
        // 1. WC session
        if (function_exists('WC') && WC()->session) {
            WC()->session->set('rental_checkout_photos', $photos);
        }
        // 2. Transient (always, even if WC session was written)
        $sid = $this->get_raw_session_id();
        if (!$sid) {
            $sid = $this->get_session_id();
        }
        if ($sid) {
            if (empty($photos)) {
                delete_transient('rental_photos_' . $sid);
            } else {
                set_transient('rental_photos_' . $sid, $photos, DAY_IN_SECONDS);
            }
        }
    }

    /**
     * Add photo if not duplicate (by content hash).
     */
    private function add_session_photo($photo_data) {
        $path = isset($photo_data['path']) ? $photo_data['path'] : '';
        if (!$path || !is_readable($path)) {
            return false;
        }
        $hash = md5_file($path);
        if ($hash === false) {
            return false;
        }
        $photos = $this->get_session_photos();
        foreach ($photos as $existing) {
            $p = isset($existing['path']) ? $existing['path'] : '';
            if ($p && is_readable($p) && md5_file($p) === $hash) {
                return false;
            }
        }
        $photos[] = $photo_data;
        $this->set_session_photos($photos);
        return true;
    }

    /**
     * NUCLEAR clear — wipe every possible storage backend AND temp files.
     * Called after order creation, on thank-you page, etc.
     */
    private function clear_all_session_storage() {
        // Collect all session IDs we know about so we can delete their temp dirs.
        $session_ids_to_clean = array();

        // 1. WC session photos + session ID
        if (function_exists('WC') && WC()->session) {
            WC()->session->set('rental_checkout_photos', array());
            $wc_sid = WC()->session->get('rental_photo_upload_session_id');
            WC()->session->set('rental_photo_upload_session_id', '');
            if ($wc_sid) {
                delete_transient('rental_photos_' . $wc_sid);
                $session_ids_to_clean[] = $wc_sid;
            }
        }

        // 2. Cookie-based transient
        if (isset($_COOKIE['rental_photo_session'])) {
            $cookie_sid = sanitize_text_field($_COOKIE['rental_photo_session']);
            if ($cookie_sid) {
                delete_transient('rental_photos_' . $cookie_sid);
                $session_ids_to_clean[] = $cookie_sid;
            }
            // Expire the cookie
            setcookie('rental_photo_session', '', time() - 3600, '/', '', is_ssl(), true);
            unset($_COOKIE['rental_photo_session']);
        }

        // 3. Delete temp files on disk for all known session IDs.
        //    Photos have already been moved to permanent order storage by
        //    attach_photos_to_order(), so these are safe to remove.
        $session_ids_to_clean = array_unique($session_ids_to_clean);
        if (!empty($session_ids_to_clean)) {
            $upload_dir = wp_upload_dir();
            $base_dir   = $upload_dir['basedir'] . '/' . $this->upload_dir_name;
            foreach ($session_ids_to_clean as $sid) {
                $this->remove_directory($base_dir . '/' . $sid);
            }
        }
    }

    // =========================================================================
    // AJAX Handlers
    // =========================================================================

    public function ajax_upload_photo() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'rental_photo_upload_nonce')) {
            wp_send_json_error(array('message' => __('Security check failed. Please refresh the page and try again.', 'rentopian-sync')), 403);
        }
        if (empty($_FILES['rental_checkout_photo'])) {
            wp_send_json_error(array('message' => __('No file was uploaded.', 'rentopian-sync')), 400);
        }
        $file = $_FILES['rental_checkout_photo'];
        $validation = $this->validate_file($file);
        if (is_wp_error($validation)) {
            wp_send_json_error(array('message' => $validation->get_error_message()), 400);
        }
        $current_photos = $this->get_session_photos();
        if (count($current_photos) >= $this->max_files) {
            wp_send_json_error(array('message' => sprintf(__('Maximum of %d photos allowed.', 'rentopian-sync'), $this->max_files)), 400);
        }
        $upload_dir = $this->get_upload_dir();
        if (is_wp_error($upload_dir)) {
            wp_send_json_error(array('message' => $upload_dir->get_error_message()), 500);
        }
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $unique_id = uniqid('rp_', true);
        $filename  = $unique_id . '.' . $extension;
        $filepath  = $upload_dir['path'] . '/' . $filename;
        if (!move_uploaded_file($file['tmp_name'], $filepath)) {
            wp_send_json_error(array('message' => __('Failed to save uploaded file.', 'rentopian-sync')), 500);
        }
        $image_info = getimagesize($filepath);
        if (!$image_info) {
            @unlink($filepath);
            wp_send_json_error(array('message' => __('The uploaded file is not a valid image.', 'rentopian-sync')), 400);
        }
        $photo_data = array(
            'id'            => $unique_id,
            'filename'      => $filename,
            'original_name' => sanitize_file_name($file['name']),
            'path'          => $filepath,
            'url'           => $upload_dir['url'] . '/' . $filename,
            'size'          => filesize($filepath),
            'mime_type'     => $image_info['mime'],
            'uploaded_at'   => time(),
        );
        $added = $this->add_session_photo($photo_data);
        wp_send_json_success(array(
            'photo' => array(
                'id'            => $photo_data['id'],
                'filename'      => $photo_data['filename'],
                'original_name' => $photo_data['original_name'],
                'url'           => $photo_data['url'],
                'size'          => $photo_data['size'],
            ),
            'count'     => count($this->get_session_photos()),
            'max'       => $this->max_files,
            'message'   => $added ? __('Photo uploaded successfully.', 'rentopian-sync') : __('This photo was already added.', 'rentopian-sync'),
            'duplicate' => !$added,
        ));
    }

    public function ajax_remove_photo() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'rental_photo_upload_nonce')) {
            wp_send_json_error(array('message' => __('Security check failed.', 'rentopian-sync')), 403);
        }
        $photo_id = isset($_POST['photo_id']) ? sanitize_text_field($_POST['photo_id']) : '';
        if (empty($photo_id)) {
            wp_send_json_error(array('message' => __('Invalid photo ID.', 'rentopian-sync')), 400);
        }
        $photos = $this->get_session_photos();
        $removed = false;
        foreach ($photos as $index => $photo) {
            if ($photo['id'] === $photo_id) {
                if (isset($photo['path']) && file_exists($photo['path'])) {
                    @unlink($photo['path']);
                }
                unset($photos[$index]);
                $removed = true;
                break;
            }
        }
        if (!$removed) {
            wp_send_json_error(array('message' => __('Photo not found.', 'rentopian-sync')), 404);
        }
        $this->set_session_photos(array_values($photos));
        wp_send_json_success(array('count' => count($photos), 'max' => $this->max_files, 'message' => __('Photo removed.', 'rentopian-sync')));
    }

    /**
     * AJAX: Return current session photos. JS calls this on checkout page load
     * to detect stale photos (photos exist on server but not in JS state).
     */
    public function ajax_get_session_photos() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'rental_photo_upload_nonce')) {
            wp_send_json_error(array('message' => __('Security check failed.', 'rentopian-sync')), 403);
        }
        $photos = $this->get_session_photos();
        $out = array();
        foreach ($photos as $photo) {
            $out[] = array(
                'id'            => isset($photo['id']) ? $photo['id'] : '',
                'filename'      => isset($photo['filename']) ? $photo['filename'] : '',
                'original_name' => isset($photo['original_name']) ? $photo['original_name'] : '',
                'url'           => isset($photo['url']) ? $photo['url'] : '',
                'size'          => isset($photo['size']) ? $photo['size'] : 0,
            );
        }
        wp_send_json_success(array('photos' => $out, 'count' => count($out), 'max' => $this->max_files));
    }

    /**
     * AJAX: Clear session photos. JS calls this when it detects stale state.
     */
    public function ajax_clear_session_photos() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'rental_photo_upload_nonce')) {
            wp_send_json_error(array('message' => __('Security check failed.', 'rentopian-sync')), 403);
        }
        $this->clear_all_session_storage();
        wp_send_json_success(array('message' => __('Session photos cleared.', 'rentopian-sync'), 'count' => 0));
    }

    // =========================================================================
    // File Validation
    // =========================================================================

    private function validate_file($file) {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $msgs = array(
                UPLOAD_ERR_INI_SIZE   => __('File exceeds server upload limit.', 'rentopian-sync'),
                UPLOAD_ERR_FORM_SIZE  => __('File exceeds form upload limit.', 'rentopian-sync'),
                UPLOAD_ERR_PARTIAL    => __('File was only partially uploaded.', 'rentopian-sync'),
                UPLOAD_ERR_NO_FILE    => __('No file was uploaded.', 'rentopian-sync'),
                UPLOAD_ERR_NO_TMP_DIR => __('Server missing temporary folder.', 'rentopian-sync'),
                UPLOAD_ERR_CANT_WRITE => __('Failed to write file to disk.', 'rentopian-sync'),
            );
            return new WP_Error('upload_error', isset($msgs[$file['error']]) ? $msgs[$file['error']] : __('Unknown upload error.', 'rentopian-sync'));
        }
        if ($file['size'] > $this->max_file_size) {
            return new WP_Error('file_too_large', sprintf(__('File "%s" exceeds the maximum size of %d MB.', 'rentopian-sync'), sanitize_file_name($file['name']), $this->max_file_size / (1024 * 1024)));
        }
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $this->allowed_extensions, true)) {
            return new WP_Error('invalid_extension', sprintf(__('File type ".%s" is not allowed. Accepted types: %s', 'rentopian-sync'), $ext, implode(', ', array_map('strtoupper', $this->allowed_extensions))));
        }
        $finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : null;
        if ($finfo) {
            $mime = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
            if (!in_array($mime, $this->allowed_mime_types, true)) {
                return new WP_Error('invalid_mime', sprintf(__('File "%s" has an invalid file type.', 'rentopian-sync'), sanitize_file_name($file['name'])));
            }
        }
        if (!@getimagesize($file['tmp_name'])) {
            return new WP_Error('not_an_image', sprintf(__('File "%s" is not a valid image.', 'rentopian-sync'), sanitize_file_name($file['name'])));
        }
        return true;
    }

    // =========================================================================
    // Order Attachment
    // =========================================================================

    public function attach_photos_to_order_on_new_order($order_id) {
        $order = $order_id ? wc_get_order($order_id) : null;
        if (!$order) {
            return;
        }
        $this->attach_photos_to_order($order);
    }

    /**
     * Attach photos to order meta, move files to permanent storage, then
     * nuclear-clear ALL session storage including temp files on disk.
     *
     * Only photos whose IDs appear in $_POST['rental_checkout_photo_ids']
     * (the hidden fields created by JS for the current submission) are
     * attached. This prevents stale session photos from a previous order
     * leaking into a new one.
     *
     * Uses _rental_checkout_photos_attached meta guard to prevent double-attachment.
     */
    public function attach_photos_to_order($order) {
        $order_id = $order->get_id();

        // Guard: prevent double-attachment
        if (get_post_meta($order_id, '_rental_checkout_photos_attached', true)) {
            return;
        }

        // Source of truth: the hidden fields JS added for THIS submission.
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $posted_ids = isset($_POST['rental_checkout_photo_ids']) && is_array($_POST['rental_checkout_photo_ids'])
            ? array_map('sanitize_text_field', $_POST['rental_checkout_photo_ids'])
            : array();

        $session_photos = $this->get_session_photos();

        // Filter session photos to only those submitted with this order.
        $photos = array();
        if (!empty($posted_ids) && !empty($session_photos)) {
            $posted_ids_map = array_flip($posted_ids);
            foreach ($session_photos as $photo) {
                $id = isset($photo['id']) ? $photo['id'] : '';
                if ($id && isset($posted_ids_map[$id])) {
                    $photos[] = $photo;
                }
            }
        }

        if (empty($photos)) {
            update_post_meta($order_id, '_rental_checkout_photos', array());
            update_post_meta($order_id, '_rental_checkout_photos_count', 0);
            update_post_meta($order_id, '_rental_checkout_photos_attached', 1);
            $this->clear_all_session_storage();
            return;
        }

        // Deduplicate by photo ID (belt-and-suspenders)
        $seen_ids = array();
        $unique = array();
        foreach ($photos as $photo) {
            $id = isset($photo['id']) ? $photo['id'] : '';
            if ($id && isset($seen_ids[$id])) {
                continue;
            }
            if ($id) {
                $seen_ids[$id] = true;
            }
            $unique[] = $photo;
        }

        // Move files from temp session dir to permanent order dir so
        // they survive the temp cleanup cron and session directory deletion.
        $order_dir = $this->get_order_photo_dir($order_id);

        $photo_meta = array();
        foreach ($unique as $photo) {
            $src_path = isset($photo['path']) ? $photo['path'] : '';
            $filename = isset($photo['filename']) ? $photo['filename'] : '';

            $new_path = $src_path;
            $new_url  = isset($photo['url']) ? $photo['url'] : '';

            // Move file to permanent order directory if source exists.
            if ($src_path && $filename && !is_wp_error($order_dir) && file_exists($src_path)) {
                $dest_path = $order_dir['path'] . '/' . $filename;
                if (@copy($src_path, $dest_path)) {
                    $new_path = $dest_path;
                    $new_url  = $order_dir['url'] . '/' . $filename;
                    @unlink($src_path); // Remove original temp file
                }
            }

            $photo_meta[] = array(
                'id'            => isset($photo['id']) ? $photo['id'] : '',
                'filename'      => $filename,
                'original_name' => isset($photo['original_name']) ? $photo['original_name'] : '',
                'path'          => $new_path,
                'url'           => $new_url,
                'size'          => isset($photo['size']) ? $photo['size'] : 0,
                'mime_type'     => isset($photo['mime_type']) ? $photo['mime_type'] : '',
            );
        }

        update_post_meta($order_id, '_rental_checkout_photos', $photo_meta);
        update_post_meta($order_id, '_rental_checkout_photos_count', count($photo_meta));
        update_post_meta($order_id, '_rental_checkout_photos_attached', 1);

        // NUCLEAR clear (session data + temp files on disk)
        $this->clear_all_session_storage();
    }

    /**
     * Get or create a permanent photo directory for a specific order.
     *
     * @param int $order_id
     * @return array{path: string, url: string}|WP_Error
     */
    private function get_order_photo_dir($order_id) {
        $upload_dir = wp_upload_dir();
        if ($upload_dir['error']) {
            return new WP_Error('upload_dir_error', $upload_dir['error']);
        }
        $dir = $upload_dir['basedir'] . '/' . $this->upload_dir_name . '/orders/' . $order_id;
        $url = $upload_dir['baseurl'] . '/' . $this->upload_dir_name . '/orders/' . $order_id;
        if (!file_exists($dir)) {
            if (!wp_mkdir_p($dir)) {
                return new WP_Error('mkdir_failed', 'Failed to create order photo directory.');
            }
            @file_put_contents($dir . '/.htaccess', "Options -Indexes\n");
            @file_put_contents($dir . '/index.php', "<?php\n// Silence is golden.\n");
        }
        return array('path' => $dir, 'url' => $url);
    }

    public function clear_photo_session_after_order_created($order_id) {
        $this->clear_all_session_storage();
    }

    /**
     * Clear session on thank-you page load (belt-and-suspenders).
     */
    public function clear_session_on_thankyou($order_id) {
        $this->clear_all_session_storage();
    }

    // =========================================================================
    // API Data
    // =========================================================================

    public function add_photos_to_order_data($data, $order_id) {
        $photos = get_post_meta($order_id, '_rental_checkout_photos', true);
        if (empty($photos) || !is_array($photos)) {
            return $data;
        }
        $count = 0;
        foreach ($photos as $photo) {
            if (isset($photo['path']) && file_exists($photo['path'])) {
                $count++;
            }
        }
        if ($count > 0) {
            $data['checkout_photos_count'] = $count;
        }
        return $data;
    }

    public function get_order_photos_for_multipart($order_id) {
        $photos = get_post_meta($order_id, '_rental_checkout_photos', true);
        if (empty($photos) || !is_array($photos)) {
            return array();
        }
        $out = array();
        foreach ($photos as $photo) {
            if (!isset($photo['path']) || !file_exists($photo['path'])) {
                continue;
            }
            $out[] = array(
                'path'          => $photo['path'],
                'mime_type'     => isset($photo['mime_type']) ? $photo['mime_type'] : 'image/jpeg',
                'original_name' => isset($photo['original_name']) ? $photo['original_name'] : 'photo.jpg',
            );
        }
        return $out;
    }

    // =========================================================================
    // Cleanup
    // =========================================================================

    public function cleanup_expired_temp_files() {
        $upload_dir = wp_upload_dir();
        $base_dir   = $upload_dir['basedir'] . '/' . $this->upload_dir_name;
        if (!is_dir($base_dir)) {
            return;
        }
        $expiry_time = time() - DAY_IN_SECONDS;
        $session_dirs = glob($base_dir . '/*', GLOB_ONLYDIR);
        if (!$session_dirs) {
            return;
        }
        foreach ($session_dirs as $session_dir) {
            $dirname = basename($session_dir);

            // Never touch the permanent order photos directory.
            if ($dirname === 'orders') {
                continue;
            }

            if ($dirname === 'temp') {
                $temp_files = glob($session_dir . '/*');
                if ($temp_files) {
                    foreach ($temp_files as $f) {
                        if (filemtime($f) < $expiry_time) {
                            @unlink($f);
                        }
                    }
                }
                continue;
            }

            // Remove expired temp session directories.
            if (filemtime($session_dir) < $expiry_time) {
                $this->remove_directory($session_dir);
            }
        }
    }

    /**
     * Recursively remove a directory and ALL its contents (including dotfiles).
     *
     * glob() skips dotfiles like .htaccess, so we use scandir() instead to
     * ensure the directory is truly empty before rmdir().
     *
     * @param string $dir Absolute path to directory.
     */
    private function remove_directory($dir) {
        if (!is_dir($dir)) {
            return;
        }
        $items = @scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->remove_directory($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    public static function is_enabled() {
        return (bool) get_option('rental_checkout_photo_upload_enabled', 0);
    }
}
