<?php

if ( !defined('RENTAL_UPDATE_MANIFEST_URL')) {
    define('RENTAL_UPDATE_MANIFEST_URL', 'https://rentopian.com/wp-plugin/rentopian-sync.json');
}

function rental_get_upgrade_information($slug) {
    // Trying to get from cache first
    if ( !$remote = get_transient("rental_upgrade_$slug")) {
        // FIX SSL SNI
        $filter_add = true;
        if (function_exists('curl_version')) {
            $version = curl_version();
            if (version_compare($version['version'], '7.18', '>=')) {
                $filter_add = false;
            }
        }
        if ($filter_add) {
            add_filter('https_ssl_verify', '__return_false');
        }

        
        $request = wp_remote_get(RENTAL_UPDATE_MANIFEST_URL, [
            'timeout' => 10,
            'headers' => [
                'Accept' => 'application/json'
            ]
        ]);


        // info.json is the file with the actual plugin information on your server
        // $request = wp_remote_get('https://rentprotheme.com/theme-assets/plugins/rentopian-sync.json', [
        //     'timeout' => 10,
        //     'headers' => [
        //         'Accept' => 'application/json'
        //     ]
        // ]);

        if ($filter_add) {
            remove_filter('https_ssl_verify', '__return_false');
        }

        if (is_wp_error($request) || !isset($request['response']) || !isset($request['response']['code']) || $request['response']['code'] != 200 ||
            !isset($request['body']) || empty($remote = json_decode($request['body']))) {
            return false;
        }
//        $remote = json_decode( preg_replace('/[\x00-\x1F\x80-\xFF]/', '', $remote['body']) );
        set_transient("rental_upgrade_$slug", $remote, 7200); // 2 hours cache
    }

    return $remote;
}

function rental_plugin_info($res, $action, $args) {
    // Do nothing if this is not about getting plugin information
    if ($action !== 'plugin_information') {
        return $res;
    }

    $slug = plugin_basename(RENTOPIAN_SYNC_PATH . '/rentopian-sync.php');
    // Do nothing if it is not our plugin
    if ( !isset($args->slug) || $slug !== $args->slug) {
        return $res;
    }

    if ($remote = rental_get_upgrade_information($slug)) {
        $res = new stdClass();
        $res->name = $remote->name;
        $res->slug = $slug;
        $res->version = $remote->version;
        $res->tested = $remote->tested;
        $res->requires = $remote->requires;
        $res->author = $remote->author;
        $res->author_profile = 'https://rentopian.com'; // WordPress.org profile
        $res->download_link = $remote->download_url;
        $res->trunk = $remote->download_url;
        $res->last_updated = $remote->last_updated;
        $res->sections = [
            'description' => $remote->sections->description, // description tab
            'installation' => $remote->sections->installation, // installation tab
            'changelog' => $remote->sections->changelog, // changelog tab
            // you can add your custom sections (tabs) here
        ];

        // in case you want the screenshots tab, use the following HTML format for its content:
        // <ol><li><a href="IMG_URL" target="_blank" rel="noopener noreferrer"><img src="IMG_URL" alt="CAPTION" /></a><p>CAPTION</p></li></ol>
        if ( !empty($remote->sections->screenshots)) {
            $res->sections['screenshots'] = $remote->sections->screenshots;
        }

        $image_folder = plugins_url(plugin_basename(RENTOPIAN_SYNC_PATH . '/assets/images'));
        $res->banners = array(
            'low' => $image_folder . '/banner-772x250.png',
            'high' => $image_folder . '/banner-1544x500.png'
        );
    }

    return $res;
}
add_filter('plugins_api', 'rental_plugin_info', 20, 3);


function rental_push_update($transient) {
    $slug = plugin_basename(RENTOPIAN_SYNC_PATH . '/rentopian-sync.php');

    // Extra check for 3rd plugins
    if (isset($transient->response[$slug])) {
        return $transient;
    }

    $remote = rental_get_upgrade_information($slug);

    // Manifest could not be retrieved — the update check cannot run.
    if ( !$remote) {
        rental_report_update_failure('manifest_unreachable', 'Update manifest could not be retrieved.');
        return $transient;
    }

    // Manifest returned a non-version value (e.g. a placeholder) — version_compare would silently fail.
    if ( !rental_is_valid_version(isset($remote->version) ? $remote->version : null)) {
        rental_report_update_failure('invalid_version', 'Update manifest returned an invalid version value.', array(
            'manifest_version' => isset($remote->version) ? (string) $remote->version : '(missing)',
        ));
        return $transient;
    }

    $requires = isset($remote->requires) ? $remote->requires : '0';
    if (version_compare(RENTOPIAN_SYNC_VERSION, $remote->version, '<') && version_compare($requires, get_bloginfo('version'), '<=')) {
        $res = new stdClass();
        $res->slug = $slug;
        $res->plugin = $slug;
        $res->new_version = $remote->version;
        $res->tested = $remote->tested;
        $res->package = $remote->download_url;
        $res->url = $remote->homepage;
        $transient->response[$slug] = $res;
    }

    return $transient;
}
add_filter('pre_set_site_transient_update_plugins', 'rental_push_update');


function rental_is_valid_version($version) {
    return is_string($version) && (bool) preg_match('/^\d/', trim($version));
}


