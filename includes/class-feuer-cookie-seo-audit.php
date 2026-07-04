<?php
/**
 * Local SEO audit for published WordPress content.
 *
 * @package Feuer_Cookie
 */

if (! defined('ABSPATH')) {
	exit;
}

class Feuer_Cookie_SEO_Audit {

	/**
	 * Run the on-page SEO audit.
	 *
	 * @param string $events_table Events table name.
	 * @return array<string,mixed>
	 */
	public function scan($events_table = '') {
		$posts     = $this->get_public_posts();
		$traffic   = $this->get_page_view_map($events_table, 30);
		$rows      = array();
		$totals    = array(
			'pages'      => 0,
			'critical'   => 0,
			'warnings'   => 0,
			'opportunity'=> 0,
			'traffic'    => 0,
		);

		foreach ($posts as $post) {
			$row = $this->audit_post($post, $traffic);
			$rows[] = $row;

			$totals['pages']++;
			$totals['critical'] += (int) ('critical' === $row['severity']);
			$totals['warnings'] += (int) ('warning' === $row['severity']);
			$totals['opportunity'] += (int) ('opportunity' === $row['severity']);
			$totals['traffic'] += (int) $row['views_30d'];
		}

		usort(
			$rows,
			static function ($a, $b) {
				if ((int) $a['score'] === (int) $b['score']) {
					return (int) $b['views_30d'] <=> (int) $a['views_30d'];
				}

				return (int) $a['score'] <=> (int) $b['score'];
			}
		);

		return array(
			'generated_at' => current_time('mysql'),
			'source'       => 'local_wordpress_content',
			'lookback_days'=> 30,
			'totals'       => $totals,
			'rows'         => $rows,
			'recommendations' => $this->build_recommendations($rows),
		);
	}

	/**
	 * Get published public posts and pages.
	 *
	 * @return array<int,WP_Post>
	 */
	private function get_public_posts() {
		$post_types = get_post_types(array('public' => true), 'names');
		unset($post_types['attachment']);

		return get_posts(
			array(
				'post_type'              => array_values($post_types),
				'post_status'            => 'publish',
				'numberposts'            => 150,
				'orderby'                => 'modified',
				'order'                  => 'DESC',
				'suppress_filters'       => false,
				'ignore_sticky_posts'    => true,
				'no_found_rows'          => true,
				'update_post_meta_cache' => true,
				'update_post_term_cache' => false,
			)
		);
	}

