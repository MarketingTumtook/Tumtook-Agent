<?php
/**
 * Background refreshes and FlyingPress page cache invalidation.
 */

if (!defined('ABSPATH')) {
    exit;
}

function tumtook_agents_cron_schedules($schedules) {
    $schedules['tumtook_agents_every_minute'] = array(
        'interval' => TUMTOOK_AGENTS_REFRESH_INTERVAL,
        'display'  => 'Tumtook Agents: every minute',
    );
    return $schedules;
}
add_filter('cron_schedules', 'tumtook_agents_cron_schedules');

/** Schedule on normal plugin updates too, without requiring reactivation. */
function tumtook_agents_ensure_refresh_schedule() {
    if (get_option('tumtook_agents_version') !== TUMTOOK_AGENTS_VERSION) {
        $state = get_option(TUMTOOK_AGENTS_STATE_KEY, array());
        $state['purge_pending'] = true;
        update_option(TUMTOOK_AGENTS_STATE_KEY, $state, false);
        update_option('tumtook_agents_version', TUMTOOK_AGENTS_VERSION, false);
    }

    if (!wp_next_scheduled(TUMTOOK_AGENTS_CRON_HOOK)) {
        wp_schedule_event(time() + 1, 'tumtook_agents_every_minute', TUMTOOK_AGENTS_CRON_HOOK);
    }
}
add_action('init', 'tumtook_agents_ensure_refresh_schedule');
register_activation_hook(dirname(__DIR__) . '/tumtook-agents.php', 'tumtook_agents_ensure_refresh_schedule');

/** Remember the actual page when a builder/template renders the shortcode. */
function tumtook_agents_track_current_page() {
    if (is_admin() || !is_singular() || is_preview()) {
        return;
    }

    $post_id = get_queried_object_id();
    if (!$post_id || get_post_status($post_id) !== 'publish') {
        return;
    }

    $post_ids = array_map('intval', (array) get_option('tumtook_agents_page_ids', array()));
    if (!in_array($post_id, $post_ids, true)) {
        $post_ids[] = $post_id;
        update_option('tumtook_agents_page_ids', $post_ids, false);
    }
}

/**
 * Find existing cached shortcode pages, including Elementor data, plus pages
 * observed at render time. This scan runs on invalidation, not on each poll.
 *
 * @return string[]
 */
function tumtook_agents_get_page_urls() {
    global $wpdb;

    $like = '%' . $wpdb->esc_like('[tumtook_agents') . '%';
    $discovered_ids = $wpdb->get_col($wpdb->prepare(
        "SELECT ID FROM {$wpdb->posts}
         WHERE post_status = 'publish' AND post_content LIKE %s
         UNION
         SELECT p.ID FROM {$wpdb->posts} p
         INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
         WHERE p.post_status = 'publish' AND m.meta_key = '_elementor_data'
         AND m.meta_value LIKE %s",
        $like,
        $like
    ));

    $tracked_ids = array_map('intval', (array) get_option('tumtook_agents_page_ids', array()));
    $post_ids = array_unique(array_map('intval', array_merge($tracked_ids, $discovered_ids)));
    $urls = array();
    foreach ($post_ids as $post_id) {
        $post = get_post($post_id);
        if ($post && is_post_publicly_viewable($post)) {
            if (!in_array($post_id, $tracked_ids, true)
                && !has_shortcode($post->post_content, 'tumtook_agents')
                && !has_shortcode((string) get_post_meta($post_id, '_elementor_data', true), 'tumtook_agents')) {
                continue;
            }
            $url = get_permalink($post_id);
            if ($url) {
                $urls[] = $url;
            }
        }
    }

    // Allows explicit URLs for archive pages or shortcodes in shared templates.
    $urls = (array) apply_filters('tumtook_agents_page_urls', $urls);
    return array_values(array_unique(array_filter(array_map('esc_url_raw', $urls))));
}

/**
 * Purge only affected HTML and optionally queue fresh pages for preloading.
 * New agent data must already be stored before this runs.
 *
 * @param bool $preload Whether a fresh snapshot is ready for preloading.
 * @return bool Whether invalidation completed without an integration error.
 */
function tumtook_agents_purge_page_cache($preload = true) {
    if (!class_exists('FlyingPress\\Purge')) {
        return true;
    }
    if (!is_callable(array('FlyingPress\\Purge', 'purge_urls'))) {
        return false;
    }

    try {
        $urls = tumtook_agents_get_page_urls();
        if ($urls) {
            if (\FlyingPress\Purge::purge_urls($urls) === false) {
                return false;
            }
            if ($preload && is_callable(array('FlyingPress\\Preload', 'preload_urls'))) {
                \FlyingPress\Preload::preload_urls($urls);
            }
        }
    } catch (\Throwable $error) {
        // Keep the pending flag so the next successful refresh can retry.
        return false;
    }
    return true;
}

