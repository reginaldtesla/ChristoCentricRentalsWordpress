<?php

defined('ABSPATH') || exit;

/**
 * Isolated storage for client verification (first-time account / checkout form).
 * Uses a separate MySQL database and a private folder — not the product Media Library.
 */
final class CCR_Client_Store
{
    public const DB_NAME_DEFAULT = 'christocentric_clients';
    public const DIR = 'ccr-clients';
    public const FILE_PREFIX = 'ccrfile:';

    public const TABLE_CLIENTS = 'ccr_clients';
    public const TABLE_FILES = 'ccr_client_files';

    private static ?wpdb $db = null;
    private static bool $ready = false;
    private static string $lastError = '';

    public static function init(): void
    {
        try {
            self::bootstrap();
        } catch (Throwable $e) {
            self::$ready = false;
            self::$lastError = $e->getMessage();
        }
        add_action('wp_ajax_ccr_client_file', [self::class, 'ajax_serve_file']);
        add_action('admin_notices', [self::class, 'admin_notice']);
    }

    public static function bootstrap(): void
    {
        self::db();
    }

    public static function last_error(): string
    {
        return self::$lastError;
    }

    public static function is_ready(): bool
    {
        self::db();

        return self::$ready;
    }

    public static function db(): ?wpdb
    {
        if (self::$db instanceof wpdb && self::$ready) {
            return self::$db;
        }

        try {
            return self::connect();
        } catch (Throwable $e) {
            self::$ready = false;
            self::$lastError = $e->getMessage();
            self::$db = null;

            return null;
        }
    }

    private static function connect(): ?wpdb
    {
        $name = defined('CCR_CLIENTS_DB_NAME') ? (string) CCR_CLIENTS_DB_NAME : self::DB_NAME_DEFAULT;
        $user = defined('CCR_CLIENTS_DB_USER') ? (string) CCR_CLIENTS_DB_USER : DB_USER;
        $pass = defined('CCR_CLIENTS_DB_PASSWORD') ? (string) CCR_CLIENTS_DB_PASSWORD : DB_PASSWORD;
        $host = defined('CCR_CLIENTS_DB_HOST') ? (string) CCR_CLIENTS_DB_HOST : DB_HOST;

        $usingDefaults = ! defined('CCR_CLIENTS_DB_NAME') || ! defined('CCR_CLIENTS_DB_USER') || ! defined('CCR_CLIENTS_DB_PASSWORD');
        if ($pass === '' || $pass === 'paste-the-password-here') {
            self::$lastError = 'CCR_CLIENTS_DB_PASSWORD is missing or still set to the example text. Put the real Hostinger password in wp-config.php, above the “stop editing” line.';
            self::$ready = false;

            return null;
        }

        $mysqli = @new mysqli($host, $user, $pass, $name);
        if ($mysqli->connect_error) {
            self::$lastError = sprintf(
                'Could not connect to %s as %s @ %s — %s%s',
                $name,
                $user,
                $host,
                $mysqli->connect_error,
                $usingDefaults ? ' (wp-config is missing one of CCR_CLIENTS_DB_NAME / USER / PASSWORD.)' : ''
            );
            self::$ready = false;

            return null;
        }
        $mysqli->close();

        $db = new wpdb($user, $pass, $name, $host);
        if (! $db->ready) {
            $raw = is_string($db->error) ? wp_strip_all_tags($db->error) : $db->last_error;
            self::$lastError = $raw !== '' ? $raw : sprintf('wpdb could not use database %s.', $name);
            self::$ready = false;

            return null;
        }

        $charset = defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4';
        $collate = defined('DB_COLLATE') ? DB_COLLATE : 'utf8mb4_unicode_ci';
        if (is_object($db->dbh)) {
            $db->set_charset($db->dbh, $charset);
        }

        self::$db = $db;
        self::install_schema($db, $charset, $collate);
        self::$ready = self::tables_exist();
        if (! self::$ready && self::$lastError === '') {
            $sqlError = (string) $db->last_error;
            self::$lastError = $sqlError !== ''
                ? $sqlError
                : 'Connected, but tables ccr_clients / ccr_client_files were not created. In hPanel, edit the database user and grant ALL PRIVILEGES on this database.';
        }
        self::protect_dir();

        return self::$ready ? $db : null;
    }

