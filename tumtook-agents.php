<?php
/**
 * Plugin Name: Tumtook Agents
 * Plugin URI: https://chatbotline.tumtook.com
 * Description: ดึงข้อมูลรายชื่อ Agents จาก Tumtook API มาแสดงผลผ่าน Shortcode [tumtook_agents]
 * Version: 1.0.4
 * Author: Tumtook
 * Text Domain: tumtook-agents
 */

if (!defined('ABSPATH')) {
    exit;
}

define('TUMTOOK_AGENTS_PLUGIN_URL', plugin_dir_url(__FILE__));
define('TUMTOOK_AGENTS_API_URL', 'https://line.tumtook.com/api/config/agents?x-api-key=LFUlhy9mgG0UKDCzoon7JnYIjIHKH91v');
define('TUMTOOK_AGENTS_CACHE_KEY', 'tumtook_agents_' . substr(md5(TUMTOOK_AGENTS_API_URL), 0, 8));
define('TUMTOOK_AGENTS_STALE_CACHE_KEY', TUMTOOK_AGENTS_CACHE_KEY . '_stale');
define('TUMTOOK_AGENTS_CACHE_TTL', 30 * MINUTE_IN_SECONDS);
define('TUMTOOK_AGENTS_STALE_CACHE_TTL', 12 * HOUR_IN_SECONDS);
define('TUMTOOK_AGENTS_REQUEST_TIMEOUT', 5);

/**
 * Enqueue plugin styles.
 *
 * @return void
 */
function tumtook_agents_register_styles() {
    wp_register_style(
        'tumtook-agents-style',
        TUMTOOK_AGENTS_PLUGIN_URL . 'style.css',
        array(),
        '1.0.4'
    );
}
add_action('wp_enqueue_scripts', 'tumtook_agents_register_styles');

/**
 * Enqueue plugin styles only when the shortcode is rendered.
 *
 * @return void
 */
function tumtook_agents_enqueue_styles() {
    if (!wp_style_is('tumtook-agents-style', 'registered')) {
        tumtook_agents_register_styles();
    }

    wp_enqueue_style('tumtook-agents-style');
}

/**
 * Purge cached pages from FlyingPress when available.
 *
 * @return void
 */
function tumtook_agents_purge_page_cache() {
    if (class_exists('FlyingPress\Purge')) {
        \FlyingPress\Purge::purge_pages();
    }
}

/**
 * Allow FlyingPress to cache shortcode pages by default.
 *
 * @param bool $is_cacheable Whether FlyingPress should cache the current page.
 * @return bool
 */
function tumtook_agents_control_flyingpress_cache($is_cacheable) {
    if (!defined('TUMTOOK_AGENTS_DISABLE_PAGE_CACHE') || TUMTOOK_AGENTS_DISABLE_PAGE_CACHE !== true) {
        return $is_cacheable;
    }

    if (!$is_cacheable || is_admin() || !is_singular()) {
        return $is_cacheable;
    }

    $post = get_post();

    if (!$post instanceof WP_Post) {
        return $is_cacheable;
    }

    return has_shortcode($post->post_content, 'tumtook_agents') ? false : $is_cacheable;
}
add_filter('flying_press_is_cacheable', 'tumtook_agents_control_flyingpress_cache');

/**
 * Clear cached agents data and purge related FlyingPress page caches.
 *
 * @return void
 */
function tumtook_agents_clear_cache() {
    delete_transient(TUMTOOK_AGENTS_CACHE_KEY);
    delete_transient(TUMTOOK_AGENTS_STALE_CACHE_KEY);
    tumtook_agents_purge_page_cache();
}

/**
 * Get a normalized LINE ID for sorting agents.
 *
 * @param array $agent Agent data.
 * @return string
 */
function tumtook_agents_get_sort_line_id($agent) {
    return isset($agent['lineId']) ? trim((string) $agent['lineId']) : '';
}

/**
 * Register plugin admin page.
 *
 * @return void
 */
function tumtook_agents_register_admin_page() {
    add_options_page(
        'Tumtook Agents',
        'Tumtook Agents',
        'manage_options',
        'tumtook-agents',
        'tumtook_agents_render_admin_page'
    );
}
add_action('admin_menu', 'tumtook_agents_register_admin_page');

/**
 * Return the plugin settings page URL.
 *
 * @param array $args Optional query args.
 * @return string
 */
