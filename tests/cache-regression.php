<?php
/**
 * CLI regression tests using real WP hooks/cron and isolated data/HTTP adapters.
 * Run: php wp-content/plugins/tumtook-agents/tests/cache-regression.php
 * Also run with --without-flyingpress. No live database or network is used.
 */
namespace FlyingPress {
    if (PHP_SAPI !== 'cli') {
        exit;
    }
    if (!in_array('--without-flyingpress', $_SERVER['argv'], true)) {
        class Purge {
            public static $calls = array();
            public static $fail = false;
            public static function purge_urls($urls) {
                if (self::$fail) {
                    throw new \RuntimeException('Simulated purge failure');
                }
                self::$calls[] = $urls;
                foreach ($urls as $url) {
                    unset($GLOBALS['page_cache'][$url]);
                }
            }
            public static function purge_pages() {
                throw new \RuntimeException('A site-wide purge must never run.');
            }
        }
        class Preload {
            public static $calls = array();
            public static function preload_urls($urls) {
                self::$calls[] = $urls;
                foreach ($urls as $url) {
                    $GLOBALS['page_cache'][$url] = \tumtook_agents_shortcode();
                }
            }
        }
    }
}

namespace {
    define('ABSPATH', dirname(__DIR__, 4) . '/');
    define('MINUTE_IN_SECONDS', 60);
    define('HOUR_IN_SECONDS', 3600);
    define('DAY_IN_SECONDS', 86400);
    define('WEEK_IN_SECONDS', 604800);
    define('WP_PLUGIN_DIR', ABSPATH . 'wp-content/plugins');
    define('WPMU_PLUGIN_DIR', ABSPATH . 'wp-content/mu-plugins');
    $wp_plugin_paths = array();
    require ABSPATH . 'wp-includes/plugin.php';
    require ABSPATH . 'wp-includes/cron.php';
    require ABSPATH . 'wp-includes/shortcodes.php';
    require ABSPATH . 'wp-includes/class-wp-error.php';

    // Fail on notices/warnings as well as assertions.
    set_error_handler(static function ($severity, $message, $file, $line) {
        throw new \ErrorException($message, 0, $severity, $file, $line);
    });

    $options = array();
    $transients = array();
    $api_calls = 0;
    $api_body = '';
    $api_status = 200;
    $api_error = false;
    $on_request = null;
    $current_post_id = 101;
    $preview = false;
    $page_cache = array();
    $checks = 0;

    class WP_Post {
        public $ID;
        public $post_status;
        public $post_content;
        public $post_type;
        public $slug;
    }
    class TestDB {
        public $posts = 'posts';
        public $postmeta = 'postmeta';
        public $options = 'options';
        public $db;
        public $scans = 0;
        public function __construct() {
            $this->db = new \PDO('sqlite::memory:');
            $this->db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $this->db->exec('CREATE TABLE posts (ID INTEGER PRIMARY KEY, post_status TEXT, post_content TEXT, post_type TEXT, slug TEXT)');
            $this->db->exec('CREATE TABLE postmeta (post_id INTEGER, meta_key TEXT, meta_value TEXT)');
        }
        public function prepare($sql, ...$args) { return array($sql, $args); }
        public function esc_like($text) { return addcslashes($text, '_%\\'); }
        public function get_col($query) {
            ++$this->scans;
            $sql = str_replace('%s', '?', $query[0]);
            // Match MySQL LIKE escaping while executing the actual discovery SQL.
            $sql = str_replace('LIKE ?', "LIKE ? ESCAPE '\\'", $sql);
            $statement = $this->db->prepare($sql);
            $statement->execute($query[1]);
            return $statement->fetchAll(\PDO::FETCH_COLUMN);
        }
        public function query($query) {
            if (strpos($query[0], 'DELETE FROM options WHERE option_name = %s AND option_value = %s') !== 0) {
                throw new \RuntimeException('Unexpected SQL mutation in isolated test');
            }
            list($key, $expected) = $query[1];
            if (array_key_exists($key, $GLOBALS['options']) && (string) $GLOBALS['options'][$key] === $expected) {
                unset($GLOBALS['options'][$key]);
                return 1;
            }
            return 0;
        }
    }
    $wpdb = new TestDB();
    $insert_post = $wpdb->db->prepare('INSERT INTO posts VALUES (?, ?, ?, ?, ?)');
    foreach (array(
        array(101, 'publish', '[tumtook_agents]', 'page', 'agents'),
        array(102, 'publish', '', 'page', 'elementor-agents'),
        array(103, 'publish', 'Rendered through a shared template', 'page', 'template-agents'),
        array(104, 'publish', 'Unrelated page', 'page', 'unrelated'),
        array(105, 'private', '[tumtook_agents]', 'page', 'private'),
        array(106, 'draft', '[tumtook_agents]', 'page', 'draft'),
        array(107, 'publish', '[tumtook_agents]', 'elementor_library', 'library'),
        array(108, 'publish', '[tumtook_agents_other]', 'page', 'other-shortcode'),
    ) as $row) {
        $insert_post->execute($row);
    }
    $insert_meta = $wpdb->db->prepare('INSERT INTO postmeta VALUES (?, ?, ?)');
    $insert_meta->execute(array(102, '_elementor_data', '[{"widgetType":"shortcode","settings":{"shortcode":"[tumtook_agents]"}}]'));

