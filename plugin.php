<?php
/*
Plugin Name: YOURLS Analytics 24h
Plugin URI: https://github.com/your-username/yourls-analytics-24h
Description: Admin-only dashboard showing YOURLS clicks from the last 24 hours, grouped by short link and country.
Version: 1.0.0
Author: YOURLS Analytics 24h contributors
License: MIT
*/

if (!defined('YOURLS_ABSPATH')) {
    die();
}

require_once dirname(__FILE__) . '/includes/analytics.php';

yourls_add_action('plugins_loaded', 'ya24_register_admin_page');

function ya24_register_admin_page() {
    yourls_register_plugin_page(
        'ya24-analytics',
        'Analytics 24h',
        'ya24_render_admin_page'
    );
}

function ya24_render_admin_page() {
    if (!yourls_is_valid_user()) {
        die('Unauthorized');
    }

    ya24_render_dashboard();
}
