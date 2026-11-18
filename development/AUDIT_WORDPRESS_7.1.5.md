# Feuer-Cookie: audit admin/frontend for WordPress 7.1.5

Date: 18.11.2026
Scope: source review of the admin UI, frontend consent flows, updater and release metadata. This is not a runtime certification.

## Target version

WordPress 7.1.5 is the latest stable release on the audit date. WordPress 7.2 is still a release candidate. The plugin declares WordPress 6.9 and PHP 8.0 as minimum requirements. Keep the minimum at 6.9 unless the project intentionally drops older installations.

## Findings and changes

### Fixed

1. **Admin pages existed but were missing from the plugin menu.** The dashboard linked to `feuer-cookie-visitors`, and the SEO audit action redirected to `feuer-cookie-seo-audit`, but neither page was registered with `add_submenu_page()`. Both routes are now registered, restoring navigation and making their asset-loading hooks reachable.
2. **Dashboard widget tabs were not accessible as tabs.** The tab list lacked tab and panel relationships, selected state, and keyboard navigation. Added `role=tab`/`tabpanel`, `aria-selected`, `aria-controls`, labelled panels, roving tabindex, and Arrow/Home/End navigation.
3. **Two admin-post actions had no WordPress hooks.** `handle_run_seo_audit()` and `handle_export_events()` had nonce and capability checks but were never connected to `admin_post`; the SEO audit form and visitor export links therefore could not reach their handlers. Both hooks are now registered.
4. **Consent cookies were always marked `Secure`.** Browsers do not send those cookies over HTTP, so a site served over HTTP could fail to persist consent and keep reopening the banner. The cookie now uses WordPress `is_ssl()` to set `Secure` only for HTTPS requests.
5. **Client IP selection trusted request-supplied proxy headers.** Forwarded IP headers could be spoofed when the web server was directly exposed. The plugin now uses `REMOTE_ADDR` by default and accepts `X-Forwarded-For` only when the immediate proxy address is explicitly allowlisted through `feuer_cookie_trusted_proxy_ips`.
6. **Location data trusted vendor-specific HTTP headers.** Country and city could be supplied by a client without a trusted proxy. The plugin now reads only server-configured `GEOIP_COUNTRY_CODE` and `GEOIP_CITY`; it makes no request to a geolocation provider. The manual documents the proxy filter and data behavior.
7. **Retention missed security and engagement rows.** IP-block events and any legacy page-engagement rows could outlive the configured retention period. Cleanup now applies the Consent-audit period to consent and blocked-IP events, and the analytics period to page views and engagement rows.
8. **Consent event rows kept full query strings.** The event logger now stores the canonical site URL with the request path only, so URL query parameters are not copied into consent/security logs.
9. **Daily statistics had no schema or write path.** Admin reports expected a `daily_stats` table, but activation/migration created only events and IP-block tables, and the rollup method was never called. The table is now created and upgraded with `dbDelta`; successful consent, blocked-IP, and opted-in page-view inserts update daily counters. Daily aggregates follow the configured analytics retention period. The concurrent unique-visitor counter still relies on a read after event insert and should be checked under load in the runtime matrix.

### Compatibility and validation notes

- The reviewed code uses long-standing WordPress hooks and APIs; no dependency on a known 7.1-only behavior or removed API was found in the reviewed entry points.
- The release manifest still reports `tested: 6.9`. Do not raise this to 7.1.5 until the plugin is actually exercised on a WordPress 7.1.5 installation. `Requires at least: 6.9` is a minimum, not a tested-up-to value.
- No PHP, WP-CLI, Composer, or WordPress runtime is installed in the available workspace. PHP syntax lint, activation/database migration, admin navigation, AJAX consent, live embeds, and browser/device checks could not be run here.
- Source review confirmed capability checks and nonces on admin-post mutation/export handlers and a nonce on the public consent AJAX handler. SQL reads are generally prepared; output rendering uses WordPress escaping helpers in the reviewed admin paths. Proxy identity and location now use server-configured sources instead of untrusted vendor headers.
- JavaScript syntax checks are performed separately with Node. Package SHA-256 is checked after release generation.

## Recommended update plan

1. Run an isolated WordPress 6.9 and 7.1.5 matrix with PHP 8.0 and the current supported PHP release. Verify activation, schema creation/upgrade, every submenu, settings save, diagnostics, SEO audit, both exports, consent accept/reject/revoke, and plugin auto-update.
2. Visually inspect admin pages at desktop and narrow widths, and the consent banner/settings panel at 320, 390, 768, and desktop widths. Include keyboard-only and screen-reader smoke checks.
3. Only after the 7.1.5 run passes, change the updater manifest `tested` value in `tools/build-release.ps1` from 6.9 to 7.1.5 and record the matrix in the release history.
4. Add repeatable CI jobs for PHP lint, WordPress Coding Standards, JavaScript syntax, packaging/hash verification, and the WordPress/PHP matrix. The main plugin class is now large enough that splitting admin rendering, analytics, and consent handling into separate classes would reduce regression risk.

## Result

The confirmed admin defects are fixed in source. Static review suggests the WordPress 7.1.5 API surface remains compatible, but runtime compatibility is not yet proven because no WordPress/PHP environment was available. The manifest's tested-up-to claim intentionally remains unchanged pending that run.
