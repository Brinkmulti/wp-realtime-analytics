<?php
/**
 * Plugin Name: Brink Multimedia Analytics
 * Plugin URI: https://www.brink-multimedia.nl
 * Description: Real-time, privacy-vriendelijke statistieken en marketing dashboard voor WordPress.
 * Version: 4.3.1
 * Author: Brink Multimedia
 * Author URI: https://www.brink-multimedia.nl
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Update URI: false
 */

if (!defined('ABSPATH')) exit;

define('WPA_TABLE_STATS', 'brink_analytics_stats');
define('WPA_TABLE_DAILY', 'brink_analytics_daily_summary');
define('WPA_DB_VERSION', '4.3.0'); // ophogen bij elke wijziging aan het tabel-schema
define('WPA_PLUGIN_VERSION', '4.3.0');

// ---------------------------------------------------------------------
// GitHub Auto-Updater (lichtgewicht, geen externe library)
// Vul hieronder je eigen GitHub-gebruikersnaam/organisatie en repo-naam in.
// Zolang WPA_GITHUB_OWNER_REPO leeg is, wordt de update-checker overgeslagen.
// Resultaat wordt 12 uur gecachet zodat we niet bij elke transient-refresh
// een live call naar de GitHub API doen (die zonder token maar 60
// verzoeken/uur per IP toestaat — op shared hosting met meerdere sites/
// plugins kan dat limiet snel vollopen).
// ---------------------------------------------------------------------
define('WPA_GITHUB_OWNER_REPO', 'Brinkmulti/wp-realtime-analytics'); // 'eigenaar/repo', geen volledige URL

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
            // Sla een "leeg" resultaat kort op, zodat we bij een storing niet blijven bonken op de API
            set_transient('wpa_github_release_cache', array(), 15 * MINUTE_IN_SECONDS);
            return $transient;
        }

        $data = json_decode(wp_remote_retrieve_body($response));
        $release = array(
            'tag_name'    => isset($data->tag_name) ? $data->tag_name : '',
            'zipball_url' => isset($data->zipball_url) ? $data->zipball_url : '',
            'html_url'    => isset($data->html_url) ? $data->html_url : '',
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

// Zorgt ervoor dat WordPress de plugin-map niet verkeerd hernoemt tijdens het updaten
// (GitHub's zip-download heet bv. "wp-realtime-analytics-4.3.0", niet de originele mapnaam)
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

// Eén centrale plek om het geheime hash-secret op te halen (en zo nodig aan
// te maken). Wordt gebruikt bij activatie, bij updates én bij het hashen
// van bezoekers-IP's — voorkomt drie keer bijna-identieke code.
function wpa_get_hash_secret() {
    $secret = get_option('wpa_hash_secret');
    if (!$secret) {
        $secret = wp_generate_password(32, false, false);
        add_option('wpa_hash_secret', $secret);
    }
    return $secret;
}

register_activation_hook(__FILE__, 'wpa_activate_plugin');
function wpa_activate_plugin() {
    wpa_create_tables();

    // Genereer één keer een geheime, willekeurige salt voor het hashen van
    // bezoekers-IP's. Deze staat los van publiek achterhaalbare gegevens
    // (zoals het admin-e-mailadres) zodat hashes niet terug te rekenen zijn.
    wpa_get_hash_secret();

    update_option('wpa_db_version', WPA_DB_VERSION);

    if (!wp_next_scheduled('wpa_daily_cleanup_event')) {
        wp_schedule_event(time(), 'daily', 'wpa_daily_cleanup_event');
    }
    if (!wp_next_scheduled('wpa_weekly_email_event')) {
        wp_schedule_event(time(), 'weekly', 'wpa_weekly_email_event');
    }
}

register_deactivation_hook(__FILE__, 'wpa_deactivate_plugin');
function wpa_deactivate_plugin() {
    wp_clear_scheduled_hook('wpa_weekly_email_event');
    wp_clear_scheduled_hook('wpa_daily_cleanup_event');
}

function wpa_create_tables() {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();

    $table_stats = $wpdb->prefix . WPA_TABLE_STATS;
    // Uitgebreid met UTM, OS en is_entrance voor marketing data
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

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql_stats);
    dbDelta($sql_daily);
}

// Draai dbDelta ook automatisch als het plugin-schema wijzigt bij een update
// (activation hook draait alleen bij handmatige (de)activatie, niet bij een
// automatische update via GitHub).
add_action('plugins_loaded', 'wpa_maybe_upgrade_db');
function wpa_maybe_upgrade_db() {
    if (get_option('wpa_db_version') !== WPA_DB_VERSION) {
        wpa_create_tables();
        update_option('wpa_db_version', WPA_DB_VERSION);
    }
    wpa_get_hash_secret();
}

add_action('rest_api_init', function () {
    register_rest_route('brink-analytics/v1', '/track', array(
        'methods' => 'POST',
        'callback' => 'wpa_rest_track_visit',
        'permission_callback' => '__return_true'
    ));
});

