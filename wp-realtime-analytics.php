<?php
/**
 * Plugin Name: Brink Multimedia Analytics
 * Plugin URI: https://www.brink-multimedia.nl
 * Description: Real-time, privacy-vriendelijke statistieken en marketing dashboard voor WordPress.
 * Version: 5.0.0
 * Author: Brink Multimedia
 * Author URI: https://www.brink-multimedia.nl
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Update URI: false
 */

if (!defined('ABSPATH')) exit;

define('WPA_TABLE_STATS', 'brink_analytics_stats');
define('WPA_TABLE_DAILY', 'brink_analytics_daily_summary');
define('WPA_TABLE_GOALS', 'brink_analytics_goals');
define('WPA_TABLE_FUNNELS', 'brink_analytics_funnel_steps');
define('WPA_DB_VERSION', '5.0.0');
define('WPA_PLUGIN_VERSION', '5.0.0');

// ---------------------------------------------------------------------
// GitHub Auto-Updater (lichtgewicht, geen externe library)
// ---------------------------------------------------------------------
define('WPA_GITHUB_OWNER_REPO', 'Brinkmulti/wp-realtime-analytics'); // 'eigenaar/repo'

add_filter('pre_set_site_transient_update_plugins', 'wpa_github_check_update');
function wpa_github_check_update($transient) {
    if (empty($transient->checked) || WPA_GITHUB_OWNER_REPO === '') {
        return $transient;
    }

    $plugin_slug = plugin_basename(__FILE__);
    $release = get_transient('wpa_github_release_cache');

    if (false === $release) {
        $response = wp_remote_get(
            'https://api.github.com/repos/' . WPA_GITHUB_OWNER_REPO . '/releases/latest',
            array(
                'headers' => array('Accept' => 'application/vnd.github.v3+json'),
                'timeout' => 10,
            )
        );

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            set_transient('wpa_github_release_cache', array(), 15 * MINUTE_IN_SECONDS);
            wpa_debug_log('GitHub update-check mislukt: ' . (is_wp_error($response) ? $response->get_error_message() : wp_remote_retrieve_response_code($response)));
            return $transient;
        }

        $data = json_decode(wp_remote_retrieve_body($response));
        $release = array(
            'tag_name'    => isset($data->tag_name) ? $data->tag_name : '',
            'zipball_url' => isset($data->zipball_url) ? $data->zipball_url : '',
            'html_url'    => isset($data->html_url) ? $data->html_url : '',
            'body'        => isset($data->body) ? $data->body : '',
            'published_at' => isset($data->published_at) ? $data->published_at : '',
        );
        set_transient('wpa_github_release_cache', $release, 12 * HOUR_IN_SECONDS);
    }

    if (!empty($release['tag_name'])) {
        $github_versie = ltrim($release['tag_name'], 'v');

        if (version_compare(WPA_PLUGIN_VERSION, $github_versie, '<')) {
            $plugin_info = new stdClass();
            $plugin_info->slug = current(explode('/', $plugin_slug));
            $plugin_info->plugin = $plugin_slug;
            $plugin_info->new_version = $github_versie;
            $plugin_info->package = $release['zipball_url'];
            $plugin_info->url = $release['html_url'];

            $transient->response[$plugin_slug] = $plugin_info;
        }
    }
    return $transient;
}

add_filter('upgrader_source_selection', 'wpa_github_fix_mapnaam', 10, 3);
function wpa_github_fix_mapnaam($source, $remote_source, $upgrader) {
    global $wp_filesystem;
    if (isset($upgrader->skin->plugin_info) && $upgrader->skin->plugin_info['Name'] === 'Brink Multimedia Analytics') {
        $juiste_mapnaam = dirname(plugin_basename(__FILE__));
        $nieuwe_bron = trailingslashit($remote_source) . $juiste_mapnaam;
        if ($wp_filesystem->move($source, $nieuwe_bron)) {
            return trailingslashit($nieuwe_bron);
        }
    }
    return $source;
}

// ---------------------------------------------------------------------
// Kleine hulpfuncties (Feature #36: optionele debug-logging)
// ---------------------------------------------------------------------
function wpa_debug_log($message) {
    if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
        error_log('[Brink Analytics] ' . $message);
    }
}

function wpa_get_hash_secret() {
    $secret = get_option('wpa_hash_secret');
    if (!$secret) {
        $secret = wp_generate_password(32, false, false);
        add_option('wpa_hash_secret', $secret);
    }
    return $secret;
}

// Feature #25: IP-anonimisering (extra laag bovenop de HMAC-hash)
function wpa_anonymize_ip($ip) {
    if (!get_option('wpa_anonymize_ip', true)) {
        return $ip;
    }
    if (strpos($ip, '.') !== false) { // IPv4: laatste octet wissen
        $parts = explode('.', $ip);
        $parts[3] = '0';
        return implode('.', $parts);
    }
    if (strpos($ip, ':') !== false) { // IPv6: laatste 80 bits wissen
        $parts = explode(':', $ip);
        for ($i = 4; $i < count($parts); $i++) { $parts[$i] = '0'; }
        return implode(':', $parts);
    }
    return $ip;
}

// Feature #8: Kanaal-groepering
function wpa_get_channel($referrer, $utm_source, $utm_medium) {
    if (!empty($utm_medium) && in_array(strtolower($utm_medium), array('cpc', 'ppc', 'paid', 'ads'), true)) {
        return 'Betaald';
    }
    if (!empty($utm_source)) {
        return 'Campagne';
    }
    if (empty($referrer)) {
        return 'Direct';
    }
    $host = wp_parse_url($referrer, PHP_URL_HOST);
    if (!$host) return 'Direct';
    $social_hosts = array('facebook.com', 'instagram.com', 'linkedin.com', 'twitter.com', 'x.com', 'tiktok.com', 'pinterest.com', 'youtube.com');
    $search_hosts = array('google.', 'bing.com', 'duckduckgo.com', 'yahoo.com', 'ecosia.org');
    foreach ($social_hosts as $s) {
        if (strpos($host, $s) !== false) return 'Social';
    }
    foreach ($search_hosts as $s) {
        if (strpos($host, $s) !== false) return 'Organisch';
    }
    return 'Referral';
}

function wpa_get_trend_html($current, $prev) {
    if ($prev == 0) return '<span style="font-size:12px;color:#888;">N/A</span>';
    $diff = $current - $prev;
    $perc = round(($diff / $prev) * 100);
    $color = $diff >= 0 ? '#4caf50' : '#f44336';
    $arrow = $diff >= 0 ? '▲' : '▼';
    $sign = $diff > 0 ? '+' : '';
    return "<span style='font-size:13px; color:$color; font-weight:500;'>$arrow ".abs($perc)."% ($sign$diff)</span>";
}

// ---------------------------------------------------------------------
// Database
// ---------------------------------------------------------------------
function wpa_create_tables() {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();

    $table_stats = $wpdb->prefix . WPA_TABLE_STATS;
    $sql_stats = "CREATE TABLE $table_stats (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        visit_time datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
        visitor_hash varchar(64) NOT NULL,
        page_url varchar(255) NOT NULL,
        referrer varchar(255) DEFAULT '' NOT NULL,
        device varchar(20) DEFAULT 'desktop' NOT NULL,
        os varchar(50) DEFAULT '' NOT NULL,
        browser varchar(50) DEFAULT '' NOT NULL,
        utm_campaign varchar(100) DEFAULT '' NOT NULL,
        utm_source varchar(100) DEFAULT '' NOT NULL,
        utm_medium varchar(100) DEFAULT '' NOT NULL,
        event_type varchar(20) DEFAULT 'pageview' NOT NULL,
        event_name varchar(255) DEFAULT '' NOT NULL,
        event_value varchar(255) DEFAULT '' NOT NULL,
        country varchar(50) DEFAULT '' NOT NULL,
        time_on_page int(11) DEFAULT 0 NOT NULL,
        scroll_depth int(11) DEFAULT 0 NOT NULL,
        is_entrance tinyint(1) DEFAULT 0 NOT NULL,
        PRIMARY KEY  (id),
        KEY visit_time (visit_time),
        KEY visitor_hash (visitor_hash),
        KEY event_type_time (event_type, visit_time),
        KEY page_url_event (page_url(191), event_type)
    ) $charset_collate;";

    $table_daily = $wpdb->prefix . WPA_TABLE_DAILY;
    $sql_daily = "CREATE TABLE $table_daily (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        summary_date date NOT NULL,
        page_url varchar(255) NOT NULL,
        total_views int(11) DEFAULT 0 NOT NULL,
        unique_visitors int(11) DEFAULT 0 NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY date_url (summary_date, page_url(191))
    ) $charset_collate;";

    // Feature #11: meerdere conversiedoelen i.p.v. één losse optie
    $table_goals = $wpdb->prefix . WPA_TABLE_GOALS;
    $sql_goals = "CREATE TABLE $table_goals (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        name varchar(100) NOT NULL,
        url_pattern varchar(255) NOT NULL,
        PRIMARY KEY  (id)
    ) $charset_collate;";

    // Feature #12: conversietrechter (stappen)
    $table_funnels = $wpdb->prefix . WPA_TABLE_FUNNELS;
    $sql_funnels = "CREATE TABLE $table_funnels (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        step_order int(11) NOT NULL DEFAULT 0,
        name varchar(100) NOT NULL,
        url_pattern varchar(255) NOT NULL,
        PRIMARY KEY  (id)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql_stats);
    dbDelta($sql_daily);
    dbDelta($sql_goals);
    dbDelta($sql_funnels);
}

register_activation_hook(__FILE__, 'wpa_activate_plugin');
function wpa_activate_plugin() {
    wpa_create_tables();
    wpa_get_hash_secret();
    update_option('wpa_db_version', WPA_DB_VERSION);

    if (!wp_next_scheduled('wpa_daily_cleanup_event')) {
        wp_schedule_event(time(), 'daily', 'wpa_daily_cleanup_event');
    }
    if (!wp_next_scheduled('wpa_weekly_email_event')) {
        wp_schedule_event(time(), 'weekly', 'wpa_weekly_email_event');
    }
    // Feature #29: dagelijkse controle op afwijkend verkeer
    if (!wp_next_scheduled('wpa_anomaly_check_event')) {
        wp_schedule_event(time(), 'daily', 'wpa_anomaly_check_event');
    }

    // Feature #12: standaard Administrator-capability voor dashboardtoegang
    $admin_role = get_role('administrator');
    if ($admin_role && !$admin_role->has_cap('view_brink_analytics')) {
        $admin_role->add_cap('view_brink_analytics');
    }
}

register_deactivation_hook(__FILE__, 'wpa_deactivate_plugin');
function wpa_deactivate_plugin() {
    wp_clear_scheduled_hook('wpa_weekly_email_event');
    wp_clear_scheduled_hook('wpa_daily_cleanup_event');
    wp_clear_scheduled_hook('wpa_anomaly_check_event');
}

register_uninstall_hook(__FILE__, 'wpa_uninstall_plugin');
function wpa_uninstall_plugin() {
    foreach (wp_roles()->roles as $role_slug => $role_info) {
        $role_obj = get_role($role_slug);
        if ($role_obj && $role_obj->has_cap('view_brink_analytics')) {
            $role_obj->remove_cap('view_brink_analytics');
        }
    }
}

add_action('plugins_loaded', 'wpa_maybe_upgrade_db');
function wpa_maybe_upgrade_db() {
    if (get_option('wpa_db_version') !== WPA_DB_VERSION) {
        wpa_create_tables();
        update_option('wpa_db_version', WPA_DB_VERSION);
    }
    wpa_get_hash_secret();
}

