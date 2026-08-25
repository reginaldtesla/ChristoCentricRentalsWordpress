<?php
/**
 * Translation Overrides Admin Page
 *
 * Provides a separate admin page under the Rentopian Sync menu for viewing
 * and editing cached translations. Allows clients to correct industry-specific
 * terms that auto-translation gets wrong.
 *
 * Features:
 *  - Paginated table (25 per page, server-side)
 *  - Search (by source text or translated text)
 *  - Filter by language and by type (all / manual / auto)
 *  - Inline editing of translations (AJAX save)
 *  - Manual corrections are marked is_manual=1 and protected from auto-overwrite
 *  - Revert-to-auto button to undo manual corrections
 *  - Visual indicators: manual corrections shown with a badge
 *  - Only visible when translation module is active
 *
 * @package RentopianSync\Translation
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Rental_Translation_Overrides_Page {

    /**
     * Page slug.
     *
     * @var string
     */
    private const PAGE_SLUG = 'rentopian-translation-overrides';

    /**
     * Items per page.
     *
     * @var int
     */
    private const PER_PAGE = 25;

    /**
     * Constructor — register hooks.
     */
    public function __construct() {
        add_action( 'admin_menu', [ $this, 'register_page' ], 20 );

        // AJAX: save edited translation.
        add_action( 'wp_ajax_rental_translation_save_override', [ $this, 'ajax_save_override' ] );

        // AJAX: revert to auto translation.
        add_action( 'wp_ajax_rental_translation_revert_auto', [ $this, 'ajax_revert_auto' ] );

        // AJAX: load table rows (for pagination/search without full page reload).
        add_action( 'wp_ajax_rental_translation_load_overrides', [ $this, 'ajax_load_table' ] );
    }

    /**
     * Register the submenu page only when translation is enabled.
     */
    public function register_page(): void {
        if ( '1' !== get_option( 'rental_translation_enabled', '0' ) ) {
            return;
        }

        if ( ! function_exists( 'pll_languages_list' ) ) {
            return;
        }

        $parent_slug = function_exists( 'rental_get_admin_menu_slug' )
            ? rental_get_admin_menu_slug()
            : __FILE__;

        add_submenu_page(
            $parent_slug,
            __( 'Translation Overrides', 'rentopian-sync' ),
            __( 'Translation Overrides', 'rentopian-sync' ),
            'import',
            self::PAGE_SLUG,
            [ $this, 'render_page' ]
        );
    }

    /**
     * Get the admin page URL (for linking from settings).
     *
     * @return string
     */
    public static function get_page_url(): string {
        return admin_url( 'admin.php?page=' . self::PAGE_SLUG );
    }

    /**
     * Render the full admin page.
     */
    public function render_page(): void {
        $cache = Rental_Translation_Cache::get_instance();
        $stats = $cache->get_stats();

        // Current filters from URL.
        $current_page = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
        $search       = sanitize_text_field( $_GET['s'] ?? '' );
        $lang_filter  = sanitize_text_field( $_GET['lang'] ?? '' );
        $type_filter  = sanitize_text_field( $_GET['type'] ?? '' );

        // Fetch data.
        $result = $cache->get_paginated( $current_page, self::PER_PAGE, $search, $lang_filter, $type_filter );

        // Available languages for filter dropdown.
        $languages = function_exists( 'pll_languages_list' )
            ? pll_languages_list( [ 'hide_empty' => false ] )
            : [];
        $default_lang = function_exists( 'pll_default_language' ) ? pll_default_language( 'slug' ) : 'en';
        $secondary    = array_values( array_diff( $languages, [ $default_lang ] ) );

        $nonce = wp_create_nonce( 'rental_translation_overrides' );
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e( 'Translation Overrides', 'rentopian-sync' ); ?></h1>

            <p class="description" style="margin-top: 5px;">
                <?php esc_html_e( 'Edit cached translations below. Manual corrections are protected from being overwritten by auto-translation during re-syncs.', 'rentopian-sync' ); ?>
            </p>

            <!-- Stats bar -->
            <div style="background: #f8f9fa; border: 1px solid #e2e4e7; border-radius: 4px; padding: 12px 16px; margin: 15px 0; display: flex; gap: 24px; flex-wrap: wrap; align-items: center;">
                <div>
                    <strong><?php echo esc_html( number_format_i18n( $stats['total'] ) ); ?></strong>
                    <?php esc_html_e( 'total translations', 'rentopian-sync' ); ?>
                </div>
                <div>
                    <span style="display: inline-block; background: #0073aa; color: #fff; border-radius: 3px; padding: 1px 8px; font-size: 12px; font-weight: 600;">
                        <?php echo esc_html( number_format_i18n( $stats['manual_count'] ) ); ?>
                    </span>
                    <?php esc_html_e( 'manual corrections', 'rentopian-sync' ); ?>
                </div>
                <div style="color: #46b450;">
                    <strong><?php echo esc_html( number_format_i18n( $stats['total_chars_saved'] ) ); ?></strong>
                    <?php esc_html_e( 'API characters saved', 'rentopian-sync' ); ?>
                </div>
            </div>

            <!-- Filters -->
            <form method="get" style="margin-bottom: 15px; display: flex; gap: 8px; flex-wrap: wrap; align-items: center;">
                <input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>">

                <input type="search"
                       name="s"
                       value="<?php echo esc_attr( $search ); ?>"
                       placeholder="<?php esc_attr_e( 'Search source or translation...', 'rentopian-sync' ); ?>"
                       style="min-width: 280px;" />

                <select name="lang">
                    <option value=""><?php esc_html_e( 'All languages', 'rentopian-sync' ); ?></option>
                    <?php foreach ( $secondary as $lang ) : ?>
                        <option value="<?php echo esc_attr( $lang ); ?>" <?php selected( $lang_filter, $lang ); ?>>
                            <?php echo esc_html( strtoupper( $lang ) ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <select name="type">
                    <option value="" <?php selected( $type_filter, '' ); ?>><?php esc_html_e( 'All types', 'rentopian-sync' ); ?></option>
                    <option value="manual" <?php selected( $type_filter, 'manual' ); ?>><?php esc_html_e( 'Manual corrections only', 'rentopian-sync' ); ?></option>
                    <option value="auto" <?php selected( $type_filter, 'auto' ); ?>><?php esc_html_e( 'Auto-translated only', 'rentopian-sync' ); ?></option>
                </select>

                <button type="submit" class="button"><?php esc_html_e( 'Filter', 'rentopian-sync' ); ?></button>

                <?php if ( ! empty( $search ) || ! empty( $lang_filter ) || ! empty( $type_filter ) ) : ?>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ); ?>" class="button" style="margin-left: 4px;">
                        <?php esc_html_e( 'Clear filters', 'rentopian-sync' ); ?>
                    </a>
                <?php endif; ?>
            </form>

            <!-- Results info -->
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                <span class="displaying-num">
                    <?php
                    printf(
                        esc_html__( 'Showing %1$d–%2$d of %3$d', 'rentopian-sync' ),
                        $result['total'] > 0 ? ( ( $current_page - 1 ) * self::PER_PAGE + 1 ) : 0,
                        min( $current_page * self::PER_PAGE, $result['total'] ),
                        $result['total']
                    );
                    ?>
                </span>

                <span id="rental-override-status" style="font-weight: 500;"></span>
            </div>

            <!-- Table -->
            <table class="wp-list-table widefat fixed striped" id="rental-overrides-table">
                <thead>
                    <tr>
                        <th style="width: 30%;"><?php esc_html_e( 'Source text (English)', 'rentopian-sync' ); ?></th>
                        <th style="width: 30%;"><?php esc_html_e( 'Translation', 'rentopian-sync' ); ?></th>
                        <th style="width: 6%;"><?php esc_html_e( 'Lang', 'rentopian-sync' ); ?></th>
                        <th style="width: 8%;"><?php esc_html_e( 'Type', 'rentopian-sync' ); ?></th>
                        <th style="width: 10%;"><?php esc_html_e( 'Provider', 'rentopian-sync' ); ?></th>
                        <th style="width: 16%;"><?php esc_html_e( 'Actions', 'rentopian-sync' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $result['items'] ) ) : ?>
                        <tr>
                            <td colspan="6" style="text-align: center; padding: 20px;">
                                <?php esc_html_e( 'No translations found.', 'rentopian-sync' ); ?>
                            </td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ( $result['items'] as $row ) : ?>
                            <?php $is_manual = (int) $row->is_manual === 1; ?>
                            <tr data-row-id="<?php echo esc_attr( $row->id ); ?>"
                                style="<?php echo $is_manual ? 'background: #f0f6fc;' : ''; ?>">
                                <td>
                                    <div style="max-height: 80px; overflow-y: auto; word-break: break-word; font-size: 13px;">
                                        <?php echo esc_html( mb_substr( $row->source_text, 0, 300, 'UTF-8' ) ); ?>
                                        <?php if ( mb_strlen( $row->source_text, 'UTF-8' ) > 300 ) echo '…'; ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="rental-override-display" data-row-id="<?php echo esc_attr( $row->id ); ?>"
                                         style="max-height: 80px; overflow-y: auto; word-break: break-word; font-size: 13px; cursor: pointer;"
                                         title="<?php esc_attr_e( 'Click to edit', 'rentopian-sync' ); ?>">
                                        <?php echo esc_html( mb_substr( $row->translated_text, 0, 300, 'UTF-8' ) ); ?>
                                        <?php if ( mb_strlen( $row->translated_text, 'UTF-8' ) > 300 ) echo '…'; ?>
                                    </div>
                                    <div class="rental-override-edit" data-row-id="<?php echo esc_attr( $row->id ); ?>" style="display: none;">
                                        <textarea rows="3" style="width: 100%; font-size: 13px;"
                                                  class="rental-override-textarea"><?php echo esc_textarea( $row->translated_text ); ?></textarea>
                                        <div style="margin-top: 4px; display: flex; gap: 4px;">
                                            <button type="button" class="button button-primary button-small rental-override-save-btn"
                                                    data-row-id="<?php echo esc_attr( $row->id ); ?>">
                                                <?php esc_html_e( 'Save', 'rentopian-sync' ); ?>
                                            </button>
                                            <button type="button" class="button button-small rental-override-cancel-btn"
                                                    data-row-id="<?php echo esc_attr( $row->id ); ?>">
                                                <?php esc_html_e( 'Cancel', 'rentopian-sync' ); ?>
                                            </button>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <strong><?php echo esc_html( strtoupper( $row->target_lang ) ); ?></strong>
                                </td>
                                <td>
                                    <?php if ( $is_manual ) : ?>
                                        <span style="display: inline-block; background: #0073aa; color: #fff; border-radius: 3px; padding: 1px 8px; font-size: 11px; font-weight: 600;">
                                            <?php esc_html_e( 'Manual', 'rentopian-sync' ); ?>
                                        </span>
                                    <?php else : ?>
                                        <span style="display: inline-block; background: #e2e4e7; color: #555; border-radius: 3px; padding: 1px 8px; font-size: 11px;">
                                            <?php esc_html_e( 'Auto', 'rentopian-sync' ); ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td style="font-size: 12px; color: #666;">
                                    <?php echo esc_html( ucfirst( $row->provider ) ); ?>
                                    <br>
                                    <small><?php echo esc_html( wp_date( 'M j, Y', strtotime( $row->updated_at ) ) ); ?></small>
                                </td>
                                <td>
                                    <button type="button" class="button button-small rental-override-edit-btn"
                                            data-row-id="<?php echo esc_attr( $row->id ); ?>">
                                        <?php esc_html_e( 'Edit', 'rentopian-sync' ); ?>
                                    </button>
                                    <?php if ( $is_manual ) : ?>
                                        <button type="button" class="button button-small rental-override-revert-btn"
                                                data-row-id="<?php echo esc_attr( $row->id ); ?>"
                                                title="<?php esc_attr_e( 'Remove manual override. Next sync will auto-translate this text again.', 'rentopian-sync' ); ?>">
                                            <?php esc_html_e( 'Revert', 'rentopian-sync' ); ?>
                                        </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <!-- Pagination -->
            <?php if ( $result['total_pages'] > 1 ) : ?>
                <div class="tablenav bottom" style="margin-top: 10px;">
                    <div class="tablenav-pages">
                        <span class="displaying-num">
                            <?php printf( esc_html__( '%d items', 'rentopian-sync' ), $result['total'] ); ?>
                        </span>
                        <span class="pagination-links">
                            <?php
                            $base_url = add_query_arg( [
                                'page' => self::PAGE_SLUG,
                                's'    => $search,
                                'lang' => $lang_filter,
                                'type' => $type_filter,
                            ], admin_url( 'admin.php' ) );

                            // First page.
                            if ( $current_page > 1 ) {
                                printf(
                                    '<a class="first-page button" href="%s">«</a> ',
                                    esc_url( add_query_arg( 'paged', 1, $base_url ) )
                                );
                                printf(
                                    '<a class="prev-page button" href="%s">‹</a> ',
                                    esc_url( add_query_arg( 'paged', $current_page - 1, $base_url ) )
                                );
                            } else {
                                echo '<span class="tablenav-pages-navspan button disabled">«</span> ';
                                echo '<span class="tablenav-pages-navspan button disabled">‹</span> ';
                            }

                            printf(
                                '<span class="paging-input">%d / %d</span>',
                                $current_page,
                                $result['total_pages']
                            );

                            // Next / Last.
                            if ( $current_page < $result['total_pages'] ) {
                                printf(
                                    ' <a class="next-page button" href="%s">›</a>',
                                    esc_url( add_query_arg( 'paged', $current_page + 1, $base_url ) )
                                );
                                printf(
                                    ' <a class="last-page button" href="%s">»</a>',
                                    esc_url( add_query_arg( 'paged', $result['total_pages'], $base_url ) )
                                );
                            } else {
                                echo ' <span class="tablenav-pages-navspan button disabled">›</span>';
                                echo ' <span class="tablenav-pages-navspan button disabled">»</span>';
                            }
                            ?>
                        </span>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <script>
        (function() {
            var nonce = '<?php echo esc_js( $nonce ); ?>';
            var statusEl = document.getElementById('rental-override-status');

            function showStatus(msg, color) {
                statusEl.style.color = color || '#333';
                statusEl.textContent = msg;
                setTimeout(function(){ statusEl.textContent = ''; }, 4000);
            }

            // Edit button → show textarea.
            document.querySelectorAll('.rental-override-edit-btn').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var id = this.dataset.rowId;
                    document.querySelector('.rental-override-display[data-row-id="'+id+'"]').style.display = 'none';
                    document.querySelector('.rental-override-edit[data-row-id="'+id+'"]').style.display = '';
                    document.querySelector('.rental-override-edit[data-row-id="'+id+'"] textarea').focus();
                });
            });

            // Click on translation text → open edit.
            document.querySelectorAll('.rental-override-display').forEach(function(el) {
                el.addEventListener('click', function() {
                    var id = this.dataset.rowId;
                    var editBtn = document.querySelector('.rental-override-edit-btn[data-row-id="'+id+'"]');
                    if (editBtn) editBtn.click();
                });
            });

            // Cancel button → hide textarea.
            document.querySelectorAll('.rental-override-cancel-btn').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var id = this.dataset.rowId;
                    document.querySelector('.rental-override-display[data-row-id="'+id+'"]').style.display = '';
                    document.querySelector('.rental-override-edit[data-row-id="'+id+'"]').style.display = 'none';
                });
            });

            // Save button → AJAX.
            document.querySelectorAll('.rental-override-save-btn').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var id = this.dataset.rowId;
                    var textarea = document.querySelector('.rental-override-edit[data-row-id="'+id+'"] textarea');
                    var newText = textarea.value.trim();

                    if (!newText) {
                        showStatus('<?php echo esc_js( __( 'Translation cannot be empty.', 'rentopian-sync' ) ); ?>', 'red');
                        return;
                    }

                    btn.disabled = true;
                    btn.textContent = '<?php echo esc_js( __( 'Saving...', 'rentopian-sync' ) ); ?>';

                    var data = new FormData();
                    data.append('action', 'rental_translation_save_override');
                    data.append('_wpnonce', nonce);
                    data.append('row_id', id);
                    data.append('translated_text', newText);

                    fetch(ajaxurl, { method: 'POST', body: data })
                        .then(function(r) { return r.json(); })
                        .then(function(resp) {
                            btn.disabled = false;
                            btn.textContent = '<?php echo esc_js( __( 'Save', 'rentopian-sync' ) ); ?>';

                            if (resp.success) {
                                // Update display text.
                                var display = document.querySelector('.rental-override-display[data-row-id="'+id+'"]');
                                display.textContent = newText.substring(0, 300) + (newText.length > 300 ? '…' : '');
                                display.style.display = '';
                                document.querySelector('.rental-override-edit[data-row-id="'+id+'"]').style.display = 'none';

                                // Update type badge to Manual.
                                var row = document.querySelector('tr[data-row-id="'+id+'"]');
                                if (row) {
                                    row.style.background = '#f0f6fc';
                                    var typeCell = row.querySelectorAll('td')[3];
                                    typeCell.innerHTML = '<span style="display:inline-block;background:#0073aa;color:#fff;border-radius:3px;padding:1px 8px;font-size:11px;font-weight:600;"><?php echo esc_js( __( 'Manual', 'rentopian-sync' ) ); ?></span>';
                                    // Add revert button if not present.
                                    var actionsCell = row.querySelectorAll('td')[5];
                                    if (!actionsCell.querySelector('.rental-override-revert-btn')) {
                                        var revertBtn = document.createElement('button');
                                        revertBtn.type = 'button';
                                        revertBtn.className = 'button button-small rental-override-revert-btn';
                                        revertBtn.dataset.rowId = id;
                                        revertBtn.textContent = '<?php echo esc_js( __( 'Revert', 'rentopian-sync' ) ); ?>';
                                        revertBtn.addEventListener('click', revertHandler);
                                        actionsCell.appendChild(document.createTextNode(' '));
                                        actionsCell.appendChild(revertBtn);
                                    }
                                }

                                showStatus('<?php echo esc_js( __( 'Saved! This translation is now protected from auto-overwrite.', 'rentopian-sync' ) ); ?>', '#46b450');
                            } else {
                                showStatus(resp.data || '<?php echo esc_js( __( 'Error saving.', 'rentopian-sync' ) ); ?>', 'red');
                            }
                        })
                        .catch(function() {
                            btn.disabled = false;
                            btn.textContent = '<?php echo esc_js( __( 'Save', 'rentopian-sync' ) ); ?>';
                            showStatus('<?php echo esc_js( __( 'Network error.', 'rentopian-sync' ) ); ?>', 'red');
                        });
                });
            });

            // Revert handler.
            function revertHandler() {
                var id = this.dataset.rowId;
                if (!confirm('<?php echo esc_js( __( 'Revert to auto-translation? The next sync will overwrite this with a fresh translation from the API.', 'rentopian-sync' ) ); ?>')) {
                    return;
                }

                var btn = this;
                btn.disabled = true;

                var data = new FormData();
                data.append('action', 'rental_translation_revert_auto');
                data.append('_wpnonce', nonce);
                data.append('row_id', id);

                fetch(ajaxurl, { method: 'POST', body: data })
                    .then(function(r) { return r.json(); })
                    .then(function(resp) {
                        btn.disabled = false;
                        if (resp.success) {
                            var row = document.querySelector('tr[data-row-id="'+id+'"]');
                            if (row) {
                                row.style.background = '';
                                var typeCell = row.querySelectorAll('td')[3];
                                typeCell.innerHTML = '<span style="display:inline-block;background:#e2e4e7;color:#555;border-radius:3px;padding:1px 8px;font-size:11px;"><?php echo esc_js( __( 'Auto', 'rentopian-sync' ) ); ?></span>';
                                btn.remove();
                            }
                            showStatus('<?php echo esc_js( __( 'Reverted to auto. Next sync will re-translate this.', 'rentopian-sync' ) ); ?>', '#0073aa');
                        } else {
                            showStatus(resp.data || '<?php echo esc_js( __( 'Error reverting.', 'rentopian-sync' ) ); ?>', 'red');
                        }
                    });
            }

            document.querySelectorAll('.rental-override-revert-btn').forEach(function(btn) {
                btn.addEventListener('click', revertHandler);
            });
        })();
        </script>

        <style>
            #rental-overrides-table .rental-override-display:hover {
                background: #f0f0f0;
                border-radius: 3px;
            }
            #rental-overrides-table textarea {
                resize: vertical;
            }
        </style>
        <?php
    }

    // ─────────────────────────────────────────────────────────────
    //  AJAX Handlers
    // ─────────────────────────────────────────────────────────────

    /**
     * AJAX: Save a manual translation override.
     */
    public function ajax_save_override(): void {
        check_ajax_referer( 'rental_translation_overrides' );

        if ( ! current_user_can( 'import' ) ) {
            wp_send_json_error( __( 'Unauthorized.', 'rentopian-sync' ) );
        }

        $row_id          = (int) ( $_POST['row_id'] ?? 0 );
        $translated_text = sanitize_textarea_field( $_POST['translated_text'] ?? '' );

        if ( $row_id <= 0 ) {
            wp_send_json_error( __( 'Invalid row ID.', 'rentopian-sync' ) );
        }

        if ( empty( trim( $translated_text ) ) ) {
            wp_send_json_error( __( 'Translation cannot be empty.', 'rentopian-sync' ) );
        }

        $cache = Rental_Translation_Cache::get_instance();

        // Verify row exists.
        $row = $cache->get_row( $row_id );
        if ( ! $row ) {
            wp_send_json_error( __( 'Translation entry not found.', 'rentopian-sync' ) );
        }

        $success = $cache->update_translation( $row_id, $translated_text );

        if ( $success ) {
            if ( class_exists( 'Project_WP_Logger', false ) ) {
                Project_WP_Logger::write(
                    sprintf(
                        'Manual override saved: row #%d, lang=%s, source="%s" → "%s"',
                        $row_id,
                        $row->target_lang,
                        mb_substr( $row->source_text, 0, 50, 'UTF-8' ),
                        mb_substr( $translated_text, 0, 50, 'UTF-8' )
                    ),
                    'info',
                    'rentopian-translation'
                );
            }

            wp_send_json_success( [ 'message' => __( 'Translation saved.', 'rentopian-sync' ) ] );
        } else {
            wp_send_json_error( __( 'Database error saving translation.', 'rentopian-sync' ) );
        }
    }

    /**
     * AJAX: Revert a manual override back to auto.
     */
    public function ajax_revert_auto(): void {
        check_ajax_referer( 'rental_translation_overrides' );

        if ( ! current_user_can( 'import' ) ) {
            wp_send_json_error( __( 'Unauthorized.', 'rentopian-sync' ) );
        }

        $row_id = (int) ( $_POST['row_id'] ?? 0 );

        if ( $row_id <= 0 ) {
            wp_send_json_error( __( 'Invalid row ID.', 'rentopian-sync' ) );
        }

        $cache   = Rental_Translation_Cache::get_instance();
        $success = $cache->revert_to_auto( $row_id );

        if ( $success ) {
            wp_send_json_success( [ 'message' => __( 'Reverted to auto-translation.', 'rentopian-sync' ) ] );
        } else {
            wp_send_json_error( __( 'Database error reverting.', 'rentopian-sync' ) );
        }
    }

    /**
     * AJAX: Load table rows (for future AJAX pagination if needed).
     */
    public function ajax_load_table(): void {
        check_ajax_referer( 'rental_translation_overrides' );

        if ( ! current_user_can( 'import' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $page        = max( 1, (int) ( $_POST['page'] ?? 1 ) );
        $search      = sanitize_text_field( $_POST['search'] ?? '' );
        $target_lang = sanitize_text_field( $_POST['target_lang'] ?? '' );
        $filter_type = sanitize_text_field( $_POST['filter_type'] ?? '' );

        $cache  = Rental_Translation_Cache::get_instance();
        $result = $cache->get_paginated( $page, self::PER_PAGE, $search, $target_lang, $filter_type );

        wp_send_json_success( $result );
    }
}
