<?php
/**
 * Website diagnostics for external services, themes and plugins.
 *
 * @package Feuer_Cookie
 */

if (! defined('ABSPATH')) {
	exit;
}

class Feuer_Cookie_Diagnostics {

	/**
	 * Run a site scan.
	 *
	 * @return array<string,mixed>
	 */
	public function scan() {
		$theme      = wp_get_theme();
		$site_host  = $this->normalize_host((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
		$paths      = array();
		$locations  = array();
		$records    = array();
		$notices    = array();
		$plugins    = $this->get_active_plugins();
		$post_urls  = $this->scan_published_content($site_host);
		$theme_info = array(
			'name'    => $theme->get('Name'),
			'version' => $theme->get('Version'),
		);

		$paths[]     = get_stylesheet_directory();
		$locations[] = 'Aktives Theme: ' . get_stylesheet();

		if (get_template_directory() && get_template_directory() !== get_stylesheet_directory()) {
			$paths[]     = get_template_directory();
			$locations[] = 'Eltern-Theme: ' . basename(get_template_directory());
		}

		foreach ($plugins as $plugin) {
			if (empty($plugin['path']) || ! is_dir($plugin['path'])) {
				continue;
			}

			$paths[]     = $plugin['path'];
			$locations[] = 'Plugin: ' . $plugin['name'];
		}

		foreach ($paths as $index => $path) {
			$records = array_merge(
				$records,
				$this->scan_path_for_urls($path, $locations[ $index ], $site_host)
			);
		}

		foreach ($post_urls as $post_record) {
			$records[] = $post_record;
		}

		$records = $this->unique_records($records);

		foreach ($records as $record) {
			if (! empty($record['notice'])) {
				$notices[] = $record['notice'];
			}
		}

		$notices = array_values(array_unique(array_filter($notices)));

		return array(
			'generated_at'    => current_time('mysql'),
			'scan_mode'       => 'static_request_source_analysis',
			'record_count'    => count($records),
			'active_theme'    => $theme_info,
			'active_plugins'  => array_map(
				static function ($plugin) {
					return array(
						'name'    => $plugin['name'],
						'version' => $plugin['version'],
					);
				},
				$plugins
			),
			'services'        => $records,
			'service_summary' => array_values(
				array_unique(
					array_filter(
						array_map(
							static function ($record) {
								return $record['service'];
							},
							$records
						)
					)
				)
			),
			'notices'         => $notices,
		);
	}

	/**
	 * Get active plugin metadata.
	 *
	 * @return array<int,array<string,string>>
	 */
	private function get_active_plugins() {
		$plugins = array();

		if (! function_exists('get_plugins')) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$all_plugins    = get_plugins();
		$active_plugins = (array) get_option('active_plugins', array());

		foreach ($active_plugins as $plugin_file) {
			$plugin_dir = dirname(WP_PLUGIN_DIR . '/' . $plugin_file);
			$basename   = isset($all_plugins[ $plugin_file ]['Name']) ? $all_plugins[ $plugin_file ]['Name'] : basename($plugin_dir);
			$version    = isset($all_plugins[ $plugin_file ]['Version']) ? $all_plugins[ $plugin_file ]['Version'] : '';

			$plugins[] = array(
				'name'    => $basename,
				'version' => $version,
				'path'    => $plugin_dir,
			);
		}

		return $plugins;
	}

	/**
	 * Scan recent published posts and pages.
	 *
	 * @param string $site_host Site host.
	 * @return array<int,array<string,mixed>>
	 */
	private function scan_published_content($site_host) {
		$records = array();
		$posts   = get_posts(
			array(
				'post_type'              => array('page', 'post'),
				'post_status'            => 'publish',
				'numberposts'            => 50,
				'orderby'                => 'date',
				'order'                  => 'DESC',
				'suppress_filters'       => true,
				'ignore_sticky_posts'    => true,
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		foreach ($posts as $post) {
			$candidates = $this->extract_content_request_candidates((string) $post->post_content);

			foreach ($candidates as $candidate) {
				$record = $this->build_record($candidate, 'Inhalt: ' . get_the_title($post), $site_host);

				if ($record) {
					$records[] = $record;
				}
			}
		}

		return $records;
	}

	/**
	 * Scan a theme or plugin path recursively.
	 *
	 * @param string $path Filesystem path.
	 * @param string $location Human-readable source label.
	 * @param string $site_host Site host.
	 * @return array<int,array<string,mixed>>
	 */
	private function scan_path_for_urls($path, $location, $site_host) {
		$records        = array();
		$extensions     = array('php', 'js', 'css', 'html', 'htm', 'twig', 'mustache');
		$max_file_size  = 1024 * 1024;
		$directory      = new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS);
		$iterator       = new RecursiveIteratorIterator($directory);
		$visited_files  = 0;
		$max_file_count = 600;

		foreach ($iterator as $file) {
			if (! $file->isFile()) {
				continue;
			}

			$file_path = wp_normalize_path($file->getPathname());

			if ($this->should_skip_scan_file($file_path)) {
				continue;
			}

			$extension = strtolower((string) $file->getExtension());

			if (! in_array($extension, $extensions, true)) {
				continue;
			}

			if ((int) $file->getSize() > $max_file_size) {
				continue;
			}

			if ($visited_files >= $max_file_count) {
				break;
			}

			$visited_files++;

			if (! is_readable($file_path)) {
				continue;
			}

			$content = file_get_contents($file_path);

			if (false === $content) {
				continue;
			}

			$candidates = $this->extract_request_candidates($content, $extension);
			$file_label = $location . ' / ' . ltrim(str_replace(wp_normalize_path($path), '', wp_normalize_path($file->getPathname())), '/');

			foreach ($candidates as $candidate) {
				$record = $this->build_record($candidate, $file_label, $site_host);

				if ($record) {
					$records[] = $record;
				}
			}
		}

		return $records;
	}

	/**
	 * Extract request candidates from published post content.
	 *
	 * @param string $content Content blob.
	 * @return array<int,array<string,mixed>>
	 */
	private function extract_content_request_candidates($content) {
		$patterns = array_merge(
			$this->get_markup_request_patterns(),
			$this->get_css_request_patterns(),
			$this->get_shortcode_request_patterns()
		);

		return $this->match_request_patterns($content, $patterns);
	}

	/**
	 * Extract request candidates from source code.
	 *
	 * @param string $content File content.
	 * @param string $extension File extension.
	 * @return array<int,array<string,mixed>>
	 */
	private function extract_request_candidates($content, $extension) {
		$patterns = array();

		switch ($extension) {
			case 'php':
				$patterns = array_merge(
					$this->get_php_request_patterns(),
					$this->get_markup_request_patterns(),
					$this->get_css_request_patterns(),
					$this->get_js_request_patterns()
				);
				break;
			case 'js':
				$patterns = array_merge(
					$this->get_js_request_patterns(),
					$this->get_css_request_patterns()
				);
				break;
			case 'css':
				$patterns = $this->get_css_request_patterns();
				break;
			case 'html':
			case 'htm':
			case 'twig':
			case 'mustache':
				$patterns = array_merge(
					$this->get_markup_request_patterns(),
					$this->get_css_request_patterns(),
					$this->get_js_request_patterns()
				);
				break;
		}

		if (empty($patterns)) {
			return array();
		}

		return $this->match_request_patterns($content, $patterns);
	}

	/**
	 * Request patterns for PHP files.
	 *
	 * @return array<int,array<string,string>>
	 */
	private function get_php_request_patterns() {
		return array(
			array(
				'evidence' => 'wp_enqueue_*',
				'regex'    => '#\b(?:wp_enqueue_script|wp_register_script|wp_enqueue_style|wp_register_style)\s*\([^;]*?["\']((?:https?:)?//[^"\']+)["\']#is',
			),
			array(
				'evidence' => 'WP HTTP request',
				'execution_context' => 'server',
				'regex'    => '#\b(?:wp_remote_get|wp_remote_post|wp_remote_request|wp_safe_remote_get|wp_safe_remote_post|wp_safe_remote_request|wp_remote_head)\s*\(\s*["\']((?:https?:)?//[^"\']+)["\']#i',
			),
			array(
				'evidence' => 'cURL request',
				'execution_context' => 'server',
				'regex'    => '#\bcurl_setopt(?:_array)?\s*\([^;]*CURLOPT_URL[^;]*["\']((?:https?:)?//[^"\']+)["\']#is',
			),
			array(
				'evidence' => 'Leaflet tileLayer',
				'regex'    => '#\b(?:L\.)?tileLayer\s*\(\s*["\']((?:https?:)?//[^"\']+)["\']#i',
			),
		);
	}

	/**
	 * Request patterns for JavaScript files.
	 *
	 * @return array<int,array<string,string>>
	 */
	private function get_js_request_patterns() {
		return array(
			array(
				'evidence' => 'JavaScript fetch()',
				'regex'    => '#\b(?:fetch|importScripts)\s*\(\s*["\']((?:https?:)?//[^"\']+)["\']#i',
			),
			array(
				'evidence' => 'Axios or getScript',
				'regex'    => '#\b(?:axios\.(?:get|post|request)|jQuery\.getScript|\$\.getScript)\s*\(\s*["\']((?:https?:)?//[^"\']+)["\']#i',
			),
			array(
				'evidence' => 'XMLHttpRequest.open()',
				'regex'    => '#\bopen\s*\(\s*["\'](?:GET|POST|PUT|DELETE|PATCH|HEAD|OPTIONS)["\']\s*,\s*["\']((?:https?:)?//[^"\']+)["\']#i',
			),
			array(
				'evidence' => 'DOM src/href assignment',
				'regex'    => '#\.(?:src|href)\s*=\s*["\']((?:https?:)?//[^"\']+)["\']#i',
			),
			array(
				'evidence' => 'Leaflet tileLayer',
				'regex'    => '#\b(?:L\.)?tileLayer\s*\(\s*["\']((?:https?:)?//[^"\']+)["\']#i',
			),
		);
	}

	/**
	 * Request patterns for CSS and inline styles.
	 *
	 * @return array<int,array<string,string>>
	 */
	private function get_css_request_patterns() {
		return array(
			array(
				'evidence' => 'CSS @import',
				'regex'    => '#@import\s+(?:url\()?\s*["\']?((?:https?:)?//[^"\')\s]+)#i',
			),
			array(
				'evidence' => 'CSS url()',
				'regex'    => '#url\(\s*["\']?((?:https?:)?//[^"\')\s]+)#i',
			),
		);
	}

	/**
	 * Request patterns for rendered markup.
	 *
	 * @return array<int,array<string,string>>
	 */
	private function get_markup_request_patterns() {
		return array(
			array(
				'evidence' => 'HTML resource tag',
				'regex'    => '#<(?:script|iframe|img|source|video|audio|embed|object|link)\b[^>]*?(?:src|href|poster|data)\s*=\s*["\']((?:https?:)?//[^"\']+)["\']#i',
			),
			array(
				'evidence' => 'Form action',
				'regex'    => '#<form\b[^>]*?\baction\s*=\s*["\']((?:https?:)?//[^"\']+)["\']#i',
			),
		);
	}

	/**
	 * Request patterns for supported shortcodes in post content.
	 *
	 * @return array<int,array<string,string>>
	 */
	private function get_shortcode_request_patterns() {
		return array(
			array(
				'evidence' => 'Shortcode attribute',
				'regex'    => '#\[(?:leaflet-map|leaflet-image|leaflet-wms|leaflet-geojson|leaflet-kml|leaflet-gpx|leaflet-video-overlay)\b[^\]]*?(?:tiles|src|url|image|video|geojson|kml|gpx)=["\']((?:https?:)?//[^"\']+)["\']#i',
			),
		);
	}

	/**
	 * Run all patterns and return only active request contexts.
	 *
	 * @param string                              $content File or post content.
	 * @param array<int,array<string,string>>     $patterns Request patterns.
	 * @return array<int,array<string,mixed>>
	 */
	private function match_request_patterns($content, $patterns) {
		$results      = array();
		$seen         = array();
		$scan_content = $this->strip_comments_preserving_lines($content);

		foreach ($patterns as $pattern) {
			$matches = array();

			if (! preg_match_all($pattern['regex'], $scan_content, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
				continue;
			}

			foreach ($matches as $match) {
				$raw_url = isset($match[1][0]) ? (string) $match[1][0] : '';
				$offset  = isset($match[1][1]) ? (int) $match[1][1] : 0;

				if ('' === $raw_url) {
					continue;
				}

				$url  = 0 === strpos($raw_url, '//') ? 'https:' . $raw_url : $raw_url;
				$url  = rtrim($url, " \t\n\r\0\x0B,;");
				$line = $this->line_number_from_offset($scan_content, $offset);

				if ($this->is_comment_only_line($content, $line)) {
					continue;
				}

				$key = implode('|', array($pattern['evidence'], $line, $url));

				if (isset($seen[ $key ])) {
					continue;
				}

				$seen[ $key ] = true;
				$results[]    = array(
					'url'                => $url,
					'evidence'           => $pattern['evidence'],
					'execution_context'  => $pattern['execution_context'] ?? 'browser',
					'line'               => $line,
					'context'            => $this->line_excerpt($content, $line),
				);
			}
		}

		return $results;
	}

	/**
	 * Skip paths that should not contribute to diagnostics.
	 *
	 * @param string $file_path Normalized file path.
	 * @return bool
	 */
	private function should_skip_scan_file($file_path) {
		$file_path = '/' . trim(strtolower(str_replace('\\', '/', $file_path)), '/') . '/';
		$blocked_segments = array(
			'/release/',
			'/version/',
			'/versions/',
			'/snippets/',
			'/vendor/',
			'/node_modules/',
			'/.git/',
			'/.svn/',
		);

		foreach ($blocked_segments as $segment) {
			if (false !== strpos($file_path, $segment)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Determine line number from a byte offset.
	 *
	 * @param string $content Content blob.
	 * @param int    $offset Match offset.
	 * @return int
	 */
	private function line_number_from_offset($content, $offset) {
		if ($offset <= 0) {
			return 1;
		}

		return 1 + substr_count(substr($content, 0, $offset), "\n");
	}

	/**
	 * Return a cleaned single-line snippet.
	 *
	 * @param string $content Content blob.
	 * @param int    $line_number 1-based line number.
	 * @return string
	 */
	private function line_excerpt($content, $line_number) {
		$lines = preg_split('/\r\n|\r|\n/', $content);
		$index = max(0, $line_number - 1);
		$line  = isset($lines[ $index ]) ? (string) $lines[ $index ] : '';
		$line  = trim(preg_replace('/\s+/', ' ', $line));

		if (strlen($line) > 180) {
			$line = substr($line, 0, 177) . '...';
		}

		return $line;
	}

	/**
	 * Skip comment-only lines.
	 *
	 * @param string $content Content blob.
	 * @param int    $line_number 1-based line number.
	 * @return bool
	 */
	private function is_comment_only_line($content, $line_number) {
		$lines = preg_split('/\r\n|\r|\n/', $content);
		$index = max(0, $line_number - 1);
		$line  = isset($lines[ $index ]) ? ltrim((string) $lines[ $index ]) : '';

		return 0 === strpos($line, '//')
			|| 0 === strpos($line, '#')
			|| 0 === strpos($line, '/*')
			|| 0 === strpos($line, '*')
			|| 0 === strpos($line, '<!--');
	}

	/**
	 * Strip comments while preserving line structure and offsets.
	 *
	 * @param string $content Content blob.
	 * @return string
	 */
	private function strip_comments_preserving_lines($content) {
		$patterns = array(
			'/<!--[\s\S]*?-->/',
			'/\/\*[\s\S]*?\*\//',
		);

		foreach ($patterns as $pattern) {
			$content = preg_replace_callback(
				$pattern,
				array($this, 'replace_comment_with_whitespace'),
				$content
			);
		}

		$content = preg_replace_callback(
			'/(?m)(^|[ \t;,{(])\/\/[^\r\n]*/',
			array($this, 'replace_line_comment_with_whitespace'),
			$content
		);

		$content = preg_replace_callback(
			'/(?m)(^|[ \t])#[^\r\n]*/',
			array($this, 'replace_line_comment_with_whitespace'),
			$content
		);

		return (string) $content;
	}

	/**
	 * Replace a block comment with whitespace while preserving newlines.
	 *
	 * @param array<int,string> $match Regex match.
	 * @return string
	 */
	private function replace_comment_with_whitespace($match) {
		return (string) preg_replace('/[^\r\n]/', ' ', (string) $match[0]);
	}

	/**
	 * Replace a line comment with whitespace while preserving its prefix.
	 *
	 * @param array<int,string> $match Regex match.
	 * @return string
	 */
	private function replace_line_comment_with_whitespace($match) {
		$prefix  = isset($match[1]) ? (string) $match[1] : '';
		$comment = substr((string) $match[0], strlen($prefix));

		return $prefix . preg_replace('/[^\r\n]/', ' ', $comment);
	}

	/**
	 * Normalize host names for internal/external comparison.
	 *
	 * @param string $host Host value.
	 * @return string
	 */
	private function normalize_host($host) {
		$host = strtolower(trim($host));

		if (0 === strpos($host, 'www.')) {
			$host = substr($host, 4);
		}

		return $host;
	}

	/**
	 * Build a normalised service record.
	 *
	 * @param array<string,mixed> $candidate Match data.
	 * @param string              $location Source location.
	 * @param string              $site_host Site host.
	 * @return array<string,mixed>|null
	 */
	private function build_record($candidate, $location, $site_host) {
		$url    = isset($candidate['url']) ? (string) $candidate['url'] : '';
		$domain = $this->normalize_host((string) wp_parse_url($url, PHP_URL_HOST));

		if (! $domain) {
			return null;
		}

		if ('cloudflare.com' === $domain || 'cdnjs.cloudflare.com' === $domain || (strlen($domain) > strlen('.cloudflare.com') && substr($domain, -strlen('.cloudflare.com')) === '.cloudflare.com')) {
			return null;
		}

		if ($site_host && $domain === $site_host) {
			return null;
		}

		$service = $this->guess_service($domain, $url);
		$execution_context = (string) ($candidate['execution_context'] ?? 'browser');
		$recommendation = 'server' === $execution_context
			? 'Interner Serveraufruf; kein Browser-Cookie-Dienst. Bei Bedarf separat in der technischen Dokumentation erfassen.'
			: $this->recommendation_for($service, $domain);

		return array(
			'service'        => $service,
			'domain'         => $domain,
			'url'            => esc_url_raw($url),
			'execution_context' => $execution_context,
			'location'       => $location,
			'line'           => isset($candidate['line']) ? (string) $candidate['line'] : '',
			'evidence'       => isset($candidate['evidence']) ? (string) $candidate['evidence'] : 'Direkter Request',
			'context'        => isset($candidate['context']) ? (string) $candidate['context'] : '',
			'recommendation' => $recommendation,
			'notice'         => $this->notice_for($service, $domain),
		);
	}

	/**
	 * Guess service name from URL and domain.
	 *
	 * @param string $domain Domain.
	 * @param string $url URL.
	 * @return string
	 */
	private function guess_service($domain, $url) {
		$domain = strtolower($domain);
		$url    = strtolower($url);

		if (false !== strpos($domain, 'openstreetmap.org') || false !== strpos($domain, 'nominatim.openstreetmap.org')) {
			return 'OpenStreetMap';
		}

		if ((false !== strpos($domain, 'google.com') || false !== strpos($domain, 'recaptcha.net')) && false !== strpos($url, 'recaptcha')) {
			return 'Google reCAPTCHA';
		}

		if (false !== strpos($url, 'leaflet') || false !== strpos($domain, 'leafletjs.com')) {
			return 'Leaflet';
		}

		if (false !== strpos($url, 'bootstrap') || false !== strpos($domain, 'bootstrapcdn.com') || false !== strpos($domain, 'jsdelivr.net')) {
			return 'Bootstrap/CDN';
		}

		if (false !== strpos($domain, 'google-analytics.com') || false !== strpos($domain, 'googletagmanager.com') || false !== strpos($domain, 'doubleclick.net')) {
			return 'Google Analytics/Tag Manager';
		}

		if (false !== strpos($domain, 'fonts.googleapis.com') || false !== strpos($domain, 'fonts.gstatic.com')) {
			return 'Google Fonts';
		}

		if (false !== strpos($domain, 'youtube.com') || false !== strpos($domain, 'youtube-nocookie.com') || false !== strpos($domain, 'youtu.be')) {
			return 'YouTube';
		}

		if (false !== strpos($domain, 'vimeo.com')) {
			return 'Vimeo';
		}

		if (false !== strpos($domain, 'fontawesome.com') || false !== strpos($domain, 'kit.fontawesome.com') || false !== strpos($domain, 'use.fontawesome.com')) {
			return 'Font Awesome';
		}

		if (false !== strpos($domain, 'unpkg.com')) {
			return 'unpkg CDN';
		}

		return 'Externer Dienst';
	}

	/**
	 * Return operator recommendation.
	 *
	 * @param string $service Service name.
	 * @param string $domain Domain.
	 * @return string
	 */
	private function recommendation_for($service, $domain) {
		switch ($service) {
			case 'OpenStreetMap':
				return 'Datenschutzhinweis, Attribution sowie Tile- und Referrer-Nutzung pruefen. Bei Bedarf eigene Tiles oder einen alternativen Anbieter vorsehen.';
			case 'Leaflet':
				return 'Leaflet moeglichst lokal ausliefern oder eine CDN-Nutzung ausdruecklich in Consent und Datenschutzerklaerung dokumentieren.';
			case 'Bootstrap/CDN':
			case 'unpkg CDN':
				return 'Pruefen, ob CDN-Dateien lokal ausgeliefert werden koennen. Falls nicht, den Dienst im Consent und in der Datenschutzerklaerung benennen.';
			case 'Google Analytics/Tag Manager':
				return 'Vor Einwilligung nicht laden. Auftragsverarbeitung, Datenuebermittlung und konkrete Zwecke dokumentieren.';
			case 'Google Fonts':
				return 'Schriftarten moeglichst lokal hosten, um unnoetige Drittanfragen zu vermeiden.';
			case 'YouTube':
			case 'Vimeo':
				return 'Eingebettete Medien erst nach Einwilligung oder ueber eine Zwei-Klick-Loesung freigeben.';
			case 'Font Awesome':
				return 'Assets moeglichst lokal ausliefern oder die externe Anfrage transparent dokumentieren.';
			default:
				return 'Pruefen, ob personenbezogene Daten, IP-Adressen oder Geraetedaten an ' . $domain . ' uebertragen werden.';
		}
	}

	/**
	 * Return condensed admin notice text.
	 *
	 * @param string $service Service name.
	 * @param string $domain Domain.
	 * @return string
	 */
	private function notice_for($service, $domain) {
		switch ($service) {
			case 'OpenStreetMap':
				return 'OpenStreetMap erkannt: Kartennutzung, Attribution und Datenschutzhinweis aufeinander abstimmen.';
			case 'Leaflet':
				return 'Leaflet erkannt: Bei CDN-Auslieferung lokale Bereitstellung oder Consent-Sperre bevorzugen.';
			case 'Bootstrap/CDN':
			case 'unpkg CDN':
				return 'Externe CDN-Ressourcen erkannt: Eine lokale Auslieferung ist datenschutzfreundlicher.';
			case 'Google Analytics/Tag Manager':
				return 'Tracking-Dienst erkannt: Bis zur Einwilligung blockieren und Rechtsgrundlage dokumentieren.';
			case 'Google Fonts':
				return 'Google Fonts erkannt: Lokales Hosting wird empfohlen.';
			default:
				return 'Externer Dienst erkannt: ' . $domain;
		}
	}

	/**
	 * Remove duplicates by service, domain and source evidence.
	 *
	 * @param array<int,array<string,mixed>> $records Records.
	 * @return array<int,array<string,mixed>>
	 */
	private function unique_records($records) {
		$seen    = array();
		$results = array();

		foreach ($records as $record) {
			$key = implode(
				'|',
				array(
					(string) ($record['service'] ?? ''),
					(string) ($record['domain'] ?? ''),
					(string) ($record['location'] ?? ''),
					(string) ($record['line'] ?? ''),
					(string) ($record['evidence'] ?? ''),
					(string) ($record['url'] ?? ''),
				)
			);

			if (isset($seen[ $key ])) {
				continue;
			}

			$seen[ $key ] = true;
			$results[]    = $record;
		}

		return $results;
	}
}