	/**
	 * Build a 30-day traffic map by request path.
	 *
	 * @param string $events_table Events table name.
	 * @param int    $days Lookback days.
	 * @return array<string,int>
	 */
	private function get_page_view_map($events_table, $days) {
		global $wpdb;

		$map = array();
		$table = (string) $events_table;

		if ('' === $table || ! $this->table_exists($table)) {
			return $map;
		}

		$table_sql = '`' . str_replace('`', '``', $table) . '`';
		$since = wp_date('Y-m-d H:i:s', current_time('timestamp') - (max(1, (int) $days) * DAY_IN_SECONDS));
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT request_path, COUNT(*) AS hits
				FROM {$table_sql}
				WHERE request_type = 'page_view' AND is_robot = 0 AND created_at >= %s
				GROUP BY request_path",
				$since
			),
			ARRAY_A
		);

		foreach ((array) $rows as $row) {
			$path = $this->normalize_path((string) ($row['request_path'] ?? ''));
			$map[ $path ] = (int) ($row['hits'] ?? 0);
		}

		return $map;
	}

	/**
	 * Check if a table exists.
	 *
	 * @param string $table Table name.
	 * @return bool
	 */
	private function table_exists($table) {
		global $wpdb;

		return (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
	}

	/**
	 * Audit one post.
	 *
	 * @param WP_Post           $post Post object.
	 * @param array<string,int> $traffic Traffic map.
	 * @return array<string,mixed>
	 */
	private function audit_post($post, $traffic) {
		$url          = get_permalink($post);
		$path         = $this->normalize_path((string) wp_parse_url($url, PHP_URL_PATH));
		$title        = $this->get_seo_title($post);
		$description  = $this->get_meta_description($post);
		$canonical    = $this->get_canonical_url($post, $url);
		$content      = (string) $post->post_content;
		$plain_text   = trim(wp_strip_all_tags(strip_shortcodes($content)));
		$word_count   = str_word_count($plain_text);
		$issues       = array();
		$actions      = array();
		$score        = 100;
		$views_30d    = (int) ($traffic[ $path ] ?? 0);
		$image_count  = $this->count_images($content);
		$internal_links = $this->count_internal_links($content);

		$this->score_title($title, $issues, $actions, $score);
		$this->score_description($description, $issues, $actions, $score);
		$this->score_canonical($canonical, $url, $issues, $actions, $score);
		$this->score_indexing($post, $issues, $actions, $score);
		$this->score_content($word_count, $issues, $actions, $score);
		$this->score_links_and_media($internal_links, $image_count, $issues, $actions, $score);

		$score = max(0, min(100, $score));

		return array(
			'post_id'        => (int) $post->ID,
			'post_type'      => (string) $post->post_type,
			'title'          => get_the_title($post),
			'url'            => $url,
			'path'           => $path,
			'seo_title'      => $title,
			'description'    => $description,
			'canonical'      => $canonical,
			'word_count'     => $word_count,
			'image_count'    => $image_count,
			'internal_links' => $internal_links,
			'views_30d'      => $views_30d,
			'score'          => $score,
			'severity'       => $this->severity_for($score, $views_30d),
			'issues'         => array_values(array_unique($issues)),
			'actions'        => array_values(array_unique($actions)),
			'edit_url'       => get_edit_post_link($post->ID, 'raw'),
		);
	}

	/**
	 * Get SEO title from common SEO plugins or fallback to WP title.
	 *
	 * @param WP_Post $post Post object.
	 * @return string
	 */
	private function get_seo_title($post) {
		$candidates = array(
			get_post_meta($post->ID, '_yoast_wpseo_title', true),
			get_post_meta($post->ID, 'rank_math_title', true),
			get_post_meta($post->ID, '_aioseo_title', true),
			get_the_title($post),
		);

		foreach ($candidates as $candidate) {
			$candidate = trim(wp_strip_all_tags((string) $candidate));

			if ('' !== $candidate) {
				return $this->replace_title_tokens($candidate, $post);
			}
		}

		return '';
	}

	/**
	 * Get meta description from common SEO plugins or fallback to excerpt.
	 *
	 * @param WP_Post $post Post object.
	 * @return string
	 */
	private function get_meta_description($post) {
		$candidates = array(
			get_post_meta($post->ID, '_yoast_wpseo_metadesc', true),
			get_post_meta($post->ID, 'rank_math_description', true),
			get_post_meta($post->ID, '_aioseo_description', true),
			$post->post_excerpt,
		);

		foreach ($candidates as $candidate) {
			$candidate = trim(wp_strip_all_tags((string) $candidate));

			if ('' !== $candidate) {
				return $this->replace_title_tokens($candidate, $post);
			}
		}

		return '';
	}

	/**
	 * Replace common SEO plugin title tokens.
	 *
	 * @param string  $value Raw value.
	 * @param WP_Post $post Post object.
	 * @return string
	 */
	private function replace_title_tokens($value, $post) {
		$replacements = array(
			'%%title%%'    => get_the_title($post),
			'%title%'      => get_the_title($post),
			'%%sitename%%' => get_bloginfo('name'),
			'%sitename%'   => get_bloginfo('name'),
			'%sep%'        => '-',
			'%%sep%%'      => '-',
		);

		return trim(strtr($value, $replacements));
	}

	/**
	 * Get canonical URL from common SEO plugins or permalink fallback.
	 *
	 * @param WP_Post $post Post object.
	 * @param string  $fallback Fallback URL.
	 * @return string
	 */
	private function get_canonical_url($post, $fallback) {
		$candidates = array(
			get_post_meta($post->ID, '_yoast_wpseo_canonical', true),
			get_post_meta($post->ID, 'rank_math_canonical_url', true),
			get_post_meta($post->ID, '_aioseo_canonical_url', true),
			$fallback,
		);

		foreach ($candidates as $candidate) {
			$candidate = esc_url_raw((string) $candidate);

			if ('' !== $candidate) {
				return $candidate;
			}
		}

		return '';
	}

	/**
	 * Score title signals.
	 *
	 * @param string           $title SEO title.
	 * @param array<int,string> $issues Issues.
	 * @param array<int,string> $actions Actions.
	 * @param int              $score Score.
	 * @return void
	 */
	private function score_title($title, &$issues, &$actions, &$score) {
		$length = strlen($title);

		if ('' === $title) {
			$issues[] = 'SEO-Titel fehlt.';
			$actions[] = 'Einen eindeutigen Titel mit Hauptthema und Orts-/Leistungsbezug setzen.';
			$score -= 22;
			return;
		}

		if ($length < 30 || $length > 65) {
			$issues[] = 'SEO-Titel ausserhalb des empfohlenen Bereichs.';
			$actions[] = 'Titel auf etwa 30 bis 65 Zeichen bringen.';
			$score -= 10;
		}
	}

	/**
	 * Score meta description.
	 *
	 * @param string           $description Meta description.
	 * @param array<int,string> $issues Issues.
	 * @param array<int,string> $actions Actions.
	 * @param int              $score Score.
	 * @return void
	 */
	private function score_description($description, &$issues, &$actions, &$score) {
		$length = strlen($description);

		if ('' === $description) {
			$issues[] = 'Meta-Beschreibung fehlt.';
			$actions[] = 'Eine konkrete Meta-Beschreibung mit Nutzenversprechen ergaenzen.';
			$score -= 18;
			return;
		}

		if ($length < 80 || $length > 160) {
			$issues[] = 'Meta-Beschreibung hat keine optimale Laenge.';
			$actions[] = 'Beschreibung auf etwa 80 bis 160 Zeichen verdichten.';
			$score -= 8;
		}
	}

	/**
	 * Score canonical URL.
	 *
	 * @param string           $canonical Canonical URL.
	 * @param string           $url Current URL.
	 * @param array<int,string> $issues Issues.
	 * @param array<int,string> $actions Actions.
	 * @param int              $score Score.
	 * @return void
	 */
	private function score_canonical($canonical, $url, &$issues, &$actions, &$score) {
		if ('' === $canonical) {
			$issues[] = 'Canonical fehlt.';
			$actions[] = 'Canonical auf die bevorzugte URL setzen.';
			$score -= 8;
			return;
		}

		if ($this->normalize_path((string) wp_parse_url($canonical, PHP_URL_PATH)) !== $this->normalize_path((string) wp_parse_url($url, PHP_URL_PATH))) {
			$issues[] = 'Canonical zeigt auf eine andere URL.';
			$actions[] = 'Pruefen, ob die Seite wirklich konsolidiert werden soll.';
			$score -= 12;
		}
	}

	/**
	 * Score indexability.
	 *
	 * @param WP_Post          $post Post object.
	 * @param array<int,string> $issues Issues.
	 * @param array<int,string> $actions Actions.
	 * @param int              $score Score.
	 * @return void
	 */
	private function score_indexing($post, &$issues, &$actions, &$score) {
		$robots = array(
			get_post_meta($post->ID, '_yoast_wpseo_meta-robots-noindex', true),
			get_post_meta($post->ID, 'rank_math_robots', true),
			get_post_meta($post->ID, '_aioseo_robots_noindex', true),
		);
		$robots_text = strtolower(wp_json_encode($robots));

		if (false !== strpos($robots_text, 'noindex') || false !== strpos($robots_text, '"1"')) {
			$issues[] = 'Noindex-Signal gefunden.';
			$actions[] = 'Indexierung pruefen, wenn diese Seite organischen Traffic bringen soll.';
			$score -= 24;
		}
	}

	/**
	 * Score content depth.
	 *
	 * @param int              $word_count Word count.
	 * @param array<int,string> $issues Issues.
	 * @param array<int,string> $actions Actions.
	 * @param int              $score Score.
	 * @return void
	 */
	private function score_content($word_count, &$issues, &$actions, &$score) {
		if ($word_count < 120) {
			$issues[] = 'Sehr wenig indexierbarer Text.';
			$actions[] = 'Suchintention mit klaren Abschnitten, Fakten und internen Links abdecken.';
			$score -= 14;
		}
	}

	/**
	 * Score links and media.
	 *
	 * @param int              $internal_links Internal link count.
	 * @param int              $image_count Image count.
	 * @param array<int,string> $issues Issues.
	 * @param array<int,string> $actions Actions.
	 * @param int              $score Score.
	 * @return void
	 */
	private function score_links_and_media($internal_links, $image_count, &$issues, &$actions, &$score) {
		if ($internal_links < 1) {
			$issues[] = 'Keine internen Links im Inhalt erkannt.';
			$actions[] = 'Mindestens einen passenden internen Link zu verwandten Inhalten setzen.';
			$score -= 8;
		}

	}

	/**
	 * Count images in post content.
	 *
	 * @param string $content Post content.
	 * @return int
	 */
	private function count_images($content) {
		return preg_match_all('/<img\b/i', $content);
	}

	/**
	 * Count internal links in post content.
	 *
	 * @param string $content Post content.
	 * @return int
	 */
	private function count_internal_links($content) {
		$home_host = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
		$count = 0;

		if (! preg_match_all('/<a\b[^>]*href=["\']([^"\']+)["\']/i', $content, $matches)) {
			return 0;
		}

		foreach ($matches[1] as $href) {
			$href = trim((string) $href);

			if (0 === strpos($href, '/') && 0 !== strpos($href, '//')) {
				$count++;
				continue;
			}

			$host = strtolower((string) wp_parse_url($href, PHP_URL_HOST));

			if ($host && $host === $home_host) {
				$count++;
			}
		}

		return $count;
	}

	/**
	 * Normalize URL path.
	 *
	 * @param string $path URL path.
	 * @return string
	 */
	private function normalize_path($path) {
		$path = '/' . trim((string) $path, '/');

		return '/' === $path ? '/' : trailingslashit($path);
	}

	/**
	 * Determine severity by score and traffic.
	 *
	 * @param int $score Score.
	 * @param int $views Views.
	 * @return string
	 */
	private function severity_for($score, $views) {
		if ($score < 60) {
			return 'critical';
		}

		if ($score < 82) {
			return 'warning';
		}

		if ($views > 20) {
			return 'opportunity';
		}

		return 'ok';
	}

	/**
	 * Build top-level recommendations.
	 *
	 * @param array<int,array<string,mixed>> $rows Audit rows.
	 * @return array<int,string>
	 */
	private function build_recommendations($rows) {
		$recommendations = array();
		$missing_desc = 0;
		$short_content = 0;
		$hot_issues = 0;

		foreach ($rows as $row) {
			$issues = (array) ($row['issues'] ?? array());

			if (in_array('Meta-Beschreibung fehlt.', $issues, true)) {
				$missing_desc++;
			}

			if (in_array('Sehr wenig indexierbarer Text.', $issues, true)) {
				$short_content++;
			}

			if ((int) ($row['views_30d'] ?? 0) > 20 && (int) ($row['score'] ?? 100) < 82) {
				$hot_issues++;
			}
		}

		if ($hot_issues > 0) {
			$recommendations[] = 'Zuerst Seiten mit Traffic und Score unter 82 optimieren, weil dort Wirkung schneller sichtbar wird.';
		}

		if ($missing_desc > 0) {
			$recommendations[] = 'Fehlende Meta-Beschreibungen nachtragen und mit klarer Handlungsabsicht formulieren.';
		}

		if ($short_content > 0) {
			$recommendations[] = 'Duenne Seiten pruefen: ausbauen, zusammenlegen oder bewusst aus der Suche nehmen.';
		}

		if (empty($recommendations)) {
			$recommendations[] = 'Die wichtigsten Basis-Signale sind gepflegt. Naechster Schritt: Keyword-Cluster je Seite und interne Links gezielt ausbauen.';
		}

		return $recommendations;
	}
}