    private static function maybe_create_database(string $name, string $user, string $pass, string $host): void
    {
        if (! preg_match('/^[A-Za-z0-9_]+$/', $name)) {
            return;
        }
        try {
            $mysqli = @new mysqli($host, $user, $pass);
            if ($mysqli->connect_error) {
                return;
            }
            $mysqli->query(
                'CREATE DATABASE IF NOT EXISTS `' . $mysqli->real_escape_string($name) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
            );
            $mysqli->close();
        } catch (Throwable $e) {
            // Hostinger users often cannot CREATE DATABASE from PHP — they add the DB in hPanel.
        }
    }

    private static function install_schema(wpdb $db, string $charset, string $collate): void
    {
        $clients = self::TABLE_CLIENTS;
        $files = self::TABLE_FILES;
        $charsetCollate = 'DEFAULT CHARACTER SET ' . $charset . ($collate !== '' ? ' COLLATE ' . $collate : '');
        $db->query(
            "CREATE TABLE IF NOT EXISTS {$clients} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id BIGINT UNSIGNED NOT NULL,
                payload LONGTEXT NOT NULL,
                completed TINYINT(1) NOT NULL DEFAULT 0,
                signed_at VARCHAR(32) NOT NULL DEFAULT '',
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY user_id (user_id)
            ) {$charsetCollate}"
        );
        $db->query(
            "CREATE TABLE IF NOT EXISTS {$files} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id BIGINT UNSIGNED NOT NULL,
                field_key VARCHAR(64) NOT NULL,
                filename VARCHAR(255) NOT NULL,
                rel_path VARCHAR(500) NOT NULL,
                mime VARCHAR(120) NOT NULL DEFAULT '',
                filesize INT UNSIGNED NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY user_field (user_id, field_key)
            ) {$charsetCollate}"
        );

        if ($db->last_error !== '') {
            self::$lastError = $db->last_error;
            self::$ready = false;
        }
    }

    public static function tables_exist(): bool
    {
        $db = self::$db;
        if (! $db instanceof wpdb) {
            return false;
        }
        $clients = $db->get_var($db->prepare('SHOW TABLES LIKE %s', self::TABLE_CLIENTS));
        $files = $db->get_var($db->prepare('SHOW TABLES LIKE %s', self::TABLE_FILES));

        return $clients === self::TABLE_CLIENTS && $files === self::TABLE_FILES;
    }

    public static function dir(): string
    {
        $upload = wp_upload_dir();
        $base = trailingslashit((string) ($upload['basedir'] ?? WP_CONTENT_DIR . '/uploads'));

        return $base . self::DIR;
    }

    public static function protect_dir(): void
    {
        $dir = self::dir();
        if (! is_dir($dir)) {
            wp_mkdir_p($dir);
        }
        $ht = $dir . '/.htaccess';
        if (! is_file($ht)) {
            file_put_contents($ht, "Require all denied\nDeny from all\n");
        }
        $index = $dir . '/index.php';
        if (! is_file($index)) {
            file_put_contents($index, "<?php\n// Silence is golden.\n");
        }
    }

    public static function file_ref(int $fileId): string
    {
        return self::FILE_PREFIX . $fileId;
    }

    public static function parse_file_id(string $ref): int
    {
        $ref = trim($ref);
        if (str_starts_with($ref, self::FILE_PREFIX)) {
            return absint(substr($ref, strlen(self::FILE_PREFIX)));
        }

        return 0;
    }

    /**
     * @return array<string,string>
     */
    public static function get_payload(int $userId): array
    {
        $db = self::db();
        if (! $db || $userId <= 0) {
            return [];
        }
        $row = $db->get_row(
            $db->prepare('SELECT payload, completed, signed_at FROM ' . self::TABLE_CLIENTS . ' WHERE user_id = %d', $userId),
            ARRAY_A
        );
        if (! is_array($row)) {
            return [];
        }
        $data = json_decode((string) ($row['payload'] ?? ''), true);
        if (! is_array($data)) {
            $data = [];
        }
        $data['_completed'] = ((int) ($row['completed'] ?? 0)) === 1 ? 'yes' : 'no';
        $data['_signed_at'] = (string) ($row['signed_at'] ?? '');

        $files = $db->get_results(
            $db->prepare('SELECT id, field_key FROM ' . self::TABLE_FILES . ' WHERE user_id = %d', $userId),
            ARRAY_A
        );
        if (is_array($files)) {
            foreach ($files as $file) {
                $key = (string) ($file['field_key'] ?? '');
                $id = absint($file['id'] ?? 0);
                if ($key !== '' && $id > 0) {
                    $data[$key] = self::file_ref($id);
                }
            }
        }

        return $data;
    }

    public static function is_complete(int $userId): bool
    {
        $db = self::db();
        if (! $db || $userId <= 0) {
            return false;
        }
        $completed = (int) $db->get_var(
            $db->prepare('SELECT completed FROM ' . self::TABLE_CLIENTS . ' WHERE user_id = %d', $userId)
        );

        return $completed === 1;
    }

    /**
     * @param array<string,string> $fields
     * @param array<string,string> $fileRefs field => ccrfile:id
     */
    public static function save(int $userId, array $fields, array $fileRefs, bool $completed = true): void
    {
        $db = self::db();
        if (! $db || $userId <= 0) {
            return;
        }
        $now = current_time('mysql');
        $signed = $completed ? gmdate('c') : '';
        $payload = wp_json_encode($fields) ?: '{}';
        $existing = (int) $db->get_var($db->prepare('SELECT id FROM ' . self::TABLE_CLIENTS . ' WHERE user_id = %d', $userId));
        if ($existing > 0) {
            $db->update(
                self::TABLE_CLIENTS,
                [
                    'payload' => $payload,
                    'completed' => $completed ? 1 : 0,
                    'signed_at' => $signed !== '' ? $signed : (string) $db->get_var($db->prepare('SELECT signed_at FROM ' . self::TABLE_CLIENTS . ' WHERE user_id = %d', $userId)),
                    'updated_at' => $now,
                ],
                ['user_id' => $userId],
                ['%s', '%d', '%s', '%s'],
                ['%d']
            );
        } else {
            $db->insert(
                self::TABLE_CLIENTS,
                [
                    'user_id' => $userId,
                    'payload' => $payload,
                    'completed' => $completed ? 1 : 0,
                    'signed_at' => $signed,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                ['%d', '%s', '%d', '%s', '%s', '%s']
            );
        }

        foreach ($fileRefs as $field => $ref) {
            $fileId = self::parse_file_id((string) $ref);
            if ($fileId <= 0) {
                continue;
            }
            $db->update(
                self::TABLE_FILES,
                ['field_key' => sanitize_key($field)],
                ['id' => $fileId, 'user_id' => $userId],
                ['%s'],
                ['%d', '%d']
            );
        }
    }

    /**
     * @return string|WP_Error File ref (ccrfile:id) or empty string if no upload.
     */
    public static function store_upload(string $field, int $userId)
    {
        if (empty($_FILES[$field]) || ! is_array($_FILES[$field])) {
            return '';
        }
        $file = $_FILES[$field];
        $errorCode = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($errorCode === UPLOAD_ERR_NO_FILE) {
            return '';
        }
        if ($errorCode !== UPLOAD_ERR_OK) {
            return new WP_Error('ccr_upload', __('Could not upload that file. Try a smaller JPG, PNG, or PDF (under 8MB).', 'christocentric-rentals'));
        }
        $size = (int) ($file['size'] ?? 0);
        if ($size > 8 * MB_IN_BYTES) {
            return new WP_Error('ccr_upload', __('One of your files is too large. Please upload images under 8MB.', 'christocentric-rentals'));
        }

        $db = self::db();
        if (! $db) {
            return new WP_Error('ccr_upload', __('Client verification storage is not available.', 'christocentric-rentals'));
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        $overrides = [
            'test_form' => false,
            'mimes' => [
                'jpg|jpeg|jpe' => 'image/jpeg',
                'png' => 'image/png',
                'webp' => 'image/webp',
                'pdf' => 'application/pdf',
            ],
        ];

        $userDir = self::dir() . '/' . $userId;
        wp_mkdir_p($userDir);

        add_filter('upload_dir', [self::class, 'upload_dir_for_user']);
        $GLOBALS['ccr_client_upload_user'] = $userId;
        $moved = wp_handle_upload($file, $overrides);
        unset($GLOBALS['ccr_client_upload_user']);
        remove_filter('upload_dir', [self::class, 'upload_dir_for_user']);

        if (! is_array($moved) || ! empty($moved['error'])) {
            return new WP_Error('ccr_upload', is_array($moved) ? (string) $moved['error'] : __('Upload failed.', 'christocentric-rentals'));
        }

        $abs = (string) ($moved['file'] ?? '');
        $rel = self::DIR . '/' . $userId . '/' . basename($abs);
        $filename = basename($abs);
        $mime = (string) ($moved['type'] ?? '');
        $now = current_time('mysql');

        $existingId = (int) $db->get_var($db->prepare(
            'SELECT id FROM ' . self::TABLE_FILES . ' WHERE user_id = %d AND field_key = %s',
            $userId,
            $field
        ));
        if ($existingId > 0) {
            $oldPath = (string) $db->get_var($db->prepare('SELECT rel_path FROM ' . self::TABLE_FILES . ' WHERE id = %d', $existingId));
            $db->update(
                self::TABLE_FILES,
                [
                    'filename' => $filename,
                    'rel_path' => $rel,
                    'mime' => $mime,
                    'filesize' => $size,
                    'created_at' => $now,
                ],
                ['id' => $existingId],
                ['%s', '%s', '%s', '%d', '%s'],
                ['%d']
            );
            self::delete_abs_if_ours($oldPath);
            $id = $existingId;
        } else {
            $db->insert(
                self::TABLE_FILES,
                [
                    'user_id' => $userId,
                    'field_key' => $field,
                    'filename' => $filename,
                    'rel_path' => $rel,
                    'mime' => $mime,
                    'filesize' => $size,
                    'created_at' => $now,
                ],
                ['%d', '%s', '%s', '%s', '%s', '%d', '%s']
            );
            $id = (int) $db->insert_id;
        }

        return $id > 0 ? self::file_ref($id) : new WP_Error('ccr_upload', __('Could not save uploaded file.', 'christocentric-rentals'));
    }

    /** @param array<string,string> $dirs */
    public static function upload_dir_for_user(array $dirs): array
    {
        $userId = absint($GLOBALS['ccr_client_upload_user'] ?? 0);
        $subdir = '/' . self::DIR . ($userId > 0 ? '/' . $userId : '');
        $dirs['subdir'] = $subdir;
        $dirs['path'] = ($dirs['basedir'] ?? '') . $subdir;
        $dirs['url'] = ($dirs['baseurl'] ?? '') . $subdir;

        return $dirs;
    }

    private static function delete_abs_if_ours(string $relPath): void
    {
        if ($relPath === '' || ! str_starts_with($relPath, self::DIR . '/')) {
            return;
        }
        $abs = trailingslashit((string) (wp_upload_dir()['basedir'] ?? '')) . $relPath;
        if (is_file($abs)) {
            @unlink($abs);
        }
    }

    public static function file_display_name(string $ref): string
    {
        $id = self::parse_file_id($ref);
        if ($id <= 0) {
            return '';
        }
        $db = self::db();
        if (! $db) {
            return '';
        }
        $name = (string) $db->get_var($db->prepare('SELECT filename FROM ' . self::TABLE_FILES . ' WHERE id = %d', $id));

        return $name;
    }

    public static function file_url(string $ref): string
    {
        $id = self::parse_file_id($ref);
        if ($id <= 0) {
            return '';
        }

        return wp_nonce_url(
            add_query_arg(
                [
                    'action' => 'ccr_client_file',
                    'id' => $id,
                ],
                admin_url('admin-ajax.php')
            ),
            'ccr_client_file_' . $id
        );
    }

    public static function ajax_serve_file(): void
    {
        $fileId = absint($_GET['id'] ?? $_REQUEST['id'] ?? 0); // phpcs:ignore
        if ($fileId <= 0) {
            wp_die(esc_html__('File not found.', 'christocentric-rentals'), '', ['response' => 404]);
        }
        if (! is_user_logged_in()) {
            wp_die(esc_html__('You must be signed in.', 'christocentric-rentals'), '', ['response' => 403]);
        }
        check_admin_referer('ccr_client_file_' . $fileId);

        $db = self::db();
        if (! $db) {
            wp_die(esc_html__('File not found.', 'christocentric-rentals'), '', ['response' => 404]);
        }
        $row = $db->get_row($db->prepare('SELECT * FROM ' . self::TABLE_FILES . ' WHERE id = %d', $fileId), ARRAY_A);
        if (! is_array($row)) {
            wp_die(esc_html__('File not found.', 'christocentric-rentals'), '', ['response' => 404]);
        }
        $owner = absint($row['user_id'] ?? 0);
        if ($owner !== get_current_user_id() && ! current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You cannot view this file.', 'christocentric-rentals'), '', ['response' => 403]);
        }
        $abs = trailingslashit((string) (wp_upload_dir()['basedir'] ?? '')) . (string) ($row['rel_path'] ?? '');
        if (! is_file($abs)) {
            wp_die(esc_html__('File not found.', 'christocentric-rentals'), '', ['response' => 404]);
        }

        nocache_headers();
        header('Content-Type: ' . ((string) ($row['mime'] ?: 'application/octet-stream')));
        header('Content-Disposition: inline; filename="' . rawurlencode((string) $row['filename']) . '"');
        header('Content-Length: ' . (string) filesize($abs));
        readfile($abs);
        exit;
    }

    public static function admin_notice(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }
        if (self::is_ready()) {
            return;
        }
        echo '<div class="notice notice-error"><p><strong>' . esc_html__('Christocentric Rentals: client verification storage is not ready.', 'christocentric-rentals') . '</strong></p>';
        if (self::$lastError !== '') {
            echo '<p><code>' . esc_html(self::$lastError) . '</code></p>';
        }
        echo '<p>' . esc_html__('In Hostinger wp-config.php the four CCR_CLIENTS_DB_* lines must sit above “That’s all, stop editing”. CCR_CLIENTS_DB_HOST should match DB_HOST. CCR_CLIENTS_DB_PASSWORD must be the real password, not paste-the-password-here.', 'christocentric-rentals') . '</p></div>';
    }

    /** Copy old usermeta / Media Library ID uploads into the clients database once. */
    public static function migrate_user(int $userId): void
    {
        if ($userId <= 0 || ! self::is_ready()) {
            return;
        }
        if (self::is_complete($userId) || self::get_payload($userId) !== []) {
            return;
        }
        if ((string) get_user_meta($userId, '_ccr_agreement_completed', true) !== 'yes') {
            return;
        }

        $fields = [];
        if (class_exists('CCR_Rental_Agreement')) {
            foreach (CCR_Rental_Agreement::text_field_map() as $field => $meta) {
                $fields[$field] = (string) get_user_meta($userId, $meta, true);
            }
        }
        $fileRefs = [];
        $fileMap = class_exists('CCR_Rental_Agreement') ? CCR_Rental_Agreement::file_field_map() : [];
        foreach ($fileMap as $field => $meta) {
            $attachId = absint(get_user_meta($userId, $meta, true));
            if ($attachId <= 0) {
                continue;
            }
            $src = get_attached_file($attachId);
            if (! is_string($src) || ! is_file($src)) {
                continue;
            }
            $copied = self::ingest_existing_file($userId, $field, $src, (string) get_post_mime_type($attachId));
            if ($copied !== '') {
                $fileRefs[$field] = $copied;
            }
        }
        self::save($userId, $fields, $fileRefs, true);
    }

    private static function ingest_existing_file(int $userId, string $field, string $src, string $mime): string
    {
        $db = self::db();
        if (! $db) {
            return '';
        }
        $userDir = self::dir() . '/' . $userId;
        wp_mkdir_p($userDir);
        $filename = sanitize_file_name(basename($src));
        $dest = $userDir . '/' . $filename;
        if (! @copy($src, $dest)) {
            return '';
        }
        $rel = self::DIR . '/' . $userId . '/' . $filename;
        $now = current_time('mysql');
        $db->replace(
            self::TABLE_FILES,
            [
                'user_id' => $userId,
                'field_key' => $field,
                'filename' => $filename,
                'rel_path' => $rel,
                'mime' => $mime,
                'filesize' => (int) filesize($dest),
                'created_at' => $now,
            ]
        );
        $id = (int) $db->insert_id;
        if ($id <= 0) {
            $id = (int) $db->get_var($db->prepare(
                'SELECT id FROM ' . self::TABLE_FILES . ' WHERE user_id = %d AND field_key = %s',
                $userId,
                $field
            ));
        }

        return $id > 0 ? self::file_ref($id) : '';
    }
}