function tumtook_agents_get_admin_page_url($args = array()) {
    $args = wp_parse_args(
        $args,
        array(
            'page' => 'tumtook-agents',
        )
    );

    return add_query_arg($args, admin_url('options-general.php'));
}

/**
 * Handle admin actions.
 *
 * @return void
 */
function tumtook_agents_handle_admin_actions() {
    if (!is_admin() || !current_user_can('manage_options')) {
        return;
    }

    if (empty($_POST['tumtook_agents_clear_cache'])) {
        return;
    }

    check_admin_referer('tumtook_agents_clear_cache_action');

    tumtook_agents_clear_cache();

    wp_safe_redirect(
        tumtook_agents_get_admin_page_url(
            array(
                'cache_cleared' => '1',
            )
        )
    );
    exit;
}
add_action('admin_init', 'tumtook_agents_handle_admin_actions');

/**
 * Render plugin admin page.
 *
 * @return void
 */
function tumtook_agents_render_admin_page() {
    $cache_cleared = isset($_GET['cache_cleared']) && $_GET['cache_cleared'] === '1';
    ?>
    <div class="wrap">
        <h1>Tumtook Agents</h1>

        <?php if ($cache_cleared) : ?>
            <div class="notice notice-success is-dismissible">
                <p>ลบ cache ของ Tumtook Agents เรียบร้อยแล้ว</p>
            </div>
        <?php endif; ?>

        <div class="card" style="max-width: 720px;">
            <h2>Cache Tools</h2>
            <p>ใช้ปุ่มนี้เพื่อล้าง cache ข้อมูล agents ที่ปลั๊กอินเก็บไว้ แล้วให้ request ถัดไปดึงข้อมูลใหม่จาก API</p>
            <form method="post">
                <?php wp_nonce_field('tumtook_agents_clear_cache_action'); ?>
                <input type="hidden" name="tumtook_agents_clear_cache" value="1" />
                <?php submit_button('Clear Cache', 'primary', 'submit', false); ?>
            </form>
        </div>
    </div>
    <?php
}

/**
 * Fetch agents data from remote API.
 *
 * @return array
 */
function tumtook_agents_request_data() {
    $response = wp_remote_get(
        TUMTOOK_AGENTS_API_URL,
        array(
            'timeout'   => TUMTOOK_AGENTS_REQUEST_TIMEOUT,
            'sslverify' => true,
            'headers'   => array(
                'Accept' => 'application/json',
            ),
        )
    );

    if (is_wp_error($response)) {
        return array();
    }

    $data = json_decode(wp_remote_retrieve_body($response), true);

    if (!is_array($data)) {
        return array();
    }

    if (isset($data['items']) && is_array($data['items'])) {
        $data = $data['items'];
    }

    if (!is_array($data)) {
        return array();
    }

    $agents = array_values(
        array_filter(
            $data,
            static function ($agent) {
                return is_array($agent)
                    && !empty($agent['name'])
                    && isset($agent['flexEnabled'])
                    && $agent['flexEnabled'] === true;
            }
        )
    );

    usort(
        $agents,
        static function ($agent_a, $agent_b) {
            return strnatcasecmp(
                tumtook_agents_get_sort_line_id($agent_a),
                tumtook_agents_get_sort_line_id($agent_b)
            );
        }
    );

    return $agents;
}

/**
 * Fetch agents data with caching.
 *
 * @return array
 */
function tumtook_agents_fetch_data() {
    $cached = get_transient(TUMTOOK_AGENTS_CACHE_KEY);

    if ($cached !== false) {
        return is_array($cached) ? $cached : array();
    }

    $data = tumtook_agents_request_data();

    if (!empty($data)) {
        set_transient(TUMTOOK_AGENTS_CACHE_KEY, $data, TUMTOOK_AGENTS_CACHE_TTL);
        set_transient(TUMTOOK_AGENTS_STALE_CACHE_KEY, $data, TUMTOOK_AGENTS_STALE_CACHE_TTL);

        return $data;
    }

    $stale = get_transient(TUMTOOK_AGENTS_STALE_CACHE_KEY);

    return is_array($stale) ? $stale : array();
}

/**
 * Build a display name for an agent.
 *
 * @param array $agent Agent data.
 * @return string
 */
function tumtook_agents_get_display_name($agent) {
    $raw_name = isset($agent['name']) ? (string) $agent['name'] : '';
    $raw_name = str_replace('กด!', '', $raw_name);
    $raw_name = preg_replace('/\s*\(.*?\)\s*/', '', $raw_name);

    return 'เซลล์' . trim($raw_name);
}

