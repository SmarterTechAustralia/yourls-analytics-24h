<?php
/*
Plugin Name: YOURLS Analytics 24h
Plugin URI: https://github.com/SmarterTechAustralia/yourls-analytics-24h
Description: Admin-only dashboard showing individual YOURLS clicks from the last 24 hours, including time, short link, destination and country.
Version: 2.0.1
Author: Smarter Tech Australia
License: MIT
*/

if (!defined('YOURLS_ABSPATH')) {
    die();
}

yourls_add_action('plugins_loaded', 'ya24_register_page');
yourls_add_action('html_head', 'ya24_admin_css');

function ya24_register_page() {
    yourls_register_plugin_page(
        'ya24-analytics',
        'Analytics 24h',
        'ya24_render_page'
    );
}

function ya24_admin_css() {
    echo '<style>
        .ya24-wrap { max-width: 1250px; }
        .ya24-cards {
            display:flex;
            gap:15px;
            margin:20px 0 30px;
            flex-wrap:wrap;
        }
        .ya24-card {
            background:#fff;
            border:1px solid #dcdcde;
            border-radius:6px;
            padding:20px 28px;
            min-width:150px;
            box-shadow:0 1px 2px rgba(0,0,0,.05);
        }
        .ya24-card strong {
            display:block;
            font-size:30px;
            line-height:1.1;
        }
        .ya24-card span {
            display:block;
            color:#646970;
            margin-top:6px;
        }
        .ya24-table {
            width:100%;
            border-collapse:collapse;
            background:#fff;
        }
        .ya24-table th,
        .ya24-table td {
            padding:9px 10px;
            border-bottom:1px solid #eee;
            text-align:left;
            vertical-align:top;
        }
        .ya24-table th {
            background:#f6f7f7;
            font-weight:600;
        }
        .ya24-time {
            white-space:nowrap;
            color:#50575e;
        }
        .ya24-keyword {
            font-weight:600;
            white-space:nowrap;
        }
        .ya24-destination {
            max-width:420px;
            overflow:hidden;
            text-overflow:ellipsis;
            white-space:nowrap;
        }
        .ya24-country {
            white-space:nowrap;
        }
        .ya24-section {
            margin-top:30px;
        }
        .ya24-muted {
            color:#646970;
        }
        .ya24-table-wrap {
            overflow-x:auto;
        }
        @media(max-width:700px) {
            .ya24-card {
                min-width:120px;
                padding:15px;
            }
            .ya24-destination {
                max-width:220px;
            }
        }
    </style>';
}

function ya24_country_name($code) {
    $countries = array(
        'AU'=>'Australia',
        'US'=>'United States',
        'GB'=>'United Kingdom',
        'CA'=>'Canada',
        'NZ'=>'New Zealand',
        'DE'=>'Germany',
        'FR'=>'France',
        'IT'=>'Italy',
        'ES'=>'Spain',
        'NL'=>'Netherlands',
        'SE'=>'Sweden',
        'NO'=>'Norway',
        'DK'=>'Denmark',
        'FI'=>'Finland',
        'CH'=>'Switzerland',
        'AT'=>'Austria',
        'BE'=>'Belgium',
        'IE'=>'Ireland',
        'IN'=>'India',
        'JP'=>'Japan',
        'CN'=>'China',
        'SG'=>'Singapore',
        'MY'=>'Malaysia',
        'ID'=>'Indonesia',
        'IR'=>'Iran',
        'TR'=>'Türkiye',
        'AE'=>'United Arab Emirates',
        'SA'=>'Saudi Arabia',
        'BR'=>'Brazil',
        'MX'=>'Mexico',
        'ZA'=>'South Africa',
        'RU'=>'Russia',
        'UA'=>'Ukraine'
    );

    $code = strtoupper(trim((string)$code));

    if ($code === '') {
        return 'Unknown';
    }

    return isset($countries[$code]) ? $countries[$code] : $code;
}

function ya24_flag($code) {
    $code = strtoupper(trim((string)$code));

    if (strlen($code) !== 2 ||
        ord($code[0]) < 65 || ord($code[0]) > 90 ||
        ord($code[1]) < 65 || ord($code[1]) > 90) {
        return '🌐';
    }

    return chr(0x1F1E6 + ord($code[0]) - 65)
         . chr(0x1F1E6 + ord($code[1]) - 65);
}