// ---------------------------------------------------------------------
// REST API: bezoeker-tracking
// ---------------------------------------------------------------------
add_action('rest_api_init', function () {
    register_rest_route('brink-analytics/v1', '/track', array(
        'methods' => 'POST',
        'callback' => 'wpa_rest_track_visit',
        'permission_callback' => '__return_true',
    ));
    // Feature #34: publiek leesbaar (maar capability-beveiligd) statistieken-endpoint
    register_rest_route('brink-analytics/v1', '/stats', array(
        'methods' => 'GET',
        'callback' => 'wpa_rest_get_stats',
        'permission_callback' => function () {
            return current_user_can('view_brink_analytics');
        },
    ));
});

function wpa_rest_get_stats($request) {
    global $wpdb;
    $table = $wpdb->prefix . WPA_TABLE_STATS;
    $days = min(absint($request->get_param('days') ?: 7), 365);

    $views = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE visit_time >= DATE_SUB(NOW(), INTERVAL %d DAY) AND event_type='pageview'", $days));
    $visitors = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT visitor_hash) FROM $table WHERE visit_time >= DATE_SUB(NOW(), INTERVAL %d DAY) AND event_type='pageview'", $days));

    return new WP_REST_Response(array(
        'period_days' => $days,
        'total_views' => $views,
        'unique_visitors' => $visitors,
    ), 200);
}

// Feature #35: webhook afvuren bij een behaalde conversie
function wpa_maybe_fire_webhook($url, $visitor_hash) {
    $webhook_url = get_option('wpa_webhook_url', '');
    if (empty($webhook_url)) return;

    $goals = wpa_get_goals();
    foreach ($goals as $goal) {
        if (!empty($goal->url_pattern) && strpos($url, $goal->url_pattern) !== false) {
            wp_remote_post($webhook_url, array(
                'timeout' => 3,
                'blocking' => false,
                'headers' => array('Content-Type' => 'application/json'),
                'body' => wp_json_encode(array(
                    'event' => 'conversion',
                    'goal' => $goal->name,
                    'page_url' => $url,
                    'visitor_hash' => $visitor_hash,
                    'site' => home_url(),
                )),
            ));
            break;
        }
    }
}

function wpa_get_goals() {
    global $wpdb;
    $table = $wpdb->prefix . WPA_TABLE_GOALS;
    return $wpdb->get_results("SELECT * FROM $table ORDER BY id ASC");
}

function wpa_get_funnel_steps() {
    global $wpdb;
    $table = $wpdb->prefix . WPA_TABLE_FUNNELS;
    return $wpdb->get_results("SELECT * FROM $table ORDER BY step_order ASC");
}

function wpa_rest_track_visit($request) {
    global $wpdb;
    $table = $wpdb->prefix . WPA_TABLE_STATS;
    $params = $request->get_json_params();
    if (!is_array($params)) {
        $params = array();
    }

    $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field($_SERVER['HTTP_USER_AGENT']) : '';

    if ($user_agent === '' || preg_match('/(bot|crawl|spider|slurp|yahoo|mediapartners|headless|curl|wget|python-requests)/i', $user_agent)) {
        $blocks = get_option('wpa_bot_blocks', 0);
        update_option('wpa_bot_blocks', $blocks + 1);
        return new WP_REST_Response(array('success' => false, 'reason' => 'bot'), 200);
    }

    $event_type = isset($params['event_type']) ? sanitize_text_field($params['event_type']) : 'pageview';
    $allowed_event_types = array('pageview', 'ping', 'engagement', 'click', 'download', 'outbound', 'form_submit', 'click_map', 'form_field_exit', 'video_progress', 'web_vitals', 'ab_variant');
    if (!in_array($event_type, $allowed_event_types, true)) {
        return new WP_REST_Response(array('success' => false, 'reason' => 'invalid_event'), 400);
    }

    $url = isset($params['page_url']) ? esc_url_raw($params['page_url']) : '';
    $referrer = isset($params['referrer']) ? esc_url_raw($params['referrer']) : '';
    $event_name = isset($params['event_name']) ? sanitize_text_field($params['event_name']) : '';
    $event_value = isset($params['event_value']) ? sanitize_text_field($params['event_value']) : '';
    $time_on_page = isset($params['time_on_page']) ? min(absint($params['time_on_page']), 86400) : 0;
    $scroll_depth = isset($params['scroll_depth']) ? min(absint($params['scroll_depth']), 100) : 0;

    // Feature #39: developers kunnen een pagina server-side laten uitsluiten
    if ($url !== '' && apply_filters('wpa_exclude_page', false, $url)) {
        return new WP_REST_Response(array('success' => false, 'reason' => 'excluded_page'), 200);
    }

    $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field($_SERVER['REMOTE_ADDR']) : '127.0.0.1';

    $exclude_ips = get_option('wpa_exclude_ips', array());
    if (!empty($exclude_ips) && in_array($ip, $exclude_ips, true)) {
        return new WP_REST_Response(array('success' => false, 'reason' => 'excluded_ip'), 200);
    }

    $ip = wpa_anonymize_ip($ip);
    $country = isset($_SERVER['HTTP_CF_IPCOUNTRY']) ? sanitize_text_field($_SERVER['HTTP_CF_IPCOUNTRY']) : 'Unknown';

    $secret = wpa_get_hash_secret();
    $rotating_period = date('W-Y');
    $hash = hash_hmac('sha256', $ip . $user_agent . $rotating_period, $secret);

    $rl_key = 'wpa_rl_' . $hash;
    $rl_count = (int) get_transient($rl_key);
    if ($rl_count > 30) {
        return new WP_REST_Response(array('success' => false, 'reason' => 'rate_limited'), 429);
    }
    set_transient($rl_key, $rl_count + 1, MINUTE_IN_SECONDS);

    if ($event_type === 'ping') {
        $wpdb->query($wpdb->prepare("UPDATE $table SET visit_time = %s WHERE visitor_hash = %s ORDER BY id DESC LIMIT 1", current_time('mysql'), $hash));
        return new WP_REST_Response(array('success' => true), 200);
    }

    $user_agent_lower = strtolower($user_agent);
    $device = (strpos($user_agent_lower, 'mobile') !== false) ? 'Mobiel' : ((strpos($user_agent_lower, 'tablet') !== false || strpos($user_agent_lower, 'ipad') !== false) ? 'Tablet' : 'Desktop');
    $os = 'Overig';
    if (strpos($user_agent_lower, 'windows') !== false) $os = 'Windows';
    elseif (strpos($user_agent_lower, 'mac os') !== false) $os = 'macOS';
    elseif (strpos($user_agent_lower, 'android') !== false) $os = 'Android';
    elseif (strpos($user_agent_lower, 'iphone') !== false || strpos($user_agent_lower, 'ipad') !== false) $os = 'iOS';
    elseif (strpos($user_agent_lower, 'linux') !== false) $os = 'Linux';
    $browser = 'Overig';
    if (strpos($user_agent_lower, 'edg') !== false) $browser = 'Edge';
    elseif (strpos($user_agent_lower, 'chrome') !== false) $browser = 'Chrome';
    elseif (strpos($user_agent_lower, 'firefox') !== false) $browser = 'Firefox';
    elseif (strpos($user_agent_lower, 'safari') !== false) $browser = 'Safari';

    $utm_source = isset($params['utm_source']) ? sanitize_text_field($params['utm_source']) : '';
    $utm_medium = isset($params['utm_medium']) ? sanitize_text_field($params['utm_medium']) : '';
    $utm_campaign = isset($params['utm_campaign']) ? sanitize_text_field($params['utm_campaign']) : '';

    $is_entrance = 0;
    if ($event_type === 'pageview') {
        $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE visitor_hash = %s AND visit_time >= %s LIMIT 1", $hash, date('Y-m-d H:i:s', current_time('timestamp') - 1800)));
        $is_entrance = $existing ? 0 : 1;
    }

    $wpdb->insert($table, array(
        'visit_time' => current_time('mysql'),
        'visitor_hash' => $hash,
        'page_url' => $url,
        'referrer' => $referrer,
        'device' => $device,
        'os' => $os,
        'browser' => $browser,
        'utm_campaign' => $utm_campaign,
        'utm_source' => $utm_source,
        'utm_medium' => $utm_medium,
        'event_type' => $event_type,
        'event_name' => $event_name,
        'event_value' => $event_value,
        'country' => $country,
        'time_on_page' => $time_on_page,
        'scroll_depth' => $scroll_depth,
        'is_entrance' => $is_entrance,
    ));

    if ($event_type === 'pageview') {
        wpa_maybe_fire_webhook($url, $hash);
    }

    return new WP_REST_Response(array('success' => true), 200);
}

// Feature #4 en #5: 404's en site-zoekopdrachten server-side registreren
add_action('template_redirect', 'wpa_track_404_and_search');
function wpa_track_404_and_search() {
    if (is_admin() || current_user_can('manage_options')) return;
    global $wpdb;
    $table = $wpdb->prefix . WPA_TABLE_STATS;

    if (is_404()) {
        $wpdb->insert($table, array(
            'visit_time' => current_time('mysql'),
            'visitor_hash' => 'server',
            'page_url' => esc_url_raw(home_url(add_query_arg(array(), $_SERVER['REQUEST_URI'] ?? ''))),
            'referrer' => isset($_SERVER['HTTP_REFERER']) ? esc_url_raw(wp_unslash($_SERVER['HTTP_REFERER'])) : '',
            'event_type' => '404',
            'event_name' => '404',
        ));
    } elseif (is_search()) {
        $query = sanitize_text_field(get_search_query());
        if ($query !== '') {
            $wpdb->insert($table, array(
                'visit_time' => current_time('mysql'),
                'visitor_hash' => 'server',
                'page_url' => esc_url_raw(home_url('/?s=' . rawurlencode($query))),
                'event_type' => 'site_search',
                'event_name' => $query,
            ));
        }
    }
}

// Feature #13: WooCommerce-omzet koppelen aan een conversie (alleen als WooCommerce actief is)
add_action('woocommerce_thankyou', 'wpa_track_woocommerce_order');
function wpa_track_woocommerce_order($order_id) {
    if (!$order_id || !function_exists('wc_get_order')) return;
    if (get_post_meta($order_id, '_wpa_tracked', true)) return; // voorkom dubbeltelling bij herladen

    $order = wc_get_order($order_id);
    if (!$order) return;

    global $wpdb;
    $table = $wpdb->prefix . WPA_TABLE_STATS;
    $wpdb->insert($table, array(
        'visit_time' => current_time('mysql'),
        'visitor_hash' => 'server',
        'page_url' => esc_url_raw(home_url('/checkout/order-received/')),
        'event_type' => 'purchase',
        'event_name' => 'order_' . $order_id,
        'event_value' => (string) $order->get_total(),
    ));
    update_post_meta($order_id, '_wpa_tracked', 1);
}