    function __($text) { return $text; }
    function plugins_url($path, $file) { return 'https://example.test/plugin/'; }
    function trailingslashit($text) { return rtrim($text, '/') . '/'; }
    function wp_normalize_path($file) { return str_replace('\\', '/', $file); }
    function is_wp_error($value) { return $value instanceof WP_Error; }
    function is_admin() { return false; }
    function is_singular() { return true; }
    function is_preview() { return $GLOBALS['preview']; }
    function get_queried_object_id() { return $GLOBALS['current_post_id']; }
    function get_post($id = null) {
        $statement = $GLOBALS['wpdb']->db->prepare('SELECT * FROM posts WHERE ID = ?');
        $statement->execute(array($id === null ? get_queried_object_id() : $id));
        return $statement->fetchObject('WP_Post');
    }
    function get_post_status($id) { $post = get_post($id); return $post ? $post->post_status : false; }
    function get_post_meta($id, $key, $single) {
        $statement = $GLOBALS['wpdb']->db->prepare('SELECT meta_value FROM postmeta WHERE post_id = ? AND meta_key = ?');
        $statement->execute(array($id, $key));
        $value = $statement->fetchColumn();
        return $value === false ? '' : $value;
    }
    function is_post_publicly_viewable($post) { return $post->post_status === 'publish' && $post->post_type === 'page'; }
    function get_permalink($id) { return 'https://example.test/' . get_post($id)->slug . '/'; }
    function get_option($key, $default = false) { return $GLOBALS['options'][$key] ?? $default; }
    function update_option($key, $value, $autoload = null) {
        $changed = !array_key_exists($key, $GLOBALS['options']) || $GLOBALS['options'][$key] !== $value;
        $GLOBALS['options'][$key] = $value;
        return $changed;
    }
    function add_option($key, $value, $deprecated = '', $autoload = null) {
        if (array_key_exists($key, $GLOBALS['options'])) { return false; }
        return update_option($key, $value, $autoload);
    }
    function delete_option($key) { unset($GLOBALS['options'][$key]); }
    function wp_cache_delete($key, $group) {}
    function get_transient($key) {
        $entry = $GLOBALS['transients'][$key] ?? null;
        return $entry && $entry['expires'] > time() ? $entry['value'] : false;
    }
    function set_transient($key, $value, $ttl) {
        $GLOBALS['transients'][$key] = array('value' => $value, 'expires' => time() + $ttl);
        return true;
    }
    function delete_transient($key) { unset($GLOBALS['transients'][$key]); }
    function wp_remote_get($url, $args) {
        ++$GLOBALS['api_calls'];
        if ($GLOBALS['on_request']) { call_user_func($GLOBALS['on_request']); }
        return $GLOBALS['api_error']
            ? new WP_Error('http_request_failed', 'Simulated network failure')
            : array('body' => $GLOBALS['api_body'], 'response' => array('code' => $GLOBALS['api_status']));
    }
    function wp_remote_retrieve_body($response) { return $response['body']; }
    function wp_remote_retrieve_response_code($response) { return $response['response']['code']; }
    function wp_json_encode($data) { return json_encode($data); }
    function wp_style_is(...$args) { return true; }
    function wp_enqueue_style(...$args) {}
    function esc_attr($text) { return htmlspecialchars((string) $text, ENT_QUOTES); }
    function esc_html($text) { return htmlspecialchars((string) $text, ENT_QUOTES); }
    function esc_url($text) { return htmlspecialchars((string) $text, ENT_QUOTES); }
    function esc_url_raw($text) { return $text; }