function ya24_render_page() {
    $db = yourls_get_db();

    $log_table = YOURLS_DB_TABLE_LOG;
    $url_table = YOURLS_DB_TABLE_URL;

    /*
     * Individual clicks from the last 24 hours.
     * This is deliberately NOT grouped, so every recorded click
     * can be seen with its time, link and country.
     */
    $clicks_sql = "
        SELECT
            l.click_time,
            l.shorturl,
            l.country_code,
            u.url AS destination
        FROM `$log_table` AS l
        LEFT JOIN `$url_table` AS u
            ON u.keyword = l.shorturl
        WHERE l.click_time >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ORDER BY l.click_time DESC
        LIMIT 1000
    ";

    $clicks = $db->fetchObjects($clicks_sql);

    /*
     * Summary totals.
     */
    $totals_sql = "
        SELECT
            COUNT(*) AS clicks,
            COUNT(DISTINCT shorturl) AS links,
            COUNT(DISTINCT NULLIF(country_code, '')) AS countries
        FROM `$log_table`
        WHERE click_time >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
    ";

    $totals = $db->fetchObject($totals_sql);

    /*
     * Country summary.
     */
    $country_sql = "
        SELECT
            country_code,
            COUNT(*) AS clicks
        FROM `$log_table`
        WHERE click_time >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
        GROUP BY country_code
        ORDER BY clicks DESC
    ";

    $countries = $db->fetchObjects($country_sql);

    /*
     * Link summary.
     */
    $link_sql = "
        SELECT
            l.shorturl,
            u.url AS destination,
            COUNT(*) AS clicks
        FROM `$log_table` AS l
        LEFT JOIN `$url_table` AS u
            ON u.keyword = l.shorturl
        WHERE l.click_time >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
        GROUP BY l.shorturl, u.url
        ORDER BY clicks DESC
        LIMIT 100
    ";

    $links = $db->fetchObjects($link_sql);

    echo '<div class="wrap ya24-wrap">';
    echo '<h2>YOURLS Analytics - Last 24 Hours</h2>';
    echo '<p class="ya24-muted">Individual clicks recorded during the last 24 hours.</p>';

    echo '<div class="ya24-cards">';
    echo '<div class="ya24-card">';
    echo '<strong>' . number_format((int)$totals->clicks) . '</strong>';
    echo '<span>Total clicks</span>';
    echo '</div>';

    echo '<div class="ya24-card">';
    echo '<strong>' . number_format((int)$totals->links) . '</strong>';
    echo '<span>Links</span>';
    echo '</div>';

    echo '<div class="ya24-card">';
    echo '<strong>' . number_format((int)$totals->countries) . '</strong>';
    echo '<span>Countries</span>';
    echo '</div>';
    echo '</div>';

    /*
     * Main activity table.
     */
    echo '<div class="ya24-section">';
    echo '<h3>Click Activity</h3>';

    if (!$clicks) {
        echo '<p>No clicks recorded in the last 24 hours.</p>';
    } else {
        echo '<div class="ya24-table-wrap">';
        echo '<table class="widefat striped ya24-table">';
        echo '<thead><tr>';
        echo '<th>Time</th>';
        echo '<th>Short Link</th>';
        echo '<th>Destination</th>';
        echo '<th>Country</th>';
        echo '</tr></thead>';
        echo '<tbody>';

        foreach ($clicks as $click) {
            $keyword = (string)$click->shorturl;
            $destination = (string)$click->destination;
            $country = (string)$click->country_code;

            echo '<tr>';

            echo '<td class="ya24-time">';
            echo esc_html($click->click_time);
            echo '</td>';

            echo '<td class="ya24-keyword">';
            echo '<a href="' . esc_url(yourls_link($keyword)) . '" target="_blank" rel="noopener noreferrer">';
            echo esc_html($keyword);
            echo '</a>';
            echo '</td>';

            echo '<td class="ya24-destination" title="' . esc_attr($destination) . '">';
            echo esc_html($destination);
            echo '</td>';

            echo '<td class="ya24-country">';
            echo esc_html(ya24_flag($country)) . ' ';
            echo esc_html(ya24_country_name($country));
            echo '</td>';

            echo '</tr>';
        }

        echo '</tbody>';
        echo '</table>';
        echo '</div>';

        if (count($clicks) >= 1000) {
            echo '<p class="ya24-muted">Showing the latest 1,000 clicks.</p>';
        }
    }

    echo '</div>';

    /*
     * Country summary.
     */
    echo '<div class="ya24-section">';
    echo '<h3>Countries</h3>';

    if ($countries) {
        echo '<div class="ya24-table-wrap">';
        echo '<table class="widefat striped ya24-table">';
        echo '<thead><tr><th>Country</th><th>Clicks</th></tr></thead><tbody>';

        foreach ($countries as $country) {
            $code = (string)$country->country_code;

            echo '<tr>';
            echo '<td class="ya24-country">';
            echo esc_html(ya24_flag($code)) . ' ';
            echo esc_html(ya24_country_name($code));
            echo '</td>';
            echo '<td><strong>' . number_format((int)$country->clicks) . '</strong></td>';
            echo '</tr>';
        }

        echo '</tbody></table></div>';
    }

    echo '</div>';

    /*
     * Link summary.
     */
    echo '<div class="ya24-section">';
    echo '<h3>Links</h3>';

    if ($links) {
        echo '<div class="ya24-table-wrap">';
        echo '<table class="widefat striped ya24-table">';
        echo '<thead><tr>';
        echo '<th>Short Link</th>';
        echo '<th>Destination</th>';
        echo '<th>Clicks</th>';
        echo '</tr></thead><tbody>';

        foreach ($links as $link) {
            $keyword = (string)$link->shorturl;
            $destination = (string)$link->destination;

            echo '<tr>';

            echo '<td class="ya24-keyword">';
            echo '<a href="' . esc_url(yourls_link($keyword)) . '" target="_blank" rel="noopener noreferrer">';
            echo esc_html($keyword);
            echo '</a>';
            echo '</td>';

            echo '<td class="ya24-destination" title="' . esc_attr($destination) . '">';
            echo esc_html($destination);
            echo '</td>';

            echo '<td><strong>' . number_format((int)$link->clicks) . '</strong></td>';

            echo '</tr>';
        }

        echo '</tbody></table></div>';
    }

    echo '</div>';
    echo '</div>';
}