function wpa_rest_track_visit($request) {
    global $wpdb;
    $table = $wpdb->prefix . WPA_TABLE_STATS;
    $params = $request->get_json_params();
    if (!is_array($params)) {
        $params = array();
    }

    $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field($_SERVER['HTTP_USER_AGENT']) : '';

    // Feature 19: Bot & Spam filter teller
    if ($user_agent === '' || preg_match('/(bot|crawl|spider|slurp|yahoo|mediapartners|headless|curl|wget|python-requests)/i', $user_agent)) {
        $blocks = get_option('wpa_bot_blocks', 0);
        update_option('wpa_bot_blocks', $blocks + 1);
        return new WP_REST_Response(array('success' => false, 'reason' => 'bot'), 200);
    }

    $event_type = isset($params['event_type']) ? sanitize_text_field($params['event_type']) : 'pageview';
    $allowed_event_types = array('pageview', 'ping', 'engagement', 'click', 'download', 'outbound', 'form_submit');
    if (!in_array($event_type, $allowed_event_types, true)) {
        return new WP_REST_Response(array('success' => false, 'reason' => 'invalid_event'), 400);
    }

    $url = isset($params['page_url']) ? esc_url_raw($params['page_url']) : '';
    $referrer = isset($params['referrer']) ? esc_url_raw($params['referrer']) : '';
    $event_name = isset($params['event_name']) ? sanitize_text_field($params['event_name']) : '';
    $time_on_page = isset($params['time_on_page']) ? min(absint($params['time_on_page']), 86400) : 0;
    $scroll_depth = isset($params['scroll_depth']) ? min(absint($params['scroll_depth']), 100) : 0;

    $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field($_SERVER['REMOTE_ADDR']) : '127.0.0.1';

    // Cloudflare of algemene Country header (Feature 16)
    $country = isset($_SERVER['HTTP_CF_IPCOUNTRY']) ? sanitize_text_field($_SERVER['HTTP_CF_IPCOUNTRY']) : 'Unknown';

    // Geheime, willekeurige salt (los van publiek achterhaalbare gegevens zoals
    // het admin-e-mailadres) zodat de hash niet terug te rekenen is naar een IP.
    $secret = wpa_get_hash_secret();
    $rotating_period = date('W-Y'); // wekelijkse rotatie, zoals voorheen
    $hash = hash_hmac('sha256', $ip . $user_agent . $rotating_period, $secret);

    // Lichte rate-limiting per bezoeker om misbruik/flooding van de tabel te beperken.
    $rl_key = 'wpa_rl_' . $hash;
    $rl_count = (int) get_transient($rl_key);
    if ($rl_count > 30) { // max ~30 events per minuut per bezoeker-hash
        return new WP_REST_Response(array('success' => false, 'reason' => 'rate_limited'), 429);
    }
    set_transient($rl_key, $rl_count + 1, MINUTE_IN_SECONDS);

    // Heartbeat Ping (Behoudt actieve bezoekers in de Live-teller)
    if ($event_type === 'ping') {
        $wpdb->query($wpdb->prepare("UPDATE $table SET visit_time = %s WHERE visitor_hash = %s ORDER BY id DESC LIMIT 1", current_time('mysql'), $hash));
        return new WP_REST_Response(array('success' => true), 200);
    }

    // Feature 20: OS & Apparaat detectie
    $device = 'Desktop';
    $os = 'Overig';
    if (preg_match('/(tablet|ipad|playbook|silk)|(android(?!.*mobi))/i', strtolower($user_agent))) {
        $device = 'Tablet';
    } elseif (preg_match('/Mobile|Android|BlackBerry|iPhone|Windows Phone/i', $user_agent)) {
        $device = 'Mobile';
    }
    
    if (preg_match('/windows/i', $user_agent)) $os = 'Windows';
    elseif (preg_match('/macintosh|mac os x/i', $user_agent)) $os = 'Mac OS';
    elseif (preg_match('/linux/i', $user_agent)) $os = 'Linux';
    elseif (preg_match('/iphone|ipad/i', $user_agent)) $os = 'iOS';
    elseif (preg_match('/android/i', $user_agent)) $os = 'Android';

    // Feature 1: UTM Parsing
    $utm_campaign = $utm_source = $utm_medium = '';
    $parsed_url = wp_parse_url($url);
    if (isset($parsed_url['query'])) {
        parse_str($parsed_url['query'], $q_vars);
        $utm_campaign = isset($q_vars['utm_campaign']) ? sanitize_text_field($q_vars['utm_campaign']) : '';
        $utm_source = isset($q_vars['utm_source']) ? sanitize_text_field($q_vars['utm_source']) : '';
        $utm_medium = isset($q_vars['utm_medium']) ? sanitize_text_field($q_vars['utm_medium']) : '';
    }

    // Feature 2: Is dit de landingspagina van deze sessie? (Check afgelopen 30 min)
    $is_entrance = 0;
    if ($event_type === 'pageview') {
        $recent = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE visitor_hash = %s AND visit_time >= DATE_SUB(NOW(), INTERVAL 30 MINUTE) LIMIT 1", $hash));
        if (!$recent) $is_entrance = 1;
    }

    $wpdb->insert($table, array(
        'visit_time' => current_time('mysql'),
        'visitor_hash' => $hash,
        'page_url' => wp_make_link_relative($url),
        'referrer' => wp_parse_url($referrer, PHP_URL_HOST) ?? 'Direct',
        'device' => $device,
        'os' => $os,
        'utm_campaign' => $utm_campaign,
        'utm_source' => $utm_source,
        'utm_medium' => $utm_medium,
        'event_type' => $event_type,
        'event_name' => $event_name,
        'country' => $country,
        'time_on_page' => $time_on_page,
        'scroll_depth' => $scroll_depth,
        'is_entrance' => $is_entrance
    ));

    return new WP_REST_Response(array('success' => true), 200);
}

add_action('wp_footer', 'wpa_insert_tracking_script');
function wpa_insert_tracking_script() {
    if (current_user_can('manage_options') || is_admin()) return;
    $api_url = esc_url_raw(rest_url('brink-analytics/v1/track'));
    ?>
    <script>
    (function() {
        // Respecteer de browser-instelling "Do Not Track"
        if (navigator.doNotTrack === '1' || window.doNotTrack === '1') return;

        let maxScroll = 0;
        let startTime = Date.now();
        let apiEndpoint = '<?php echo $api_url; ?>';

        function sendEvent(type, name) {
            fetch(apiEndpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ page_url: window.location.href, event_type: type, event_name: name })
            });
        }

        // Direct registreren als weergave (met 404 detectie)
        let is_404 = <?php echo is_404() ? 'true' : 'false'; ?>;
        fetch(apiEndpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                page_url: window.location.href,
                referrer: document.referrer,
                event_type: 'pageview',
                event_name: is_404 ? '404_error' : ''
            }),
            keepalive: true
        });

        // Actieve "Heartbeat" (Ping) elke 2 minuten (lager dan voorheen om
        // de database-schrijflast op drukbezochte sites te beperken)
        setInterval(() => {
            if (document.visibilityState === 'visible') {
                fetch(apiEndpoint, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ page_url: window.location.href, event_type: 'ping' }),
                    keepalive: true
                });
            }
        }, 120000);

        // Feature 8: Scroll Diepte
        window.addEventListener('scroll', () => {
            let s = Math.round((window.scrollY / (document.body.scrollHeight - window.innerHeight)) * 100);
            if (s > maxScroll && s <= 100) maxScroll = s;
        });

        // Feature 7: Time on Page (Verlaten van pagina)
        window.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'hidden') {
                let timeSpent = Math.round((Date.now() - startTime) / 1000);
                navigator.sendBeacon(apiEndpoint, JSON.stringify({
                    page_url: window.location.href,
                    event_type: 'engagement',
                    time_on_page: timeSpent,
                    scroll_depth: maxScroll
                }));
            }
        });

        // Feature 10, 11, 12, 14: Acties, Downloads en Links meten
        document.addEventListener('click', function(e) {
            let link = e.target.closest('a');
            if (link && link.href) {
                if (link.href.startsWith('tel:')) sendEvent('click', 'Telefoon: ' + link.href.replace('tel:', ''));
                else if (link.href.startsWith('mailto:')) sendEvent('click', 'Email: ' + link.href.replace('mailto:', ''));
                else if (link.href.match(/\.(pdf|zip|docx|xls)$/i)) sendEvent('download', link.href);
                else if (!link.href.includes(window.location.hostname)) sendEvent('outbound', link.href);
            }
            
            let form = e.target.closest('form');
            if (form && e.target.type === 'submit') {
                sendEvent('form_submit', form.id || form.action || 'Contactformulier');
            }
        });
    })();
    </script>
    <?php
}

