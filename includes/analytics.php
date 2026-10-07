<?php

function ya24_get_table() {
    return YOURLS_DB_TABLE_LOG;
}

function ya24_get_clicks() {
    $db = yourls_get_db();

    $table = ya24_get_table();

    $sql = "
        SELECT
            l.shorturl AS keyword,
            l.country_code AS country,
            COUNT(*) AS clicks
        FROM `$table` l
        WHERE l.click_time >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
        GROUP BY l.shorturl, l.country_code
        ORDER BY clicks DESC, l.shorturl ASC
    ";

    return $db->fetchObjects($sql);
}

function ya24_get_totals() {
    $db = yourls_get_db();
    $table = ya24_get_table();

    $sql = "
        SELECT
            COUNT(*) AS total_clicks,
            COUNT(DISTINCT shorturl) AS unique_links,
            COUNT(DISTINCT NULLIF(country_code, '')) AS countries
        FROM `$table`
        WHERE click_time >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
    ";

    return $db->fetchObject($sql);
}

function ya24_get_hourly() {
    $db = yourls_get_db();
    $table = ya24_get_table();

    $sql = "
        SELECT
            DATE_FORMAT(click_time, '%Y-%m-%d %H:00:00') AS hour,
            COUNT(*) AS clicks
        FROM `$table`
        WHERE click_time >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
        GROUP BY DATE_FORMAT(click_time, '%Y-%m-%d %H:00:00')
        ORDER BY hour ASC
    ";

    return $db->fetchObjects($sql);
}

function ya24_get_long_url($keyword) {
    $db = yourls_get_db();

    $table = YOURLS_DB_TABLE_URL;

    $keyword = yourls_escape($keyword);

    $sql = "SELECT url FROM `$table` WHERE keyword = '$keyword' LIMIT 1";

    $result = $db->fetchObject($sql);

    return $result ? $result->url : '';
}

function ya24_country_name($code) {
    $countries = array(
        'AU' => 'Australia',
        'US' => 'United States',
        'GB' => 'United Kingdom',
        'CA' => 'Canada',
        'NZ' => 'New Zealand',
        'DE' => 'Germany',
        'FR' => 'France',
        'IT' => 'Italy',
        'ES' => 'Spain',
        'NL' => 'Netherlands',
        'SE' => 'Sweden',
        'NO' => 'Norway',
        'DK' => 'Denmark',
        'FI' => 'Finland',
        'CH' => 'Switzerland',
        'AT' => 'Austria',
        'BE' => 'Belgium',
        'IE' => 'Ireland',
        'IN' => 'India',
        'JP' => 'Japan',
        'CN' => 'China',
        'SG' => 'Singapore',
        'MY' => 'Malaysia',
        'ID' => 'Indonesia',
        'IR' => 'Iran',
        'TR' => 'Türkiye',
        'AE' => 'United Arab Emirates',
        'SA' => 'Saudi Arabia',
        'BR' => 'Brazil',
        'MX' => 'Mexico',
        'ZA' => 'South Africa',
        'RU' => 'Russia',
        'UA' => 'Ukraine',
    );

    if (!$code) {
        return 'Unknown';
    }

    $code = strtoupper($code);

    return isset($countries[$code]) ? $countries[$code] : $code;
}

function ya24_country_flag($code) {
    if (!$code || strlen($code) !== 2) {
        return '🌐';
    }

    $code = strtoupper($code);

    return chr(0x1F1E6 + ord($code[0]) - ord('A'))
        . chr(0x1F1E6 + ord($code[1]) - ord('A'));
}

function ya24_render_dashboard() {
    $totals = ya24_get_totals();
    $rows = ya24_get_clicks();
    $hourly = ya24_get_hourly();

    echo '<div class="wrap">';
    echo '<h2>YOURLS Analytics - Last 24 Hours</h2>';
    echo '<p class="ya24-updated">Data from the last 24 hours. Refresh the page to update.</p>';

    echo '<div class="ya24-cards">';
    echo '<div class="ya24-card"><strong>' . number_format((int)$totals->total_clicks) . '</strong><span>Total clicks</span></div>';
    echo '<div class="ya24-card"><strong>' . number_format((int)$totals->unique_links) . '</strong><span>Links</span></div>';
    echo '<div class="ya24-card"><strong>' . number_format((int)$totals->countries) . '</strong><span>Countries</span></div>';
    echo '</div>';

    echo '<h3>Clicks by Link and Country</h3>';

    if (!$rows) {
        echo '<p>No clicks recorded in the last 24 hours.</p>';
    } else {
        echo '<table class="widefat striped ya24-table">';
        echo '<thead><tr>';
        echo '<th>Short Link</th>';
        echo '<th>Destination</th>';
        echo '<th>Country</th>';
        echo '<th>Clicks</th>';
        echo '</tr></thead><tbody>';

        foreach ($rows as $row) {
            $keyword = $row->keyword;
            $country = $row->country;
            $destination = ya24_get_long_url($keyword);

            $short_url = yourls_link($keyword);

            echo '<tr>';
            echo '<td><a href="' . esc_url($short_url) . '" target="_blank" rel="noopener">'
                . esc_html($keyword) . '</a></td>';
            echo '<td class="ya24-destination" title="' . esc_attr($destination) . '">'
                . esc_html($destination) . '</td>';
            echo '<td>'
                . esc_html(ya24_country_flag($country)) . ' '
                . esc_html(ya24_country_name($country))
                . '</td>';
            echo '<td><strong>' . number_format((int)$row->clicks) . '</strong></td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    echo '<h3>Clicks by Hour</h3>';
    echo '<div class="ya24-hourly">';

    if ($hourly) {
        $max = 1;

        foreach ($hourly as $item) {
            $max = max($max, (int)$item->clicks);
        }

        foreach ($hourly as $item) {
            $width = round(((int)$item->clicks / $max) * 100);
            echo '<div class="ya24-hour">';
            echo '<span class="ya24-hour-label">' . esc_html(date('H:i', strtotime($item->hour))) . '</span>';
            echo '<div class="ya24-bar"><span style="width:' . $width . '%"></span></div>';
            echo '<span class="ya24-hour-count">' . number_format((int)$item->clicks) . '</span>';
            echo '</div>';
        }
    } else {
        echo '<p>No hourly data available.</p>';
    }

    echo '</div>';
    echo '</div>';

    echo '<link rel="stylesheet" href="' . esc_url(plugins_url('../assets/admin.css', __FILE__)) . '">';
}