// Log and email a notice when the update check cannot work.
// Throttled per reason: the update transient refreshes often, so this can fire
// many times per request.
function rental_report_update_failure($reason_code, $reason_message, $details = array()) {
    $throttle_key = 'rental_update_fail_' . sanitize_key($reason_code);
    if (get_transient($throttle_key)) {
        return;
    }
    $window = (int) apply_filters('rental_update_failure_throttle', DAY_IN_SECONDS, $reason_code);
    set_transient($throttle_key, time(), $window);

    $when = current_time('Y-m-d H:i:s') . ' (' . wp_timezone_string() . ')';
    $site = home_url();

    $context = array(
        'site=' . $site,
        'installed=' . RENTOPIAN_SYNC_VERSION,
        'reason=' . $reason_code,
    );
    foreach ($details as $key => $value) {
        $context[] = $key . '=' . $value;
    }
    $log_line = $reason_message . ' [' . implode('; ', $context) . ']';

    if (class_exists('Project_WP_Logger')) {
        Project_WP_Logger::write($log_line, 'error', 'rentopian-sync-updater');
    }

    rental_send_update_failure_email($reason_message, $details, $when, $site);
}


// Send the update-failure notice to the configured recipients.
// Recipients are filterable so more addresses can be added later.
function rental_send_update_failure_email($reason_message, $details, $when, $site) {
    $recipients = apply_filters('rental_update_failure_emails', array('babakhani.aarony@gmail.com'));
    $recipients = array_values(array_unique(array_filter((array) $recipients, 'is_email')));
    if (empty($recipients)) {
        return;
    }

    $host    = wp_parse_url($site, PHP_URL_HOST);
    $subject = sprintf('[Rentopian Sync] Plugin update check failed on %s', $host);

    $lines   = array();
    $lines[] = 'Date/Time: ' . $when;
    $lines[] = 'Site: ' . $site;
    $lines[] = 'Installed version: ' . RENTOPIAN_SYNC_VERSION;
    $lines[] = 'Issue: ' . $reason_message;
    foreach ($details as $key => $value) {
        $lines[] = ucfirst(str_replace('_', ' ', $key)) . ': ' . $value;
    }
    $lines[] = 'Manifest: ' . RENTAL_UPDATE_MANIFEST_URL;

    wp_mail($recipients, $subject, implode("\r\n", $lines));
}


function rental_pre_upgrade_filter($reply, $package, $updater) {
    $slug = plugin_basename(RENTOPIAN_SYNC_PATH . '/rentopian-sync.php');

    // Do nothing if it is not our plugin
    if (( !isset($updater->skin->plugin) || $slug !== $updater->skin->plugin) && ( !isset($updater->skin->plugin_info) || !$updater->skin->plugin_info['Name'] === "Rentopian Sync")) {
        return $reply;
    }

    if ( !$updater->fs_connect([WP_CONTENT_DIR])) {
        return new WP_Error('no_credentials', esc_html__("Error! Can't connect to filesystem", 'rentopian-sync'));
    }

    $updater->strings['downloading_package_url'] = esc_html__('Getting download link...', 'rentopian-sync');
    $updater->skin->feedback('downloading_package_url');

    if ( !$remote = rental_get_upgrade_information($slug)) {
        return new WP_Error('no_credentials', esc_html__('Download link could not be retrieved', 'rentopian-sync'));
    }

    $updater->strings['downloading_package'] = esc_html__('Downloading package...', 'rentopian-sync');
    $updater->skin->feedback('downloading_package');

    $downloaded_archive = download_url($remote->download_url);
    if (is_wp_error($downloaded_archive)) {
        return $downloaded_archive;
    }

    // WP will use same name for plugin directory as archive name, so we have to rename it
    $downloaded_archive_info = pathinfo($downloaded_archive);
    if ($downloaded_archive_info['filename'] !== 'rentopian-sync') {
        $new_archive_name = $downloaded_archive_info['dirname'] . '/rentopian-sync.' . $downloaded_archive_info['extension'];
        if (rename($downloaded_archive, $new_archive_name)) {
            $downloaded_archive = $new_archive_name;
        }
    }

    return $downloaded_archive;
}
//add_filter('upgrader_pre_download', 'rental_pre_upgrade_filter', 10, 3);


function rental_after_update($updater, $options) {
    if (isset($options['action'] ) && $options['action'] == 'update' && $options['type'] === 'plugin') {
        // just clean the cache when new plugin version is installed
        delete_transient('rental_upgrade_' . plugin_basename(RENTOPIAN_SYNC_PATH . '/rentopian-sync.php'));
    }
}
add_action('upgrader_process_complete', 'rental_after_update', 10, 2);


// Plugin version this site last ran. Empty until a build that tracks it has
// loaded once, so an older install reads as "unknown" rather than current.
if ( !defined('RENTAL_INSTALLED_VERSION_OPTION')) {
    define('RENTAL_INSTALLED_VERSION_OPTION', 'rental_sync_installed_version');
}

// Runs the upgrade steps this build brings with it, on every request.
function rental_run_upgrade_steps() {
    if (class_exists('Rental_Sets_Admin_Settings')) {
        Rental_Sets_Admin_Settings::apply_release_defaults();
    }

    // Record what the site is running. Nothing above depends on it; it is the
    // anchor for any future step that has to know which version it came from.
    if (get_option(RENTAL_INSTALLED_VERSION_OPTION, '') !== RENTOPIAN_SYNC_VERSION) {
        update_option(RENTAL_INSTALLED_VERSION_OPTION, RENTOPIAN_SYNC_VERSION, true);
    }
}
add_action('plugins_loaded', 'rental_run_upgrade_steps', 20);



function rental_force_correct_folder( $source, $remote, $upgrader, $hook_extra ) {
    if (
        isset( $hook_extra['plugin'] )
        && $hook_extra['plugin'] === plugin_basename( RENTOPIAN_SYNC_PATH . '/rentopian-sync.php' )
    ) {
        $correct = dirname($source) . '/rentopian-sync';
        if ( basename($source) !== 'rentopian-sync' ) {
            rename($source, $correct);
            return $correct;
        }
    }
    return $source;
}
add_filter( 'upgrader_source_selection', 'rental_force_correct_folder', 10, 4 );