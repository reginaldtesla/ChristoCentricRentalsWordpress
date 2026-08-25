<?php
/**
 * Translation Queue
 *
 * Stores translation jobs in the wp_options table and processes them
 * in batches via WP-Cron.
 *
 * Logging points:
 *  - BATCH START:  batch size, total remaining, lock status
 *  - PER ITEM:     item type, object_id, success/failure
 *  - BATCH END:    processed count, errors count, elapsed time, remaining
 *
 * @package RentopianSync\Translation
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Rental_Translation_Queue {

    /**
     * Option key for the pending queue.
     *
     * @var string
     */
    private const QUEUE_OPTION = 'rental_translation_queue';

    /**
     * Option key for the processing lock.
     *
     * @var string
     */
    private const LOCK_OPTION = 'rental_translation_queue_lock';

    /**
     * WP-Cron hook name.
     *
     * @var string
     */
    public const CRON_HOOK = 'rental_process_translation_queue';

    /**
     * Log source identifier.
     *
     * @var string
     */
    private const LOG_SOURCE = 'rentopian-translation';

    /**
     * Items to process per cron tick.
     *
     * @var int
     */
    private int $batch_size = 20;

    /**
     * Lock timeout in seconds.
     *
     * @var int
     */
    private int $lock_timeout = 300;

    /**
     * Singleton instance.
     *
     * @var self|null
     */
    private static ?self $instance = null;

    /**
     * Constructor — register cron hook.
     */
    public function __construct() {
        add_action( self::CRON_HOOK, [ $this, 'process' ] );
    }

    /**
     * Get singleton.
     *
     * @return self
     */
    public static function get_instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    // ─────────────────────────────────────────────────────────────
    //  Enqueue
    // ─────────────────────────────────────────────────────────────

    /**
     * Add a post to the translation queue.
     *
     * @param int    $post_id   The WP post ID.
     * @param string $post_type Post type.
     * @param array  $fields    Translatable field values.
     */
    public function enqueue_post( int $post_id, string $post_type, array $fields = [] ): void {
        $this->add_to_queue( [
            'type'      => 'post',
            'object_id' => $post_id,
            'post_type' => $post_type,
            'fields'    => $fields,
            'added_at'  => time(),
        ] );
    }

    /**
     * Add a term to the translation queue.
     *
     * @param int    $term_id  The WP term ID.
     * @param string $taxonomy Taxonomy name.
     * @param array  $fields   Translatable field values.
     */
    public function enqueue_term( int $term_id, string $taxonomy, array $fields = [] ): void {
        $this->add_to_queue( [
            'type'      => 'term',
            'object_id' => $term_id,
            'taxonomy'  => $taxonomy,
            'fields'    => $fields,
            'added_at'  => time(),
        ] );
    }

    /**
     * Enqueue all untranslated posts/terms after a bulk sync.
     */
    public function enqueue_bulk_sync_items(): void {
        if ( ! function_exists( 'pll_default_language' ) ) {
            return;
        }

        $default_lang  = pll_default_language( 'slug' );
        $all_languages = pll_languages_list( [ 'hide_empty' => false ] );
        $secondary     = array_diff( $all_languages, [ $default_lang ] );

        if ( empty( $secondary ) ) {
            return;
        }

        $token = Project_WP_Logger::start( 'bulk_enqueue' );
        Project_WP_Logger::write(
            sprintf( 'Bulk enqueue started. Default lang: %s, secondary: %s', $default_lang, implode( ', ', $secondary ) ),
            'info',
            self::LOG_SOURCE
        );

        $count_before = $this->get_pending_count();

        // Enqueue products and variations.
        $this->enqueue_untranslated_posts( [ 'product', 'product_variation' ] );

        // Enqueue taxonomy terms.
        $taxonomies = [ 'product_cat', 'product_tag', 'product_brand' ];

        $attribute_taxonomies = wc_get_attribute_taxonomies();
        if ( ! empty( $attribute_taxonomies ) ) {
            foreach ( $attribute_taxonomies as $attr ) {
                $taxonomies[] = 'pa_' . $attr->attribute_name;
            }
        }

        foreach ( $taxonomies as $taxonomy ) {
            if ( taxonomy_exists( $taxonomy ) ) {
                $this->enqueue_untranslated_terms( $taxonomy );
            }
        }

        $this->ensure_cron_scheduled();

        $count_after = $this->get_pending_count();
        $added       = $count_after - $count_before;

        Project_WP_Logger::stop(
            $token,
            'bulk_enqueue',
            'info',
            self::LOG_SOURCE,
            0,
            sprintf( 'Added %d items to queue. Total pending: %d', $added, $count_after )
        );
    }

    // ─────────────────────────────────────────────────────────────
    //  Process (called by WP-Cron)
    // ─────────────────────────────────────────────────────────────

    /**
     * Process the next batch of queued items.
     *
     * Logging:
     *  1. BATCH START — batch size, total in queue, lock acquired
     *  2. PER ITEM    — type, object_id, success/error
     *  3. BATCH END   — processed, errors, elapsed time, remaining
     */
    public function process(): void {
        // ── BATCH START ──────────────────────────────────────────
        if ( ! $this->acquire_lock() ) {
            Project_WP_Logger::write(
                'Queue process skipped: lock already held by another process.',
                'warning',
                self::LOG_SOURCE
            );
            return;
        }

        $batch_token = Project_WP_Logger::start( 'queue_batch' );

        try {
            $queue = $this->get_queue();

            if ( empty( $queue ) ) {
                Project_WP_Logger::write( 'Queue is empty. Unscheduling cron.', 'info', self::LOG_SOURCE );
                $this->unschedule_cron();
                return;
            }

            $total_in_queue = count( $queue );
            $batch          = array_splice( $queue, 0, $this->batch_size );
            $batch_count    = count( $batch );

            Project_WP_Logger::write(
                sprintf(
                    'BATCH START — Processing %d items (of %d total in queue). Batch size limit: %d.',
                    $batch_count,
                    $total_in_queue,
                    $this->batch_size
                ),
                'info',
                self::LOG_SOURCE
            );

            // ── PER ITEM ─────────────────────────────────────────
            $processor    = new Rental_Translation_Processor();
            $success_count = 0;
            $error_count   = 0;

            foreach ( $batch as $item_index => $item ) {
                $item_type = $item['type'] ?? 'unknown';
                $object_id = $item['object_id'] ?? 0;
                $item_label = sprintf(
                    '%s #%d (%s)',
                    $item_type,
                    $object_id,
                    $item_type === 'post' ? ( $item['post_type'] ?? 'unknown' ) : ( $item['taxonomy'] ?? 'unknown' )
                );

                $item_token = Project_WP_Logger::start( 'item_' . $object_id );

                try {
                    if ( 'post' === $item_type ) {
                        
                        $processor->translate_post( $object_id, $item['post_type'], $item['fields'] ?? [] );
                    } elseif ( 'term' === $item_type ) {

                        $processor->translate_term( $object_id, $item['taxonomy'], $item['fields'] ?? [] );
                    } else {

                        Project_WP_Logger::write(
                            sprintf( 'Unknown item type "%s" for object #%d — skipping.', $item_type, $object_id ),
                            'warning',
                            self::LOG_SOURCE
                        );

                        $error_count++;
                        continue;
                    }

                    $success_count++;
                    Project_WP_Logger::stop(
                        $item_token,
                        $item_label,
                        'debug',
                        self::LOG_SOURCE,
                        0,
                        'OK'
                    );

                } catch ( \Throwable $e ) {
                    $error_count++;
                    Project_WP_Logger::stop(
                        $item_token,
                        $item_label,
                        'error',
                        self::LOG_SOURCE,
                        0,
                        sprintf( 'FAILED: %s in %s:%d', $e->getMessage(), basename( $e->getFile() ), $e->getLine() )
                    );
                }
            }

            // ── BATCH END ────────────────────────────────────────
            // Save remaining queue.
            $this->save_queue( $queue );

            $remaining = count( $queue );

            if ( ! empty( $queue ) ) {
                $this->ensure_cron_scheduled();
            } else {
                $this->unschedule_cron();
            }

            Project_WP_Logger::stop(
                $batch_token,
                'queue_batch',
                'info',
                self::LOG_SOURCE,
                0,
                sprintf(
                    'BATCH END — Processed: %d, Errors: %d, Remaining in queue: %d. Cron %s.',
                    $success_count,
                    $error_count,
                    $remaining,
                    $remaining > 0 ? 'rescheduled' : 'unscheduled'
                )
            );

        } catch ( \Throwable $e ) {
            // Catch-all for unexpected errors in the batch orchestration itself.
            Project_WP_Logger::write(
                sprintf(
                    'BATCH FATAL ERROR: %s in %s:%d. Queue state may be inconsistent.',
                    $e->getMessage(),
                    basename( $e->getFile() ),
                    $e->getLine()
                ),
                'critical',
                self::LOG_SOURCE
            );
        } finally {
            $this->release_lock();
        }
    }

    // ─────────────────────────────────────────────────────────────
    //  Queue helpers
    // ─────────────────────────────────────────────────────────────

    /**
     * @param array $item Queue item data.
     */
    private function add_to_queue( array $item ): void {
        $queue   = $this->get_queue();
        $queue[] = $item;
        $this->save_queue( $queue );
        $this->ensure_cron_scheduled();
    }

    /**
     * @return array
     */
    private function get_queue(): array {
        $queue = get_option( self::QUEUE_OPTION, [] );
        return is_array( $queue ) ? $queue : [];
    }

    /**
     * @param array $queue
     */
    private function save_queue( array $queue ): void {
        update_option( self::QUEUE_OPTION, $queue, false );
    }

    /**
     * @return int
     */
    public function get_pending_count(): int {
        return count( $this->get_queue() );
    }

    // ─────────────────────────────────────────────────────────────
    //  Bulk enqueue helpers
    // ─────────────────────────────────────────────────────────────

    /**
     * @param string[] $post_types Post types to scan.
     */
    private function enqueue_untranslated_posts( array $post_types ): void {
        global $wpdb;

        foreach ( $post_types as $post_type ) {
            $offset = 0;
            $limit  = 200;

            do {
                $post_ids = $wpdb->get_col(
                    $wpdb->prepare(
                        "SELECT p.ID FROM {$wpdb->posts} p
                         WHERE p.post_type = %s
                           AND p.post_status IN ('publish','draft','private')
                         ORDER BY p.ID ASC
                         LIMIT %d OFFSET %d",
                        $post_type,
                        $limit,
                        $offset
                    )
                );

                if ( empty( $post_ids ) ) {
                    break;
                }

                foreach ( $post_ids as $post_id ) {
                    $post_id = (int) $post_id;

                    if ( ! $this->post_needs_translation( $post_id ) ) {
                        continue;
                    }

                    $post = get_post( $post_id );
                    if ( ! $post ) {
                        continue;
                    }

                    $this->enqueue_post( $post_id, $post_type, [
                        'title'   => $post->post_title,
                        'content' => $post->post_content,
                        'excerpt' => $post->post_excerpt,
                    ] );
                }

                $offset += $limit;
            } while ( count( $post_ids ) >= $limit );
        }
    }

    /**
     * @param string $taxonomy Taxonomy name.
     */
    private function enqueue_untranslated_terms( string $taxonomy ): void {
        $terms = get_terms( [
            'taxonomy'   => $taxonomy,
            'hide_empty' => false,
            'fields'     => 'all',
        ] );

        if ( is_wp_error( $terms ) || empty( $terms ) ) {
            return;
        }

        foreach ( $terms as $term ) {
            if ( ! $this->term_needs_translation( (int) $term->term_id ) ) {
                continue;
            }

            $this->enqueue_term( (int) $term->term_id, $taxonomy, [
                'name'        => $term->name,
                'description' => $term->description,
            ] );
        }
    }

    /**
     * @param int $post_id Post ID.
     * @return bool
     */
    private function post_needs_translation( int $post_id ): bool {
        if ( ! function_exists( 'pll_get_post_translations' ) || ! function_exists( 'pll_languages_list' ) ) {
            return false;
        }

        $translations    = pll_get_post_translations( $post_id );
        $all_languages   = pll_languages_list( [ 'hide_empty' => false ] );
        $default_lang    = pll_default_language( 'slug' );
        $secondary_langs = array_diff( $all_languages, [ $default_lang ] );

        foreach ( $secondary_langs as $lang ) {
            if ( ! isset( $translations[ $lang ] ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param int $term_id Term ID.
     * @return bool
     */
    private function term_needs_translation( int $term_id ): bool {
        if ( ! function_exists( 'pll_get_term_translations' ) || ! function_exists( 'pll_languages_list' ) ) {
            return false;
        }

        $translations    = pll_get_term_translations( $term_id );
        $all_languages   = pll_languages_list( [ 'hide_empty' => false ] );
        $default_lang    = pll_default_language( 'slug' );
        $secondary_langs = array_diff( $all_languages, [ $default_lang ] );

        foreach ( $secondary_langs as $lang ) {
            if ( ! isset( $translations[ $lang ] ) ) {
                return true;
            }
        }

        return false;
    }

    // ─────────────────────────────────────────────────────────────
    //  Cron management
    // ─────────────────────────────────────────────────────────────

    private function ensure_cron_scheduled(): void {
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time(), 'every_minute', self::CRON_HOOK );
        }
    }

    private function unschedule_cron(): void {
        $timestamp = wp_next_scheduled( self::CRON_HOOK );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, self::CRON_HOOK );
        }
    }

    /**
     * @param array $schedules Existing schedules.
     * @return array
     */
    public static function register_cron_interval( array $schedules ): array {
        if ( ! isset( $schedules['every_minute'] ) ) {
            $schedules['every_minute'] = [
                'interval' => 60,
                'display'  => __( 'Every Minute', 'rentopian-sync' ),
            ];
        }
        return $schedules;
    }

    // ─────────────────────────────────────────────────────────────
    //  Locking
    // ─────────────────────────────────────────────────────────────

    private function acquire_lock(): bool {
        $lock = get_option( self::LOCK_OPTION, 0 );
        if ( $lock > time() ) {
            return false;
        }
        update_option( self::LOCK_OPTION, time() + $this->lock_timeout, false );
        return true;
    }

    private function release_lock(): void {
        delete_option( self::LOCK_OPTION );
    }
}
