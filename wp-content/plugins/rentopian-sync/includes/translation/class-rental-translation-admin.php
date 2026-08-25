<?php
/**
 * Translation Settings — Embedded in Rentopian Settings Page
 *
 * Instead of a separate admin page, this class:
 *  1. Provides a static render method to output the Translation section HTML
 *     inside the existing rental_settings_page() General Settings tab.
 *  2. Registers AJAX handlers for saving settings, queueing, clearing, and
 *     manually processing the queue (for local/testing).
 *
 * @package RentopianSync\Translation
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Rental_Translation_Admin {

    /**
     * Option keys used by the translation module.
     */
    private const OPT_ENABLED  = 'rental_translation_enabled';
    private const OPT_PROVIDER = 'rental_translation_provider';
    private const OPT_API_KEY  = 'rental_translation_api_key';

    /**
     * Constructor — register AJAX handlers.
     */
    public function __construct() {
        // AJAX: save translation settings.
        add_action( 'wp_ajax_rental_save_translation_settings', [ $this, 'ajax_save_settings' ] );

        // AJAX: queue all items for translation.
        add_action( 'wp_ajax_rental_translation_queue_all', [ $this, 'ajax_queue_all' ] );

        // AJAX: clear the translation queue.
        add_action( 'wp_ajax_rental_translation_clear_queue', [ $this, 'ajax_clear_queue' ] );

        // AJAX: process queue now (for local testing / manual trigger).
        add_action( 'wp_ajax_rental_translation_process_now', [ $this, 'ajax_process_now' ] );

        // AJAX: clear translation cache.
        add_action( 'wp_ajax_rental_translation_clear_cache', [ $this, 'ajax_clear_cache' ] );

        // AJAX: refresh usage data.
        add_action( 'wp_ajax_rental_translation_refresh_usage', [ $this, 'ajax_refresh_usage' ] );

        // Hook into the existing settings section save to persist translation options.
        add_action( 'wp_ajax_rental_save_settings_section', [ $this, 'save_on_general_section' ], 5 );
    }

    // ─────────────────────────────────────────────────────────────
    //  Render — call from rental_settings_page()
    // ─────────────────────────────────────────────────────────────

    /**
     * Render the Translation settings section HTML.
     *
     * Call this inside rental_settings_page() within the General Settings tab,
     * inside the <form> tag, wrapped in the same pattern as other sections:
     *
     *   Rental_Translation_Admin::render_settings_section();
     */
    public static function render_settings_section(): void {
        $val_enabled  = get_option( self::OPT_ENABLED, '0' );
        $val_provider = get_option( self::OPT_PROVIDER, 'deepl' );
        $val_api_key  = get_option( self::OPT_API_KEY, '' );

        // Queue status.
        $queue         = Rental_Translation_Queue::get_instance();
        $pending_count = $queue->get_pending_count();
        $next_run      = wp_next_scheduled( Rental_Translation_Queue::CRON_HOOK );

        // Active languages.
        $active_langs = '';
        if ( function_exists( 'pll_languages_list' ) ) {
            $langs        = pll_languages_list( [ 'hide_empty' => false ] );
            $active_langs = implode( ', ', $langs );
        }
        ?>
        <div class="section-wrapper" id="rental-translation-settings-section">
            <div class="rental-inner-title">
                <h3><?php _e( 'Auto-Translation (Polylang)', 'rentopian-sync' ); ?></h3>
            </div>

            <!-- Enable Auto-Translation -->
            <div class="rental-form-group">
                <div class="rntp-sync-form-title">
                    <label for="rntp-checkbox-rental_translation_enabled" class="rental-label">
                        <?php _e( 'Enable Auto-Translation', 'rentopian-sync' ); ?>
                    </label>
                </div>
                <div class="rntp-sync-field">
                    <div class="rntp-checkbox inline">
                        <label>
                            <input id="rntp-checkbox-rental_translation_enabled"
                                   name="<?php echo esc_attr( self::OPT_ENABLED ); ?>"
                                   type="checkbox"
                                   value="1"
                                   <?php checked( $val_enabled, '1' ); ?> />
                            <span></span>
                        </label>
                    </div>
                </div>
                <div class="rntp-help-block">
                    <div class="rntp-help-block-content">
                        <?php _e( 'Automatically translate synced products, categories, tags, and attributes into all active Polylang languages.', 'rentopian-sync' ); ?>
                    </div>
                </div>
                <div class="clearfix"></div>
            </div>

            <!-- Translation Provider -->
            <div class="rental-form-group rental-translation-dependent" <?php echo $val_enabled !== '1' ? 'style="display:none"' : ''; ?>>
                <div class="rntp-sync-form-title">
                    <label class="rental-label">
                        <?php _e( 'Translation Provider', 'rentopian-sync' ); ?>
                    </label>
                </div>
                <div class="rntp-sync-field">
                    <div class="rntp-radio inline">
                        <input id="rntp-translation-provider-deepl"
                               name="<?php echo esc_attr( self::OPT_PROVIDER ); ?>"
                               type="radio"
                               value="deepl"
                               <?php checked( $val_provider, 'deepl' ); ?> />
                        <label for="rntp-translation-provider-deepl" class="radio-label">
                            DeepL (<?php _e( 'recommended — free 500K chars/month', 'rentopian-sync' ); ?>)
                        </label>
                    </div>
                    <div class="rntp-radio inline">
                        <input id="rntp-translation-provider-google"
                               name="<?php echo esc_attr( self::OPT_PROVIDER ); ?>"
                               type="radio"
                               value="google"
                               <?php checked( $val_provider, 'google' ); ?> />
                        <label for="rntp-translation-provider-google" class="radio-label">
                            Google Cloud Translation (<?php _e( 'free 500K chars/month', 'rentopian-sync' ); ?>)
                        </label>
                    </div>
                </div>
                <div class="rntp-help-block">
                    <div class="rntp-help-block-content">
                        <?php _e( 'DeepL provides higher-quality translations. Both offer a free tier of 500,000 characters per month.', 'rentopian-sync' ); ?>
                    </div>
                </div>
                <div class="clearfix"></div>
            </div>

            <!-- API Key -->
            <div class="rental-form-group rental-translation-dependent" <?php echo $val_enabled !== '1' ? 'style="display:none"' : ''; ?>>
                <div class="rntp-sync-form-title">
                    <label for="rntp-input-rental_translation_api_key" class="rental-label">
                        <?php _e( 'Translation API Key', 'rentopian-sync' ); ?>
                    </label>
                </div>
                <div class="rntp-sync-field">
                    <div class="rntp-input-text">
                        <input type="password"
                               id="rntp-input-rental_translation_api_key"
                               name="<?php echo esc_attr( self::OPT_API_KEY ); ?>"
                               value="<?php echo esc_attr( $val_api_key ); ?>"
                               autocomplete="off"
                               style="min-width: 350px;" />
                    </div>
                </div>
                <div class="rntp-help-block">
                    <div class="rntp-help-block-content">
                        <?php _e( 'DeepL: get a free key at', 'rentopian-sync' ); ?>
                        <a href="https://www.deepl.com/pro-api" target="_blank">deepl.com/pro-api</a> —
                        <?php _e( 'Google: create a key in', 'rentopian-sync' ); ?>
                        <a href="https://console.cloud.google.com/apis/credentials" target="_blank">Google Cloud Console</a>.
                    </div>
                </div>
                <div class="clearfix"></div>
            </div>

            <!-- Active Languages (info row) -->
            <div class="rental-form-group rental-translation-dependent" <?php echo $val_enabled !== '1' ? 'style="display:none"' : ''; ?>>
                <div class="rntp-sync-form-title">
                    <label class="rental-label">
                        <?php _e( 'Active Languages', 'rentopian-sync' ); ?>
                    </label>
                </div>
                <div class="rntp-sync-field">
                    <div style="padding: 6px 0;">
                        <?php if ( ! empty( $active_langs ) ) : ?>
                            <strong><?php echo esc_html( $active_langs ); ?></strong>
                        <?php else : ?>
                            <em><?php _e( 'Polylang is not active or no languages configured.', 'rentopian-sync' ); ?></em>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="rntp-help-block">
                    <div class="rntp-help-block-content">
                        <?php _e( 'Content will be translated from the default language into every other language listed above.', 'rentopian-sync' ); ?>
                    </div>
                </div>
                <div class="clearfix"></div>
            </div>

            <!-- Translation Queue Status & Action Buttons -->
            <div class="rental-form-group rental-translation-dependent" <?php echo $val_enabled !== '1' ? 'style="display:none"' : ''; ?>>
                <div class="rntp-sync-form-title">
                    <label class="rental-label">
                        <?php _e( 'Translation Queue', 'rentopian-sync' ); ?>
                    </label>
                </div>
                <div class="rntp-sync-field">
                    <div style="padding: 6px 0; margin-bottom: 10px;">
                        <strong id="rental-translation-pending-count"><?php echo esc_html( number_format_i18n( $pending_count ) ); ?></strong>
                        <?php _e( 'items pending', 'rentopian-sync' ); ?>
                        <?php if ( $pending_count > 0 ) : ?>
                            <span class="description" style="margin-left: 5px;">
                                (<?php _e( '~20 items/minute via WP-Cron', 'rentopian-sync' ); ?>)
                            </span>
                        <?php endif; ?>
                        <?php if ( $next_run ) : ?>
                            <br><small><?php _e( 'Next scheduled run:', 'rentopian-sync' ); ?> <?php echo esc_html( wp_date( 'Y-m-d H:i:s', $next_run ) ); ?></small>
                        <?php endif; ?>
                    </div>

                    <div style="display: flex; gap: 8px; flex-wrap: wrap; align-items: center;">
                        <button type="button" class="btn btn-sm btn-inline-success" id="rental-translation-queue-all">
                            <?php _e( 'Queue All for Translation', 'rentopian-sync' ); ?>
                        </button>

                        <button type="button" class="btn btn-sm btn-inline-success" id="rental-translation-process-now">
                            <?php _e( 'Process Queue Now', 'rentopian-sync' ); ?>
                        </button>

                        <button type="button" class="btn btn-sm" id="rental-translation-clear-queue"
                                style="<?php echo $pending_count === 0 ? 'display:none;' : ''; ?>">
                            <?php _e( 'Clear Queue', 'rentopian-sync' ); ?>
                        </button>

                        <span id="rental-translation-action-status" style="margin-left: 5px;"></span>
                    </div>
                </div>
                <div class="rntp-help-block">
                    <div class="rntp-help-block-content">
                        <?php _e( '"Queue All" scans for untranslated content and adds it to the background queue. "Process Now" manually triggers one batch — useful for local development where WP-Cron does not fire automatically.', 'rentopian-sync' ); ?>
                    </div>
                </div>
                <div class="clearfix"></div>
            </div>

            <!-- API Usage (real-time from DeepL) -->
            <div class="rental-form-group rental-translation-dependent" <?php echo $val_enabled !== '1' ? 'style="display:none"' : ''; ?>>
                <div class="rntp-sync-form-title">
                    <label class="rental-label">
                        <?php _e( 'API Usage (current billing period)', 'rentopian-sync' ); ?>
                    </label>
                </div>
                <div class="rntp-sync-field">
                    <?php
                    $usage = Rental_Translation_Usage::get_usage();
                    if ( $usage && null !== $usage['character_count'] ) :
                        $percent = $usage['percent_used'];
                        $bar_color = $percent > 90 ? '#dc3232' : ( $percent > 70 ? '#f0b849' : '#46b450' );
                    ?>
                        <div style="padding: 6px 0;">
                            <strong><?php echo esc_html( number_format_i18n( $usage['character_count'] ) ); ?></strong>
                            /
                            <?php if ( $usage['is_unlimited'] ) : ?>
                                <?php _e( 'Unlimited', 'rentopian-sync' ); ?>
                            <?php else : ?>
                                <strong><?php echo esc_html( number_format_i18n( $usage['character_limit'] ) ); ?></strong>
                            <?php endif; ?>
                            <?php _e( 'characters used', 'rentopian-sync' ); ?>
                            <?php if ( ! $usage['is_unlimited'] ) : ?>
                                <span style="margin-left: 8px;">(<?php echo esc_html( $percent ); ?>%)</span>
                            <?php endif; ?>
                        </div>

                        <?php if ( ! $usage['is_unlimited'] ) : ?>
                            <div style="background: #e0e0e0; border-radius: 4px; height: 14px; width: 100%; max-width: 400px; margin: 6px 0;">
                                <div style="background: <?php echo esc_attr( $bar_color ); ?>; height: 100%; border-radius: 4px; width: <?php echo min( 100, $percent ); ?>%; transition: width 0.3s;"></div>
                            </div>
                            <div style="padding: 2px 0;">
                                <small>
                                    <?php echo esc_html( number_format_i18n( $usage['characters_remaining'] ) ); ?>
                                    <?php _e( 'characters remaining', 'rentopian-sync' ); ?>
                                </small>
                            </div>
                        <?php endif; ?>

                        <?php if ( ! empty( $usage['billing_period_start'] ) && ! empty( $usage['billing_period_end'] ) ) : ?>
                            <div style="padding: 2px 0;">
                                <small>
                                    <?php _e( 'Billing period:', 'rentopian-sync' ); ?>
                                    <?php echo esc_html( wp_date( 'M j, Y', strtotime( $usage['billing_period_start'] ) ) ); ?>
                                    —
                                    <?php echo esc_html( wp_date( 'M j, Y', strtotime( $usage['billing_period_end'] ) ) ); ?>
                                </small>
                            </div>
                        <?php endif; ?>

                        <div style="padding: 2px 0;">
                            <small style="color: #999;">
                                <?php _e( 'Last checked:', 'rentopian-sync' ); ?> <?php echo esc_html( $usage['fetched_at'] ); ?>
                                —
                                <a href="#" id="rental-translation-refresh-usage" style="text-decoration: none;">
                                    <?php _e( 'Refresh', 'rentopian-sync' ); ?>
                                </a>
                            </small>
                        </div>

                    <?php elseif ( $usage && ! empty( $usage['note'] ) ) : ?>
                        <div style="padding: 6px 0;">
                            <em><?php echo esc_html( $usage['note'] ); ?></em>
                        </div>
                    <?php else : ?>
                        <div style="padding: 6px 0;">
                            <em><?php _e( 'Unable to fetch usage data. Check your API key.', 'rentopian-sync' ); ?></em>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="rntp-help-block">
                    <div class="rntp-help-block-content">
                        <?php _e( 'Real-time character usage from your translation provider. Data is cached for 5 minutes.', 'rentopian-sync' ); ?>
                    </div>
                </div>
                <div class="clearfix"></div>
            </div>

            <!-- Translation Cache Stats -->
            <?php
            $cache_stats = Rental_Translation_Cache::get_instance()->get_stats();
            ?>
            <div class="rental-form-group rental-translation-dependent" <?php echo $val_enabled !== '1' ? 'style="display:none"' : ''; ?>>
                <div class="rntp-sync-form-title">
                    <label class="rental-label">
                        <?php _e( 'Translation Cache', 'rentopian-sync' ); ?>
                    </label>
                </div>
                <div class="rntp-sync-field">
                    <div style="padding: 6px 0;">
                        <strong><?php echo esc_html( number_format_i18n( $cache_stats['total'] ) ); ?></strong>
                        <?php _e( 'cached translations', 'rentopian-sync' ); ?>

                        <?php if ( $cache_stats['manual_count'] > 0 ) : ?>
                            —
                            <span style="display: inline-block; background: #0073aa; color: #fff; border-radius: 3px; padding: 1px 8px; font-size: 12px; font-weight: 600;">
                                <?php echo esc_html( number_format_i18n( $cache_stats['manual_count'] ) ); ?>
                                <?php _e( 'manual corrections', 'rentopian-sync' ); ?>
                            </span>
                        <?php endif; ?>

                        <?php if ( $cache_stats['total_chars_saved'] > 0 ) : ?>
                            —
                            <span style="color: #46b450;">
                                <?php echo esc_html( number_format_i18n( $cache_stats['total_chars_saved'] ) ); ?>
                                <?php _e( 'API characters saved', 'rentopian-sync' ); ?>
                            </span>
                        <?php endif; ?>
                    </div>

                    <?php if ( ! empty( $cache_stats['by_lang'] ) ) : ?>
                        <div style="padding: 2px 0;">
                            <small>
                                <?php
                                $lang_parts = [];
                                foreach ( $cache_stats['by_lang'] as $lang => $cnt ) {
                                    $lang_parts[] = strtoupper( $lang ) . ': ' . number_format_i18n( $cnt );
                                }
                                echo esc_html( implode( ' · ', $lang_parts ) );
                                ?>
                            </small>
                        </div>
                    <?php endif; ?>

                    <div style="margin-top: 10px; display: flex; gap: 8px; flex-wrap: wrap; align-items: center;">
                        <?php if ( class_exists( 'Rental_Translation_Overrides_Page' ) ) : ?>
                            <a href="<?php echo esc_url( Rental_Translation_Overrides_Page::get_page_url() ); ?>"
                               class="btn btn-sm btn-inline-success">
                                <?php _e( 'Manage Translation Overrides', 'rentopian-sync' ); ?>
                            </a>
                        <?php endif; ?>

                        <?php if ( $cache_stats['total'] > 0 ) : ?>
                            <?php
                            $clear_msg = $cache_stats['manual_count'] > 0
                                ? sprintf(
                                    __( 'WARNING: This will delete ALL cached translations including %d manual corrections. Auto-translations only can be cleared from the Overrides page. Continue?', 'rentopian-sync' ),
                                    $cache_stats['manual_count']
                                )
                                : __( 'Clear the translation cache? Next sync will re-translate everything using API quota.', 'rentopian-sync' );
                            ?>
                            <button type="button" class="btn btn-sm" id="rental-translation-clear-cache"
                                    onclick="if(confirm('<?php echo esc_attr( $clear_msg ); ?>')) rentalTranslationAction('rental_translation_clear_cache', this)">
                                <?php _e( 'Clear Entire Cache', 'rentopian-sync' ); ?>
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="rntp-help-block">
                    <div class="rntp-help-block-content">
                        <?php _e( 'Cached translations are reused on re-syncs to save API quota. Use "Manage Translation Overrides" to correct industry-specific terms — manual corrections are protected from being overwritten.', 'rentopian-sync' ); ?>
                    </div>
                </div>
                <div class="clearfix"></div>
            </div>
        </div>

        <script>
        (function(){
            // Toggle dependent fields visibility.
            var chk = document.getElementById('rntp-checkbox-rental_translation_enabled');
            if (chk) {
                chk.addEventListener('change', function(){
                    var deps = document.querySelectorAll('.rental-translation-dependent');
                    for (var i = 0; i < deps.length; i++) {
                        deps[i].style.display = this.checked ? '' : 'none';
                    }
                });
            }

            // Action button handlers.
            var nonce = '<?php echo wp_create_nonce( 'rental_translation_actions' ); ?>';

            document.getElementById('rental-translation-queue-all').addEventListener('click', function(){
                rentalTranslationAction('rental_translation_queue_all', this);
            });

            document.getElementById('rental-translation-process-now').addEventListener('click', function(){
                rentalTranslationAction('rental_translation_process_now', this);
            });

            document.getElementById('rental-translation-clear-queue').addEventListener('click', function(){
                if (confirm('<?php esc_attr_e( 'Clear all pending translations?', 'rentopian-sync' ); ?>')) {
                    rentalTranslationAction('rental_translation_clear_queue', this);
                }
            });

            // Usage refresh link.
            var refreshLink = document.getElementById('rental-translation-refresh-usage');
            if (refreshLink) {
                refreshLink.addEventListener('click', function(e){
                    e.preventDefault();
                    this.textContent = '<?php esc_attr_e( 'Refreshing...', 'rentopian-sync' ); ?>';
                    var link = this;
                    var data = new FormData();
                    data.append('action', 'rental_translation_refresh_usage');
                    data.append('_wpnonce', nonce);
                    fetch(ajaxurl, { method: 'POST', body: data })
                        .then(function(r){ return r.json(); })
                        .then(function(resp){
                            link.textContent = '<?php esc_attr_e( 'Refresh', 'rentopian-sync' ); ?>';
                            if (resp.success) {
                                // Reload to show updated stats.
                                location.reload();
                            }
                        })
                        .catch(function(){
                            link.textContent = '<?php esc_attr_e( 'Refresh', 'rentopian-sync' ); ?>';
                        });
                });
            }

            function rentalTranslationAction(action, btn) {
                var statusEl     = document.getElementById('rental-translation-action-status');
                var originalText = btn.textContent;
                btn.disabled     = true;
                btn.textContent  = '<?php esc_attr_e( 'Processing...', 'rentopian-sync' ); ?>';
                statusEl.textContent = '';

                var data = new FormData();
                data.append('action', action);
                data.append('_wpnonce', nonce);

                fetch(ajaxurl, { method: 'POST', body: data })
                    .then(function(r){ return r.json(); })
                    .then(function(resp){
                        btn.disabled    = false;
                        btn.textContent = originalText;
                        if (resp.success) {
                            statusEl.style.color = 'green';
                            statusEl.textContent = resp.data.message || '<?php esc_attr_e( 'Done!', 'rentopian-sync' ); ?>';
                            if (resp.data.pending !== undefined) {
                                var counter = document.getElementById('rental-translation-pending-count');
                                if (counter) counter.textContent = resp.data.pending;
                                // Show/hide clear button.
                                var clearBtn = document.getElementById('rental-translation-clear-queue');
                                if (clearBtn) clearBtn.style.display = resp.data.pending > 0 ? '' : 'none';
                            }
                        } else {
                            statusEl.style.color = 'red';
                            statusEl.textContent = resp.data || '<?php esc_attr_e( 'Error occurred.', 'rentopian-sync' ); ?>';
                        }
                    })
                    .catch(function(){
                        btn.disabled    = false;
                        btn.textContent = originalText;
                        statusEl.style.color = 'red';
                        statusEl.textContent = '<?php esc_attr_e( 'Network error.', 'rentopian-sync' ); ?>';
                    });
            }
        })();
        </script>
        <?php
    }

    // ─────────────────────────────────────────────────────────────
    //  Save hook — piggyback on existing General settings AJAX save
    // ─────────────────────────────────────────────────────────────

    /**
     * When the General settings section is saved via AJAX, also persist
     * translation options that are present in the same form.
     *
     * Hooked at priority 5 so it runs before the main handler.
     */
    public function save_on_general_section(): void {
        // Only act when the "general" section is being saved.
        if ( ! isset( $_POST['section'] ) || $_POST['section'] !== 'general' ) {
            return;
        }

        $enabled  = ! empty( $_POST[ self::OPT_ENABLED ] ) ? '1' : '0';
        $provider = isset( $_POST[ self::OPT_PROVIDER ] ) ? sanitize_text_field( $_POST[ self::OPT_PROVIDER ] ) : 'deepl';
        $api_key  = isset( $_POST[ self::OPT_API_KEY ] ) ? sanitize_text_field( $_POST[ self::OPT_API_KEY ] ) : '';

        update_option( self::OPT_ENABLED, $enabled );
        update_option( self::OPT_PROVIDER, $provider );
        update_option( self::OPT_API_KEY, $api_key );

        Rental_Translation_Factory::reset();
    }

    // ─────────────────────────────────────────────────────────────
    //  Standalone AJAX Handlers
    // ─────────────────────────────────────────────────────────────

    /**
     * AJAX: Save translation settings independently.
     */
    public function ajax_save_settings(): void {
        check_ajax_referer( 'rental_save_translation_settings' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $enabled  = isset( $_POST[ self::OPT_ENABLED ] ) ? sanitize_text_field( $_POST[ self::OPT_ENABLED ] ) : '0';
        $provider = isset( $_POST[ self::OPT_PROVIDER ] ) ? sanitize_text_field( $_POST[ self::OPT_PROVIDER ] ) : 'deepl';
        $api_key  = isset( $_POST[ self::OPT_API_KEY ] ) ? sanitize_text_field( $_POST[ self::OPT_API_KEY ] ) : '';

        update_option( self::OPT_ENABLED, $enabled );
        update_option( self::OPT_PROVIDER, $provider );
        update_option( self::OPT_API_KEY, $api_key );

        Rental_Translation_Factory::reset();

        wp_send_json_success( [ 'message' => __( 'Translation settings saved.', 'rentopian-sync' ) ] );
    }

    /**
     * AJAX: Queue all untranslated items.
     */
    public function ajax_queue_all(): void {
        check_ajax_referer( 'rental_translation_actions' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $queue = Rental_Translation_Queue::get_instance();
        $queue->enqueue_bulk_sync_items();

        wp_send_json_success( [
            'message' => sprintf(
                __( '%d items queued for translation.', 'rentopian-sync' ),
                $queue->get_pending_count()
            ),
            'pending' => $queue->get_pending_count(),
        ] );
    }

    /**
     * AJAX: Clear the queue.
     */
    public function ajax_clear_queue(): void {
        check_ajax_referer( 'rental_translation_actions' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        update_option( 'rental_translation_queue', [], false );

        wp_send_json_success( [
            'message' => __( 'Queue cleared.', 'rentopian-sync' ),
            'pending' => 0,
        ] );
    }

    /**
     * AJAX: Process queue now (manual trigger for local dev / testing).
     *
     * Processes one batch immediately without waiting for WP-Cron.
     */
    public function ajax_process_now(): void {
        check_ajax_referer( 'rental_translation_actions' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $queue        = Rental_Translation_Queue::get_instance();
        $before_count = $queue->get_pending_count();

        if ( $before_count === 0 ) {
            wp_send_json_success( [
                'message' => __( 'Queue is empty — nothing to process.', 'rentopian-sync' ),
                'pending' => 0,
            ] );
        }

        // Process one batch synchronously.
        $queue->process();

        $after_count = $queue->get_pending_count();
        $processed   = $before_count - $after_count;

        wp_send_json_success( [
            'message' => sprintf(
                __( 'Processed %d items. %d remaining.', 'rentopian-sync' ),
                $processed,
                $after_count
            ),
            'pending' => $after_count,
        ] );
    }

    /**
     * AJAX: Clear the translation cache.
     */
    public function ajax_clear_cache(): void {
        check_ajax_referer( 'rental_translation_actions' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        Rental_Translation_Cache::get_instance()->clear_all();

        wp_send_json_success( [
            'message' => __( 'Translation cache cleared.', 'rentopian-sync' ),
        ] );
    }

    /**
     * AJAX: Refresh usage data from the translation API.
     */
    public function ajax_refresh_usage(): void {
        check_ajax_referer( 'rental_translation_actions' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $usage = Rental_Translation_Usage::get_usage( true );

        if ( null === $usage ) {
            wp_send_json_error( __( 'Failed to fetch usage data.', 'rentopian-sync' ) );
        }

        wp_send_json_success( [
            'message' => __( 'Usage data refreshed.', 'rentopian-sync' ),
            'usage'   => $usage,
        ] );
    }
}