// Feature 17: CSV Export
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
        $data = $wpdb->get_results("SELECT visit_time, page_url, referrer, device, os, country FROM $table ORDER BY visit_time DESC LIMIT 5000", ARRAY_A);
        
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="analytics-export.csv"');
        $output = fopen('php://output', 'w');
        fputcsv($output, array('Tijd', 'Pagina', 'Bron', 'Apparaat', 'OS', 'Land'));
        foreach ($data as $row) fputcsv($output, $row);
        fclose($output);
        exit;
    }
}

add_action('wpa_weekly_email_event', 'wpa_send_weekly_email');
function wpa_send_weekly_email() {
    global $wpdb;
    $email = get_option('wpa_report_email', '');
    if (empty($email)) return;

    $table = $wpdb->prefix . WPA_TABLE_STATS;
    $views = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE visit_time >= DATE_SUB(NOW(), INTERVAL 7 DAY) AND event_type='pageview'");
    $visitors = (int) $wpdb->get_var("SELECT COUNT(DISTINCT visitor_hash) FROM $table WHERE visit_time >= DATE_SUB(NOW(), INTERVAL 7 DAY) AND event_type='pageview'");
    $top_page = $wpdb->get_var("SELECT page_url FROM $table WHERE visit_time >= DATE_SUB(NOW(), INTERVAL 7 DAY) AND event_type='pageview' GROUP BY page_url ORDER BY COUNT(*) DESC LIMIT 1");

    $body  = "Hoi,\n\nHier is je wekelijkse samenvatting van Brink Multimedia Analytics:\n\n";
    $body .= "- Weergaven afgelopen 7 dagen: " . number_format_i18n($views) . "\n";
    $body .= "- Unieke bezoekers afgelopen 7 dagen: " . number_format_i18n($visitors) . "\n";
    if ($top_page) {
        $body .= "- Meest bezochte pagina: " . $top_page . "\n";
    }
    $body .= "\nBekijk het volledige dashboard voor meer details: " . admin_url('admin.php?page=brink-analytics') . "\n";

    wp_mail($email, 'Je wekelijkse Brink Analytics Rapport', $body);
}

// Data-retentie: dagelijkse aggregatie + opschoning zodat de ruwe tabel niet
// onbeperkt blijft groeien (belangrijk voor snelheid op de lange termijn).
add_action('wpa_daily_cleanup_event', 'wpa_run_daily_cleanup');
function wpa_run_daily_cleanup() {
    global $wpdb;
    $table = $wpdb->prefix . WPA_TABLE_STATS;
    $table_daily = $wpdb->prefix . WPA_TABLE_DAILY;

    // Bewaar ruwe, per-bezoek data standaard 90 dagen; instelbaar via een
    // eigen 'wpa_retention_days' optie (bv. handmatig via WP-CLI/andere plugin).
    $retention_days = (int) get_option('wpa_retention_days', 90);
    if ($retention_days < 30) {
        $retention_days = 30; // ondergrens, voorkomt dat men per ongeluk alles direct wist
    }

    // 1. Aggregeer dagen die ouder zijn dan de bewaartermijn naar de dagsamenvatting,
    //    zodat historische totalen (voor de "Prognoses"-tab) behouden blijven.
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

    // 2. Verwijder de ruwe regels die nu geaggregeerd zijn.
    $wpdb->query($wpdb->prepare(
        "DELETE FROM $table WHERE visit_time < DATE_SUB(CURDATE(), INTERVAL %d DAY)",
        $retention_days
    ));
}

add_action('admin_menu', 'wpa_add_admin_menu');
function wpa_add_admin_menu() {
    $hook = add_menu_page('Brink Analytics', 'Brink Analytics', 'manage_options', 'brink-analytics', 'wpa_render_dashboard', 'dashicons-chart-area', 2);
    add_action('load-' . $hook, function () {
        add_action('admin_enqueue_scripts', 'wpa_enqueue_dashboard_assets');
    });
}

function wpa_enqueue_dashboard_assets() {
    // Gepinde versie i.p.v. "latest", zodat een toekomstige Chart.js-major
    // release het dashboard niet ongemerkt kan breken.
    wp_enqueue_script(
        'wpa-chartjs',
        'https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js',
        array(),
        '4.4.4',
        true
    );
}

// Trend Berekening Formattering — bewust buiten wpa_render_dashboard() gedefinieerd,
// anders zou PHP een fatale "Cannot redeclare function"-fout geven zodra die
// functie ooit binnen één request meer dan één keer wordt aangeroepen.
function wpa_get_trend_html($current, $prev) {
    if ($prev == 0) return '<span style="font-size:12px;color:#888;">N/A</span>';
    $diff = $current - $prev;
    $perc = round(($diff / $prev) * 100);
    $color = $diff >= 0 ? '#4caf50' : '#f44336';
    $arrow = $diff >= 0 ? '▲' : '▼';
    $sign = $diff > 0 ? '+' : '';
    return "<span style='font-size:13px; color:$color; font-weight:500;'>$arrow ".abs($perc)."% ($sign$diff)</span>";
}