    require dirname(__DIR__) . '/tumtook-agents.php';

    function verify($condition, $label) {
        if (!$condition) { throw new \RuntimeException('FAIL: ' . $label); }
        ++$GLOBALS['checks'];
        echo 'PASS: ' . $label . "\n";
    }
    function agent($name, $enabled = true, $line = null) {
        return array('name' => $name, 'flexEnabled' => $enabled,
            'lineId' => $line ?? '@' . $name, 'avatarFilename' => $name . '.png');
    }
    function api_items($items, $wrapped = true) {
        $GLOBALS['api_body'] = json_encode($wrapped ? array('items' => $items) : $items);
        $GLOBALS['api_status'] = 200;
        $GLOBALS['api_error'] = false;
    }
    function reset_state() {
        $GLOBALS['options'] = array();
        $GLOBALS['transients'] = array();
        $GLOBALS['api_calls'] = 0;
        $GLOBALS['on_request'] = null;
        $GLOBALS['current_post_id'] = 101;
        $GLOBALS['preview'] = false;
        $GLOBALS['page_cache'] = array(get_permalink(104) => 'Unrelated cached HTML');
        $GLOBALS['wpdb']->scans = 0;
        if (class_exists('FlyingPress\\Purge')) {
            \FlyingPress\Purge::$calls = array();
            \FlyingPress\Purge::$fail = false;
            \FlyingPress\Preload::$calls = array();
        }
        api_items(array(agent('AgentA'), agent('AgentB')));
    }
    function poll() { do_action(TUMTOOK_AGENTS_CRON_HOOK); }
    function rendered_contains($name) { return strpos(tumtook_agents_shortcode(), $name) !== false; }
    function visit($url) {
        if (!isset($GLOBALS['page_cache'][$url])) {
            $GLOBALS['page_cache'][$url] = tumtook_agents_shortcode();
        }
        return $GLOBALS['page_cache'][$url];
    }

    reset_state();
    do_action('init');
    $event = wp_get_scheduled_event(TUMTOOK_AGENTS_CRON_HOOK);
    verify($event && $event->interval === 60, 'A real WordPress recurring event is registered every 60 seconds.');
    $cron = get_option('cron');
    do_action('init');
    verify(get_option('cron') === $cron && $api_calls === 0, 'Repeated init does not duplicate jobs or request the API.');

    if (!class_exists('FlyingPress\\Purge')) {
        poll();
        verify(rendered_contains('AgentA'), 'The plugin works without FlyingPress.');
        api_items(array(agent('AgentA', false), agent('AgentB', false)));
        poll();
        verify(tumtook_agents_fetch_data() === array(), 'Automatic disabling works without FlyingPress.');
        tumtook_agents_deactivate();
        verify(wp_next_scheduled(TUMTOOK_AGENTS_CRON_HOOK) === false, 'Deactivation removes the scheduled job without FlyingPress.');
        echo "All {$checks} checks passed (FlyingPress absent).\n";
        exit;
    }

    // Warm legacy caches have no fingerprint. Upgrade must still purge cached HTML.
    set_transient(TUMTOOK_AGENTS_CACHE_KEY, array(agent('AgentA'), agent('AgentB')), 1800);
    set_transient(TUMTOOK_AGENTS_STALE_CACHE_KEY, array(agent('AgentA'), agent('AgentB')), 43200);
    poll();
    verify($api_calls === 1 && count(\FlyingPress\Purge::$calls) === 1, 'The first scheduled poll refreshes legacy data despite a warm 30-minute cache.');
    $rendered = tumtook_agents_shortcode();
    verify(strpos($rendered, 'class="tumtook-agent-line-link"') !== false
        && strpos($rendered, 'href="https://line.me/ti/p/@AgentA"') !== false,
        'Rendered agent cards include a direct LINE chat link.');
    verify(strpos($rendered, 'aria-label="แชต LINE กับ เซลล์AgentA"') !== false
        && strpos($rendered, 'rel="noopener noreferrer"') !== false,
        'LINE chat links have an accessible label and safe external-link attributes.');
    verify(\FlyingPress\Purge::$calls[0] === array(get_permalink(101), get_permalink(102)), 'Only direct and Elementor shortcode pages are discovered; private/draft/library/other tags are excluded.');
    verify($page_cache[get_permalink(104)] === 'Unrelated cached HTML', 'Unrelated FlyingPress HTML stays untouched.');
    verify(strpos(visit(get_permalink(101)), 'AgentA') !== false && $api_calls === 1, 'The next visit rebuilds HTML using stored data without requesting the API.');
    verify(\FlyingPress\Preload::$calls === array(), 'No additional FlyingPress preload is requested by the plugin.');
    $scans = $wpdb->scans;
    poll();
    verify($api_calls === 2 && count(\FlyingPress\Purge::$calls) === 1 && $wpdb->scans === $scans, 'Unchanged data does not scan pages, purge, or preload again.');

