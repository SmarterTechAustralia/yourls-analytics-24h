<?php
/*
Plugin Name: YOURLS Analytics 24h
Plugin URI: https://github.com/SmarterTechAustralia/yourls-analytics-24h
Description: Admin-only dashboard showing individual YOURLS clicks from the last 24 hours.
Version: 3.0.0
Author: Smarter Tech Australia
License: MIT
*/

if (!defined('YOURLS_ABSPATH')) {
    die();
}

/**
 * Register the Analytics 24h admin page.
 */
yourls_add_action('plugins_loaded', 'ya24_register_page');

function ya24_register_page() {
    yourls_register_plugin_page(
        'ya24-analytics',
        'Analytics 24h',
        'ya24_render_page'
    );
}

/**
 * Escape HTML safely.
 */
function ya24_h($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/**
 * Return a country name for a 2-letter country code.
 */
function ya24_country_name($code) {
    $code = strtoupper(trim((string) $code));

    if ($code === '') {
        return 'Unknown';
    }

    $countries = array(
        'AU' => 'Australia',
        'AT' => 'Austria',
        'BE' => 'Belgium',
        'BR' => 'Brazil',
        'CA' => 'Canada',
        'CH' => 'Switzerland',
        'CN' => 'China',
        'DE' => 'Germany',
        'DK' => 'Denmark',
        'ES' => 'Spain',
        'FI' => 'Finland',
        'FR' => 'France',
        'GB' => 'United Kingdom',
        'HK' => 'Hong Kong',
        'ID' => 'Indonesia',
        'IE' => 'Ireland',
        'IN' => 'India',
        'IR' => 'Iran',
        'IT' => 'Italy',
        'JP' => 'Japan',
        'KR' => 'South Korea',
        'MY' => 'Malaysia',
        'NL' => 'Netherlands',
        'NO' => 'Norway',
        'NZ' => 'New Zealand',
        'PK' => 'Pakistan',
        'PL' => 'Poland',
        'PT' => 'Portugal',
        'RU' => 'Russia',
        'SA' => 'Saudi Arabia',
        'SE' => 'Sweden',
        'SG' => 'Singapore',
        'TH' => 'Thailand',
        'TR' => 'Turkey',
        'TW' => 'Taiwan',
        'UA' => 'Ukraine',
        'US' => 'United States',
        'VN' => 'Vietnam',
        'ZA' => 'South Africa',
    );

    return isset($countries[$code]) ? $countries[$code] : $code;
}

/**
 * Small country flag from ISO code.
 */
function ya24_flag($code) {
    $code = strtoupper(trim((string) $code));

    if (!preg_match('/^[A-Z]{2}$/', $code)) {
        return '';
    }

    return chr(0x1F1E6 + ord($code[0]) - ord('A'))
        . chr(0x1F1E6 + ord($code[1]) - ord('A'));
}

/**
 * Render the dashboard.
 *
 * IMPORTANT:
 * Never hard-code "yourls_" here.
 * YOURLS defines these constants from YOURLS_DB_PREFIX.
 *
 * For example:
 * YOURLS_DB_PREFIX = yprek7_
 * results in:
 * YOURLS_DB_TABLE_LOG = yprek7_log
 * YOURLS_DB_TABLE_URL = yprek7_url
 */
function ya24_render_page() {
    global $ydb;

    if (!isset($ydb)) {
        echo '<div class="error"><p>YOURLS database object is not available.</p></div>';
        return;
    }

    // Use YOURLS table constants. This makes the plugin work with any prefix.
    $log_table = defined('YOURLS_DB_TABLE_LOG')
        ? YOURLS_DB_TABLE_LOG
        : YOURLS_DB_PREFIX . 'log';

    $url_table = defined('YOURLS_DB_TABLE_URL')
        ? YOURLS_DB_TABLE_URL
        : YOURLS_DB_PREFIX . 'url';

    // Validate table names before inserting them into SQL.
    if (!preg_match('/^[A-Za-z0-9_]+$/', $log_table) ||
        !preg_match('/^[A-Za-z0-9_]+$/', $url_table)) {
        echo '<div class="error"><p>Invalid YOURLS database table name.</p></div>';
        return;
    }

    /*
     * We intentionally query the log table directly.
     * YOURLS stores individual click records in:
     * click_id, click_time, shorturl, referrer, user_agent,
     * ip_address and country_code.
     */
    $summary_sql = "
        SELECT
            COUNT(*) AS total_clicks,
            COUNT(DISTINCT shorturl) AS total_links,
            COUNT(DISTINCT CASE
                WHEN country_code IS NOT NULL AND country_code <> ''
                THEN country_code
                ELSE NULL
            END) AS total_countries
        FROM `{$log_table}`
        WHERE click_time >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
    ";

    $summary = $ydb->get_row($summary_sql);

    if (!$summary) {
        $summary = (object) array(
            'total_clicks' => 0,
            'total_links' => 0,
            'total_countries' => 0
        );
    }

    /*
     * Individual clicks.
     *
     * LEFT JOIN is important. A click must still be displayed even if
     * its short URL no longer exists in the URL table.
     */
    $click_sql = "
        SELECT
            l.click_id,
            l.click_time,
            l.shorturl,
            l.country_code,
            l.ip_address,
            l.referrer,
            l.user_agent,
            u.url AS destination
        FROM `{$log_table}` AS l
        LEFT JOIN `{$url_table}` AS u
            ON u.keyword = l.shorturl
        WHERE l.click_time >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ORDER BY l.click_time DESC, l.click_id DESC
        LIMIT 1000
    ";

    $clicks = $ydb->get_results($click_sql);

    /*
     * Country summary.
     */
    $country_sql = "
        SELECT
            country_code,
            COUNT(*) AS clicks
        FROM `{$log_table}`
        WHERE click_time >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
        GROUP BY country_code
        ORDER BY clicks DESC
    ";

    $countries = $ydb->get_results($country_sql);

    /*
     * Link summary.
     */
    $link_sql = "
        SELECT
            l.shorturl,
            COUNT(*) AS clicks,
            u.url AS destination
        FROM `{$log_table}` AS l
        LEFT JOIN `{$url_table}` AS u
            ON u.keyword = l.shorturl
        WHERE l.click_time >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
        GROUP BY l.shorturl, u.url
        ORDER BY clicks DESC
        LIMIT 100
    ";

    $links = $ydb->get_results($link_sql);

    ?>
    <style>
        .ya24-wrap {
            max-width: 1250px;
            margin: 20px auto;
            font-family: Arial, sans-serif;
        }

        .ya24-wrap h1 {
            margin-bottom: 6px;
        }

        .ya24-description {
            color: #666;
            margin-bottom: 20px;
        }

        .ya24-cards {
            display: flex;
            gap: 15px;
            margin-bottom: 25px;
            flex-wrap: wrap;
        }

        .ya24-card {
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 6px;
            padding: 18px 25px;
            min-width: 180px;
            box-sizing: border-box;
        }

        .ya24-card-number {
            font-size: 32px;
            font-weight: bold;
            line-height: 1;
            margin-bottom: 7px;
        }

        .ya24-card-label {
            color: #555;
        }

        .ya24-section {
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 6px;
            padding: 18px;
            margin-bottom: 25px;
            overflow-x: auto;
        }

        .ya24-section h2 {
            margin-top: 0;
            font-size: 18px;
        }

        .ya24-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        .ya24-table th {
            background: #f5f5f5;
            text-align: left;
            padding: 10px;
            border-bottom: 2px solid #ddd;
            white-space: nowrap;
        }

        .ya24-table td {
            padding: 9px 10px;
            border-bottom: 1px solid #eee;
            vertical-align: top;
        }

        .ya24-table tr:hover td {
            background: #fafafa;
        }

        .ya24-time {
            white-space: nowrap;
        }

        .ya24-short {
            font-weight: bold;
            white-space: nowrap;
        }

        .ya24-destination {
            max-width: 450px;
            word-break: break-all;
        }

        .ya24-destination a {
            text-decoration: none;
        }

        .ya24-country {
            white-space: nowrap;
        }

        .ya24-ip {
            white-space: nowrap;
            font-family: monospace;
        }

        .ya24-muted {
            color: #777;
        }

        .ya24-empty {
            padding: 25px;
            text-align: center;
            color: #777;
        }

        .ya24-refresh {
            margin-bottom: 20px;
        }

        .ya24-refresh a {
            display: inline-block;
            padding: 7px 12px;
            border: 1px solid #ccc;
            border-radius: 4px;
            background: #f7f7f7;
            text-decoration: none;
        }

        @media (max-width: 800px) {
            .ya24-card {
                min-width: 140px;
            }

            .ya24-wrap {
                margin: 10px;
            }
        }
    </style>

    <div class="wrap ya24-wrap">
        <h1>YOURLS Analytics - Last 24 Hours</h1>

        <div class="ya24-description">
            Individual click activity recorded during the last 24 hours.
        </div>

        <div class="ya24-refresh">
            <a href="<?php echo ya24_h(yourls_link('plugins.php?page=ya24-analytics')); ?>">
                Refresh
            </a>
        </div>

        <div class="ya24-cards">
            <div class="ya24-card">
                <div class="ya24-card-number">
                    <?php echo number_format((int) $summary->total_clicks); ?>
                </div>
                <div class="ya24-card-label">Total clicks</div>
            </div>

            <div class="ya24-card">
                <div class="ya24-card-number">
                    <?php echo number_format((int) $summary->total_links); ?>
                </div>
                <div class="ya24-card-label">Links</div>
            </div>

            <div class="ya24-card">
                <div class="ya24-card-number">
                    <?php echo number_format((int) $summary->total_countries); ?>
                </div>
                <div class="ya24-card-label">Countries</div>
            </div>
        </div>

        <div class="ya24-section">
            <h2>Click Activity</h2>

            <?php if (empty($clicks)) : ?>

                <div class="ya24-empty">
                    No individual clicks were returned from the YOURLS log table.
                </div>

            <?php else : ?>

                <table class="ya24-table">
                    <thead>
                        <tr>
                            <th>Time</th>
                            <th>Short Link</th>
                            <th>Destination</th>
                            <th>Country</th>
                            <th>IP Address</th>
                            <th>Referrer</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($clicks as $click) : ?>

                        <?php
                        $short = isset($click->shorturl) ? $click->shorturl : '';
                        $destination = isset($click->destination) ? $click->destination : '';
                        $country = isset($click->country_code) ? strtoupper(trim($click->country_code)) : '';
                        $ip = isset($click->ip_address) ? $click->ip_address : '';
                        $referrer = isset($click->referrer) ? $click->referrer : '';
                        $time = isset($click->click_time) ? $click->click_time : '';
                        ?>

                        <tr>
                            <td class="ya24-time">
                                <?php echo ya24_h($time); ?>
                            </td>

                            <td class="ya24-short">
                                <?php echo ya24_h($short); ?>
                            </td>

                            <td class="ya24-destination">
                                <?php if ($destination !== '') : ?>
                                    <a href="<?php echo ya24_h($destination); ?>" target="_blank" rel="noopener noreferrer">
                                        <?php echo ya24_h($destination); ?>
                                    </a>
                                <?php else : ?>
                                    <span class="ya24-muted">URL no longer exists</span>
                                <?php endif; ?>
                            </td>

                            <td class="ya24-country">
                                <?php if ($country !== '') : ?>
                                    <?php echo ya24_h(ya24_flag($country)); ?>
                                    <?php echo ya24_h(ya24_country_name($country)); ?>
                                    <small>(<?php echo ya24_h($country); ?>)</small>
                                <?php else : ?>
                                    <span class="ya24-muted">Unknown</span>
                                <?php endif; ?>
                            </td>

                            <td class="ya24-ip">
                                <?php echo ya24_h($ip); ?>
                            </td>

                            <td>
                                <?php if ($referrer !== '') : ?>
                                    <?php echo ya24_h($referrer); ?>
                                <?php else : ?>
                                    <span class="ya24-muted">Direct</span>
                                <?php endif; ?>
                            </td>
                        </tr>

                    <?php endforeach; ?>
                    </tbody>
                </table>

                <p class="ya24-muted">
                    Showing up to 1,000 individual clicks from the last 24 hours.
                </p>

            <?php endif; ?>
        </div>

        <div class="ya24-section">
            <h2>Clicks by Country</h2>

            <?php if (empty($countries)) : ?>

                <div class="ya24-empty">No country data available.</div>

            <?php else : ?>

                <table class="ya24-table">
                    <thead>
                        <tr>
                            <th>Country</th>
                            <th>Code</th>
                            <th>Clicks</th>
                        </tr>
                    </thead>
                    <tbody>

                    <?php foreach ($countries as $row) : ?>

                        <?php
                        $country = isset($row->country_code)
                            ? strtoupper(trim($row->country_code))
                            : '';
                        ?>

                        <tr>
                            <td>
                                <?php echo ya24_h(ya24_flag($country)); ?>
                                <?php echo ya24_h(ya24_country_name($country)); ?>
                            </td>
                            <td><?php echo ya24_h($country !== '' ? $country : 'Unknown'); ?></td>
                            <td><?php echo number_format((int) $row->clicks); ?></td>
                        </tr>

                    <?php endforeach; ?>

                    </tbody>
                </table>

            <?php endif; ?>
        </div>

        <div class="ya24-section">
            <h2>Clicks by Short Link</h2>

            <?php if (empty($links)) : ?>

                <div class="ya24-empty">No link data available.</div>

            <?php else : ?>

                <table class="ya24-table">
                    <thead>
                        <tr>
                            <th>Short Link</th>
                            <th>Destination</th>
                            <th>Clicks</th>
                        </tr>
                    </thead>
                    <tbody>

                    <?php foreach ($links as $row) : ?>

                        <tr>
                            <td class="ya24-short">
                                <?php echo ya24_h($row->shorturl); ?>
                            </td>

                            <td class="ya24-destination">
                                <?php if (!empty($row->destination)) : ?>
                                    <?php echo ya24_h($row->destination); ?>
                                <?php else : ?>
                                    <span class="ya24-muted">URL no longer exists</span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <?php echo number_format((int) $row->clicks); ?>
                            </td>
                        </tr>

                    <?php endforeach; ?>

                    </tbody>
                </table>

            <?php endif; ?>
        </div>
    </div>
    <?php
}
