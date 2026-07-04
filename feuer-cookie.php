<?php
/**
 * Plugin Name: Feuer-Cookie
 * Plugin URI: https://wdmin.com/plugins/feuer-cookie/
 * Description: Konfigurierbarer Consent-Banner mit Dienst-Blocking, lokaler Diagnose, Besucherstatistik und IP-Schutz fuer WordPress.
 * Version: 1.24.2
 * Author: Walter Faerber
 * Author URI: https://wdmin.com
 * Update URI: https://wdmin.com/plugins/feuer-cookie/
 * Text Domain: feuer-cookie
 * Requires at least: 6.9
 * Requires PHP: 8.0
 */

if (! defined('ABSPATH')) {
	exit;
}

define('FEUER_COOKIE_VERSION', '1.24.2');
define('FEUER_COOKIE_MIN_WP_VERSION', '6.9');
define('FEUER_COOKIE_MIN_PHP_VERSION', '8.0');
define('FEUER_COOKIE_FILE', __FILE__);
define('FEUER_COOKIE_PATH', plugin_dir_path(__FILE__));
define('FEUER_COOKIE_URL', plugin_dir_url(__FILE__));
define('FEUER_COOKIE_SLUG', 'feuer-cookie');
define('FEUER_COOKIE_HOME_URL', 'https://wdmin.com/plugins/feuer-cookie/');
define('FEUER_COOKIE_AUTHOR_URL', 'https://wdmin.com');
define('FEUER_COOKIE_UPDATE_MANIFEST_URL', FEUER_COOKIE_HOME_URL . 'release/latest/update.json');
define('FEUER_COOKIE_UPDATE_CACHE_KEY', 'feuer_cookie_update_manifest');

require_once FEUER_COOKIE_PATH . 'includes/class-feuer-cookie-diagnostics.php';
require_once FEUER_COOKIE_PATH . 'includes/class-feuer-cookie-seo-audit.php';
require_once FEUER_COOKIE_PATH . 'includes/class-feuer-cookie-plugin.php';
require_once FEUER_COOKIE_PATH . 'includes/class-feuer-cookie-updater.php';

register_activation_hook(FEUER_COOKIE_FILE, array('Feuer_Cookie_Plugin', 'activate'));
register_deactivation_hook(FEUER_COOKIE_FILE, array('Feuer_Cookie_Plugin', 'deactivate'));

Feuer_Cookie_Plugin::instance();
new Feuer_Cookie_Updater(FEUER_COOKIE_FILE, FEUER_COOKIE_UPDATE_MANIFEST_URL);

if (! function_exists('feuer_cookie_header_macro')) {
	/**
	 * Gibt das Feuer-Cookie-Header-Makro fuer Theme-Dateien aus.
	 *
	 * @return void
	 */
	function feuer_cookie_header_macro() {
		echo Feuer_Cookie_Plugin::instance()->get_template_macro_markup('header'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}

if (! function_exists('feuer_cookie_footer_macro')) {
	/**
	 * Gibt das Feuer-Cookie-Footer-Makro fuer Theme-Dateien aus.
	 *
	 * @return void
	 */
	function feuer_cookie_footer_macro() {
		echo Feuer_Cookie_Plugin::instance()->get_template_macro_markup('footer'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}

if (! function_exists('feuer_cookie_has_category_consent')) {
	/**
	 * Prueft die Einwilligung fuer eine Consent-Kategorie.
	 *
	 * @param string $category Kategorie-Slug.
	 * @return bool
	 */
	function feuer_cookie_has_category_consent($category) {
		return Feuer_Cookie_Plugin::instance()->has_category_consent((string) $category);
	}
}

if (! function_exists('feuer_cookie_has_vendor_consent')) {
	/**
	 * Prueft die Einwilligung fuer einen konkreten Empfaenger.
	 *
	 * @param string $vendor Vendor-Slug.
	 * @return bool
	 */
	function feuer_cookie_has_vendor_consent($vendor) {
		return Feuer_Cookie_Plugin::instance()->has_vendor_consent((string) $vendor);
	}
}

if (! function_exists('ff_cookie_has_consent')) {
	/**
	 * Backward-compatible helper for service-based consent checks.
	 *
	 * @param string $service_id Service identifier.
	 * @return bool
	 */
	function ff_cookie_has_consent($service_id) {
		return Feuer_Cookie_Plugin::instance()->has_service_consent((string) $service_id);
	}
}

if (! function_exists('feuer_cookie_render_service_gate')) {
	/**
	 * Render content only after consent for a specific service.
	 *
	 * @param string $service_id Service identifier.
	 * @param string $content    Protected markup.
	 * @param array  $args       Optional placeholder arguments.
	 * @return string
	 */
	function feuer_cookie_render_service_gate($service_id, $content = '', $args = array()) {
		return Feuer_Cookie_Plugin::instance()->render_service_gate((string) $service_id, (string) $content, (array) $args);
	}
}