/** Compare only fields used in the rendered cards, ignoring API metadata. */
function tumtook_agents_data_fingerprint($agents) {
    $cards = array();
    foreach ($agents as $agent) {
        $cards[] = array(
            'name'  => tumtook_agents_get_display_name($agent),
            'line'  => isset($agent['lineId']) ? (string) $agent['lineId'] : '',
            'image' => tumtook_agents_get_image_url($agent),
        );
    }
    return md5(wp_json_encode($cards));
}

/** Delete only the lock we observed; never remove another worker's new lock. */
function tumtook_agents_release_refresh_lock($expires) {
    global $wpdb;

    $deleted = $wpdb->query($wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
        TUMTOOK_AGENTS_LOCK_KEY,
        (string) $expires
    ));
    if ($deleted) {
        wp_cache_delete(TUMTOOK_AGENTS_LOCK_KEY, 'options');
    }
}

/**
 * Refresh independently of the 30-minute snapshot TTL. The snapshot is kept
 * for fast frontend reads; its TTL is not the API polling interval.
 *
 * @return array|WP_Error
 */
function tumtook_agents_refresh_data() {
    $now = time();
    $lock = (int) get_option(TUMTOOK_AGENTS_LOCK_KEY, 0);
    if ($lock > $now) {
        return new WP_Error('tumtook_agents_refresh_busy', 'An agents refresh is already running.');
    }
    if ($lock) {
        tumtook_agents_release_refresh_lock($lock);
    }
    // add_option uses a unique option name to avoid concurrent API requests.
    if (!add_option(TUMTOOK_AGENTS_LOCK_KEY, $now + 30, '', false)) {
        return new WP_Error('tumtook_agents_refresh_busy', 'An agents refresh is already running.');
    }

    try {
        $state = get_option(TUMTOOK_AGENTS_STATE_KEY, array());
        $state['last_attempt'] = $now;
        update_option(TUMTOOK_AGENTS_STATE_KEY, $state, false);

        $data = tumtook_agents_request_data();
        if (is_wp_error($data)) {
            // Do not overwrite or extend the lifetime of the last good data.
            $state['last_error'] = $data->get_error_code();
            update_option(TUMTOOK_AGENTS_STATE_KEY, $state, false);
            return $data;
        }

        $fingerprint = tumtook_agents_data_fingerprint($data);
        $has_snapshot = is_array(get_transient(TUMTOOK_AGENTS_CACHE_KEY))
            || is_array(get_transient(TUMTOOK_AGENTS_STALE_CACHE_KEY));
        $changed = !$has_snapshot || !isset($state['fingerprint']) || $state['fingerprint'] !== $fingerprint;
        // [] is authoritative: overwrite BOTH caches when all agents are off.
        set_transient(TUMTOOK_AGENTS_CACHE_KEY, $data, TUMTOOK_AGENTS_CACHE_TTL);
        set_transient(TUMTOOK_AGENTS_STALE_CACHE_KEY, $data, TUMTOOK_AGENTS_STALE_CACHE_TTL);
        $state['fingerprint'] = $fingerprint;
        $state['last_success'] = $now;
        $state['last_error'] = '';
        $state['purge_pending'] = $changed || !empty($state['purge_pending']);
        update_option(TUMTOOK_AGENTS_STATE_KEY, $state, false);

        if ($state['purge_pending'] && tumtook_agents_purge_page_cache()) {
            $state['purge_pending'] = false;
            update_option(TUMTOOK_AGENTS_STATE_KEY, $state, false);
        }
        return $data;
    } finally {
        tumtook_agents_release_refresh_lock($now + 30);
    }
}
add_action(TUMTOOK_AGENTS_CRON_HOOK, 'tumtook_agents_refresh_data');

/** Manual clearing also invalidates any error/old HTML created before refill. */
function tumtook_agents_clear_cache() {
    delete_transient(TUMTOOK_AGENTS_CACHE_KEY);
    delete_transient(TUMTOOK_AGENTS_STALE_CACHE_KEY);
    $state = get_option(TUMTOOK_AGENTS_STATE_KEY, array());
    $state['last_attempt'] = 0;
    $state['purge_pending'] = true;
    update_option(TUMTOOK_AGENTS_STATE_KEY, $state, false);
    tumtook_agents_purge_page_cache(false);
}
