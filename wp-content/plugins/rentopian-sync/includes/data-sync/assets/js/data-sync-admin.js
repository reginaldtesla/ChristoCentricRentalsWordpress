/**
 * Background Data Sync — admin UI.
 *
 * Owns the start/resync + stop controls and polls the pipeline (data phase
 * and the chained file phase) while a run is live. Run history lives in the
 * synchronization log, which covers every sync type rather than this one.
 *
 * Three rules shape the rendering:
 *
 *   - Both stage bars are always on screen once a run exists, and reset to
 *     zero the moment a new run starts, so a resync visibly restarts rather
 *     than appearing to hang at the previous run's position.
 *   - A problem is never reported as "check the logs": the server names the
 *     cause and the fix, and the banner clears itself as soon as the run
 *     recovers.
 *   - The counters a run produced stay on screen for the rest of the visit.
 *     The server drops them once the run is reported, and a panel that
 *     blanks its own results at the finish line reads like data loss.
 */
(function ($) {
    'use strict';

    var cfg = window.rentalDataSyncObj || {};
    var ajaxUrl = cfg.url || (window.rentalObj && rentalObj.url) || window.ajaxurl;
    var i18n = cfg.i18n || {};
    var nonce = cfg.nonce || '';

    // Mirrors Rental_Data_Sync_Status / Rental_Sync_Status.
    var STATUS_COMPLETED = 3;

    // Fast cadence right after a start so the first callback shows up
    // quickly; relaxed once the run is clearly moving.
    var POLL_FAST_MS = 2000;
    var POLL_SLOW_MS = 5000;
    var FAST_POLL_FOR_MS = 60000;

    var pollTimer = null;
    var pollDelay = 0;
    var fastUntil = 0;
    var busy = false;
    var currentSyncId = '';

    // Last counters seen for each run. The server deletes a run's stats as
    // soon as its report goes out, so without this the totals vanish at the
    // exact moment the run finishes.
    var lastStats = {};

    // Set while a run started from this page is still in flight, so the
    // outcome is only announced to whoever asked for it — a page load does
    // not congratulate anyone on a run that finished days ago.
    var awaitingCompletion = false;

    var $btnStart, $btnStop, $btnStopAll, $notice, $status, $lastRun;

    // Whether the visible banner reports a problem. Only those are cleared
    // automatically once the run recovers — an outcome message the admin
    // just triggered stays put.
    var noticeIsProblem = false;

    function t(key, fallback) {
        return i18n[key] || fallback;
    }

    function esc(value) {
        return $('<span>').text(String(value == null ? '' : value)).html();
    }

    function notice(type, html, isProblem) {
        if (!$notice || !$notice.length) { return; }
        noticeIsProblem = !!isProblem;
        $notice
            .removeClass('notice-success notice-error notice-warning notice-info')
            .addClass('notice notice-' + type)
            .html(html)
            .show();
    }

    function clearNotice() {
        noticeIsProblem = false;
        if ($notice && $notice.length) {
            $notice.hide().empty();
        }
    }

    /**
     * Render a server-side problem: the cause, then what to do about it.
     */
    function problemHtml(message, hints) {
        var html = '<p><strong>' + esc(message) + '</strong></p>';

        if (hints && hints.length) {
            html += '<ul class="rental-ds-hints">';
            $.each(hints, function (_, hint) {
                html += '<li>' + esc(hint) + '</li>';
            });
            html += '</ul>';
        }

        return html;
    }

    function showProblem(type, message, hints) {
        notice(type, problemHtml(message, hints), true);
    }

    /* ── Rendering ─────────────────────────────────────────── */

    /**
     * The counters to show for a run: whatever the server still holds, or
     * the last set it reported for that same run.
     */
    function statsFor(data) {
        var syncId = data.sync_id || '';
        var stats = data.stats;

        if (stats && !$.isEmptyObject(stats)) {
            lastStats[syncId] = stats;
            return stats;
        }

        return lastStats[syncId] || {};
    }

    function statsList(stats) {
        var items = '';
        $.each(stats || {}, function (entity, c) {
            var bits = [];
            if (c.created) { bits.push(c.created + ' new'); }
            if (c.updated) { bits.push(c.updated + ' updated'); }
            if (c.deleted) { bits.push(c.deleted + ' deleted'); }
            if (c.skipped) { bits.push(c.skipped + ' skipped'); }
            if (c.failed) { bits.push(c.failed + ' failed'); }
            if (bits.length) {
                items += '<li><strong>' + esc(entity) + '</strong> ' + esc(bits.join(', ')) + '</li>';
            }
        });
        return items ? '<ul class="rental-ds-stats">' + items + '</ul>' : '';
    }

    /**
     * What became of the run report. "Sent" is only claimed when the mail
     * actually went out; the two ways it does not are different problems
     * with different fixes, and are named as such.
     */
    function reportLine(chain) {
        if (!chain || !chain.reported_at) { return ''; }

        if (chain.report_sent) {
            return '<p class="rental-ds-muted">' +
                esc(t('reported', 'Report emailed at') + ' ' + chain.reported_at) + '</p>';
        }

        var why = chain.report_recipients
            ? t('reportFailed', 'Report could not be emailed — the site has no working mail transport. The full report is in this run\'s log.')
            : t('reportNoRecipient', 'Report not emailed: no recipient is set. Choose one under "Report email" above.');

        return '<p class="rental-ds-error">' + esc(why + ' (' + chain.reported_at + ')') + '</p>';
    }

    function stageRow(name, stateText, percent, extra, cssClass) {
        return '<div class="rental-ds-stage ' + (cssClass || '') + '">' +
            '<span class="rental-ds-stage-name">' + esc(name) + '</span>' +
            '<span class="rental-ds-state">' + esc(stateText) + '</span>' +
            '<span class="rental-ds-bar"><span style="width:' + Math.max(0, Math.min(100, percent)) + '%"></span></span>' +
            '<span class="rental-ds-pct">' + Math.round(Math.max(0, Math.min(100, percent))) + '%</span>' +
            (extra ? '<span class="rental-ds-muted">' + extra + '</span>' : '') +
            '</div>';
    }

    function fileStageState(data, files) {
        if (files.is_running) { return t('processing', 'processing'); }
        if (files.status === 3) { return t('completed', 'completed'); }
        if (data && data.file_chain && data.file_chain.file_sync_id) { return t('queued', 'queued'); }
        // The data phase is done and the chain has not fired yet — a real
        // state of its own, not the same as "nothing has happened".
        if (data && data.chain_pending) { return t('fileStarting', 'starting soon'); }
        return t('waiting', 'waiting');
    }

    function render(snapshot) {
        if (!$status || !$status.length) { return; }

        var data = snapshot.data;
        var files = snapshot.files || {};
        var html = '';
        var panelClass = 'is-idle';

        if (!data) {
            html += '<p class="rental-ds-muted">' +
                esc(snapshot.has_previous_success
                    ? t('idleSynced', 'No run in progress. The catalog was synchronized previously.')
                    : t('idleNever', 'No background synchronization has been run yet.')) +
                '</p>';
        } else {
            panelClass = data.status === 3 ? 'is-done'
                : data.status === 4 ? 'is-failed'
                    : data.status === 5 ? 'is-canceled'
                        : 'is-running';

            html += stageRow(
                t('stageData', 'Data'),
                data.status_label,
                data.percent,
                esc(t('phase', 'phase') + ' ' + (data.phase + 1) + '/' + data.phase_total +
                    ' (' + data.phase_label + ') · ' + data.processed_count + ' ' + t('rows', 'rows')),
                data.status === 3 ? 'is-done' : ''
            );

            html += stageRow(
                t('stageFiles', 'Files'),
                fileStageState(data, files),
                files.percent || 0,
                files.total_count
                    ? esc(files.processed_count + '/' + files.total_count + ' ' + t('images', 'images'))
                    : '',
                files.status === 3 ? 'is-done' : ''
            );

            html += statsList(statsFor(data));

            html += reportLine(data.file_chain);
        }

        $status.removeClass('is-idle is-running is-done is-failed is-canceled')
            .addClass(panelClass)
            .html(html);
    }

    /**
     * The last recorded outcome, success or failure, refreshed on every
     * poll so the line is never stale after a run ends.
     */
    function renderLastRun(snapshot) {
        if (!$lastRun || !$lastRun.length) { return; }

        var run = snapshot.last_run;
        if (!run || !run.finished_at) {
            $lastRun.hide().empty();
            return;
        }

        var label = run.status === 3 ? t('outcomeCompleted', 'completed')
            : run.status === 4 ? t('outcomeFailed', 'failed')
                : t('outcomeCanceled', 'canceled');

        var html = '<span class="rental-ds-badge is-' + esc(run.status_label) + '">' + esc(label) + '</span> ' +
            esc(t('lastRun', 'Last synchronization:') + ' ' + run.finished_at);

        if (run.duration) {
            html += ' <span class="rental-ds-muted">(' + esc(formatDuration(run.duration)) + ')</span>';
        }
        if (run.message) {
            html += '<br><span class="rental-ds-muted">' + esc(run.message) + '</span>';
        }

        $lastRun.html(html).show();
    }

    function formatDuration(seconds) {
        seconds = parseInt(seconds, 10) || 0;
        if (seconds < 60) { return seconds + 's'; }
        var m = Math.floor(seconds / 60);
        if (m < 60) { return m + 'm ' + (seconds % 60) + 's'; }
        return Math.floor(m / 60) + 'h ' + (m % 60) + 'm';
    }

    /**
     * Apply the server snapshot to the controls and the problem banner.
     */
    function applyControls(snapshot) {
        var running = !!snapshot.is_running;

        if ($btnStart && $btnStart.length) {
            $btnStart
                .prop('disabled', running || busy)
                .toggleClass('is-busy', running)
                .find('.rental-ds-btn-label')
                .text(running ? t('running', 'Synchronizing…') : snapshot.button_label);
        }

        // Stop follows what is stoppable, not what is moving. A phase whose
        // driver went quiet is still open, still blocks the next run, and is
        // exactly when this button is reached for.
        if ($btnStop && $btnStop.length) {
            var stoppable = snapshot.can_stop === undefined ? running : snapshot.can_stop;
            $btnStop.prop('disabled', !stoppable || busy);
        }

        if ($btnStopAll && $btnStopAll.length) {
            $btnStopAll.prop('disabled', busy);
        }

        var diagnosis = snapshot.diagnosis;
        if (diagnosis && diagnosis.severity !== 'info') {
            showProblem(diagnosis.severity, diagnosis.message, diagnosis.hints);
        } else if (noticeIsProblem && !busy) {
            // The run recovered — a stale failure banner would only mislead.
            clearNotice();
        }

        // "Scheduled" stops being true the moment the pipeline finishes, and
        // leaving it on screen is the last thing an admin reads about a run
        // that is actually over.
        if (awaitingCompletion && !noticeIsProblem && pipelineComplete(snapshot)) {
            awaitingCompletion = false;
            notice('success', '<p>' + esc(t('completedAll', 'Background synchronization completed.')) + '</p>', false);
        }
    }

    /**
     * Whether both phases of the run this page started have finished. A
     * chained file phase that never completed leaves the pipeline unfinished
     * however done the data phase looks.
     */
    function pipelineComplete(snapshot) {
        var data = snapshot.data;

        if (!data || snapshot.is_running || data.status !== STATUS_COMPLETED) {
            return false;
        }

        return (snapshot.files || {}).status === STATUS_COMPLETED;
    }

    /* ── Polling ───────────────────────────────────────────── */

    function stopPolling() {
        if (pollTimer) {
            clearInterval(pollTimer);
            pollTimer = null;
            pollDelay = 0;
        }
    }

    function startPolling() {
        var wanted = Date.now() < fastUntil ? POLL_FAST_MS : POLL_SLOW_MS;
        if (pollTimer && pollDelay === wanted) { return; }

        stopPolling();
        pollDelay = wanted;
        pollTimer = setInterval(refresh, wanted);
    }

    function refresh(onDone) {
        return $.get(ajaxUrl, { action: 'rental_data_sync_status' })
            .done(function (snapshot) {
                if (!snapshot || !snapshot.success) { return; }

                render(snapshot);
                renderLastRun(snapshot);
                applyControls(snapshot);

                currentSyncId = (snapshot.data && snapshot.data.sync_id) || currentSyncId;

                if (!snapshot.is_running) {
                    stopPolling();
                } else {
                    startPolling();
                }

                if (typeof onDone === 'function') { onDone(snapshot); }
            });
    }

    /* ── Wiring ────────────────────────────────────────────── */

    $(function () {
        $btnStart = $('#rental_data_sync_start_btn');
        $btnStop = $('#rental_data_sync_cancel_btn');
        $btnStopAll = $('#rental_data_sync_cancel_all_btn');
        $notice = $('#rental_data_sync_notice');
        $status = $('#rental_data_sync_status');
        $lastRun = $('#rental_data_sync_last_run');

        if (!$btnStart.length) { return; }

        $btnStart.on('click', function (e) {
            e.preventDefault();

            var apiKey = $('#rental_api_key').val();

            if (!apiKey) {
                showProblem('error', t('needKey', 'Enter the API key first.'), []);
                return;
            }

            if (!window.confirm(t('confirmStart',
                'Are you sure you want to proceed with the data and file synchronization? The current website content will be removed and replaced with the latest content, so the storefront will be incomplete until the run finishes.'))) {
                return;
            }

            busy = true;
            clearNotice();
            fastUntil = Date.now() + FAST_POLL_FOR_MS;

            // Reset the panel immediately: a resync must look like it
            // restarted, not like it resumed the previous run.
            currentSyncId = '';
            $status.removeClass('is-idle is-done is-failed is-canceled').addClass('is-running')
                .html('<p class="rental-ds-muted">' + esc(t('starting', 'Starting…')) + '</p>');
            applyControls({ is_running: true, button_label: $btnStart.text() });

            $.post(ajaxUrl, { action: 'rental_data_sync_start', api_key: apiKey, nonce: nonce })
                .done(function (resp) {
                    if (resp.warnings && resp.warnings.length) {
                        var hints = [];
                        $.each(resp.warnings, function (_, w) {
                            hints = hints.concat([w.message]).concat(w.hints || []);
                        });
                        showProblem('warning', t('startedWithWarnings', 'Synchronization scheduled, but with warnings:'), hints);
                    } else {
                        notice('success', '<p>' + esc(t('startedData', 'Background synchronization scheduled.')) + '</p>', false);
                    }

                    // This page is now the one waiting on an outcome.
                    awaitingCompletion = true;
                })
                .fail(function (xhr) {
                    var body = xhr.responseJSON || {};
                    showProblem(
                        'error',
                        body.message || t('startFailedUnknown',
                            'The synchronization could not be started and the server gave no reason. The site may have hit a PHP error — check WooCommerce → Status → Logs (rentopian-data-sync).'),
                        body.hints || []
                    );
                })
                .always(function () {
                    busy = false;
                    refresh();
                    startPolling();
                });
        });

        $btnStop.on('click', function (e) {
            e.preventDefault();

            if (!window.confirm(t('confirmStop',
                'Stop the running synchronization? Already-synced records stay; the rest is skipped until the next run.'))) {
                return;
            }

            busy = true;
            awaitingCompletion = false;
            $btnStop.prop('disabled', true);

            $.post(ajaxUrl, { action: 'rental_data_sync_cancel', nonce: nonce })
                .done(function (resp) {
                    notice('warning', '<p>' + esc(resp.message || t('stopped', 'Synchronization canceled.')) + '</p>', false);
                })
                .fail(function (xhr) {
                    var body = xhr.responseJSON || {};
                    showProblem('error', body.message || t('stopFailed', 'Cancel failed.'), body.hints || []);
                })
                .always(function () {
                    busy = false;
                    stopPolling();
                    refresh();
                });
        });

        // Stop everything, including runs this page never started — the way
        // out of a pipeline left half-alive by an earlier attempt.
        if ($btnStopAll.length) {
            $btnStopAll.on('click', function (e) {
                e.preventDefault();

                if (!window.confirm(t('confirmStopAll',
                    'Stop every synchronization, data and images, including any left running from an earlier attempt? Nothing already imported is removed, and starting a new synchronization re-enables them.'))) {
                    return;
                }

                busy = true;
                awaitingCompletion = false;
                $btnStopAll.prop('disabled', true);

                $.post(ajaxUrl, { action: 'rental_data_sync_cancel_all', nonce: nonce })
                    .done(function (resp) {
                        notice('warning', '<p>' + esc(resp.message || t('stoppedAll', 'All synchronization stopped.')) + '</p>', false);
                    })
                    .fail(function (xhr) {
                        var body = xhr.responseJSON || {};
                        showProblem('error', body.message || t('stopAllFailed', 'The synchronizations could not be stopped.'), body.hints || []);
                    })
                    .always(function () {
                        busy = false;
                        stopPolling();
                        refresh();
                    });
            });
        }

        // Report recipients.
        $('#rental_data_sync_report_save').on('click', function (e) {
            e.preventDefault();

            var $button = $(this);
            var $status = $('#rental_data_sync_report_status');

            $button.prop('disabled', true);
            $status.removeClass('rental-ds-error').text(t('saving', 'Saving…'));

            $.post(ajaxUrl, {
                action: 'rental_data_sync_save_report_email',
                user_id: $('#rental_data_sync_report_user').val(),
                extra: $('#rental_data_sync_report_extra').val(),
                nonce: nonce
            })
                .done(function (resp) {
                    $status.text(resp.message || '');
                })
                .fail(function (xhr) {
                    var body = xhr.responseJSON || {};
                    $status.addClass('rental-ds-error')
                        .text(body.message || t('reportSaveFailed', 'The report recipient could not be saved.'));
                })
                .always(function () {
                    $button.prop('disabled', false);
                });
        });

        // Initial state: label the button and resume polling if a run is live.
        refresh(function (snapshot) {
            if (snapshot.is_running) { startPolling(); }
        });
    });
})(jQuery);