// ---------------------------------------------------------------------
// Front-end tracking script
// ---------------------------------------------------------------------
add_action('wp_footer', 'wpa_insert_tracking_script');
function wpa_insert_tracking_script() {
    if (current_user_can('manage_options') || is_admin()) return;

    if (is_user_logged_in()) {
        $exclude_roles = get_option('wpa_exclude_roles', array());
        if (!empty($exclude_roles)) {
            $current_user = wp_get_current_user();
            if (array_intersect($exclude_roles, (array) $current_user->roles)) {
                return;
            }
        }
    }

    $exclude_ips = get_option('wpa_exclude_ips', array());
    if (!empty($exclude_ips) && isset($_SERVER['REMOTE_ADDR'])) {
        if (in_array(sanitize_text_field($_SERVER['REMOTE_ADDR']), $exclude_ips, true)) {
            return;
        }
    }

    global $wp;
    $current_url = home_url(add_query_arg(array(), $wp->request));
    if (apply_filters('wpa_exclude_page', false, $current_url)) {
        return;
    }

    if (!apply_filters('wpa_should_track', true)) {
        return;
    }

    $api_url = esc_url_raw(rest_url('brink-analytics/v1/track'));

    // Feature-toggles (standaard uit, om de plugin licht te houden)
    $enable_heatmap = (bool) get_option('wpa_enable_heatmap', false);
    $enable_form_tracking = (bool) get_option('wpa_enable_form_tracking', false);
    $enable_video_tracking = (bool) get_option('wpa_enable_video_tracking', false);
    $enable_web_vitals = (bool) get_option('wpa_enable_web_vitals', false);
    ?>
    <script>
    (function() {
        if (navigator.doNotTrack === '1' || window.doNotTrack === '1') return;

        let maxScroll = 0;
        const apiEndpoint = <?php echo wp_json_encode($api_url); ?>;
        const params = new URLSearchParams(window.location.search);

        function send(payload, useBeacon) {
            const body = JSON.stringify(Object.assign({ page_url: window.location.href }, payload));
            if (useBeacon && navigator.sendBeacon) {
                navigator.sendBeacon(apiEndpoint, new Blob([body], { type: 'application/json' }));
            } else {
                fetch(apiEndpoint, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: body, keepalive: true });
            }
        }

        send({
            event_type: 'pageview',
            referrer: document.referrer,
            utm_source: params.get('utm_source') || '',
            utm_medium: params.get('utm_medium') || '',
            utm_campaign: params.get('utm_campaign') || ''
        });

        window.addEventListener('scroll', function() {
            const scrolled = Math.round((window.scrollY / (document.body.scrollHeight - window.innerHeight)) * 100);
            if (scrolled > maxScroll) maxScroll = Math.min(scrolled, 100);
        }, { passive: true });

        // Feature #16: outbound- en downloadlinks
        document.addEventListener('click', function(e) {
            const link = e.target.closest('a');
            if (!link || !link.href) return;
            if (link.hostname && link.hostname !== window.location.hostname) {
                send({ event_type: 'outbound', event_name: link.href }, true);
            } else if (/\.(pdf|zip|docx?|xlsx?|pptx?)$/i.test(link.pathname)) {
                send({ event_type: 'download', event_name: link.pathname }, true);
            }
            // Feature #15: A/B-variant tracking via data-wpa-variant attribuut
            const variantEl = e.target.closest('[data-wpa-variant]');
            if (variantEl) {
                send({ event_type: 'ab_variant', event_name: variantEl.getAttribute('data-wpa-variant') }, true);
            }
        });

        <?php if ($enable_heatmap): ?>
        // Feature #1: click-heatmap (grid-gebaseerd, geen exacte pixel-opslag = privacyvriendelijker)
        document.addEventListener('click', function(e) {
            const gx = Math.min(4, Math.floor((e.clientX / window.innerWidth) * 5));
            const gy = Math.min(4, Math.floor((e.clientY / window.innerHeight) * 5));
            send({ event_type: 'click_map', event_value: gx + ',' + gy }, true);
        });
        <?php endif; ?>

        <?php if ($enable_form_tracking): ?>
        // Feature #6: formulierveld-analyse (alleen veldnaam, nooit de waarde)
        let lastField = '';
        document.addEventListener('focusout', function(e) {
            if (e.target.closest('form') && e.target.name) {
                lastField = e.target.name;
            }
        });
        document.addEventListener('visibilitychange', function() {
            if (document.visibilityState === 'hidden' && lastField) {
                send({ event_type: 'form_field_exit', event_name: lastField }, true);
                lastField = '';
            }
        });
        document.addEventListener('submit', function() { lastField = ''; });
        <?php endif; ?>

        <?php if ($enable_video_tracking): ?>
        // Feature #10: video-engagement (YouTube/Vimeo embeds via postMessage)
        const videoMarks = {};
        window.addEventListener('message', function(e) {
            try {
                const data = JSON.parse(e.data);
                if (data.event === 'infoDelivery' && data.info && typeof data.info.percent === 'number') {
                    const pct = Math.floor(data.info.percent * 100 / 25) * 25;
                    const key = (data.id || 'video') + '-' + pct;
                    if (pct > 0 && !videoMarks[key]) {
                        videoMarks[key] = true;
                        send({ event_type: 'video_progress', event_name: data.id || 'video', event_value: String(pct) }, true);
                    }
                }
            } catch (err) {}
        });
        <?php endif; ?>

        <?php if ($enable_web_vitals): ?>
        // Feature #37: Core Web Vitals (native PerformanceObserver, geen externe library)
        try {
            let lcp = 0, cls = 0;
            new PerformanceObserver(function(list) {
                const entries = list.getEntries();
                lcp = entries[entries.length - 1].renderTime || entries[entries.length - 1].loadTime || 0;
            }).observe({ type: 'largest-contentful-paint', buffered: true });
            new PerformanceObserver(function(list) {
                for (const entry of list.getEntries()) {
                    if (!entry.hadRecentInput) cls += entry.value;
                }
            }).observe({ type: 'layout-shift', buffered: true });
            window.addEventListener('visibilitychange', function() {
                if (document.visibilityState === 'hidden' && lcp > 0) {
                    send({ event_type: 'web_vitals', event_value: JSON.stringify({ lcp: Math.round(lcp), cls: Math.round(cls * 1000) / 1000 }) }, true);
                }
            });
        } catch (err) {}
        <?php endif; ?>

        setInterval(function() {
            if (document.visibilityState === 'visible') {
                send({ event_type: 'ping' }, true);
            }
        }, 120000);

        window.addEventListener('visibilitychange', function() {
            if (document.visibilityState === 'hidden') {
                send({ event_type: 'engagement', time_on_page: Math.round(performance.now() / 1000), scroll_depth: maxScroll }, true);
            }
        });
    })();
    </script>
    <?php
}

// ---------------------------------------------------------------------
// Admin menu & assets
// ---------------------------------------------------------------------
add_action('admin_menu', 'wpa_add_admin_menu');
function wpa_add_admin_menu() {
    $hook = add_menu_page('Brink Analytics', 'Brink Analytics', 'view_brink_analytics', 'brink-analytics', 'wpa_render_dashboard', 'dashicons-chart-area', 2);
    add_action('load-' . $hook, function () {
        add_action('admin_enqueue_scripts', 'wpa_enqueue_dashboard_assets');
    });
}

function wpa_enqueue_dashboard_assets() {
    wp_enqueue_script(
        'wpa-chartjs',
        'https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js',
        array(),
        '4.4.4',
        true
    );
}

// Feature #23: klein widgetje op het standaard WordPress-dashboard
add_action('wp_dashboard_setup', 'wpa_add_dashboard_widget');
function wpa_add_dashboard_widget() {
    if (!current_user_can('view_brink_analytics')) return;
    wp_add_dashboard_widget('wpa_dashboard_widget', 'Brink Analytics — vandaag', 'wpa_render_dashboard_widget');
}

function wpa_render_dashboard_widget() {
    global $wpdb;
    $table = $wpdb->prefix . WPA_TABLE_STATS;
    $live_time_limit = date('Y-m-d H:i:s', current_time('timestamp') - 300);
    $live = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT visitor_hash) FROM $table WHERE visit_time >= %s AND event_type='pageview'", $live_time_limit));
    $today_views = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE DATE(visit_time) = CURDATE() AND event_type='pageview'");
    $today_visitors = (int) $wpdb->get_var("SELECT COUNT(DISTINCT visitor_hash) FROM $table WHERE DATE(visit_time) = CURDATE() AND event_type='pageview'");
    echo '<p><strong>' . esc_html($live) . '</strong> live bezoekers nu &middot; <strong>' . esc_html($today_views) . '</strong> weergaven vandaag &middot; <strong>' . esc_html($today_visitors) . '</strong> unieke bezoekers vandaag</p>';
    echo '<p><a href="' . esc_url(admin_url('admin.php?page=brink-analytics')) . '">Bekijk volledig dashboard &rarr;</a></p>';
}

// Feature #38: netwerk-breed overzicht bij WordPress Multisite
add_action('network_admin_menu', 'wpa_add_network_admin_menu');
function wpa_add_network_admin_menu() {
    add_menu_page('Brink Analytics (netwerk)', 'Brink Analytics', 'manage_network', 'brink-analytics-network', 'wpa_render_network_dashboard', 'dashicons-chart-area', 2);
}

function wpa_render_network_dashboard() {
    if (!current_user_can('manage_network')) {
        wp_die(esc_html__('Geen toestemming.', 'brink-analytics'));
    }
    echo '<div class="wrap"><h1>Brink Analytics — netwerkoverzicht</h1><table class="widefat"><thead><tr><th>Site</th><th>Weergaven (7 dagen)</th><th>Unieke bezoekers (7 dagen)</th></tr></thead><tbody>';
    $sites = get_sites(array('number' => 200));
    foreach ($sites as $site) {
        switch_to_blog($site->blog_id);
        global $wpdb;
        $table = $wpdb->prefix . WPA_TABLE_STATS;
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") === $table) {
            $views = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE visit_time >= DATE_SUB(NOW(), INTERVAL 7 DAY) AND event_type='pageview'");
            $visitors = (int) $wpdb->get_var("SELECT COUNT(DISTINCT visitor_hash) FROM $table WHERE visit_time >= DATE_SUB(NOW(), INTERVAL 7 DAY) AND event_type='pageview'");
        } else {
            $views = 0; $visitors = 0;
        }
        echo '<tr><td>' . esc_html(get_bloginfo('name')) . '</td><td>' . esc_html($views) . '</td><td>' . esc_html($visitors) . '</td></tr>';
        restore_current_blog();
    }
    echo '</tbody></table></div>';
}

// ---------------------------------------------------------------------
// Cronjobs: rapportage, opschoning, afwijkingsdetectie
// ---------------------------------------------------------------------
add_action('wpa_weekly_email_event', 'wpa_send_weekly_email');
function wpa_send_weekly_email() {
    // Feature #28: e-mailfrequentie instelbaar; deze cron is de vaste "klok",
    // maar we versturen alleen daadwerkelijk als de frequentie dat toestaat.
    $frequency = get_option('wpa_email_frequency', 'weekly');
    if ($frequency === 'never') return;
    if ($frequency === 'daily' && get_transient('wpa_last_email_sent')) return;
    if ($frequency === 'monthly' && (int) date('j') !== 1) return;

    global $wpdb;
    $email = get_option('wpa_report_email', '');
    if (empty($email)) return;

    $table = $wpdb->prefix . WPA_TABLE_STATS;
    $views = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE visit_time >= DATE_SUB(NOW(), INTERVAL 7 DAY) AND event_type='pageview'");
    $visitors = (int) $wpdb->get_var("SELECT COUNT(DISTINCT visitor_hash) FROM $table WHERE visit_time >= DATE_SUB(NOW(), INTERVAL 7 DAY) AND event_type='pageview'");
    $top_page = $wpdb->get_var("SELECT page_url FROM $table WHERE visit_time >= DATE_SUB(NOW(), INTERVAL 7 DAY) AND event_type='pageview' GROUP BY page_url ORDER BY COUNT(*) DESC LIMIT 1");

    $body  = "Hoi,\n\nHier is je samenvatting van Brink Multimedia Analytics:\n\n";
    $body .= "- Weergaven afgelopen 7 dagen: " . number_format_i18n($views) . "\n";
    $body .= "- Unieke bezoekers afgelopen 7 dagen: " . number_format_i18n($visitors) . "\n";
    if ($top_page) {
        $body .= "- Meest bezochte pagina: " . $top_page . "\n";
    }
    $body .= "\nBekijk het volledige dashboard: " . admin_url('admin.php?page=brink-analytics') . "\n";

    wp_mail($email, 'Je Brink Analytics Rapport', $body);
    set_transient('wpa_last_email_sent', 1, DAY_IN_SECONDS - 60);
}

