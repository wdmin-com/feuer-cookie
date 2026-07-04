<?php
/**
 * Plugin update integration for the developer-hosted release server.
 *
 * @package Feuer_Cookie
 */

if (! defined('ABSPATH')) {
	exit;
}

class Feuer_Cookie_Updater {

	/**
	 * Site transient cache key for the remote manifest.
	 *
	 * @var string
	 */
	private $cache_key = FEUER_COOKIE_UPDATE_CACHE_KEY;

	/**
	 * Cache lifetime in seconds.
	 *
	 * @var int
	 */
	private $cache_ttl = 21600;

	/**
	 * Main plugin file.
	 *
	 * @var string
	 */
	private $plugin_file = '';

	/**
	 * WordPress plugin basename.
	 *
	 * @var string
	 */
	private $plugin_basename = '';

	/**
	 * Plugin slug.
	 *
	 * @var string
	 */
	private $slug = FEUER_COOKIE_SLUG;

	/**
	 * Remote JSON manifest URL.
	 *
	 * @var string
	 */
	private $manifest_url = '';

	/**
	 * Constructor.
	 *
	 * @param string $plugin_file  Absolute plugin bootstrap path.
	 * @param string $manifest_url Remote JSON manifest.
	 */
	public function __construct($plugin_file, $manifest_url) {
		$this->plugin_file     = (string) $plugin_file;
		$this->plugin_basename = plugin_basename($plugin_file);
		$this->manifest_url    = esc_url_raw((string) $manifest_url);

		add_filter('pre_set_site_transient_update_plugins', array($this, 'inject_update_metadata'));
		add_filter('plugins_api', array($this, 'inject_plugin_information'), 20, 3);
		add_filter('auto_update_plugin', array($this, 'maybe_enable_auto_update'), 20, 2);
		add_action('upgrader_process_complete', array($this, 'purge_manifest_cache'), 10, 2);
		add_action('delete_site_transient_update_plugins', array($this, 'delete_manifest_cache'));
	}

	/**
	 * Add developer-hosted update information to the WordPress update transient.
	 *
	 * @param object $transient Core update transient.
	 * @return object
	 */
	public function inject_update_metadata($transient) {
		if (! is_object($transient)) {
			$transient = new stdClass();
		}

		$manifest = $this->get_manifest();

		if (empty($manifest)) {
			return $transient;
		}

		$update = $this->build_update_payload($manifest);

		if (version_compare(FEUER_COOKIE_VERSION, (string) $manifest['version'], '<')) {
			$transient->response[ $this->plugin_basename ] = $update;
			return $transient;
		}

		$transient->no_update[ $this->plugin_basename ] = $update;

		return $transient;
	}

	/**
	 * Provide plugin information for the details modal.
	 *
	 * @param mixed  $result Current result.
	 * @param string $action API action.
	 * @param object $args   Request arguments.
	 * @return mixed
	 */
	public function inject_plugin_information($result, $action, $args) {
		$slug = is_object($args) && ! empty($args->slug) ? sanitize_key((string) $args->slug) : '';

		if ('plugin_information' !== $action || $this->slug !== $slug) {
			return $result;
		}

		$manifest = $this->get_manifest();

		if (empty($manifest)) {
			return $result;
		}

		return (object) array(
			'name'          => (string) $manifest['name'],
			'slug'          => $this->slug,
			'version'       => (string) $manifest['version'],
			'author'        => sprintf(
				'<a href="%s">%s</a>',
				esc_url((string) $manifest['author_homepage']),
				esc_html((string) $manifest['author'])
			),
			'author_profile' => (string) $manifest['author_homepage'],
			'homepage'      => (string) $manifest['homepage'],
			'requires'      => (string) $manifest['requires'],
			'tested'        => (string) $manifest['tested'],
			'requires_php'  => (string) $manifest['requires_php'],
			'last_updated'  => (string) $manifest['last_updated'],
			'download_link' => '',
			'sections'      => (array) $manifest['sections'],
			'banners'       => (array) $manifest['banners'],
			'icons'         => (array) $manifest['icons'],
			'external'      => true,
		);
	}

	/**
	 * Enable background auto-updates when the remote manifest requests it.
	 *
	 * @param bool  $update Current auto-update decision.
	 * @param mixed $item   Plugin update object.
	 * @return bool
	 */
	public function maybe_enable_auto_update($update, $item) {
		$plugin = is_object($item) && ! empty($item->plugin) ? (string) $item->plugin : '';

		if ($this->plugin_basename !== $plugin) {
			return (bool) $update;
		}

		$manifest = $this->get_manifest();

		if (! empty($manifest['autoupdate'])) {
			return true;
		}

		return (bool) $update;
	}

	/**
	 * Clear cached manifest after this plugin has been upgraded.
	 *
	 * @param WP_Upgrader $upgrader   Upgrader instance.
	 * @param array       $hook_extra Context data.
	 * @return void
	 */
	public function purge_manifest_cache($upgrader, $hook_extra) {
		$plugins = isset($hook_extra['plugins']) && is_array($hook_extra['plugins']) ? $hook_extra['plugins'] : array();

		if ('update' !== ($hook_extra['action'] ?? '') || 'plugin' !== ($hook_extra['type'] ?? '')) {
			return;
		}

		if (in_array($this->plugin_basename, $plugins, true)) {
			$this->delete_manifest_cache();
		}
	}