function wpa_render_dashboard() {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('Je hebt geen toestemming om deze pagina te bekijken.', 'brink-analytics'));
    }
    global $wpdb;
    $table = $wpdb->prefix . WPA_TABLE_STATS;
    
    // Instellingen opslaan (Goal URL & Email)
    if (isset($_POST['wpa_save_settings'])) {
        if (
            !isset($_POST['wpa_settings_nonce']) ||
            !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['wpa_settings_nonce'])), 'wpa_save_settings_action') ||
            !current_user_can('manage_options')
        ) {
            wp_die(esc_html__('Beveiligingscontrole mislukt. Ververs de pagina en probeer het opnieuw.', 'brink-analytics'));
        }
        update_option('wpa_goal_url', sanitize_text_field(wp_unslash($_POST['wpa_goal_url'])));
        update_option('wpa_report_email', sanitize_email(wp_unslash($_POST['wpa_report_email'])));
        echo '<div class="updated"><p>Instellingen opgeslagen.</p></div>';
    }

    $goal_url = get_option('wpa_goal_url', '/bedankt');
    $report_email = get_option('wpa_report_email', get_option('admin_email'));

    // Filters verwerken (Inclusief Custom Date)
    $range = isset($_GET['range']) ? sanitize_text_field($_GET['range']) : '30';
    $device_filter = isset($_GET['f_device']) ? sanitize_text_field($_GET['f_device']) : '';
    
    // Feature 15: Aangepaste Datum-prikker
    if ($range === 'custom' && isset($_GET['c_start']) && isset($_GET['c_end'])) {
        $c_start = sanitize_text_field($_GET['c_start']);
        $c_end = sanitize_text_field($_GET['c_end']);
        $where = $wpdb->prepare("visit_time >= %s AND visit_time <= %s", $c_start . ' 00:00:00', $c_end . ' 23:59:59');
        $prev_where = "1=0"; // Geen prev voor custom (te complex)
    } else {
        switch ($range) {
            case '1': 
                $where = "visit_time >= CURDATE()"; 
                $prev_where = "visit_time >= DATE_SUB(CURDATE(), INTERVAL 1 DAY) AND visit_time < CURDATE()";
                break;
            case 'yesterday': 
                $where = "visit_time >= DATE_SUB(CURDATE(), INTERVAL 1 DAY) AND visit_time < CURDATE()"; 
                $prev_where = "visit_time >= DATE_SUB(CURDATE(), INTERVAL 2 DAY) AND visit_time < DATE_SUB(CURDATE(), INTERVAL 1 DAY)";
                break;
            case 'all': 
                $where = "1=1"; 
                $prev_where = "1=0"; 
                break;
            default: 
                // Optie A (Geen vandaag mee tellen bij 7/30 mits we voorkomen dat hij crasht)
                $days = (int)$range;
                $where = "visit_time >= DATE_SUB(CURDATE(), INTERVAL $days DAY) AND visit_time < CURDATE()"; 
                $prev_where = "visit_time >= DATE_SUB(CURDATE(), INTERVAL ".($days*2)." DAY) AND visit_time < DATE_SUB(CURDATE(), INTERVAL $days DAY)";
                break;
        }
    }

    if ($device_filter !== '') {
        $where .= $wpdb->prepare(" AND device = %s", $device_filter);
        if($prev_where != "1=0") $prev_where .= $wpdb->prepare(" AND device = %s", $device_filter);
    }

    // Live Teller (volledig onafhankelijk van de $where-query hierboven)
    $live_time_limit = date('Y-m-d H:i:s', current_time('timestamp') - 300);
    $live_visitors = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(DISTINCT visitor_hash) FROM $table WHERE visit_time >= %s AND event_type='pageview'", 
        $live_time_limit
    ));

    // Totals
    $total_views = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE $where AND event_type='pageview'");
    $unique_visitors = (int) $wpdb->get_var("SELECT COUNT(DISTINCT visitor_hash) FROM $table WHERE $where AND event_type='pageview'");
    
    $prev_views = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE $prev_where AND event_type='pageview'");
    
    // Core Overzicht Data
    $top_pages = $wpdb->get_results("SELECT page_url, COUNT(*) as count FROM $table WHERE $where AND event_type='pageview' GROUP BY page_url ORDER BY count DESC LIMIT 5");
    $top_referrers = $wpdb->get_results("SELECT referrer, COUNT(*) as count FROM $table WHERE $where AND referrer != '' GROUP BY referrer ORDER BY count DESC LIMIT 5");
    $frequent_visitors = $wpdb->get_results("SELECT visitor_hash, COUNT(*) as count, MAX(visit_time) as last_seen FROM $table WHERE $where GROUP BY visitor_hash ORDER BY count DESC LIMIT 5");
    
    // Feature: 404 Fouten Detectie
    $errors_404 = $wpdb->get_results("SELECT page_url, COUNT(*) as count FROM $table WHERE $where AND event_type='pageview' AND event_name='404_error' GROUP BY page_url ORDER BY count DESC LIMIT 5");

    // Lijn Grafiek
    $chart_labels = []; $chart_data = [];
    if ($range === '1' || $range === 'yesterday') {
        $hourly_stats = $wpdb->get_results("SELECT HOUR(visit_time) as time_val, COUNT(DISTINCT visitor_hash) as count FROM $table WHERE $where AND event_type='pageview' GROUP BY HOUR(visit_time)");
        $hourly_counts = [];
        foreach($hourly_stats as $stat) $hourly_counts[$stat->time_val] = $stat->count;
        for($i = 0; $i <= 23; $i++) {
            $chart_labels[] = $i . ':00';
            $chart_data[] = isset($hourly_counts[$i]) ? $hourly_counts[$i] : 0;
        }
    } else {
        $daily_stats = $wpdb->get_results("SELECT DATE(visit_time) as date_val, COUNT(DISTINCT visitor_hash) as count FROM $table WHERE $where AND event_type='pageview' GROUP BY DATE(visit_time)");
        $daily_counts = [];
        foreach($daily_stats as $stat) $daily_counts[$stat->date_val] = $stat->count;
        
        $start_date = new DateTime();
        if ($range === 'all') {
            $min_date = $wpdb->get_var("SELECT MIN(DATE(visit_time)) FROM $table");
            if ($min_date) $start_date = new DateTime($min_date);
            $end_date = new DateTime();
            $end_date->modify('+1 day'); // inclusief vandaag voor "alles"
        } elseif ($range === 'custom') {
            $start_date = new DateTime($c_start);
            $end_date = new DateTime($c_end);
            $end_date->modify('+1 day');
        } else {
            // Fix voor 00:00 - stop gisteren (Optie A)
            $start_date->modify("-".((int)$range)." days");
            $end_date = new DateTime('today'); 
        }
        
        $interval = new DateInterval('P1D');
        $period = new DatePeriod($start_date, $interval, $end_date);
        
        foreach ($period as $dt) {
            $date_str = $dt->format('Y-m-d');
            $chart_labels[] = $dt->format('j M');
            $chart_data[] = isset($daily_counts[$date_str]) ? $daily_counts[$date_str] : 0;
        }
    }

    // Uurgrafiek (Gemiddelden 24u)
    $hour_labels = []; $hour_data = [];
    $raw_hour_stats = $wpdb->get_results("SELECT HOUR(visit_time) as h, COUNT(DISTINCT visitor_hash) as c FROM $table WHERE $where AND event_type='pageview' GROUP BY HOUR(visit_time)");
    $h_counts = [];
    foreach($raw_hour_stats as $hs) $h_counts[$hs->h] = $hs->c;
    for($i=0; $i<=23; $i++) {
        $hour_labels[] = $i . ':00';
        $divisor = (is_numeric($range) && $range > 1) ? (int)$range : 1;
        $hour_data[] = isset($h_counts[$i]) ? round($h_counts[$i] / $divisor, 1) : 0;
    }

    // --- Data voor Marketing Tabbladen ---
    
    // Tab 2: Campagnes
    $utm_data = $wpdb->get_results("SELECT utm_campaign, COUNT(*) as c FROM $table WHERE $where AND utm_campaign != '' GROUP BY utm_campaign ORDER BY c DESC LIMIT 10");
    $landing_pages = $wpdb->get_results("SELECT page_url, COUNT(*) as c FROM $table WHERE $where AND is_entrance=1 GROUP BY page_url ORDER BY c DESC LIMIT 5");
    // Feature 5: Dark Social
    $dark_social = $wpdb->get_results("SELECT page_url, COUNT(*) as c FROM $table WHERE $where AND referrer='Direct' AND page_url != '/' AND event_type='pageview' GROUP BY page_url ORDER BY c DESC LIMIT 5");
    // Feature 4: Social Grouping
    $social_group = $wpdb->get_results("SELECT 
        CASE WHEN referrer LIKE '%facebook%' THEN 'Facebook' WHEN referrer LIKE '%instagram%' THEN 'Instagram' WHEN referrer LIKE '%linkedin%' THEN 'LinkedIn' ELSE 'Andere Social' END as netwerk, COUNT(*) as c 
        FROM $table WHERE $where AND (referrer LIKE '%facebook%' OR referrer LIKE '%instagram%' OR referrer LIKE '%linkedin%') GROUP BY netwerk ORDER BY c DESC");

    // Tab 3: Betrokkenheid & Conversie
    // Feature 6: Bounce Rate
    $bounces = $wpdb->get_var("SELECT COUNT(*) FROM (SELECT visitor_hash FROM $table WHERE $where GROUP BY visitor_hash HAVING COUNT(id) = 1) as b");
    $bounce_rate = $unique_visitors > 0 ? round(($bounces / $unique_visitors) * 100) : 0;
    $avg_time = (int) $wpdb->get_var("SELECT AVG(time_on_page) FROM $table WHERE $where AND event_type='engagement' AND time_on_page > 0");
    $avg_scroll = (int) $wpdb->get_var("SELECT AVG(scroll_depth) FROM $table WHERE $where AND event_type='engagement' AND scroll_depth > 0");
    $goal_hits = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT visitor_hash) FROM $table WHERE $where AND page_url LIKE %s", '%' . $wpdb->esc_like($goal_url) . '%'));
    
    // Tab 4: Interactie
    $outbound = $wpdb->get_results("SELECT event_name, COUNT(*) as c FROM $table WHERE $where AND event_type='outbound' GROUP BY event_name ORDER BY c DESC LIMIT 5");
    $downloads = $wpdb->get_results("SELECT event_name, COUNT(*) as c FROM $table WHERE $where AND event_type='download' GROUP BY event_name ORDER BY c DESC LIMIT 5");
    $clicks = $wpdb->get_results("SELECT event_name, COUNT(*) as c FROM $table WHERE $where AND event_type='click' GROUP BY event_name ORDER BY c DESC LIMIT 5");
    
    // Tab 5: Rapporteren
    $bot_blocks = get_option('wpa_bot_blocks', 0);
    $os_stats = $wpdb->get_results("SELECT os, COUNT(*) as c FROM $table WHERE $where AND event_type='pageview' GROUP BY os ORDER BY c DESC");
    $country_stats = $wpdb->get_results("SELECT country, COUNT(*) as c FROM $table WHERE $where AND country != 'Unknown' GROUP BY country ORDER BY c DESC LIMIT 5");

    // Tab 6: Prognoses & Doelen
    $first_visit = $wpdb->get_var("SELECT MIN(visit_time) FROM $table");
    if (!$first_visit || $first_visit == '0000-00-00 00:00:00') {
        $first_visit = current_time('mysql');
    }
    
    $days_active = max(1, (strtotime(current_time('mysql')) - strtotime($first_visit)) / 86400);
    
    $total_all_views = (int)$wpdb->get_var("SELECT COUNT(*) FROM $table WHERE event_type='pageview'");
    $total_all_visitors = (int)$wpdb->get_var("SELECT COUNT(DISTINCT visitor_hash) FROM $table WHERE event_type='pageview'");
    $total_all_goals = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT visitor_hash) FROM $table WHERE page_url LIKE %s", '%' . $wpdb->esc_like($goal_url) . '%'));

    $forecast_yearly_views = round(($total_all_views / $days_active) * 365);
    $forecast_yearly_visitors = round(($total_all_visitors / $days_active) * 365);
    $forecast_yearly_goals = round(($total_all_goals / $days_active) * 365);
    
    $target_yearly_visitors = round($forecast_yearly_visitors * 1.25);
    if($target_yearly_visitors == 0) $target_yearly_visitors = 1000;
    
    $progress_visitors = min(100, round(($total_all_visitors / $target_yearly_visitors) * 100));

    ?>
    <style>
        .wpa-wrap { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; max-width: 1200px; margin: 20px auto; }
        .wpa-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .wpa-stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 20px; }
        .wpa-stat-box { background: #fff; padding: 20px; border-radius: 8px; border: 1px solid #ccd0d4; text-align: center; position:relative; }
        .wpa-stat-box h3 { margin: 0 0 10px 0; color: #646970; font-size: 14px; }
        .wpa-stat-box p { margin: 0; font-size: 32px; font-weight: 600; color: #cca900; }
        .wpa-chart-container { position: relative; height: 350px; width: 100%; background: #fff; padding: 20px; border-radius: 8px; border: 1px solid #ccd0d4; margin-bottom: 20px; box-sizing: border-box; }
        .wpa-panel-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; margin-top: 20px; }
        .wpa-panel { background: #fff; padding: 20px; border-radius: 8px; border: 1px solid #ccd0d4; overflow-x: auto; }
        .wpa-table { width: 100%; border-collapse: collapse; }
        .wpa-table th, .wpa-table td { padding: 10px; text-align: left; border-bottom: 1px solid #f0f0f1; word-break: break-all; font-size: 13px; }
        .wpa-tabs { margin-bottom: 20px; border-bottom: 1px solid #ccd0d4; padding-bottom: 0px; display:flex; gap:15px; }
        .wpa-tab-btn { background: none; border: none; font-size: 15px; padding: 10px 5px; cursor: pointer; color: #646970; }
        .wpa-tab-btn.active { color: #cca900; font-weight: bold; border-bottom: 3px solid #cca900; margin-bottom:-1px; }
        .tab-content { display: none; }
        .tab-content.active { display: block; }
        .live-dot { display:inline-block; width:10px; height:10px; background-color:#4caf50; border-radius:50%; margin-right:5px; animation: pulse 2s infinite; }
        @keyframes pulse { 0% { box-shadow: 0 0 0 0 rgba(76, 175, 80, 0.7); } 70% { box-shadow: 0 0 0 8px rgba(76, 175, 80, 0); } 100% { box-shadow: 0 0 0 0 rgba(76, 175, 80, 0); } }
    </style>

    <div class="wrap wpa-wrap">
        <div class="wpa-header">
            <h1>📊 Brink Multimedia Analytics</h1>
            <span style="font-size: 12px; color:#888;">Versie <?php echo esc_html(WPA_PLUGIN_VERSION); ?></span>
        </div>

        <div class="wpa-tabs">
            <button class="wpa-tab-btn active" onclick="wpaSwitch('overzicht', this)">Overzicht</button>
            <button class="wpa-tab-btn" onclick="wpaSwitch('campagnes', this)">Campagnes</button>
            <button class="wpa-tab-btn" onclick="wpaSwitch('betrokkenheid', this)">Betrokkenheid</button>
            <button class="wpa-tab-btn" onclick="wpaSwitch('interactie', this)">Interactie</button>
            <button class="wpa-tab-btn" onclick="wpaSwitch('rapporten', this)">Rapporteren</button>
            <button class="wpa-tab-btn" onclick="wpaSwitch('prognoses', this)">Prognoses</button>
        </div>

        <form method="GET" style="margin-bottom: 20px; background: #fff; padding: 15px; border-radius: 8px; border: 1px solid #ccd0d4; display:flex; gap:20px; align-items:center; flex-wrap:wrap;">
            <input type="hidden" name="page" value="brink-analytics">
            <div>
                <label><strong>Periode:</strong> </label>
                <select name="range" onchange="wpaCheckCustom(this)">
                    <option value="1" <?php selected($range, '1'); ?>>Vandaag</option>
                    <option value="yesterday" <?php selected($range, 'yesterday'); ?>>Gisteren</option>
                    <option value="7" <?php selected($range, '7'); ?>>Afgelopen 7 dagen (Voltooid)</option>
                    <option value="30" <?php selected($range, '30'); ?>>Afgelopen 30 dagen (Voltooid)</option>
                    <option value="all" <?php selected($range, 'all'); ?>>Alles (Vanaf begin)</option>
                    <option value="custom" <?php selected($range, 'custom'); ?>>Aangepast...</option>
                </select>
            </div>
            
            <div id="wpa_custom_dates" style="display: <?php echo $range==='custom'?'block':'none'; ?>;">
                <input type="date" name="c_start" value="<?php echo isset($c_start) ? esc_attr($c_start) : ''; ?>"> t/m 
                <input type="date" name="c_end" value="<?php echo isset($c_end) ? esc_attr($c_end) : ''; ?>">
                <button type="submit" class="button">Toepassen</button>
            </div>

            <div>
                <label><strong>Apparaat:</strong> </label>
                <select name="f_device" onchange="this.form.submit()">
                    <option value="">Alle apparaten</option>
                    <option value="Desktop" <?php selected($device_filter, 'Desktop'); ?>>Desktop</option>
                    <option value="Mobile" <?php selected($device_filter, 'Mobile'); ?>>Mobiel</option>
                    <option value="Tablet" <?php selected($device_filter, 'Tablet'); ?>>Tablet</option>
                </select>
            </div>
        </form>

        <script>
            function wpaCheckCustom(sel) {
                if(sel.value === 'custom') document.getElementById('wpa_custom_dates').style.display = 'block';
                else sel.form.submit();
            }
            function wpaSwitch(tab, btn) {
                document.querySelectorAll('.tab-content').forEach(t => t.style.display = 'none');
                document.querySelectorAll('.wpa-tab-btn').forEach(b => b.classList.remove('active'));
                document.getElementById('tab-'+tab).style.display = 'block';
                btn.classList.add('active');
            }
        </script>

        <!-- TAB 1: OVERZICHT -->
        <div id="tab-overzicht" class="tab-content active" style="display:block;">
            <div class="wpa-stats-grid">
                <div class="wpa-stat-box" style="border-top: 3px solid #4caf50;">
                    <h3>Live Bezoekers</h3>
                    <p style="color:#4caf50;"><span class="live-dot"></span><?php echo $live_visitors; ?></p>
                </div>
                <div class="wpa-stat-box">
                    <h3>Unieke Bezoekers</h3>
                    <p><?php echo number_format_i18n($unique_visitors); ?></p>
                    <?php if($range != 'all' && $range != 'custom') echo wpa_get_trend_html($total_views, $prev_views); ?>
                </div>
                <div class="wpa-stat-box">
                    <h3>Totale Weergaven</h3>
                    <p><?php echo number_format_i18n($total_views); ?></p>
                </div>
            </div>

            <div class="wpa-chart-container">
                <canvas id="wpaChart"></canvas>
            </div>
            
            <div class="wpa-chart-container" style="height:250px;">
                <h3 style="margin-top:0;font-size:14px;color:#646970;">Wanneer komen je bezoekers? (Gemiddeld per uur)</h3>
                <canvas id="wpaHourChart"></canvas>
            </div>

            <div class="wpa-panel-grid">
                <div class="wpa-panel">
                    <h2>Meest Bezochte Pagina's</h2>
                    <table class="wpa-table">
                        <tr><th>Pagina</th><th>Weergaven</th></tr>
                        <?php foreach($top_pages as $p): ?>
                            <tr><td><?php echo esc_html($p->page_url); ?></td><td><?php echo $p->count; ?></td></tr>
                        <?php endforeach; ?>
                    </table>
                </div>
                
                <div class="wpa-panel">
                    <h2>Verkeersbronnen</h2>
                    <table class="wpa-table">
                        <tr><th>Bron</th><th>Bezoekers</th></tr>
                        <?php foreach($top_referrers as $r): ?>
                            <tr><td><?php echo esc_html($r->referrer); ?></td><td><?php echo $r->count; ?></td></tr>
                        <?php endforeach; ?>
                    </table>
                </div>

                <div class="wpa-panel">
                    <h2>Meest Actieve Bezoekers (Gehasht)</h2>
                    <table class="wpa-table">
                        <tr><th>Gebruikers Hash</th><th>Klikken</th><th>Laatst Actief</th></tr>
                        <?php foreach($frequent_visitors as $v): ?>
                            <tr>
                                <td><code><?php echo esc_html(substr($v->visitor_hash, 0, 10)); ?>...</code></td>
                                <td><?php echo $v->count; ?></td>
                                <td><?php echo esc_html($v->last_seen); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                </div>

                <div class="wpa-panel">
                    <h2>🚨 Dode Links (404 Fouten)</h2>
                    <p style="font-size:12px;color:#888;">Bezoekers (of dode campagne-links) die op een onbestaande pagina uitkwamen.</p>
                    <table class="wpa-table">
                        <tr><th>URL</th><th>Aantal keer geraakt</th></tr>
                        <?php foreach($errors_404 as $e): ?>
                            <tr><td style="color:#f44336;"><?php echo esc_html($e->page_url); ?></td><td><?php echo $e->count; ?></td></tr>
                        <?php endforeach; ?>
                        <?php if(empty($errors_404)) echo '<tr><td colspan="2" style="color:#4caf50;">Geen 404-fouten gevonden. Lekker bezig!</td></tr>'; ?>
                    </table>
                </div>
            </div>
        </div>

        <!-- TAB 2: CAMPAGNES -->
        <div id="tab-campagnes" class="tab-content">
            <div class="wpa-panel-grid">
                <div class="wpa-panel">
                    <h2>Beste Landingspagina's</h2>
                    <p style="font-size:12px;color:#888;">De eerste pagina die een bezoeker ziet in zijn sessie.</p>
                    <table class="wpa-table">
                        <tr><th>Pagina</th><th>Instromen</th></tr>
                        <?php foreach($landing_pages as $lp): ?>
                            <tr><td><?php echo esc_html($lp->page_url); ?></td><td><?php echo $lp->c; ?></td></tr>
                        <?php endforeach; ?>
                    </table>
                </div>
                <div class="wpa-panel">
                    <h2>UTM Campagnes</h2>
                    <table class="wpa-table">
                        <tr><th>Campagne Naam</th><th>Klikken</th></tr>
                        <?php foreach($utm_data as $u): ?>
                            <tr><td><?php echo esc_html($u->utm_campaign); ?></td><td><?php echo $u->c; ?></td></tr>
                        <?php endforeach; ?>
                        <?php if(empty($utm_data)) echo '<tr><td colspan="2">Geen actieve UTM campagnes gevonden.</td></tr>'; ?>
                    </table>
                </div>
                <div class="wpa-panel">
                    <h2>Gegroepeerde Social Media</h2>
                    <table class="wpa-table">
                        <tr><th>Netwerk</th><th>Bezoeken</th></tr>
                        <?php foreach($social_group as $sg): ?>
                            <tr><td><?php echo esc_html($sg->netwerk); ?></td><td><?php echo $sg->c; ?></td></tr>
                        <?php endforeach; ?>
                    </table>
                </div>
                <div class="wpa-panel">
                    <h2>Dark Social (Diepe Directe Links)</h2>
                    <p style="font-size:12px;color:#888;">Mensen die direct binnenkomen op diepe artikelen (vaak via WhatsApp of Mail).</p>
                    <table class="wpa-table">
                        <tr><th>Artikel / Pagina</th><th>Directe Bezoeken</th></tr>
                        <?php foreach($dark_social as $ds): ?>
                            <tr><td><?php echo esc_html($ds->page_url); ?></td><td><?php echo $ds->c; ?></td></tr>
                        <?php endforeach; ?>
                    </table>
                </div>
            </div>
        </div>

        <!-- TAB 3: BETROKKENHEID -->
        <div id="tab-betrokkenheid" class="tab-content">
            <div class="wpa-stats-grid">
                <div class="wpa-stat-box">
                    <h3>Bouncepercentage</h3>
                    <p><?php echo $bounce_rate; ?>%</p>
                    <span style="font-size:12px;color:#888;">Vertrok na 1 pagina</span>
                </div>
                <div class="wpa-stat-box">
                    <h3>Gem. Leestijd</h3>
                    <p><?php echo round($avg_time); ?> sec</p>
                </div>
                <div class="wpa-stat-box">
                    <h3>Gem. Scrolldiepte</h3>
                    <p><?php echo round($avg_scroll); ?>%</p>
                </div>
                <div class="wpa-stat-box" style="border-top: 3px solid #cca900;">
                    <h3>Conversies (Doel bereikt)</h3>
                    <p><?php echo $goal_hits; ?></p>
                    <span style="font-size:12px;color:#888;">Doel: <?php echo esc_html($goal_url); ?></span>
                </div>
            </div>
            
            <div class="wpa-panel-grid" style="grid-template-columns: 1fr;">
                <div class="wpa-panel">
                    <h2>Kliks op Telefoon & E-mail</h2>
                    <table class="wpa-table">
                        <tr><th>Contact Actie</th><th>Aantal Kliks</th></tr>
                        <?php foreach($clicks as $cl): ?>
                            <tr><td><?php echo esc_html($cl->event_name); ?></td><td><?php echo $cl->c; ?></td></tr>
                        <?php endforeach; ?>
                    </table>
                </div>
            </div>
        </div>

        <!-- TAB 4: INTERACTIE -->
        <div id="tab-interactie" class="tab-content">
            <div class="wpa-panel-grid">
                <div class="wpa-panel">
                    <h2>Downloads (PDF, ZIP, DOCX)</h2>
                    <table class="wpa-table">
                        <tr><th>Bestand</th><th>Aantal Downloads</th></tr>
                        <?php foreach($downloads as $dl): ?>
                            <tr><td><?php echo esc_html(basename($dl->event_name)); ?></td><td><?php echo $dl->c; ?></td></tr>
                        <?php endforeach; ?>
                        <?php if(empty($downloads)) echo '<tr><td colspan="2">Geen downloads geregistreerd.</td></tr>'; ?>
                    </table>
                </div>
                <div class="wpa-panel">
                    <h2>Uitgaande Links (Outbound)</h2>
                    <table class="wpa-table">
                        <tr><th>Externe URL</th><th>Kliks</th></tr>
                        <?php foreach($outbound as $ob): ?>
                            <tr><td><?php echo esc_html($ob->event_name); ?></td><td><?php echo $ob->c; ?></td></tr>
                        <?php endforeach; ?>
                    </table>
                </div>
            </div>
        </div>

        <!-- TAB 5: RAPPORTEREN -->
        <div id="tab-rapporten" class="tab-content">
            <div class="wpa-panel-grid" style="align-items:start;">
                
                <div class="wpa-panel">
                    <h2>Marketeer Instellingen</h2>
                    <form method="POST">
                        <?php wp_nonce_field('wpa_save_settings_action', 'wpa_settings_nonce'); ?>
                        <p>
                            <label><strong>Conversie Doel URL (bijv. /bedankt):</strong></label><br>
                            <input type="text" name="wpa_goal_url" value="<?php echo esc_attr($goal_url); ?>" style="width:100%; margin-top:5px;">
                        </p>
                        <p>
                            <label><strong>Ontvang wekelijkse samenvatting (E-mail):</strong></label><br>
                            <input type="email" name="wpa_report_email" value="<?php echo esc_attr($report_email); ?>" style="width:100%; margin-top:5px;">
                        </p>
                        <button type="submit" name="wpa_save_settings" class="button button-primary">Instellingen Opslaan</button>
                    </form>
                    <hr style="margin:20px 0;">
                    <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=brink-analytics&wpa_export=1'), 'wpa_export_csv')); ?>" class="button">📥 Exporteer Data (CSV)</a>
                </div>

                <div class="wpa-panel">
                    <h2>Bot Filtering & Kwaliteit</h2>
                    <p style="font-size:16px;">Er zijn tot nu toe <strong><?php echo $bot_blocks; ?></strong> bekende spam-bots genegeerd door het filter. Je data is zuiver menselijk.</p>
                </div>
                
                <div class="wpa-panel">
                    <h2>Besturingssystemen</h2>
                    <table class="wpa-table">
                        <?php foreach($os_stats as $o): ?>
                            <tr><td><?php echo esc_html($o->os); ?></td><td><?php echo $o->c; ?></td></tr>
                        <?php endforeach; ?>
                    </table>
                </div>

                <div class="wpa-panel">
                    <h2>Locatie (Land) via Cloudflare</h2>
                    <table class="wpa-table">
                        <?php foreach($country_stats as $c): ?>
                            <tr><td><?php echo esc_html($c->country); ?></td><td><?php echo $c->c; ?></td></tr>
                        <?php endforeach; ?>
                        <?php if(empty($country_stats)) echo '<tr><td colspan="2">Geen locatiedata gevonden (Cloudflare vereist).</td></tr>'; ?>
                    </table>
                </div>

            </div>
        </div>

        <!-- TAB 6: PROGNOSES -->
        <div id="tab-prognoses" class="tab-content">
            <div class="wpa-panel-grid" style="grid-template-columns: 1fr;">
                <div class="wpa-panel" style="background: linear-gradient(135deg, #f9f9f9 0%, #ffffff 100%); border-left: 4px solid #cca900;">
                    <h2>🚀 Jouw Groei & Prognoses</h2>
                    <p style="font-size:15px; color:#646970; margin-bottom: 20px;">
                        Op basis van jouw data uit de afgelopen <strong><?php echo round($days_active); ?> dagen</strong>, hebben we berekend wat je kunt verwachten als je op dit tempo doorgaat. Let op: dit is een wiskundige berekening om je richting te geven en doelen op scherp te zetten!
                    </p>

                    <div class="wpa-stats-grid">
                        <div class="wpa-stat-box">
                            <h3>Verwachte Bezoekers (Per jaar)</h3>
                            <p><?php echo number_format_i18n($forecast_yearly_visitors); ?></p>
                            <span style="font-size:12px;color:#888;">Bij huidige koers</span>
                        </div>
                        <div class="wpa-stat-box">
                            <h3>Verwachte Weergaven (Per jaar)</h3>
                            <p><?php echo number_format_i18n($forecast_yearly_views); ?></p>
                            <span style="font-size:12px;color:#888;">Bij huidige koers</span>
                        </div>
                        <div class="wpa-stat-box" style="border-top: 3px solid #4caf50;">
                            <h3>Verwachte Conversies (Per jaar)</h3>
                            <p><?php echo number_format_i18n($forecast_yearly_goals); ?></p>
                            <span style="font-size:12px;color:#888;">Doel: <?php echo esc_html($goal_url); ?></span>
                        </div>
                    </div>

                    <div style="margin-top: 30px; padding: 25px; background: #fff; border: 1px solid #ccd0d4; border-radius: 8px;">
                        <h3 style="margin-top: 0; font-size: 18px;">🏆 Motivatie Doel: <?php echo number_format_i18n($target_yearly_visitors); ?> Bezoekers</h3>
                        <p style="font-size:14px; color:#646970;">Als marketeer wil je natuurlijk altijd blijven groeien. We hebben een ambitieus jaardoel voor je opgesteld dat <strong>25% bovenop je huidige prognose</strong> ligt. Laten we kijken hoe ver je bent met het behalen van dit doel!</p>
                        
                        <div style="width: 100%; background-color: #f0f0f1; border-radius: 20px; height: 28px; margin-top: 20px; overflow: hidden; position: relative;">
                            <div style="width: <?php echo $progress_visitors; ?>%; background-color: <?php echo $progress_visitors >= 100 ? '#4caf50' : '#cca900'; ?>; height: 100%; border-radius: 20px; transition: width 1s ease-in-out;"></div>
                            <span style="position: absolute; width: 100%; text-align: center; top: 5px; left: 0; color: <?php echo $progress_visitors > 50 ? '#fff' : '#333'; ?>; font-size: 13px; font-weight: bold;">
                                <?php echo $progress_visitors; ?>% voltooid van ambitieus jaardoel
                            </span>
                        </div>
                        <p style="font-size:12px; color:#888; text-align:right; margin-top:8px;">
                            Je hebt momenteel <strong><?php echo number_format_i18n($total_all_visitors); ?></strong> van de benodigde <strong><?php echo number_format_i18n($target_yearly_visitors); ?></strong> unieke bezoekers binnen.
                        </p>
                    </div>
                </div>
            </div>
        </div>

    </div>
    <?php
    // De chart-initialisatie wordt als "inline script" aan de wpa-chartjs
    // handle gekoppeld, zodat WordPress hem gegarandeerd ná Chart.js zelf
    // uitvoert, ongeacht waar/wanneer admin footer-scripts worden geprint.
    $wpa_chart_init_js = "
        const ctx = document.getElementById('wpaChart').getContext('2d');
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: " . wp_json_encode($chart_labels) . ",
                datasets: [{
                    label: 'Unieke Bezoekers',
                    data: " . wp_json_encode($chart_data) . ",
                    borderColor: '#cca900',
                    backgroundColor: 'rgba(204, 169, 0, 0.1)',
                    borderWidth: 3,
                    fill: true,
                    tension: 0.4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
            }
        });

        const ctxHour = document.getElementById('wpaHourChart').getContext('2d');
        new Chart(ctxHour, {
            type: 'bar',
            data: {
                labels: " . wp_json_encode($hour_labels) . ",
                datasets: [{
                    label: 'Gemiddeld per uur',
                    data: " . wp_json_encode($hour_data) . ",
                    backgroundColor: 'rgba(54, 162, 235, 0.5)',
                    borderRadius: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: { y: { beginAtZero: true } }
            }
        });
    ";
    wp_add_inline_script('wpa-chartjs', $wpa_chart_init_js, 'after');
}