add_action('wpa_daily_cleanup_event', 'wpa_run_daily_cleanup');
function wpa_run_daily_cleanup() {
    global $wpdb;
    $table = $wpdb->prefix . WPA_TABLE_STATS;
    $table_daily = $wpdb->prefix . WPA_TABLE_DAILY;

    // Feature #27: aparte bewaartermijn voor ruwe data vs. geaggregeerde data
    $retention_days = (int) get_option('wpa_retention_days_raw', 90);
    if ($retention_days < 30) $retention_days = 30;

    $rows_to_aggregate = $wpdb->get_results($wpdb->prepare(
        "SELECT DATE(visit_time) as d, page_url, COUNT(*) as views, COUNT(DISTINCT visitor_hash) as uniques
         FROM $table
         WHERE visit_time < DATE_SUB(CURDATE(), INTERVAL %d DAY) AND event_type = 'pageview'
         GROUP BY DATE(visit_time), page_url",
        $retention_days
    ));

    foreach ($rows_to_aggregate as $row) {
        $wpdb->query($wpdb->prepare(
            "INSERT INTO $table_daily (summary_date, page_url, total_views, unique_visitors)
             VALUES (%s, %s, %d, %d)
             ON DUPLICATE KEY UPDATE total_views = total_views + VALUES(total_views), unique_visitors = VALUES(unique_visitors)",
            $row->d, $row->page_url, $row->views, $row->uniques
        ));
    }

    $wpdb->query($wpdb->prepare(
        "DELETE FROM $table WHERE visit_time < DATE_SUB(CURDATE(), INTERVAL %d DAY)",
        $retention_days
    ));

    // Geaggregeerde samenvatting mag desgewenst langer bewaard blijven
    $summary_retention = (int) get_option('wpa_retention_days_summary', 730);
    if ($summary_retention > 0) {
        $wpdb->query($wpdb->prepare(
            "DELETE FROM $table_daily WHERE summary_date < DATE_SUB(CURDATE(), INTERVAL %d DAY)",
            $summary_retention
        ));
    }
}

// Feature #29: afwijkingsmeldingen bij een plotselinge piek of dip
add_action('wpa_anomaly_check_event', 'wpa_run_anomaly_check');
function wpa_run_anomaly_check() {
    global $wpdb;
    $table = $wpdb->prefix . WPA_TABLE_STATS;
    $email = get_option('wpa_report_email', get_option('admin_email'));
    if (empty($email)) return;

    $yesterday = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE DATE(visit_time) = DATE_SUB(CURDATE(), INTERVAL 1 DAY) AND event_type='pageview'");
    $avg_7d = (float) $wpdb->get_var("SELECT AVG(daily) FROM (SELECT COUNT(*) as daily FROM $table WHERE visit_time >= DATE_SUB(CURDATE(), INTERVAL 8 DAY) AND visit_time < DATE_SUB(CURDATE(), INTERVAL 1 DAY) AND event_type='pageview' GROUP BY DATE(visit_time)) t");

    if ($avg_7d < 10) return; // te weinig data om betrouwbaar iets over te zeggen

    $deviation = (($yesterday - $avg_7d) / $avg_7d) * 100;
    if (abs($deviation) < 50) return;

    $richting = $deviation > 0 ? 'piek' : 'daling';
    $body = "Brink Analytics signaleert een ongebruikelijke $richting in het verkeer.\n\n";
    $body .= "Gisteren: " . number_format_i18n($yesterday) . " weergaven\n";
    $body .= "Gemiddeld (7 dagen ervoor): " . number_format_i18n(round($avg_7d)) . " weergaven\n";
    $body .= "Afwijking: " . round($deviation) . "%\n\n";
    $body .= "Bekijk het dashboard: " . admin_url('admin.php?page=brink-analytics');

    wp_mail($email, "Brink Analytics: ongebruikelijke $richting in verkeer", $body);
}

// ---------------------------------------------------------------------
// Feature #33: WP-CLI commando's
// ---------------------------------------------------------------------
if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command('brink-analytics cleanup', function () {
        wpa_run_daily_cleanup();
        WP_CLI::success('Opschoning uitgevoerd.');
    });

    WP_CLI::add_command('brink-analytics export', function ($args, $assoc_args) {
        global $wpdb;
        $table = $wpdb->prefix . WPA_TABLE_STATS;
        $rows = $wpdb->get_results("SELECT * FROM $table ORDER BY visit_time DESC LIMIT 10000", ARRAY_A);
        $path = isset($assoc_args['path']) ? $assoc_args['path'] : 'brink-analytics-export.csv';
        $fp = fopen($path, 'w');
        if (!$fp) {
            WP_CLI::error('Kon bestand niet wegschrijven: ' . $path);
            return;
        }
        if (!empty($rows)) {
            fputcsv($fp, array_keys($rows[0]));
            foreach ($rows as $row) {
                fputcsv($fp, $row);
            }
        }
        fclose($fp);
        WP_CLI::success('Geëxporteerd naar ' . $path);
    });
}

// ---------------------------------------------------------------------
// CSV-export
// ---------------------------------------------------------------------
add_action('admin_init', 'wpa_handle_csv_export');
function wpa_handle_csv_export() {
    if (
        isset($_GET['wpa_export']) &&
        current_user_can('manage_options') &&
        isset($_GET['_wpnonce']) &&
        wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'wpa_export_csv')
    ) {
        global $wpdb;
        $table = $wpdb->prefix . WPA_TABLE_STATS;
        $rows = $wpdb->get_results("SELECT * FROM $table ORDER BY visit_time DESC LIMIT 50000", ARRAY_A);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=brink-analytics-export-' . date('Y-m-d') . '.csv');
        $out = fopen('php://output', 'w');
        if (!empty($rows)) {
            fputcsv($out, array_keys($rows[0]));
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
        }
        fclose($out);
        exit;
    }
}

// Feature #31: instellingen exporteren/importeren als JSON
add_action('admin_init', 'wpa_handle_settings_export');
function wpa_handle_settings_export() {
    if (isset($_GET['wpa_export_settings']) && current_user_can('manage_options') &&
        isset($_GET['_wpnonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'wpa_export_settings')
    ) {
        $settings = array(
            'wpa_goal_url' => get_option('wpa_goal_url'),
            'wpa_report_email' => get_option('wpa_report_email'),
            'wpa_exclude_roles' => get_option('wpa_exclude_roles', array()),
            'wpa_exclude_ips' => get_option('wpa_exclude_ips', array()),
            'wpa_dashboard_roles' => get_option('wpa_dashboard_roles', array()),
            'wpa_anonymize_ip' => get_option('wpa_anonymize_ip', true),
            'wpa_retention_days_raw' => get_option('wpa_retention_days_raw', 90),
            'wpa_retention_days_summary' => get_option('wpa_retention_days_summary', 730),
            'wpa_email_frequency' => get_option('wpa_email_frequency', 'weekly'),
            'wpa_webhook_url' => get_option('wpa_webhook_url', ''),
            'wpa_enable_heatmap' => get_option('wpa_enable_heatmap', false),
            'wpa_enable_form_tracking' => get_option('wpa_enable_form_tracking', false),
            'wpa_enable_video_tracking' => get_option('wpa_enable_video_tracking', false),
            'wpa_enable_web_vitals' => get_option('wpa_enable_web_vitals', false),
            'wpa_campaign_costs' => get_option('wpa_campaign_costs', array()),
        );
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename=brink-analytics-settings-' . date('Y-m-d') . '.json');
        echo wp_json_encode($settings, JSON_PRETTY_PRINT);
        exit;
    }
}

// ---------------------------------------------------------------------
// Dashboard
// ---------------------------------------------------------------------
function wpa_render_dashboard() {
    if (!current_user_can('view_brink_analytics')) {
        wp_die(esc_html__('Je hebt geen toestemming om deze pagina te bekijken.', 'brink-analytics'));
    }
    global $wpdb;
    $table = $wpdb->prefix . WPA_TABLE_STATS;
    $can_manage = current_user_can('manage_options');

    // --- Instellingen opslaan ---
    if ($can_manage && isset($_POST['wpa_save_settings'])) {
        if (!isset($_POST['wpa_settings_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['wpa_settings_nonce'])), 'wpa_save_settings_action')) {
            wp_die(esc_html__('Beveiligingscontrole mislukt. Ververs de pagina en probeer het opnieuw.', 'brink-analytics'));
        }
        update_option('wpa_report_email', sanitize_email(wp_unslash($_POST['wpa_report_email'])));
        update_option('wpa_webhook_url', esc_url_raw(wp_unslash($_POST['wpa_webhook_url'] ?? '')));
        update_option('wpa_email_frequency', in_array($_POST['wpa_email_frequency'] ?? '', array('daily','weekly','monthly','never'), true) ? sanitize_key($_POST['wpa_email_frequency']) : 'weekly');
        update_option('wpa_anonymize_ip', isset($_POST['wpa_anonymize_ip']));
        update_option('wpa_retention_days_raw', max(30, absint($_POST['wpa_retention_days_raw'] ?? 90)));
        update_option('wpa_retention_days_summary', max(0, absint($_POST['wpa_retention_days_summary'] ?? 730)));
        update_option('wpa_enable_heatmap', isset($_POST['wpa_enable_heatmap']));
        update_option('wpa_enable_form_tracking', isset($_POST['wpa_enable_form_tracking']));
        update_option('wpa_enable_video_tracking', isset($_POST['wpa_enable_video_tracking']));
        update_option('wpa_enable_web_vitals', isset($_POST['wpa_enable_web_vitals']));

        $valid_roles = array_keys(wp_roles()->roles);
        $posted_exclude_roles = isset($_POST['wpa_exclude_roles']) && is_array($_POST['wpa_exclude_roles']) ? array_map('sanitize_key', wp_unslash($_POST['wpa_exclude_roles'])) : array();
        update_option('wpa_exclude_roles', array_values(array_intersect($posted_exclude_roles, $valid_roles)));

        $raw_ips = isset($_POST['wpa_exclude_ips']) ? sanitize_textarea_field(wp_unslash($_POST['wpa_exclude_ips'])) : '';
        $ip_lines = array_filter(array_map('trim', explode("\n", $raw_ips)));
        $clean_ips = array_values(array_filter($ip_lines, function ($ip) { return filter_var($ip, FILTER_VALIDATE_IP) !== false; }));
        update_option('wpa_exclude_ips', $clean_ips);

        $posted_dashboard_roles = isset($_POST['wpa_dashboard_roles']) && is_array($_POST['wpa_dashboard_roles']) ? array_map('sanitize_key', wp_unslash($_POST['wpa_dashboard_roles'])) : array();
        $posted_dashboard_roles = array_values(array_intersect($posted_dashboard_roles, $valid_roles));
        foreach (wp_roles()->roles as $role_slug => $role_info) {
            if ($role_slug === 'administrator') continue;
            $role_obj = get_role($role_slug);
            if (!$role_obj) continue;
            if (in_array($role_slug, $posted_dashboard_roles, true)) { $role_obj->add_cap('view_brink_analytics'); }
            else { $role_obj->remove_cap('view_brink_analytics'); }
        }
        update_option('wpa_dashboard_roles', $posted_dashboard_roles);

        // Feature #16: ROI-kosten per UTM-campagne
        $costs = array();
        if (!empty($_POST['wpa_campaign_name']) && is_array($_POST['wpa_campaign_name'])) {
            foreach ($_POST['wpa_campaign_name'] as $i => $cname) {
                $cname = sanitize_text_field(wp_unslash($cname));
                $cost = isset($_POST['wpa_campaign_cost'][$i]) ? floatval($_POST['wpa_campaign_cost'][$i]) : 0;
                if ($cname !== '') $costs[$cname] = $cost;
            }
        }
        update_option('wpa_campaign_costs', $costs);

        echo '<div class="updated"><p>Instellingen opgeslagen.</p></div>';
    }

    // --- Doelen (Feature #11) opslaan ---
    if ($can_manage && isset($_POST['wpa_save_goals']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['wpa_goals_nonce'] ?? '')), 'wpa_save_goals_action')) {
        $goals_table = $wpdb->prefix . WPA_TABLE_GOALS;
        $wpdb->query("TRUNCATE TABLE $goals_table");
        if (!empty($_POST['goal_name']) && is_array($_POST['goal_name'])) {
            foreach ($_POST['goal_name'] as $i => $name) {
                $name = sanitize_text_field(wp_unslash($name));
                $pattern = isset($_POST['goal_url'][$i]) ? sanitize_text_field(wp_unslash($_POST['goal_url'][$i])) : '';
                if ($name !== '' && $pattern !== '') {
                    $wpdb->insert($goals_table, array('name' => $name, 'url_pattern' => $pattern));
                }
            }
        }
        echo '<div class="updated"><p>Doelen opgeslagen.</p></div>';
    }

    // --- Trechter (Feature #12) opslaan ---
    if ($can_manage && isset($_POST['wpa_save_funnel']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['wpa_funnel_nonce'] ?? '')), 'wpa_save_funnel_action')) {
        $funnel_table = $wpdb->prefix . WPA_TABLE_FUNNELS;
        $wpdb->query("TRUNCATE TABLE $funnel_table");
        if (!empty($_POST['funnel_name']) && is_array($_POST['funnel_name'])) {
            foreach ($_POST['funnel_name'] as $i => $name) {
                $name = sanitize_text_field(wp_unslash($name));
                $pattern = isset($_POST['funnel_url'][$i]) ? sanitize_text_field(wp_unslash($_POST['funnel_url'][$i])) : '';
                if ($name !== '' && $pattern !== '') {
                    $wpdb->insert($funnel_table, array('step_order' => $i, 'name' => $name, 'url_pattern' => $pattern));
                }
            }
        }
        echo '<div class="updated"><p>Trechter opgeslagen.</p></div>';
    }

    // --- Instellingen importeren (Feature #31) ---
    if ($can_manage && isset($_POST['wpa_import_settings']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['wpa_import_nonce'] ?? '')), 'wpa_import_settings_action')) {
        if (!empty($_FILES['wpa_import_file']['tmp_name']) && is_uploaded_file($_FILES['wpa_import_file']['tmp_name'])) {
            $json = file_get_contents($_FILES['wpa_import_file']['tmp_name']);
            $data = json_decode($json, true);
            if (is_array($data)) {
                $allowed_keys = array('wpa_goal_url','wpa_report_email','wpa_exclude_roles','wpa_exclude_ips','wpa_dashboard_roles','wpa_anonymize_ip','wpa_retention_days_raw','wpa_retention_days_summary','wpa_email_frequency','wpa_webhook_url','wpa_enable_heatmap','wpa_enable_form_tracking','wpa_enable_video_tracking','wpa_enable_web_vitals','wpa_campaign_costs');
                foreach ($allowed_keys as $key) {
                    if (array_key_exists($key, $data)) {
                        update_option($key, $data[$key]);
                    }
                }
                echo '<div class="updated"><p>Instellingen geïmporteerd.</p></div>';
            } else {
                echo '<div class="error"><p>Kon het bestand niet lezen. Controleer of het een geldig export-bestand is.</p></div>';
            }
        }
    }

    $active_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'overzicht';
    $tabs = array(
        'overzicht' => 'Overzicht',
        'trechter' => 'Trechter & doelen',
        'kanalen' => 'Kanalen & gedrag',
        'privacy' => 'Privacy & toegang',
        'instellingen' => 'Instellingen',
        'systeem' => 'Systeem',
    );
    ?>
    <div class="wrap">
        <h1>Brink Multimedia Analytics</h1>
        <h2 class="nav-tab-wrapper">
            <?php foreach ($tabs as $slug => $label): ?>
                <a href="<?php echo esc_url(admin_url('admin.php?page=brink-analytics&tab=' . $slug)); ?>" class="nav-tab <?php echo $active_tab === $slug ? 'nav-tab-active' : ''; ?>"><?php echo esc_html($label); ?></a>
            <?php endforeach; ?>
        </h2>
        <div style="margin-top:20px;">
        <?php
        switch ($active_tab) {
            case 'trechter': wpa_render_tab_trechter($wpdb, $table, $can_manage); break;
            case 'kanalen': wpa_render_tab_kanalen($wpdb, $table); break;
            case 'privacy': wpa_render_tab_privacy($can_manage); break;
            case 'instellingen': wpa_render_tab_instellingen($can_manage); break;
            case 'systeem': wpa_render_tab_systeem($wpdb, $table); break;
            default: wpa_render_tab_overzicht($wpdb, $table); break;
        }
        ?>
        </div>
    </div>
    <?php
}

