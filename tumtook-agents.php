<?php
/**
 * Plugin Name: Tumtook Agents
 * Plugin URI: https://chatbotline.tumtook.com
 * Description: ดึงข้อมูลรายชื่อ Agents จาก Tumtook API มาแสดงผลผ่าน Shortcode [tumtook_agents]
 * Version: 1.2.0
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
define('TUMTOOK_AGENTS_VERSION', '1.2.0');
define('TUMTOOK_AGENTS_REFRESH_INTERVAL', MINUTE_IN_SECONDS);
define('TUMTOOK_AGENTS_CRON_HOOK', 'tumtook_agents_refresh_event');
define('TUMTOOK_AGENTS_STATE_KEY', TUMTOOK_AGENTS_CACHE_KEY . '_state');
define('TUMTOOK_AGENTS_LOCK_KEY', TUMTOOK_AGENTS_CACHE_KEY . '_lock');

require_once __DIR__ . '/includes/cache.php';

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
        TUMTOOK_AGENTS_VERSION
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

    return has_shortcode($post->post_content, 'tumtook_agents')
        || has_shortcode((string) get_post_meta($post->ID, '_elementor_data', true), 'tumtook_agents')
        ? false : $is_cacheable;
}
add_filter('flying_press_is_cacheable', 'tumtook_agents_control_flyingpress_cache');

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
    $state = get_option(TUMTOOK_AGENTS_STATE_KEY, array());
    $next_refresh = wp_next_scheduled(TUMTOOK_AGENTS_CRON_HOOK);
    ?>
    <div class="wrap">
        <h1>Tumtook Agents</h1>

        <?php if ($cache_cleared) : ?>
            <div class="notice notice-success is-dismissible">
                <p>รับคำขออัปเดตแล้ว ระบบจะดึงข้อมูลและอัปเดตแคชในรอบงานเบื้องหลังถัดไป ไม่ต้องกดซ้ำ</p>
            </div>
        <?php endif; ?>

        <div class="card" style="max-width: 720px;">
            <h2>การอัปเดตอัตโนมัติ</h2>
            <p>ตรวจข้อมูล API เบื้องหลังทุก 60 วินาที และล้างแคชเฉพาะหน้าที่แสดง Agents เมื่อข้อมูลที่แสดงเปลี่ยน</p>
            <p>อัปเดตสำเร็จล่าสุด:
                <?php echo empty($state['last_success']) ? 'ยังไม่มี' : esc_html(wp_date('Y-m-d H:i:s', $state['last_success'])); ?>
            </p>
            <p>รอบตรวจถัดไป:
                <?php echo $next_refresh ? esc_html(wp_date('Y-m-d H:i:s', $next_refresh)) : 'ยังไม่ได้ตั้งเวลา'; ?>
            </p>
            <?php if (!empty($state['last_error'])) : ?>
                <p>การตรวจครั้งล่าสุดไม่สำเร็จ ระบบจะลองใหม่ในรอบถัดไปและใช้ข้อมูลล่าสุดที่ยังมีอยู่ชั่วคราว</p>
            <?php endif; ?>
            <?php if (!empty($state['purge_pending'])) : ?>
                <p>กำลังรออัปเดตแคชหน้าเว็บในรอบตรวจที่สำเร็จถัดไป</p>
            <?php endif; ?>
            <p>เพื่อให้ตรวจได้ทุกนาทีแม้ไม่มีคนเข้าเว็บ ให้ตั้ง Cron ของเซิร์ฟเวอร์เรียก WordPress ทุกนาที โดย WP-Cron ตามปกติอาจทำงานช้ากว่าเวลาที่ตั้งไว้</p>
        </div>
        <div class="card" style="max-width: 720px;">
            <h2>Cache Tools</h2>
            <p>ระบบอัปเดตอัตโนมัติ ไม่ต้องกดปุ่มเป็นประจำ ปุ่มนี้ใช้ขออัปเดตข้อมูลและแคชในรอบงานเบื้องหลังถัดไป โดยยังเก็บข้อมูลล่าสุดไว้ระหว่างรอ</p>
            <form method="post">
                <?php wp_nonce_field('tumtook_agents_clear_cache_action'); ?>
                <input type="hidden" name="tumtook_agents_clear_cache" value="1" />
                <?php submit_button('ขออัปเดตข้อมูล', 'primary', 'submit', false); ?>
            </form>
        </div>
    </div>
    <?php
}

/**
 * Fetch agents data from remote API.
 *
 * @return array|WP_Error An empty array is a successful result with no enabled agents.
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
        return new WP_Error('tumtook_agents_network_error', 'Unable to reach the agents API.');
    }

    $status = wp_remote_retrieve_response_code($response);
    if ($status < 200 || $status >= 300) {
        return new WP_Error('tumtook_agents_http_error', 'The agents API returned an unsuccessful response.');
    }

    // Decode objects as objects so that {} cannot be confused with a valid [].
    $data = json_decode(wp_remote_retrieve_body($response));
    if (json_last_error() !== JSON_ERROR_NONE) {
        return new WP_Error('tumtook_agents_invalid_json', 'The agents API returned invalid JSON.');
    }

    if (is_object($data) && property_exists($data, 'items')) {
        $data = $data->items;
    }
    if (!is_array($data)) {
        return new WP_Error('tumtook_agents_invalid_data', 'The agents API did not return an agent list.');
    }

    foreach ($data as &$agent) {
        if (!is_object($agent) || !isset($agent->name) || !is_string($agent->name)
            || !isset($agent->flexEnabled) || !is_bool($agent->flexEnabled)) {
            return new WP_Error('tumtook_agents_invalid_data', 'The agents API returned an invalid agent.');
        }
        $agent = (array) $agent;
    }
    unset($agent);

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
 * Serve the last snapshot without blocking visitors on routine API refreshes.
 *
 * @return array|WP_Error
 */