	/**
	 * Delete the cached manifest.
	 *
	 * @return void
	 */
	public function delete_manifest_cache() {
		delete_site_transient($this->cache_key);
	}

	/**
	 * Fetch and normalise the release manifest.
	 *
	 * @return array<string,mixed>
	 */
	private function get_manifest() {
		$cached = get_site_transient($this->cache_key);

		if (is_array($cached) && ! empty($cached['version'])) {
			return $cached;
		}

		if (! $this->manifest_url) {
			return array();
		}

		$response = wp_safe_remote_get(
			$this->manifest_url,
			array(
				'timeout' => 10,
				'headers' => array(
					'Accept' => 'application/json',
				),
			)
		);

		if (is_wp_error($response)) {
			return array();
		}

		$status = (int) wp_remote_retrieve_response_code($response);

		if (200 !== $status) {
			return array();
		}

		$decoded = json_decode((string) wp_remote_retrieve_body($response), true);

		if (! is_array($decoded)) {
			return array();
		}

		$manifest = $this->normalize_manifest($decoded);

		if (empty($manifest)) {
			return array();
		}

		set_site_transient($this->cache_key, $manifest, $this->cache_ttl);

		return $manifest;
	}

	/**
	 * Validate manifest data and fill fallback values.
	 *
	 * @param array<string,mixed> $manifest Raw manifest.
	 * @return array<string,mixed>
	 */
	private function normalize_manifest($manifest) {
		$version = sanitize_text_field((string) ($manifest['version'] ?? ''));

		if ('' === $version) {
			return array();
		}

		$sections = array();

		foreach ((array) ($manifest['sections'] ?? array()) as $section_key => $section_value) {
			$key = sanitize_key((string) $section_key);

			if ('' === $key) {
				continue;
			}

			$sections[ $key ] = wp_kses_post((string) $section_value);
		}

		return array(
			'name'            => sanitize_text_field((string) ($manifest['name'] ?? 'Feuer-Cookie')),
			'version'         => $version,
			'homepage'        => esc_url_raw((string) ($manifest['homepage'] ?? FEUER_COOKIE_HOME_URL)),
			'details_url'     => esc_url_raw((string) ($manifest['details_url'] ?? FEUER_COOKIE_HOME_URL)),
			'download_url'    => esc_url_raw((string) ($manifest['download_url'] ?? FEUER_COOKIE_HOME_URL)),
			'package_url'     => esc_url_raw((string) ($manifest['package_url'] ?? '')),
			'author'          => sanitize_text_field((string) ($manifest['author'] ?? 'Walter Faerber')),
			'author_homepage' => esc_url_raw((string) ($manifest['author_homepage'] ?? FEUER_COOKIE_AUTHOR_URL)),
			'requires'        => sanitize_text_field((string) ($manifest['requires'] ?? FEUER_COOKIE_MIN_WP_VERSION)),
			'tested'          => sanitize_text_field((string) ($manifest['tested'] ?? FEUER_COOKIE_MIN_WP_VERSION)),
			'requires_php'    => sanitize_text_field((string) ($manifest['requires_php'] ?? FEUER_COOKIE_MIN_PHP_VERSION)),
			'last_updated'    => sanitize_text_field((string) ($manifest['last_updated'] ?? gmdate('Y-m-d H:i:s'))),
			'autoupdate'      => ! empty($manifest['autoupdate']),
			'sections'        => $sections,
			'banners'         => $this->normalize_image_map((array) ($manifest['banners'] ?? array())),
			'icons'           => $this->normalize_image_map((array) ($manifest['icons'] ?? array())),
		);
	}

	/**
	 * Sanitize icon or banner URL maps.
	 *
	 * @param array<string,mixed> $images Raw image map.
	 * @return array<string,string>
	 */
	private function normalize_image_map($images) {
		$normalized = array();

		foreach ($images as $size => $url) {
			$key = sanitize_key((string) $size);
			$src = esc_url_raw((string) $url);

			if ($key && $src) {
				$normalized[ $key ] = $src;
			}
		}

		return $normalized;
	}

	/**
	 * Build the update response payload expected by WordPress core.
	 *
	 * @param array<string,mixed> $manifest Release manifest.
	 * @return object
	 */
	private function build_update_payload($manifest) {
		return (object) array(
			'id'           => FEUER_COOKIE_HOME_URL,
			'slug'         => $this->slug,
			'plugin'       => $this->plugin_basename,
			'new_version'  => (string) $manifest['version'],
			'url'          => (string) $manifest['details_url'],
			'package'      => (string) $manifest['package_url'],
			'icons'        => (array) $manifest['icons'],
			'banners'      => (array) $manifest['banners'],
			'requires'     => (string) $manifest['requires'],
			'tested'       => (string) $manifest['tested'],
			'requires_php' => (string) $manifest['requires_php'],
			'autoupdate'   => ! empty($manifest['autoupdate']),
		);
	}
}