    $current_post_id = 103;
    tumtook_agents_shortcode();
    $current_post_id = 101;
    api_items(array(agent('AgentA', false), agent('AgentB')));
    poll();
    verify(!rendered_contains('AgentA') && rendered_contains('AgentB'), 'Turning one agent off changes the actual shortcode output.');
    verify(in_array(get_permalink(103), end(\FlyingPress\Purge::$calls), true), 'A page rendered through a shared template is tracked for invalidation.');
    verify(strpos(visit(get_permalink(101)), 'AgentA') === false, 'The rebuilt FlyingPress page no longer contains the disabled card.');
    $calls = $api_calls;
    for ($i = 0; $i < 20; ++$i) { tumtook_agents_shortcode(); }
    verify($api_calls === $calls, 'Repeated visitors read the snapshot without adding API requests.');

    api_items(array(agent('AgentA', false), agent('AgentB', false)));
    poll();
    verify(get_transient(TUMTOOK_AGENTS_CACHE_KEY) === array()
        && get_transient(TUMTOOK_AGENTS_STALE_CACHE_KEY) === array(), 'Turning off the last agent overwrites BOTH caches with an empty list.');
    verify(strpos(tumtook_agents_shortcode(), 'tumtook-agents-empty') !== false
        && strpos(tumtook_agents_shortcode(), 'tumtook-agents-error') === false, 'All-disabled data renders an empty state instead of a loading error.');
    $api_error = true;
    poll();
    verify(tumtook_agents_fetch_data() === array(), 'A later API failure cannot resurrect agents disabled by the last successful response.');

    foreach (array(true, false) as $wrapped) {
        reset_state();
        poll();
        api_items(array(), $wrapped);
        poll();
        verify(tumtook_agents_fetch_data() === array(), ($wrapped ? 'items=[]' : 'A top-level []') . ' is accepted as an authoritative empty list.');
    }

    reset_state();
    poll();
    $before = $transients;
    $purges = count(\FlyingPress\Purge::$calls);
    foreach (array(
        array(503, '{"items":[]}'),
        array(200, '<html>error</html>'),
        array(200, '{}'),
        array(200, '{"error":"unavailable"}'),
        array(200, '{"items":{}}'),
        array(200, '{"items":[{}]}'),
    ) as $invalid) {
        list($api_status, $api_body) = $invalid;
        poll();
        verify($transients === $before && count(\FlyingPress\Purge::$calls) === $purges,
            'HTTP ' . $api_status . ' with invalid/error data preserves the last good snapshot and its expiry: ' . $api_body);
    }
    $api_error = true;
    poll();
    verify($transients === $before, 'A network failure does not overwrite or extend stale data.');
    $transients[TUMTOOK_AGENTS_CACHE_KEY]['expires'] = time() - 1;
    $calls = $api_calls;
    verify(rendered_contains('AgentA') && $api_calls === $calls, 'An expired primary cache serves stale data without blocking on the API.');

    // Cached error HTML must be invalidated even when recovery returns the same agents.
    $transients[TUMTOOK_AGENTS_STALE_CACHE_KEY]['expires'] = time() - 1;
    $page_cache[get_permalink(101)] = tumtook_agents_shortcode();
    api_items(array(agent('AgentA'), agent('AgentB')));
    poll();
    verify(count(\FlyingPress\Purge::$calls) === $purges + 1
        && strpos(visit(get_permalink(101)), 'AgentA') !== false, 'Recovery after both snapshots expire rebuilds error HTML even for the same list.');

    reset_state();
    $api_error = true;
    verify(is_wp_error(tumtook_agents_fetch_data()), 'A cold request returns a placeholder while the background job is pending.');
    for ($i = 0; $i < 20; ++$i) { tumtook_agents_shortcode(); }
    verify($api_calls === 0 && $wpdb->scans === 0
        && \FlyingPress\Purge::$calls === array(), 'Cold frontend requests never call the API, scan pages, or purge caches.');
    poll();
    for ($i = 0; $i < 20; ++$i) { tumtook_agents_shortcode(); }
    verify($api_calls === 1, 'Visitors do not retry the API after a failed background poll.');
    api_items(array(agent('Recovered')));
    poll();
    verify(rendered_contains('Recovered'), 'The next background poll recovers without waiting for a visitor.');