function wpa_render_tab_overzicht($wpdb, $table) {
    $range = isset($_GET['range']) ? sanitize_text_field($_GET['range']) : '7';
    $c_start = isset($_GET['c_start']) ? sanitize_text_field($_GET['c_start']) : '';
    $c_end = isset($_GET['c_end']) ? sanitize_text_field($_GET['c_end']) : '';

    if ($range === 'custom' && $c_start && $c_end) {
        $where = $wpdb->prepare('visit_time BETWEEN %s AND %s', $c_start . ' 00:00:00', $c_end . ' 23:59:59');
        $days_span = (strtotime($c_end) - strtotime($c_start)) / DAY_IN_SECONDS + 1;
        $prev_start = date('Y-m-d', strtotime($c_start) - $days_span * DAY_IN_SECONDS);
        $prev_end = date('Y-m-d', strtotime($c_start) - DAY_IN_SECONDS);
        $prev_where = $wpdb->prepare('visit_time BETWEEN %s AND %s', $prev_start . ' 00:00:00', $prev_end . ' 23:59:59');
    } elseif ($range === 'all') {
        $where = '1=1';
        $prev_where = '1=0';
    } else {
        $days = (int) $range;
        if ($days <= 0) $days = 7;
        $where = $wpdb->prepare('visit_time >= DATE_SUB(NOW(), INTERVAL %d DAY)', $days);
        $prev_where = $wpdb->prepare('visit_time BETWEEN DATE_SUB(NOW(), INTERVAL %d DAY) AND DATE_SUB(NOW(), INTERVAL %d DAY)', $days * 2, $days);
    }

    $live_time_limit = date('Y-m-d H:i:s', current_time('timestamp') - 300);
    $live_visitors = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT visitor_hash) FROM $table WHERE visit_time >= %s AND event_type='pageview'", $live_time_limit));

    $total_views = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE $where AND event_type='pageview'");
    $unique_visitors = (int) $wpdb->get_var("SELECT COUNT(DISTINCT visitor_hash) FROM $table WHERE $where AND event_type='pageview'");
    $prev_views = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE $prev_where AND event_type='pageview'");

    $chart_rows = $wpdb->get_results("SELECT DATE(visit_time) as d, COUNT(DISTINCT visitor_hash) as c FROM $table WHERE $where AND event_type='pageview' GROUP BY DATE(visit_time) ORDER BY d ASC");
    $chart_labels = array(); $chart_data = array();
    foreach ($chart_rows as $row) { $chart_labels[] = date('d-m', strtotime($row->d)); $chart_data[] = (int) $row->c; }

    $hour_rows = $wpdb->get_results("SELECT HOUR(visit_time) as h, COUNT(*) as c FROM $table WHERE $where AND event_type='pageview' GROUP BY HOUR(visit_time) ORDER BY h ASC");
    $hour_labels = array(); $hour_data = array();
    foreach ($hour_rows as $row) { $hour_labels[] = $row->h . ':00'; $hour_data[] = (int) $row->c; }

    $top_pages = $wpdb->get_results("SELECT page_url, COUNT(*) as c FROM $table WHERE $where AND event_type='pageview' GROUP BY page_url ORDER BY c DESC LIMIT 10");
    $top_referrers = $wpdb->get_results("SELECT referrer, COUNT(*) as c FROM $table WHERE $where AND event_type='pageview' AND referrer != '' GROUP BY referrer ORDER BY c DESC LIMIT 10");
    $devices = $wpdb->get_results("SELECT device, COUNT(*) as c FROM $table WHERE $where AND event_type='pageview' GROUP BY device ORDER BY c DESC");

    // Feature #11: totalen per (mogelijk meerdere) conversiedoel
    $goals = wpa_get_goals();
    $goal_totals = array();
    foreach ($goals as $goal) {
        $goal_totals[$goal->name] = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT visitor_hash) FROM $table WHERE $where AND page_url LIKE %s", '%' . $wpdb->esc_like($goal->url_pattern) . '%'));
    }
    ?>
    <div style="display:flex;gap:10px;align-items:center;margin-bottom:20px;flex-wrap:wrap;">
        <form method="GET" style="display:flex;gap:8px;align-items:center;">
            <input type="hidden" name="page" value="brink-analytics">
            <select name="range" onchange="this.form.submit()">
                <option value="7" <?php selected($range, '7'); ?>>Laatste 7 dagen</option>
                <option value="30" <?php selected($range, '30'); ?>>Laatste 30 dagen</option>
                <option value="90" <?php selected($range, '90'); ?>>Laatste 90 dagen</option>
                <option value="all" <?php selected($range, 'all'); ?>>Alles</option>
                <option value="custom" <?php selected($range, 'custom'); ?>>Aangepast</option>
            </select>
            <input type="date" name="c_start" value="<?php echo isset($c_start) ? esc_attr($c_start) : ''; ?>"> t/m
            <input type="date" name="c_end" value="<?php echo isset($c_end) ? esc_attr($c_end) : ''; ?>">
            <button class="button">Filter</button>
        </form>
    </div>

    <div style="display:grid;grid-template-columns:repeat(4, 1fr);gap:16px;margin-bottom:20px;">
        <div class="wpa-panel"><h3>Live bezoekers</h3><p style="font-size:28px;margin:0;"><?php echo esc_html($live_visitors); ?></p></div>
        <div class="wpa-panel"><h3>Weergaven</h3><p style="font-size:28px;margin:0;"><?php echo esc_html(number_format_i18n($total_views)); ?></p><?php if ($range !== 'all' && $range !== 'custom') echo wpa_get_trend_html($total_views, $prev_views); ?></div>
        <div class="wpa-panel"><h3>Unieke bezoekers</h3><p style="font-size:28px;margin:0;"><?php echo esc_html(number_format_i18n($unique_visitors)); ?></p></div>
        <div class="wpa-panel"><h3>Doelen behaald</h3><p style="font-size:28px;margin:0;"><?php echo esc_html(array_sum($goal_totals)); ?></p></div>
    </div>

    <?php if (!empty($goal_totals)): ?>
    <div class="wpa-panel" style="margin-bottom:20px;">
        <h3>Doelen per naam</h3>
        <table class="widefat"><thead><tr><th>Doel</th><th>Behaald</th></tr></thead><tbody>
        <?php foreach ($goal_totals as $name => $count): ?>
            <tr><td><?php echo esc_html($name); ?></td><td><?php echo esc_html($count); ?></td></tr>
        <?php endforeach; ?>
        </tbody></table>
    </div>
    <?php endif; ?>

    <div class="wpa-panel" style="margin-bottom:20px;"><canvas id="wpaChart" height="80"></canvas></div>
    <div class="wpa-panel" style="margin-bottom:20px;"><canvas id="wpaHourChart" height="60"></canvas></div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
        <div class="wpa-panel">
            <h3>Top pagina's</h3>
            <table class="widefat"><tbody>
            <?php foreach ($top_pages as $p): ?>
                <tr><td><?php echo esc_html($p->page_url); ?></td><td><?php echo esc_html($p->c); ?></td></tr>
            <?php endforeach; ?>
            </tbody></table>
        </div>
        <div class="wpa-panel">
            <h3>Top verwijzers</h3>
            <table class="widefat"><tbody>
            <?php foreach ($top_referrers as $r): ?>
                <tr><td><?php echo esc_html($r->referrer); ?></td><td><?php echo esc_html($r->c); ?></td></tr>
            <?php endforeach; ?>
            </tbody></table>
        </div>
    </div>

    <div class="wpa-panel" style="margin-top:16px;">
        <h3>Apparaten</h3>
        <canvas id="wpaDeviceChart" height="70"></canvas>
    </div>

    <?php
    $device_labels = array(); $device_data = array();
    foreach ($devices as $d) { $device_labels[] = $d->device; $device_data[] = (int) $d->c; }

    $inline_js = "
        const ctx = document.getElementById('wpaChart').getContext('2d');
        new Chart(ctx, { type: 'line', data: { labels: " . wp_json_encode($chart_labels) . ", datasets: [{ label: 'Unieke bezoekers', data: " . wp_json_encode($chart_data) . ", borderColor: '#cca900', backgroundColor: 'rgba(204,169,0,0.1)', borderWidth: 3, fill: true, tension: 0.4 }] }, options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } } });
        const ctxHour = document.getElementById('wpaHourChart').getContext('2d');
        new Chart(ctxHour, { type: 'bar', data: { labels: " . wp_json_encode($hour_labels) . ", datasets: [{ label: 'Gemiddeld per uur', data: " . wp_json_encode($hour_data) . ", backgroundColor: 'rgba(54,162,235,0.5)', borderRadius: 4 }] }, options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } } } });
        const ctxDevice = document.getElementById('wpaDeviceChart').getContext('2d');
        new Chart(ctxDevice, { type: 'doughnut', data: { labels: " . wp_json_encode($device_labels) . ", datasets: [{ data: " . wp_json_encode($device_data) . ", backgroundColor: ['#378ADD','#1D9E75','#EF9F27','#D85A30'] }] }, options: { responsive: true, maintainAspectRatio: false } });
    ";
    wp_add_inline_script('wpa-chartjs', $inline_js, 'after');
}

