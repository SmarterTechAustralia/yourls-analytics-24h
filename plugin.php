<?php
/*
Plugin Name: YOURLS Analytics 24h
Plugin URI: https://github.com/SmarterTechAustralia/yourls-analytics-24h
Description: Admin-only dashboard showing individual YOURLS clicks from the last 24 hours.
Version: 3.2.0
Author: Smarter Tech Australia
License: MIT
*/

if (!defined('YOURLS_ABSPATH')) {
    die();
}

yourls_add_action('plugins_loaded', 'ya24_register_page');

function ya24_register_page() {
    yourls_register_plugin_page('ya24-analytics', 'Analytics 24h', 'ya24_render_page');
}

function ya24_h($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function ya24_country_name($code) {
    $code = strtoupper(trim((string) $code));
    $countries = array(
        'AU'=>'Australia','AT'=>'Austria','BE'=>'Belgium','BR'=>'Brazil','CA'=>'Canada',
        'CH'=>'Switzerland','CN'=>'China','DE'=>'Germany','DK'=>'Denmark','ES'=>'Spain',
        'FI'=>'Finland','FR'=>'France','GB'=>'United Kingdom','HK'=>'Hong Kong','ID'=>'Indonesia',
        'IE'=>'Ireland','IN'=>'India','IR'=>'Iran','IT'=>'Italy','JP'=>'Japan','KR'=>'South Korea',
        'MY'=>'Malaysia','NL'=>'Netherlands','NO'=>'Norway','NZ'=>'New Zealand','PK'=>'Pakistan',
        'PL'=>'Poland','PT'=>'Portugal','RU'=>'Russia','SA'=>'Saudi Arabia','SE'=>'Sweden',
        'SG'=>'Singapore','TH'=>'Thailand','TR'=>'Turkey','TW'=>'Taiwan','UA'=>'Ukraine',
        'US'=>'United States','VN'=>'Vietnam','ZA'=>'South Africa'
    );
    return $code === '' ? 'Unknown' : (isset($countries[$code]) ? $countries[$code] : $code);
}

function ya24_flag($code) {
    $code = strtoupper(trim((string) $code));
    if (!preg_match('/^[A-Z]{2}$/', $code)) {
        return '';
    }
    $a = 127397;
    return html_entity_decode('&#' . ($a + ord($code[0])) . ';', ENT_NOQUOTES, 'UTF-8')
         . html_entity_decode('&#' . ($a + ord($code[1])) . ';', ENT_NOQUOTES, 'UTF-8');
}

function ya24_table_name($suffix) {
    // YOURLS config.php defines YOURLS_DB_PREFIX, for example: yprek7_
    // Always derive table names from that prefix so the plugin works with any YOURLS installation.
    if (!defined('YOURLS_DB_PREFIX')) {
        return false;
    }

    $prefix = (string) YOURLS_DB_PREFIX;
    $name = $prefix . $suffix;

    return preg_match('/^[A-Za-z0-9_]+$/', $name) ? $name : false;
}

function ya24_get_columns($ydb, $table) {
    $rows = $ydb->get_results("SHOW COLUMNS FROM `{$table}`");
    $columns = array();
    if (is_array($rows)) {
        foreach ($rows as $row) {
            if (isset($row->Field)) {
                $columns[$row->Field] = true;
            }
        }
    }
    return $columns;
}

function ya24_db_error($ydb) {
    if (isset($ydb->last_error) && trim((string) $ydb->last_error) !== '') {
        return (string) $ydb->last_error;
    }
    return '';
}

function ya24_render_page() {
    global $ydb;

    echo '<div class="ya24-wrap">';

    if (!isset($ydb)) {
        echo '<div class="error"><p><strong>YOURLS Analytics:</strong> database object is not available.</p></div></div>';
        return;
    }

    $log_table = ya24_table_name('log');
    $url_table = ya24_table_name('url');

    if (!$log_table || !$url_table) {
        echo '<div class="error"><p><strong>YOURLS Analytics:</strong> could not determine YOURLS table names.</p></div></div>';
        return;
    }

    // Discover the actual schema instead of assuming optional columns exist.
    $log_columns = ya24_get_columns($ydb, $log_table);
    $url_columns = ya24_get_columns($ydb, $url_table);

    $required = array('click_time', 'shorturl', 'country_code');
    foreach ($required as $column) {
        if (!isset($log_columns[$column])) {
            echo '<div class="error"><p><strong>YOURLS Analytics:</strong> required column <code>' . ya24_h($column) . '</code> was not found in <code>' . ya24_h($log_table) . '</code>.</p></div></div>';
            return;
        }
    }

    $has_destination = isset($url_columns['keyword']) && isset($url_columns['url']);
    $has_ip = isset($log_columns['ip_address']);
    $has_referrer = isset($log_columns['referrer']);
    $has_user_agent = isset($log_columns['user_agent']);

    // Summary. Only use columns confirmed to exist.
    $summary_sql = "
        SELECT
            COUNT(*) AS total_clicks,
            COUNT(DISTINCT shorturl) AS total_links,
            COUNT(DISTINCT NULLIF(country_code, '')) AS total_countries
        FROM `{$log_table}`
        WHERE click_time >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
    ";
    $summary = $ydb->get_row($summary_sql);
    $summary_error = ya24_db_error($ydb);

    if ($summary_error) {
        echo '<div class="error"><p><strong>Summary query failed:</strong> ' . ya24_h($summary_error) . '</p></div></div>';
        return;
    }

    $total_clicks = isset($summary->total_clicks) ? (int) $summary->total_clicks : 0;
    $total_links = isset($summary->total_links) ? (int) $summary->total_links : 0;
    $total_countries = isset($summary->total_countries) ? (int) $summary->total_countries : 0;

    // Individual click query. The only mandatory log fields are the fields we verified above.
    $select = array(
        'l.click_time',
        'l.shorturl',
        'l.country_code'
    );
    if ($has_ip) $select[] = 'l.ip_address';
    if ($has_referrer) $select[] = 'l.referrer';
    if ($has_user_agent) $select[] = 'l.user_agent';
    if ($has_destination) $select[] = 'u.url AS destination';

    $click_sql = "SELECT " . implode(', ', $select) . " FROM `{$log_table}` AS l";
    if ($has_destination) {
        $click_sql .= " LEFT JOIN `{$url_table}` AS u ON u.keyword = l.shorturl";
    }
    $click_sql .= " WHERE l.click_time >= DATE_SUB(NOW(), INTERVAL 24 HOUR) ORDER BY l.click_time DESC LIMIT 1000";

    $clicks = $ydb->get_results($click_sql);
    $click_error = ya24_db_error($ydb);

    // Country summary.
    $country_sql = "
        SELECT country_code, COUNT(*) AS clicks
        FROM `{$log_table}`
        WHERE click_time >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
        GROUP BY country_code
        ORDER BY clicks DESC
    ";
    $countries = $ydb->get_results($country_sql);
    $country_error = ya24_db_error($ydb);

    // Link summary.
    if ($has_destination) {
        $link_sql = "
            SELECT l.shorturl, COUNT(*) AS clicks, u.url AS destination
            FROM `{$log_table}` AS l
            LEFT JOIN `{$url_table}` AS u ON u.keyword = l.shorturl
            WHERE l.click_time >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
            GROUP BY l.shorturl, u.url
            ORDER BY clicks DESC
            LIMIT 100
        ";
    } else {
        $link_sql = "
            SELECT shorturl, COUNT(*) AS clicks
            FROM `{$log_table}`
            WHERE click_time >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
            GROUP BY shorturl
            ORDER BY clicks DESC
            LIMIT 100
        ";
    }
    $links = $ydb->get_results($link_sql);
    $link_error = ya24_db_error($ydb);

    ?>
    <style>
        .ya24-wrap{max-width:1250px;margin:20px auto;font-family:Arial,sans-serif;color:#333}
        .ya24-wrap h1{margin:0 0 6px}.ya24-description{color:#666;margin-bottom:20px}
        .ya24-cards{display:flex;gap:15px;margin-bottom:25px;flex-wrap:wrap}
        .ya24-card{background:#fff;border:1px solid #ddd;border-radius:6px;padding:18px 25px;min-width:180px;box-sizing:border-box}
        .ya24-number{font-size:32px;font-weight:bold}.ya24-label{color:#555;margin-top:5px}
        .ya24-section{margin-top:28px}.ya24-table-wrap{overflow-x:auto;border:1px solid #ddd;background:#fff}
        .ya24-table{width:100%;border-collapse:collapse;font-size:13px}.ya24-table th{background:#f5f5f5;text-align:left;padding:10px;border-bottom:2px solid #ddd;white-space:nowrap}
        .ya24-table td{padding:9px 10px;border-bottom:1px solid #eee;vertical-align:top}.ya24-table tr:hover{background:#fafafa}
        .ya24-destination{max-width:480px;word-break:break-word}.ya24-muted{color:#888}.ya24-code{font-family:monospace}
        .ya24-error{margin:15px 0;padding:12px;background:#fff0f0;border-left:4px solid #c00}.ya24-ok{margin:15px 0;padding:10px;background:#f1fff1;border-left:4px solid #398a3c}
        .ya24-country{white-space:nowrap}.ya24-small{font-size:12px;color:#777}.ya24-refresh{float:right;margin-bottom:10px}
    </style>

    <div class="ya24-refresh"><a class="button" href="<?php echo ya24_h(yourls_link('ya24-analytics', 'plugins-page')); ?>">Refresh</a></div>
    <h1>YOURLS Analytics - Last 24 Hours</h1>
    <p class="ya24-description">Individual clicks recorded during the last 24 hours.</p>

    <?php if ($click_error): ?>
        <div class="ya24-error"><strong>Click Activity query failed:</strong><br><?php echo ya24_h($click_error); ?></div>
    <?php endif; ?>

    <div class="ya24-cards">
        <div class="ya24-card"><div class="ya24-number"><?php echo $total_clicks; ?></div><div class="ya24-label">Total clicks</div></div>
        <div class="ya24-card"><div class="ya24-number"><?php echo $total_links; ?></div><div class="ya24-label">Links</div></div>
        <div class="ya24-card"><div class="ya24-number"><?php echo $total_countries; ?></div><div class="ya24-label">Countries</div></div>
    </div>

    <div class="ya24-section">
        <h2>Click Activity</h2>
        <p class="ya24-small">Showing up to 1,000 individual clicks, newest first.</p>
        <div class="ya24-table-wrap">
            <table class="ya24-table">
                <thead><tr>
                    <th>Time</th><th>Short Link</th><th>Destination</th><th>Country</th>
                    <?php if ($has_ip): ?><th>IP Address</th><?php endif; ?>
                    <?php if ($has_referrer): ?><th>Referrer</th><?php endif; ?>
                </tr></thead>
                <tbody>
                <?php if (is_array($clicks) && count($clicks)): ?>
                    <?php foreach ($clicks as $row): ?>
                        <tr>
                            <td><?php echo ya24_h(isset($row->click_time) ? $row->click_time : ''); ?></td>
                            <td class="ya24-code"><?php echo ya24_h(isset($row->shorturl) ? $row->shorturl : ''); ?></td>
                            <td class="ya24-destination">
                                <?php if (isset($row->destination) && $row->destination !== ''): ?>
                                    <?php echo ya24_h($row->destination); ?>
                                <?php else: ?><span class="ya24-muted">URL not found</span><?php endif; ?>
                            </td>
                            <td class="ya24-country">
                                <?php $cc = isset($row->country_code) ? $row->country_code : ''; ?>
                                <?php echo ya24_h(ya24_flag($cc)); ?> <?php echo ya24_h(ya24_country_name($cc)); ?>
                            </td>
                            <?php if ($has_ip): ?><td><?php echo ya24_h(isset($row->ip_address) ? $row->ip_address : ''); ?></td><?php endif; ?>
                            <?php if ($has_referrer): ?><td class="ya24-destination"><?php echo ya24_h(isset($row->referrer) ? $row->referrer : ''); ?></td><?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="6" class="ya24-muted">No individual clicks were returned by the log query.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="ya24-section">
        <h2>Clicks by Country</h2>
        <?php if ($country_error): ?><div class="ya24-error"><strong>Country query failed:</strong> <?php echo ya24_h($country_error); ?></div>
        <?php elseif (is_array($countries) && count($countries)): ?>
            <div class="ya24-table-wrap"><table class="ya24-table"><thead><tr><th>Country</th><th>Clicks</th></tr></thead><tbody>
            <?php foreach ($countries as $row): ?>
                <?php $cc = isset($row->country_code) ? $row->country_code : ''; ?>
                <tr><td><?php echo ya24_h(ya24_flag($cc)); ?> <?php echo ya24_h(ya24_country_name($cc)); ?></td><td><?php echo (int) $row->clicks; ?></td></tr>
            <?php endforeach; ?>
            </tbody></table></div>
        <?php else: ?><p class="ya24-muted">No country data.</p><?php endif; ?>
    </div>

    <div class="ya24-section">
        <h2>Clicks by Short Link</h2>
        <?php if ($link_error): ?><div class="ya24-error"><strong>Link query failed:</strong> <?php echo ya24_h($link_error); ?></div>
        <?php elseif (is_array($links) && count($links)): ?>
            <div class="ya24-table-wrap"><table class="ya24-table"><thead><tr><th>Short Link</th><th>Clicks</th><th>Destination</th></tr></thead><tbody>
            <?php foreach ($links as $row): ?>
                <tr><td class="ya24-code"><?php echo ya24_h(isset($row->shorturl) ? $row->shorturl : ''); ?></td><td><?php echo (int) $row->clicks; ?></td><td class="ya24-destination"><?php echo ya24_h(isset($row->destination) ? $row->destination : 'URL not found'); ?></td></tr>
            <?php endforeach; ?>
            </tbody></table></div>
        <?php else: ?><p class="ya24-muted">No link data.</p><?php endif; ?>
    </div>
    <?php
    echo '</div>';
}