function tumtook_agents_fetch_data() {
    $cached = get_transient(TUMTOOK_AGENTS_CACHE_KEY);

    if (is_array($cached)) {
        return $cached;
    }

    $stale = get_transient(TUMTOOK_AGENTS_STALE_CACHE_KEY);
    if (is_array($stale)) {
        return $stale;
    }

    // Even a cold page must not run HTTP requests or FlyingPress operations.
    // The scheduled worker fills the snapshot and invalidates this placeholder.
    tumtook_agents_ensure_refresh_schedule();
    return new WP_Error('tumtook_agents_retry_later', 'The agents data is temporarily unavailable.');
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
    $line_url = tumtook_agents_get_line_url($agent);
    $img_url = tumtook_agents_get_image_url($agent);
    $qr_url = tumtook_agents_get_qr_url($agent);
    ?>
    <div class="tumtook-agent-card" style="--delay: <?php echo esc_attr($index * 0.05); ?>s">
        <div class="tumtook-agent-photo">
            <?php if ($img_url) : ?>
                <div class="tumtook-flip-front">
                    <img src="<?php echo esc_url($img_url); ?>" alt="<?php echo esc_attr($name); ?>" loading="lazy" decoding="async" />
                </div>
            <?php else : ?>
                <div class="tumtook-flip-front tumtook-agent-placeholder">
                    <span><?php echo esc_html(mb_substr($name, 0, 1)); ?></span>
                </div>
            <?php endif; ?>

            <?php if ($qr_url) : ?>
                <div class="tumtook-flip-back">
                    <img src="<?php echo esc_url($qr_url); ?>" alt="QR <?php echo esc_attr($line_id); ?>" loading="lazy" decoding="async" />
                </div>
            <?php endif; ?>
        </div>

        <div class="tumtook-agent-info">
            <h3 class="tumtook-agent-name"><?php echo esc_html($name); ?></h3>
            <?php if ($line_id !== '') : ?>
                <p class="tumtook-agent-lineid">Line ID : <?php echo esc_html($line_id); ?></p>
            <?php endif; ?>
            <?php if ($line_url !== '') : ?>
                <a
                    class="tumtook-agent-line-link"
                    href="<?php echo esc_url($line_url); ?>"
                    target="_blank"
                    rel="noopener noreferrer"
                    aria-label="แชต LINE กับ <?php echo esc_attr($name); ?>"
                    style="color:#fff!important;text-decoration:none!important;"
                >แชต LINE</a>
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
    tumtook_agents_track_current_page();
    tumtook_agents_enqueue_styles();

    $agents = tumtook_agents_fetch_data();

    if (is_wp_error($agents)) {
        return '<p class="tumtook-agents-error">ไม่สามารถโหลดข้อมูลได้ กรุณาลองใหม่อีกครั้ง</p>';
    }
    if (empty($agents)) {
        return '<p class="tumtook-agents-empty">ขณะนี้ยังไม่มีทีมฝ่ายขายที่เปิดให้บริการ</p>';
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
    wp_clear_scheduled_hook(TUMTOOK_AGENTS_CRON_HOOK);
    delete_transient(TUMTOOK_AGENTS_CACHE_KEY);
    delete_transient(TUMTOOK_AGENTS_STALE_CACHE_KEY);
    delete_option(TUMTOOK_AGENTS_LOCK_KEY);
    delete_option(TUMTOOK_AGENTS_STATE_KEY);
    delete_option('tumtook_agents_version');
}
register_deactivation_hook(__FILE__, 'tumtook_agents_deactivate');