function wpa_render_tab_trechter($wpdb, $table, $can_manage) {
    $goals = wpa_get_goals();
    $steps = wpa_get_funnel_steps();
    ?>
    <div class="wpa-panel" style="margin-bottom:20px;">
        <h2>Conversiedoelen</h2>
        <p style="color:#666;">Meerdere doelen naast elkaar, elk met een eigen naam en URL-patroon (bijv. <code>/bedankt</code>).</p>
        <form method="POST">
            <?php wp_nonce_field('wpa_save_goals_action', 'wpa_goals_nonce'); ?>
            <table class="widefat" id="wpa-goals-table"><thead><tr><th>Naam</th><th>URL-patroon</th></tr></thead><tbody>
            <?php $rows = !empty($goals) ? $goals : array((object)['name'=>'','url_pattern'=>'']); ?>
            <?php foreach ($rows as $g): ?>
                <tr>
                    <td><input type="text" name="goal_name[]" value="<?php echo esc_attr($g->name); ?>" style="width:100%;"></td>
                    <td><input type="text" name="goal_url[]" value="<?php echo esc_attr($g->url_pattern); ?>" style="width:100%;"></td>
                </tr>
            <?php endforeach; ?>
            </tbody></table>
            <?php if ($can_manage): ?>
            <p><button type="button" class="button" onclick="wpaAddGoalRow()">+ Doel toevoegen</button> <button type="submit" name="wpa_save_goals" class="button button-primary">Doelen opslaan</button></p>
            <?php endif; ?>
        </form>
    </div>

    <div class="wpa-panel">
        <h2>Conversietrechter</h2>
        <p style="color:#666;">Stappen in volgorde. We tellen hoeveel unieke bezoekers elke stap hebben bezocht (geen strikte volgordecontrole — een indicatie van afhaakpercentages).</p>
        <?php if (!empty($steps)):
            $prev_count = null;
            foreach ($steps as $step):
                $count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT visitor_hash) FROM $table WHERE page_url LIKE %s AND event_type='pageview'", '%' . $wpdb->esc_like($step->url_pattern) . '%'));
                $pct = ($prev_count && $prev_count > 0) ? round(($count / $prev_count) * 100) : 100;
                $width = $prev_count ? max(5, $pct) : 100;
        ?>
            <div style="margin-bottom:6px;">
                <div style="background:var(--surface-1, #f0f0f1);border-radius:4px;overflow:hidden;">
                    <div style="width:<?php echo esc_attr($width); ?>%;background:#378ADD;color:#fff;padding:8px 12px;font-size:13px;"><?php echo esc_html($step->name); ?> — <?php echo esc_html($count); ?> (<?php echo esc_html($pct); ?>%)</div>
                </div>
            </div>
        <?php $prev_count = $count; endforeach; else: ?>
            <p>Nog geen trechter ingesteld.</p>
        <?php endif; ?>

        <form method="POST" style="margin-top:16px;">
            <?php wp_nonce_field('wpa_save_funnel_action', 'wpa_funnel_nonce'); ?>
            <table class="widefat" id="wpa-funnel-table"><thead><tr><th>Stapnaam</th><th>URL-patroon</th></tr></thead><tbody>
            <?php $frows = !empty($steps) ? $steps : array((object)['name'=>'','url_pattern'=>'']); ?>
            <?php foreach ($frows as $s): ?>
                <tr>
                    <td><input type="text" name="funnel_name[]" value="<?php echo esc_attr($s->name); ?>" style="width:100%;"></td>
                    <td><input type="text" name="funnel_url[]" value="<?php echo esc_attr($s->url_pattern); ?>" style="width:100%;"></td>
                </tr>
            <?php endforeach; ?>
            </tbody></table>
            <?php if ($can_manage): ?>
            <p><button type="button" class="button" onclick="wpaAddFunnelRow()">+ Stap toevoegen</button> <button type="submit" name="wpa_save_funnel" class="button button-primary">Trechter opslaan</button></p>
            <?php endif; ?>
        </form>
    </div>
    <script>
    function wpaAddGoalRow() {
        const tbody = document.querySelector('#wpa-goals-table tbody');
        const tr = document.createElement('tr');
        tr.innerHTML = '<td><input type="text" name="goal_name[]" style="width:100%;"></td><td><input type="text" name="goal_url[]" style="width:100%;"></td>';
        tbody.appendChild(tr);
    }
    function wpaAddFunnelRow() {
        const tbody = document.querySelector('#wpa-funnel-table tbody');
        const tr = document.createElement('tr');
        tr.innerHTML = '<td><input type="text" name="funnel_name[]" style="width:100%;"></td><td><input type="text" name="funnel_url[]" style="width:100%;"></td>';
        tbody.appendChild(tr);
    }
    </script>
    <?php
}

