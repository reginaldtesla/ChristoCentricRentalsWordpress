/**
 * Unified synchronization log — admin UI.
 *
 * Lists every synchronization the site has run, whichever generation of the
 * pipeline produced it, and expands one run into the tail of its log.
 *
 * One rule shapes the rendering: a request that fails says so. Every path
 * out of an AJAX call writes something into the panel — the response, the
 * server's message, or the transport error — so the panel can never sit on
 * "loading" with nothing behind it.
 */
(function ($) {
    'use strict';

    var cfg = window.rentalSyncLogObj || {};
    var ajaxUrl = cfg.url || window.ajaxurl;
    var i18n = cfg.i18n || {};
    var nonce = cfg.nonce || '';

    // A log request reads files from disk; a slow one still has to end.
    var REQUEST_TIMEOUT_MS = 30000;

    var $panel, $tbody, $pagination, $notice;
    var currentPage = 1;
    var totalPages = 1;

    function t(key, fallback) {
        return i18n[key] || fallback;
    }

    function esc(value) {
        return $('<span>').text(String(value == null ? '' : value)).html();
    }

    function notice(type, message) {
        if (!$notice || !$notice.length) { return; }
        $notice
            .removeClass('notice-success notice-error notice-warning notice-info')
            .addClass('notice notice-' + type)
            .html('<p>' + esc(message) + '</p>')
            .show();
    }

    function clearNotice() {
        if ($notice && $notice.length) { $notice.hide().empty(); }
    }

    /**
     * Why a request failed, in the server's words when it gave any.
     */
    function failureMessage(xhr, textStatus) {
        var body = xhr.responseJSON;

        if (body && body.message) { return body.message; }
        if (textStatus === 'timeout') { return t('timedOut', 'The server did not answer in time. Try again.'); }
        if (xhr.status === 0) { return t('offline', 'The browser could not reach the server.'); }

        if (xhr.status) {
            return t('httpError', 'The server answered with an error') + ' (HTTP ' + xhr.status + ').';
        }

        return t('unknownError', 'The request failed and the server gave no reason.');
    }

    function request(options) {
        return $.ajax($.extend({ url: ajaxUrl, timeout: REQUEST_TIMEOUT_MS }, options));
    }

    /* ── Formatting ────────────────────────────────────────── */

    function formatDuration(seconds) {
        seconds = parseInt(seconds, 10) || 0;
        if (seconds < 60) { return seconds + 's'; }
        var m = Math.floor(seconds / 60);
        if (m < 60) { return m + 'm ' + (seconds % 60) + 's'; }
        return Math.floor(m / 60) + 'h ' + (m % 60) + 'm';
    }

    function formatTime(unix) {
        if (!unix) { return '—'; }
        return new Date(unix * 1000).toLocaleString();
    }

    function shortId(id) {
        id = String(id == null ? '' : id);
        return id.length > 10 ? id.slice(0, 8) + '…' : id;
    }

    // What a run got through, in the units that source actually counts.
    function counts(run) {
        return run.counts_label || '—';
    }

    /* ── Run list ──────────────────────────────────────────── */

    /**
     * A cell's main value with a quieter second line under it, so the table
     * carries every field of a run without growing a column for each.
     */
    function stacked(main, sub, subTitle) {
        var html = esc(main);

        if (sub) {
            html += '<span class="rental-sl-sub"' +
                (subTitle ? ' title="' + esc(subTitle) + '"' : '') + '>' + esc(sub) + '</span>';
        }

        return html;
    }

    function runRow(run) {
        // Same class and data attribute the retry handler has always been
        // bound to, so the action keeps working with no new wiring.
        var retry = run.has_failures
            ? ' <button type="button" class="btn btn-sm btn-inline-warning rental-retry-failed" data-sync_id="' +
              esc(run.file_sync_id) + '">' + esc(t('retryFailed', 'Retry Failed Images')) + '</button>'
            : '';

        var idCell = '<code title="' + esc(run.sync_id) + '">' + esc(shortId(run.sync_id)) + '</code>';
        if (run.file_sync_id && run.file_sync_id !== run.sync_id) {
            idCell += '<span class="rental-sl-sub" title="' + esc(run.file_sync_id) + '">' +
                esc(t('filesId', 'files:') + ' ' + shortId(run.file_sync_id)) + '</span>';
        }

        return '<tr data-run_key="' + esc(run.key) + '">' +
            '<td>' + idCell + '</td>' +
            '<td>' + stacked(run.type_label, run.mode_label) + '</td>' +
            '<td><span class="rental-sl-badge is-' + esc(run.status_label) + '">' +
            esc(run.status_label) + '</span>' +
            (run.phase_label ? '<span class="rental-sl-sub">' + esc(run.phase_label) + '</span>' : '') +
            '</td>' +
            '<td>' + stacked(
                formatTime(run.started_at),
                run.finished_at ? t('finished', 'finished') + ' ' + formatTime(run.finished_at) : ''
            ) + '</td>' +
            '<td>' + esc(run.duration ? formatDuration(run.duration) : '—') + '</td>' +
            '<td>' + esc(counts(run)) + '</td>' +
            '<td class="rental-sl-actions">' +
            '<button type="button" class="btn btn-sm btn-inline-info btn-icon-fixed rental-sl-details">' +
            '<span class="dashicons dashicons-visibility"></span>' +
            esc(t('details', 'Details')) + '</button>' +
            '<button type="button" class="btn btn-sm btn-inline-danger btn-icon-fixed rental-sl-delete"' +
            (run.is_running ? ' disabled' : '') + '>' +
            '<span class="dashicons dashicons-trash"></span>' +
            esc(t('deleteRun', 'Delete')) + '</button>' + retry +
            '</td>' +
            '</tr>' +
            '<tr class="rental-sl-detail-row" style="display:none">' +
            '<td colspan="7"><div class="rental-sl-detail"></div></td></tr>';
    }

    /**
     * Same first/previous/next/last shape the other log panels use, so
     * paging through runs feels like paging through anything else here.
     */
    function renderPagination(data) {
        if (!$pagination || !$pagination.length) { return; }

        var pages = data.total_pages || 0;
        var page = data.page || 1;

        totalPages = pages;

        if (pages < 2) {
            $pagination.empty();
            return;
        }

        var html = '';

        if (page > 1) {
            html += '<a class="first" href="#" title="' + esc(t('first', 'First')) + '">&laquo;</a> ..';
            html += '<a href="#">' + (page - 1) + '</a>';
        }

        html += '<a class="active" href="#">' + page + '</a>';

        if (page < pages) {
            html += '<a href="#">' + (page + 1) + '</a>';
            html += '.. <a class="last" href="#" title="' + esc(t('last', 'Last')) + '">&raquo;</a>';
        }

        $pagination.html(html);
    }

    function loadRuns(page) {
        if (!$tbody || !$tbody.length) { return; }

        currentPage = page || 1;
        $tbody.html(messageRow('rental-sl-muted', t('loading', 'Loading…')));

        request({ data: { action: 'rental_sync_log_runs', page_no: currentPage } })
            .done(function (data) {
                if (!data || !data.success) {
                    $tbody.html(messageRow('rental-sl-error',
                        (data && data.message) || t('unknownError', 'The request failed and the server gave no reason.')));
                    return;
                }

                var rows = '';
                $.each(data.runs || [], function (_, run) { rows += runRow(run); });

                $tbody.html(rows || messageRow('rental-sl-muted',
                    t('noRuns', 'No synchronization has been recorded yet.')));

                renderPagination(data);
            })
            .fail(function (xhr, textStatus) {
                $tbody.html(messageRow('rental-sl-error', failureMessage(xhr, textStatus)));
            });
    }

    function messageRow(cssClass, message) {
        return '<tr><td colspan="7" class="' + cssClass + '">' + esc(message) + '</td></tr>';
    }

    /* ── Run detail ────────────────────────────────────────── */

    function entryList(entries) {
        var html = '<ul class="rental-sl-entries">';

        $.each(entries || [], function (_, entry) {
            var when = entry.time ? new Date(entry.time * 1000).toLocaleTimeString() : '';
            html += '<li class="level-' + esc(entry.level) + '">' +
                '<code>' + esc(when) + '</code> ' +
                (entry.channel ? '<span class="rental-sl-channel">[' + esc(entry.channel) + ']</span> ' : '') +
                esc(entry.message) + '</li>';
        });

        return html + '</ul>';
    }

    function section(sec) {
        var html = '<div class="rental-sl-section"><h4>' + esc(sec.title) + '</h4>';

        if (sec.note) {
            html += '<p class="rental-sl-muted">' + esc(sec.note) + '</p>';
        }
        if (sec.truncated) {
            html += '<p class="rental-sl-muted">' +
                esc(t('logTruncated', 'Showing the most recent lines — download the log for the whole run.')) + '</p>';
        }
        if (sec.entries && sec.entries.length) {
            html += entryList(sec.entries);
        }

        return html + '</div>';
    }

    function renderDetail(data) {
        var run = data.run || {};

        var html = '<div class="rental-sl-detail-toolbar">' +
            '<a class="btn btn-sm btn-inline-info btn-icon-fixed" href="' + esc(data.download_url) + '">' +
            '<span class="dashicons dashicons-download"></span>' +
            esc(t('downloadLog', 'Download log')) + '</a>';

        if (run.message) {
            html += '<span class="rental-sl-muted">' + esc(run.message) + '</span>';
        }

        html += '</div>';

        $.each(data.sections || [], function (_, sec) { html += section(sec); });

        return html;
    }

    /* ── Wiring ────────────────────────────────────────────── */

    $(function () {
        $panel = $('#rental_sync_log_panel');
        if (!$panel.length) { return; }

        $tbody = $panel.find('tbody');
        $pagination = $panel.find('.rental-pagination');
        $notice = $panel.find('.rental-sl-notice');
        totalPages = 1;

        $panel.on('click', '.rental-sl-details', function () {
            var $row = $(this).closest('tr');
            var $detailRow = $row.next('.rental-sl-detail-row');
            var $target = $detailRow.find('.rental-sl-detail');

            if ($detailRow.is(':visible')) {
                $detailRow.hide();
                return;
            }

            $target.html('<p class="rental-sl-muted">' + esc(t('loading', 'Loading…')) + '</p>');
            $detailRow.show();

            request({ data: { action: 'rental_sync_log_detail', key: $row.attr('data-run_key') } })
                .done(function (data) {
                    if (!data || !data.success) {
                        $target.html('<p class="rental-sl-error">' +
                            esc((data && data.message) || t('unknownError', 'The request failed and the server gave no reason.')) +
                            '</p>');
                        return;
                    }

                    $target.html(renderDetail(data));
                })
                .fail(function (xhr, textStatus) {
                    $target.html('<p class="rental-sl-error">' + esc(failureMessage(xhr, textStatus)) + '</p>');
                });
        });

        $panel.on('click', '.rental-sl-delete', function () {
            var $button = $(this);
            var $row = $button.closest('tr');

            if (!window.confirm(t('confirmDelete', 'Delete this run and its log?'))) {
                return;
            }

            clearNotice();
            $button.prop('disabled', true);

            request({
                method: 'POST',
                data: { action: 'rental_sync_log_delete', key: $row.attr('data-run_key'), nonce: nonce }
            })
                .done(function () {
                    loadRuns(currentPage);
                })
                .fail(function (xhr, textStatus) {
                    notice('error', failureMessage(xhr, textStatus));
                    $button.prop('disabled', false);
                });
        });

        $pagination.on('click', 'a', function (e) {
            e.preventDefault();

            var $link = $(this);

            if ($link.hasClass('first')) {
                loadRuns(1);
            } else if ($link.hasClass('last')) {
                loadRuns(totalPages);
            } else if (!$link.hasClass('active')) {
                loadRuns(parseInt($link.text(), 10) || 1);
            }
        });

        $panel.on('click', '.rental-sl-refresh', function () {
            clearNotice();
            loadRuns(currentPage);
        });

        loadRuns(1);
    });
})(jQuery);