/**
 * Build image URL for an agent.
 *
 * @param array $agent Agent data.
 * @return string
 */
function tumtook_agents_get_image_url($agent) {
    if (empty($agent['avatarFilename'])) {
        return '';
    }

    return 'https://line.tumtook.com/api/uploads/agents/' . rawurlencode((string) $agent['avatarFilename']);
}

/**
 * Build LINE URL for an agent.
 *
 * @param array $agent Agent data.
 * @return string
 */
function tumtook_agents_get_line_url($agent) {
    if (empty($agent['lineId'])) {
        return '';
    }

    return 'https://line.me/ti/p/' . (string) $agent['lineId'];
}

/**
 * Build QR URL for an agent.
 *
 * @param array $agent Agent data.
 * @return string
 */
function tumtook_agents_get_qr_url($agent) {
    $line_url = tumtook_agents_get_line_url($agent);

    if ($line_url === '') {
        return '';
    }

    return 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&margin=10&color=06c755&data=' . urlencode($line_url);
}

/**
 * Render a single agent card.
 *
 * @param array $agent Agent data.
 * @param int   $index Position in the grid.
 * @return void
 */
function tumtook_agents_render_card($agent, $index) {
    if (empty($agent['name'])) {
        return;
    }

    $name = tumtook_agents_get_display_name($agent);
    $line_id = isset($agent['lineId']) ? (string) $agent['lineId'] : '';
    $img_url = tumtook_agents_get_image_url($agent);
    $qr_url = tumtook_agents_get_qr_url($agent);
    ?>
    <div class="tumtook-agent-card" style="--delay: <?php echo esc_attr($index * 0.05); ?>s">
        <div class="tumtook-agent-photo">
            <?php if ($img_url) : ?>
                <div class="tumtook-flip-front">
                    <img src="<?php echo esc_url($img_url); ?>" alt="<?php echo esc_attr($name); ?>" loading="lazy" />
                </div>
            <?php else : ?>
                <div class="tumtook-flip-front tumtook-agent-placeholder">
                    <span><?php echo esc_html(mb_substr($name, 0, 1)); ?></span>
                </div>
            <?php endif; ?>

            <?php if ($qr_url) : ?>
                <div class="tumtook-flip-back">
                    <img src="<?php echo esc_url($qr_url); ?>" alt="QR <?php echo esc_attr($line_id); ?>" loading="lazy" />
                </div>
            <?php endif; ?>
        </div>

        <div class="tumtook-agent-info">
            <h3 class="tumtook-agent-name"><?php echo esc_html($name); ?></h3>
            <?php if ($line_id !== '') : ?>
                <p class="tumtook-agent-lineid">Line ID : <?php echo esc_html($line_id); ?></p>
            <?php endif; ?>
        </div>
    </div>
    <?php
}

/**
 * Shortcode: [tumtook_agents]
 * Displays agent names from the Tumtook API.
 *
 * @return string
 */
function tumtook_agents_shortcode() {
    tumtook_agents_enqueue_styles();

    $agents = tumtook_agents_fetch_data();

    if (empty($agents)) {
        return '<p class="tumtook-agents-error">ไม่สามารถโหลดข้อมูลได้ กรุณาลองใหม่อีกครั้ง</p>';
    }

    ob_start();
    ?>
    <div class="tumtook-agents-wrapper">
        <div class="tumtook-agents-header">
            <h2 class="tumtook-agents-title"><img class="tumtook-agents-logo" src="<?php echo esc_url(TUMTOOK_AGENTS_PLUGIN_URL . 'assets/images/logotumtook.svg'); ?>" alt="Tumtook" /> ทีมฝ่ายขาย Tumtook</h2>
            <p class="tumtook-agents-subtitle">พร้อมให้บริการคุณ <?php echo count($agents); ?> คน</p>
        </div>
        <div class="tumtook-agents-grid">
            <?php foreach ($agents as $index => $agent) : ?>
                <?php tumtook_agents_render_card($agent, $index); ?>
            <?php endforeach; ?>
        </div>
    </div>
    <?php

    return ob_get_clean();
}
add_shortcode('tumtook_agents', 'tumtook_agents_shortcode');

/**
 * Clear cache when plugin is deactivated.
 *
 * @return void
 */
function tumtook_agents_deactivate() {
    tumtook_agents_clear_cache();
}
register_deactivation_hook(__FILE__, 'tumtook_agents_deactivate');
