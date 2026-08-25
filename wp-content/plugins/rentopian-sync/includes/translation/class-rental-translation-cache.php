<?php
/**
 * Translation Cache (Database Table)
 *
 * Persists translated strings in a custom DB table so they survive re-syncs.
 *
 * v1.1 changes:
 *  - Added `is_manual` TINYINT(1) column. When 1, the row was manually corrected
 *    by an admin and will NOT be overwritten by auto-translation.
 *
 * @package RentopianSync\Translation
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Rental_Translation_Cache {

    private const TABLE_NAME         = 'rental_translation_cache';
    private const DB_VERSION_OPTION  = 'rental_translation_cache_db_version';
    private const DB_VERSION         = '1.1';

    private string $table;
    private static ?self $instance   = null;
    private array $memory_cache      = [];

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . self::TABLE_NAME;
    }

    public static function get_instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    // ─────────────────────────────────────────────────────────────
    //  Table creation / migration
    // ─────────────────────────────────────────────────────────────

    public function maybe_create_table(): void {
        $installed_version = get_option( self::DB_VERSION_OPTION, '0' );

        if ( version_compare( $installed_version, self::DB_VERSION, '>=' ) ) {
            return;
        }

        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$this->table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            source_hash CHAR(32) NOT NULL,
            source_text LONGTEXT NOT NULL,
            source_lang VARCHAR(10) NOT NULL DEFAULT 'en',
            target_lang VARCHAR(10) NOT NULL,
            translated_text LONGTEXT NOT NULL,
            provider VARCHAR(20) NOT NULL DEFAULT 'deepl',
            char_count INT UNSIGNED NOT NULL DEFAULT 0,
            is_manual TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY source_target (source_hash, target_lang),
            KEY target_lang (target_lang),
            KEY provider (provider),
            KEY is_manual (is_manual)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        // If upgrading from 1.0, ensure column exists (dbDelta may not add it reliably).
        if ( version_compare( $installed_version, '1.0', '>=' ) && version_compare( $installed_version, '1.1', '<' ) ) {
            $col_exists = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                     WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'is_manual'",
                    DB_NAME,
                    $this->table
                )
            );
            if ( ! $col_exists ) {
                $wpdb->query( "ALTER TABLE {$this->table} ADD COLUMN is_manual TINYINT(1) NOT NULL DEFAULT 0 AFTER char_count" );
                $wpdb->query( "ALTER TABLE {$this->table} ADD KEY is_manual (is_manual)" );
            }
        }

        update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
    }

    // ─────────────────────────────────────────────────────────────
    //  Lookup
    // ─────────────────────────────────────────────────────────────

    public function get( string $source_text, string $target_lang, string $source_lang = 'en' ): ?string {
        if ( empty( trim( $source_text ) ) ) {
            return $source_text;
        }

        $hash      = $this->make_hash( $source_text, $source_lang );
        $cache_key = $hash . ':' . $target_lang;

        if ( isset( $this->memory_cache[ $cache_key ] ) ) {
            return $this->memory_cache[ $cache_key ];
        }

        global $wpdb;

        $translated = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT translated_text FROM {$this->table}
                 WHERE source_hash = %s AND target_lang = %s
                 LIMIT 1",
                $hash,
                $target_lang
            )
        );

        if ( null !== $translated ) {
            $this->memory_cache[ $cache_key ] = $translated;
        }

        return $translated;
    }

    public function get_batch( array $texts, string $target_lang, string $source_lang = 'en' ): array {
        $hits   = [];
        $misses = [];

        if ( empty( $texts ) ) {
            return compact( 'hits', 'misses' );
        }

        $to_query = [];
        foreach ( $texts as $key => $text ) {
            if ( empty( trim( $text ) ) ) {
                $hits[ $key ] = $text;
                continue;
            }

            $hash      = $this->make_hash( $text, $source_lang );
            $cache_key = $hash . ':' . $target_lang;

            if ( isset( $this->memory_cache[ $cache_key ] ) ) {
                $hits[ $key ] = $this->memory_cache[ $cache_key ];
            } else {
                $to_query[ $hash ] = $key;
            }
        }

        if ( empty( $to_query ) ) {
            return compact( 'hits', 'misses' );
        }

        global $wpdb;

        $hashes       = array_keys( $to_query );
        $placeholders = implode( ',', array_fill( 0, count( $hashes ), '%s' ) );

        $args   = $hashes;
        $args[] = $target_lang;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT source_hash, translated_text FROM {$this->table}
                 WHERE source_hash IN ({$placeholders}) AND target_lang = %s",
                ...$args
            )
        );

        $found_hashes = [];
        if ( $rows ) {
            foreach ( $rows as $row ) {
                $found_hashes[ $row->source_hash ] = $row->translated_text;
            }
        }

        foreach ( $to_query as $hash => $original_key ) {
            if ( isset( $found_hashes[ $hash ] ) ) {
                $hits[ $original_key ] = $found_hashes[ $hash ];
                $this->memory_cache[ $hash . ':' . $target_lang ] = $found_hashes[ $hash ];
            } else {
                $misses[ $original_key ] = $texts[ $original_key ];
            }
        }

        return compact( 'hits', 'misses' );
    }

    // ─────────────────────────────────────────────────────────────
    //  Store
    // ─────────────────────────────────────────────────────────────

    /**
     * Store a translation in the cache.
     *
     * IMPORTANT: If is_manual = 1 on the existing row, auto-translation will
     * NOT overwrite it. Only manual saves (from admin UI) can update manual rows.
     *
     * @param string $source_text    Original text.
     * @param string $translated_text Translated text.
     * @param string $target_lang    Target language code.
     * @param string $source_lang    Source language code.
     * @param string $provider       Provider name ('deepl', 'google', 'manual').
     * @param bool   $is_manual      Whether this is a manual correction.
     */
    public function set(
        string $source_text,
        string $translated_text,
        string $target_lang,
        string $source_lang = 'en',
        string $provider = 'deepl',
        bool   $is_manual = false
    ): void {
        if ( empty( trim( $source_text ) ) ) {
            return;
        }

        global $wpdb;

        $hash       = $this->make_hash( $source_text, $source_lang );
        $char_count = mb_strlen( $source_text, 'UTF-8' );
        $manual_int = $is_manual ? 1 : 0;

        if ( ! $is_manual ) {
            // Auto-translation: do NOT overwrite if is_manual = 1 on existing row.
            $existing_manual = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT is_manual FROM {$this->table}
                     WHERE source_hash = %s AND target_lang = %s
                     LIMIT 1",
                    $hash,
                    $target_lang
                )
            );

            if ( $existing_manual !== null && (int) $existing_manual === 1 ) {
                // Manual override exists — do not overwrite. Use it instead.
                $this->memory_cache[ $hash . ':' . $target_lang ] = $this->get( $source_text, $target_lang, $source_lang );
                return;
            }
        }

        $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO {$this->table}
                    (source_hash, source_text, source_lang, target_lang, translated_text, provider, char_count, is_manual)
                 VALUES (%s, %s, %s, %s, %s, %s, %d, %d)
                 ON DUPLICATE KEY UPDATE
                    translated_text = VALUES(translated_text),
                    provider        = VALUES(provider),
                    char_count      = VALUES(char_count),
                    source_text     = VALUES(source_text),
                    is_manual       = VALUES(is_manual),
                    updated_at      = CURRENT_TIMESTAMP",
                $hash,
                $source_text,
                $source_lang,
                $target_lang,
                $translated_text,
                $provider,
                $char_count,
                $manual_int
            )
        );

        $this->memory_cache[ $hash . ':' . $target_lang ] = $translated_text;
    }

    public function set_batch(
        array $pairs,
        string $target_lang,
        string $source_lang = 'en',
        string $provider = 'deepl'
    ): void {
        foreach ( $pairs as $source => $translated ) {
            $this->set( $source, $translated, $target_lang, $source_lang, $provider );
        }
    }

    // ─────────────────────────────────────────────────────────────
    //  Manual override helpers
    // ─────────────────────────────────────────────────────────────

    /**
     * Update a single cache row by ID (used by admin editor).
     *
     * @param int    $row_id          Cache row ID.
     * @param string $translated_text New translated text.
     *
     * @return bool True on success.
     */
    public function update_translation( int $row_id, string $translated_text ): bool {
        global $wpdb;

        $result = $wpdb->update(
            $this->table,
            [
                'translated_text' => $translated_text,
                'provider'        => 'manual',
                'is_manual'       => 1,
                'updated_at'      => current_time( 'mysql' ),
            ],
            [ 'id' => $row_id ],
            [ '%s', '%s', '%d', '%s' ],
            [ '%d' ]
        );

        // Invalidate memory cache.
        $this->memory_cache = [];

        return $result !== false;
    }

    /**
     * Revert a manual override back to auto-translation.
     *
     * Marks is_manual = 0 so the next sync/queue run will overwrite with a fresh API translation.
     *
     * @param int $row_id Cache row ID.
     *
     * @return bool True on success.
     */
    public function revert_to_auto( int $row_id ): bool {
        global $wpdb;

        $result = $wpdb->update(
            $this->table,
            [ 'is_manual' => 0 ],
            [ 'id' => $row_id ],
            [ '%d' ],
            [ '%d' ]
        );

        $this->memory_cache = [];

        return $result !== false;
    }

    /**
     * Get a single cache row by ID.
     *
     * @param int $row_id Cache row ID.
     *
     * @return object|null
     */
    public function get_row( int $row_id ): ?object {
        global $wpdb;

        return $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$this->table} WHERE id = %d", $row_id )
        );
    }

    // ─────────────────────────────────────────────────────────────
    //  Paginated query (for admin table)
    // ─────────────────────────────────────────────────────────────

    /**
     * Fetch paginated cache entries with optional search and language filter.
     *
     * @param int    $page        Page number (1-based).
     * @param int    $per_page    Items per page.
     * @param string $search      Search string (matches source_text or translated_text).
     * @param string $target_lang Filter by language (empty = all).
     * @param string $filter_type Filter by type: '' = all, 'manual' = is_manual=1, 'auto' = is_manual=0.
     *
     * @return array{ items: object[], total: int, total_pages: int, page: int }
     */
    public function get_paginated(
        int $page = 1,
        int $per_page = 25,
        string $search = '',
        string $target_lang = '',
        string $filter_type = ''
    ): array {
        global $wpdb;

        $where_clauses = [];
        $where_values  = [];

        if ( ! empty( $search ) ) {
            $like = '%' . $wpdb->esc_like( $search ) . '%';
            $where_clauses[] = '(source_text LIKE %s OR translated_text LIKE %s)';
            $where_values[]  = $like;
            $where_values[]  = $like;
        }

        if ( ! empty( $target_lang ) ) {
            $where_clauses[] = 'target_lang = %s';
            $where_values[]  = $target_lang;
        }

        if ( $filter_type === 'manual' ) {
            $where_clauses[] = 'is_manual = 1';
        } elseif ( $filter_type === 'auto' ) {
            $where_clauses[] = 'is_manual = 0';
        }

        $where_sql = '';
        if ( ! empty( $where_clauses ) ) {
            $where_sql = 'WHERE ' . implode( ' AND ', $where_clauses );
        }

        // Count total.
        $count_query = "SELECT COUNT(*) FROM {$this->table} {$where_sql}";
        if ( ! empty( $where_values ) ) {
            $count_query = $wpdb->prepare( $count_query, ...$where_values );
        }
        $total = (int) $wpdb->get_var( $count_query );

        // Fetch page.
        $offset      = max( 0, ( $page - 1 ) * $per_page );
        $total_pages = max( 1, (int) ceil( $total / $per_page ) );

        $data_query = "SELECT * FROM {$this->table} {$where_sql} ORDER BY is_manual DESC, updated_at DESC LIMIT %d OFFSET %d";
        $data_values = array_merge( $where_values, [ $per_page, $offset ] );
        $items = $wpdb->get_results( $wpdb->prepare( $data_query, ...$data_values ) );

        return [
            'items'       => $items ?: [],
            'total'       => $total,
            'total_pages' => $total_pages,
            'page'        => $page,
        ];
    }

    // ─────────────────────────────────────────────────────────────
    //  Stats
    // ─────────────────────────────────────────────────────────────

    public function get_stats(): array {
        global $wpdb;

        $total        = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table}" );
        $manual_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table} WHERE is_manual = 1" );

        $by_lang_rows = $wpdb->get_results(
            "SELECT target_lang, COUNT(*) as cnt, SUM(char_count) as chars_saved
             FROM {$this->table}
             GROUP BY target_lang",
            ARRAY_A
        );

        $by_lang           = [];
        $total_chars_saved = 0;

        if ( $by_lang_rows ) {
            foreach ( $by_lang_rows as $row ) {
                $by_lang[ $row['target_lang'] ] = (int) $row['cnt'];
                $total_chars_saved += (int) $row['chars_saved'];
            }
        }

        return [
            'total'             => $total,
            'manual_count'      => $manual_count,
            'by_lang'           => $by_lang,
            'total_chars_saved' => $total_chars_saved,
        ];
    }

    public function clear_all(): int {
        global $wpdb;
        $deleted = $wpdb->query( "TRUNCATE TABLE {$this->table}" );
        $this->memory_cache = [];
        return $deleted !== false ? $deleted : 0;
    }

    /**
     * Clear only auto-translated entries (preserve manual corrections).
     *
     * @return int Number of rows deleted.
     */
    public function clear_auto_only(): int {
        global $wpdb;
        $deleted = $wpdb->query( "DELETE FROM {$this->table} WHERE is_manual = 0" );
        $this->memory_cache = [];
        return $deleted !== false ? $deleted : 0;
    }

    public function clear_language( string $target_lang ): int {
        global $wpdb;
        $deleted = $wpdb->delete( $this->table, [ 'target_lang' => $target_lang ], [ '%s' ] );
        return $deleted !== false ? $deleted : 0;
    }

    // ─────────────────────────────────────────────────────────────
    //  Helpers
    // ─────────────────────────────────────────────────────────────

    private function make_hash( string $text, string $source_lang ): string {
        return md5( $source_lang . ':' . $text );
    }

    public function get_table_name(): string {
        return $this->table;
    }
}