function wpa_render_tab_kanalen($wpdb, $table) {
    $rows = $wpdb->get_results("SELECT referrer, utm_source, utm_medium, visitor_hash FROM $table WHERE event_type='pageview' AND visit_time >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
    $channels = array();
    foreach ($rows as $r) {
        $ch = wpa_get_channel($r->referrer, $r->utm_source, $r->utm_medium);
        $channels[$ch] = ($channels[$ch] ?? 0) + 1;
    }
    arsort($channels);

    // Nieuw vs. terugkerend (Feature #7)
    $new_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM (SELECT visitor_hash FROM $table WHERE event_type='pageview' AND visit_time >= DATE_SUB(NOW(), INTERVAL 30 DAY) GROUP BY visitor_hash HAVING MIN(visit_time) >= DATE_SUB(NOW(), INTERVAL 30 DAY)) t");
    $total_unique = (int) $wpdb->get_var("SELECT COUNT(DISTINCT visitor_hash) FROM $table WHERE event_type='pageview' AND visit_time >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
    $returning_count = max(0, $total_unique - $new_count);

    // Exit-pagina's (Feature #9)
    $exit_pages = $wpdb->get_results("SELECT t1.page_url, COUNT(*) as c FROM $table t1 INNER JOIN (SELECT visitor_hash, MAX(visit_time) as last_visit FROM $table WHERE event_type='pageview' GROUP BY visitor_hash) t2 ON t1.visitor_hash = t2.visitor_hash AND t1.visit_time = t2.last_visit WHERE t1.event_type='pageview' GROUP BY t1.page_url ORDER BY c DESC LIMIT 10");

    // 404's en site-zoekopdrachten (Feature #4, #5)
    $not_found = $wpdb->get_results("SELECT page_url, COUNT(*) as c FROM $table WHERE event_type='404' GROUP BY page_url ORDER BY c DESC LIMIT 10");
    $searches = $wpdb->get_results("SELECT event_name, COUNT(*) as c FROM $table WHERE event_type='site_search' GROUP BY event_name ORDER BY c DESC LIMIT 10");

    // Formuliervelden, video, web vitals, A/B (Feature #6, #10, #37, #15)
    $form_exits = $wpdb->get_results("SELECT event_name, COUNT(*) as c FROM $table WHERE event_type='form_field_exit' GROUP BY event_name ORDER BY c DESC LIMIT 10");
    $video_progress = $wpdb->get_results("SELECT event_name, event_value, COUNT(*) as c FROM $table WHERE event_type='video_progress' GROUP BY event_name, event_value ORDER BY event_name, event_value+0");
    $ab_variants = $wpdb->get_results("SELECT event_name, COUNT(*) as c FROM $table WHERE event_type='ab_variant' GROUP BY event_name ORDER BY c DESC");
    $vitals_raw = $wpdb->get_results("SELECT event_value FROM $table WHERE event_type='web_vitals' ORDER BY id DESC LIMIT 200");

    // Click-heatmap (Feature #1) — grid-telling voor de meest bezochte pagina
    $top_page_for_heatmap = $wpdb->get_var("SELECT page_url FROM $table WHERE event_type='pageview' GROUP BY page_url ORDER BY COUNT(*) DESC LIMIT 1");
    $heatmap_rows = $top_page_for_heatmap ? $wpdb->get_results($wpdb->prepare("SELECT event_value, COUNT(*) as c FROM $table WHERE event_type='click_map' AND page_url = %s GROUP BY event_value", $top_page_for_heatmap)) : array();
    $heatmap_grid = array_fill(0, 25, 0);
    foreach ($heatmap_rows as $hr) {
        $coords = explode(',', $hr->event_value);
        if (count($coords) === 2) {
            $idx = ((int) $coords[1]) * 5 + (int) $coords[0];
            if ($idx >= 0 && $idx < 25) $heatmap_grid[$idx] = (int) $hr->c;
        }
    }
    $max_heat = max(1, max($heatmap_grid));

    // Cohort-retentie (Feature #17) — vereenvoudigd: per week eerste bezoek, % terug in +1 week
    $cohort_rows = $wpdb->get_results("
        SELECT YEARWEEK(first_seen) as cohort_week, COUNT(*) as cohort_size FROM (
            SELECT visitor_hash, MIN(visit_time) as first_seen FROM $table WHERE event_type='pageview' GROUP BY visitor_hash
        ) t WHERE first_seen >= DATE_SUB(NOW(), INTERVAL 8 WEEK) GROUP BY cohort_week ORDER BY cohort_week ASC");

    // ROI per campagne (Feature #16)
    $campaign_costs = get_option('wpa_campaign_costs', array());
    $campaign_conversions = array();
    if (!empty($campaign_costs)) {
        $goals = wpa_get_goals();
        $goal_pattern = !empty($goals) ? $goals[0]->url_pattern : '';
        foreach ($campaign_costs as $cname => $cost) {
            $conv = $goal_pattern ? (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT visitor_hash) FROM $table WHERE utm_campaign = %s AND page_url LIKE %s", $cname, '%' . $wpdb->esc_like($goal_pattern) . '%')) : 0;
            $campaign_conversions[$cname] = array('cost' => $cost, 'conversions' => $conv, 'cpc' => $conv > 0 ? round($cost / $conv, 2) : null);
        }
    }
    ?>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;">
        <div class="wpa-panel">
            <h3>Kanaal-groepering (30 dagen)</h3>
            <table class="widefat"><tbody>
            <?php foreach ($channels as $name => $count): ?>
                <tr><td><?php echo esc_html($name); ?></td><td><?php echo esc_html($count); ?></td></tr>
            <?php endforeach; ?>
            </tbody></table>
        </div>
        <div class="wpa-panel">
            <h3>Nieuw vs. terugkerend (30 dagen)</h3>
            <p>Nieuw: <strong><?php echo esc_html($new_count); ?></strong> &middot; Terugkerend: <strong><?php echo esc_html($returning_count); ?></strong></p>
        </div>
    </div>

    <div class="wpa-panel" style="margin-bottom:16px;">
        <h3>Exit-pagina's</h3>
        <table class="widefat"><tbody>
        <?php foreach ($exit_pages as $e): ?>
            <tr><td><?php echo esc_html($e->page_url); ?></td><td><?php echo esc_html($e->c); ?></td></tr>
        <?php endforeach; ?>
        </tbody></table>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;">
        <div class="wpa-panel">
            <h3>404-fouten</h3>
            <table class="widefat"><tbody>
            <?php foreach ($not_found as $n): ?>
                <tr><td><?php echo esc_html($n->page_url); ?></td><td><?php echo esc_html($n->c); ?></td></tr>
            <?php endforeach; ?>
            </tbody></table>
        </div>
        <div class="wpa-panel">
            <h3>Site-zoekopdrachten</h3>
            <table class="widefat"><tbody>
            <?php foreach ($searches as $s): ?>
                <tr><td><?php echo esc_html($s->event_name); ?></td><td><?php echo esc_html($s->c); ?></td></tr>
            <?php endforeach; ?>
            </tbody></table>
        </div>
    </div>

    <div class="wpa-panel" style="margin-bottom:16px;">
        <h3>Klik-heatmap (grid) — meest bezochte pagina<?php echo $top_page_for_heatmap ? ': ' . esc_html($top_page_for_heatmap) : ''; ?></h3>
        <?php if (!$top_page_for_heatmap || array_sum($heatmap_grid) === 0): ?>
            <p style="color:#666;">Nog geen data. Zet "Click-heatmap" aan bij Instellingen om dit te gaan meten.</p>
        <?php else: ?>
            <div style="display:grid;grid-template-columns:repeat(5, 1fr);gap:2px;max-width:400px;">
            <?php foreach ($heatmap_grid as $count):
                $intensity = $count / $max_heat;
                $bg = 'rgba(226,75,74,' . round($intensity, 2) . ')';
            ?>
                <div style="background:<?php echo esc_attr($bg); ?>;height:40px;border:0.5px solid #ddd;"></div>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;">
        <div class="wpa-panel">
            <h3>Formulierveld-uitval</h3>
            <?php if (empty($form_exits)): ?><p style="color:#666;">Nog geen data. Zet dit aan bij Instellingen.</p><?php endif; ?>
            <table class="widefat"><tbody>
            <?php foreach ($form_exits as $f): ?>
                <tr><td><?php echo esc_html($f->event_name); ?></td><td><?php echo esc_html($f->c); ?></td></tr>
            <?php endforeach; ?>
            </tbody></table>
        </div>
        <div class="wpa-panel">
            <h3>Video-engagement</h3>
            <?php if (empty($video_progress)): ?><p style="color:#666;">Nog geen data. Zet dit aan bij Instellingen.</p><?php endif; ?>
            <table class="widefat"><tbody>
            <?php foreach ($video_progress as $v): ?>
                <tr><td><?php echo esc_html($v->event_name); ?> — <?php echo esc_html($v->event_value); ?>%</td><td><?php echo esc_html($v->c); ?></td></tr>
            <?php endforeach; ?>
            </tbody></table>
        </div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;">
        <div class="wpa-panel">
            <h3>A/B-varianten</h3>
            <?php if (empty($ab_variants)): ?><p style="color:#666;">Geen data. Voeg <code>data-wpa-variant="..."</code> toe aan elementen om te testen.</p><?php endif; ?>
            <table class="widefat"><tbody>
            <?php foreach ($ab_variants as $a): ?>
                <tr><td><?php echo esc_html($a->event_name); ?></td><td><?php echo esc_html($a->c); ?></td></tr>
            <?php endforeach; ?>
            </tbody></table>
        </div>
        <div class="wpa-panel">
            <h3>Core Web Vitals (gemiddelde, laatste 200 metingen)</h3>
            <?php
            $lcp_sum = 0; $cls_sum = 0; $n = 0;
            foreach ($vitals_raw as $vr) {
                $data = json_decode($vr->event_value, true);
                if (is_array($data) && isset($data['lcp'])) { $lcp_sum += $data['lcp']; $cls_sum += $data['cls'] ?? 0; $n++; }
            }
            if ($n > 0): ?>
                <p>Gem. LCP: <strong><?php echo esc_html(round($lcp_sum / $n)); ?> ms</strong> &middot; Gem. CLS: <strong><?php echo esc_html(round($cls_sum / $n, 3)); ?></strong></p>
            <?php else: ?>
                <p style="color:#666;">Nog geen data. Zet dit aan bij Instellingen.</p>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!empty($campaign_conversions)): ?>
    <div class="wpa-panel" style="margin-bottom:16px;">
        <h3>Campagne-ROI</h3>
        <table class="widefat"><thead><tr><th>Campagne</th><th>Kosten</th><th>Conversies</th><th>Kosten per conversie</th></tr></thead><tbody>
        <?php foreach ($campaign_conversions as $cname => $info): ?>
            <tr><td><?php echo esc_html($cname); ?></td><td>€<?php echo esc_html(number_format_i18n($info['cost'], 2)); ?></td><td><?php echo esc_html($info['conversions']); ?></td><td><?php echo $info['cpc'] !== null ? '€' . esc_html(number_format_i18n($info['cpc'], 2)) : 'N/A'; ?></td></tr>
        <?php endforeach; ?>
        </tbody></table>
    </div>
    <?php endif; ?>

    <div class="wpa-panel">
        <h3>Cohort-retentie (nieuwe bezoekers per week, laatste 8 weken)</h3>
        <table class="widefat"><thead><tr><th>Week</th><th>Nieuwe bezoekers</th></tr></thead><tbody>
        <?php foreach ($cohort_rows as $c): ?>
            <tr><td><?php echo esc_html($c->cohort_week); ?></td><td><?php echo esc_html($c->cohort_size); ?></td></tr>
        <?php endforeach; ?>
        </tbody></table>
        <p style="color:#666;font-size:12px;">Vereenvoudigde weergave: exacte terugkeerpercentages per vervolgweek vereisen aanvullende koppeling en zijn bewust simpel gehouden om de plugin licht te houden.</p>
    </div>
    <?php
}

function wpa_render_tab_privacy($can_manage) {
    $exclude_roles = get_option('wpa_exclude_roles', array());
    $exclude_ips = get_option('wpa_exclude_ips', array());
    $dashboard_roles = get_option('wpa_dashboard_roles', array());
    $anonymize_ip = get_option('wpa_anonymize_ip', true);
    $editable_roles = wp_roles()->roles;
    $bot_blocks = get_option('wpa_bot_blocks', 0);
    ?>
    <div class="wpa-panel">
        <h2>Privacy &amp; toegang</h2>
        <form method="POST">
            <?php wp_nonce_field('wpa_save_settings_action', 'wpa_settings_nonce'); ?>

            <h3>Cookievrij by design</h3>
            <p style="color:#666;">Deze plugin plaatst geen cookies en gebruikt geen localStorage — bezoekers worden herkend via een eenmalige, wekelijks roterende hash (IP + user-agent + geheime sleutel), niet via een cookie.</p>

            <h3>Rollen uitsluiten van tracking</h3>
            <?php foreach ($editable_roles as $role_slug => $role_info): ?>
                <label style="margin-right:15px; display:inline-block; margin-top:5px;">
                    <input type="checkbox" name="wpa_exclude_roles[]" value="<?php echo esc_attr($role_slug); ?>" <?php checked(in_array($role_slug, $exclude_roles, true)); ?> <?php disabled(!$can_manage); ?>>
                    <?php echo esc_html(translate_user_role($role_info['name'])); ?>
                </label>
            <?php endforeach; ?>
            <p style="font-size:12px;color:#888;">Beheerders worden altijd al automatisch uitgesloten.</p>

            <h3>IP-adressen uitsluiten (één per regel)</h3>
            <textarea name="wpa_exclude_ips" rows="3" style="width:100%;" <?php disabled(!$can_manage); ?>><?php echo esc_textarea(implode("\n", $exclude_ips)); ?></textarea>

            <h3 style="margin-top:20px;">IP-anonimisering</h3>
            <label><input type="checkbox" name="wpa_anonymize_ip" <?php checked($anonymize_ip); ?> <?php disabled(!$can_manage); ?>> Laatste IP-octet wissen vóórdat het gehasht wordt (extra privacylaag boven de HMAC-hash)</label>

            <h3 style="margin-top:20px;">Wie mag het dashboard bekijken?</h3>
            <?php foreach ($editable_roles as $role_slug => $role_info): if ($role_slug === 'administrator') continue; ?>
                <label style="margin-right:15px; display:inline-block; margin-top:5px;">
                    <input type="checkbox" name="wpa_dashboard_roles[]" value="<?php echo esc_attr($role_slug); ?>" <?php checked(in_array($role_slug, $dashboard_roles, true)); ?> <?php disabled(!$can_manage); ?>>
                    <?php echo esc_html(translate_user_role($role_info['name'])); ?>
                </label>
            <?php endforeach; ?>
            <p style="font-size:12px;color:#888;">Administrator heeft altijd toegang. Instellingen wijzigen blijft voorbehouden aan Administrator.</p>

            <?php if ($can_manage): ?>
            <p style="margin-top:20px;"><button type="submit" name="wpa_save_settings" class="button button-primary">Opslaan</button></p>
            <?php endif; ?>
        </form>

        <hr>
        <h3>Geblokkeerde bot-verzoeken</h3>
        <p><?php echo esc_html(number_format_i18n($bot_blocks)); ?> verzoeken herkend en geweerd als bot-verkeer (niet meegeteld in statistieken).</p>
    </div>
    <?php
}

function wpa_render_tab_instellingen($can_manage) {
    $report_email = get_option('wpa_report_email', get_option('admin_email'));
    $webhook_url = get_option('wpa_webhook_url', '');
    $email_frequency = get_option('wpa_email_frequency', 'weekly');
    $retention_raw = get_option('wpa_retention_days_raw', 90);
    $retention_summary = get_option('wpa_retention_days_summary', 730);
    $enable_heatmap = get_option('wpa_enable_heatmap', false);
    $enable_form_tracking = get_option('wpa_enable_form_tracking', false);
    $enable_video_tracking = get_option('wpa_enable_video_tracking', false);
    $enable_web_vitals = get_option('wpa_enable_web_vitals', false);
    $campaign_costs = get_option('wpa_campaign_costs', array());
    ?>
    <div class="wpa-panel" style="margin-bottom:20px;">
        <h2>Marketeer- &amp; systeeminstellingen</h2>
        <form method="POST">
            <?php wp_nonce_field('wpa_save_settings_action', 'wpa_settings_nonce'); ?>

            <p><label><strong>Rapportage-e-mailadres:</strong></label><br>
            <input type="email" name="wpa_report_email" value="<?php echo esc_attr($report_email); ?>" style="width:100%;" <?php disabled(!$can_manage); ?>></p>

            <p><label><strong>E-mailfrequentie:</strong></label><br>
            <select name="wpa_email_frequency" <?php disabled(!$can_manage); ?>>
                <option value="daily" <?php selected($email_frequency, 'daily'); ?>>Dagelijks</option>
                <option value="weekly" <?php selected($email_frequency, 'weekly'); ?>>Wekelijks</option>
                <option value="monthly" <?php selected($email_frequency, 'monthly'); ?>>Maandelijks</option>
                <option value="never" <?php selected($email_frequency, 'never'); ?>>Nooit</option>
            </select></p>

            <p><label><strong>Webhook-URL bij conversie</strong> (bv. Slack/Zapier/Make):</label><br>
            <input type="url" name="wpa_webhook_url" value="<?php echo esc_attr($webhook_url); ?>" placeholder="https://hooks.zapier.com/..." style="width:100%;" <?php disabled(!$can_manage); ?>></p>

            <p><label><strong>Bewaartermijn ruwe data (dagen, min. 30):</strong></label><br>
            <input type="number" name="wpa_retention_days_raw" value="<?php echo esc_attr($retention_raw); ?>" min="30" <?php disabled(!$can_manage); ?>></p>

            <p><label><strong>Bewaartermijn samenvattingen (dagen, 0 = onbeperkt):</strong></label><br>
            <input type="number" name="wpa_retention_days_summary" value="<?php echo esc_attr($retention_summary); ?>" min="0" <?php disabled(!$can_manage); ?>></p>

            <h3 style="margin-top:20px;">Optionele functies (standaard uit — houdt de plugin licht)</h3>
            <label style="display:block;margin-top:6px;"><input type="checkbox" name="wpa_enable_heatmap" <?php checked($enable_heatmap); ?> <?php disabled(!$can_manage); ?>> Click-heatmap</label>
            <label style="display:block;margin-top:6px;"><input type="checkbox" name="wpa_enable_form_tracking" <?php checked($enable_form_tracking); ?> <?php disabled(!$can_manage); ?>> Formulierveld-analyse</label>
            <label style="display:block;margin-top:6px;"><input type="checkbox" name="wpa_enable_video_tracking" <?php checked($enable_video_tracking); ?> <?php disabled(!$can_manage); ?>> Video-engagement (YouTube/Vimeo)</label>
            <label style="display:block;margin-top:6px;"><input type="checkbox" name="wpa_enable_web_vitals" <?php checked($enable_web_vitals); ?> <?php disabled(!$can_manage); ?>> Core Web Vitals</label>

            <h3 style="margin-top:20px;">Campagne-ROI: kosten per UTM-campagne</h3>
            <table class="widefat" id="wpa-cost-table"><thead><tr><th>Campagnenaam (utm_campaign)</th><th>Kosten (€)</th></tr></thead><tbody>
            <?php $cost_rows = !empty($campaign_costs) ? $campaign_costs : array('' => ''); ?>
            <?php foreach ($cost_rows as $cname => $cost): ?>
                <tr>
                    <td><input type="text" name="wpa_campaign_name[]" value="<?php echo esc_attr($cname); ?>" style="width:100%;" <?php disabled(!$can_manage); ?>></td>
                    <td><input type="number" step="0.01" name="wpa_campaign_cost[]" value="<?php echo esc_attr($cost); ?>" style="width:100%;" <?php disabled(!$can_manage); ?>></td>
                </tr>
            <?php endforeach; ?>
            </tbody></table>
            <?php if ($can_manage): ?><p><button type="button" class="button" onclick="wpaAddCostRow()">+ Campagne toevoegen</button></p><?php endif; ?>

            <?php if ($can_manage): ?>
            <p style="margin-top:20px;"><button type="submit" name="wpa_save_settings" class="button button-primary">Instellingen opslaan</button></p>
            <?php endif; ?>
        </form>
        <script>
        function wpaAddCostRow() {
            const tbody = document.querySelector('#wpa-cost-table tbody');
            const tr = document.createElement('tr');
            tr.innerHTML = '<td><input type="text" name="wpa_campaign_name[]" style="width:100%;"></td><td><input type="number" step="0.01" name="wpa_campaign_cost[]" style="width:100%;"></td>';
            tbody.appendChild(tr);
        }
        </script>

        <?php if ($can_manage): ?>
        <hr style="margin:20px 0;">
        <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=brink-analytics&wpa_export=1'), 'wpa_export_csv')); ?>" class="button">📥 Exporteer ruwe data (CSV)</a>
        <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=brink-analytics&wpa_export_settings=1'), 'wpa_export_settings')); ?>" class="button">📥 Exporteer instellingen (JSON)</a>
        <?php endif; ?>
    </div>

    <?php if ($can_manage): ?>
    <div class="wpa-panel" style="margin-bottom:20px;">
        <h2>Instellingen importeren</h2>
        <form method="POST" enctype="multipart/form-data">
            <?php wp_nonce_field('wpa_import_settings_action', 'wpa_import_nonce'); ?>
            <input type="file" name="wpa_import_file" accept="application/json">
            <button type="submit" name="wpa_import_settings" class="button">Importeren</button>
        </form>
    </div>
    <?php endif; ?>

    <div class="wpa-panel">
        <h2>UTM-linkbuilder</h2>
        <p><label>Basis-URL:</label><br><input type="text" id="wpa-utm-base" style="width:100%;" placeholder="https://voorbeeld.nl/pagina"></p>
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;">
            <p><label>Source:</label><br><input type="text" id="wpa-utm-source" placeholder="newsletter"></p>
            <p><label>Medium:</label><br><input type="text" id="wpa-utm-medium" placeholder="email"></p>
            <p><label>Campaign:</label><br><input type="text" id="wpa-utm-campaign" placeholder="zomerpromo"></p>
        </div>
        <p><input type="text" id="wpa-utm-result" readonly style="width:100%;" placeholder="Resultaat verschijnt hier..."></p>
        <button type="button" class="button" onclick="wpaBuildUtm()">Genereer link</button>
        <button type="button" class="button" onclick="navigator.clipboard.writeText(document.getElementById('wpa-utm-result').value)">Kopiëren</button>
    </div>
    <script>
    function wpaBuildUtm() {
        const base = document.getElementById('wpa-utm-base').value;
        if (!base) return;
        const params = new URLSearchParams();
        const source = document.getElementById('wpa-utm-source').value;
        const medium = document.getElementById('wpa-utm-medium').value;
        const campaign = document.getElementById('wpa-utm-campaign').value;
        if (source) params.set('utm_source', source);
        if (medium) params.set('utm_medium', medium);
        if (campaign) params.set('utm_campaign', campaign);
        const sep = base.indexOf('?') > -1 ? '&' : '?';
        document.getElementById('wpa-utm-result').value = base + (params.toString() ? sep + params.toString() : '');
    }
    </script>
    <?php
}

function wpa_render_tab_systeem($wpdb, $table) {
    // Feature #32: gezondheidscheck
    $last_cron = wp_next_scheduled('wpa_daily_cleanup_event');
    $size_row = $wpdb->get_row($wpdb->prepare(
        "SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) as size_mb, TABLE_ROWS as row_count
         FROM information_schema.TABLES WHERE table_schema = %s AND table_name = %s",
        DB_NAME, $table
    ));
    $rest_url = rest_url('brink-analytics/v1/track');
    $rest_check = get_transient('wpa_rest_health_check');
    if (false === $rest_check) {
        $resp = wp_remote_post($rest_url, array('timeout' => 5, 'body' => wp_json_encode(array('event_type' => 'ping'))));
        $rest_check = (!is_wp_error($resp) && wp_remote_retrieve_response_code($resp) < 500) ? 'ok' : 'fout';
        set_transient('wpa_rest_health_check', $rest_check, HOUR_IN_SECONDS);
    }

    // Feature #40: wijzigingslog uit de laatst opgehaalde GitHub release
    $release = get_transient('wpa_github_release_cache');
    ?>
    <div class="wpa-panel" style="margin-bottom:20px;">
        <h2>Gezondheidscheck</h2>
        <div style="display:grid;grid-template-columns:repeat(4, 1fr);gap:16px;">
            <div><p style="font-size:12px;color:#888;margin:0;">Volgende opschoning</p><p style="font-weight:600;"><?php echo $last_cron ? esc_html(date_i18n('d-m-Y H:i', $last_cron)) : 'Niet gepland'; ?></p></div>
            <div><p style="font-size:12px;color:#888;margin:0;">Tabelgrootte</p><p style="font-weight:600;"><?php echo esc_html($size_row->size_mb ?? '?'); ?> MB (<?php echo esc_html(number_format_i18n($size_row->row_count ?? 0)); ?> rijen, indicatief)</p></div>
            <div><p style="font-size:12px;color:#888;margin:0;">REST-endpoint</p><p style="font-weight:600;color:<?php echo $rest_check === 'ok' ? '#4caf50' : '#f44336'; ?>;"><?php echo $rest_check === 'ok' ? '✔ Bereikbaar' : '✘ Probleem'; ?></p></div>
            <div><p style="font-size:12px;color:#888;margin:0;">Plugin-versie</p><p style="font-weight:600;"><?php echo esc_html(WPA_PLUGIN_VERSION); ?></p></div>
        </div>
    </div>

    <div class="wpa-panel" style="margin-bottom:20px;">
        <h2>Voor developers</h2>
        <p><strong>REST-endpoint (leestoegang):</strong> <code>GET <?php echo esc_html(rest_url('brink-analytics/v1/stats?days=7')); ?></code> — vereist een ingelogde gebruiker met dashboardtoegang (of Application Password).</p>
        <p><strong>WP-CLI:</strong> <code>wp brink-analytics cleanup</code> en <code>wp brink-analytics export --path=export.csv</code></p>
        <p><strong>Filter-hooks:</strong></p>
        <ul style="list-style:disc;margin-left:20px;">
            <li><code>apply_filters('wpa_should_track', true)</code> — koppel aan een cookie-consent/CMP-plugin.</li>
            <li><code>apply_filters('wpa_exclude_page', false, $url)</code> — sluit specifieke pagina's programmatisch uit.</li>
        </ul>
        <?php if (is_multisite() && current_user_can('manage_network')): ?>
        <p><a href="<?php echo esc_url(network_admin_url('admin.php?page=brink-analytics-network')); ?>">Bekijk het netwerkoverzicht &rarr;</a></p>
        <?php endif; ?>
    </div>

    <div class="wpa-panel">
        <h2>Wijzigingslog (laatste GitHub release)</h2>
        <?php if (!empty($release['body'])): ?>
            <p style="font-size:12px;color:#888;">Versie <?php echo esc_html(ltrim($release['tag_name'], 'v')); ?><?php echo !empty($release['published_at']) ? ' — ' . esc_html(date_i18n('d-m-Y', strtotime($release['published_at']))) : ''; ?></p>
            <div style="white-space:pre-wrap;font-size:13px;"><?php echo esc_html($release['body']); ?></div>
        <?php else: ?>
            <p style="color:#666;">Nog geen release-informatie opgehaald (wordt elke 12 uur ververst).</p>
        <?php endif; ?>
    </div>
    <?php
}

add_action('admin_head', 'wpa_admin_inline_style');
function wpa_admin_inline_style() {
    $screen = get_current_screen();
    if (!$screen || strpos($screen->id, 'brink-analytics') === false) return;
    ?>
    <style>
        .wpa-panel { background:#fff; border:1px solid #ccd0d4; border-radius:4px; padding:16px 20px; }
        .wpa-panel h2, .wpa-panel h3 { margin-top:0; }
    </style>
    <?php
}
