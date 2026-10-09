# YOURLS Analytics 24h

A YOURLS plugin that provides an admin-only dashboard for click activity during the last 24 hours.

## Features

- Total clicks in the last 24 hours
- Number of clicks with a referrer
- Number of unique short links receiving clicks
- Number of countries
- Clicks grouped by short link and country
- YOURLS click ID for each click
- Destination URL for each short link
- Hourly click activity
- Country flags
- Admin-only page
- No public click counter
- No changes to YOURLS core files

## Requirements

- YOURLS 1.9.x or newer
- Click logging enabled
- PHP version supported by your YOURLS installation

## Installation

1. Download or clone this repository.
2. Copy the `yourls-analytics-24h` directory to:

   `user/plugins/`

3. In YOURLS, open the Plugins page.
4. Activate **YOURLS Analytics 24h**.
5. Open **Plugins > Analytics 24h**.

## Country data

The plugin reads the `country_code` value already stored by YOURLS in the click log.

Current YOURLS versions populate this value during normal redirect logging. This means the plugin does not need to call an external GeoIP service for every visitor.

Existing log records without a country code will appear as `Unknown`.

## Privacy

The plugin does not create a second copy of the visitor IP address. It reads the existing YOURLS click log.

## Important

If `YOURLS_NOSTATS` is enabled, YOURLS does not record click statistics and this dashboard will not have data.

## License

MIT