    reset_state();
    poll();
    $purges = count(\FlyingPress\Purge::$calls);
    $with_metadata = agent('AgentA');
    $with_metadata['updatedAt'] = 'changed metadata';
    api_items(array(agent('AgentB'), $with_metadata));
    poll();
    verify(count(\FlyingPress\Purge::$calls) === $purges, 'API metadata and input reordering alone do not purge visible cards.');
    $with_metadata['avatarFilename'] = 'new-avatar.png';
    api_items(array($with_metadata, agent('AgentB')));
    poll();
    verify(count(\FlyingPress\Purge::$calls) === $purges + 1, 'A visible avatar change invalidates the affected pages.');

    reset_state();
    $on_request = static function () {
        verify(is_wp_error(tumtook_agents_refresh_data()), 'A concurrent refresh is blocked while the first API call runs.');
    };
    poll();
    verify($api_calls === 1 && get_option(TUMTOOK_AGENTS_LOCK_KEY) === false, 'Only one API request runs and the refresh lock is released.');
    $on_request = null;
    update_option(TUMTOOK_AGENTS_LOCK_KEY, time() - 1);
    poll();
    verify($api_calls === 2, 'An abandoned expired lock can be recovered.');
    $old_lock = time() - 10;
    $new_lock = time() + 30;
    update_option(TUMTOOK_AGENTS_LOCK_KEY, $new_lock);
    tumtook_agents_release_refresh_lock($old_lock);
    verify(get_option(TUMTOOK_AGENTS_LOCK_KEY) === $new_lock, 'An old worker cannot delete a newer worker lock.');
    delete_option(TUMTOOK_AGENTS_LOCK_KEY);

    reset_state();
    \FlyingPress\Purge::$fail = true;
    poll();
    verify(!empty(get_option(TUMTOOK_AGENTS_STATE_KEY)['purge_pending']), 'A FlyingPress failure leaves invalidation pending.');
    \FlyingPress\Purge::$fail = false;
    poll();
    verify(empty(get_option(TUMTOOK_AGENTS_STATE_KEY)['purge_pending'])
        && count(\FlyingPress\Purge::$calls) === 1, 'Pending invalidation is retried even when the next API response is unchanged.');

    reset_state();
    do_action('init');
    poll();
    $before = $transients;
    $calls = $api_calls;
    $scans = $wpdb->scans;
    $purges = \FlyingPress\Purge::$calls;
    $cron = get_option('cron');
    \FlyingPress\Purge::$fail = true;
    tumtook_agents_clear_cache();
    verify($transients === $before && $api_calls === $calls && $wpdb->scans === $scans
        && \FlyingPress\Purge::$calls === $purges, 'The admin refresh button keeps snapshots and performs no API, discovery, or FlyingPress work.');
    for ($i = 0; $i < 10; ++$i) { tumtook_agents_clear_cache(); }
    verify(get_option('cron') === $cron, 'Repeated refresh clicks reuse the existing job instead of spawning more workers.');
    verify(rendered_contains('AgentA') && $api_calls === $calls, 'Pages remain readable after a refresh click even if FlyingPress would fail.');
    \FlyingPress\Purge::$fail = false;
    poll();
    verify(rendered_contains('AgentA') && count(\FlyingPress\Purge::$calls) === count($purges) + 1
        && \FlyingPress\Preload::$calls === array(), 'The background poll performs the requested purge after storing data, without extra preload.');
    tumtook_agents_deactivate();
    verify(wp_next_scheduled(TUMTOOK_AGENTS_CRON_HOOK) === false
        && get_option(TUMTOOK_AGENTS_LOCK_KEY) === false, 'Deactivation removes scheduled polling and locks.');

    define('TUMTOOK_AGENTS_DISABLE_PAGE_CACHE', true);
    $current_post_id = 102;
    verify(tumtook_agents_control_flyingpress_cache(true) === false, 'The optional cache exclusion also recognizes Elementor shortcodes.');
    $current_post_id = 104;
    verify(tumtook_agents_control_flyingpress_cache(true) === true, 'The optional exclusion preserves caching on unrelated pages.');
    echo "All {$checks} checks passed.\n";
}
