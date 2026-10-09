<?php
/*
Plugin Name: YOURLS Analytics 24h
Plugin URI: https://github.com/SmarterTechAustralia/yourls-analytics-24h
Description: Admin-only dashboard showing individual YOURLS clicks from the last 24 hours.
Version: 3.4.0
Author: Smarter Tech Australia
License: MIT
*/

if ( ! defined('YOURLS_ABSPATH') ) {
    die();
}

yourls_add_action('plugins_loaded', 'ya24_register_page');

function ya24_register_page() {
    yourls_register_plugin_page('ya24-analytics', 'Analytics 24h', 'ya24_render_page');
}

function ya24_escape($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function ya24_ipv4($address) {
    $address = trim((string) $address);

    if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return $address;
    }

    if (preg_match('/^::ffff:(\d{1,3}(?:\.\d{1,3}){3})$/i', $address, $matches)
        && filter_var($matches[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return $matches[1];
    }

    return '';
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

    if ($code === '') {
        return 'Unknown';
    }

    return isset($countries[$code]) ? $countries[$code] : $code;
}

function ya24_utf8_chr($codepoint) {
    if ($codepoint < 0x80) {
        return chr($codepoint);
    }
    if ($codepoint < 0x800) {
        return chr(0xC0 | ($codepoint >> 6)) . chr(0x80 | ($codepoint & 0x3F));
    }
    if ($codepoint < 0x10000) {
        return chr(0xE0 | ($codepoint >> 12)) . chr(0x80 | (($codepoint >> 6) & 0x3F)) . chr(0x80 | ($codepoint & 0x3F));
    }
    return chr(0xF0 | ($codepoint >> 18)) . chr(0x80 | (($codepoint >> 12) & 0x3F)) . chr(0x80 | (($codepoint >> 6) & 0x3F)) . chr(0x80 | ($codepoint & 0x3F));
}


function ya24_stats_url($shorturl) {
    $base = defined('YOURLS_SITE') ? rtrim(YOURLS_SITE, '/') : '';
    return $base . '/' . rawurlencode((string) $shorturl) . '+';
}

function ya24_flag($code) {
    $code = strtoupper(trim((string) $code));

    if (strlen($code) !== 2 || !preg_match('/^[A-Z]{2}$/', $code)) {
        return '';
    }

    $first = 0x1F1E6 + (ord($code[0]) - 65);
    $second = 0x1F1E6 + (ord($code[1]) - 65);

    return ya24_utf8_chr($first) . ya24_utf8_chr($second);
}

function ya24_render_page() {
    global $ydb;

    echo '<div class="ya24-wrap">';

    if (!isset($ydb)) {
        echo '<div class="ya24-error"><strong>YOURLS Analytics:</strong> database object is not available.</div></div>';
        return;
    }

    if (!defined('YOURLS_DB_PREFIX')) {
        echo '<div class="ya24-error"><strong>YOURLS Analytics:</strong> YOURLS_DB_PREFIX is not defined.</div></div>';
        return;
    }

    $prefix = (string) YOURLS_DB_PREFIX;

    if (!preg_match('/^[A-Za-z0-9_]+$/', $prefix)) {
        echo '<div class="ya24-error"><strong>YOURLS Analytics:</strong> invalid YOURLS_DB_PREFIX.</div></div>';
        return;
    }

    $log_table = $prefix . 'log';
    $url_table = $prefix . 'url';

    echo '<style>';
    echo '.ya24-wrap{max-width:1250px;margin:20px auto;padding:0 10px;font-family:Arial,sans-serif;color:#333}';
    echo '.ya24-wrap h1{margin:0;font-size:24px}.ya24-wrap h2{margin:0 0 10px;font-size:18px}.ya24-header{display:flex;align-items:center;justify-content:space-between;gap:15px;margin:0 0 6px}';
    echo '.ya24-description{color:#666;margin:0 0 20px}.ya24-cards{display:flex;gap:15px;margin:0 0 25px;flex-wrap:wrap}';
    echo '.ya24-card{background:#fff;border:1px solid #ddd;border-radius:6px;padding:18px 25px;min-width:180px;box-sizing:border-box}';
    echo '.ya24-number{font-size:32px;font-weight:bold}.ya24-label{color:#555;margin-top:5px}';
    echo '.ya24-section{margin-top:28px}.ya24-table-wrap{overflow-x:auto;border:1px solid #ddd;background:#fff}';
    echo '.ya24-table{width:100%;border-collapse:collapse;font-size:13px}.ya24-table th{background:#f5f5f5;text-align:left;padding:0;border-bottom:2px solid #ddd;white-space:nowrap}';
    echo '.ya24-sort{width:100%;padding:10px;border:0;background:transparent;color:inherit;font:inherit;font-weight:bold;text-align:left;cursor:pointer}.ya24-sort:hover{background:#e9e9e9}.ya24-sort::after{content:" ↕";color:#999}.ya24-sort[data-direction="asc"]::after{content:" ↑"}.ya24-sort[data-direction="desc"]::after{content:" ↓"}';
    echo '.ya24-table td{padding:9px 10px;border-bottom:1px solid #eee;vertical-align:top}.ya24-table tr:hover{background:#fafafa}';
    echo '.ya24-table tr.ya24-busiest td{background:#ccebd5!important;border-bottom-color:#69a879}.ya24-table tr.ya24-busiest:hover td{background:#b5dfc1!important}.ya24-table tr.ya24-second-busiest td{background:#fff3bf!important;border-bottom-color:#dfc969}.ya24-table tr.ya24-second-busiest:hover td{background:#ffeba0!important}.ya24-rank-label{display:inline-block;margin-left:6px;padding:2px 5px;border-radius:3px;color:#fff;font-family:Arial,sans-serif;font-size:10px;font-weight:bold;text-transform:uppercase}.ya24-busiest-label{background:#287a3e}.ya24-second-label{background:#8a6a00}.ya24-link-clicks{font-size:16px;font-weight:bold;text-align:center}';
    echo '.ya24-destination{max-width:500px;word-break:break-all}.ya24-referrer{max-width:240px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.ya24-visitor-ip{display:block;margin-top:4px;font-family:monospace;font-size:12px}.ya24-code{font-family:monospace}.ya24-country{white-space:nowrap}';
    echo '.ya24-error{background:#fff0f0;border-left:4px solid #c00;padding:12px;margin:15px 0}.ya24-debug{background:#f5f5f5;border:1px solid #ddd;padding:8px;margin-bottom:15px;font-size:12px;color:#666}';
    echo '.ya24-muted{color:#888}.ya24-stats{font-weight:bold;text-decoration:none;margin-left:4px}.ya24-stats:hover{text-decoration:underline}.ya24-refresh{flex:0 0 auto}.ya24-refresh a{display:inline-block;padding:6px 12px;background:#eee;border:1px solid #ccc;text-decoration:none;border-radius:3px}';
    echo '@media(max-width:600px){.ya24-header{align-items:flex-start;flex-direction:column}.ya24-refresh{align-self:flex-end}}';
    echo '</style>';

    echo '<div class="ya24-header">';
    echo '<h1>YOURLS Analytics - Last 24 Hours</h1>';
    echo '<div class="ya24-refresh"><a href="' . ya24_escape(isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '') . '">Refresh</a></div>';
    echo '</div>';
    echo '<p class="ya24-description">Individual clicks recorded during the last 24 hours.</p>';

    $summary = null;
    $summary_error = '';

    try {
        $summary_sql = "SELECT COUNT(*) AS total_clicks, COUNT(DISTINCT shorturl) AS total_links, COUNT(DISTINCT NULLIF(country_code, '')) AS total_countries, SUM(CASE WHEN referrer IS NOT NULL AND TRIM(referrer) <> '' AND LOWER(TRIM(referrer)) <> 'direct' THEN 1 ELSE 0 END) AS clicks_with_referrer FROM `{$log_table}` WHERE click_time >= DATE_SUB(NOW(), INTERVAL 24 HOUR)";
        $summary = $ydb->fetchOne($summary_sql);
    } catch (Throwable $e) {
        $summary_error = $e->getMessage();
    }

    if ($summary_error !== '') {
        echo '<div class="ya24-error"><strong>Summary SQL error:</strong><br>' . ya24_escape($summary_error) . '</div>';
    }

    $total_clicks = 0;
    $total_links = 0;
    $total_countries = 0;
    $clicks_with_referrer = 0;

    if (is_array($summary)) {
        if (isset($summary['total_clicks'])) {
            $total_clicks = (int) $summary['total_clicks'];
        }
        if (isset($summary['total_links'])) {
            $total_links = (int) $summary['total_links'];
        }
        if (isset($summary['total_countries'])) {
            $total_countries = (int) $summary['total_countries'];
        }
        if (isset($summary['clicks_with_referrer'])) {
            $clicks_with_referrer = (int) $summary['clicks_with_referrer'];
        }
    }

    echo '<div class="ya24-cards">';
    echo '<div class="ya24-card"><div class="ya24-number">' . $total_clicks . '</div><div class="ya24-label">Total clicks</div></div>';
    echo '<div class="ya24-card"><div class="ya24-number">' . $total_links . '</div><div class="ya24-label">Links</div></div>';
    echo '<div class="ya24-card"><div class="ya24-number">' . $total_countries . '</div><div class="ya24-label">Countries</div></div>';
    echo '<div class="ya24-card"><div class="ya24-number">' . $clicks_with_referrer . '</div><div class="ya24-label">With referrer</div></div>';
    echo '</div>';

    $clicks = array();
    $click_error = '';

    try {
        $click_sql = "SELECT l.click_id, l.click_time, l.shorturl, l.referrer, l.ip_address, l.country_code, totals.link_clicks, u.url AS destination FROM `{$log_table}` AS l LEFT JOIN `{$url_table}` AS u ON u.keyword = l.shorturl LEFT JOIN (SELECT shorturl, COUNT(*) AS link_clicks FROM `{$log_table}` WHERE click_time >= DATE_SUB(NOW(), INTERVAL 24 HOUR) GROUP BY shorturl) AS totals ON totals.shorturl = l.shorturl WHERE l.click_time >= DATE_SUB(NOW(), INTERVAL 24 HOUR) ORDER BY l.click_time DESC LIMIT 1000";
        $clicks = $ydb->fetchObjects($click_sql);
    } catch (Throwable $e) {
        $click_error = $e->getMessage();
    }

    echo '<div class="ya24-section"><h2>Click Activity</h2>';
    echo '<p class="ya24-muted">One row per click, newest first. Maximum 1,000 rows.</p>';

    if ($click_error !== '') {
        echo '<div class="ya24-error"><strong>Click Activity SQL error:</strong><br>' . ya24_escape($click_error) . '</div>';
    }

    echo '<div class="ya24-table-wrap"><table class="ya24-table" id="ya24-click-table">';
    echo '<thead><tr>';
    echo '<th><button class="ya24-sort" type="button" data-type="text">Time</button></th>';
    echo '<th><button class="ya24-sort" type="button" data-type="number">Click ID</button></th>';
    echo '<th><button class="ya24-sort" type="button" data-type="text">Short Link</button></th>';
    echo '<th><button class="ya24-sort" type="button" data-type="number">Link Clicks</button></th>';
    echo '<th><button class="ya24-sort" type="button" data-type="text">Destination</button></th>';
    echo '<th><button class="ya24-sort" type="button" data-type="text">Referrer</button></th>';
    echo '<th><button class="ya24-sort" type="button" data-type="text">Country</button></th>';
    echo '</tr></thead><tbody>';

    if (is_array($clicks) && count($clicks) > 0) {
        $ranked_click_totals = array();

        foreach ($clicks as $row) {
            $row_link_clicks = isset($row->link_clicks) ? (int) $row->link_clicks : 0;

            if ($row_link_clicks > 0) {
                $ranked_click_totals[$row_link_clicks] = true;
            }
        }

        $ranked_click_totals = array_keys($ranked_click_totals);
        rsort($ranked_click_totals, SORT_NUMERIC);
        $busiest_clicks = isset($ranked_click_totals[0]) ? (int) $ranked_click_totals[0] : 0;
        $second_busiest_clicks = isset($ranked_click_totals[1]) ? (int) $ranked_click_totals[1] : 0;

        foreach ($clicks as $row) {
            $cc = isset($row->country_code) ? strtoupper(trim($row->country_code)) : '';
            $destination = isset($row->destination) ? $row->destination : '';
            $time = isset($row->click_time) ? $row->click_time : '';
            $shorturl = isset($row->shorturl) ? $row->shorturl : '';
            $click_id = isset($row->click_id) ? $row->click_id : '';
            $referrer = isset($row->referrer) ? trim($row->referrer) : '';
            $is_direct = $referrer === '' || strtolower($referrer) === 'direct';
            $referrer_host = $is_direct ? '' : parse_url($referrer, PHP_URL_HOST);
            $referrer_label = $referrer_host ? $referrer_host : $referrer;
            $is_referrer_url = filter_var($referrer, FILTER_VALIDATE_URL) && preg_match('/^https?:\/\//i', $referrer);
            $visitor_ipv4 = ya24_ipv4(isset($row->ip_address) ? $row->ip_address : '');
            $link_clicks = isset($row->link_clicks) ? (int) $row->link_clicks : 0;
            $is_busiest = $link_clicks > 0 && $link_clicks === $busiest_clicks;
            $is_second_busiest = $link_clicks > 0 && $link_clicks === $second_busiest_clicks;
            $row_class = $is_busiest ? 'ya24-busiest' : ($is_second_busiest ? 'ya24-second-busiest' : '');

            echo '<tr' . ($row_class !== '' ? ' class="' . $row_class . '"' : '') . '>';
            echo '<td>' . ya24_escape($time) . '</td>';
            echo '<td class="ya24-code">' . ($click_id !== '' ? ya24_escape($click_id) : '<span class="ya24-muted">-</span>') . '</td>';
            echo '<td class="ya24-code">' . ya24_escape($shorturl) . ' <a class="ya24-stats" href="' . ya24_escape(ya24_stats_url($shorturl)) . '" target="_blank" rel="noopener" title="View YOURLS statistics">+</a>';
            echo $is_busiest
                ? '<span class="ya24-rank-label ya24-busiest-label">Busiest</span>'
                : ($is_second_busiest ? '<span class="ya24-rank-label ya24-second-label">Second</span>' : '');
            echo '</td>';
            echo '<td class="ya24-link-clicks">' . $link_clicks . '</td>';
            echo '<td class="ya24-destination">' . ($destination !== '' ? ya24_escape($destination) : '<span class="ya24-muted">URL not found</span>') . '</td>';
            echo '<td class="ya24-referrer">';
            echo $is_direct
                ? '<span class="ya24-muted">Direct</span>'
                : ($is_referrer_url
                    ? '<a href="' . ya24_escape($referrer) . '" target="_blank" rel="noopener noreferrer" title="' . ya24_escape($referrer) . '">' . ya24_escape($referrer_label) . '</a>'
                    : ya24_escape($referrer_label));
                    echo '<span class="ya24-visitor-ip ya24-muted">IPv4: ' . ($visitor_ipv4 !== '' ? ya24_escape($visitor_ipv4) : 'unavailable') . '</span>';
            echo '</td>';
            echo '<td class="ya24-country">' . ya24_escape(ya24_flag($cc)) . ' ' . ya24_escape(ya24_country_name($cc)) . '</td>';
            echo '</tr>';
        }
    } elseif ($click_error === '') {
        echo '<tr><td colspan="7" class="ya24-muted">No clicks returned by the individual click query.</td></tr>';
    }

    echo '</tbody></table></div></div>';
    echo '<script>';
    echo '(function(){var table=document.getElementById("ya24-click-table");if(!table){return;}var buttons=table.querySelectorAll(".ya24-sort");buttons.forEach(function(button,column){button.addEventListener("click",function(){var direction=button.getAttribute("data-direction")==="asc"?"desc":"asc";buttons.forEach(function(item){item.removeAttribute("data-direction");});button.setAttribute("data-direction",direction);var rows=Array.prototype.slice.call(table.tBodies[0].rows);rows.sort(function(a,b){var left=a.cells[column].textContent.trim();var right=b.cells[column].textContent.trim();var comparison=button.getAttribute("data-type")==="number"?(Number(left)||0)-(Number(right)||0):left.localeCompare(right,undefined,{numeric:true,sensitivity:"base"});return direction==="asc"?comparison:-comparison;});rows.forEach(function(row){table.tBodies[0].appendChild(row);});});});})();';
    echo '</script>';
    echo '</div>';
}
