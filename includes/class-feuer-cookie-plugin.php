<?php

if (! defined('ABSPATH')) {
	exit;
}

class Feuer_Cookie_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Feuer_Cookie_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Settings option key.
	 *
	 * @var string
	 */
	private $settings_key = 'feuer_cookie_settings';

	/**
	 * Diagnostics option key.
	 *
	 * @var string
	 */
	private $diagnostics_key = 'feuer_cookie_diagnostics_report';

	/**
	 * Consent revision history option key.
	 *
	 * @var string
	 */
	private $consent_log_key = 'feuer_cookie_consent_revision_log';

	/**
	 * Database schema version option key.
	 *
	 * @var string
	 */
	private $schema_version_key = 'feuer_cookie_schema_version';

	/**
	 * Consent cookie name.
	 *
	 * @var string
	 */
	private $consent_cookie = 'feuer_cookie_consent';

	/**
	 * Session cookie name.
	 *
	 * @var string
	 */
	private $session_cookie = 'feuer_cookie_session';

	/**
	 * Revision cookie name.
	 *
	 * @var string
	 */
	private $revision_cookie = 'feuer_cookie_revision';

	/**
	 * Preferences cookie name.
	 *
	 * @var string
	 */
	private $preferences_cookie = 'feuer_cookie_preferences';

	/**
	 * Table names.
	 *
	 * @var array<string,string>
	 */
	private $tables = array();

	/**
	 * Diagnostics object.
	 *
	 * @var Feuer_Cookie_Diagnostics
	 */
	private $diagnostics;

	/**
	 * Return singleton.
	 *
	 * @return Feuer_Cookie_Plugin
	 */
	public static function instance() {
		if (null === self::$instance) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Short summary for compact banner layouts.
	 *
	 * @return string
	 */
	private function get_banner_summary_text() {
		return 'Wir verwenden notwendige Funktionen und optionale Statistik, Karten sowie externe Inhalte. Sie koennen akzeptieren, ablehnen oder individuell auswaehlen.';
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		global $wpdb;

		$this->tables = array(
			'events'      => $wpdb->prefix . 'feu-cookie_events',
			'daily_stats' => $wpdb->prefix . 'feu-cookie_daily_stats',
			'ip_blocks'   => $wpdb->prefix . 'feu-cookie_ip_blocks',
		);

		$this->diagnostics = new Feuer_Cookie_Diagnostics();
		add_action('init', array($this, 'register_hooks'));
	}

	/**
	 * Activation hook.
	 *
	 * @return void
	 */
	public static function activate() {
		$plugin = self::instance();

		$plugin->create_tables();
		$plugin->maybe_seed_defaults();

		if (! wp_next_scheduled('feuer_cookie_cleanup_logs')) {
			wp_schedule_event(time(), 'daily', 'feuer_cookie_cleanup_logs');
		}
	}

	/**
	 * Deactivation hook.
	 *
	 * @return void
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook('feuer_cookie_cleanup_logs');
	}

	/**
	 * Return a raw table name for wpdb helpers.
	 *
	 * @param string $key Table key.
	 * @return string
	 */
	private function table_name($key) {
		return (string) ($this->tables[ $key ] ?? '');
	}

	/**
	 * Return a SQL-safe quoted table identifier.
	 *
	 * @param string $key Table key.
	 * @return string
	 */
	private function table_sql($key) {
		return '`' . str_replace('`', '``', $this->table_name($key)) . '`';
	}

	/**
	 * Register plugin hooks.
	 *
	 * @return void
	 */
	public function register_hooks() {
		$this->migrate_banner_layout_setting();
		$this->maybe_upgrade_schema();

		add_action('admin_menu', array($this, 'register_admin_menu'));
		add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
		add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_assets'));
		// Must run before a GTM container that is printed through wp_head.
		add_action('wp_head', array($this, 'render_gtm_consent_defaults'), 0);
		add_action('template_redirect', array($this, 'maybe_reset_demo_mode_storage'), -1);
		add_action('template_redirect', array($this, 'handle_frontend_gate'), 0);
		add_action('template_redirect', array($this, 'maybe_track_analytics_page_view'), 20);
		add_action('wp_footer', array($this, 'render_consent_banner'), 1);
		add_action('wp_footer', array($this, 'render_reopen_button'));
		add_action('wp_ajax_feuer_cookie_set_consent', array($this, 'handle_consent_ajax'));
		add_action('wp_ajax_nopriv_feuer_cookie_set_consent', array($this, 'handle_consent_ajax'));
		add_action('admin_post_feuer_cookie_save_settings', array($this, 'handle_settings_save'));
		add_action('admin_post_feuer_cookie_check_updates', array($this, 'handle_check_updates'));
		add_action('admin_post_feuer_cookie_add_block', array($this, 'handle_add_block'));
		add_action('admin_post_feuer_cookie_delete_block', array($this, 'handle_delete_block'));
		add_action('admin_post_feuer_cookie_run_diagnostics', array($this, 'handle_run_diagnostics'));
		add_action('admin_post_feuer_cookie_run_seo_audit', array($this, 'handle_run_seo_audit'));
		add_action('admin_post_feuer_cookie_export_events', array($this, 'handle_export_events'));
		add_action('admin_post_feuer_cookie_export_analytics', array($this, 'handle_analytics_export'));
		add_action('feuer_cookie_cleanup_logs', array($this, 'cleanup_old_logs'));
		add_filter('the_content', array($this, 'filter_content_embeds'), 20);
		add_filter('pre_do_shortcode_tag', array($this, 'filter_external_shortcode_tag'), 10, 4);
		add_shortcode('feuer_cookie_header_macro', array($this, 'shortcode_header_macro'));
		add_shortcode('feuer_cookie_footer_macro', array($this, 'shortcode_footer_macro'));
		add_shortcode('feuer_cookie_service_gate', array($this, 'shortcode_service_gate'));
		add_filter('plugin_action_links_' . plugin_basename(FEUER_COOKIE_FILE), array($this, 'add_action_links'));
		add_filter('script_loader_tag', array($this, 'filter_external_script_tag'), 10, 3);
		add_filter('style_loader_tag', array($this, 'filter_external_style_tag'), 10, 4);
		// Feuer Analytics may register its service independently. Keep it visible
		// only when the site owner has explicitly enabled the optional bridge.
		add_filter('feuer_cookie_registered_services', array($this, 'filter_feuer_analytics_service'), 100);
	}

	/**
	 * Create plugin database tables.
	 *
	 * @return void
	 */
	private function create_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$events_table = $this->table_sql('events');
		$daily_stats_table = $this->table_sql('daily_stats');
		$ip_blocks_table = $this->table_sql('ip_blocks');

		$events_sql = "CREATE TABLE {$events_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			session_token varchar(64) NOT NULL,
			consent_state varchar(20) NOT NULL,
			request_type varchar(32) NOT NULL,
			request_url text NOT NULL,
			request_path varchar(191) NOT NULL,
			referrer_url text NULL,
			referrer_path varchar(191) NULL,
			page_title varchar(191) NULL,
			ip_address varchar(100) NULL,
			ip_hash char(64) NOT NULL,
			user_agent text NULL,
			browser_lang varchar(32) NULL,
			country_code varchar(8) NULL,
			country_name varchar(100) NULL,
			city varchar(120) NULL,
			device_type varchar(32) NULL,
			browser_name varchar(64) NULL,
			os_name varchar(64) NULL,
			is_robot tinyint(1) NOT NULL DEFAULT 0,
			meta_json longtext NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY session_token (session_token),
			KEY consent_state (consent_state),
			KEY request_type (request_type),
			KEY request_path (request_path),
			KEY referrer_path (referrer_path),
			KEY country_code (country_code),
			KEY city (city),
			KEY device_type (device_type),
			KEY browser_name (browser_name),
			KEY created_at (created_at),
			KEY event_period (request_type, created_at)
		) {$charset};";

		$blocks_sql = "CREATE TABLE {$ip_blocks_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			ip_pattern varchar(100) NOT NULL,
			reason text NULL,
			is_active tinyint(1) NOT NULL DEFAULT 1,
			created_by bigint(20) unsigned NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY ip_pattern (ip_pattern),
			KEY is_active (is_active)
		) {$charset};";

		$daily_stats_sql = "CREATE TABLE {$daily_stats_table} (
			day date NOT NULL,
			page_views bigint(20) unsigned NOT NULL DEFAULT 0,
			unique_visitors bigint(20) unsigned NOT NULL DEFAULT 0,
			consents_accept bigint(20) unsigned NOT NULL DEFAULT 0,
			consents_decline bigint(20) unsigned NOT NULL DEFAULT 0,
			blocked_requests bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (day)
		) {$charset};";

		dbDelta($events_sql);
		dbDelta($daily_stats_sql);
		dbDelta($blocks_sql);
		update_option($this->schema_version_key, FEUER_COOKIE_VERSION);
	}

	/**
	 * Upgrade local tables after plugin updates.
	 *
	 * @return void
	 */
	private function maybe_upgrade_schema() {
		if (get_option($this->schema_version_key) === FEUER_COOKIE_VERSION) {
			return;
		}

		$this->create_tables();
	}

	/**
	 * Seed defaults on first activation.
	 *
	 * @return void
	 */
	private function maybe_seed_defaults() {
		$current = get_option($this->settings_key);

		if (! is_array($current)) {
			$defaults = $this->get_default_settings();
			add_option($this->settings_key, $defaults);
			add_option($this->consent_log_key, array($this->build_revision_log_entry($defaults)));
			return;
		}

		$settings = wp_parse_args($current, $this->get_default_settings());
		update_option($this->settings_key, $settings);

		if (! is_array(get_option($this->consent_log_key))) {
			add_option($this->consent_log_key, array($this->build_revision_log_entry($settings)));
		}
	}

	/**
	 * Move saved banner layouts to their current identifiers without changing the selected style.
	 *
	 * @return void
	 */
	private function migrate_banner_layout_setting() {
		$settings = get_option($this->settings_key);

		if (! is_array($settings) || ! isset($settings['banner_layout'])) {
			return;
		}

		$layout = $this->sanitize_banner_layout((string) $settings['banner_layout']);

		if ($layout !== $settings['banner_layout']) {
			$settings['banner_layout'] = $layout;
			update_option($this->settings_key, $settings);
		}
	}

	/**
	 * Register admin menu pages.
	 *
	 * @return void
	 */
	public function register_admin_menu() {
		add_menu_page(
			'Feuer-Cookie',
			'Feuer-Cookie',
			'manage_options',
			'feuer-cookie',
			array($this, 'render_overview_page'),
			'dashicons-shield-alt',
			56
		);

		add_submenu_page('feuer-cookie', 'Übersicht', 'Übersicht', 'manage_options', 'feuer-cookie', array($this, 'render_overview_page'));
		add_submenu_page('feuer-cookie', 'Statistik', 'Statistik', 'manage_options', 'feuer-cookie-analytics', array($this, 'render_analytics_page'));
		add_submenu_page('feuer-cookie', 'Besucher', 'Besucher', 'manage_options', 'feuer-cookie-visitors', array($this, 'render_visitors_page'));
		add_submenu_page('feuer-cookie', 'Status', 'Status', 'manage_options', 'feuer-cookie-status', array($this, 'render_status_page'));
		add_submenu_page('feuer-cookie', 'IP-Sperrliste', 'IP-Sperrliste', 'manage_options', 'feuer-cookie-blocklist', array($this, 'render_blocklist_page'));
		add_submenu_page('feuer-cookie', 'Diagnose', 'Diagnose', 'manage_options', 'feuer-cookie-diagnostics', array($this, 'render_diagnostics_page'));
		add_submenu_page('feuer-cookie', 'SEO-Audit', 'SEO-Audit', 'manage_options', 'feuer-cookie-seo-audit', array($this, 'render_seo_audit_page'));
		add_submenu_page('feuer-cookie', 'Einstellungen', 'Einstellungen', 'manage_options', 'feuer-cookie-settings', array($this, 'render_settings_page'));
		add_submenu_page('feuer-cookie', 'Dokumentation', 'Dokumentation', 'manage_options', 'feuer-cookie-docs', array($this, 'render_docs_page'));
	}

	/**
	 * Register a WordPress dashboard widget with visitor and consent stats.
	 *
	 * @return void
	 */
	public function register_dashboard_widget() {
		if (! current_user_can('manage_options')) {
			return;
		}

		wp_add_dashboard_widget(
			'feuer_cookie_dashboard_widget',
			'Feuer-Cookie Besucherstatus',
			array($this, 'render_dashboard_widget')
		);
	}

	/**
	 * Render the WordPress dashboard widget.
	 *
	 * @return void
	 */
	public function render_dashboard_widget() {
		$stats = $this->get_dashboard_widget_stats();
		?>
		<div class="feuer-cookie-dashboard-widget" data-feuer-cookie-dashboard-widget>
			<div class="feuer-cookie-dashboard-widget__hero">
				<div>
					<p class="feuer-cookie-dashboard-widget__eyebrow"><span class="dashicons dashicons-shield-alt" aria-hidden="true"></span> Consent & Statistik</p>
					<h3>Besucher heute im Blick</h3>
				</div>
				<div class="feuer-cookie-dashboard-widget__score">
					<span><?php echo esc_html(number_format_i18n((int) $stats['today_visitors'])); ?></span>
					<small>Besucher</small>
				</div>
			</div>

			<div class="feuer-cookie-dashboard-tabs" role="tablist" aria-label="Feuer-Cookie Dashboard">
				<button id="feuer-cookie-dashboard-tab-today" type="button" role="tab" aria-selected="true" aria-controls="feuer-cookie-dashboard-panel-today" tabindex="0" class="is-active" data-feuer-cookie-dashboard-tab="today"><span class="dashicons dashicons-visibility" aria-hidden="true"></span> Heute</button>
				<button id="feuer-cookie-dashboard-tab-week" type="button" role="tab" aria-selected="false" aria-controls="feuer-cookie-dashboard-panel-week" tabindex="-1" data-feuer-cookie-dashboard-tab="week"><span class="dashicons dashicons-chart-area" aria-hidden="true"></span> 7 Tage</button>
				<button id="feuer-cookie-dashboard-tab-cookie" type="button" role="tab" aria-selected="false" aria-controls="feuer-cookie-dashboard-panel-cookie" tabindex="-1" data-feuer-cookie-dashboard-tab="cookie"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span> Cookie</button>
				<button id="feuer-cookie-dashboard-tab-status" type="button" role="tab" aria-selected="false" aria-controls="feuer-cookie-dashboard-panel-status" tabindex="-1" data-feuer-cookie-dashboard-tab="status"><span class="dashicons dashicons-performance" aria-hidden="true"></span> Status</button>
			</div>

			<div id="feuer-cookie-dashboard-panel-today" class="feuer-cookie-dashboard-panel is-active" role="tabpanel" aria-labelledby="feuer-cookie-dashboard-tab-today" tabindex="0" data-feuer-cookie-dashboard-panel="today">
				<div class="feuer-cookie-dashboard-metrics">
					<?php $this->render_dashboard_metric('Aufrufe', (int) $stats['today_page_views'], 'dashicons-chart-bar'); ?>
					<?php $this->render_dashboard_metric('Besucher', (int) $stats['today_visitors'], 'dashicons-groups'); ?>
					<?php $this->render_dashboard_metric('Cookie-Auswahl', (int) $stats['today_consent_total'], 'dashicons-privacy'); ?>
				</div>
				<?php $this->render_dashboard_top_pages($stats['today_top_pages']); ?>
			</div>

			<div id="feuer-cookie-dashboard-panel-week" class="feuer-cookie-dashboard-panel" role="tabpanel" aria-labelledby="feuer-cookie-dashboard-tab-week" tabindex="0" data-feuer-cookie-dashboard-panel="week" hidden>
				<div class="feuer-cookie-dashboard-metrics">
					<?php $this->render_dashboard_metric('Aufrufe', (int) $stats['week_page_views'], 'dashicons-chart-line'); ?>
					<?php $this->render_dashboard_metric('Besucher', (int) $stats['week_visitors'], 'dashicons-admin-users'); ?>
					<?php $this->render_dashboard_metric('Engagement', (int) $stats['week_engagement'], 'dashicons-clock'); ?>
				</div>
				<p class="feuer-cookie-dashboard-note">Zeitraum: letzte 7 Tage, Robots ausgeschlossen.</p>
			</div>

			<div id="feuer-cookie-dashboard-panel-cookie" class="feuer-cookie-dashboard-panel" role="tabpanel" aria-labelledby="feuer-cookie-dashboard-tab-cookie" tabindex="0" data-feuer-cookie-dashboard-panel="cookie" hidden>
				<div class="feuer-cookie-dashboard-metrics">
					<?php $this->render_dashboard_metric('Bestaetigt', (int) $stats['week_consent_optional'], 'dashicons-yes'); ?>
					<?php $this->render_dashboard_metric('Nur erforderlich', (int) $stats['week_consent_necessary'], 'dashicons-lock'); ?>
					<?php $this->render_dashboard_metric('Quote', (string) $stats['week_consent_rate'] . '%', 'dashicons-chart-pie'); ?>
				</div>
				<p class="feuer-cookie-dashboard-note">Letzte Cookie-Entscheidung: <strong><?php echo esc_html((string) $stats['last_consent']); ?></strong></p>
			</div>

			<div id="feuer-cookie-dashboard-panel-status" class="feuer-cookie-dashboard-panel" role="tabpanel" aria-labelledby="feuer-cookie-dashboard-tab-status" tabindex="0" data-feuer-cookie-dashboard-panel="status" hidden>
				<div class="feuer-cookie-dashboard-status">
					<p><span class="dashicons dashicons-database" aria-hidden="true"></span> Tabellen: <strong><?php echo $stats['tables_ok'] ? 'OK' : 'Pruefen'; ?></strong></p>
					<p><span class="dashicons dashicons-admin-network" aria-hidden="true"></span> Realtime 15 Min.: <strong><?php echo esc_html(number_format_i18n((int) $stats['active_15m'])); ?></strong></p>
					<p><span class="dashicons dashicons-admin-tools" aria-hidden="true"></span> Admin-Bypass: <strong><?php echo $stats['admin_bypass'] ? 'Aktiv' : 'Aus'; ?></strong></p>
					<p><span class="dashicons dashicons-warning" aria-hidden="true"></span> Demo-Modus: <strong><?php echo $stats['demo_mode'] ? 'Aktiv' : 'Aus'; ?></strong></p>
				</div>
			</div>

			<div class="feuer-cookie-dashboard-actions">
				<a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=feuer-cookie')); ?>">Uebersicht</a>
				<a class="button" href="<?php echo esc_url(admin_url('admin.php?page=feuer-cookie-visitors')); ?>">Besucher</a>
				<a class="button" href="<?php echo esc_url(admin_url('admin.php?page=feuer-cookie-status')); ?>">Status</a>
			</div>
		</div>
		<?php
	}

	/**
	 * Collect compact dashboard widget statistics.
	 *
	 * @return array<string,mixed>
	 */
	private function get_dashboard_widget_stats() {
		global $wpdb;

		$settings = $this->get_settings();
		$tables_ok = $this->table_exists('events') && $this->table_exists('daily_stats');
		$empty = array(
			'today_page_views'       => 0,
			'today_visitors'         => 0,
			'today_consent_total'    => 0,
			'today_top_pages'        => array(),
			'week_page_views'        => 0,
			'week_visitors'          => 0,
			'week_engagement'        => 0,
			'week_consent_optional'  => 0,
			'week_consent_necessary' => 0,
			'week_consent_rate'      => 0,
			'last_consent'           => '-',
			'active_15m'             => 0,
			'tables_ok'              => $tables_ok,
			'admin_bypass'           => ! empty($settings['admin_bypass']),
			'demo_mode'              => ! empty($settings['demo_mode']),
		);

		if (! $tables_ok) {
			return $empty;
		}

		$events_table = $this->table_sql('events');
		$today_start  = wp_date('Y-m-d 00:00:00', current_time('timestamp'));
		$week_start   = wp_date('Y-m-d H:i:s', current_time('timestamp') - (7 * DAY_IN_SECONDS));
		$since_15     = wp_date('Y-m-d H:i:s', current_time('timestamp') - (15 * MINUTE_IN_SECONDS));

		$optional_sql = "request_type IN ('consent_accept_all', 'consent_custom', 'consent_service_allow')";

		$empty['today_page_views'] = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$events_table} WHERE request_type = 'page_view' AND is_robot = 0 AND created_at >= %s", $today_start));
		$empty['today_visitors'] = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT session_token) FROM {$events_table} WHERE request_type = 'page_view' AND is_robot = 0 AND created_at >= %s", $today_start));
		$today_optional = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$events_table} WHERE {$optional_sql} AND is_robot = 0 AND created_at >= %s", $today_start));
		$today_necessary = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$events_table} WHERE request_type = 'consent_necessary' AND is_robot = 0 AND created_at >= %s", $today_start));
		$empty['today_consent_total'] = $today_optional + $today_necessary;
		$empty['week_page_views'] = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$events_table} WHERE request_type = 'page_view' AND is_robot = 0 AND created_at >= %s", $week_start));
		$empty['week_visitors'] = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT session_token) FROM {$events_table} WHERE request_type = 'page_view' AND is_robot = 0 AND created_at >= %s", $week_start));
		$empty['week_engagement'] = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$events_table} WHERE request_type = 'page_engagement' AND is_robot = 0 AND created_at >= %s", $week_start));
		$empty['week_consent_optional'] = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$events_table} WHERE {$optional_sql} AND is_robot = 0 AND created_at >= %s", $week_start));
		$empty['week_consent_necessary'] = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$events_table} WHERE request_type = 'consent_necessary' AND is_robot = 0 AND created_at >= %s", $week_start));
		$week_consent_total = $empty['week_consent_optional'] + $empty['week_consent_necessary'];
		$empty['week_consent_rate'] = $week_consent_total > 0 ? (int) round(($empty['week_consent_optional'] / $week_consent_total) * 100) : 0;
		$empty['last_consent'] = $this->get_last_consent_time() ?: '-';
		$empty['active_15m'] = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT session_token) FROM {$events_table} WHERE is_robot = 0 AND created_at >= %s", $since_15));
		$empty['today_top_pages'] = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT request_path, COUNT(*) AS hits
				FROM {$events_table}
				WHERE request_type = 'page_view' AND is_robot = 0 AND created_at >= %s
				GROUP BY request_path
				ORDER BY hits DESC
				LIMIT 4",
				$today_start
			),
			ARRAY_A
		);

		return $empty;
	}

	/**
	 * Render a compact dashboard widget metric.
	 *
	 * @param string     $label Metric label.
	 * @param int|string $value Metric value.
	 * @param string     $icon Dashicon class.
	 * @return void
	 */
	private function render_dashboard_metric($label, $value, $icon) {
		$display = is_numeric($value) ? number_format_i18n($value) : (string) $value;
		?>
		<div class="feuer-cookie-dashboard-metric">
			<span class="dashicons <?php echo esc_attr($icon); ?>" aria-hidden="true"></span>
			<strong><?php echo esc_html($display); ?></strong>
			<small><?php echo esc_html($label); ?></small>
		</div>
		<?php
	}

	/**
	 * Render today's top pages in the dashboard widget.
	 *
	 * @param array<int,array<string,mixed>> $pages Page rows.
	 * @return void
	 */
	private function render_dashboard_top_pages($pages) {
		?>
		<div class="feuer-cookie-dashboard-top">
			<strong>Top-Seiten heute</strong>
			<?php if ($pages) : ?>
				<ul>
					<?php foreach ($pages as $page) : ?>
						<li>
							<span title="<?php echo esc_attr((string) $page['request_path']); ?>"><?php echo esc_html($this->format_admin_path_label((string) $page['request_path'])); ?></span>
							<em><?php echo esc_html(number_format_i18n((int) $page['hits'])); ?></em>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php else : ?>
				<p>Noch keine Seitenaufrufe heute.</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Enqueue admin assets.
	 *
	 * @param string $hook Current admin hook.
	 * @return void
	 */
	public function enqueue_admin_assets($hook) {
		if (false === strpos($hook, 'feuer-cookie') && 'index.php' !== $hook) {
			return;
		}

		wp_enqueue_style('feuer-cookie-admin', FEUER_COOKIE_URL . 'assets/css/admin.css', array(), FEUER_COOKIE_VERSION);
		wp_enqueue_script('feuer-cookie-admin', FEUER_COOKIE_URL . 'assets/js/admin.js', array('jquery'), FEUER_COOKIE_VERSION, true);
		wp_localize_script(
			'feuer-cookie-admin',
			'FeuerCookieAdmin',
			array(
				'ajaxUrl'         => admin_url('admin-ajax.php'),
				'nonce'           => wp_create_nonce('feuer_cookie_consent'),
				'currentUrl'      => $this->get_settings_page_url($this->get_current_settings_tab()),
				'localStorageKey' => 'feuer-cookie:' . $this->get_cookie_name('consent') . ':record',
				'messages'        => array(
					'cleared' => 'Die gespeicherte Einwilligung in diesem Browser wurde geloescht.',
					'error'   => 'Die Einwilligung konnte nicht geloescht werden.',
				),
			)
		);

		if (false !== strpos($hook, 'feuer-cookie')) {
			wp_enqueue_media();
		}
	}

	/**
	 * Enqueue frontend assets for banner, panel and placeholders.
	 *
	 * @return void
	 */
	public function enqueue_frontend_assets() {
		if (! $this->should_handle_frontend_request()) {
			return;
		}

		if ($this->is_robot_request()) {
			return;
		}

		if ($this->is_admin_bypass_active()) {
			return;
		}

		$this->register_frontend_assets();
	}

	/**
	 * Register frontend CSS and JS.
	 *
	 * @return void
	 */
	private function register_frontend_assets() {
		wp_enqueue_style('feuer-cookie-frontend', FEUER_COOKIE_URL . 'assets/css/frontend.css', array(), FEUER_COOKIE_VERSION);
		wp_enqueue_script('feuer-cookie-frontend', FEUER_COOKIE_URL . 'assets/js/frontend.js', array(), FEUER_COOKIE_VERSION, true);
		wp_localize_script(
			'feuer-cookie-frontend',
			'FeuerCookieData',
			$this->get_frontend_config()
		);
	}

	/**
	 * Liefert die JavaScript-Konfiguration für lokale Plugin-Skripte.
	 *
	 * @return array<string,mixed>
	 */
	private function get_frontend_config() {
		$settings       = $this->get_settings();
		$preferences    = $this->get_saved_preferences();
		$consent_record = $this->get_saved_consent_record();

		return array(
			'ajaxUrl'         => admin_url('admin-ajax.php'),
			'nonce'           => wp_create_nonce('feuer_cookie_consent'),
			'currentUrl'      => $this->current_url(),
			'acceptLabel'     => $settings['accept_label'],
			'declineLabel'    => $settings['decline_label'],
			'saveLabel'       => $settings['save_label'],
			'reopenLabel'     => $settings['reopen_label'],
			'settingsLabel'   => $settings['settings_label'],
			'serviceAllowLabel' => $settings['service_allow_label'],
			'preferences'     => $preferences,
			'consentState'    => $this->get_consent_state(),
			'consentRecord'   => $consent_record,
			'demoMode'        => $this->is_demo_mode_active(),
			'gtmConsentMode'  => ! empty($settings['gtm_consent_mode']),
			'gtmConsent'      => $this->get_gtm_consent_payload(),
			'localStorageKey' => 'feuer-cookie:' . $this->get_cookie_name('consent') . ':record',
		);
	}

	/**
	 * Print the Consent Mode v2 default state before Google Tag Manager executes.
	 *
	 * The dataLayer call itself stores no cookie. All non-essential Google storage
	 * remains denied until a visitor has actively opted in to Google Analytics/GTM.
	 *
	 * @return void
	 */
	public function render_gtm_consent_defaults() {
		if (! $this->should_handle_frontend_request() || $this->is_robot_request()) {
			return;
		}

		if (empty($this->get_settings()['gtm_consent_mode'])) {
			return;
		}

		$payload = wp_json_encode($this->get_gtm_consent_payload());

		if (! $payload) {
			return;
		}

		echo '<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag("consent","default",' . $payload . ');</script>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Convert the saved granular selection into a GTM Consent Mode v2 payload.
	 *
	 * @return array<string,string|int>
	 */
	private function get_gtm_consent_payload() {
		$google_allowed = 'configured' === $this->get_consent_state()
			&& $this->has_vendor_consent('google_analytics');

		return array(
			'ad_storage'              => $google_allowed ? 'granted' : 'denied',
			'ad_user_data'            => $google_allowed ? 'granted' : 'denied',
			'ad_personalization'      => $google_allowed ? 'granted' : 'denied',
			'analytics_storage'       => $google_allowed ? 'granted' : 'denied',
			'functionality_storage'   => 'denied',
			'personalization_storage' => 'denied',
			'security_storage'        => 'denied',
			'wait_for_update'         => 500,
		);
	}

	/**
	 * Gibt den Header-Makro-Shortcode aus.
	 *
	 * @return string
	 */
	public function shortcode_header_macro() {
		return $this->get_template_macro_markup('header');
	}

	/**
	 * Gibt den Footer-Makro-Shortcode aus.
	 *
	 * @return string
	 */
	public function shortcode_footer_macro() {
		return $this->get_template_macro_markup('footer');
	}

	/**
	 * Add plugin action links.
	 *
	 * @param array<int,string> $links Existing links.
	 * @return array<int,string>
	 */
	public function add_action_links($links) {
		array_unshift(
			$links,
			sprintf(
				'<a href="%s">%s</a>',
				esc_url(admin_url('admin.php?page=feuer-cookie-settings')),
				esc_html__('Einstellungen', 'feuer-cookie')
			)
		);

		return $links;
	}

	/**
	 * Gibt lokales CSS für isolierte Consent-Seiten aus.
	 *
	 * @return void
	 */
	public function print_gate_head_assets() {
		printf(
			'<link rel="stylesheet" id="feuer-cookie-frontend-css" href="%s" media="all">' . "\n",
			esc_url(FEUER_COOKIE_URL . 'assets/css/frontend.css?ver=' . FEUER_COOKIE_VERSION)
		);
	}

	/**
	 * Gibt lokale JavaScript-Ressourcen für isolierte Consent-Seiten aus.
	 *
	 * @return void
	 */
	public function print_gate_footer_assets() {
		printf(
			"<script>window.FeuerCookieData = %s;</script>\n",
			wp_json_encode($this->get_frontend_config())
		);
		printf(
			'<script src="%s"></script>' . "\n",
			esc_url(FEUER_COOKIE_URL . 'assets/js/frontend.js?ver=' . FEUER_COOKIE_VERSION)
		);
	}

	/**
	 * Liefert Makro-Markup für Header oder Footer im Theme.
	 *
	 * @param string $position header oder footer.
	 * @return string
	 */
	public function get_template_macro_markup($position = 'footer') {
		$position = 'header' === $position ? 'header' : 'footer';
		$html     = "<!-- Feuer-Cookie {$position} Makro -->\n";

		if (! $this->should_handle_frontend_request() || is_user_logged_in() || $this->is_admin_bypass_active() || $this->is_robot_request()) {
			return $html;
		}

		if ('header' === $position) {
			if (! wp_style_is('feuer-cookie-frontend', 'done') && ! wp_style_is('feuer-cookie-frontend', 'enqueued')) {
				$html .= sprintf(
					'<link rel="stylesheet" id="feuer-cookie-frontend-css" href="%s" media="all">' . "\n",
					esc_url(FEUER_COOKIE_URL . 'assets/css/frontend.css?ver=' . FEUER_COOKIE_VERSION)
				);
			}

			return $html;
		}

		$html .= sprintf(
			"<script>window.FeuerCookieData = %s;</script>\n",
			wp_json_encode($this->get_frontend_config())
		);

		if (! wp_script_is('feuer-cookie-frontend', 'done') && ! wp_script_is('feuer-cookie-frontend', 'enqueued')) {
			$html .= sprintf(
				'<script src="%s"></script>' . "\n",
				esc_url(FEUER_COOKIE_URL . 'assets/js/frontend.js?ver=' . FEUER_COOKIE_VERSION)
			);
		}

		return $html;
	}

	/**
	 * Save settings from admin form.
	 *
	 * @return void
	 */
	public function handle_settings_save() {
		if (! current_user_can('manage_options')) {
			wp_die(esc_html__('Keine Berechtigung.', 'feuer-cookie'));
		}

		check_admin_referer('feuer_cookie_save_settings');

		$previous_settings = $this->get_settings();
		$settings          = $previous_settings;
		$settings_tab      = $this->sanitize_settings_tab(wp_unslash($_POST['settings_tab'] ?? 'general'));

		switch ($settings_tab) {
			case 'general':
				$settings['logo_url']         = esc_url_raw(wp_unslash($_POST['logo_url'] ?? ''));
				$settings['consent_revision'] = sanitize_text_field(wp_unslash($_POST['consent_revision'] ?? FEUER_COOKIE_VERSION));
				$settings['privacy_url']      = esc_url_raw(wp_unslash($_POST['privacy_url'] ?? ''));
				$settings['imprint_url']      = esc_url_raw(wp_unslash($_POST['imprint_url'] ?? ''));
				$settings['support_url']      = esc_url_raw(wp_unslash($_POST['support_url'] ?? ''));
				break;

			case 'banner':
				$settings['consent_template']    = $this->sanitize_template(wp_unslash($_POST['consent_template'] ?? 'feuerwand'));
				$settings['banner_layout']       = $this->sanitize_banner_layout(wp_unslash($_POST['banner_layout'] ?? 'bottom-right'));
				$settings['agreement_title']     = sanitize_text_field(wp_unslash($_POST['agreement_title'] ?? ''));
				$settings['agreement_text']      = wp_kses_post(wp_unslash($_POST['agreement_text'] ?? ''));
				$settings['blocked_title']       = sanitize_text_field(wp_unslash($_POST['blocked_title'] ?? ''));
				$settings['blocked_text']        = wp_kses_post(wp_unslash($_POST['blocked_text'] ?? ''));
				$settings['accept_label']        = sanitize_text_field(wp_unslash($_POST['accept_label'] ?? ''));
				$settings['decline_label']       = sanitize_text_field(wp_unslash($_POST['decline_label'] ?? ''));
				$settings['save_label']          = sanitize_text_field(wp_unslash($_POST['save_label'] ?? ''));
				$settings['settings_label']      = sanitize_text_field(wp_unslash($_POST['settings_label'] ?? ''));
				$settings['reopen_label']        = sanitize_text_field(wp_unslash($_POST['reopen_label'] ?? ''));
				$settings['panel_button_shape']  = $this->sanitize_panel_button_shape(wp_unslash($_POST['panel_button_shape'] ?? 'circle'));
				$settings['panel_button_color']  = $this->sanitize_panel_button_color(wp_unslash($_POST['panel_button_color'] ?? 'ocean'));
				$settings['panel_button_icon']   = $this->sanitize_panel_button_icon(wp_unslash($_POST['panel_button_icon'] ?? 'cookie'));
				$settings['panel_button_position'] = $this->sanitize_panel_button_position(wp_unslash($_POST['panel_button_position'] ?? 'bottom-right'));
				$settings['service_allow_label'] = sanitize_text_field(wp_unslash($_POST['service_allow_label'] ?? ''));
				$settings['delete_label']        = sanitize_text_field(wp_unslash($_POST['delete_label'] ?? ''));
				break;

			case 'privacy':
				$cookie_name                    = sanitize_key(wp_unslash($_POST['cookie_name'] ?? 'feuer_cookie_consent'));
				$settings['cookie_name']        = $cookie_name ? $cookie_name : 'feuer_cookie_consent';
				$settings['consent_event_retention_days'] = max(30, min(1825, (int) ($_POST['consent_event_retention_days'] ?? 365)));
				$settings['analytics_retention_days'] = max(30, min(365, (int) ($_POST['analytics_retention_days'] ?? 90)));
				$settings['consent_cookie_days'] = max(1, min(3650, (int) ($_POST['consent_cookie_days'] ?? 365)));
				$settings['overlay_opacity']    = $this->sanitize_overlay_opacity($_POST['overlay_opacity'] ?? 40);
				$settings['blocking_mode']      = $this->sanitize_blocking_mode(wp_unslash($_POST['blocking_mode'] ?? 'strict'));
				$settings['gtm_consent_mode']   = ! empty($_POST['gtm_consent_mode']) ? 1 : 0;
				$settings['feuer_analytics_sync'] = ! empty($_POST['feuer_analytics_sync']) ? 1 : 0;
				$settings['admin_bypass']       = ! empty($_POST['admin_bypass']) ? 1 : 0;
				break;
		}

		$settings['show_reopen_button']  = 1;
		$settings['demo_mode']           = ! empty($_POST['demo_mode']) ? 1 : 0;
		unset($settings['decline_title'], $settings['decline_text'], $settings['alternative_url'], $settings['daily_stats_retention_days'], $settings['retention_days'], $settings['store_full_ip']);

		update_option($this->settings_key, $settings);
		$this->maybe_append_revision_log($previous_settings, $settings);

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'               => 'feuer-cookie-settings',
					'tab'                => $settings_tab,
					'feuer_cookie_notice' => 'saved',
				),
				admin_url('admin.php')
			)
		);
		exit;
	}

	/**
	 * Manually trigger a remote update check for this plugin.
	 *
	 * @return void
	 */
	public function handle_check_updates() {
		if (! current_user_can('manage_options')) {
			wp_die(esc_html__('Keine Berechtigung.', 'feuer-cookie'));
		}

		check_admin_referer('feuer_cookie_check_updates');

		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/update.php';

		$settings_tab = $this->sanitize_settings_tab(wp_unslash($_POST['settings_tab'] ?? 'general'));
		$plugin_file  = plugin_basename(FEUER_COOKIE_FILE);
		$notice       = 'update_check_failed';

		delete_site_transient(FEUER_COOKIE_UPDATE_CACHE_KEY);
		delete_site_transient('update_plugins');
		wp_clean_plugins_cache(true);
		wp_update_plugins();

		$updates = get_site_transient('update_plugins');

		if (is_object($updates)) {
			if (! empty($updates->response[ $plugin_file ])) {
				$notice = 'update_available';
			} elseif (! empty($updates->no_update[ $plugin_file ])) {
				$notice = 'update_current';
			}
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'                => 'feuer-cookie-settings',
					'tab'                 => $settings_tab,
					'feuer_cookie_notice' => $notice,
				),
				admin_url('admin.php')
			)
		);
		exit;
	}

	/**
	 * Add an IP block entry.
	 *
	 * @return void
	 */
	public function handle_add_block() {
		global $wpdb;

		if (! current_user_can('manage_options')) {
			wp_die(esc_html__('Keine Berechtigung.', 'feuer-cookie'));
		}

		check_admin_referer('feuer_cookie_add_block');

		$pattern = sanitize_text_field(wp_unslash($_POST['ip_pattern'] ?? ''));
		$reason  = sanitize_textarea_field(wp_unslash($_POST['reason'] ?? ''));

		if ($pattern) {
			$wpdb->insert(
				$this->tables['ip_blocks'],
				array(
					'ip_pattern' => $pattern,
					'reason'     => $reason,
					'is_active'  => 1,
					'created_by' => get_current_user_id(),
					'created_at' => current_time('mysql'),
				),
				array('%s', '%s', '%d', '%d', '%s')
			);
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'               => 'feuer-cookie-blocklist',
					'feuer_cookie_notice' => 'blocked_added',
				),
				admin_url('admin.php')
			)
		);
		exit;
	}

	/**
	 * Delete an IP block entry.
	 *
	 * @return void
	 */
	public function handle_delete_block() {
		global $wpdb;

		if (! current_user_can('manage_options')) {
			wp_die(esc_html__('Keine Berechtigung.', 'feuer-cookie'));
		}

		check_admin_referer('feuer_cookie_delete_block');

		$id = (int) ($_POST['block_id'] ?? 0);

		if ($id > 0) {
			$wpdb->delete($this->tables['ip_blocks'], array('id' => $id), array('%d'));
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'               => 'feuer-cookie-blocklist',
					'feuer_cookie_notice' => 'blocked_deleted',
				),
				admin_url('admin.php')
			)
		);
		exit;
	}

	/**
	 * Run diagnostics and save report.
	 *
	 * @return void
	 */
	public function handle_run_diagnostics() {
		if (! current_user_can('manage_options')) {
			wp_die(esc_html__('Keine Berechtigung.', 'feuer-cookie'));
		}

		check_admin_referer('feuer_cookie_run_diagnostics');

		$report = $this->diagnostics->scan();
		update_option($this->diagnostics_key, $report);

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'               => 'feuer-cookie-diagnostics',
					'feuer_cookie_notice' => 'diagnostics_run',
				),
				admin_url('admin.php')
			)
		);
		exit;
	}

	/**
	 * Run local SEO audit and persist the report.
	 *
	 * @return void
	 */
	public function handle_run_seo_audit() {
		if (! current_user_can('manage_options')) {
			wp_die(esc_html__('Keine Berechtigung.', 'feuer-cookie'));
		}

		check_admin_referer('feuer_cookie_run_seo_audit');

		$report = $this->seo_audit->scan($this->table_name('events'));
		update_option($this->seo_audit_key, $report);

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'               => 'feuer-cookie-seo-audit',
					'feuer_cookie_notice' => 'seo_audit_run',
				),
				admin_url('admin.php')
			)
		);
		exit;
	}

	/**
	 * Export filtered visitor events as CSV.
	 *
	 * @return void
	 */
	public function handle_export_events() {
		if (! current_user_can('manage_options')) {
			wp_die(esc_html__('Keine Berechtigung.', 'feuer-cookie'));
		}

		check_admin_referer('feuer_cookie_export_events');

		$filters = $this->get_visitor_filters_from_request();
		$events  = $this->get_filtered_event_rows($filters, 5000);
		$format  = 'json' === sanitize_key(wp_unslash($_GET['format'] ?? 'csv')) ? 'json' : 'csv';
		$filename = 'feuer-cookie-events-' . wp_date('Y-m-d-His') . '.' . $format;

		if ('json' === $format) {
			nocache_headers();
			header('Content-Type: application/json; charset=utf-8');
			header('Content-Disposition: attachment; filename="' . $filename . '"');
			echo wp_json_encode(
				array(
					'generated_at' => current_time('mysql'),
					'filters'      => $filters,
					'events'       => $events,
				),
				JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
			);
			exit;
		}

		nocache_headers();
		header('Content-Type: text/csv; charset=utf-8');
		header('Content-Disposition: attachment; filename="' . $filename . '"');

		$output = fopen('php://output', 'w');
		fputcsv($output, array('created_at', 'request_type', 'consent_state', 'request_path', 'page_title', 'referrer_path', 'ip_address', 'device_type', 'browser_name', 'os_name', 'is_robot', 'meta'));

		foreach ($events as $event) {
			fputcsv(
				$output,
				array(
					(string) ($event['created_at'] ?? ''),
					(string) ($event['request_type'] ?? ''),
					(string) ($event['consent_state'] ?? ''),
					(string) ($event['request_path'] ?? ''),
					(string) ($event['page_title'] ?? ''),
					(string) ($event['referrer_path'] ?? ''),
					(string) ($event['ip_address'] ?? ''),
					(string) ($event['device_type'] ?? ''),
					(string) ($event['browser_name'] ?? ''),
					(string) ($event['os_name'] ?? ''),
					! empty($event['is_robot']) ? '1' : '0',
					$this->format_event_meta_summary($event),
				)
			);
		}

		fclose($output);
		exit;
	}

	/**
	 * Export consent-based analytics as grouped CSV rows without visitor IDs.
	 *
	 * @return void
	 */
	public function handle_analytics_export() {
		global $wpdb;

		if (! current_user_can('manage_options')) {
			wp_die(esc_html__('Keine Berechtigung.', 'feuer-cookie'));
		}

		check_admin_referer('feuer_cookie_export_analytics');
		$days = isset($_GET['days']) ? absint(wp_unslash($_GET['days'])) : 30;
		$days = in_array($days, array(7, 30, 90), true) ? $days : 30;
		$now = current_time('timestamp');
		$since = wp_date('Y-m-d H:i:s', $now - ($days * DAY_IN_SECONDS));
		$table = $this->table_sql('events');
		$sections = array(
			'Tagesverlauf' => "SELECT DATE(created_at) AS label, COUNT(*) AS views, COUNT(DISTINCT session_token) AS sessions FROM {$table} WHERE request_type = 'page_view' AND created_at >= %s GROUP BY DATE(created_at) ORDER BY label ASC",
			'Seiten' => "SELECT request_path AS label, COUNT(*) AS views, COUNT(DISTINCT session_token) AS sessions FROM {$table} WHERE request_type = 'page_view' AND created_at >= %s GROUP BY request_path ORDER BY views DESC LIMIT 100",
			'Herkunftsdomains' => "SELECT referrer_path AS label, COUNT(*) AS views, COUNT(DISTINCT session_token) AS sessions FROM {$table} WHERE request_type = 'page_view' AND referrer_path <> '' AND created_at >= %s GROUP BY referrer_path ORDER BY sessions DESC LIMIT 100",
			'Geräte' => "SELECT device_type AS label, COUNT(*) AS views, COUNT(DISTINCT session_token) AS sessions FROM {$table} WHERE request_type = 'page_view' AND device_type <> '' AND created_at >= %s GROUP BY device_type ORDER BY sessions DESC",
			'Browser' => "SELECT browser_name AS label, COUNT(*) AS views, COUNT(DISTINCT session_token) AS sessions FROM {$table} WHERE request_type = 'page_view' AND browser_name <> '' AND created_at >= %s GROUP BY browser_name ORDER BY sessions DESC",
			'Betriebssysteme' => "SELECT os_name AS label, COUNT(*) AS views, COUNT(DISTINCT session_token) AS sessions FROM {$table} WHERE request_type = 'page_view' AND os_name <> '' AND created_at >= %s GROUP BY os_name ORDER BY sessions DESC",
		);

		nocache_headers();
		header('Content-Type: text/csv; charset=utf-8');
		header('Content-Disposition: attachment; filename="feuer-cookie-statistik-' . $days . '-tage-' . wp_date('Y-m-d') . '.csv"');
		$output = fopen('php://output', 'w');
		fwrite($output, "\xEF\xBB\xBF");
		fputcsv($output, array('Bereich', 'Eintrag', 'Seitenaufrufe', 'Besucher-Sitzungen'), ';');

		foreach ($sections as $section => $sql) {
			$rows = $wpdb->get_results($wpdb->prepare($sql, $since), ARRAY_A);

			foreach ((array) $rows as $row) {
				$label = (string) ($row['label'] ?? '');

				if (preg_match('/^[=+\-@\t\r]/', $label)) {
					$label = "'" . $label;
				}

				fputcsv($output, array($section, $label, (int) ($row['views'] ?? 0), (int) ($row['sessions'] ?? 0)), ';');
			}
		}

		fclose($output);
		exit;
	}

	/**
	 * Clean old raw logs.
	 *
	 * @return void
	 */
	public function cleanup_old_logs() {
		global $wpdb;

		$settings       = $this->get_settings();
		$consent_days   = max(30, (int) ($settings['consent_event_retention_days'] ?? 365));
		$consent_cutoff = gmdate('Y-m-d H:i:s', time() - (DAY_IN_SECONDS * $consent_days));
		$analytics_days = max(30, min(365, (int) ($settings['analytics_retention_days'] ?? 90)));
		$analytics_cutoff = gmdate('Y-m-d H:i:s', time() - (DAY_IN_SECONDS * $analytics_days));
		$events_table   = $this->table_sql('events');
		$daily_stats_table = $this->table_sql('daily_stats');

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$events_table}
				WHERE created_at < %s
					AND request_type IN ('consent_accept_all', 'consent_custom', 'consent_necessary', 'consent_service_allow', 'consent_deleted', 'blocked_ip')",
				$consent_cutoff
			)
		);

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$events_table} WHERE request_type IN ('page_view', 'page_engagement') AND created_at < %s",
				$analytics_cutoff
			)
		);

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$daily_stats_table} WHERE day < %s",
				wp_date('Y-m-d', time() - (DAY_IN_SECONDS * $analytics_days))
			)
		);

	}

	/**
	 * Apply the consent gate and block handling.
	 *
	 * @return void
	 */
	public function handle_frontend_gate() {
		if (! $this->should_handle_frontend_request()) {
			return;
		}

		if ($this->is_admin_bypass_active()) {
			return;
		}

		if ($this->is_robot_request()) {
			return;
		}

		$ip = $this->get_client_ip();

		if ($this->is_ip_blocked($ip)) {
			$this->log_event('blocked_ip', 'blocked', array('blocked' => true));
			$this->render_gate_template('blocked');
		}

	}

	/**
	 * Store a minimal page view only after explicit statistics consent.
	 * No IP address, full URL, query string or raw user agent is retained.
	 *
	 * @return void
	 */
	public function maybe_track_analytics_page_view() {
		if (! $this->should_handle_frontend_request() || $this->is_admin_bypass_active() || $this->is_robot_request()) {
			return;
		}

		$cookie_name = 'feuer_cookie_analytics_session';

		if ('configured' !== $this->get_consent_state() || ! $this->has_category_consent('statistics') || $this->is_demo_mode_active()) {
			if (isset($_COOKIE[ $cookie_name ])) {
				setcookie($cookie_name, '', array('expires' => time() - HOUR_IN_SECONDS, 'path' => COOKIEPATH ? COOKIEPATH : '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax'));
				unset($_COOKIE[ $cookie_name ]);
			}

			return;
		}

		$path = substr(sanitize_text_field($this->current_path()), 0, 191);

		if (! $path) {
			return;
		}

		$visitor_token = sanitize_text_field(wp_unslash($_COOKIE[ $cookie_name ] ?? ''));

		if (! preg_match('/^[a-f0-9]{64}$/', $visitor_token)) {
			$visitor_token = bin2hex(random_bytes(32));
		}

		setcookie(
			$cookie_name,
			$visitor_token,
			array('expires' => time() + (30 * MINUTE_IN_SECONDS), 'path' => COOKIEPATH ? COOKIEPATH : '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax')
		);
		$_COOKIE[ $cookie_name ] = $visitor_token;

		$user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '';
		$profile    = $this->parse_client_profile($user_agent);
		$referrer   = isset($_SERVER['HTTP_REFERER']) ? esc_url_raw(wp_unslash($_SERVER['HTTP_REFERER'])) : '';
		$ref_host   = strtolower((string) wp_parse_url($referrer, PHP_URL_HOST));
		$site_host  = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
		$ref_host   = preg_replace('/^www\./', '', $ref_host);
		$site_host  = preg_replace('/^www\./', '', $site_host);
		$source     = $ref_host && $ref_host !== $site_host ? substr(sanitize_text_field($ref_host), 0, 191) : '';
		$visitor_id = hash_hmac('sha256', $visitor_token, wp_salt('auth'));

		global $wpdb;
		$inserted = $wpdb->insert(
			$this->tables['events'],
			array(
				'session_token' => $visitor_id,
				'consent_state' => 'configured',
				'request_type'  => 'page_view',
				'request_url'   => '',
				'request_path'  => $path,
				'referrer_url'  => '',
				'referrer_path' => $source,
				'page_title'    => '',
				'ip_address'    => '',
				'ip_hash'       => '',
				'user_agent'    => '',
				'browser_lang'  => '',
				'country_code'  => '',
				'country_name'  => '',
				'city'          => '',
				'device_type'   => (string) $profile['device_type'],
				'browser_name'  => (string) $profile['browser_name'],
				'os_name'       => (string) $profile['os_name'],
				'is_robot'      => 0,
				'meta_json'     => '',
				'created_at'    => current_time('mysql'),
			),
			array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s')
		);

		if (false !== $inserted) {
			$this->increment_daily_stats('page_view', $visitor_id);
		}
	}

	/**
	 * Render the consent banner on frontend pages until a valid decision exists.
	 *
	 * @return void
	 */
	public function render_consent_banner() {
		$settings    = $this->get_settings();
		$preferences = $this->get_saved_preferences();
		$layout      = $this->sanitize_banner_layout((string) ($settings['banner_layout'] ?? 'bottom-right'));
		$style       = '--feuer-cookie-overlay-opacity: ' . $this->get_overlay_opacity_css_value((int) ($settings['overlay_opacity'] ?? 40)) . ';';

		if (! $this->should_handle_frontend_request() || $this->is_admin_bypass_active()) {
			return;
		}

		if ($this->is_robot_request()) {
			return;
		}

		if ('configured' === $this->get_consent_state()) {
			return;
		}

		?>
		<div class="feuer-cookie-backdrop feuer-cookie-backdrop--<?php echo esc_attr($layout); ?>" style="<?php echo esc_attr($style); ?>" aria-hidden="true"></div>
		<div class="feuer-cookie-banner feuer-cookie-banner--<?php echo esc_attr($layout); ?>" style="<?php echo esc_attr($style); ?>" data-feuer-cookie-banner>
			<div class="feuer-cookie-banner__surface card border-0 shadow-lg" role="dialog" aria-modal="true" aria-labelledby="feuer-cookie-banner-title">
				<div class="card-body p-3 p-md-4">
					<div class="feuer-cookie-banner__preset-icon" aria-hidden="true">
						<?php echo $this->get_panel_button_icon_svg('cookie'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</div>
					<div class="feuer-cookie-banner__intro">
						<div class="d-flex flex-wrap align-items-center gap-2">
							<span class="feuer-cookie-banner__eyebrow badge rounded-pill">Ihre Datenschutzauswahl</span>
						</div>
						<h2 id="feuer-cookie-banner-title" class="feuer-cookie-banner__title h5 mb-0"><?php echo esc_html($settings['agreement_title']); ?></h2>
						<div class="feuer-cookie-banner__summary">
							<p><?php echo esc_html($this->get_banner_summary_text()); ?></p>
						</div>
						<details class="feuer-cookie-banner__details">
							<summary>Mehr Informationen</summary>
							<div class="feuer-cookie-banner__text feuer-cookie-richtext small">
							<?php echo wp_kses_post($settings['agreement_text']); ?>
							</div>
						</details>
						<div class="feuer-cookie-banner__links small">
						<?php if (! empty($settings['privacy_url'])) : ?>
							<a href="<?php echo esc_url($settings['privacy_url']); ?>">Datenschutzerklärung</a>
						<?php endif; ?>
						<?php if (! empty($settings['imprint_url'])) : ?>
							<a href="<?php echo esc_url($settings['imprint_url']); ?>">Impressum</a>
						<?php endif; ?>
						</div>
						<?php if ($this->is_demo_mode_active()) : ?>
							<div class="feuer-cookie-banner__demo-note">
								<?php echo esc_html__('Demo-Modus aktiv: Das Banner wird bei jedem Seitenaufruf angezeigt und Entscheidungen werden nicht gespeichert.', 'feuer-cookie'); ?>
							</div>
						<?php endif; ?>
					</div>

					<div class="feuer-cookie-banner__actions d-flex flex-wrap gap-2 mt-3">
						<button type="button" class="feuer-cookie-button feuer-cookie-button--secondary btn btn-outline-secondary btn-sm" data-feuer-cookie-toggle-settings aria-expanded="false">
							<?php echo esc_html($settings['settings_label']); ?>
						</button>
						<button type="button" class="feuer-cookie-button feuer-cookie-button--secondary btn btn-outline-secondary btn-sm" data-feuer-cookie-decision="necessary">
							<?php echo esc_html($settings['decline_label']); ?>
						</button>
						<button type="button" class="feuer-cookie-button feuer-cookie-button--primary btn btn-primary btn-sm" data-feuer-cookie-decision="accept_all">
							<?php echo esc_html($settings['accept_label']); ?>
						</button>
					</div>

					<div class="feuer-cookie-banner__settings mt-3 pt-3 border-top" data-feuer-cookie-banner-settings hidden>
						<?php echo $this->get_consent_matrix_markup($preferences, 'banner'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</div>
				</div>
			</div>
		</div>
		<?php
	}


	/**
	 * Render the floating reopen button.
	 *
	 * @return void
	 */
	public function render_reopen_button() {
		echo $this->get_reopen_button_markup(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Liefert das HTML für den schwebenden Widerruf-Button.
	 *
	 * @return string
	 */
	private function get_reopen_button_markup() {
		$settings      = $this->get_settings();
		$preferences   = $this->get_saved_preferences();
		$button_shape  = $this->sanitize_panel_button_shape((string) ($settings['panel_button_shape'] ?? 'circle'));
		$button_color  = $this->sanitize_panel_button_color((string) ($settings['panel_button_color'] ?? 'ocean'));
		$button_icon   = $this->sanitize_panel_button_icon((string) ($settings['panel_button_icon'] ?? 'cookie'));
		$button_position = $this->sanitize_panel_button_position((string) ($settings['panel_button_position'] ?? 'bottom-right'));
		$panel_id      = function_exists('wp_unique_id') ? wp_unique_id('feuer-cookie-reopen-panel-') : 'feuer-cookie-reopen-panel';
		$panel_title_id = $panel_id . '-title';

		if (! $this->should_handle_frontend_request() || $this->is_admin_bypass_active()) {
			return '';
		}

		if ($this->is_robot_request()) {
			return '';
		}

		if ('configured' !== $this->get_consent_state()) {
			return '';
		}

		ob_start();
		?>
		<div class="feuer-cookie-reopen feuer-cookie-reopen--<?php echo esc_attr($button_position); ?>" data-feuer-cookie-panel data-feuer-cookie-panel-shape="<?php echo esc_attr($button_shape); ?>" data-feuer-cookie-panel-color="<?php echo esc_attr($button_color); ?>" data-feuer-cookie-panel-icon="<?php echo esc_attr($button_icon); ?>">
			<button
				type="button"
				class="feuer-cookie-reopen__button feuer-cookie-reopen__button--<?php echo esc_attr($button_shape); ?> btn btn-primary btn-sm"
				data-feuer-cookie-open
				aria-label="<?php echo esc_attr($settings['reopen_label']); ?>"
				aria-controls="<?php echo esc_attr($panel_id); ?>"
				aria-expanded="false"
				title="<?php echo esc_attr($settings['reopen_label']); ?>"
			>
				<span class="feuer-cookie-sr-only"><?php echo esc_html($settings['reopen_label']); ?></span>
				<?php echo $this->get_panel_button_icon_svg($button_icon); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</button>
			<div id="<?php echo esc_attr($panel_id); ?>" class="feuer-cookie-reopen__panel card border-0 shadow-lg" role="dialog" aria-modal="true" aria-labelledby="<?php echo esc_attr($panel_title_id); ?>" hidden>
				<div class="feuer-cookie-reopen__header">
					<h2 id="<?php echo esc_attr($panel_title_id); ?>" class="feuer-cookie-reopen__title"><?php echo esc_html__('Cookie-Einstellungen', 'feuer-cookie'); ?></h2>
					<button type="button" class="feuer-cookie-reopen__close" data-feuer-cookie-close aria-label="<?php echo esc_attr__('Einstellungen schließen', 'feuer-cookie'); ?>">
						<span aria-hidden="true">&times;</span>
					</button>
				</div>
				<p class="feuer-cookie-reopen__description"><?php echo esc_html__('Sie können Ihre Auswahl jederzeit ändern.', 'feuer-cookie'); ?></p>
				<?php echo $this->get_consent_matrix_markup($preferences, 'panel'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</div>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Return the SVG markup for one floating panel icon preset.
	 *
	 * @param string $icon Icon slug.
	 * @return string
	 */
	private function get_panel_button_icon_svg($icon) {
		switch ($icon) {
			case 'shield':
				return '<svg class="feuer-cookie-reopen__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 2.5 4.5 5.4v5.4c0 4.6 2.8 8.8 7.5 10.7 4.7-1.9 7.5-6.1 7.5-10.7V5.4L12 2.5Zm0 2.4 5 1.9v3.9c0 3.5-2 6.6-5 8.2-3-1.6-5-4.7-5-8.2V6.8l5-1.9Zm-.9 3.4v3H8.4v2.2h2.7v3h2.1v-3H16v-2.2h-2.8v-3h-2.1Z" fill="currentColor"/></svg>';
			case 'gear':
				return '<svg class="feuer-cookie-reopen__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="m10.8 2.7-.6 2.3c-.5.1-1 .3-1.4.6L6.7 4.3 4.3 6.7l1.3 2.1c-.3.5-.5.9-.6 1.4l-2.3.6v3.4l2.3.6c.1.5.3 1 .6 1.4l-1.3 2.1 2.4 2.4 2.1-1.3c.4.3.9.5 1.4.6l.6 2.3h3.4l.6-2.3c.5-.1 1-.3 1.4-.6l2.1 1.3 2.4-2.4-1.3-2.1c.3-.4.5-.9.6-1.4l2.3-.6v-3.4l-2.3-.6c-.1-.5-.3-.9-.6-1.4l1.3-2.1-2.4-2.4-2.1 1.3c-.4-.3-.9-.5-1.4-.6l-.6-2.3h-3.4Zm1.2 5.2a4.1 4.1 0 1 1 0 8.2 4.1 4.1 0 0 1 0-8.2Zm0 2.2a1.9 1.9 0 1 0 0 3.8 1.9 1.9 0 0 0 0-3.8Z" fill="currentColor"/></svg>';
			case 'lock':
				return '<svg class="feuer-cookie-reopen__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M7.5 10V8.3a4.5 4.5 0 1 1 9 0V10h.8c1.2 0 2.2 1 2.2 2.2v7.1c0 1.2-1 2.2-2.2 2.2H6.7c-1.2 0-2.2-1-2.2-2.2v-7.1C4.5 11 5.5 10 6.7 10h.8Zm2.2 0h4.6V8.3a2.3 2.3 0 1 0-4.6 0V10Zm2.3 3a1.9 1.9 0 0 0-1.1 3.4v2.1h2.2v-2.1A1.9 1.9 0 0 0 12 13Z" fill="currentColor"/></svg>';
			case 'chart':
				return '<svg class="feuer-cookie-reopen__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M4 19.5h16v1.8H2.5V4H4v15.5Zm3.1-3.2 3.7-4.2 3.1 2.5 4.8-6 .3 3 1.9-.2-.7-5.9-5.7.8.3 1.8 2.4-.3-3.8 4.8-3.1-2.5-5 5.6 1.8 1.6Z" fill="currentColor"/></svg>';
			case 'cookie':
			default:
				return '<svg class="feuer-cookie-reopen__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 2.5c-2.4 0-4.3 1-5.5 2.8-1.8.1-3.2 1.6-3.2 3.4 0 1 .4 1.9 1 2.5-.6.9-.9 1.9-.9 3 0 1.8.9 3.4 2.4 4.3.4 1.8 2 3 3.9 3 .9 0 1.8-.3 2.5-.8.7.5 1.5.8 2.5.8 1.9 0 3.5-1.2 3.9-3 1.4-.9 2.4-2.5 2.4-4.3 0-1.1-.3-2.1-.9-3 .7-.6 1-1.5 1-2.5 0-1.8-1.4-3.3-3.2-3.4C16.3 3.5 14.4 2.5 12 2.5Zm0 2.3c1.5 0 2.6.6 3.3 1.8l.6.9 1.1-.1h.1c.8 0 1.4.6 1.4 1.4 0 .5-.3 1-.7 1.2l-1.3.8.9 1.2c.4.5.6 1.2.6 1.8 0 1-.6 2-1.6 2.5l-.8.4-.1.9c-.1.9-.8 1.5-1.7 1.5-.6 0-1.1-.2-1.5-.7L12 18l-.9.8c-.4.4-.9.7-1.5.7-.9 0-1.6-.6-1.7-1.5l-.1-.9-.8-.4c-1-.6-1.6-1.5-1.6-2.5 0-.7.2-1.3.6-1.8l.9-1.2-1.3-.8c-.5-.3-.7-.7-.7-1.2 0-.8.6-1.4 1.4-1.4h.1l1.1.1.6-.9c.7-1.2 1.8-1.8 3.3-1.8Zm-2 5a1.5 1.5 0 1 0 0 3 1.5 1.5 0 0 0 0-3Zm4 0a1.5 1.5 0 1 0 0 3 1.5 1.5 0 0 0 0-3Zm-2 4c-1.1 0-2 .9-2 2h4c0-1.1-.9-2-2-2Z" fill="currentColor"/></svg>';
		}
	}

	/**
	 * Baut die granulare Consent-Matrix für Gate und Widerruf-Panel.
	 *
	 * @param array<string,mixed> $preferences Aktuelle Präferenzen.
	 * @param string              $context     banner oder panel.
	 * @return string
	 */
	public function get_consent_matrix_markup($preferences = array(), $context = 'panel') {
		$settings       = $this->get_settings();
		$preferences    = $this->sanitize_preferences($preferences);
		$valid_contexts = array('banner', 'panel');
		$context        = in_array($context, $valid_contexts, true) ? $context : 'panel';
		$categories     = $this->get_category_definitions();
		$services       = $this->get_visible_vendor_definitions();
		$category_map   = array();
		$category_text  = 'panel' === $context
			? 'Sie können Ihre Auswahl jederzeit ändern. Bereits freigegebene Dienste lassen sich hier wieder deaktivieren.'
			: 'Treffen Sie Ihre Auswahl granular nach Kategorie und Dienst. Alle nicht erforderlichen Dienste bleiben standardmäßig deaktiviert.';

		foreach ($services as $slug => $service) {
			$category = (string) ($service['category'] ?? 'external_media');

			if (! isset($category_map[ $category ])) {
				$category_map[ $category ] = array();
			}

			$category_map[ $category ][ $slug ] = $service;
		}

		ob_start();
		?>
		<div class="feuer-cookie-consent feuer-cookie-consent--<?php echo esc_attr($context); ?>" data-feuer-cookie-consent>
			<?php if ('banner' !== $context) : ?>
				<div class="feuer-cookie-consent__intro">
					<p><?php echo esc_html($category_text); ?></p>
					<p>Technisch erforderliche Verarbeitungen bleiben aktiv, damit Ihre Auswahl gespeichert, die Website sicher ausgeliefert und missbräuchliche Zugriffe abgewehrt werden können.</p>
				</div>
			<?php endif; ?>

			<div class="feuer-cookie-consent__groups">
				<?php foreach ($categories as $category_slug => $category) : ?>
					<?php
					$category_services = $category_map[ $category_slug ] ?? array();
					$is_required       = ! empty($category['required']);
					$is_checked        = ! empty($preferences['categories'][ $category_slug ]);
					$details_id        = 'feuer-cookie-category-' . sanitize_html_class($context . '-' . $category_slug);
					$service_count     = count($category_services);

					if (! $is_required && empty($category_services)) {
						continue;
					}
					?>
					<section class="feuer-cookie-consent__group" data-feuer-cookie-category-block="<?php echo esc_attr($category_slug); ?>">
						<div class="feuer-cookie-consent__group-head">
							<label class="feuer-cookie-consent__toggle">
								<input
									type="checkbox"
									data-feuer-cookie-category="<?php echo esc_attr($category_slug); ?>"
									<?php checked($is_checked); ?>
									<?php disabled($is_required); ?>
								>
								<span class="feuer-cookie-consent__toggle-copy">
									<strong><?php echo esc_html($category['label']); ?></strong>
									<span><?php echo esc_html($category['description']); ?></span>
								</span>
							</label>
							<div class="feuer-cookie-consent__group-actions">
								<span class="feuer-cookie-consent__badge<?php echo $is_required ? ' is-required' : ''; ?>">
									<?php echo $is_required ? esc_html__('Immer aktiv', 'feuer-cookie') : esc_html__('Optional', 'feuer-cookie'); ?>
								</span>
								<?php if ($service_count > 0) : ?>
									<button
										type="button"
										class="feuer-cookie-consent__group-toggle btn btn-outline-secondary btn-sm"
										data-feuer-cookie-category-toggle="<?php echo esc_attr($category_slug); ?>"
										data-feuer-cookie-label-closed="<?php echo esc_attr(sprintf(__('Dienste anzeigen (%d)', 'feuer-cookie'), $service_count)); ?>"
										data-feuer-cookie-label-open="<?php echo esc_attr__('Dienste ausblenden', 'feuer-cookie'); ?>"
										aria-expanded="false"
										aria-controls="<?php echo esc_attr($details_id); ?>"
									>
										<?php echo esc_html(sprintf(__('Dienste anzeigen (%d)', 'feuer-cookie'), $service_count)); ?>
									</button>
								<?php endif; ?>
							</div>
						</div>

						<?php if ($category_services) : ?>
							<div
								id="<?php echo esc_attr($details_id); ?>"
								class="feuer-cookie-consent__group-details"
								data-feuer-cookie-category-details="<?php echo esc_attr($category_slug); ?>"
								hidden
							>
								<div class="feuer-cookie-consent__vendors">
								<?php foreach ($category_services as $service_slug => $service) : ?>
									<?php
									$service_checked  = ! empty($preferences['vendors'][ $service_slug ]);
									$service_required = ! empty($service['required']);
									?>
									<label class="feuer-cookie-consent__vendor">
										<div class="feuer-cookie-consent__vendor-head">
											<input
												type="checkbox"
												data-feuer-cookie-vendor="<?php echo esc_attr($service_slug); ?>"
												data-feuer-cookie-category-parent="<?php echo esc_attr($category_slug); ?>"
												<?php checked($service_checked); ?>
												<?php disabled($service_required); ?>
											>
											<span class="feuer-cookie-consent__vendor-title-row">
												<span class="feuer-cookie-consent__vendor-title"><?php echo esc_html($service['label']); ?></span>
												<?php if (! empty($service['provider'])) : ?>
													<span class="feuer-cookie-consent__vendor-meta"><?php echo esc_html((string) $service['provider']); ?></span>
												<?php endif; ?>
											</span>
										</div>
									</label>
								<?php endforeach; ?>
								</div>
							</div>
						<?php endif; ?>
					</section>
				<?php endforeach; ?>
			</div>

			<?php if ('banner' !== $context) : ?>
				<div class="feuer-cookie-consent__legal">
					<p><strong>Ihre Kontrolle:</strong> Karten, externe Medien und Marketing werden nur fuer die von Ihnen ausgewaehlten Dienste freigeschaltet.</p>
					<p><strong>Widerruf:</strong> Ihre Auswahl kann jederzeit ueber das Cookie-Symbol angepasst oder widerrufen werden.</p>
				</div>
			<?php endif; ?>

			<div class="feuer-cookie-actions feuer-cookie-actions--consent">
				<?php if ('banner' !== $context) : ?>
					<button type="button" class="feuer-cookie-button feuer-cookie-button--secondary" data-feuer-cookie-decision="necessary"><?php echo esc_html($settings['decline_label']); ?></button>
				<?php endif; ?>
				<button type="button" class="feuer-cookie-button feuer-cookie-button--secondary" data-feuer-cookie-decision="save_selection"><?php echo esc_html($settings['save_label']); ?></button>
				<?php if ('panel' === $context) : ?>
					<button type="button" class="feuer-cookie-button feuer-cookie-button--secondary" data-feuer-cookie-decision="delete_consent"><?php echo esc_html($settings['delete_label']); ?></button>
				<?php endif; ?>
				<?php if ('banner' !== $context) : ?>
					<button type="button" class="feuer-cookie-button feuer-cookie-button--primary" data-feuer-cookie-decision="accept_all"><?php echo esc_html($settings['accept_label']); ?></button>
				<?php endif; ?>
			</div>

		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Verarbeitet granulare Consent-Entscheidungen per AJAX.
	 *
	 * @return void
	 */
	public function handle_consent_ajax() {
		check_ajax_referer('feuer_cookie_consent', 'nonce');

		$decision    = sanitize_key(wp_unslash($_POST['decision'] ?? ''));
		$current_url = esc_url_raw(wp_unslash($_POST['current_url'] ?? home_url('/')));
		$raw_payload = wp_unslash($_POST['preferences'] ?? '');
		$service_id  = sanitize_key(wp_unslash($_POST['service'] ?? ''));
		$preferences = $this->sanitize_preferences($this->decode_preferences_payload($raw_payload));

		if (! in_array($decision, array('necessary', 'accept_all', 'save_selection', 'allow_service', 'delete_consent'), true)) {
			wp_send_json_error(array('message' => 'Ungültige Entscheidung.'), 400);
		}

		$privacy_acknowledged = 'delete_consent' !== $decision;

		if ('delete_consent' === $decision) {
			$this->log_event('consent_deleted', 'unknown', array('deleted_at' => current_time('mysql')));
			$this->clear_consent_storage(true);

			wp_send_json_success(
				array(
					'redirect'           => $this->sanitize_redirect_url($current_url, home_url('/')),
					'status'             => 'unknown',
					'preferences'        => $this->get_default_preferences(),
					'consentRecord'      => array(),
					'gtmConsent'         => $this->get_gtm_consent_payload(),
					'clearClientStorage' => true,
				)
			);
		}

		if ('necessary' === $decision) {
			$preferences = $this->get_necessary_only_preferences();
			$event_type  = 'consent_necessary';
		} elseif ('accept_all' === $decision) {
			$preferences = $this->get_accept_all_preferences();
			$event_type  = 'consent_accept_all';
		} elseif ('allow_service' === $decision) {
			$services = $this->get_visible_vendor_definitions();

			if (! $service_id || ! isset($services[ $service_id ])) {
				wp_send_json_error(array('message' => 'Unbekannter Dienst.'), 400);
			}

			$preferences = $this->grant_service_consent(
				'configured' === $this->get_consent_state() ? $this->get_saved_preferences() : $this->get_necessary_only_preferences(),
				$service_id
			);
			$event_type = 'consent_service_allow';
		} else {
			$event_type = 'consent_custom';
		}

		$record = $this->build_consent_record($preferences, $decision, $service_id, $privacy_acknowledged);

		if ('consent_custom' === $event_type && ! $this->has_optional_consent_in_preferences($preferences)) {
			$event_type = 'consent_necessary';
		}

		if ($this->is_demo_mode_active()) {
			$this->clear_consent_storage(true);

			wp_send_json_success(
				array(
					'redirect'           => $this->sanitize_redirect_url($current_url, home_url('/')),
					'status'             => 'demo',
					'preferences'        => $preferences,
					'consentRecord'      => array(),
					'gtmConsent'         => $this->get_gtm_consent_payload(),
					'demoMode'           => true,
					'clearClientStorage' => true,
				)
			);
		}

		$this->store_consent_record($record);
		$this->log_event($event_type, 'configured', $record);
		$redirect = $this->sanitize_redirect_url($current_url, home_url('/'));

		wp_send_json_success(
			array(
				'redirect'      => $redirect,
				'status'        => 'configured',
				'preferences'   => $preferences,
				'consentRecord' => $record,
				'gtmConsent'    => $this->get_gtm_consent_payload(),
			)
		);
	}

	/**
	 * Remove external script tags on allowed legal pages before consent.
	 *
	 * @param string $tag Script tag.
	 * @param string $handle Script handle.
	 * @param string $src Source URL.
	 * @return string
	 */
	public function filter_external_script_tag($tag, $handle, $src) {
		if (! $this->should_evaluate_external_assets()) {
			return $tag;
		}

		return $this->should_allow_external_url($src) ? $tag : '';
	}

	/**
	 * Remove external style tags on allowed legal pages before consent.
	 *
	 * @param string $html Style HTML.
	 * @param string $handle Style handle.
	 * @param string $href Source URL.
	 * @param string $media Media query.
	 * @return string
	 */
	public function filter_external_style_tag($html, $handle, $href, $media) {
		if (! $this->should_evaluate_external_assets()) {
			return $html;
		}

		return $this->should_allow_external_url($href) ? $html : '';
	}

	/**
	 * Prüft, ob externe Assets ausgewertet werden sollen.
	 *
	 * @return bool
	 */
	private function should_evaluate_external_assets() {
		$settings = $this->get_settings();

		if ('off' === $settings['blocking_mode']) {
			return false;
		}

		if ($this->is_robot_request()) {
			return false;
		}

		return $this->should_handle_frontend_request() && ! $this->is_admin_bypass_active();
	}

	/**
	 * Prüft, ob eine externe URL gemäß aktueller Auswahl geladen werden darf.
	 *
	 * @param string $url Externe URL.
	 * @return bool
	 */
	private function should_allow_external_url($url) {
		if (! $this->is_external_url($url)) {
			return true;
		}

		if ('configured' !== $this->get_consent_state()) {
			return false;
		}

		$vendor_slug = $this->match_vendor_slug_from_url($url);

		if (! $vendor_slug) {
			return false;
		}

		return $this->has_vendor_consent($vendor_slug);
	}

	/**
	 * Sperrt bekannte eingebettete Drittinhalte ohne passende Auswahl.
	 *
	 * @param string $content Inhalts-HTML.
	 * @return string
	 */
	public function filter_content_embeds($content) {
		if (! $this->should_evaluate_external_assets() || is_admin()) {
			return $content;
		}

		return preg_replace_callback(
			'#<iframe[^>]+src=["\']([^"\']+)["\'][^>]*>.*?</iframe>#is',
			array($this, 'replace_blocked_iframe'),
			$content
		);
	}

	/**
	 * Intercept registered external shortcodes before they render.
	 *
	 * @param string|false        $output Short-circuited output.
	 * @param string              $tag    Shortcode tag.
	 * @param array<string,mixed> $attr   Shortcode attributes.
	 * @param array<int,string>   $m      Regex match data.
	 * @return string|false
	 */
	public function filter_external_shortcode_tag($output, $tag, $attr, $m) {
		if (false !== $output) {
			return $output;
		}

		if (! $this->should_evaluate_external_assets() || is_admin()) {
			return false;
		}

		$map = $this->get_shortcode_service_map();

		if (! isset($map[ $tag ])) {
			return false;
		}

		$service_id = (string) $map[ $tag ];

		if (! $service_id || $this->has_service_consent($service_id)) {
			return false;
		}

		$args = apply_filters(
			'feuer_cookie_shortcode_placeholder_args',
			array(
				'message' => 'Dieser Bereich bleibt unscharf, bis Sie den entsprechenden Dienst freigeben.',
			),
			$tag,
			$service_id,
			(array) $attr,
			$m
		);

		$placeholder_args = is_array($args) ? $args : array();

		if (empty($placeholder_args['min_height'])) {
			$placeholder_args['min_height'] = $this->extract_shortcode_placeholder_height((array) $attr);
		}

		return $this->render_service_placeholder($service_id, $placeholder_args);
	}

	/**
	 * Map shortcode tags to consent services so other plugins can integrate.
	 *
	 * @return array<string,string>
	 */
	private function get_shortcode_service_map() {
		$map = array(
			'leaflet-map'           => 'leaflet_osm',
			'leaflet-image'         => 'leaflet_osm',
			'leaflet-wms'           => 'leaflet_osm',
			'leaflet-geojson'       => 'leaflet_osm',
			'leaflet-kml'           => 'leaflet_osm',
			'leaflet-gpx'           => 'leaflet_osm',
			'leaflet-image-overlay' => 'leaflet_osm',
			'leaflet-video-overlay' => 'leaflet_osm',
		);

		$map        = apply_filters('feuer_cookie_shortcode_service_map', $map);
		$normalised = array();

		foreach ((array) $map as $shortcode => $service_id) {
			$shortcode  = sanitize_key((string) $shortcode);
			$service_id = sanitize_key((string) $service_id);

			if (! $shortcode || ! $service_id) {
				continue;
			}

			$normalised[ $shortcode ] = $service_id;
		}

		return $normalised;
	}

	/**
	 * Ersetzt blockierte Iframes durch einen Hinweis.
	 *
	 * @param array<int,string> $matches Treffer.
	 * @return string
	 */
	private function replace_blocked_iframe($matches) {
		$src         = $matches[1] ?? '';
		$service_id  = $this->match_vendor_slug_from_url($src);

		if (! $service_id || $this->has_vendor_consent($service_id)) {
			return $matches[0];
		}

		return $this->render_service_placeholder(
			$service_id,
			array(
				'message'    => 'Dieser Bereich verwendet datenschutzrelevante Inhalte und bleibt unscharf, bis Sie den entsprechenden Dienst ausdruecklich freigeben.',
				'min_height' => $this->extract_embed_height_from_markup((string) ($matches[0] ?? '')),
			)
		);
	}

	/**
	 * Render protected content only after consent for a service.
	 *
	 * @param string $service_id Service identifier.
	 * @param string $content    Protected content.
	 * @param array  $args       Placeholder options.
	 * @return string
	 */
	public function render_service_gate($service_id, $content = '', $args = array()) {
		if (! $this->should_evaluate_external_assets()) {
			return (string) $content;
		}

		if ($this->has_service_consent($service_id)) {
			return (string) $content;
		}

		$placeholder_args = (array) $args;

		if (empty($placeholder_args['min_height'])) {
			$placeholder_args['min_height'] = 240;
		}

		return $this->render_service_placeholder($service_id, $placeholder_args);
	}

	/**
	 * Render a reusable placeholder for blocked services.
	 *
	 * @param string $service_id Service identifier.
	 * @param array  $args       Placeholder options.
	 * @return string
	 */
	private function render_service_placeholder($service_id, $args = array()) {
		$services = $this->get_visible_vendor_definitions();
		$service  = $services[ $service_id ] ?? array(
			'label'    => 'Externer Dienst',
			'category' => 'external_media',
		);
		$category       = $service['category'] ?? 'external_media';
		$provider       = ! empty($service['provider']) ? (string) $service['provider'] : '';
		$message        = ! empty($args['message'])
			? (string) $args['message']
			: 'Dieser Bereich nutzt datenschutzrelevante Inhalte. Ohne Ihre Zustimmung bleibt der Inhalt unscharf und blockiert.';
		$min_height     = $this->normalize_placeholder_height($args['min_height'] ?? 240);
		$style_attribute = sprintf('--feuer-cookie-placeholder-height:%dpx;', $min_height);
		$meta_parts     = array(
			'Datenschutz-Einstellung erforderlich',
			$this->category_label($category),
		);

		if ($provider) {
			$meta_parts[] = $provider;
		}

		ob_start();
		?>
		<div class="feuer-cookie-placeholder feuer-cookie-placeholder--blur feuer-cookie-embed-placeholder card border-0 shadow-sm" data-feuer-cookie-placeholder data-service="<?php echo esc_attr($service_id); ?>">
			<div class="feuer-cookie-placeholder__preview" style="<?php echo esc_attr($style_attribute); ?>">
				<div class="feuer-cookie-placeholder__preview-surface" aria-hidden="true">
					<span class="feuer-cookie-placeholder__blur-block feuer-cookie-placeholder__blur-block--hero"></span>
					<span class="feuer-cookie-placeholder__blur-block feuer-cookie-placeholder__blur-block--wide"></span>
					<span class="feuer-cookie-placeholder__blur-block feuer-cookie-placeholder__blur-block--card"></span>
					<span class="feuer-cookie-placeholder__blur-block feuer-cookie-placeholder__blur-block--card"></span>
					<span class="feuer-cookie-placeholder__blur-block feuer-cookie-placeholder__blur-block--line"></span>
				</div>
				<div class="feuer-cookie-placeholder__veil" aria-hidden="true"></div>
				<div class="feuer-cookie-placeholder__body">
					<p class="feuer-cookie-placeholder__title"><strong><?php echo esc_html((string) $service['label']); ?></strong></p>
					<p class="feuer-cookie-placeholder__text"><?php echo esc_html($message); ?></p>
					<p class="feuer-cookie-placeholder__meta"><?php echo esc_html(implode(' | ', $meta_parts)); ?></p>
					<div class="feuer-cookie-actions feuer-cookie-actions--placeholder d-flex flex-wrap gap-2">
						<button type="button" class="feuer-cookie-button feuer-cookie-button--primary btn btn-primary btn-sm" data-feuer-cookie-allow-service="<?php echo esc_attr($service_id); ?>">
							<?php echo esc_html($this->get_settings()['service_allow_label']); ?>
						</button>
						<button type="button" class="feuer-cookie-button feuer-cookie-button--secondary btn btn-outline-secondary btn-sm" data-feuer-cookie-toggle-settings>
							<?php echo esc_html($this->get_settings()['settings_label']); ?>
						</button>
					</div>
				</div>
			</div>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Sanitize placeholder heights for blocked areas.
	 *
	 * @param mixed $height Height value.
	 * @return int
	 */
	private function normalize_placeholder_height($height) {
		$height = absint($height);

		if ($height < 160) {
			$height = 240;
		}

		return min($height, 720);
	}

	/**
	 * Extract a sensible placeholder height from blocked iframe markup.
	 *
	 * @param string $html Embed markup.
	 * @return int
	 */
	private function extract_embed_height_from_markup($html) {
		if (preg_match('/\bheight=["\']?(\d{2,4})/i', $html, $matches)) {
			return $this->normalize_placeholder_height((int) $matches[1]);
		}

		if (preg_match('/height\s*:\s*(\d{2,4})px/i', $html, $matches)) {
			return $this->normalize_placeholder_height((int) $matches[1]);
		}

		return 240;
	}

	/**
	 * Extract a sensible placeholder height from shortcode attributes.
	 *
	 * @param array<string,mixed> $atts Shortcode attributes.
	 * @return int
	 */
	private function extract_shortcode_placeholder_height($atts) {
		foreach (array('height', 'mapheight', 'h') as $key) {
			if (empty($atts[ $key ])) {
				continue;
			}

			if (preg_match('/(\d{2,4})/', (string) $atts[ $key ], $matches)) {
				return $this->normalize_placeholder_height((int) $matches[1]);
			}
		}

		return 240;
	}

	/**
	 * Shortcode wrapper for protected service content.
	 *
	 * @param array<string,mixed> $atts    Shortcode attributes.
	 * @param string|null         $content Shortcode content.
	 * @return string
	 */
	public function shortcode_service_gate($atts, $content = null) {
		$atts = shortcode_atts(
			array(
				'service' => '',
				'message' => '',
			),
			(array) $atts,
			'feuer_cookie_service_gate'
		);

		$service_id = sanitize_key((string) $atts['service']);

		if (! $service_id) {
			return '';
		}

		return $this->render_service_gate($service_id, do_shortcode((string) $content), array('message' => (string) $atts['message']));
	}

	/**
	 * Render the blocked-IP template.
	 *
	 * @param string $mode Template mode.
	 * @return void
	 */
	private function render_gate_template($mode) {
		$settings        = $this->get_settings();
		$report          = get_option($this->diagnostics_key, array());
		$preferences     = $this->get_saved_preferences();
		$service_summary = array_slice((array) ($report['service_summary'] ?? array()), 0, 6);
		nocache_headers();

		if ('blocked' === $mode) {
			status_header(403);
		}

		$plugin = $this;
		include FEUER_COOKIE_PATH . 'templates/' . $mode . '-gate.php';
		exit;
	}

	/**
	 * Get plugin settings merged with defaults.
	 *
	 * @return array<string,mixed>
	 */
	public function get_settings() {
		$settings = wp_parse_args((array) get_option($this->settings_key, array()), $this->get_default_settings());
		$settings['banner_layout'] = $this->sanitize_banner_layout((string) $settings['banner_layout']);
		$delete_label = (string) ($settings['delete_label'] ?? '');

		if ('Einwilligung loeschen' !== $delete_label && false !== strpos($delete_label, 'Einwilligung l') && false !== strpos($delete_label, 'schen')) {
			$settings['delete_label'] = 'Einwilligung loeschen';
		}

		$settings['show_reopen_button'] = 1;

		return $settings;
	}


	/**
	 * Default settings.
	 *
	 * @return array<string,mixed>
	 */
	private function get_default_settings() {
		return array(
			'logo_url'            => '',
			'consent_revision'    => FEUER_COOKIE_VERSION,
			'consent_template'    => 'feuerwand',
			'banner_layout'       => 'bottom-right',
			'agreement_title'     => 'Datenschutz-Einstellungen',
			'agreement_text'      => $this->default_agreement_text(),
			'blocked_title'       => 'Zugriff gesperrt',
			'blocked_text'        => $this->default_blocked_text(),
			'accept_label'        => 'Alle akzeptieren',
			'decline_label'       => 'Nur notwendige',
			'save_label'          => 'Auswahl speichern',
			'settings_label'      => 'Einstellungen',
			'reopen_label'        => 'Cookie-Einstellungen',
			'panel_button_shape'  => 'circle',
			'panel_button_color'  => 'ocean',
			'panel_button_icon'   => 'cookie',
			'panel_button_position' => 'bottom-right',
			'service_allow_label' => 'Diesen Dienst erlauben',
			'delete_label'        => 'Einwilligung loeschen',
			'privacy_url'         => home_url('/datenschutz/'),
			'imprint_url'         => home_url('/impressum/'),
			'support_url'         => FEUER_COOKIE_HOME_URL,
			'cookie_name'         => 'feuer_cookie_consent',
			'consent_event_retention_days' => 365,
			'analytics_retention_days' => 90,
			'consent_cookie_days' => 365,
			'overlay_opacity'     => 40,
			'blocking_mode'       => 'strict',
			'gtm_consent_mode'    => 1,
			'feuer_analytics_sync' => 0,
			'admin_bypass'        => 1,
			'show_reopen_button'  => 1,
			'demo_mode'           => 0,
		);
	}

	/**
	 * Available banner layout variants.
	 *
	 * @return array<string,string>
	 */
	private function get_banner_layout_options() {
		return array(
			'bottom-right'         => 'Unten rechts',
			'bottom-center'        => 'Unten mittig',
			'top-center'           => 'Oben mittig',
			'center-modal'         => 'Zentriertes Modal',
			'top-bar'              => 'Obere Leiste',
			'bottom-bar'           => 'Untere Leiste',
			'bottom-left-compact'  => 'Kompakt unten links',
			'bottom-right-compact' => 'Kompakt unten rechts',
			'side-left'            => 'Seitlich links',
			'side-right'           => 'Seitlich rechts',
			'center-compact'       => 'Kompakt zentriert',
			'feuer-dark-bar'       => 'Dunkle Leiste',
			'feuer-compact-toast'  => 'Kompakter Toast',
			'feuer-light-card'     => 'Helle Akzentkarte',
		);
	}

	/**
	 * Available floating panel button shapes.
	 *
	 * @return array<string,string>
	 */
	private function get_panel_button_shape_options() {
		return array(
			'circle' => 'Rund',
			'cookie' => 'Keksform',
			'diamond' => 'Diamant',
			'soft-square' => 'Weiches Quadrat',
			'square' => 'Quadratisch',
			'pill'   => 'Pill',
		);
	}

	/**
	 * Available floating panel button color presets.
	 *
	 * @return array<string,string>
	 */
	private function get_panel_button_color_options() {
		return array(
			'ocean'    => 'Blau',
			'amber'    => 'Bernstein',
			'forest'   => 'Gruen',
			'berry'    => 'Beere',
			'graphite' => 'Graphit',
			'violet'   => 'Violett',
		);
	}

	/**
	 * Available floating panel button icons.
	 *
	 * @return array<string,string>
	 */
	private function get_panel_button_icon_options() {
		return array(
			'cookie' => 'Keks',
			'shield' => 'Schild',
			'gear'   => 'Zahnrad',
			'lock'   => 'Schloss',
			'chart'  => 'Chart',
		);
	}

	/**
	 * Available positions for the floating privacy control.
	 *
	 * @return array<string,string>
	 */
	private function get_panel_button_position_options() {
		return array(
			'bottom-right' => 'Unten rechts',
			'bottom-left'  => 'Unten links',
			'middle-right' => 'Mittig rechts',
			'middle-left'  => 'Mittig links',
		);
	}

	/**
	 * Sanitize the floating panel button shape.
	 *
	 * @param string $shape Shape slug.
	 * @return string
	 */
	private function sanitize_panel_button_shape($shape) {
		$options = array_keys($this->get_panel_button_shape_options());

		return in_array($shape, $options, true) ? $shape : 'circle';
	}

	/**
	 * Sanitize the floating panel button color preset.
	 *
	 * @param string $color Color slug.
	 * @return string
	 */
	private function sanitize_panel_button_color($color) {
		$options = array_keys($this->get_panel_button_color_options());

		return in_array($color, $options, true) ? $color : 'ocean';
	}

	/**
	 * Sanitize the floating panel button icon slug.
	 *
	 * @param string $icon Icon slug.
	 * @return string
	 */
	private function sanitize_panel_button_icon($icon) {
		$options = array_keys($this->get_panel_button_icon_options());

		return in_array($icon, $options, true) ? $icon : 'cookie';
	}

	/**
	 * Sanitize the floating privacy-control position.
	 *
	 * @param string $position Position slug.
	 * @return string
	 */
	private function sanitize_panel_button_position($position) {
		$options = array_keys($this->get_panel_button_position_options());

		return in_array($position, $options, true) ? $position : 'bottom-right';
	}

	/**
	 * Available settings tabs.
	 *
	 * @return array<string,string>
	 */
	private function get_settings_tabs() {
		return array(
			'general'  => 'Marke & Links',
			'banner'   => 'Banner & Button',
			'services' => 'Kategorien & Dienste',
			'privacy'  => 'Speicherung & Betrieb',
			'export'   => 'Revision & Export',
		);
	}

	/**
	 * Sanitize settings tab slug.
	 *
	 * @param string $tab Tab slug.
	 * @return string
	 */
	private function sanitize_settings_tab($tab) {
		$tab  = sanitize_key((string) $tab);
		$tabs = $this->get_settings_tabs();

		return isset($tabs[ $tab ]) ? $tab : 'general';
	}

	/**
	 * Resolve the current settings tab from the request.
	 *
	 * @return string
	 */
	private function get_current_settings_tab() {
		$page = sanitize_key(wp_unslash($_GET['page'] ?? ''));

		if ('feuer-cookie-settings' !== $page) {
			return 'general';
		}

		return $this->sanitize_settings_tab(wp_unslash($_GET['tab'] ?? 'general'));
	}

	/**
	 * Build one settings page URL for a specific tab.
	 *
	 * @param string $tab Tab slug.
	 * @return string
	 */
	private function get_settings_page_url($tab = 'general') {
		return add_query_arg(
			array(
				'page' => 'feuer-cookie-settings',
				'tab'  => $this->sanitize_settings_tab($tab),
			),
			admin_url('admin.php')
		);
	}

	/**
	 * Render the settings tab navigation.
	 *
	 * @param string $active_tab Current tab.
	 * @return void
	 */
	private function render_settings_tabs_nav($active_tab) {
		?>
		<nav class="nav-tab-wrapper feuer-cookie-settings-tabs" aria-label="Einstellungsbereiche">
			<?php foreach ($this->get_settings_tabs() as $tab_key => $tab_label) : ?>
				<a href="<?php echo esc_url($this->get_settings_page_url($tab_key)); ?>" class="<?php echo esc_attr('nav-tab' . ($active_tab === $tab_key ? ' nav-tab-active' : '')); ?>">
					<?php echo esc_html($tab_label); ?>
				</a>
			<?php endforeach; ?>
		</nav>
		<?php
	}


	/**
	 * Default agreement text.
	 *
	 * @return string
	 */
	private function default_agreement_text() {
		return '<p>Wir verwenden notwendige Funktionen sowie nach Ihrer Auswahl optionale Statistik, Karten und externe Inhalte. Sie können alle akzeptieren, ablehnen oder Ihre Auswahl individuell festlegen.</p><p>Weitere Informationen finden Sie in unserer Datenschutzerklärung. Alle nicht erforderlichen Kategorien bleiben standardmäßig deaktiviert, bis Sie aktiv zustimmen.</p>';
	}

	/**
	 * Default decline text.
	 *
	 * @return string
	 */
	private function default_decline_text() {
		return '<p>Sie haben sich für eine datensparsame Nutzung entschieden. Es bleiben nur technisch erforderliche Funktionen aktiv. Optionale Verarbeitungen wie Statistik, eingebettete Medien, Karten oder externe Ressourcen bleiben ausgeschaltet, bis Sie diese ausdrücklich freigeben.</p><p>Sie können Ihre Auswahl jederzeit wieder öffnen und einzelne Zwecke oder Empfänger nachträglich erlauben oder widerrufen.</p>';
	}

	/**
	 * Default blocked text.
	 *
	 * @return string
	 */
	private function default_blocked_text() {
		return '<p>Ihre Anfrage wurde blockiert, weil die aktuelle IP-Adresse oder ein zugehöriger Bereich auf der Sperrliste dieser Website steht.</p><p>Wenn Sie der Meinung sind, dass dies ein Irrtum ist, kontaktieren Sie bitte den Websitebetreiber über den hinterlegten Support-Kanal.</p>';
	}

	/**
	 * Liefert die verfügbaren Einwilligungskategorien.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function get_category_definitions() {
		return array(
			'necessary'      => array(
				'label'       => 'Notwendig',
				'description' => 'Erforderlich für Speicherung Ihrer Auswahl, Sicherheit, Zustandsverwaltung und die grundlegende Bereitstellung der Website.',
				'required'    => true,
			),
			'statistics'     => array(
				'label'       => 'Statistik',
				'description' => 'Anonyme Reichweitenmessung dieser Website und optionale externe Statistikdienste.',
				'required'    => false,
			),
			'external_media' => array(
				'label'       => 'Externe Medien',
				'description' => 'Optionale externe Inhalte, Einbettungen, CDNs oder sonstige Drittressourcen.',
				'required'    => false,
			),
			'maps'           => array(
				'label'       => 'Karten',
				'description' => 'Optionale Karten-, Tile- und Geodienste wie Leaflet oder OpenStreetMap.',
				'required'    => false,
			),
			'marketing'      => array(
				'label'       => 'Marketing',
				'description' => 'Optionale Tracking-, Marketing- oder Tag-Management-Dienste.',
				'required'    => false,
			),
		);
	}

	/**
	 * Liefert die bekannten Basis-Empfänger.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function get_base_vendor_definitions() {
		return array(
			'necessary_core'               => array(
				'label'        => 'Feuer-Cookie Kernfunktionen',
				'category'     => 'necessary',
				'provider'     => get_bloginfo('name'),
				'purpose'      => 'Speicherung Ihrer Auswahl, Sitzungszustand und datenschutzkonforme Auslieferung des Plugins.',
				'legal_basis'  => '§ 25 Abs. 2 Nr. 2 TDDDG, Art. 6 Abs. 1 lit. f DSGVO',
				'description'  => 'Ohne diese technisch erforderliche Verarbeitung kann das Plugin Ihre Auswahl nicht zuverlässig speichern.',
				'required'     => true,
				'detected'     => true,
			),
			'feuer_cookie_security'         => array(
				'label'        => 'Feuer-Cookie Sicherheitsprotokolle',
				'category'     => 'necessary',
				'provider'     => get_bloginfo('name'),
				'purpose'      => 'Abwehr missbräuchlicher Zugriffe, IP-Sperren und Sicherheitsereignisse.',
				'legal_basis'  => '§ 25 Abs. 2 Nr. 2 TDDDG, Art. 6 Abs. 1 lit. f DSGVO',
				'description'  => 'Technisch und sicherheitsbezogen erforderliche Ereignisse bleiben aktiv.',
				'required'     => true,
				'detected'     => true,
			),
			'feuer_cookie_statistics'       => array(
				'label'        => 'Feuer-Cookie Statistik',
				'category'     => 'statistics',
				'provider'     => get_bloginfo('name'),
				'purpose'      => 'Pseudonymisierte Reichweitenmessung innerhalb dieser Website.',
				'description'  => 'Erfasst Seitenpfade, externe Herkunftsdomains sowie grobe Geräte- und Browsergruppen. Nur nach Ihrer ausdrücklichen Freigabe; IP-Adressen, vollständige URLs und rohe Browser-Kennungen werden nicht gespeichert. Ein zufälliges Sitzungs-Cookie läuft nach 30 Minuten Inaktivität ab.',
				'legal_basis'  => '§ 25 Abs. 1 TDDDG, Art. 6 Abs. 1 lit. a DSGVO',
				'always_visible' => true,
				'required'     => false,
			),
			'leaflet_osm'                  => array(
				'label'        => 'Leaflet / OpenStreetMap',
				'category'     => 'maps',
				'provider'     => 'OpenStreetMap Foundation',
				'purpose'      => 'Bereitstellung interaktiver Karten, Kartendaten und Tile-Layer.',
				'legal_basis'  => '§ 25 Abs. 1 TDDDG, Art. 6 Abs. 1 lit. a DSGVO',
				'description'  => 'Bei Freigabe können Leaflet-Komponenten und Kartenkacheln von OpenStreetMap geladen werden.',
				'privacy_url'  => 'https://www.openstreetmap.org/copyright',
				'cookies'      => array(),
				'loads_external_resources' => true,
				'always_visible' => true,
				'required'     => false,
			),
			'leaflet'                      => array(
				'label'        => 'Leaflet',
				'category'     => 'maps',
				'provider'     => 'Leaflet',
				'purpose'      => 'Darstellung interaktiver Kartenoberflächen.',
				'legal_basis'  => '§ 25 Abs. 1 TDDDG, Art. 6 Abs. 1 lit. a DSGVO',
				'description'  => 'Leaflet wird nur berücksichtigt, wenn die Kartenfunktion auf der Website eingesetzt wird.',
				'required'     => false,
			),
			'google_analytics'             => array(
				'label'        => 'Google Analytics / Tag Manager',
				'category'     => 'marketing',
				'provider'     => 'Google',
				'purpose'      => 'Externe Statistik-, Tracking- und Tag-Management-Funktionen.',
				'legal_basis'  => '§ 25 Abs. 1 TDDDG, Art. 6 Abs. 1 lit. a DSGVO',
				'description'  => 'Wird nur nach ausdrücklicher Freigabe eingebunden.',
				'required'     => false,
			),
			'google_fonts'                 => array(
				'label'        => 'Google Fonts',
				'category'     => 'external_media',
				'provider'     => 'Google',
				'purpose'      => 'Laden externer Webschriftarten.',
				'legal_basis'  => '§ 25 Abs. 1 TDDDG, Art. 6 Abs. 1 lit. a DSGVO',
				'description'  => 'Schriften von Google bleiben bis zu Ihrer Freigabe blockiert.',
				'required'     => false,
			),
			'youtube'                      => array(
				'label'        => 'YouTube',
				'category'     => 'external_media',
				'provider'     => 'Google / YouTube',
				'purpose'      => 'Einbettung und Wiedergabe externer Videos.',
				'legal_basis'  => '§ 25 Abs. 1 TDDDG, Art. 6 Abs. 1 lit. a DSGVO',
				'description'  => 'Eingebettete Videos bleiben bis zu Ihrer Freigabe blockiert.',
				'required'     => false,
			),
			'vimeo'                        => array(
				'label'        => 'Vimeo',
				'category'     => 'external_media',
				'provider'     => 'Vimeo',
				'purpose'      => 'Einbettung und Wiedergabe externer Videos.',
				'legal_basis'  => '§ 25 Abs. 1 TDDDG, Art. 6 Abs. 1 lit. a DSGVO',
				'description'  => 'Eingebettete Vimeo-Inhalte bleiben bis zu Ihrer Freigabe blockiert.',
				'required'     => false,
			),
			'bootstrap_cdn'                => array(
				'label'        => 'Bootstrap / CDN',
				'category'     => 'external_media',
				'provider'     => 'Externer CDN-Anbieter',
				'purpose'      => 'Abruf externer CSS- oder JavaScript-Dateien über Content Delivery Networks.',
				'legal_basis'  => '§ 25 Abs. 1 TDDDG, Art. 6 Abs. 1 lit. a DSGVO',
				'description'  => 'Nur relevant, wenn die Website tatsächlich externe CDN-Dateien nutzt.',
				'required'     => false,
			),
			'google_recaptcha'             => array(
				'label'        => 'Google reCAPTCHA',
				'category'     => 'external_media',
				'provider'     => 'Google',
				'purpose'      => 'Schutz von Formularen vor automatisiertem Missbrauch.',
				'description'  => 'Wird nur angezeigt, wenn reCAPTCHA auf der Website tatsächlich eingebunden ist.',
				'required'     => false,
			),
			'unpkg_cdn'                    => array(
				'label'        => 'unpkg CDN',
				'category'     => 'external_media',
				'provider'     => 'unpkg',
				'purpose'      => 'Abruf externer JavaScript- oder CSS-Dateien.',
				'legal_basis'  => '§ 25 Abs. 1 TDDDG, Art. 6 Abs. 1 lit. a DSGVO',
				'description'  => 'Bleibt ohne Einwilligung blockiert.',
				'required'     => false,
			),
			'font_awesome'                 => array(
				'label'        => 'Font Awesome',
				'category'     => 'external_media',
				'provider'     => 'Fonticons / Font Awesome',
				'purpose'      => 'Abruf externer Icon-Ressourcen.',
				'legal_basis'  => '§ 25 Abs. 1 TDDDG, Art. 6 Abs. 1 lit. a DSGVO',
				'description'  => 'Externe Icon-Dienste bleiben ohne Freigabe blockiert.',
				'required'     => false,
			),
		);
	}

	/**
	 * Return registered services from defaults and filters.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function get_registered_service_definitions() {
		$services   = apply_filters('feuer_cookie_registered_services', $this->get_base_vendor_definitions());
		$services   = apply_filters('feuer_cookie_services', $services);
		$normalised = array();

		foreach ((array) $services as $slug => $service) {
			if (! is_array($service)) {
				continue;
			}

			$service_id = sanitize_key((string) ($service['service_id'] ?? $slug));

			if (! $service_id) {
				continue;
			}

			$normalised[ $service_id ] = $this->normalize_service_definition($service_id, $service);
		}

		return $normalised;
	}

	/**
	 * Normalise one service definition.
	 *
	 * @param string              $service_id Service identifier.
	 * @param array<string,mixed> $service    Raw definition.
	 * @return array<string,mixed>
	 */
	private function normalize_service_definition($service_id, $service) {
		$categories = $this->get_category_definitions();
		$category   = sanitize_key((string) ($service['category'] ?? 'external_media'));

		if (! isset($categories[ $category ])) {
			$category = 'external_media';
		}

		return array(
			'service_id'               => $service_id,
			'label'                    => (string) ($service['label'] ?? $service['name'] ?? $service_id),
			'name'                     => (string) ($service['name'] ?? $service['label'] ?? $service_id),
			'category'                 => $category,
			'provider'                 => (string) ($service['provider'] ?? get_bloginfo('name')),
			'purpose'                  => (string) ($service['purpose'] ?? ''),
			'legal_basis'              => (string) ($service['legal_basis'] ?? '§ 25 Abs. 1 TDDDG, Art. 6 Abs. 1 lit. a DSGVO'),
			'description'              => (string) ($service['description'] ?? ''),
			'privacy_url'              => esc_url_raw((string) ($service['privacy_url'] ?? '')),
			'cookies'                  => isset($service['cookies']) && is_array($service['cookies']) ? $service['cookies'] : array(),
			'loads_external_resources' => ! empty($service['loads_external_resources']),
			'required'                 => ! empty($service['required']),
			'detected'                 => ! empty($service['detected']),
			'always_visible'           => ! empty($service['always_visible']) || isset($service['service_id']),
			'domains'                  => isset($service['domains']) && is_array($service['domains']) ? array_values(array_filter(array_map('strval', $service['domains']))) : array(),
			'locations'                => isset($service['locations']) && is_array($service['locations']) ? $service['locations'] : array(),
		);
	}

	/**
	 * Liefert die auf dieser Website sichtbaren Empfänger.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function get_visible_vendor_definitions() {
		$base     = $this->get_registered_service_definitions();
		$detected = $this->get_detected_vendor_definitions();
		$visible  = array();

		foreach ($base as $slug => $vendor) {
			if (! empty($vendor['required']) || ! empty($vendor['always_visible']) || isset($detected[ $slug ])) {
				$visible[ $slug ] = isset($detected[ $slug ]) ? array_merge($vendor, $detected[ $slug ]) : $vendor;
			}
		}

		foreach ($detected as $slug => $vendor) {
			if (! isset($visible[ $slug ])) {
				$visible[ $slug ] = $vendor;
			}
		}

		return $visible;
	}

	/**
	 * Baut die erkannten Empfänger aus der Diagnose.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function get_detected_vendor_definitions() {
		$report   = get_option($this->diagnostics_key, array());
		$services = (array) ($report['services'] ?? array());
		$vendors  = array();
		$known    = $this->get_registered_service_definitions();

		foreach ($services as $service) {
			$service_domain = strtolower((string) ($service['domain'] ?? ''));

			if ('cloudflare.com' === $service_domain || 'cdnjs.cloudflare.com' === $service_domain || str_ends_with($service_domain, '.cloudflare.com')) {
				continue;
			}

			if (! $this->is_browser_request_diagnostic_service($service)) {
				continue;
			}

			$service_name = (string) ($service['service'] ?? '');
			$domain       = (string) ($service['domain'] ?? '');
			$slug         = $this->vendor_slug_from_service($service_name, $domain);

			if (! $slug) {
				continue;
			}

			if (! isset($vendors[ $slug ])) {
				$vendors[ $slug ] = isset($known[ $slug ])
					? $known[ $slug ]
					: $this->build_custom_vendor_definition($domain);
				$vendors[ $slug ]['detected'] = true;
				$vendors[ $slug ]['locations'] = array();
			}

			if (! empty($service['location'])) {
				$vendors[ $slug ]['locations'][] = $service['location'];
			}
		}

		foreach ($vendors as $slug => $vendor) {
			if (! empty($vendor['locations'])) {
				$vendors[ $slug ]['locations'] = array_values(array_unique((array) $vendor['locations']));
			}
		}

		return $vendors;
	}

	/**
	 * Exclude server calls and non-runtime source examples from visitor consent.
	 *
	 * @param array<string,mixed> $service Diagnostic record.
	 * @return bool
	 */
	private function is_browser_request_diagnostic_service($service) {
		$context  = strtolower((string) ($service['execution_context'] ?? ''));
		$evidence = strtolower((string) ($service['evidence'] ?? ''));
		$location = '/' . trim(strtolower(str_replace('\\', '/', (string) ($service['location'] ?? ''))), '/') . '/';

		if ('server' === $context || false !== strpos($evidence, 'wp http request') || false !== strpos($evidence, 'curl request')) {
			return false;
		}

		foreach (array('/release/', '/version/', '/versions/', '/snippets/', '/node_modules/', '/.git/', '/.svn/') as $segment) {
			if (false !== strpos($location, $segment)) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Erzeugt eine Definition für unbekannte externe Dienste.
	 *
	 * @param string $domain Domainname.
	 * @return array<string,mixed>
	 */
	private function build_custom_vendor_definition($domain) {
		return array(
			'label'       => $domain,
			'category'    => 'external_media',
			'provider'    => $domain,
			'purpose'     => 'Abruf externer Inhalte oder Dateien von ' . $domain . '.',
			'legal_basis' => '§ 25 Abs. 1 TDDDG, Art. 6 Abs. 1 lit. a DSGVO',
			'description' => 'Dieser externe Dienst wurde bei der Diagnose erkannt und bleibt bis zu Ihrer Freigabe blockiert.',
			'privacy_url' => '',
			'cookies'     => array(),
			'loads_external_resources' => true,
			'required'    => false,
			'detected'    => true,
		);
	}

	/**
	 * Liefert den Vendorslug zu einem Diagnosedienst.
	 *
	 * @param string $service Service-Name.
	 * @param string $domain Domain.
	 * @return string
	 */
	private function vendor_slug_from_service($service, $domain = '') {
		switch ($service) {
			case 'OpenStreetMap':
			case 'Leaflet':
				return 'leaflet_osm';
			case 'Bootstrap/CDN':
				return 'bootstrap_cdn';
			case 'Google Analytics/Tag Manager':
				return 'google_analytics';
			case 'Google Fonts':
				return 'google_fonts';
			case 'Google reCAPTCHA':
				return 'google_recaptcha';
			case 'YouTube':
				return 'youtube';
			case 'Vimeo':
				return 'vimeo';
			case 'Font Awesome':
				return 'font_awesome';
			case 'unpkg CDN':
				return 'unpkg_cdn';
			default:
				if (! $domain) {
					return '';
				}

				return 'domain_' . preg_replace('/[^a-z0-9_]+/i', '_', strtolower($domain));
		}
	}

	/**
	 * Ordnet eine URL einem Vendorslug zu.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private function match_vendor_slug_from_url($url) {
		$matched = sanitize_key((string) apply_filters('feuer_cookie_match_service_for_url', '', $url));

		if ($matched) {
			return $matched;
		}

		$integrated_service = $this->match_integrated_vendor_from_url($url);

		if ($integrated_service) {
			return $integrated_service;
		}

		$host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
		$path = strtolower((string) wp_parse_url($url, PHP_URL_PATH));

		if (! $host) {
			return '';
		}

		if ((false !== strpos($host, 'google.com') || false !== strpos($host, 'recaptcha.net')) && false !== strpos($path, 'recaptcha')) {
			return 'google_recaptcha';
		}

		foreach ($this->get_registered_service_definitions() as $service_id => $service) {
			foreach ((array) ($service['domains'] ?? array()) as $domain) {
				$domain = strtolower((string) $domain);

				if ($domain && false !== strpos($host, $domain)) {
					return $service_id;
				}
			}
		}

		if (false !== strpos($host, 'openstreetmap.org') || false !== strpos($host, 'nominatim.openstreetmap.org') || false !== strpos($host, 'leafletjs.com')) {
			return 'leaflet_osm';
		}

		if (false !== strpos($host, 'google-analytics.com') || false !== strpos($host, 'googletagmanager.com') || false !== strpos($host, 'doubleclick.net')) {
			return 'google_analytics';
		}

		if (false !== strpos($host, 'fonts.googleapis.com') || false !== strpos($host, 'fonts.gstatic.com')) {
			return 'google_fonts';
		}

		if (false !== strpos($host, 'youtube.com') || false !== strpos($host, 'youtube-nocookie.com') || false !== strpos($host, 'youtu.be')) {
			return 'youtube';
		}

		if (false !== strpos($host, 'vimeo.com')) {
			return 'vimeo';
		}

		if (false !== strpos($host, 'fontawesome.com') || false !== strpos($host, 'kit.fontawesome.com') || false !== strpos($host, 'use.fontawesome.com')) {
			return 'font_awesome';
		}

		if (false !== strpos($host, 'unpkg.com')) {
			return 'unpkg_cdn';
		}

		if (false !== strpos($host, 'bootstrapcdn.com') || false !== strpos($host, 'jsdelivr.net')) {
			return 'bootstrap_cdn';
		}

		return 'domain_' . preg_replace('/[^a-z0-9_]+/i', '_', $host);
	}

	/**
	 * Recognise plugin-specific asset URLs that belong to one consent service.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private function match_integrated_vendor_from_url($url) {
		$host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
		$path = strtolower((string) wp_parse_url($url, PHP_URL_PATH));

		if (! $host) {
			return '';
		}

		if (false !== strpos($host, 'openstreetmap.org') || false !== strpos($host, 'nominatim.openstreetmap.org') || false !== strpos($host, 'leafletjs.com')) {
			return 'leaflet_osm';
		}

		if (false !== strpos($host, 'mapquestapi.com')) {
			return 'leaflet_osm';
		}

		if ((false !== strpos($host, 'unpkg.com') || false !== strpos($host, 'jsdelivr.net')) && (false !== strpos($path, 'leaflet') || false !== strpos($path, 'togeojson'))) {
			return 'leaflet_osm';
		}

		return '';
	}

	/**
	 * Liefert die Standardpräferenzen.
	 *
	 * @return array<string,array<string,bool>>
	 */
	private function get_default_preferences() {
		$categories = array();
		$vendors    = array();

		foreach ($this->get_category_definitions() as $slug => $category) {
			$categories[ $slug ] = ! empty($category['required']);
		}

		foreach ($this->get_visible_vendor_definitions() as $slug => $vendor) {
			$vendors[ $slug ] = ! empty($vendor['required']);
		}

		return array(
			'categories' => $categories,
			'vendors'    => $vendors,
		);
	}

	/**
	 * Liefert Präferenzen nur mit erforderlichen Diensten.
	 *
	 * @return array<string,array<string,bool>>
	 */
	private function get_necessary_only_preferences() {
		return $this->get_default_preferences();
	}

	/**
	 * Liefert Präferenzen mit allen optionalen Diensten.
	 *
	 * @return array<string,array<string,bool>>
	 */
	private function get_accept_all_preferences() {
		$preferences = $this->get_default_preferences();

		foreach ($this->get_category_definitions() as $slug => $category) {
			$preferences['categories'][ $slug ] = true;
		}

		foreach ($this->get_visible_vendor_definitions() as $slug => $vendor) {
			$preferences['vendors'][ $slug ] = true;
		}

		return $preferences;
	}

	/**
	 * Check whether preferences contain at least one non-required consent.
	 *
	 * @param array<string,array<string,bool>> $preferences Preferences.
	 * @return bool
	 */
	private function has_optional_consent_in_preferences($preferences) {
		$preferences = $this->sanitize_preferences($preferences);
		$services    = $this->get_visible_vendor_definitions();
		$categories  = $this->get_category_definitions();

		foreach ($services as $slug => $service) {
			if (empty($preferences['vendors'][ $slug ]) || ! empty($service['required'])) {
				continue;
			}

			return true;
		}

		foreach ($categories as $slug => $category) {
			if (empty($preferences['categories'][ $slug ]) || ! empty($category['required'])) {
				continue;
			}

			return true;
		}

		return false;
	}

	/**
	 * Grant consent for a single service and its parent category.
	 *
	 * @param array<string,array<string,bool>> $preferences Existing preferences.
	 * @param string                           $service_id   Service identifier.
	 * @return array<string,array<string,bool>>
	 */
	private function grant_service_consent($preferences, $service_id) {
		$preferences = $this->sanitize_preferences($preferences);
		$services    = $this->get_visible_vendor_definitions();

		if (! isset($services[ $service_id ])) {
			return $preferences;
		}

		$category = (string) $services[ $service_id ]['category'];

		$preferences['vendors'][ $service_id ]    = true;
		$preferences['categories'][ $category ]   = true;

		return $this->sanitize_preferences($preferences);
	}

	/**
	 * Build the stored consent record with metadata for re-consent checks.
	 *
	 * @param array<string,array<string,bool>> $preferences Preferences.
	 * @param string                           $decision    Decision type.
	 * @param string                           $service_id  Service identifier.
	 * @param bool                             $privacy_acknowledged Set automatically after an explicit consent action.
	 * @return array<string,mixed>
	 */
	private function build_consent_record($preferences, $decision, $service_id = '', $privacy_acknowledged = false) {
		$settings = $this->get_settings();

		return array(
			'decision'             => $decision,
			'service_id'           => $service_id,
			'timestamp'            => current_time('mysql'),
			'revision'             => (string) $settings['consent_revision'],
			'config_hash'          => $this->get_consent_config_hash(),
			'privacy_acknowledged' => (bool) $privacy_acknowledged,
			'preferences'          => $this->sanitize_preferences($preferences),
		);
	}

	/**
	 * Persist the consent record in cookies.
	 *
	 * @param array<string,mixed> $record Consent record.
	 * @return void
	 */
	private function store_consent_record($record) {
		$settings = $this->get_settings();

		$this->set_cookie($this->get_cookie_name('consent'), 'configured', (int) $settings['consent_cookie_days']);
		$this->set_cookie($this->get_cookie_name('revision'), (string) ($record['revision'] ?? $settings['consent_revision']), (int) $settings['consent_cookie_days']);
		$this->set_cookie(
			$this->get_cookie_name('preferences'),
			rawurlencode(wp_json_encode($record)),
			(int) $settings['consent_cookie_days']
		);
	}

	/**
	 * Liest gespeicherte Präferenzen aus dem Cookie.
	 *
	 * @return array<string,array<string,bool>>
	 */
	private function get_saved_preferences() {
		$record = $this->get_saved_consent_record();

		if (empty($record['preferences']) || ! is_array($record['preferences'])) {
			return $this->get_default_preferences();
		}

		return $this->sanitize_preferences($record['preferences']);
	}

	/**
	 * Read the structured consent record from the preference cookie.
	 *
	 * @return array<string,mixed>
	 */
	private function get_saved_consent_record() {
		$raw_cookie = wp_unslash($_COOKIE[ $this->get_cookie_name('preferences') ] ?? '');

		if (! $raw_cookie) {
			return array();
		}

		$decoded = $this->decode_preferences_payload($raw_cookie);

		if (isset($decoded['preferences']) && is_array($decoded['preferences'])) {
			$decoded['preferences'] = $this->sanitize_preferences($decoded['preferences']);
			$decoded['privacy_acknowledged'] = ! empty($decoded['privacy_acknowledged']);
			return $decoded;
		}

		if (isset($decoded['categories']) || isset($decoded['vendors'])) {
			return array(
				'privacy_acknowledged' => false,
				'preferences'          => $this->sanitize_preferences($decoded),
			);
		}

		return array();
	}

	/**
	 * Dekodiert den Präferenz-String aus Cookie oder Request.
	 *
	 * @param string $payload Rohwert.
	 * @return mixed
	 */
	private function decode_preferences_payload($payload) {
		if (! is_string($payload) || '' === $payload) {
			return array();
		}

		$decoded = json_decode(rawurldecode($payload), true);

		return is_array($decoded) ? $decoded : array();
	}

	/**
	 * Normalisiert und erzwingt gültige Präferenzen.
	 *
	 * @param mixed $preferences Eingabedaten.
	 * @return array<string,array<string,bool>>
	 */
	private function sanitize_preferences($preferences) {
		$defaults   = $this->get_default_preferences();
		$categories = $defaults['categories'];
		$vendors    = $defaults['vendors'];
		$defs_cat   = $this->get_category_definitions();
		$defs_vnd   = $this->get_visible_vendor_definitions();
		$input_cat  = is_array($preferences) && isset($preferences['categories']) && is_array($preferences['categories']) ? $preferences['categories'] : array();
		$input_vnd  = is_array($preferences) && isset($preferences['vendors']) && is_array($preferences['vendors']) ? $preferences['vendors'] : array();

		foreach ($categories as $slug => $default_value) {
			if (! empty($defs_cat[ $slug ]['required'])) {
				$categories[ $slug ] = true;
				continue;
			}

			$categories[ $slug ] = ! empty($input_cat[ $slug ]);
		}

		foreach ($vendors as $slug => $default_value) {
			if (! empty($defs_vnd[ $slug ]['required'])) {
				$vendors[ $slug ] = true;
				continue;
			}

			$vendors[ $slug ] = ! empty($input_vnd[ $slug ]);
		}

		foreach ($defs_vnd as $slug => $vendor) {
			$category = $vendor['category'];

			if (! empty($vendor['required'])) {
				$categories[ $category ] = true;
				$vendors[ $slug ]        = true;
				continue;
			}

			if (empty($categories[ $category ])) {
				$vendors[ $slug ] = false;
			}
		}

		foreach ($defs_cat as $slug => $category) {
			if (! empty($category['required'])) {
				$categories[ $slug ] = true;
				continue;
			}

			$has_vendor = false;

			foreach ($defs_vnd as $vendor_slug => $vendor) {
				if ($vendor['category'] === $slug && ! empty($vendors[ $vendor_slug ])) {
					$has_vendor = true;
					break;
				}
			}

			$categories[ $slug ] = $has_vendor;
		}

		return array(
			'categories' => $categories,
			'vendors'    => $vendors,
		);
	}

	/**
	 * Prüft, ob für eine Kategorie eine Einwilligung vorliegt.
	 *
	 * @param string $category Kategorie-Slug.
	 * @return bool
	 */
	public function has_category_consent($category) {
		if ('configured' !== $this->get_consent_state()) {
			return false;
		}

		$preferences = $this->get_saved_preferences();

		return ! empty($preferences['categories'][ $category ]);
	}

	/**
	 * Prüft, ob für einen Empfänger eine Einwilligung vorliegt.
	 *
	 * @param string $vendor Vendor-Slug.
	 * @return bool
	 */
	public function has_vendor_consent($vendor) {
		if ('configured' !== $this->get_consent_state()) {
			return false;
		}

		if (in_array($vendor, array('openstreetmap', 'leaflet'), true)) {
			$vendor = 'leaflet_osm';
		}

		$preferences = $this->get_saved_preferences();

		return ! empty($preferences['vendors'][ $vendor ]);
	}

	/**
	 * Check consent for a service identifier.
	 *
	 * @param string $service_id Service identifier.
	 * @return bool
	 */
	public function has_service_consent($service_id) {
		$service_id = sanitize_key($service_id);

		if ('feuer-analytics' === $service_id && ! $this->is_feuer_analytics_sync_enabled()) {
			return false;
		}

		if (! $this->should_evaluate_external_assets()) {
			return true;
		}

		return $this->has_vendor_consent($service_id);
	}

	/**
	 * Allow the optional Feuer Analytics bridge only after an administrator has
	 * deliberately enabled it. This prevents a newly activated analytics plugin
	 * from collecting visits before the service is configured in the CMP.
	 *
	 * @return bool
	 */
	public function is_feuer_analytics_sync_enabled() {
		return ! empty($this->get_settings()['feuer_analytics_sync']);
	}

	/**
	 * Hide the external analytics service until the optional bridge is enabled.
	 *
	 * @param array<string,array<string,mixed>> $services Registered services.
	 * @return array<string,array<string,mixed>>
	 */
	public function filter_feuer_analytics_service($services) {
		if (! $this->is_feuer_analytics_sync_enabled()) {
			unset($services['feuer-analytics']);
		}

		return $services;
	}

	/**
	 * Return consent totals for a service without exposing consent records.
	 *
	 * This API deliberately returns no session identifiers, request URLs, IP
	 * addresses, timestamps or preference payloads. Consumers can use it for
	 * an operational report without becoming a second consent-data store.
	 *
	 * @param string $service_id Service identifier.
	 * @param int    $days       Number of completed/current days to include.
	 * @return array<string,int|string|bool>
	 */
	public function get_consent_report($service_id, $days = 30) {
		global $wpdb;

		$service_id = sanitize_key($service_id);
		$days       = max(1, min(365, absint($days)));
		$since      = wp_date('Y-m-d 00:00:00', current_time('timestamp') - (($days - 1) * DAY_IN_SECONDS));
		$empty      = array(
			'service'           => $service_id,
			'days'              => $days,
			'available'         => false,
			'opt_in_events'     => 0,
			'optional_consents' => 0,
			'necessary_only'    => 0,
		);

		if ('feuer-analytics' === $service_id && ! $this->is_feuer_analytics_sync_enabled()) {
			return $empty;
		}

		if (! $service_id || ! $this->table_exists('events')) {
			return $empty;
		}

		$events_table = $this->table_sql('events');
		$optional_sql = "request_type IN ('consent_accept_all', 'consent_custom', 'consent_service_allow')";
		$service_like = '%\"service_id\":\"' . $wpdb->esc_like($service_id) . '\"%';

		$empty['available'] = true;
		$empty['opt_in_events'] = (int) $wpdb->get_var($wpdb->prepare(
			"SELECT COUNT(*) FROM {$events_table} WHERE request_type = 'consent_service_allow' AND meta_json LIKE %s AND created_at >= %s",
			$service_like,
			$since
		));
		$empty['optional_consents'] = (int) $wpdb->get_var($wpdb->prepare(
			"SELECT COUNT(*) FROM {$events_table} WHERE {$optional_sql} AND created_at >= %s",
			$since
		));
		$empty['necessary_only'] = (int) $wpdb->get_var($wpdb->prepare(
			"SELECT COUNT(*) FROM {$events_table} WHERE request_type = 'consent_necessary' AND created_at >= %s",
			$since
		));

		return $empty;
	}

	/**
	 * Determine whether the plugin should handle the current frontend request.
	 *
	 * @return bool
	 */
	private function should_handle_frontend_request() {
		if (is_admin() || wp_doing_ajax() || wp_doing_cron()) {
			return false;
		}

		if ((defined('REST_REQUEST') && REST_REQUEST) || (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST)) {
			return false;
		}

		if (is_feed() || is_trackback() || is_preview()) {
			return false;
		}

		return true;
	}

	/**
	 * Get current consent state with revision check.
	 *
	 * @return string
	 */
	private function get_consent_state() {
		if ($this->is_demo_mode_active()) {
			return 'unknown';
		}

		$settings = $this->get_settings();
		$state    = sanitize_key(wp_unslash($_COOKIE[ $this->get_cookie_name('consent') ] ?? ''));
		$revision = sanitize_text_field(wp_unslash($_COOKIE[ $this->get_cookie_name('revision') ] ?? ''));
		$record   = $this->get_saved_consent_record();

		if ('configured' !== $state) {
			return 'unknown';
		}

		if ($revision !== (string) $settings['consent_revision']) {
			return 'unknown';
		}

		if (empty($record['preferences']) || empty($record['timestamp'])) {
			return 'unknown';
		}

		if (($record['config_hash'] ?? '') !== $this->get_consent_config_hash()) {
			return 'unknown';
		}

		return 'configured';
	}

	/**
	 * Resolve one cookie name from settings and fallback defaults.
	 *
	 * @param string $type consent, revision, preferences or session.
	 * @return string
	 */
	private function get_cookie_name($type) {
		$settings = $this->get_settings();
		$base     = sanitize_key((string) ($settings['cookie_name'] ?? $this->consent_cookie));

		if (! $base) {
			$base = $this->consent_cookie;
		}

		switch ($type) {
			case 'session':
				return $base . '_session';
			case 'revision':
				return $base . '_revision';
			case 'preferences':
				return $base . '_preferences';
			case 'consent':
			default:
				return $base;
		}
	}

	/**
	 * Sanitize the script blocking mode.
	 *
	 * @param string $mode Requested mode.
	 * @return string
	 */
	private function sanitize_blocking_mode($mode) {
		return in_array($mode, array('strict', 'off'), true) ? $mode : 'strict';
	}

	/**
	 * Check whether logged-in admins bypass banner handling.
	 *
	 * @return bool
	 */
	private function is_admin_bypass_active() {
		if ($this->is_demo_mode_active()) {
			return false;
		}

		if (current_user_can('manage_options') && ! empty($_GET['feuer_cookie_preview'])) {
			return false;
		}

		$settings = $this->get_settings();

		return ! empty($settings['admin_bypass']) && current_user_can('manage_options');
	}

	/**
	 * Check whether demo mode is active.
	 *
	 * @return bool
	 */
	private function is_demo_mode_active() {
		return ! empty($this->get_settings()['demo_mode']);
	}

	/**
	 * Clear consent cookies for the current browser.
	 *
	 * @param bool $clear_session Also clear the session cookie.
	 * @return void
	 */
	private function clear_consent_storage($clear_session = false) {
		$cookie_types = array('consent', 'revision', 'preferences');

		if ($clear_session) {
			$cookie_types[] = 'session';
		}

		foreach ($cookie_types as $cookie_type) {
			$this->set_cookie($this->get_cookie_name($cookie_type), '', -1);
		}
	}

	/**
	 * Ensure demo mode never reuses persisted client consent.
	 *
	 * @return void
	 */
	public function maybe_reset_demo_mode_storage() {
		if (! $this->is_demo_mode_active() || ! $this->should_handle_frontend_request()) {
			return;
		}

		$this->clear_consent_storage(true);
	}

	/**
	 * Compute the consent configuration hash for automatic re-consent.
	 *
	 * @return string
	 */
	private function get_consent_config_hash() {
		$settings = $this->get_settings();
		$payload  = array(
			'categories'        => $this->get_category_definitions(),
			'services'          => $this->get_visible_vendor_definitions(),
			'consent_revision'  => (string) $settings['consent_revision'],
			'agreement_title'   => (string) $settings['agreement_title'],
			'agreement_text'    => (string) $settings['agreement_text'],
			'accept_label'      => (string) $settings['accept_label'],
			'decline_label'     => (string) $settings['decline_label'],
			'save_label'        => (string) $settings['save_label'],
			'privacy_url'       => (string) $settings['privacy_url'],
			'imprint_url'       => (string) $settings['imprint_url'],
			'blocking_mode'     => (string) $settings['blocking_mode'],
		);

		return md5(wp_json_encode($payload));
	}

	/**
	 * Return one category label.
	 *
	 * @param string $category Category slug.
	 * @return string
	 */
	private function category_label($category) {
		$categories = $this->get_category_definitions();

		return isset($categories[ $category ]['label']) ? (string) $categories[ $category ]['label'] : (string) $category;
	}

	/**
	 * Append the consent revision log when relevant settings changed.
	 *
	 * @param array<string,mixed> $previous Previous settings.
	 * @param array<string,mixed> $current  Current settings.
	 * @return void
	 */
	private function maybe_append_revision_log($previous, $current) {
		$tracked_keys = array(
			'consent_revision',
			'agreement_title',
			'agreement_text',
			'accept_label',
			'decline_label',
			'save_label',
			'settings_label',
			'privacy_url',
			'imprint_url',
			'blocking_mode',
		);

		foreach ($tracked_keys as $key) {
			if (($previous[ $key ] ?? null) !== ($current[ $key ] ?? null)) {
				$log = $this->get_consent_revision_log();
				array_unshift($log, $this->build_revision_log_entry($current));
				update_option($this->consent_log_key, array_slice($log, 0, 20));
				return;
			}
		}
	}

	/**
	 * Build one revision log entry.
	 *
	 * @param array<string,mixed> $settings Settings snapshot.
	 * @return array<string,string>
	 */
	private function build_revision_log_entry($settings) {
		return array(
			'saved_at'    => current_time('mysql'),
			'revision'    => (string) ($settings['consent_revision'] ?? FEUER_COOKIE_VERSION),
			'banner_title'=> (string) ($settings['agreement_title'] ?? ''),
			'config_hash' => $this->get_consent_config_hash(),
		);
	}

	/**
	 * Return the stored revision history.
	 *
	 * @return array<int,array<string,string>>
	 */
	private function get_consent_revision_log() {
		$log = get_option($this->consent_log_key, array());

		return is_array($log) ? $log : array();
	}

	/**
	 * Export the plugin configuration as JSON.
	 *
	 * @return string
	 */
	private function get_configuration_export_json() {
		return wp_json_encode(
			array(
				'generated_at'  => current_time('mysql'),
				'settings'      => $this->get_settings(),
				'categories'    => $this->get_category_definitions(),
				'services'      => $this->get_visible_vendor_definitions(),
				'revision_log'  => $this->get_consent_revision_log(),
				'config_hash'   => $this->get_consent_config_hash(),
			),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);
	}

	/**
	 * Log one page view only once per request.
	 *
	 * @return void
	 */
	private function log_page_view($context = array()) {
		if ($this->request_logged) {
			return;
		}

		$this->request_logged = true;
		$this->log_event('page_view', 'configured', array(), $context);
	}

	/**
	 * Remember an AJAX-logged page view to avoid a duplicate after redirect.
	 *
	 * @param string $session_token Session token.
	 * @param string $request_path Request path.
	 * @return void
	 */
	private function mark_recent_logged_page_view($session_token, $request_path) {
		if (! $session_token || ! $request_path) {
			return;
		}

		set_transient($this->recent_page_view_key($session_token, $request_path), 1, MINUTE_IN_SECONDS);
	}

	/**
	 * Consume an AJAX page-view marker if the redirected request repeats it.
	 *
	 * @param string $session_token Session token.
	 * @param string $request_path Request path.
	 * @return bool
	 */
	private function consume_recent_logged_page_view($session_token, $request_path) {
		if (! $session_token || ! $request_path) {
			return false;
		}

		$key = $this->recent_page_view_key($session_token, $request_path);

		if (! get_transient($key)) {
			return false;
		}

		delete_transient($key);
		return true;
	}

	/**
	 * Build a transient key for one recently logged page view.
	 *
	 * @param string $session_token Session token.
	 * @param string $request_path Request path.
	 * @return string
	 */
	private function recent_page_view_key($session_token, $request_path) {
		return 'feuer_cookie_pv_' . md5($session_token . '|' . $request_path);
	}

	/**
	 * Insert an event row.
	 *
	 * @param string              $type Event type.
	 * @param string|null         $consent_state Optional override state.
	 * @param array<string,mixed> $meta Metadata.
	 * @param array<string,mixed> $context Reserved for backward compatibility.
	 * @return void
	 */
	private function log_event($type, $consent_state = null, $meta = array(), $context = array()) {
		global $wpdb;

		$ip            = $this->get_client_ip();
		$session_token = $this->ensure_session_token();
		$request_path  = $this->current_path();
		$request_url   = esc_url_raw(home_url($request_path));
		$ip_address    = $this->mask_ip($ip);
		$consent       = $consent_state ? $consent_state : $this->get_consent_state();

		$inserted = $wpdb->insert(
			$this->tables['events'],
			array(
				'session_token' => $session_token,
				'consent_state' => $consent,
				'request_type'  => $type,
				'request_url'   => $request_url,
				'request_path'  => $request_path,
				'referrer_url'  => '',
				'referrer_path' => '',
				'page_title'    => '',
				'ip_address'    => $ip_address,
				'ip_hash'       => $this->hash_ip($ip),
				'user_agent'    => '',
				'browser_lang'  => '',
				'country_code'  => '',
				'country_name'  => '',
				'city'          => '',
				'device_type'   => '',
				'browser_name'  => '',
				'os_name'       => '',
				'is_robot'      => 0,
				'meta_json'     => wp_json_encode($meta),
				'created_at'    => current_time('mysql'),
			),
			array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s')
		);

		if (false !== $inserted) {
			$this->increment_daily_stats($type, $session_token);
		}

	}

	/**
	 * Update daily aggregate counters.
	 *
	 * @param string $type Event type.
	 * @param string $session_token Session token.
	 * @return void
	 */
	private function increment_daily_stats($type, $session_token) {
		global $wpdb;

		$day             = current_time('Y-m-d');
		$page_views      = 'page_view' === $type ? 1 : 0;
		$accepts         = in_array($type, array('consent_accept_all', 'consent_custom', 'consent_service_allow'), true) ? 1 : 0;
		$declines        = 'consent_necessary' === $type ? 1 : 0;
		$blocked         = 'blocked_ip' === $type ? 1 : 0;
		$unique_visitors = 0;
		$events_table    = $this->table_sql('events');
		$daily_stats_table = $this->table_sql('daily_stats');

		if ('page_view' === $type) {
			$already_seen = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$events_table}
					WHERE request_type = 'page_view'
					AND session_token = %s
					AND DATE(created_at) = %s",
					$session_token,
					$day
				)
			);

			$unique_visitors = (int) $already_seen <= 1 ? 1 : 0;
		}

		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$daily_stats_table}
					(day, page_views, unique_visitors, consents_accept, consents_decline, blocked_requests)
				VALUES (%s, %d, %d, %d, %d, %d)
				ON DUPLICATE KEY UPDATE
					page_views = page_views + VALUES(page_views),
					unique_visitors = unique_visitors + VALUES(unique_visitors),
					consents_accept = consents_accept + VALUES(consents_accept),
					consents_decline = consents_decline + VALUES(consents_decline),
					blocked_requests = blocked_requests + VALUES(blocked_requests)",
				$day,
				$page_views,
				$unique_visitors,
				$accepts,
				$declines,
				$blocked
			)
		);
	}

	/**
	 * Ensure a session token exists.
	 *
	 * @return string
	 */
	private function ensure_session_token() {
		$existing = sanitize_text_field(wp_unslash($_COOKIE[ $this->get_cookie_name('session') ] ?? ''));

		if ($existing) {
			return $existing;
		}

		$token = wp_generate_password(32, false, false);
		$this->set_cookie($this->get_cookie_name('session'), $token, (int) $this->get_settings()['consent_cookie_days']);

		return $token;
	}

	/**
	 * Set a cookie with request-aware transport security and SameSite=Lax.
	 *
	 * @param string $name Cookie name.
	 * @param string $value Cookie value.
	 * @param int    $days Days or negative to expire.
	 * @return void
	 */
	private function set_cookie($name, $value, $days) {
		$expires = $days < 0 ? time() - DAY_IN_SECONDS : time() + (DAY_IN_SECONDS * $days);

		setcookie(
			$name,
			$value,
			array(
				'expires'  => $expires,
				'path'     => COOKIEPATH ? COOKIEPATH : '/',
				// Secure cookies are accepted only on HTTPS; HTTP sites still need a persistent consent choice.
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);

		if ($days < 0) {
			unset($_COOKIE[ $name ]);
		} else {
			$_COOKIE[ $name ] = $value;
		}
	}

	/**
	 * Check whether an IP is blocked.
	 *
	 * @param string $ip Client IP.
	 * @return bool
	 */
	private function is_ip_blocked($ip) {
		global $wpdb;

		if (! $ip) {
			return false;
		}

		$ip_blocks_table = $this->table_sql('ip_blocks');
		$blocks = $wpdb->get_results(
			"SELECT ip_pattern FROM {$ip_blocks_table} WHERE is_active = 1",
			ARRAY_A
		);

		foreach ($blocks as $block) {
			if ($this->ip_matches_pattern($ip, (string) $block['ip_pattern'])) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Match IPs against exact values, wildcards or CIDR.
	 *
	 * @param string $ip Client IP.
	 * @param string $pattern Pattern.
	 * @return bool
	 */
	private function ip_matches_pattern($ip, $pattern) {
		$pattern = trim($pattern);

		if ($ip === $pattern) {
			return true;
		}

		if (false !== strpos($pattern, '*')) {
			$regex = '/^' . str_replace('\*', '.*', preg_quote($pattern, '/')) . '$/i';
			return (bool) preg_match($regex, $ip);
		}

		if (false !== strpos($pattern, '/')) {
			return $this->ip_matches_cidr($ip, $pattern);
		}

		return false;
	}

	/**
	 * Match IPs against CIDR blocks.
	 *
	 * @param string $ip Client IP.
	 * @param string $cidr CIDR notation.
	 * @return bool
	 */
	private function ip_matches_cidr($ip, $cidr) {
		$parts = explode('/', $cidr, 2);

		if (2 !== count($parts)) {
			return false;
		}

		$subnet = inet_pton($parts[0]);
		$target = inet_pton($ip);
		$prefix = (int) $parts[1];

		if (false === $subnet || false === $target || strlen($subnet) !== strlen($target)) {
			return false;
		}

		$bytes = intdiv($prefix, 8);
		$bits  = $prefix % 8;

		if ($bytes > 0 && substr($target, 0, $bytes) !== substr($subnet, 0, $bytes)) {
			return false;
		}

		if (0 === $bits) {
			return true;
		}

		$mask = chr(((0xff << (8 - $bits)) & 0xff));

		return (ord($target[ $bytes ]) & ord($mask)) === (ord($subnet[ $bytes ]) & ord($mask));
	}

	/**
	 * Determine client IP from common proxy headers.
	 *
	 * @return string
	 */
	private function get_client_ip() {
		$remote_addr = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));

		if (! filter_var($remote_addr, FILTER_VALIDATE_IP) || ! $this->is_trusted_proxy($remote_addr)) {
			return filter_var($remote_addr, FILTER_VALIDATE_IP) ? $remote_addr : '';
		}

		$forwarded = trim((string) wp_unslash($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));

		if ('' === $forwarded) {
			return $remote_addr;
		}

		$chain = array_values(array_filter(array_map('trim', explode(',', $forwarded)), function ($address) {
			return (bool) filter_var($address, FILTER_VALIDATE_IP);
		}));
		$chain[] = $remote_addr;

		while (count($chain) > 1 && $this->is_trusted_proxy((string) end($chain))) {
			array_pop($chain);
		}

		$client_addr = (string) end($chain);

		return filter_var($client_addr, FILTER_VALIDATE_IP) ? $client_addr : $remote_addr;
	}

	/**
	 * Check whether an address belongs to a proxy explicitly trusted by the site.
	 * Configure with the `feuer_cookie_trusted_proxy_ips` filter. Exact IP addresses
	 * are required; forwarded headers are ignored unless REMOTE_ADDR is trusted.
	 *
	 * @param string $ip Proxy address.
	 * @return bool
	 */
	private function is_trusted_proxy($ip) {
		$trusted_proxies = apply_filters('feuer_cookie_trusted_proxy_ips', array());

		if (! is_array($trusted_proxies)) {
			return false;
		}

		$trusted_proxies = array_filter(array_map('trim', array_map('strval', $trusted_proxies)), function ($address) {
			return (bool) filter_var($address, FILTER_VALIDATE_IP);
		});

		return in_array($ip, $trusted_proxies, true);
	}

	/**
	 * Mask IPs by default.
	 *
	 * @param string $ip Client IP.
	 * @return string
	 */
	private function mask_ip($ip) {
		if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
			$parts    = explode('.', $ip);
			$parts[3] = '0';
			return implode('.', $parts);
		}

		if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
			$parts = explode(':', $ip);
			return implode(':', array_slice($parts, 0, 4)) . '::';
		}

		return '';
	}

	/**
	 * Hash an IP.
	 *
	 * @param string $ip Client IP.
	 * @return string
	 */
	private function hash_ip($ip) {
		return hash_hmac('sha256', $ip, wp_salt('auth'));
	}

	/**
	 * Resolve country and city from server-configured GeoIP variables only.
	 *
	 * @return array<string,string>
	 */
	private function get_request_location() {
		$country_code = strtoupper(sanitize_text_field(wp_unslash((string) ($_SERVER['GEOIP_COUNTRY_CODE'] ?? ''))));
		$city         = sanitize_text_field(wp_unslash((string) ($_SERVER['GEOIP_CITY'] ?? '')));

		if (! preg_match('/^[A-Z]{2}$/', $country_code)) {
			$country_code = '';
		}

		$city = substr($city, 0, 120);

		return array(
			'country_code' => $country_code,
			'country_name' => $this->country_name_from_code($country_code),
			'city'         => $city,
		);
	}

	/**
	 * Convert common country codes into readable labels.
	 *
	 * @param string $code ISO country code.
	 * @return string
	 */
	private function country_name_from_code($code) {
		$code = strtoupper(trim((string) $code));
		$names = array(
			'AT' => 'Oesterreich',
			'BE' => 'Belgien',
			'CH' => 'Schweiz',
			'DE' => 'Deutschland',
			'DK' => 'Daenemark',
			'ES' => 'Spanien',
			'FI' => 'Finnland',
			'FR' => 'Frankreich',
			'GB' => 'Vereinigtes Koenigreich',
			'IT' => 'Italien',
			'NL' => 'Niederlande',
			'NO' => 'Norwegen',
			'PL' => 'Polen',
			'SE' => 'Schweden',
			'TR' => 'Tuerkei',
			'UA' => 'Ukraine',
			'US' => 'USA',
		);

		return $names[ $code ] ?? $code;
	}

	/**
	 * Parse device, browser and OS from a user agent string.
	 *
	 * @param string $user_agent User agent.
	 * @return array<string,string>
	 */
	private function parse_client_profile($user_agent) {
		$user_agent = (string) $user_agent;
		$ua_lower   = strtolower($user_agent);
		$device     = 'Desktop';
		$browser    = 'Unbekannt';
		$os         = 'Unbekannt';

		if (false !== strpos($ua_lower, 'tablet') || false !== strpos($ua_lower, 'ipad')) {
			$device = 'Tablet';
		} elseif (false !== strpos($ua_lower, 'mobile') || false !== strpos($ua_lower, 'iphone') || false !== strpos($ua_lower, 'android')) {
			$device = 'Mobil';
		}

		if (false !== strpos($ua_lower, 'edg/')) {
			$browser = 'Edge';
		} elseif (false !== strpos($ua_lower, 'opr/') || false !== strpos($ua_lower, 'opera')) {
			$browser = 'Opera';
		} elseif (false !== strpos($ua_lower, 'firefox/')) {
			$browser = 'Firefox';
		} elseif (false !== strpos($ua_lower, 'chrome/') || false !== strpos($ua_lower, 'crios/')) {
			$browser = 'Chrome';
		} elseif (false !== strpos($ua_lower, 'safari/')) {
			$browser = 'Safari';
		}

		if (false !== strpos($ua_lower, 'windows')) {
			$os = 'Windows';
		} elseif (false !== strpos($ua_lower, 'android')) {
			$os = 'Android';
		} elseif (false !== strpos($ua_lower, 'iphone') || false !== strpos($ua_lower, 'ipad') || false !== strpos($ua_lower, 'ios')) {
			$os = 'iOS';
		} elseif (false !== strpos($ua_lower, 'mac os') || false !== strpos($ua_lower, 'macintosh')) {
			$os = 'macOS';
		} elseif (false !== strpos($ua_lower, 'linux')) {
			$os = 'Linux';
		}

		return array(
			'device_type'  => $device,
			'browser_name' => $browser,
			'os_name'      => $os,
		);
	}

	/**
	 * Simple robot detection by user agent.
	 *
	 * @param string $user_agent User agent string.
	 * @return bool
	 */
	private function is_robot($user_agent) {
		return (bool) preg_match('/bot|crawl|crawler|spider|slurp|preview|inspect|monitor|validator|fetch|facebookexternalhit|linkedinbot|embedly|discordbot|slackbot|telegrambot|whatsapp|applebot|yandex|bingpreview|mediapartners/i', $user_agent);
	}

	/**
	 * Detect whether the current frontend request comes from a robot.
	 *
	 * @return bool
	 */
	private function is_robot_request() {
		$user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '';

		return '' !== $user_agent && $this->is_robot($user_agent);
	}

	/**
	 * Build request context overrides from a frontend page URL.
	 *
	 * @param string $url Frontend page URL.
	 * @param string $page_title Document title.
	 * @param string $referrer_url Referrer URL.
	 * @return array<string,string>
	 */
	private function build_event_context_from_url($url, $page_title = '', $referrer_url = '') {
		$request_url = $this->sanitize_redirect_url($url, home_url('/'));

		return array(
			'request_url'   => $request_url,
			'request_path'  => $this->path_from_url($request_url),
			'page_title'    => sanitize_text_field((string) $page_title),
			'referrer_url'  => esc_url_raw((string) $referrer_url),
			'referrer_path' => $this->path_from_url((string) $referrer_url),
		);
	}

	/**
	 * Build current URL from request URI.
	 *
	 * @return string
	 */
	private function current_url() {
		$request_uri = isset($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : '/';
		return esc_url_raw(home_url($request_uri));
	}

	/**
	 * Return current request path.
	 *
	 * @return string
	 */
	private function current_path() {
		$request_uri = isset($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : '/';
		$path        = (string) wp_parse_url($request_uri, PHP_URL_PATH);
		return untrailingslashit($path) ? untrailingslashit($path) : '/';
	}

	/**
	 * Extract path from a URL.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private function path_from_url($url) {
		if (! $url) {
			return '';
		}

		$path = (string) wp_parse_url($url, PHP_URL_PATH);

		return untrailingslashit($path) ? untrailingslashit($path) : '/';
	}

	/**
	 * Extract internal path from a URL.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private function internal_path_from_url($url) {
		if (! $url) {
			return '';
		}

		$host      = (string) wp_parse_url($url, PHP_URL_HOST);
		$site_host = (string) wp_parse_url(home_url('/'), PHP_URL_HOST);

		if ($host && $site_host && $host !== $site_host) {
			return '';
		}

		return $this->path_from_url($url);
	}

	/**
	 * Determine whether a URL is external.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	private function is_external_url($url) {
		$host      = (string) wp_parse_url($url, PHP_URL_HOST);
		$site_host = (string) wp_parse_url(home_url('/'), PHP_URL_HOST);

		if (! $host) {
			return false;
		}

		return $host !== $site_host;
	}

	/**
	 * Validate redirect URL with fallback.
	 *
	 * @param string $url Candidate URL.
	 * @param string $fallback Fallback URL.
	 * @return string
	 */
	private function sanitize_redirect_url($url, $fallback) {
		$validated = wp_validate_redirect($url, $fallback);
		return $validated ? $validated : $fallback;
	}

	/**
	 * Limit allowed template identifiers.
	 *
	 * @param string $template Template identifier.
	 * @return string
	 */
	private function sanitize_template($template) {
		$allowed = array('feuerwand', 'kontrollkarte', 'sicherheitsleiste');
		return in_array($template, $allowed, true) ? $template : 'feuerwand';
	}

	/**
	 * Limit allowed banner layout identifiers.
	 *
	 * @param string $layout Banner layout identifier.
	 * @return string
	 */
	private function sanitize_banner_layout($layout) {
		$allowed = array_keys($this->get_banner_layout_options());
		if (in_array($layout, $allowed, true)) {
			return $layout;
		}

		// Older style identifiers end in the same visual variant name.
		$legacy_variants = array(
			'-dark'  => 'feuer-dark-bar',
			'-toast' => 'feuer-compact-toast',
			'-light' => 'feuer-light-card',
		);

		foreach ($legacy_variants as $suffix => $replacement) {
			if (substr($layout, -strlen($suffix)) === $suffix) {
				return $replacement;
			}
		}

		return 'bottom-right';
	}

	/**
	 * Validate the backdrop opacity percentage.
	 *
	 * @param mixed $value Submitted value.
	 * @return int
	 */
	private function sanitize_overlay_opacity($value) {
		return max(0, min(90, (int) $value));
	}

	/**
	 * Convert a percentage into a CSS-ready opacity string.
	 *
	 * @param int $value Percentage from 0 to 90.
	 * @return string
	 */
	private function get_overlay_opacity_css_value($value) {
		return number_format($this->sanitize_overlay_opacity($value) / 100, 2, '.', '');
	}


	/**
	 * Render overview page.
	 *
	 * @return void
	 */
	public function render_overview_page() {
		$settings = $this->get_settings();
		$analytics_active = defined('FEUER_ANALYTICS_VERSION');
		$analytics_sync   = $this->is_feuer_analytics_sync_enabled();
		$analytics_report = $analytics_sync ? $this->get_consent_report('feuer-analytics', 30) : array();

		$this->render_admin_header('Cookie-Uebersicht', 'Consent, Dienste und optional einwilligungsbasierte Reichweitenmessung.');
		?>
		<div class="feuer-cookie-grid feuer-cookie-grid--three">
			<div class="feuer-cookie-card"><p class="feuer-cookie-card__label">Modell</p><h3>Opt-in aktiv</h3><p>Optionale Dienste bleiben bis zu einer ausdruecklichen Auswahl blockiert.</p></div>
			<div class="feuer-cookie-card"><p class="feuer-cookie-card__label">Consent-Revision</p><h3><?php echo esc_html((string) $settings['consent_revision']); ?></h3><p>Eine neue Revision fordert Besucher erneut zur Auswahl auf.</p></div>
			<div class="feuer-cookie-card"><p class="feuer-cookie-card__label">GTM Consent Mode v2</p><h3><?php echo ! empty($settings['gtm_consent_mode']) ? 'Aktiv' : 'Aus'; ?></h3><p>Google-Speicher bleibt standardmaessig verweigert.</p></div>
		</div>
		<div class="feuer-cookie-panel"><h2>Naechste Schritte</h2><p><a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=feuer-cookie-analytics')); ?>">Statistik öffnen</a> <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=feuer-cookie-settings&tab=banner')); ?>">Banner pruefen</a> <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=feuer-cookie-settings&tab=services')); ?>">Dienste verwalten</a> <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=feuer-cookie-diagnostics')); ?>">Kompatibilitaet pruefen</a></p></div>
		<div class="feuer-cookie-panel">
			<h2>Feuer Analytics</h2>
			<?php if (! $analytics_sync) : ?>
				<p>Die Synchronisierung ist ausgeschaltet. Feuer Analytics bleibt über Feuer-Cookie gesperrt.</p>
				<p><a class="button" href="<?php echo esc_url($this->get_settings_page_url('privacy')); ?>">Synchronisierung einrichten</a></p>
			<?php elseif (! $analytics_active) : ?>
				<p>Die Synchronisierung ist aktiviert, aber Feuer Analytics ist nicht aktiv. Es werden keine Besuchsdaten durch dieses Plugin erfasst.</p>
			<?php else : ?>
				<p><strong>Synchronisiert und einwilligungsbasiert.</strong> Feuer Analytics erhält ausschließlich die aktuelle Freigabe für den Dienst „Feuer Analytics“.</p>
				<p>Letzte 30 Tage: <?php echo esc_html(number_format_i18n((int) ($analytics_report['opt_in_events'] ?? 0))); ?> explizite Freigaben, <?php echo esc_html(number_format_i18n((int) ($analytics_report['optional_consents'] ?? 0))); ?> optionale Einwilligungen.</p>
				<p><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=wps_overview_page')); ?>">Gemeinsamen Bericht öffnen</a> <a class="button" href="<?php echo esc_url($this->get_settings_page_url('privacy')); ?>">Synchronisierung verwalten</a></p>
			<?php endif; ?>
		</div>
		<?php
		$this->render_admin_footer();
		return;

		global $wpdb;

		$this->render_admin_header('Uebersicht', 'Zentrale Kennzahlen, Besuchstrends und letzte Consent-Ereignisse.');
		$activity_since = wp_date('Y-m-d H:i:s', current_time('timestamp') - (14 * DAY_IN_SECONDS));
		$events_table = $this->table_sql('events');
		$daily_stats_table = $this->table_sql('daily_stats');

		$totals = $wpdb->get_row(
			"SELECT
				COALESCE(SUM(page_views), 0) AS page_views,
				COALESCE(SUM(unique_visitors), 0) AS unique_visitors,
				COALESCE(SUM(consents_accept), 0) AS consents_accept,
				COALESCE(SUM(consents_decline), 0) AS consents_decline,
				COALESCE(SUM(blocked_requests), 0) AS blocked_requests
			FROM {$daily_stats_table}",
			ARRAY_A
		);
		$recent_days = $wpdb->get_results(
			"SELECT * FROM {$daily_stats_table} ORDER BY day DESC LIMIT 14",
			ARRAY_A
		);

		$hourly_series = $this->get_hourly_page_view_series(48);
		$top_ips       = $this->get_top_ip_rows(14, 8);
		$engagement_pages = $this->get_page_engagement_rows(14, 8);
		$top_locations = $this->get_top_location_rows(14, 8);
		$top_countries = $this->get_top_country_rows(30, 24);
		$screen_sizes  = $this->get_screen_dimension_rows(30, 8);
		$period_summaries = $this->get_traffic_period_summaries();
		$referrer_sources = $this->get_external_referrer_source_rows(30, 12);
		$top_devices   = $this->get_top_dimension_rows('device_type', 14, 8);
		$top_browsers  = $this->get_top_dimension_rows('browser_name', 14, 8);
		$top_os        = $this->get_top_dimension_rows('os_name', 14, 8);
		$consent_devices  = $this->get_consent_dimension_rows('device_type', 30, 8);
		$consent_browsers = $this->get_consent_dimension_rows('browser_name', 30, 8);

		$top_pages = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT request_path, COUNT(*) AS hits
				FROM {$events_table}
				WHERE request_type = 'page_view' AND is_robot = 0 AND created_at >= %s
				GROUP BY request_path
				ORDER BY hits DESC
				LIMIT 8",
				$activity_since
			),
			ARRAY_A
		);

		$transitions = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT referrer_path, request_path, COUNT(*) AS hits
				FROM {$events_table}
				WHERE request_type = 'page_view' AND is_robot = 0 AND referrer_path <> '' AND referrer_path IS NOT NULL AND created_at >= %s
				GROUP BY referrer_path, request_path
				ORDER BY hits DESC
				LIMIT 8",
				$activity_since
			),
			ARRAY_A
		);

		$recent_events = $wpdb->get_results(
			"SELECT * FROM {$events_table} ORDER BY created_at DESC LIMIT 12",
			ARRAY_A
		);

		$report = get_option($this->diagnostics_key, array());
		$day_offset = isset($_GET['feuer_cookie_day_offset']) ? absint(wp_unslash($_GET['feuer_cookie_day_offset'])) : 0;
		$day_offset = max(0, min(30, $day_offset));
		$selected_day_ts = current_time('timestamp') - ($day_offset * DAY_IN_SECONDS);
		$selected_day = wp_date('Y-m-d', $selected_day_ts);
		$daily_series = $this->get_daily_page_view_series(30);
		$selected_day_stats = $this->get_daily_stats_for_day($selected_day);
		$selected_day_pages = $this->get_top_pages_for_day($selected_day, 8);
		$selected_day_peak = $this->get_peak_chart_point($daily_series);
		$hourly_total = 0;
		$last_hour    = ! empty($hourly_series) ? end($hourly_series) : array('label' => '-', 'value' => 0);
		$peak_hour    = $this->get_peak_chart_point($hourly_series);
		$ip_storage_label = ! empty($this->get_settings()['store_full_ip']) ? 'Voll-IP' : 'Maskiert';
		$top_engagement = ! empty($engagement_pages) ? $engagement_pages[0] : array(
			'request_path'        => '-',
			'total_active'        => 0,
		);

		foreach ($hourly_series as $point) {
			$hourly_total += (int) ($point['value'] ?? 0);
		}

		?>
		<div class="feuer-cookie-grid feuer-cookie-grid--cards">
			<?php $this->render_stat_card('Gesamtaufrufe', (int) ($totals['page_views'] ?? 0), 'Summe aller protokollierten Seitenaufrufe.'); ?>
			<?php $this->render_stat_card('Sitzungen', (int) ($totals['unique_visitors'] ?? 0), 'Eindeutige Besuchssitzungen auf Tagesbasis.'); ?>
			<?php $this->render_stat_card('Optionale Freigaben', (int) ($totals['consents_accept'] ?? 0), 'Erfasste Freigaben optionaler Dienste seit Aktivierung.'); ?>
			<?php $this->render_stat_card('Nur erforderliche Dienste', (int) ($totals['consents_decline'] ?? 0), 'Besuche mit ausschliesslich erforderlichen Diensten.'); ?>
			<?php $this->render_stat_card('Geblockte Anfragen', (int) ($totals['blocked_requests'] ?? 0), 'Anfragen von gesperrten IPs.'); ?>
		</div>

		<div class="feuer-cookie-period-grid" aria-label="Besuchszeiträume">
			<?php foreach ($period_summaries as $period) : ?>
				<div class="feuer-cookie-period-card">
					<span><?php echo esc_html((string) $period['label']); ?></span>
					<strong><?php echo esc_html(number_format_i18n((int) $period['views'])); ?></strong>
					<small><?php echo esc_html(number_format_i18n((int) $period['visitors'])); ?> Besucher · <?php echo esc_html((string) $period['days']); ?> Tage</small>
				</div>
			<?php endforeach; ?>
		</div>

		<div class="feuer-cookie-panel feuer-cookie-panel--chart feuer-cookie-daily-dashboard">
			<div class="feuer-cookie-chart__header">
				<div>
					<p class="feuer-cookie-chart__eyebrow">Tagesansicht</p>
					<h2>Besuche nach Tagen</h2>
					<p class="feuer-cookie-chart__subline">Letzte 30 Tage, mit Tagesauswahl bis maximal 30 Tage zurueck.</p>
				</div>
				<div class="feuer-cookie-day-nav" aria-label="Tagesstatistik wechseln">
					<a class="button button-secondary<?php echo 0 === $day_offset ? ' disabled' : ''; ?>" href="<?php echo esc_url($this->get_overview_day_url(max(0, $day_offset - 1))); ?>">Neuer</a>
					<a class="button button-secondary" href="<?php echo esc_url($this->get_overview_day_url(0)); ?>">Heute</a>
					<a class="button button-secondary<?php echo $day_offset >= 30 ? ' disabled' : ''; ?>" href="<?php echo esc_url($this->get_overview_day_url(min(30, $day_offset + 1))); ?>">Aelter</a>
				</div>
			</div>
			<div class="feuer-cookie-daily-summary">
				<?php $this->render_chart_meta_item('Ausgewaehlter Tag', wp_date('d.m.Y', $selected_day_ts)); ?>
				<?php $this->render_chart_meta_item('Aufrufe', number_format_i18n((int) ($selected_day_stats['page_views'] ?? 0))); ?>
				<?php $this->render_chart_meta_item('Sitzungen', number_format_i18n((int) ($selected_day_stats['unique_visitors'] ?? 0))); ?>
				<?php $this->render_chart_meta_item('30-Tage Peak', number_format_i18n((int) ($selected_day_peak['value'] ?? 0)), (string) ($selected_day_peak['label'] ?? '-')); ?>
			</div>
			<?php $this->render_daily_area_chart($daily_series, $selected_day); ?>
		</div>

		<div class="feuer-cookie-grid feuer-cookie-grid--two">
			<div class="feuer-cookie-panel feuer-cookie-panel--chart">
				<div class="feuer-cookie-chart__header">
					<div>
						<p class="feuer-cookie-chart__eyebrow">Live-Traffic</p>
						<h2>Besuche nach Datum und Uhrzeit</h2>
						<p class="feuer-cookie-chart__subline">Letzte 48 Stunden, nur echte Seitenaufrufe ohne Bots.</p>
					</div>
					<div class="feuer-cookie-chart__meta">
						<?php $this->render_chart_meta_item('48h Summe', number_format_i18n($hourly_total)); ?>
						<?php $this->render_chart_meta_item('Peak-Stunde', number_format_i18n((int) ($peak_hour['value'] ?? 0)), (string) ($peak_hour['label'] ?? '-')); ?>
						<?php $this->render_chart_meta_item('Letzte Stunde', number_format_i18n((int) ($last_hour['value'] ?? 0)), (string) ($last_hour['label'] ?? '-')); ?>
					</div>
				</div>
				<?php $this->render_hourly_area_chart($hourly_series); ?>
			</div>

			<div class="feuer-cookie-panel feuer-cookie-panel--chart">
				<div class="feuer-cookie-chart__header">
					<div>
						<p class="feuer-cookie-chart__eyebrow">Seitenverteilung</p>
						<h2>Meistbesuchte Seiten</h2>
						<p class="feuer-cookie-chart__subline">Top 8 Seiten innerhalb der letzten 14 Tage.</p>
					</div>
					<div class="feuer-cookie-chart__meta">
						<?php $this->render_chart_meta_item('Seiten im Ranking', number_format_i18n(count($top_pages))); ?>
						<?php $this->render_chart_meta_item('Top-Hits', number_format_i18n((int) ($top_pages[0]['hits'] ?? 0))); ?>
						<?php $this->render_chart_meta_item('Fenster', '14 Tage'); ?>
					</div>
				</div>
				<?php $this->render_page_bar_chart($top_pages); ?>
			</div>
		</div>

		<div class="feuer-cookie-panel feuer-cookie-panel--chart">
			<div class="feuer-cookie-chart__header">
				<div>
					<p class="feuer-cookie-chart__eyebrow">Akquisition</p>
					<h2>Externe Quellen und Suchmaschinen</h2>
					<p class="feuer-cookie-chart__subline">Nur externe Verweise der letzten 30 Tage. Suchmaschinen werden separat markiert; direkte und interne Aufrufe sind ausgeschlossen.</p>
				</div>
				<div class="feuer-cookie-chart__meta">
					<?php $this->render_chart_meta_item('Quellen', number_format_i18n(count($referrer_sources))); ?>
					<?php $this->render_chart_meta_item('Top-Quelle', number_format_i18n((int) ($referrer_sources[0]['hits'] ?? 0)), (string) ($referrer_sources[0]['label'] ?? '-')); ?>
				</div>
			</div>
			<?php $this->render_referrer_source_chart($referrer_sources); ?>
		</div>

		<div class="feuer-cookie-grid feuer-cookie-grid--two">
			<div class="feuer-cookie-panel feuer-cookie-panel--chart">
				<div class="feuer-cookie-chart__header"><div><p class="feuer-cookie-chart__eyebrow">Geo</p><h2>Laender mit Besuchern</h2><p class="feuer-cookie-chart__subline">Farben zeigen die relative Anzahl der Aufrufe in den letzten 30 Tagen.</p></div></div>
				<?php $this->render_country_coverage($top_countries); ?>
			</div>
			<div class="feuer-cookie-panel feuer-cookie-panel--chart">
				<div class="feuer-cookie-chart__header"><div><p class="feuer-cookie-chart__eyebrow">Displays</p><h2>Bildschirm- und Viewportgroessen</h2><p class="feuer-cookie-chart__subline">Nur aus aktiv freigegebenem Engagement-Tracking, letzte 30 Tage.</p></div></div>
				<?php $this->render_generic_bar_chart($screen_sizes); ?>
			</div>
		</div>

		<div class="feuer-cookie-grid feuer-cookie-grid--two">
			<div class="feuer-cookie-panel feuer-cookie-panel--chart">
				<div class="feuer-cookie-chart__header">
					<div>
						<p class="feuer-cookie-chart__eyebrow">IP-Ranking</p>
						<h2>Aktive Besucher-IP-Adressen</h2>
						<p class="feuer-cookie-chart__subline">Top 8 IP-Eintraege der letzten 14 Tage. Standardmaessig maskiert, optional vollstaendig.</p>
					</div>
					<div class="feuer-cookie-chart__meta">
						<?php $this->render_chart_meta_item('IPs im Ranking', number_format_i18n(count($top_ips))); ?>
						<?php $this->render_chart_meta_item('Top-Hits', number_format_i18n((int) ($top_ips[0]['hits'] ?? 0))); ?>
						<?php $this->render_chart_meta_item('Speicherung', $ip_storage_label); ?>
					</div>
				</div>
				<?php $this->render_ip_bar_chart($top_ips); ?>
			</div>

			<div class="feuer-cookie-panel feuer-cookie-panel--chart">
				<div class="feuer-cookie-chart__header">
					<div>
						<p class="feuer-cookie-chart__eyebrow">Aufenthaltsdauer</p>
						<h2>Durchschnittliche aktive Zeit je Seite</h2>
						<p class="feuer-cookie-chart__subline">Summierte aktive Betrachtungszeit nach expliziter Statistik-Freigabe.</p>
					</div>
					<div class="feuer-cookie-chart__meta">
						<?php $this->render_chart_meta_item('Seiten mit Tracking', number_format_i18n(count($engagement_pages))); ?>
						<?php $this->render_chart_meta_item('Top-Gesamtzeit', $this->format_seconds_compact((int) ($top_engagement['total_active'] ?? 0)), $this->format_admin_path_label((string) ($top_engagement['request_path'] ?? '-'))); ?>
						<?php $this->render_chart_meta_item('Messart', 'Summe aktiv'); ?>
					</div>
				</div>
				<?php $this->render_duration_bar_chart($engagement_pages); ?>
			</div>
		</div>

		<div class="feuer-cookie-grid feuer-cookie-grid--two">
			<div class="feuer-cookie-panel feuer-cookie-panel--chart">
				<div class="feuer-cookie-chart__header">
					<div>
						<p class="feuer-cookie-chart__eyebrow">Regionen</p>
						<h2>Staedte und Laender</h2>
						<p class="feuer-cookie-chart__subline">Aus CDN-/Proxy-Geo-Headern, sofern der Server diese bereitstellt.</p>
					</div>
					<div class="feuer-cookie-chart__meta">
						<?php $this->render_chart_meta_item('Orte im Ranking', number_format_i18n(count($top_locations))); ?>
						<?php $this->render_chart_meta_item('Top-Ort', number_format_i18n((int) ($top_locations[0]['hits'] ?? 0)), (string) ($top_locations[0]['label'] ?? '-')); ?>
					</div>
				</div>
				<?php $this->render_generic_bar_chart($top_locations); ?>
			</div>

			<div class="feuer-cookie-panel feuer-cookie-panel--chart">
				<div class="feuer-cookie-chart__header">
					<div>
						<p class="feuer-cookie-chart__eyebrow">Clients</p>
						<h2>Geraete</h2>
						<p class="feuer-cookie-chart__subline">Desktop, Mobil und Tablet aus dem User-Agent.</p>
					</div>
					<div class="feuer-cookie-chart__meta">
						<?php $this->render_chart_meta_item('Geraetetypen', number_format_i18n(count($top_devices))); ?>
						<?php $this->render_chart_meta_item('Top-Geraet', number_format_i18n((int) ($top_devices[0]['hits'] ?? 0)), (string) ($top_devices[0]['label'] ?? '-')); ?>
					</div>
				</div>
				<?php $this->render_generic_bar_chart($top_devices); ?>
			</div>
		</div>

		<div class="feuer-cookie-grid feuer-cookie-grid--two">
			<div class="feuer-cookie-panel feuer-cookie-panel--chart">
				<div class="feuer-cookie-chart__header">
					<div>
						<p class="feuer-cookie-chart__eyebrow">Browser</p>
						<h2>Browser-Verteilung</h2>
						<p class="feuer-cookie-chart__subline">Top Browser der letzten 14 Tage ohne Bots.</p>
					</div>
					<div class="feuer-cookie-chart__meta">
						<?php $this->render_chart_meta_item('Browser', number_format_i18n(count($top_browsers))); ?>
						<?php $this->render_chart_meta_item('Top-Browser', number_format_i18n((int) ($top_browsers[0]['hits'] ?? 0)), (string) ($top_browsers[0]['label'] ?? '-')); ?>
					</div>
				</div>
				<?php $this->render_generic_bar_chart($top_browsers); ?>
			</div>

			<div class="feuer-cookie-panel feuer-cookie-panel--chart">
				<div class="feuer-cookie-chart__header">
					<div>
						<p class="feuer-cookie-chart__eyebrow">Systeme</p>
						<h2>Betriebssysteme</h2>
						<p class="feuer-cookie-chart__subline">Top Betriebssysteme der letzten 14 Tage ohne Bots.</p>
					</div>
					<div class="feuer-cookie-chart__meta">
						<?php $this->render_chart_meta_item('Systeme', number_format_i18n(count($top_os))); ?>
						<?php $this->render_chart_meta_item('Top-System', number_format_i18n((int) ($top_os[0]['hits'] ?? 0)), (string) ($top_os[0]['label'] ?? '-')); ?>
					</div>
				</div>
				<?php $this->render_generic_bar_chart($top_os); ?>
			</div>
		</div>

		<div class="feuer-cookie-grid feuer-cookie-grid--two">
			<div class="feuer-cookie-panel feuer-cookie-panel--chart">
				<div class="feuer-cookie-chart__header">
					<div>
						<p class="feuer-cookie-chart__eyebrow">Cookie-Bestaetigungen</p>
						<h2>Geraete mit gespeicherter Auswahl</h2>
						<p class="feuer-cookie-chart__subline">Consent-Entscheidungen der letzten 30 Tage nach Geraetetyp.</p>
					</div>
					<div class="feuer-cookie-chart__meta">
						<?php $this->render_chart_meta_item('Geraetetypen', number_format_i18n(count($consent_devices))); ?>
						<?php $this->render_chart_meta_item('Top-Geraet', number_format_i18n((int) ($consent_devices[0]['hits'] ?? 0)), (string) ($consent_devices[0]['label'] ?? '-')); ?>
					</div>
				</div>
				<?php $this->render_generic_bar_chart($consent_devices); ?>
			</div>

			<div class="feuer-cookie-panel feuer-cookie-panel--chart">
				<div class="feuer-cookie-chart__header">
					<div>
						<p class="feuer-cookie-chart__eyebrow">Cookie-Bestaetigungen</p>
						<h2>Browser mit gespeicherter Auswahl</h2>
						<p class="feuer-cookie-chart__subline">Consent-Entscheidungen der letzten 30 Tage nach Browser.</p>
					</div>
					<div class="feuer-cookie-chart__meta">
						<?php $this->render_chart_meta_item('Browser', number_format_i18n(count($consent_browsers))); ?>
						<?php $this->render_chart_meta_item('Top-Browser', number_format_i18n((int) ($consent_browsers[0]['hits'] ?? 0)), (string) ($consent_browsers[0]['label'] ?? '-')); ?>
					</div>
				</div>
				<?php $this->render_generic_bar_chart($consent_browsers); ?>
			</div>
		</div>

		<div class="feuer-cookie-grid feuer-cookie-grid--two">
			<div class="feuer-cookie-panel">
				<h2>Tagesstatistik der letzten 14 Tage</h2>
				<table class="widefat striped">
					<thead>
						<tr>
							<th>Tag</th>
							<th>Aufrufe</th>
							<th>Sitzungen</th>
							<th>Auswahl</th>
							<th>Nur erforderlich</th>
							<th>Blockiert</th>
						</tr>
					</thead>
					<tbody>
						<?php if ($recent_days) : ?>
							<?php foreach ($recent_days as $day) : ?>
								<tr>
									<td><?php echo esc_html($day['day']); ?></td>
									<td><?php echo esc_html((string) $day['page_views']); ?></td>
									<td><?php echo esc_html((string) $day['unique_visitors']); ?></td>
									<td><?php echo esc_html((string) $day['consents_accept']); ?></td>
									<td><?php echo esc_html((string) $day['consents_decline']); ?></td>
									<td><?php echo esc_html((string) $day['blocked_requests']); ?></td>
								</tr>
							<?php endforeach; ?>
						<?php else : ?>
							<tr><td colspan="6">Noch keine Tageswerte vorhanden.</td></tr>
						<?php endif; ?>
					</tbody>
				</table>
			</div>

			<div class="feuer-cookie-panel">
				<h2>Top-Seiten am <?php echo esc_html(wp_date('d.m.Y', $selected_day_ts)); ?></h2>
				<table class="widefat striped">
					<thead>
						<tr>
							<th>Pfad</th>
							<th>Aufrufe</th>
						</tr>
					</thead>
					<tbody>
						<?php if ($selected_day_pages) : ?>
							<?php foreach ($selected_day_pages as $page) : ?>
								<tr>
									<td><?php echo esc_html($page['request_path']); ?></td>
									<td><?php echo esc_html((string) $page['hits']); ?></td>
								</tr>
							<?php endforeach; ?>
						<?php else : ?>
							<tr><td colspan="2">Keine Seitenaufrufe fuer diesen Tag vorhanden.</td></tr>
						<?php endif; ?>
					</tbody>
				</table>
			</div>
		</div>

		<div class="feuer-cookie-grid feuer-cookie-grid--two">
			<div class="feuer-cookie-panel">
				<h2>Autodiagnose</h2>
				<?php if (! empty($report['generated_at'])) : ?>
					<p><strong>Letzter Scan:</strong> <?php echo esc_html((string) $report['generated_at']); ?></p>
					<p><strong>Modus:</strong> Nur aktive Request-Kontexte. Kommentare, normale Text-Links und rein informative URLs bleiben unberuecksichtigt.</p>
					<p><strong>Treffer:</strong> <?php echo esc_html(number_format_i18n((int) ($report['record_count'] ?? 0))); ?></p>
					<p><strong>Erkannte Dienste:</strong> <?php echo esc_html(implode(', ', (array) ($report['service_summary'] ?? array()))); ?></p>
					<?php if (! empty($report['notices'])) : ?>
						<ul class="feuer-cookie-notes">
							<?php foreach (array_slice((array) $report['notices'], 0, 5) as $notice) : ?>
								<li><?php echo esc_html($notice); ?></li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				<?php else : ?>
					<p>Noch keine Diagnose vorhanden. Bitte einen Scan starten.</p>
				<?php endif; ?>
				<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
					<?php wp_nonce_field('feuer_cookie_run_diagnostics'); ?>
					<input type="hidden" name="action" value="feuer_cookie_run_diagnostics">
					<button type="submit" class="button button-primary">Diagnose jetzt ausfuehren</button>
				</form>
			</div>
		</div>

		<div class="feuer-cookie-grid feuer-cookie-grid--two">
			<div class="feuer-cookie-panel">
				<h2>Bindung pro Seite (letzte 14 Tage)</h2>
				<table class="widefat striped">
					<thead>
						<tr>
							<th>Pfad</th>
							<th>Aufrufe</th>
							<th>Gesamt aktiv</th>
							<th>Gesamtzeit</th>
							<th>Ø aktiv</th>
							<th>Scroll</th>
						</tr>
					</thead>
					<tbody>
						<?php if ($engagement_pages) : ?>
							<?php foreach ($engagement_pages as $page) : ?>
								<tr>
									<td><?php echo esc_html($page['request_path']); ?></td>
									<td><?php echo esc_html((string) $page['views']); ?></td>
									<td><?php echo esc_html($this->format_seconds_compact((int) ($page['total_active'] ?? 0))); ?></td>
									<td><?php echo esc_html($this->format_seconds_compact((int) ($page['total_duration'] ?? 0))); ?></td>
									<td><?php echo esc_html($this->format_seconds_compact((int) ($page['avg_active_seconds'] ?? 0))); ?></td>
									<td><?php echo esc_html((string) ($page['avg_scroll_depth'] ?? 0)); ?>%</td>
								</tr>
							<?php endforeach; ?>
						<?php else : ?>
							<tr><td colspan="6">Noch keine Engagement-Daten protokolliert.</td></tr>
						<?php endif; ?>
					</tbody>
				</table>
			</div>

			<div class="feuer-cookie-panel">
				<h2>Seitenuebergaenge (letzte 14 Tage)</h2>
				<table class="widefat striped">
					<thead>
						<tr>
							<th>Von</th>
							<th>Zu</th>
							<th>Anzahl</th>
						</tr>
					</thead>
					<tbody>
						<?php if ($transitions) : ?>
							<?php foreach ($transitions as $transition) : ?>
								<tr>
									<td><?php echo esc_html($transition['referrer_path']); ?></td>
									<td><?php echo esc_html($transition['request_path']); ?></td>
									<td><?php echo esc_html((string) $transition['hits']); ?></td>
								</tr>
							<?php endforeach; ?>
						<?php else : ?>
							<tr><td colspan="3">Noch keine Seitenuebergaenge protokolliert.</td></tr>
						<?php endif; ?>
					</tbody>
				</table>
			</div>
		</div>

		<div class="feuer-cookie-panel">
			<h2>Letzte Ereignisse</h2>
			<?php $this->render_events_table($recent_events); ?>
		</div>
		<?php

		$this->render_admin_footer();
	}

	/**
	 * Render aggregate, consent-based website analytics.
	 *
	 * @return void
	 */
	public function render_analytics_page() {
		global $wpdb;

		$days = isset($_GET['days']) ? absint(wp_unslash($_GET['days'])) : 30;
		$days = in_array($days, array(7, 30, 90), true) ? $days : 30;
		$since = wp_date('Y-m-d H:i:s', current_time('timestamp') - ($days * DAY_IN_SECONDS));
		$table = $this->table_sql('events');
		$totals = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS page_views, COUNT(DISTINCT session_token) AS visitors,
				COUNT(DISTINCT request_path) AS pages, COUNT(DISTINCT CASE WHEN referrer_path <> '' THEN session_token END) AS referred_visitors
				FROM {$table} WHERE request_type = 'page_view' AND created_at >= %s",
				$since
			),
			ARRAY_A
		);
		$previous_since = wp_date('Y-m-d H:i:s', current_time('timestamp') - ((2 * $days) * DAY_IN_SECONDS));
		$previous_totals = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS page_views, COUNT(DISTINCT session_token) AS visitors
				FROM {$table} WHERE request_type = 'page_view' AND created_at >= %s AND created_at < %s",
				$previous_since,
				$since
			),
			ARRAY_A
		);
		$daily = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE(created_at) AS day, COUNT(*) AS views, COUNT(DISTINCT session_token) AS visitors
				FROM {$table} WHERE request_type = 'page_view' AND created_at >= %s
				GROUP BY DATE(created_at) ORDER BY day ASC",
				$since
			),
			ARRAY_A
		);
		$top_pages = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT request_path AS label, COUNT(*) AS views, COUNT(DISTINCT session_token) AS visitors
				FROM {$table} WHERE request_type = 'page_view' AND created_at >= %s
				GROUP BY request_path ORDER BY views DESC LIMIT 10",
				$since
			),
			ARRAY_A
		);
		$sources = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT referrer_path AS label, COUNT(*) AS views, COUNT(DISTINCT session_token) AS visitors
				FROM {$table} WHERE request_type = 'page_view' AND referrer_path <> '' AND created_at >= %s
				GROUP BY referrer_path ORDER BY visitors DESC LIMIT 10",
			$since
			),
			ARRAY_A
		);
		$devices = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT device_type AS label, COUNT(*) AS views, COUNT(DISTINCT session_token) AS visitors
				FROM {$table} WHERE request_type = 'page_view' AND device_type <> '' AND created_at >= %s
				GROUP BY device_type ORDER BY visitors DESC",
			$since
			),
			ARRAY_A
		);
		$browsers = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT browser_name AS label, COUNT(*) AS views, COUNT(DISTINCT session_token) AS visitors
				FROM {$table} WHERE request_type = 'page_view' AND browser_name <> '' AND created_at >= %s
				GROUP BY browser_name ORDER BY visitors DESC",
				$since
			),
			ARRAY_A
		);
		$systems = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT os_name AS label, COUNT(*) AS views, COUNT(DISTINCT session_token) AS visitors
				FROM {$table} WHERE request_type = 'page_view' AND os_name <> '' AND created_at >= %s
				GROUP BY os_name ORDER BY visitors DESC",
				$since
			),
			ARRAY_A
		);
		$export_url = wp_nonce_url(
			add_query_arg(array('action' => 'feuer_cookie_export_analytics', 'days' => $days), admin_url('admin-post.php')),
			'feuer_cookie_export_analytics'
		);

		$this->render_admin_header('Statistik', 'Einwilligungsbasierte Reichweitenmessung mit pseudonymisierten Sitzungen und aggregierten Berichten.');
		?>
		<div class="feuer-cookie-panel feuer-cookie-analytics-toolbar">
			<div>
				<strong>Zeitraum</strong>
				<span>Die Erfassung läuft nur nach Freigabe von „Feuer-Cookie Statistik“.</span>
			</div>
			<nav aria-label="Statistikzeitraum">
				<?php foreach (array(7 => '7 Tage', 30 => '30 Tage', 90 => '90 Tage') as $period => $label) : ?>
					<a class="button <?php echo $days === $period ? 'button-primary' : 'button-secondary'; ?>" href="<?php echo esc_url(add_query_arg(array('page' => 'feuer-cookie-analytics', 'days' => $period), admin_url('admin.php'))); ?>"><?php echo esc_html($label); ?></a>
				<?php endforeach; ?>
				<a class="button button-secondary" href="<?php echo esc_url($export_url); ?>">CSV herunterladen</a>
			</nav>
		</div>

		<div class="feuer-cookie-grid feuer-cookie-grid--cards feuer-cookie-analytics-kpis">
			<?php $this->render_stat_card('Seitenaufrufe', number_format_i18n((int) ($totals['page_views'] ?? 0)), $this->get_analytics_delta_hint((int) ($totals['page_views'] ?? 0), (int) ($previous_totals['page_views'] ?? 0))); ?>
			<?php $this->render_stat_card('Besucher-Sitzungen', number_format_i18n((int) ($totals['visitors'] ?? 0)), '30-Minuten-Sitzungen · ' . $this->get_analytics_delta_hint((int) ($totals['visitors'] ?? 0), (int) ($previous_totals['visitors'] ?? 0))); ?>
			<?php $this->render_stat_card('Seiten', number_format_i18n((int) ($totals['pages'] ?? 0)), 'Unterschiedliche Seitenpfade.'); ?>
			<?php $this->render_stat_card('Mit Herkunft', number_format_i18n((int) ($totals['referred_visitors'] ?? 0)), 'Sitzungen mit externer Herkunftsdomain.'); ?>
		</div>

		<?php if (empty($totals['page_views'])) : ?>
			<div class="feuer-cookie-panel feuer-cookie-analytics-empty">
				<h2>Noch keine Statistikdaten</h2>
				<p>Besucher werden erst gezählt, wenn sie im Cookie-Fenster „Feuer-Cookie Statistik“ ausdrücklich freigeben. Die Freigabe lässt sich jederzeit über das Cookie-Symbol ändern.</p>
				<a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=feuer-cookie-settings&tab=services')); ?>">Statistik-Dienst prüfen</a>
			</div>
		<?php else : ?>
			<div class="feuer-cookie-grid feuer-cookie-grid--two">
				<?php $this->render_analytics_trend($daily); ?>
				<?php $this->render_analytics_breakdown('Beliebte Seiten', $top_pages, 'label', 'views', 'visitors'); ?>
				<?php $this->render_analytics_breakdown('Externe Herkunftsdomains', $sources, 'label', 'visitors'); ?>
				<?php $this->render_analytics_breakdown('Geräte', $devices, 'label', 'visitors'); ?>
				<?php $this->render_analytics_breakdown('Browser', $browsers, 'label', 'visitors'); ?>
				<?php $this->render_analytics_breakdown('Betriebssysteme', $systems, 'label', 'visitors'); ?>
			</div>
		<?php endif; ?>

		<div class="feuer-cookie-panel feuer-cookie-analytics-privacy">
			<h2>Datensparsame Erfassung</h2>
			<ul class="feuer-cookie-notes">
				<li>Keine Aufzeichnung vor ausdrücklicher Statistik-Freigabe; Widerruf stoppt die Erfassung sofort.</li>
				<li>Eingeloggte Nutzer und erkannte Bots werden nicht als Besucher gezählt.</li>
				<li>Keine IP-Adresse, vollständige URL, Query-Parameter oder rohen Browser-Kennungen in Statistik-Ereignissen.</li>
				<li>Besuchersitzungen werden über ein zufälliges, serverseitig gehashtes 30-Minuten-Kennzeichen gezählt.</li>
				<li>Rohdaten werden nach 90 Tagen automatisch gelöscht.</li>
			</ul>
		</div>
		<?php
		$this->render_admin_footer();
	}

	/**
	 * Describe a current metric against the preceding equal-length period.
	 *
	 * @param int $current Current metric.
	 * @param int $previous Previous period metric.
	 * @return string
	 */
	private function get_analytics_delta_hint($current, $previous) {
		if (0 === $previous) {
			return $current > 0 ? 'Neu; kein vorheriger Vergleichswert.' : 'Im Vergleichszeitraum ebenfalls keine Werte.';
		}

		$change = round((($current - $previous) / $previous) * 100, 1);
		$sign   = $change > 0 ? '+' : '';

		return $sign . number_format_i18n($change, 1) . '% gegenüber dem Vorzeitraum.';
	}

	/**
	 * Render daily page views as a responsive SVG trend.
	 *
	 * @param array<int,array<string,mixed>> $rows Daily aggregates.
	 * @return void
	 */
	private function render_analytics_trend($rows) {
		$series = array();

		foreach ((array) $rows as $row) {
			$day = (string) ($row['day'] ?? '');
			$series[] = array(
				'value'  => (int) ($row['views'] ?? 0),
				'label'  => $day ? wp_date('d.m', strtotime($day)) : '',
				'bucket' => $day,
			);
		}
		?>
		<div class="feuer-cookie-panel feuer-cookie-panel--chart">
			<div class="feuer-cookie-chart__header">
				<div><p class="feuer-cookie-chart__eyebrow">Besuchstrend</p><h2>Seitenaufrufe pro Tag</h2><p class="feuer-cookie-chart__subline">Jeder Datenpunkt zählt nur nach Statistik-Freigabe erfasste Aufrufe.</p></div>
				<div class="feuer-cookie-chart__meta"><?php $this->render_chart_meta_item('Tage mit Daten', number_format_i18n(count($series))); ?></div>
			</div>
			<?php $this->render_daily_area_chart($series, ''); ?>
		</div>
		<?php
	}

	/**
	 * Render a compact ranked list for an analytics dimension.
	 *
	 * @param string                   $title Panel title.
	 * @param array<int,array<string,mixed>> $rows Data rows.
	 * @param string                   $label_key Label field.
	 * @param string                   $value_key Numeric field.
	 * @return void
	 */
	private function render_analytics_breakdown($title, $rows, $label_key, $value_key, $secondary_key = '') {
		$max = 1;

		foreach ((array) $rows as $row) {
			$max = max($max, (int) ($row[ $value_key ] ?? 0));
		}
		?>
		<div class="feuer-cookie-panel feuer-cookie-analytics-panel">
			<h2><?php echo esc_html($title); ?></h2>
			<?php if ($rows) : ?>
				<ol class="feuer-cookie-analytics-list">
					<?php foreach ($rows as $row) : ?>
						<?php $value = (int) ($row[ $value_key ] ?? 0); ?>
						<li>
							<div class="feuer-cookie-analytics-list__row"><span><?php echo esc_html((string) ($row[ $label_key ] ?? '')); ?><?php if ($secondary_key) : ?><small><?php echo esc_html(number_format_i18n((int) ($row[ $secondary_key ] ?? 0))); ?> Sitzungen</small><?php endif; ?></span><strong><?php echo esc_html(number_format_i18n($value)); ?></strong></div>
							<div class="feuer-cookie-analytics-list__track"><span style="width: <?php echo esc_attr((string) max(2, (int) round(($value / $max) * 100))); ?>%"></span></div>
						</li>
					<?php endforeach; ?>
				</ol>
			<?php else : ?>
				<p class="description">Für diesen Zeitraum gibt es noch keine Werte.</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render visitor page.
	 *
	 * @return void
	 */
	public function render_visitors_page() {
		$this->render_admin_header('Besucher', 'Rohdaten der letzten Sitzungen, Aufrufe und Consent-Entscheidungen.');
		$filters = $this->get_visitor_filters_from_request();
		$accepted_visitors = $this->get_consent_visitor_rows(array('consent_accept_all', 'consent_custom', 'consent_service_allow'), 80);
		$necessary_visitors = $this->get_consent_visitor_rows(array('consent_necessary'), 80);
		$audit_events = $this->get_consent_audit_rows(80);
		$events = $this->get_filtered_event_rows($filters, 200);
		$export_url = wp_nonce_url(
			add_query_arg(array_merge(array('action' => 'feuer_cookie_export_events'), $filters), admin_url('admin-post.php')),
			'feuer_cookie_export_events'
		);
		$json_export_url = wp_nonce_url(
			add_query_arg(array_merge(array('action' => 'feuer_cookie_export_events', 'format' => 'json'), $filters), admin_url('admin-post.php')),
			'feuer_cookie_export_events'
		);

		?>
		<div class="feuer-cookie-panel">
			<h2>Filter und Export</h2>
			<form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>">
				<input type="hidden" name="page" value="feuer-cookie-visitors">
				<div class="feuer-cookie-filter-grid">
					<p>
						<label for="feuer_cookie_from"><strong>Von</strong></label><br>
						<input type="date" id="feuer_cookie_from" name="from" value="<?php echo esc_attr($filters['from']); ?>">
					</p>
					<p>
						<label for="feuer_cookie_to"><strong>Bis</strong></label><br>
						<input type="date" id="feuer_cookie_to" name="to" value="<?php echo esc_attr($filters['to']); ?>">
					</p>
					<p>
						<label for="feuer_cookie_consent"><strong>Consent</strong></label><br>
						<select id="feuer_cookie_consent" name="consent">
							<option value="" <?php selected($filters['consent'], ''); ?>>Alle</option>
							<option value="optional" <?php selected($filters['consent'], 'optional'); ?>>Optionale Freigabe</option>
							<option value="necessary" <?php selected($filters['consent'], 'necessary'); ?>>Nur erforderlich</option>
							<option value="deleted" <?php selected($filters['consent'], 'deleted'); ?>>Geloescht</option>
							<option value="page_view" <?php selected($filters['consent'], 'page_view'); ?>>Seitenaufrufe</option>
							<option value="engagement" <?php selected($filters['consent'], 'engagement'); ?>>Engagement</option>
						</select>
					</p>
					<p>
						<label for="feuer_cookie_robot"><strong>Robots</strong></label><br>
						<select id="feuer_cookie_robot" name="robot">
							<option value="" <?php selected($filters['robot'], ''); ?>>Alle</option>
							<option value="0" <?php selected($filters['robot'], '0'); ?>>Nur Besucher</option>
							<option value="1" <?php selected($filters['robot'], '1'); ?>>Nur Robots</option>
						</select>
					</p>
					<p>
						<label for="feuer_cookie_ip"><strong>IP enthaelt</strong></label><br>
						<input type="text" id="feuer_cookie_ip" name="ip" value="<?php echo esc_attr($filters['ip']); ?>" class="regular-text">
					</p>
					<p>
						<label for="feuer_cookie_path"><strong>Pfad enthaelt</strong></label><br>
						<input type="text" id="feuer_cookie_path" name="path" value="<?php echo esc_attr($filters['path']); ?>" class="regular-text">
					</p>
					<p>
						<label for="feuer_cookie_device"><strong>Geraet</strong></label><br>
						<input type="text" id="feuer_cookie_device" name="device" value="<?php echo esc_attr($filters['device']); ?>" class="regular-text">
					</p>
					<p>
						<label for="feuer_cookie_browser"><strong>Browser</strong></label><br>
						<input type="text" id="feuer_cookie_browser" name="browser" value="<?php echo esc_attr($filters['browser']); ?>" class="regular-text">
					</p>
				</div>
				<p>
					<button type="submit" class="button button-primary">Filter anwenden</button>
					<a class="button button-secondary" href="<?php echo esc_url(admin_url('admin.php?page=feuer-cookie-visitors')); ?>">Zuruecksetzen</a>
					<a class="button button-secondary" href="<?php echo esc_url($export_url); ?>">CSV exportieren</a>
					<a class="button button-secondary" href="<?php echo esc_url($json_export_url); ?>">JSON exportieren</a>
				</p>
			</form>
		</div>
		<?php

		echo '<div class="feuer-cookie-grid feuer-cookie-grid--two">';
		echo '<div class="feuer-cookie-panel">';
		echo '<h2>Besucher mit optionaler Freigabe</h2>';
		$this->render_consent_visitors_table($accepted_visitors, 'optional');
		echo '</div>';
		echo '<div class="feuer-cookie-panel">';
		echo '<h2>Besucher mit nur erforderlichen Diensten</h2>';
		$this->render_consent_visitors_table($necessary_visitors, 'necessary');
		echo '</div>';
		echo '</div>';

		echo '<div class="feuer-cookie-panel">';
		echo '<h2>Consent Audit Trail</h2>';
		$this->render_events_table($audit_events);
		echo '</div>';

		echo '<div class="feuer-cookie-panel">';
		echo '<h2>Gefilterte Ereignisse</h2>';
		$this->render_events_table($events);
		echo '</div>';
		$this->render_admin_footer();
	}

	/**
	 * Render operational status and analytics diagnostics.
	 *
	 * @return void
	 */
	public function render_status_page() {
		$this->render_admin_header('Cookie-Status', 'Technischer Status der Consent-Funktionen und der optionalen Statistik.');
		?>
		<div class="feuer-cookie-panel"><p><strong>Cookie-Consent:</strong> aktiv</p><p><strong>Externe Dienste:</strong> werden entsprechend der Auswahl blockiert oder freigegeben.</p><p><strong>Lokale Statistik:</strong> zählt Besuche nur nach ausdrücklicher Freigabe durch den jeweiligen Besucher; IP-Adressen und vollständige URLs werden nicht in Statistikereignissen gespeichert.</p><p><a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=feuer-cookie-analytics')); ?>">Statistik öffnen</a> <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=feuer-cookie-settings&tab=privacy')); ?>">Datenschutz-Einstellungen</a></p></div>
		<?php
		$this->render_admin_footer();
		return;

		$health      = $this->get_statistics_health_items();
		$realtime    = $this->get_realtime_status();
		$explain     = $this->get_empty_statistics_explanations();
		$scan        = $this->scan_frontend_external_requests();
		$last_events = $this->get_filtered_event_rows(
			array(
				'from'    => '',
				'to'      => '',
				'consent' => '',
				'robot'   => '',
				'ip'      => '',
				'path'    => '',
				'device'  => '',
				'browser' => '',
			),
			20
		);

		$this->render_admin_header('Status', 'Health Check, Realtime-Daten und Diagnose fuer leere Statistiken.');
		?>
		<div class="feuer-cookie-grid feuer-cookie-grid--cards">
			<?php $this->render_stat_card('Aktive 5 Minuten', (int) $realtime['active_5m'], 'Eindeutige Sitzungen mit Ereignissen in den letzten 5 Minuten.'); ?>
			<?php $this->render_stat_card('Aktive 15 Minuten', (int) $realtime['active_15m'], 'Eindeutige Sitzungen mit Ereignissen in den letzten 15 Minuten.'); ?>
			<?php $this->render_stat_card('Letzte Consent-Aktion', (string) $realtime['last_consent'], 'Zeitpunkt der letzten Cookie-Entscheidung.'); ?>
			<?php $this->render_stat_card('Letzter Page View', (string) $realtime['last_page_view'], 'Zeitpunkt des letzten protokollierten Seitenaufrufs.'); ?>
		</div>

		<div class="feuer-cookie-grid feuer-cookie-grid--two">
			<div class="feuer-cookie-panel">
				<h2>Health Check Statistik</h2>
				<table class="widefat striped">
					<thead>
						<tr>
							<th>Pruefung</th>
							<th>Status</th>
							<th>Details</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($health as $item) : ?>
							<tr>
								<td><?php echo esc_html((string) $item['label']); ?></td>
								<td><strong><?php echo esc_html((string) $item['status']); ?></strong></td>
								<td><?php echo esc_html((string) $item['detail']); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>

			<div class="feuer-cookie-panel">
				<h2>Warum koennen Statistiken leer sein?</h2>
				<ul class="feuer-cookie-notes">
					<?php foreach ($explain as $note) : ?>
						<li><?php echo esc_html($note); ?></li>
					<?php endforeach; ?>
				</ul>
				<p>
					<a class="button button-secondary" href="<?php echo esc_url(add_query_arg('feuer_cookie_preview', '1', home_url('/'))); ?>" target="_blank" rel="noopener noreferrer">Consent Preview als Besucher oeffnen</a>
				</p>
			</div>
		</div>

		<div class="feuer-cookie-grid feuer-cookie-grid--two feuer-cookie-status-grid">
			<div class="feuer-cookie-panel">
				<h2>Frontend Request Scan</h2>
				<p class="description">Schneller HTML-Check der Startseite auf externe src/href/action-Requests. Er ersetzt nicht den vollstaendigen Diagnose-Scan.</p>
				<table class="widefat striped">
					<thead>
						<tr>
							<th>Typ</th>
							<th>Domain</th>
							<th>URL</th>
						</tr>
					</thead>
					<tbody>
						<?php if (! empty($scan['items'])) : ?>
							<?php foreach ($scan['items'] as $item) : ?>
								<tr>
									<td><?php echo esc_html((string) $item['type']); ?></td>
									<td><code><?php echo esc_html((string) $item['host']); ?></code></td>
									<td><?php echo esc_html(wp_html_excerpt((string) $item['url'], 90, '...')); ?></td>
								</tr>
							<?php endforeach; ?>
						<?php else : ?>
							<tr><td colspan="3"><?php echo esc_html((string) $scan['message']); ?></td></tr>
						<?php endif; ?>
					</tbody>
				</table>
			</div>

			<div class="feuer-cookie-panel">
				<h2>Realtime letzte Ereignisse</h2>
				<?php $this->render_events_table($last_events, true); ?>
			</div>
		</div>
		<?php
		$this->render_admin_footer();
	}

	/**
	 * Return latest consent decisions grouped by session.
	 *
	 * @param array<int,string> $types Event types to include.
	 * @param int               $limit Maximum number of rows.
	 * @return array<int,array<string,mixed>>
	 */
	private function get_consent_visitor_rows($types, $limit = 80) {
		global $wpdb;

		$allowed = array(
			'consent_accept_all',
			'consent_custom',
			'consent_service_allow',
			'consent_necessary',
		);
		$types = array_values(array_intersect(array_map('sanitize_key', (array) $types), $allowed));

		if (empty($types)) {
			return array();
		}

		$limit        = max(1, (int) $limit);
		$events_table = $this->table_sql('events');
		$type_sql     = "'" . implode("','", array_map('esc_sql', $types)) . "'";

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT e.*
				FROM {$events_table} e
				INNER JOIN (
					SELECT session_token, MAX(id) AS latest_id
					FROM {$events_table}
					WHERE request_type IN ({$type_sql})
						AND is_robot = 0
					GROUP BY session_token
				) latest ON latest.latest_id = e.id
				ORDER BY e.created_at DESC
				LIMIT %d",
				$limit
			),
			ARRAY_A
		);
	}

	/**
	 * Read visitor filters from the admin request.
	 *
	 * @return array<string,string>
	 */
	private function get_visitor_filters_from_request() {
		return array(
			'from'    => $this->sanitize_admin_date(wp_unslash($_GET['from'] ?? '')),
			'to'      => $this->sanitize_admin_date(wp_unslash($_GET['to'] ?? '')),
			'consent' => $this->sanitize_visitor_consent_filter(wp_unslash($_GET['consent'] ?? '')),
			'robot'   => $this->sanitize_robot_filter(wp_unslash($_GET['robot'] ?? '')),
			'ip'      => sanitize_text_field(wp_unslash($_GET['ip'] ?? '')),
			'path'    => sanitize_text_field(wp_unslash($_GET['path'] ?? '')),
			'device'  => sanitize_text_field(wp_unslash($_GET['device'] ?? '')),
			'browser' => sanitize_text_field(wp_unslash($_GET['browser'] ?? '')),
		);
	}

	/**
	 * Return filtered event rows for visitor tables and exports.
	 *
	 * @param array<string,string> $filters Filters.
	 * @param int                  $limit Maximum rows.
	 * @return array<int,array<string,mixed>>
	 */
	private function get_filtered_event_rows($filters, $limit = 200) {
		global $wpdb;

		$events_table = $this->table_sql('events');
		$where        = array('1=1');
		$args         = array();
		$limit        = max(1, min(5000, (int) $limit));

		if (! empty($filters['from'])) {
			$where[] = 'created_at >= %s';
			$args[]  = $filters['from'] . ' 00:00:00';
		}

		if (! empty($filters['to'])) {
			$where[] = 'created_at <= %s';
			$args[]  = $filters['to'] . ' 23:59:59';
		}

		switch ((string) ($filters['consent'] ?? '')) {
			case 'optional':
				$where[] = "request_type IN ('consent_accept_all', 'consent_custom', 'consent_service_allow')";
				break;
			case 'necessary':
				$where[] = "request_type = 'consent_necessary'";
				break;
			case 'deleted':
				$where[] = "request_type = 'consent_deleted'";
				break;
			case 'page_view':
				$where[] = "request_type = 'page_view'";
				break;
			case 'engagement':
				$where[] = "request_type = 'page_engagement'";
				break;
		}

		if ('0' === (string) ($filters['robot'] ?? '') || '1' === (string) ($filters['robot'] ?? '')) {
			$where[] = 'is_robot = %d';
			$args[]  = (int) $filters['robot'];
		}

		foreach (array('ip' => 'ip_address', 'path' => 'request_path', 'device' => 'device_type', 'browser' => 'browser_name') as $filter_key => $column) {
			if ('' === (string) ($filters[ $filter_key ] ?? '')) {
				continue;
			}

			$where[] = "{$column} LIKE %s";
			$args[]  = '%' . $wpdb->esc_like((string) $filters[ $filter_key ]) . '%';
		}

		$sql = "SELECT * FROM {$events_table} WHERE " . implode(' AND ', $where) . " ORDER BY created_at DESC LIMIT %d";
		$args[] = $limit;

		return $wpdb->get_results($wpdb->prepare($sql, $args), ARRAY_A);
	}

	/**
	 * Return recent consent events for audit display.
	 *
	 * @param int $limit Maximum rows.
	 * @return array<int,array<string,mixed>>
	 */
	private function get_consent_audit_rows($limit = 80) {
		global $wpdb;

		$events_table = $this->table_sql('events');

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$events_table}
				WHERE request_type IN ('consent_accept_all', 'consent_custom', 'consent_necessary', 'consent_service_allow', 'consent_deleted')
					AND is_robot = 0
				ORDER BY created_at DESC
				LIMIT %d",
				max(1, (int) $limit)
			),
			ARRAY_A
		);
	}

	/**
	 * Sanitize date filter values.
	 *
	 * @param string $date Date string.
	 * @return string
	 */
	private function sanitize_admin_date($date) {
		$date = trim((string) $date);

		return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : '';
	}

	/**
	 * Sanitize visitor consent filter.
	 *
	 * @param string $filter Filter.
	 * @return string
	 */
	private function sanitize_visitor_consent_filter($filter) {
		$filter  = sanitize_key((string) $filter);
		$allowed = array('', 'optional', 'necessary', 'deleted', 'page_view', 'engagement');

		return in_array($filter, $allowed, true) ? $filter : '';
	}

	/**
	 * Sanitize robot filter.
	 *
	 * @param string $filter Filter.
	 * @return string
	 */
	private function sanitize_robot_filter($filter) {
		$filter = (string) $filter;

		return in_array($filter, array('', '0', '1'), true) ? $filter : '';
	}

	/**
	 * Build health check rows for statistics storage.
	 *
	 * @return array<int,array<string,string>>
	 */
	private function get_statistics_health_items() {
		global $wpdb;

		$settings = $this->get_settings();
		$items    = array();
		$events_exists = $this->table_exists('events');
		$daily_exists  = $this->table_exists('daily_stats');
		$last_event    = $events_exists ? $this->get_last_event_time('') : '';
		$last_page     = $events_exists ? $this->get_last_event_time('page_view') : '';
		$last_consent  = $events_exists ? $this->get_last_consent_time() : '';

		$items[] = array(
			'label'  => 'Events-Tabelle',
			'status' => $events_exists ? 'OK' : 'Fehlt',
			'detail' => $this->table_name('events'),
		);
		$items[] = array(
			'label'  => 'Daily-Stats-Tabelle',
			'status' => $daily_exists ? 'OK' : 'Fehlt',
			'detail' => $this->table_name('daily_stats'),
		);
		$items[] = array(
			'label'  => 'Letzte DB-Fehlermeldung',
			'status' => empty($wpdb->last_error) ? 'OK' : 'Fehler',
			'detail' => empty($wpdb->last_error) ? 'Keine aktuelle wpdb-Fehlermeldung.' : (string) $wpdb->last_error,
		);
		$items[] = array(
			'label'  => 'Letztes Ereignis',
			'status' => $last_event ? 'OK' : 'Leer',
			'detail' => $last_event ? $last_event : 'Noch kein Ereignis gespeichert.',
		);
		$items[] = array(
			'label'  => 'Letzter Page View',
			'status' => $last_page ? 'OK' : 'Leer',
			'detail' => $last_page ? $last_page : 'Noch kein Seitenaufruf gespeichert.',
		);
		$items[] = array(
			'label'  => 'Letzte Cookie-Entscheidung',
			'status' => $last_consent ? 'OK' : 'Leer',
			'detail' => $last_consent ? $last_consent : 'Noch keine Consent-Entscheidung gespeichert.',
		);
		$items[] = array(
			'label'  => 'Admin-Bypass',
			'status' => ! empty($settings['admin_bypass']) ? 'Aktiv' : 'Aus',
			'detail' => ! empty($settings['admin_bypass']) ? 'Eingeloggte Administratoren sehen keinen normalen Consent-Flow.' : 'Admins testen den normalen Consent-Flow.',
		);
		$items[] = array(
			'label'  => 'Demo-Modus',
			'status' => ! empty($settings['demo_mode']) ? 'Aktiv' : 'Aus',
			'detail' => ! empty($settings['demo_mode']) ? 'Entscheidungen werden nicht dauerhaft gespeichert.' : 'Consent wird gespeichert.',
		);
		$items[] = array(
			'label'  => 'AJAX-Endpunkt',
			'status' => 'OK',
			'detail' => admin_url('admin-ajax.php'),
		);

		return $items;
	}

	/**
	 * Return realtime counters.
	 *
	 * @return array<string,mixed>
	 */
	private function get_realtime_status() {
		global $wpdb;

		if (! $this->table_exists('events')) {
			return array(
				'active_5m'      => 0,
				'active_15m'     => 0,
				'last_consent'   => '-',
				'last_page_view' => '-',
			);
		}

		$events_table = $this->table_sql('events');
		$since_5      = wp_date('Y-m-d H:i:s', current_time('timestamp') - (5 * MINUTE_IN_SECONDS));
		$since_15     = wp_date('Y-m-d H:i:s', current_time('timestamp') - (15 * MINUTE_IN_SECONDS));
		$active_5     = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT session_token) FROM {$events_table} WHERE is_robot = 0 AND created_at >= %s", $since_5));
		$active_15    = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT session_token) FROM {$events_table} WHERE is_robot = 0 AND created_at >= %s", $since_15));

		return array(
			'active_5m'      => $active_5,
			'active_15m'     => $active_15,
			'last_consent'   => $this->get_last_consent_time() ?: '-',
			'last_page_view' => $this->get_last_event_time('page_view') ?: '-',
		);
	}

	/**
	 * Return likely reasons for empty statistics.
	 *
	 * @return array<int,string>
	 */
	private function get_empty_statistics_explanations() {
		$settings = $this->get_settings();
		$notes    = array();

		if (! $this->table_exists('events') || ! $this->table_exists('daily_stats')) {
			$notes[] = 'Eine oder mehrere Statistik-Tabellen fehlen. Plugin deaktivieren/aktivieren oder Schema-Upgrade ausloesen.';
		}

		if (! empty($settings['admin_bypass'])) {
			$notes[] = 'Admin-Bypass ist aktiv. Tests als eingeloggter Administrator erzeugen keinen normalen Consent-Flow.';
		}

		if (! empty($settings['demo_mode'])) {
			$notes[] = 'Demo-Modus ist aktiv. Consent wird nicht dauerhaft gespeichert.';
		}

		if (! $this->get_last_consent_time()) {
			$notes[] = 'Es wurde noch keine Cookie-Auswahl gespeichert. Erst nach einer Auswahl entstehen Consent- und Page-View-Ereignisse.';
		}

		if (! $this->get_last_event_time('page_engagement')) {
			$notes[] = 'Engagement-Daten entstehen nur nach ausdruecklicher Freigabe von Feuer-Cookie Statistik und beim Verlassen/Verstecken der Seite.';
		}

		if (empty($notes)) {
			$notes[] = 'Keine offensichtliche Ursache gefunden. Pruefen Sie Browser-Konsole, Caching/Optimierungsplugins und Server-Logs.';
		}

		return $notes;
	}

	/**
	 * Fetch the homepage HTML and detect external request attributes.
	 *
	 * @return array<string,mixed>
	 */
	private function scan_frontend_external_requests() {
		$response = wp_remote_get(
			home_url('/'),
			array(
				'timeout'     => 8,
				'redirection' => 2,
				'user-agent'  => 'Feuer-Cookie Status Scan/' . FEUER_COOKIE_VERSION,
			)
		);

		if (is_wp_error($response)) {
			return array(
				'items'   => array(),
				'message' => 'Startseite konnte nicht abgerufen werden: ' . $response->get_error_message(),
			);
		}

		$html = (string) wp_remote_retrieve_body($response);

		if ('' === $html) {
			return array(
				'items'   => array(),
				'message' => 'Startseite lieferte keinen HTML-Inhalt.',
			);
		}

		$home_host = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
		$items     = array();

		if (preg_match_all('/\b(src|href|action)=["\']([^"\']+)["\']/i', $html, $matches, PREG_SET_ORDER)) {
			foreach ($matches as $match) {
				$url  = html_entity_decode((string) $match[2], ENT_QUOTES);
				$host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));

				if (! $host || $host === $home_host) {
					continue;
				}

				$items[] = array(
					'type' => strtolower((string) $match[1]),
					'host' => $host,
					'url'  => $url,
				);

				if (count($items) >= 30) {
					break;
				}
			}
		}

		return array(
			'items'   => $items,
			'message' => empty($items) ? 'Keine externen src/href/action-Requests auf der Startseite erkannt.' : '',
		);
	}

	/**
	 * Check whether one plugin table exists.
	 *
	 * @param string $key Table key.
	 * @return bool
	 */
	private function table_exists($key) {
		global $wpdb;

		$table = $this->table_name($key);

		if (! $table) {
			return false;
		}

		return (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
	}

	/**
	 * Return latest event timestamp.
	 *
	 * @param string $type Optional event type.
	 * @return string
	 */
	private function get_last_event_time($type = '') {
		global $wpdb;

		if (! $this->table_exists('events')) {
			return '';
		}

		$events_table = $this->table_sql('events');

		if ($type) {
			return (string) $wpdb->get_var($wpdb->prepare("SELECT created_at FROM {$events_table} WHERE request_type = %s ORDER BY created_at DESC LIMIT 1", $type));
		}

		return (string) $wpdb->get_var("SELECT created_at FROM {$events_table} ORDER BY created_at DESC LIMIT 1");
	}

	/**
	 * Return latest consent timestamp.
	 *
	 * @return string
	 */
	private function get_last_consent_time() {
		global $wpdb;

		if (! $this->table_exists('events')) {
			return '';
		}

		$events_table = $this->table_sql('events');

		return (string) $wpdb->get_var(
			"SELECT created_at FROM {$events_table}
			WHERE request_type IN ('consent_accept_all', 'consent_custom', 'consent_necessary', 'consent_service_allow', 'consent_deleted')
			ORDER BY created_at DESC
			LIMIT 1"
		);
	}

	/**
	 * Render blocklist page.
	 *
	 * @return void
	 */
	public function render_blocklist_page() {
		global $wpdb;

		$this->render_admin_header('IP-Sperrliste', 'IP-Adressen, Wildcards oder CIDR-Bereiche blockieren.');
		$ip_blocks_table = $this->table_sql('ip_blocks');

		$blocks = $wpdb->get_results(
			"SELECT * FROM {$ip_blocks_table} ORDER BY created_at DESC",
			ARRAY_A
		);

		?>
		<div class="feuer-cookie-grid feuer-cookie-grid--two">
			<div class="feuer-cookie-panel">
				<h2>Neue Sperre</h2>
				<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
					<?php wp_nonce_field('feuer_cookie_add_block'); ?>
					<input type="hidden" name="action" value="feuer_cookie_add_block">
					<table class="form-table">
						<tr>
							<th scope="row"><label for="ip_pattern">IP / Muster</label></th>
							<td>
								<input type="text" name="ip_pattern" id="ip_pattern" class="regular-text" placeholder="203.0.113.45, 203.0.113.*, 203.0.113.0/24">
								<p class="description">Exakte IP, Wildcard oder CIDR-Bereich.</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="reason">Begründung</label></th>
							<td><textarea name="reason" id="reason" rows="4" class="large-text"></textarea></td>
						</tr>
					</table>
					<button type="submit" class="button button-primary">Sperre speichern</button>
				</form>
			</div>

			<div class="feuer-cookie-panel">
				<h2>Hinweise</h2>
				<ul class="feuer-cookie-notes">
					<li>Die Blockliste dient der Abwehr missbräuchlicher Zugriffe und ersetzt keine Firewall.</li>
					<li>Nutzen Sie nachvollziehbare Gründe, um Maßnahmen intern dokumentieren zu können.</li>
					<li>Historische Besuchslogs sollten nur so lange gespeichert werden, wie der Zweck es erfordert.</li>
				</ul>
			</div>
		</div>

		<div class="feuer-cookie-panel">
			<h2>Aktive Sperren</h2>
			<table class="widefat striped">
				<thead>
					<tr>
						<th>Muster</th>
						<th>Grund</th>
						<th>Erstellt</th>
						<th>Aktion</th>
					</tr>
				</thead>
				<tbody>
					<?php if ($blocks) : ?>
						<?php foreach ($blocks as $block) : ?>
							<tr>
								<td><code><?php echo esc_html($block['ip_pattern']); ?></code></td>
								<td><?php echo esc_html((string) $block['reason']); ?></td>
								<td><?php echo esc_html((string) $block['created_at']); ?></td>
								<td>
									<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
										<?php wp_nonce_field('feuer_cookie_delete_block'); ?>
										<input type="hidden" name="action" value="feuer_cookie_delete_block">
										<input type="hidden" name="block_id" value="<?php echo esc_attr((string) $block['id']); ?>">
										<button type="submit" class="button button-secondary">Entfernen</button>
									</form>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php else : ?>
						<tr><td colspan="4">Noch keine IP-Sperren definiert.</td></tr>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php

		$this->render_admin_footer();
	}

	/**
	 * Render diagnostics page.
	 *
	 * @return void
	 */
	public function render_diagnostics_page() {
		$report = get_option($this->diagnostics_key, array());
		$report['services'] = array_values(array_filter((array) ($report['services'] ?? array()), function ($service) {
			$domain = strtolower((string) ($service['domain'] ?? ''));

			return ! ('cloudflare.com' === $domain || 'cdnjs.cloudflare.com' === $domain || (strlen($domain) > strlen('.cloudflare.com') && substr($domain, -strlen('.cloudflare.com')) === '.cloudflare.com'));
		}));

		$this->render_admin_header('Diagnose', 'Statische Suche nach externen Request-Stellen in aktiven Themes, Plugins und veroeffentlichten Inhalten.');

		?>
		<div class="feuer-cookie-panel">
			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
				<?php wp_nonce_field('feuer_cookie_run_diagnostics'); ?>
				<input type="hidden" name="action" value="feuer_cookie_run_diagnostics">
				<button type="submit" class="button button-primary">Scan starten</button>
			</form>
			<p class="description feuer-cookie-diag-note">Der Scan findet mögliche Request-Stellen im Quellcode; er bestätigt nicht, dass diese zur Laufzeit geladen werden. Serverseitige API-Aufrufe werden als technische Hinweise erfasst und nicht als Cookie-Dienste für Website-Besucher behandelt.</p>
		</div>
		<?php if (! empty($report)) : ?>
			<div class="feuer-cookie-grid feuer-cookie-grid--two">
				<div class="feuer-cookie-panel">
					<h2>Scan-Informationen</h2>
					<p><strong>Zeitpunkt:</strong> <?php echo esc_html((string) $report['generated_at']); ?></p>
					<p><strong>Modus:</strong> Statische Quellcodeanalyse</p>
					<p><strong>Treffer:</strong> <?php echo esc_html(number_format_i18n((int) ($report['record_count'] ?? 0))); ?></p>
					<?php if (! empty($report['active_theme'])) : ?>
						<p><strong>Aktives Theme:</strong> <?php echo esc_html($report['active_theme']['name'] . ' ' . $report['active_theme']['version']); ?></p>
					<?php endif; ?>
					<?php if (! empty($report['active_plugins'])) : ?>
						<p><strong>Aktive Plugins:</strong></p>
						<ul class="feuer-cookie-notes">
							<?php foreach ((array) $report['active_plugins'] as $plugin) : ?>
								<li><?php echo esc_html($plugin['name'] . (! empty($plugin['version']) ? ' ' . $plugin['version'] : '')); ?></li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				</div>

				<div class="feuer-cookie-panel">
					<h2>Hinweise</h2>
					<?php if (! empty($report['notices'])) : ?>
						<ul class="feuer-cookie-notes">
							<?php foreach ((array) $report['notices'] as $notice) : ?>
								<li><?php echo esc_html($notice); ?></li>
							<?php endforeach; ?>
						</ul>
					<?php else : ?>
						<p>Keine besonderen Hinweise aus dem letzten Scan.</p>
					<?php endif; ?>
				</div>
			</div>

			<div class="feuer-cookie-panel">
				<h2>Gefundene externe Request-Stellen</h2>
				<table class="widefat striped">
					<thead>
						<tr>
							<th>Dienst</th>
							<th>Domain</th>
							<th>Nachweis</th>
							<th>Kontext</th>
							<th>Fundort</th>
							<th>Empfehlung</th>
						</tr>
					</thead>
					<tbody>
						<?php if (! empty($report['services'])) : ?>
							<?php foreach ((array) $report['services'] as $service) : ?>
								<tr>
									<td><?php echo esc_html($service['service']); ?></td>
									<td><code><?php echo esc_html($service['domain']); ?></code></td>
									<td>
										<strong><?php echo esc_html((string) ($service['evidence'] ?? 'Direkter Request')); ?></strong>
										<?php if (! empty($service['context'])) : ?>
											<div class="feuer-cookie-diag-context"><code><?php echo esc_html((string) $service['context']); ?></code></div>
										<?php endif; ?>
									</td>
									<td><?php echo ('server' === (string) ($service['execution_context'] ?? '') || in_array((string) ($service['evidence'] ?? ''), array('WP HTTP request', 'cURL request'), true)) ? 'Server (intern)' : 'Browser (möglicherweise)'; ?></td>
									<td>
										<?php echo esc_html((string) $service['location']); ?>
										<?php if (! empty($service['line'])) : ?>
											<div class="feuer-cookie-diag-line">Zeile <?php echo esc_html((string) $service['line']); ?></div>
										<?php endif; ?>
									</td>
									<td><?php echo esc_html($service['recommendation']); ?></td>
								</tr>
							<?php endforeach; ?>
						<?php else : ?>
							<tr><td colspan="6">Keine externen Request-Stellen erkannt.</td></tr>
						<?php endif; ?>
					</tbody>
				</table>
			</div>
		<?php else : ?>
			<div class="feuer-cookie-panel">
				<p>Es liegt noch kein Scan vor.</p>
			</div>
		<?php endif; ?>
		<?php

		$this->render_admin_footer();
	}

	/**
	 * Render SEO audit page.
	 *
	 * @return void
	 */
	public function render_seo_audit_page() {
		$report = get_option($this->seo_audit_key, array());
		$totals = (array) ($report['totals'] ?? array());
		$rows   = array_slice((array) ($report['rows'] ?? array()), 0, 50);

		$this->render_admin_header('SEO-Audit', 'Lokaler On-Page-Check: erst Kontext erfassen, dann naechste Schritte priorisieren.');
		?>
		<div class="feuer-cookie-panel">
			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
				<?php wp_nonce_field('feuer_cookie_run_seo_audit'); ?>
				<input type="hidden" name="action" value="feuer_cookie_run_seo_audit">
				<button type="submit" class="button button-primary">SEO-Audit starten</button>
			</form>
			<p class="description feuer-cookie-diag-note">Der Audit liest nur lokale WordPress-Inhalte, bekannte SEO-Metafelder und die Feuer-Cookie-Seitenaufrufe der letzten 30 Tage. Es werden keine externen SEO-APIs abgefragt.</p>
		</div>

		<?php if (! empty($report)) : ?>
			<div class="feuer-cookie-grid feuer-cookie-grid--cards">
				<?php $this->render_stat_card('Gepruefte Seiten', (int) ($totals['pages'] ?? 0), 'Veroeffentlichte oeffentliche Inhalte'); ?>
				<?php $this->render_stat_card('Kritisch', (int) ($totals['critical'] ?? 0), 'Score unter 60'); ?>
				<?php $this->render_stat_card('Warnungen', (int) ($totals['warnings'] ?? 0), 'Score unter 82'); ?>
				<?php $this->render_stat_card('Traffic 30 Tage', (int) ($totals['traffic'] ?? 0), 'Lokale Page Views ohne Robots'); ?>
			</div>

			<div class="feuer-cookie-grid feuer-cookie-grid--two">
				<div class="feuer-cookie-panel">
					<h2>Audit-Informationen</h2>
					<p><strong>Zeitpunkt:</strong> <?php echo esc_html((string) ($report['generated_at'] ?? '-')); ?></p>
					<p><strong>Datenquelle:</strong> Lokale WordPress-Inhalte und Feuer-Cookie-Statistik</p>
					<p><strong>Zeitraum Traffic:</strong> <?php echo esc_html((string) ($report['lookback_days'] ?? 30)); ?> Tage</p>
				</div>

				<div class="feuer-cookie-panel">
					<h2>Empfohlene naechste Schritte</h2>
					<ul class="feuer-cookie-notes">
						<?php foreach ((array) ($report['recommendations'] ?? array()) as $recommendation) : ?>
							<li><?php echo esc_html((string) $recommendation); ?></li>
						<?php endforeach; ?>
					</ul>
				</div>
			</div>

			<div class="feuer-cookie-panel">
				<h2>Priorisierte Seiten</h2>
				<div class="feuer-cookie-table-scroll">
					<table class="widefat striped feuer-cookie-seo-table">
						<thead>
							<tr>
								<th>Score</th>
								<th>Seite</th>
								<th>Traffic</th>
								<th>Signale</th>
								<th>Probleme</th>
								<th>Aktion</th>
							</tr>
						</thead>
						<tbody>
							<?php if ($rows) : ?>
								<?php foreach ($rows as $row) : ?>
									<tr>
										<td>
											<span class="<?php echo esc_attr('feuer-cookie-seo-score feuer-cookie-seo-score--' . sanitize_html_class((string) ($row['severity'] ?? 'ok'))); ?>">
												<?php echo esc_html((string) (int) ($row['score'] ?? 0)); ?>
											</span>
										</td>
										<td>
											<strong><?php echo esc_html((string) ($row['title'] ?? '-')); ?></strong>
											<div class="feuer-cookie-path-title"><?php echo esc_html((string) ($row['path'] ?? '')); ?></div>
										</td>
										<td><?php echo esc_html(number_format_i18n((int) ($row['views_30d'] ?? 0))); ?></td>
										<td>
											<div class="feuer-cookie-seo-signals">
												<span><?php echo esc_html((string) (int) ($row['word_count'] ?? 0)); ?> Woerter</span>
												<span><?php echo esc_html((string) (int) ($row['internal_links'] ?? 0)); ?> interne Links</span>
												<span><?php echo esc_html((string) (int) ($row['image_count'] ?? 0)); ?> Bilder</span>
											</div>
										</td>
										<td>
											<?php if (! empty($row['issues'])) : ?>
												<ul class="feuer-cookie-seo-issues">
													<?php foreach (array_slice((array) $row['issues'], 0, 3) as $issue) : ?>
														<li><?php echo esc_html((string) $issue); ?></li>
													<?php endforeach; ?>
												</ul>
											<?php else : ?>
												<span class="feuer-cookie-seo-ok">Basis-Signale gepflegt</span>
											<?php endif; ?>
										</td>
										<td>
											<?php if (! empty($row['edit_url'])) : ?>
												<a class="button button-small" href="<?php echo esc_url((string) $row['edit_url']); ?>">Bearbeiten</a>
											<?php endif; ?>
											<?php if (! empty($row['url'])) : ?>
												<a class="button button-small" href="<?php echo esc_url((string) $row['url']); ?>" target="_blank" rel="noopener">Oeffnen</a>
											<?php endif; ?>
										</td>
									</tr>
								<?php endforeach; ?>
							<?php else : ?>
								<tr><td colspan="6">Keine veroeffentlichten Inhalte fuer den Audit gefunden.</td></tr>
							<?php endif; ?>
						</tbody>
					</table>
				</div>
			</div>
		<?php else : ?>
			<div class="feuer-cookie-panel">
				<p>Es liegt noch kein SEO-Audit vor.</p>
			</div>
		<?php endif; ?>
		<?php

		$this->render_admin_footer();
	}

	/**
	 * Render settings page.
	 *
	 * @return void
	 */
	public function render_settings_page() {
		$this->render_settings_page_tabbed();
		return;

		$settings      = $this->get_settings();
		$categories    = $this->get_category_definitions();
		$vendors       = $this->get_visible_vendor_definitions();
		$revision_log  = $this->get_consent_revision_log();
		$config_export = $this->get_configuration_export_json();

		$this->render_admin_header('Einstellungen', 'Marke, Texte, Links und Datenschutzregeln fuer Feuer-Cookie.');

		?>
		<div class="feuer-cookie-panel">
			<p class="notice-inline notice-warning">
				<strong>Wichtiger Hinweis:</strong>
				Optionalen Diensten sollte erst nach ausdrücklicher Auswahl Zugriff gewährt werden. Hinterlegen Sie mindestens Impressum und Datenschutzerklärung.
			</p>
			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
				<?php wp_nonce_field('feuer_cookie_save_settings'); ?>
				<input type="hidden" name="action" value="feuer_cookie_save_settings">

				<div class="feuer-cookie-grid feuer-cookie-grid--two feuer-cookie-settings-tools">
					<div class="feuer-cookie-card">
						<p class="feuer-cookie-card__label">Schnellzugriff</p>
						<h3 class="feuer-cookie-settings-tools__title">Demo-Modus</h3>
						<label class="feuer-cookie-settings-tools__check">
							<input type="checkbox" name="demo_mode" value="1" <?php checked($settings['demo_mode'], 1); ?>>
							Banner immer anzeigen und Consent nicht speichern.
						</label>
						<p class="feuer-cookie-card__hint">Fuer Vorschau und Tests. Vorhandene Consent-Werte werden im aktuellen Browser ignoriert.</p>
					</div>
					<div class="feuer-cookie-card">
						<p class="feuer-cookie-card__label">Widerruf</p>
						<h3 class="feuer-cookie-settings-tools__title">Einwilligung im Browser loeschen</h3>
						<p class="feuer-cookie-card__hint">Loescht Consent-Cookies und LocalStorage nur im Browser, den Sie gerade benutzen. Besucher koennen das spaeter ueber das Cookie-Symbol erneut oeffnen.</p>
						<p class="feuer-cookie-settings-tools__actions">
							<button type="button" class="button button-secondary" data-feuer-cookie-clear-browser-consent>Einwilligung jetzt loeschen</button>
						</p>
						<p class="description" data-feuer-cookie-clear-browser-consent-result hidden></p>
					</div>
				</div>

				<h2>Marke und Links</h2>
				<table class="form-table">
					<tr>
						<th scope="row"><label for="logo_url">Logo</label></th>
						<td>
							<div class="feuer-cookie-media-field">
								<input type="url" name="logo_url" id="logo_url" class="regular-text" value="<?php echo esc_attr($settings['logo_url']); ?>">
								<button type="button" class="button" data-feuer-cookie-media data-target="#logo_url">Bild auswaehlen</button>
							</div>
							<?php if (! empty($settings['logo_url'])) : ?>
								<img src="<?php echo esc_url($settings['logo_url']); ?>" alt="" class="feuer-cookie-logo-preview">
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="consent_revision">Einwilligungs-Revision</label></th>
						<td>
							<input type="text" name="consent_revision" id="consent_revision" class="regular-text" value="<?php echo esc_attr($settings['consent_revision']); ?>">
							<p class="description">Aendern, wenn sich Rechtstexte, Empfaenger oder Zwecke der Datenverarbeitung wesentlich aendern.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="privacy_url">Datenschutz-URL</label></th>
						<td><input type="url" name="privacy_url" id="privacy_url" class="regular-text" value="<?php echo esc_attr($settings['privacy_url']); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="imprint_url">Impressum-URL</label></th>
						<td><input type="url" name="imprint_url" id="imprint_url" class="regular-text" value="<?php echo esc_attr($settings['imprint_url']); ?>"></td>
					</tr>
					<?php /*
						<th scope="row"><label for="alternative_url">Alternative Zielseite / Ausweichseite</label></th>
						<td>
							<input type="url" name="alternative_url" id="alternative_url" class="regular-text" value="<?php echo esc_attr($settings['alternative_url']); ?>">
							<p class="description">Optional. Interne oder externe Seite für Besucher, die den regulären Website-Besuch nicht fortsetzen möchten.</p>
						</td>
					*/ ?>
					<tr>
						<th scope="row"><label for="support_url">Support-/Kontakt-URL</label></th>
						<td><input type="url" name="support_url" id="support_url" class="regular-text" value="<?php echo esc_attr($settings['support_url']); ?>"></td>
					</tr>
				</table>

				<h2>Consent-Banner</h2>
				<table class="form-table">
					<tr>
						<th scope="row"><label for="consent_template">Layout Sperrseite</label></th>
						<td>
							<select name="consent_template" id="consent_template">
								<option value="feuerwand" <?php selected($settings['consent_template'], 'feuerwand'); ?>>Feuerwand</option>
								<option value="kontrollkarte" <?php selected($settings['consent_template'], 'kontrollkarte'); ?>>Kontrollkarte</option>
								<option value="sicherheitsleiste" <?php selected($settings['consent_template'], 'sicherheitsleiste'); ?>>Sicherheitsleiste</option>
							</select>
							<p class="description">Wirkt nur auf die IP-Sperrseite, nicht auf das regulaere Consent-Banner.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="banner_layout">Position Banner</label></th>
						<td>
							<select name="banner_layout" id="banner_layout">
								<?php foreach ($this->get_banner_layout_options() as $layout_key => $layout_label) : ?>
									<option value="<?php echo esc_attr($layout_key); ?>" <?php selected($settings['banner_layout'], $layout_key); ?>><?php echo esc_html($layout_label); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description">Mehrere Varianten fuer unten, oben, Mitte und kompakte Darstellungen.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="agreement_title">Titel Banner</label></th>
						<td><input type="text" name="agreement_title" id="agreement_title" class="regular-text" value="<?php echo esc_attr($settings['agreement_title']); ?>"></td>
					</tr>
					<tr>
						<th scope="row">Text Banner</th>
						<td><?php wp_editor($settings['agreement_text'], 'feuer_cookie_agreement_text', array('textarea_name' => 'agreement_text', 'textarea_rows' => 12)); ?></td>
					</tr>
					<tr>
						<th scope="row"><label for="blocked_title">Titel IP-Sperre</label></th>
						<td><input type="text" name="blocked_title" id="blocked_title" class="regular-text" value="<?php echo esc_attr($settings['blocked_title']); ?>"></td>
					</tr>
					<tr>
						<th scope="row">Text IP-Sperre</th>
						<td><?php wp_editor($settings['blocked_text'], 'feuer_cookie_blocked_text', array('textarea_name' => 'blocked_text', 'textarea_rows' => 8)); ?></td>
					</tr>
					<tr>
						<th scope="row"><label for="accept_label">Button Alle akzeptieren</label></th>
						<td><input type="text" name="accept_label" id="accept_label" class="regular-text" value="<?php echo esc_attr($settings['accept_label']); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="decline_label">Button Nur notwendige</label></th>
						<td><input type="text" name="decline_label" id="decline_label" class="regular-text" value="<?php echo esc_attr($settings['decline_label']); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="save_label">Button Auswahl speichern</label></th>
						<td><input type="text" name="save_label" id="save_label" class="regular-text" value="<?php echo esc_attr($settings['save_label']); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="settings_label">Button Einstellungen</label></th>
						<td><input type="text" name="settings_label" id="settings_label" class="regular-text" value="<?php echo esc_attr($settings['settings_label']); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="reopen_label">Label Cookie-Icon</label></th>
						<td><input type="text" name="reopen_label" id="reopen_label" class="regular-text" value="<?php echo esc_attr($settings['reopen_label']); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="panel_button_shape">Form Cookie-Buttons</label></th>
						<td>
							<select name="panel_button_shape" id="panel_button_shape">
								<?php foreach ($this->get_panel_button_shape_options() as $shape_key => $shape_label) : ?>
									<option value="<?php echo esc_attr($shape_key); ?>" <?php selected($settings['panel_button_shape'], $shape_key); ?>><?php echo esc_html($shape_label); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description">Rund, Keksform, quadratisch oder als breite Pill-Variante.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="panel_button_color">Farbe Cookie-Buttons</label></th>
						<td>
							<select name="panel_button_color" id="panel_button_color">
								<?php foreach ($this->get_panel_button_color_options() as $color_key => $color_label) : ?>
									<option value="<?php echo esc_attr($color_key); ?>" <?php selected($settings['panel_button_color'], $color_key); ?>><?php echo esc_html($color_label); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description">Mehrere vordefinierte Farbstimmungen fuer unterschiedliche Marken- oder Theme-Farben.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="panel_button_icon">Icon Cookie-Buttons</label></th>
						<td>
							<select name="panel_button_icon" id="panel_button_icon">
								<?php foreach ($this->get_panel_button_icon_options() as $icon_key => $icon_label) : ?>
									<option value="<?php echo esc_attr($icon_key); ?>" <?php selected($settings['panel_button_icon'], $icon_key); ?>><?php echo esc_html($icon_label); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description">Keks, Schild, Zahnrad, Schloss oder Chart fuer die Wiederoeffnen-Schaltflaeche.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="panel_button_position">Position Cookie-Button</label></th>
						<td>
							<select name="panel_button_position" id="panel_button_position">
								<?php foreach ($this->get_panel_button_position_options() as $position_key => $position_label) : ?>
									<option value="<?php echo esc_attr($position_key); ?>" <?php selected($settings['panel_button_position'], $position_key); ?>><?php echo esc_html($position_label); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description">Wahlweise in einer unteren Ecke oder mittig am linken bzw. rechten Rand.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="service_allow_label">Button einzelner Dienst</label></th>
						<td><input type="text" name="service_allow_label" id="service_allow_label" class="regular-text" value="<?php echo esc_attr($settings['service_allow_label']); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="delete_label">Button Einwilligung loeschen</label></th>
						<td><input type="text" name="delete_label" id="delete_label" class="regular-text" value="<?php echo esc_attr($settings['delete_label']); ?>"></td>
					</tr>
				</table>

				<h2>Kategorien und Dienste</h2>
				<p class="description">Diese Liste wird aus den Plugin-Standards, registrierten Diensten und dem letzten Diagnosescan aufgebaut. Nach Aenderungen an Theme oder Plugins bitte die Diagnose erneut starten.</p>
				<table class="widefat striped">
					<thead>
						<tr>
							<th>Kategorie</th>
							<th>Dienst</th>
							<th>Zweck</th>
							<th>Rechtsgrundlage</th>
							<th>Datenschutz</th>
							<th>Status</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($vendors as $vendor) : ?>
							<tr>
								<td><?php echo esc_html($categories[ $vendor['category'] ]['label'] ?? $vendor['category']); ?></td>
								<td><?php echo esc_html((string) $vendor['label']); ?></td>
								<td><?php echo esc_html((string) $vendor['purpose']); ?></td>
								<td><?php echo esc_html((string) $vendor['legal_basis']); ?></td>
								<td>
									<?php if (! empty($vendor['privacy_url'])) : ?>
										<a href="<?php echo esc_url((string) $vendor['privacy_url']); ?>" target="_blank" rel="noopener noreferrer">Link</a>
									<?php else : ?>
										<span>-</span>
									<?php endif; ?>
								</td>
								<td><?php echo ! empty($vendor['required']) ? 'Immer aktiv' : (! empty($vendor['detected']) ? 'Erkannt' : 'Vordefiniert'); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<h2>Speicherung und Betrieb</h2>
				<table class="form-table">
					<tr>
						<th scope="row"><label for="cookie_name">Cookie-Name</label></th>
						<td>
							<input type="text" name="cookie_name" id="cookie_name" class="regular-text" value="<?php echo esc_attr($settings['cookie_name']); ?>">
							<p class="description">Basename für Consent-, Revisions-, Präferenz- und Session-Cookies.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="retention_days">Aufbewahrung Rohlogs (Tage)</label></th>
						<td><input type="number" min="7" max="365" name="retention_days" id="retention_days" value="<?php echo esc_attr((string) $settings['retention_days']); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="consent_cookie_days">Laufzeit Consent-Cookie (Tage)</label></th>
						<td><input type="number" min="1" max="3650" name="consent_cookie_days" id="consent_cookie_days" value="<?php echo esc_attr((string) $settings['consent_cookie_days']); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="overlay_opacity">Hintergrund abdunkeln (%)</label></th>
						<td>
							<input type="number" min="0" max="90" name="overlay_opacity" id="overlay_opacity" value="<?php echo esc_attr((string) $settings['overlay_opacity']); ?>">
							<p class="description">0 = keine Abdunkelung, 40 = empfohlener Standard, 90 = sehr dunkel.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="blocking_mode">Blockierung bis Einwilligung</label></th>
						<td>
							<select name="blocking_mode" id="blocking_mode">
								<option value="strict" <?php selected($settings['blocking_mode'], 'strict'); ?>>Strikt blockieren</option>
								<option value="off" <?php selected($settings['blocking_mode'], 'off'); ?>>Nur dokumentieren</option>
							</select>
							<p class="description">Im strikten Modus werden erkannte externe Skripte, Styles und Iframes bis zur Zustimmung blockiert.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Google Tag Manager</th>
						<td><label><input type="checkbox" name="gtm_consent_mode" value="1" <?php checked(! empty($settings['gtm_consent_mode'])); ?>> GTM Consent Mode v2 aktivieren</label><p class="description">Setzt vor GTM alle optionalen Google-Speicher auf „denied“ und aktualisiert sie erst nach der ausdruecklichen Freigabe von Google Analytics / Tag Manager.</p></td>
					</tr>
					<tr>
						<th scope="row">Volle IP speichern</th>
						<td><label><input type="checkbox" name="store_full_ip" value="1" <?php checked($settings['store_full_ip'], 1); ?>> Ja, statt maskierter IP den vollständigen Wert im Rohlog speichern.</label></td>
					</tr>
					<tr>
						<th scope="row">Admins umgehen Gate</th>
						<td><label><input type="checkbox" name="admin_bypass" value="1" <?php checked($settings['admin_bypass'], 1); ?>> Ja, eingeloggte Administratoren sehen die Website ohne Consent-Banner.</label></td>
					</tr>
					<tr>
						<th scope="row">Cookie-Icon im Frontend</th>
						<td>
							<p>Die Wiederoeffnen-Schaltflaeche wird auf freigegebenen Seiten immer angezeigt. Form, Farbe und Icon koennen oben angepasst werden.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Demo-Modus</th>
						<td>
							<label><input type="checkbox" name="demo_mode" value="1" <?php checked($settings['demo_mode'], 1); ?>> Ja, Banner immer anzeigen und Consent nicht speichern.</label>
							<p class="description">Ignoriert vorhandene Consent-Cookies, loescht gespeicherte Werte im aktuellen Browser und ist fuer Vorschau-/Testzwecke gedacht.</p>
						</td>
					</tr>
				</table>

				<p><button type="submit" class="button button-primary button-large">Einstellungen speichern</button></p>
			</form>
		</div>

		<div class="feuer-cookie-grid feuer-cookie-grid--two">
			<div class="feuer-cookie-panel">
				<h2>Journal der Consent-Versionen</h2>
				<table class="widefat striped">
					<thead>
						<tr>
							<th>Zeitpunkt</th>
							<th>Revision</th>
							<th>Titel</th>
							<th>Config-Hash</th>
						</tr>
					</thead>
					<tbody>
						<?php if ($revision_log) : ?>
							<?php foreach ($revision_log as $entry) : ?>
								<tr>
									<td><?php echo esc_html((string) ($entry['saved_at'] ?? '')); ?></td>
									<td><?php echo esc_html((string) ($entry['revision'] ?? '')); ?></td>
									<td><?php echo esc_html((string) ($entry['banner_title'] ?? '')); ?></td>
									<td><code><?php echo esc_html((string) ($entry['config_hash'] ?? '')); ?></code></td>
								</tr>
							<?php endforeach; ?>
						<?php else : ?>
							<tr><td colspan="4">Noch keine Revisionen protokolliert.</td></tr>
						<?php endif; ?>
					</tbody>
				</table>
			</div>

			<div class="feuer-cookie-panel">
				<h2>Export der Konfiguration</h2>
				<p class="description">Diese JSON-Ausgabe enthält Bannertexte, Kategorien, Dienste, Betriebsparameter und das Revisionsjournal.</p>
				<textarea class="large-text code" rows="18" readonly><?php echo esc_textarea($config_export); ?></textarea>
			</div>
		</div>
		<?php

		$this->render_admin_footer();
	}

	/**
	 * Render the tabbed settings page.
	 *
	 * @return void
	 */
	private function render_settings_page_tabbed() {
		$settings      = $this->get_settings();
		$categories    = $this->get_category_definitions();
		$vendors       = $this->get_visible_vendor_definitions();
		$revision_log  = $this->get_consent_revision_log();
		$config_export = $this->get_configuration_export_json();
		$active_tab    = $this->get_current_settings_tab();

		$this->render_admin_header('Einstellungen', 'Marke, Texte, Links und Datenschutzregeln fuer Feuer-Cookie.');

		?>
		<form id="feuer-cookie-update-check-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
			<?php wp_nonce_field('feuer_cookie_check_updates'); ?>
			<input type="hidden" name="action" value="feuer_cookie_check_updates">
			<input type="hidden" name="settings_tab" value="<?php echo esc_attr($active_tab); ?>">
		</form>

		<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
			<?php wp_nonce_field('feuer_cookie_save_settings'); ?>
			<input type="hidden" name="action" value="feuer_cookie_save_settings">
			<input type="hidden" name="settings_tab" value="<?php echo esc_attr($active_tab); ?>">

			<div class="feuer-cookie-panel">
				<p class="notice-inline notice-warning">
					<strong>Wichtiger Hinweis:</strong>
					Optionalen Diensten sollte erst nach ausdruecklicher Auswahl Zugriff gewaehrt werden. Hinterlegen Sie mindestens Impressum und Datenschutzerklaerung.
				</p>

				<div class="feuer-cookie-grid feuer-cookie-grid--cards feuer-cookie-settings-tools">
					<div class="feuer-cookie-card">
						<p class="feuer-cookie-card__label">Schnellzugriff</p>
						<h3 class="feuer-cookie-settings-tools__title">Demo-Modus</h3>
						<label class="feuer-cookie-settings-tools__check">
							<input type="checkbox" name="demo_mode" value="1" <?php checked($settings['demo_mode'], 1); ?>>
							Banner immer anzeigen und Consent nicht speichern.
						</label>
						<p class="feuer-cookie-card__hint">Fuer Vorschau und Tests. Vorhandene Consent-Werte werden im aktuellen Browser ignoriert.</p>
					</div>

					<div class="feuer-cookie-card">
						<p class="feuer-cookie-card__label">Widerruf</p>
						<h3 class="feuer-cookie-settings-tools__title">Einwilligung im Browser loeschen</h3>
						<p class="feuer-cookie-card__hint">Loescht Consent-Cookies und LocalStorage nur im Browser, den Sie gerade benutzen. Besucher koennen das spaeter ueber das Cookie-Symbol erneut oeffnen.</p>
						<p class="feuer-cookie-settings-tools__actions">
							<button type="button" class="button button-secondary" data-feuer-cookie-clear-browser-consent>Einwilligung jetzt loeschen</button>
						</p>
						<p class="description" data-feuer-cookie-clear-browser-consent-result hidden></p>
					</div>

					<div class="feuer-cookie-card">
						<p class="feuer-cookie-card__label">Kompatibilitaet</p>
						<h3 class="feuer-cookie-settings-tools__title">WordPress 6.9 und PHP 8.0</h3>
						<p class="feuer-cookie-card__hint">Die Mindestversionen sind im Plugin-Header, im Updater und im Release-Manifest hinterlegt. Updates kommen ueber <code><?php echo esc_html(FEUER_COOKIE_HOME_URL); ?></code>.</p>
						<p class="feuer-cookie-settings-tools__actions">
							<button type="submit" class="button button-secondary" form="feuer-cookie-update-check-form">Jetzt nach Updates suchen</button>
						</p>
						<p class="feuer-cookie-card__hint">Installierte Version: <code><?php echo esc_html(FEUER_COOKIE_VERSION); ?></code></p>
					</div>
				</div>

				<?php $this->render_settings_tabs_nav($active_tab); ?>

				<div class="feuer-cookie-settings-tabpanel">
					<?php
					switch ($active_tab) {
						case 'banner':
							$this->render_settings_banner_tab($settings);
							break;

						case 'services':
							$this->render_settings_services_tab($categories, $vendors);
							break;

						case 'privacy':
							$this->render_settings_privacy_tab($settings);
							break;

						case 'export':
							$this->render_settings_export_tab($revision_log, $config_export);
							break;

						case 'general':
						default:
							$this->render_settings_general_tab($settings);
							break;
					}
					?>
				</div>

				<p class="feuer-cookie-settings-submit">
					<button type="submit" class="button button-primary button-large">Einstellungen speichern</button>
				</p>
			</div>
		</form>
		<?php

		$this->render_admin_footer();
	}

	/**
	 * Render the general settings tab.
	 *
	 * @param array<string,mixed> $settings Settings.
	 * @return void
	 */
	private function render_settings_general_tab($settings) {
		?>
		<h2>Marke und Pflichtlinks</h2>
		<table class="form-table">
			<tr>
				<th scope="row"><label for="logo_url">Logo</label></th>
				<td>
					<div class="feuer-cookie-media-field">
						<input type="url" name="logo_url" id="logo_url" class="regular-text" value="<?php echo esc_attr($settings['logo_url']); ?>">
						<button type="button" class="button" data-feuer-cookie-media data-target="#logo_url">Bild auswaehlen</button>
					</div>
					<?php if (! empty($settings['logo_url'])) : ?>
						<img src="<?php echo esc_url($settings['logo_url']); ?>" alt="" class="feuer-cookie-logo-preview">
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="consent_revision">Einwilligungs-Revision</label></th>
				<td>
					<input type="text" name="consent_revision" id="consent_revision" class="regular-text" value="<?php echo esc_attr($settings['consent_revision']); ?>">
					<p class="description">Aendern, wenn sich Rechtstexte, Empfaenger oder Zwecke der Datenverarbeitung wesentlich aendern.</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="privacy_url">Datenschutz-URL</label></th>
				<td><input type="url" name="privacy_url" id="privacy_url" class="regular-text" value="<?php echo esc_attr($settings['privacy_url']); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><label for="imprint_url">Impressum-URL</label></th>
				<td><input type="url" name="imprint_url" id="imprint_url" class="regular-text" value="<?php echo esc_attr($settings['imprint_url']); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><label for="support_url">Support-/Kontakt-URL</label></th>
				<td><input type="url" name="support_url" id="support_url" class="regular-text" value="<?php echo esc_attr($settings['support_url']); ?>"></td>
			</tr>
		</table>

		<div class="feuer-cookie-grid feuer-cookie-grid--two feuer-cookie-settings-notes">
			<div class="feuer-cookie-card">
				<p class="feuer-cookie-card__label">Technische Basis</p>
				<h3 class="feuer-cookie-settings-tools__title">Mindestversionen fest hinterlegt</h3>
				<p class="feuer-cookie-card__hint">WordPress mindestens <code><?php echo esc_html(FEUER_COOKIE_MIN_WP_VERSION); ?></code>, PHP mindestens <code><?php echo esc_html(FEUER_COOKIE_MIN_PHP_VERSION); ?></code>. Diese Werte werden auch fuer Updates und Release-Dateien verwendet.</p>
				<p class="feuer-cookie-card__hint">Suchmaschinen und bekannte Robots erhalten kein Consent-Popup und keine geblockten eingebetteten Inhalte, damit die Indexierung sauber durchlaufen kann.</p>
			</div>
			<div class="feuer-cookie-card">
				<p class="feuer-cookie-card__label">Update-Kanal</p>
				<h3 class="feuer-cookie-settings-tools__title">Offizielle Seite</h3>
				<p class="feuer-cookie-card__hint"><a href="<?php echo esc_url(FEUER_COOKIE_HOME_URL); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html(FEUER_COOKIE_HOME_URL); ?></a></p>
				<p class="feuer-cookie-card__hint">Der Plugin-Link und der Auto-Update-Kanal zeigen auf die offizielle Seite von Walter Färber / wdmin.com.</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the banner settings tab.
	 *
	 * @param array<string,mixed> $settings Settings.
	 * @return void
	 */
	private function render_settings_banner_tab($settings) {
		?>
		<h2>Consent-Banner und Wiederoeffnen-Button</h2>
		<table class="form-table">
			<tr>
				<th scope="row"><label for="consent_template">Layout Sperrseite</label></th>
				<td>
					<select name="consent_template" id="consent_template">
						<option value="feuerwand" <?php selected($settings['consent_template'], 'feuerwand'); ?>>Feuerwand</option>
						<option value="kontrollkarte" <?php selected($settings['consent_template'], 'kontrollkarte'); ?>>Kontrollkarte</option>
						<option value="sicherheitsleiste" <?php selected($settings['consent_template'], 'sicherheitsleiste'); ?>>Sicherheitsleiste</option>
					</select>
					<p class="description">Wirkt nur auf die IP-Sperrseite, nicht auf das regulaere Consent-Banner.</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="banner_layout">Position Banner</label></th>
				<td>
					<select name="banner_layout" id="banner_layout">
						<?php foreach ($this->get_banner_layout_options() as $layout_key => $layout_label) : ?>
							<option value="<?php echo esc_attr($layout_key); ?>" <?php selected($settings['banner_layout'], $layout_key); ?>><?php echo esc_html($layout_label); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description">Mehrere Varianten fuer unten, oben, Mitte und kompakte Darstellungen.</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="agreement_title">Titel Banner</label></th>
				<td><input type="text" name="agreement_title" id="agreement_title" class="regular-text" value="<?php echo esc_attr($settings['agreement_title']); ?>"></td>
			</tr>
			<tr>
				<th scope="row">Text Banner</th>
				<td><?php wp_editor($settings['agreement_text'], 'feuer_cookie_agreement_text', array('textarea_name' => 'agreement_text', 'textarea_rows' => 12)); ?></td>
			</tr>
			<tr>
				<th scope="row"><label for="blocked_title">Titel IP-Sperre</label></th>
				<td><input type="text" name="blocked_title" id="blocked_title" class="regular-text" value="<?php echo esc_attr($settings['blocked_title']); ?>"></td>
			</tr>
			<tr>
				<th scope="row">Text IP-Sperre</th>
				<td><?php wp_editor($settings['blocked_text'], 'feuer_cookie_blocked_text', array('textarea_name' => 'blocked_text', 'textarea_rows' => 8)); ?></td>
			</tr>
			<tr>
				<th scope="row"><label for="accept_label">Button Alle akzeptieren</label></th>
				<td><input type="text" name="accept_label" id="accept_label" class="regular-text" value="<?php echo esc_attr($settings['accept_label']); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><label for="decline_label">Button Nur notwendige</label></th>
				<td><input type="text" name="decline_label" id="decline_label" class="regular-text" value="<?php echo esc_attr($settings['decline_label']); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><label for="save_label">Button Auswahl speichern</label></th>
				<td><input type="text" name="save_label" id="save_label" class="regular-text" value="<?php echo esc_attr($settings['save_label']); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><label for="settings_label">Button Einstellungen</label></th>
				<td><input type="text" name="settings_label" id="settings_label" class="regular-text" value="<?php echo esc_attr($settings['settings_label']); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><label for="reopen_label">Label Cookie-Icon</label></th>
				<td><input type="text" name="reopen_label" id="reopen_label" class="regular-text" value="<?php echo esc_attr($settings['reopen_label']); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><label for="panel_button_shape">Form Cookie-Buttons</label></th>
				<td>
					<select name="panel_button_shape" id="panel_button_shape">
						<?php foreach ($this->get_panel_button_shape_options() as $shape_key => $shape_label) : ?>
							<option value="<?php echo esc_attr($shape_key); ?>" <?php selected($settings['panel_button_shape'], $shape_key); ?>><?php echo esc_html($shape_label); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description">Rund, Keksform, quadratisch oder als breite Pill-Variante.</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="panel_button_color">Farbe Cookie-Buttons</label></th>
				<td>
					<select name="panel_button_color" id="panel_button_color">
						<?php foreach ($this->get_panel_button_color_options() as $color_key => $color_label) : ?>
							<option value="<?php echo esc_attr($color_key); ?>" <?php selected($settings['panel_button_color'], $color_key); ?>><?php echo esc_html($color_label); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description">Mehrere vordefinierte Farbstimmungen fuer unterschiedliche Marken- oder Theme-Farben.</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="panel_button_icon">Icon Cookie-Buttons</label></th>
				<td>
					<select name="panel_button_icon" id="panel_button_icon">
						<?php foreach ($this->get_panel_button_icon_options() as $icon_key => $icon_label) : ?>
							<option value="<?php echo esc_attr($icon_key); ?>" <?php selected($settings['panel_button_icon'], $icon_key); ?>><?php echo esc_html($icon_label); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description">Keks, Schild, Zahnrad, Schloss oder Chart fuer die Wiederoeffnen-Schaltflaeche.</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="service_allow_label">Button einzelner Dienst</label></th>
				<td><input type="text" name="service_allow_label" id="service_allow_label" class="regular-text" value="<?php echo esc_attr($settings['service_allow_label']); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><label for="delete_label">Button Einwilligung loeschen</label></th>
				<td><input type="text" name="delete_label" id="delete_label" class="regular-text" value="<?php echo esc_attr($settings['delete_label']); ?>"></td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Render the service overview tab.
	 *
	 * @param array<string,array<string,mixed>> $categories Categories.
	 * @param array<string,array<string,mixed>> $vendors Service definitions.
	 * @return void
	 */
	private function render_settings_services_tab($categories, $vendors) {
		$detected_count = 0;
		$required_count = 0;

		foreach ($vendors as $vendor) {
			if (! empty($vendor['detected'])) {
				$detected_count++;
			}

			if (! empty($vendor['required'])) {
				$required_count++;
			}
		}
		?>
		<h2>Kategorien und Dienste</h2>
		<p class="description">Diese Liste wird aus den Plugin-Standards, registrierten Diensten und dem letzten Diagnosescan aufgebaut. Nach Aenderungen an Theme oder Plugins bitte die Diagnose erneut starten.</p>

		<div class="feuer-cookie-grid feuer-cookie-grid--cards feuer-cookie-settings-notes">
			<div class="feuer-cookie-card">
				<p class="feuer-cookie-card__label">Kategorien</p>
				<p class="feuer-cookie-card__value"><?php echo esc_html(number_format_i18n(count($categories))); ?></p>
				<p class="feuer-cookie-card__hint">Alle Consent-Bereiche, die im Banner angezeigt werden koennen.</p>
			</div>
			<div class="feuer-cookie-card">
				<p class="feuer-cookie-card__label">Dienste gesamt</p>
				<p class="feuer-cookie-card__value"><?php echo esc_html(number_format_i18n(count($vendors))); ?></p>
				<p class="feuer-cookie-card__hint">Registrierte und erkannte Dienste in der aktuellen Installation.</p>
			</div>
			<div class="feuer-cookie-card">
				<p class="feuer-cookie-card__label">Automatisch erkannt</p>
				<p class="feuer-cookie-card__value"><?php echo esc_html(number_format_i18n($detected_count)); ?></p>
				<p class="feuer-cookie-card__hint">Dienste aus dem letzten Diagnose-Scan.</p>
			</div>
			<div class="feuer-cookie-card">
				<p class="feuer-cookie-card__label">Immer aktiv</p>
				<p class="feuer-cookie-card__value"><?php echo esc_html(number_format_i18n($required_count)); ?></p>
				<p class="feuer-cookie-card__hint">Technisch notwendige Dienste ohne Opt-in.</p>
			</div>
		</div>

		<table class="widefat striped">
			<thead>
				<tr>
					<th>Kategorie</th>
					<th>Dienst</th>
					<th>Zweck</th>
					<th>Rechtsgrundlage</th>
					<th>Datenschutz</th>
					<th>Status</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ($vendors as $vendor) : ?>
					<tr>
						<td><?php echo esc_html($categories[ $vendor['category'] ]['label'] ?? $vendor['category']); ?></td>
						<td><?php echo esc_html((string) $vendor['label']); ?></td>
						<td><?php echo esc_html((string) $vendor['purpose']); ?></td>
						<td><?php echo esc_html((string) $vendor['legal_basis']); ?></td>
						<td>
							<?php if (! empty($vendor['privacy_url'])) : ?>
								<a href="<?php echo esc_url((string) $vendor['privacy_url']); ?>" target="_blank" rel="noopener noreferrer">Link</a>
							<?php else : ?>
								<span>-</span>
							<?php endif; ?>
						</td>
						<td><?php echo ! empty($vendor['required']) ? 'Immer aktiv' : (! empty($vendor['detected']) ? 'Erkannt' : 'Vordefiniert'); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render the storage settings tab.
	 *
	 * @param array<string,mixed> $settings Settings.
	 * @return void
	 */
	private function render_settings_privacy_tab($settings) {
		?>
		<h2>Speicherung und Betrieb</h2>
		<table class="form-table">
			<tr>
				<th scope="row"><label for="cookie_name">Cookie-Name</label></th>
				<td>
					<input type="text" name="cookie_name" id="cookie_name" class="regular-text" value="<?php echo esc_attr($settings['cookie_name']); ?>">
					<p class="description">Basename fuer Consent-, Revisions-, Praeferenz- und Session-Cookies.</p>
				</td>
			</tr>
			<tr>
						<th scope="row"><label for="consent_event_retention_days">Aufbewahrung Consent- und Sicherheitsereignisse (Tage)</label></th>
				<td>
					<input type="number" min="30" max="1825" name="consent_event_retention_days" id="consent_event_retention_days" value="<?php echo esc_attr((string) ($settings['consent_event_retention_days'] ?? 365)); ?>">
					<p class="description">Consent-Entscheidungen koennen laenger als normale Rohereignisse aufbewahrt werden.</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="consent_cookie_days">Laufzeit Consent-Cookie (Tage)</label></th>
				<td><input type="number" min="1" max="3650" name="consent_cookie_days" id="consent_cookie_days" value="<?php echo esc_attr((string) $settings['consent_cookie_days']); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><label for="analytics_retention_days">Aufbewahrung Statistikdaten (Tage)</label></th>
				<td>
					<input type="number" min="30" max="365" name="analytics_retention_days" id="analytics_retention_days" value="<?php echo esc_attr((string) ($settings['analytics_retention_days'] ?? 90)); ?>">
					<p class="description">Seitenaufrufe und anonyme Sitzungskennungen werden nach diesem Zeitraum automatisch gelöscht.</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="overlay_opacity">Hintergrund abdunkeln (%)</label></th>
				<td>
					<input type="number" min="0" max="90" name="overlay_opacity" id="overlay_opacity" value="<?php echo esc_attr((string) $settings['overlay_opacity']); ?>">
					<p class="description">0 = keine Abdunkelung, 40 = empfohlener Standard, 90 = sehr dunkel.</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="blocking_mode">Blockierung bis Einwilligung</label></th>
				<td>
					<select name="blocking_mode" id="blocking_mode">
						<option value="strict" <?php selected($settings['blocking_mode'], 'strict'); ?>>Strikt blockieren</option>
						<option value="off" <?php selected($settings['blocking_mode'], 'off'); ?>>Nur dokumentieren</option>
					</select>
					<p class="description">Im strikten Modus werden erkannte externe Skripte, Styles und Iframes bis zur Zustimmung blockiert.</p>
				</td>
			</tr>
			<tr>
				<th scope="row">Admins umgehen Gate</th>
				<td><label><input type="checkbox" name="admin_bypass" value="1" <?php checked($settings['admin_bypass'], 1); ?>> Ja, eingeloggte Administratoren sehen die Website ohne Consent-Banner.</label></td>
			</tr>
			<tr>
				<th scope="row">Feuer Analytics synchronisieren</th>
				<td>
					<label><input type="checkbox" name="feuer_analytics_sync" value="1" <?php checked(! empty($settings['feuer_analytics_sync'])); ?>> Feuer Analytics als optionalen Statistik-Dienst aktivieren</label>
					<p class="description">Nur bei aktivierter Synchronisierung wird der Dienst im Banner angeboten. Feuer Analytics erhält ausschließlich die Entscheidung für den aktuellen Aufruf; Cookie-Nachweise, IP-Adressen und Besucherprofile werden nicht übertragen.</p>
				</td>
			</tr>
			<tr>
				<th scope="row">Cookie-Icon im Frontend</th>
				<td>
					<p>Die Wiederoeffnen-Schaltflaeche wird auf freigegebenen Seiten immer angezeigt. Form, Farbe und Icon koennen in der Registerkarte Banner angepasst werden.</p>
				</td>
			</tr>
		</table>

		<div class="feuer-cookie-grid feuer-cookie-grid--two feuer-cookie-settings-notes">
			<div class="feuer-cookie-card">
				<p class="feuer-cookie-card__label">Datensparsamkeit</p>
				<h3 class="feuer-cookie-settings-tools__title">Datensparsame Consent-Nachweise</h3>
				<p class="feuer-cookie-card__hint">Consent-Nachweise speichern ausschließlich eine maskierte IP und keine Besucher-, Geräte-, Standort- oder Referrer-Daten.</p>
			</div>
			<div class="feuer-cookie-card">
				<p class="feuer-cookie-card__label">Betrieb</p>
				<h3 class="feuer-cookie-settings-tools__title">Consent sauber blockieren</h3>
				<p class="feuer-cookie-card__hint">Mit <code>Strikt blockieren</code> bleiben erkannte externe Ressourcen bis zur Einwilligung gesperrt. Das ist fuer echte Compliance die sichere Voreinstellung.</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the export and revision tab.
	 *
	 * @param array<int,array<string,string>> $revision_log Revision history.
	 * @param string                          $config_export JSON export.
	 * @return void
	 */
	private function render_settings_export_tab($revision_log, $config_export) {
		?>
		<div class="feuer-cookie-grid feuer-cookie-grid--two">
			<div class="feuer-cookie-panel feuer-cookie-panel--nested">
				<h2>Journal der Consent-Versionen</h2>
				<table class="widefat striped">
					<thead>
						<tr>
							<th>Zeitpunkt</th>
							<th>Revision</th>
							<th>Titel</th>
							<th>Config-Hash</th>
						</tr>
					</thead>
					<tbody>
						<?php if ($revision_log) : ?>
							<?php foreach ($revision_log as $entry) : ?>
								<tr>
									<td><?php echo esc_html((string) ($entry['saved_at'] ?? '')); ?></td>
									<td><?php echo esc_html((string) ($entry['revision'] ?? '')); ?></td>
									<td><?php echo esc_html((string) ($entry['banner_title'] ?? '')); ?></td>
									<td><code><?php echo esc_html((string) ($entry['config_hash'] ?? '')); ?></code></td>
								</tr>
							<?php endforeach; ?>
						<?php else : ?>
							<tr><td colspan="4">Noch keine Revisionen protokolliert.</td></tr>
						<?php endif; ?>
					</tbody>
				</table>
			</div>

			<div class="feuer-cookie-panel feuer-cookie-panel--nested">
				<h2>Export der Konfiguration</h2>
				<p class="description">Diese JSON-Ausgabe enthaelt Bannertexte, Kategorien, Dienste, Betriebsparameter und das Revisionsjournal.</p>
				<textarea class="large-text code" rows="18" readonly><?php echo esc_textarea($config_export); ?></textarea>
			</div>
		</div>
		<?php
	}

	/**
	 * Render docs page.
	 *
	 * @return void
	 */
	public function render_docs_page() {
		$this->render_admin_header('Dokumentation', 'Projektdateien und interne Leitlinien für den Betrieb des Plugins.');

		$guide_path   = FEUER_COOKIE_PATH . 'ANLEITUNG.md';
		$history_path = FEUER_COOKIE_PATH . 'HISTORIE.md';

		?>
		<div class="feuer-cookie-grid feuer-cookie-grid--two">
			<div class="feuer-cookie-panel">
				<h2>Projektdateien</h2>
				<ul class="feuer-cookie-notes">
					<li><code><?php echo esc_html($guide_path); ?></code></li>
					<li><code><?php echo esc_html($history_path); ?></code></li>
				</ul>
				<p>Diese Dateien gehören zum Plugin-Paket und dokumentieren Funktionsumfang, Rechtshinweise, Einrichtung und Versionsentwicklung vollständig auf Deutsch.</p>
			</div>

			<div class="feuer-cookie-panel">
				<h2>Betriebshinweise</h2>
				<ul class="feuer-cookie-notes">
					<li>Nach Änderungen an Rechtstexten die Einwilligungs-Revision erhöhen.</li>
					<li>Nach Theme-/Plugin-Wechseln die Autodiagnose erneut starten.</li>
					<li>Rohlog-Aufbewahrung regelmäßig an den tatsächlichen Zweck anpassen.</li>
				</ul>
			</div>
		</div>

		<div class="feuer-cookie-panel">
			<h2>Makros für Theme-Dateien</h2>
			<p>Für eigene Theme-Dateien oder Seitenersteller stehen lokale Makros ohne externe Quellen bereit.</p>
			<ul class="feuer-cookie-notes">
				<li><code>&lt;?php if ( function_exists('feuer_cookie_header_macro') ) { feuer_cookie_header_macro(); } ?&gt;</code></li>
				<li><code>&lt;?php if ( function_exists('feuer_cookie_footer_macro') ) { feuer_cookie_footer_macro(); } ?&gt;</code></li>
				<li><code>[feuer_cookie_header_macro]</code></li>
				<li><code>[feuer_cookie_footer_macro]</code></li>
				<li><code>feuer_cookie_has_category_consent('maps')</code></li>
				<li><code>ff_cookie_has_consent('leaflet_osm')</code></li>
				<li><code>[feuer_cookie_service_gate service="leaflet_osm"]...[/feuer_cookie_service_gate]</code></li>
			</ul>
		</div>
		<?php

		$this->render_admin_footer();
	}

	/**
	 * Render the admin header and notices.
	 *
	 * @param string $title Page title.
	 * @param string $subtitle Subtitle text.
	 * @return void
	 */
	private function render_admin_header($title, $subtitle) {
		$notice = sanitize_key(wp_unslash($_GET['feuer_cookie_notice'] ?? ''));

		echo '<div class="wrap feuer-cookie-admin">';
		echo '<h1>' . esc_html($title) . '</h1>';
		echo '<p class="description feuer-cookie-admin__intro">' . esc_html($subtitle) . '</p>';

		if ($notice) {
			echo '<div class="notice ' . esc_attr($this->notice_css_class($notice)) . ' is-dismissible"><p>' . esc_html($this->notice_label($notice)) . '</p></div>';
		}
	}

	/**
	 * Render the admin footer wrapper.
	 *
	 * @return void
	 */
	private function render_admin_footer() {
		echo '</div>';
	}

	/**
	 * Render one stat card.
	 *
	 * @param string $label Label.
	 * @param int    $value Value.
	 * @param string $hint Hint text.
	 * @return void
	 */
	private function render_stat_card($label, $value, $hint) {
		$display_value = is_numeric($value) ? number_format_i18n($value) : (string) $value;
		?>
		<div class="feuer-cookie-card">
			<p class="feuer-cookie-card__label"><?php echo esc_html($label); ?></p>
			<p class="feuer-cookie-card__value"><?php echo esc_html($display_value); ?></p>
			<p class="feuer-cookie-card__hint"><?php echo esc_html($hint); ?></p>
		</div>
		<?php
	}

	/**
	 * Build an hourly page-view series for the admin dashboard.
	 *
	 * @param int $hours Number of hours.
	 * @return array<int,array<string,mixed>>
	 */
	private function get_hourly_page_view_series($hours = 48) {
		global $wpdb;

		$hours   = max(1, (int) $hours);
		$end_ts  = current_time('timestamp');
		$end_ts -= ((int) wp_date('i', $end_ts) * MINUTE_IN_SECONDS) + (int) wp_date('s', $end_ts);
		$start_ts    = $end_ts - (($hours - 1) * HOUR_IN_SECONDS);
		$start_label = wp_date('Y-m-d H:i:s', $start_ts);
		$events_table = $this->table_sql('events');
		$rows        = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE_FORMAT(created_at, '%%Y-%%m-%%d %%H:00:00') AS bucket, COUNT(*) AS hits
				FROM {$events_table}
				WHERE request_type = 'page_view' AND is_robot = 0 AND created_at >= %s
				GROUP BY bucket
				ORDER BY bucket ASC",
				$start_label
			),
			ARRAY_A
		);
		$lookup = array();
		$series = array();

		foreach ($rows as $row) {
			$lookup[ (string) $row['bucket'] ] = (int) $row['hits'];
		}

		for ($i = 0; $i < $hours; $i++) {
			$timestamp = $start_ts + ($i * HOUR_IN_SECONDS);
			$bucket    = wp_date('Y-m-d H:00:00', $timestamp);

			$series[] = array(
				'bucket' => $bucket,
				'label'  => wp_date('d.m H:i', $timestamp),
				'value'  => (int) ($lookup[ $bucket ] ?? 0),
			);
		}

		return $series;
	}

	/**
	 * Build a day-by-day page-view series for the admin dashboard.
	 *
	 * @param int $days Number of days.
	 * @return array<int,array<string,mixed>>
	 */
	private function get_daily_page_view_series($days = 30) {
		global $wpdb;

		$days              = max(1, min(30, (int) $days));
		$end_ts            = current_time('timestamp');
		$start_ts          = $end_ts - (($days - 1) * DAY_IN_SECONDS);
		$start_day         = wp_date('Y-m-d', $start_ts);
		$daily_stats_table = $this->table_sql('daily_stats');
		$rows              = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT day, page_views, unique_visitors, consents_accept, consents_decline, blocked_requests
				FROM {$daily_stats_table}
				WHERE day >= %s
				ORDER BY day ASC",
				$start_day
			),
			ARRAY_A
		);
		$lookup = array();
		$series = array();

		foreach ($rows as $row) {
			$lookup[ (string) $row['day'] ] = $row;
		}

		for ($i = 0; $i < $days; $i++) {
			$timestamp = $start_ts + ($i * DAY_IN_SECONDS);
			$day       = wp_date('Y-m-d', $timestamp);
			$row       = $lookup[ $day ] ?? array();

			$series[] = array(
				'bucket'           => $day,
				'label'            => wp_date('d.m', $timestamp),
				'value'            => (int) ($row['page_views'] ?? 0),
				'unique_visitors'  => (int) ($row['unique_visitors'] ?? 0),
				'consents_accept'  => (int) ($row['consents_accept'] ?? 0),
				'consents_decline' => (int) ($row['consents_decline'] ?? 0),
				'blocked_requests' => (int) ($row['blocked_requests'] ?? 0),
			);
		}

		return $series;
	}

	/**
	 * Return dashboard URL for one daily offset.
	 *
	 * @param int $offset Number of days back.
	 * @return string
	 */
	private function get_overview_day_url($offset) {
		$offset = max(0, min(30, (int) $offset));

		return add_query_arg(
			array(
				'page'                    => 'feuer-cookie',
				'feuer_cookie_day_offset' => $offset,
			),
			admin_url('admin.php')
		);
	}

	/**
	 * Get aggregate stats for one selected day.
	 *
	 * @param string $day Date in Y-m-d format.
	 * @return array<string,int|string>
	 */
	private function get_daily_stats_for_day($day) {
		global $wpdb;

		$daily_stats_table = $this->table_sql('daily_stats');
		$row               = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT day, page_views, unique_visitors, consents_accept, consents_decline, blocked_requests
				FROM {$daily_stats_table}
				WHERE day = %s
				LIMIT 1",
				$day
			),
			ARRAY_A
		);

		if ($row) {
			return $row;
		}

		return array(
			'day'              => $day,
			'page_views'       => 0,
			'unique_visitors'  => 0,
			'consents_accept'  => 0,
			'consents_decline' => 0,
			'blocked_requests' => 0,
		);
	}

	/**
	 * Get top pages for one selected day.
	 *
	 * @param string $day Date in Y-m-d format.
	 * @param int    $limit Maximum result count.
	 * @return array<int,array<string,mixed>>
	 */
	private function get_top_pages_for_day($day, $limit = 8) {
		global $wpdb;

		$limit        = max(1, (int) $limit);
		$events_table = $this->table_sql('events');

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT request_path, COUNT(*) AS hits
				FROM {$events_table}
				WHERE request_type = 'page_view'
					AND is_robot = 0
					AND DATE(created_at) = %s
				GROUP BY request_path
				ORDER BY hits DESC, request_path ASC
				LIMIT %d",
				$day,
				$limit
			),
			ARRAY_A
		);
	}

	/**
	 * Get top visitor locations for a recent period.
	 *
	 * @param int $days Number of days.
	 * @param int $limit Maximum result count.
	 * @return array<int,array<string,mixed>>
	 */
	private function get_top_location_rows($days = 14, $limit = 8) {
		global $wpdb;

		$days         = max(1, (int) $days);
		$limit        = max(1, (int) $limit);
		$since        = wp_date('Y-m-d H:i:s', current_time('timestamp') - ($days * DAY_IN_SECONDS));
		$events_table = $this->table_sql('events');

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					CASE
						WHEN city <> '' AND country_name <> '' THEN CONCAT(city, ', ', country_name)
						WHEN city <> '' AND country_code <> '' THEN CONCAT(city, ', ', country_code)
						WHEN country_name <> '' THEN country_name
						WHEN country_code <> '' THEN country_code
						ELSE 'Unbekannt'
					END AS label,
					COUNT(*) AS hits
				FROM {$events_table}
				WHERE request_type = 'page_view'
					AND is_robot = 0
					AND created_at >= %s
				GROUP BY label
				ORDER BY hits DESC, label ASC
				LIMIT %d",
				$since,
				$limit
			),
			ARRAY_A
		);
	}

	/**
	 * Get countries as a separate dimension for the coloured coverage display.
	 *
	 * @param int $days Number of days.
	 * @param int $limit Maximum result count.
	 * @return array<int,array<string,mixed>>
	 */
	private function get_top_country_rows($days = 30, $limit = 24) {
		global $wpdb;

		$since = wp_date('Y-m-d H:i:s', current_time('timestamp') - (max(1, (int) $days) * DAY_IN_SECONDS));
		$events_table = $this->table_sql('events');

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT country_code, country_name, COUNT(*) AS hits
				FROM {$events_table}
				WHERE request_type = 'page_view' AND is_robot = 0 AND country_code <> '' AND created_at >= %s
				GROUP BY country_code, country_name
				ORDER BY hits DESC, country_name ASC
				LIMIT %d",
				$since,
				max(1, (int) $limit)
			),
			ARRAY_A
		);
	}

	/**
	 * Summarise traffic in the requested reporting windows.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function get_traffic_period_summaries() {
		global $wpdb;

		$events_table = $this->table_sql('events');
		$periods = array(7 => 'Woche', 30 => 'Monat', 90 => 'Quartal', 183 => 'Halbjahr');
		$results = array();

		foreach ($periods as $days => $label) {
			$since = wp_date('Y-m-d H:i:s', current_time('timestamp') - ($days * DAY_IN_SECONDS));
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT COUNT(*) AS views, COUNT(DISTINCT session_token) AS visitors
					FROM {$events_table}
					WHERE request_type = 'page_view' AND is_robot = 0 AND created_at >= %s",
					$since
				),
				ARRAY_A
			);
			$results[] = array('label' => $label, 'days' => $days, 'views' => (int) ($row['views'] ?? 0), 'visitors' => (int) ($row['visitors'] ?? 0));
		}

		return $results;
	}

	/**
	 * Aggregate opted-in screen/viewport dimensions from engagement events.
	 *
	 * @param int $days Number of days.
	 * @param int $limit Maximum result count.
	 * @return array<int,array<string,mixed>>
	 */
	private function get_screen_dimension_rows($days = 30, $limit = 8) {
		global $wpdb;

		$since = wp_date('Y-m-d H:i:s', current_time('timestamp') - (max(1, (int) $days) * DAY_IN_SECONDS));
		$events_table = $this->table_sql('events');
		$rows = $wpdb->get_results(
			$wpdb->prepare("SELECT meta_json FROM {$events_table} WHERE request_type = 'page_engagement' AND is_robot = 0 AND created_at >= %s ORDER BY created_at DESC LIMIT 5000", $since),
			ARRAY_A
		);
		$counts = array();

		foreach ($rows as $row) {
			$meta = json_decode((string) ($row['meta_json'] ?? ''), true);
			$width = max(0, (int) ($meta['viewport_width'] ?? $meta['screen_width'] ?? 0));
			$height = max(0, (int) ($meta['viewport_height'] ?? $meta['screen_height'] ?? 0));

			if (! $width || ! $height) {
				continue;
			}

			$label = $width . ' × ' . $height;
			$counts[ $label ] = ($counts[ $label ] ?? 0) + 1;
		}

		$items = array();
		foreach ($counts as $label => $hits) {
			$items[] = array('label' => $label, 'hits' => $hits);
		}

		usort($items, static function ($left, $right) { return (int) $right['hits'] <=> (int) $left['hits']; });
		return array_slice($items, 0, max(1, (int) $limit));
	}

	/**
	 * Aggregate traffic that arrived through external referrers.
	 *
	 * @param int $days Number of days.
	 * @param int $limit Maximum result count.
	 * @return array<int,array<string,mixed>>
	 */
	private function get_external_referrer_source_rows($days = 30, $limit = 12) {
		global $wpdb;

		$since = wp_date('Y-m-d H:i:s', current_time('timestamp') - (max(1, (int) $days) * DAY_IN_SECONDS));
		$events_table = $this->table_sql('events');
		$site_host = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT referrer_url FROM {$events_table}
				WHERE request_type = 'page_view' AND is_robot = 0 AND referrer_url <> '' AND created_at >= %s
				ORDER BY created_at DESC LIMIT 5000",
				$since
			),
			ARRAY_A
		);
		$sources = array();

		foreach ($rows as $row) {
			$host = strtolower((string) wp_parse_url((string) ($row['referrer_url'] ?? ''), PHP_URL_HOST));

			if (! $host || $host === $site_host || (strlen($host) > strlen($site_host) && substr($host, -strlen('.' . $site_host)) === '.' . $site_host)) {
				continue;
			}

			$search_name = $this->get_search_engine_name($host);
			$key = $search_name ? 'search:' . $search_name : 'site:' . $host;
			if (! isset($sources[ $key ])) {
				$sources[ $key ] = array(
					'label' => $search_name ? 'Suche · ' . $search_name : $host,
					'hits'  => 0,
					'type'  => $search_name ? 'search' : 'referral',
				);
			}
			$sources[ $key ]['hits']++;
		}

		$sources = array_values($sources);
		usort($sources, static function ($left, $right) { return (int) $right['hits'] <=> (int) $left['hits']; });

		return array_slice($sources, 0, max(1, (int) $limit));
	}

	/**
	 * Return a human-readable search engine name for a referrer host.
	 *
	 * @param string $host Referrer host.
	 * @return string
	 */
	private function get_search_engine_name($host) {
		$engines = array(
			'google.' => 'Google',
			'bing.com' => 'Bing',
			'yahoo.' => 'Yahoo',
			'duckduckgo.com' => 'DuckDuckGo',
			'yandex.' => 'Yandex',
			'baidu.com' => 'Baidu',
			'ecosia.org' => 'Ecosia',
			'startpage.com' => 'Startpage',
			'brave.com' => 'Brave Search',
		);

		foreach ($engines as $needle => $name) {
			if (false !== strpos($host, $needle)) {
				return $name;
			}
		}

		return '';
	}

	/**
	 * Get top rows for one stored client dimension.
	 *
	 * @param string $dimension Stored column name.
	 * @param int    $days Number of days.
	 * @param int    $limit Maximum result count.
	 * @return array<int,array<string,mixed>>
	 */
	private function get_top_dimension_rows($dimension, $days = 14, $limit = 8) {
		global $wpdb;

		$allowed = array('device_type', 'browser_name', 'os_name');

		if (! in_array($dimension, $allowed, true)) {
			return array();
		}

		$days         = max(1, (int) $days);
		$limit        = max(1, (int) $limit);
		$since        = wp_date('Y-m-d H:i:s', current_time('timestamp') - ($days * DAY_IN_SECONDS));
		$events_table = $this->table_sql('events');
		$rows         = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT device_type, browser_name, os_name, user_agent
				FROM {$events_table}
				WHERE request_type = 'page_view'
					AND is_robot = 0
					AND created_at >= %s
				ORDER BY created_at DESC
				LIMIT 5000",
				$since
			),
			ARRAY_A
		);

		return $this->aggregate_client_dimension_rows($rows, $dimension, $limit);
	}

	/**
	 * Get consent confirmations by one client dimension.
	 *
	 * @param string $dimension Stored column name.
	 * @param int    $days Number of days.
	 * @param int    $limit Maximum result count.
	 * @return array<int,array<string,mixed>>
	 */
	private function get_consent_dimension_rows($dimension, $days = 30, $limit = 8) {
		global $wpdb;

		$allowed = array('device_type', 'browser_name', 'os_name');

		if (! in_array($dimension, $allowed, true)) {
			return array();
		}

		$days         = max(1, (int) $days);
		$limit        = max(1, (int) $limit);
		$since        = wp_date('Y-m-d H:i:s', current_time('timestamp') - ($days * DAY_IN_SECONDS));
		$events_table = $this->table_sql('events');
		$rows         = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT device_type, browser_name, os_name, user_agent
				FROM {$events_table}
				WHERE request_type IN ('consent_accept_all', 'consent_custom', 'consent_necessary', 'consent_service_allow')
					AND is_robot = 0
					AND created_at >= %s
				ORDER BY created_at DESC
				LIMIT 5000",
				$since
			),
			ARRAY_A
		);

		return $this->aggregate_client_dimension_rows($rows, $dimension, $limit);
	}

	/**
	 * Aggregate stored or parsed client dimension rows.
	 *
	 * @param array<int,array<string,mixed>> $rows Event rows.
	 * @param string                         $dimension Dimension key.
	 * @param int                            $limit Maximum result count.
	 * @return array<int,array<string,mixed>>
	 */
	private function aggregate_client_dimension_rows($rows, $dimension, $limit) {
		$aggregates = array();

		foreach ($rows as $row) {
			$label = trim((string) ($row[ $dimension ] ?? ''));

			if ('' === $label) {
				$profile = $this->parse_client_profile((string) ($row['user_agent'] ?? ''));
				$label   = (string) ($profile[ $dimension ] ?? 'Unbekannt');
			}

			if ('' === $label) {
				$label = 'Unbekannt';
			}

			if (! isset($aggregates[ $label ])) {
				$aggregates[ $label ] = array(
					'label' => $label,
					'hits'  => 0,
				);
			}

			$aggregates[ $label ]['hits']++;
		}

		$aggregates = array_values($aggregates);

		usort(
			$aggregates,
			static function ($left, $right) {
				$hits_compare = (int) ($right['hits'] ?? 0) <=> (int) ($left['hits'] ?? 0);

				if (0 !== $hits_compare) {
					return $hits_compare;
				}

				return strcmp((string) ($left['label'] ?? ''), (string) ($right['label'] ?? ''));
			}
		);

		return array_slice($aggregates, 0, max(1, (int) $limit));
	}

	/**
	 * Build a ranked list of visitor IP entries for the admin dashboard.
	 *
	 * @param int $days Number of days.
	 * @param int $limit Maximum result count.
	 * @return array<int,array<string,mixed>>
	 */
	private function get_top_ip_rows($days = 14, $limit = 8) {
		global $wpdb;

		$days        = max(1, (int) $days);
		$limit       = max(1, (int) $limit);
		$since       = wp_date('Y-m-d H:i:s', current_time('timestamp') - ($days * DAY_IN_SECONDS));
		$events_table = $this->table_sql('events');

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ip_address, COUNT(*) AS hits
				FROM {$events_table}
				WHERE request_type = 'page_view'
					AND is_robot = 0
					AND ip_address <> ''
					AND created_at >= %s
				GROUP BY ip_address
				ORDER BY hits DESC, ip_address ASC
				LIMIT %d",
				$since,
				$limit
			),
			ARRAY_A
		);
	}

	/**
	 * Aggregate engagement events per page for admin charts and tables.
	 *
	 * @param int $days Number of days.
	 * @param int $limit Maximum result count.
	 * @return array<int,array<string,mixed>>
	 */
	private function get_page_engagement_rows($days = 14, $limit = 8) {
		global $wpdb;

		$days         = max(1, (int) $days);
		$limit        = max(1, (int) $limit);
		$since        = wp_date('Y-m-d H:i:s', current_time('timestamp') - ($days * DAY_IN_SECONDS));
		$events_table = $this->table_sql('events');
		$rows         = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT request_path, page_title, meta_json
				FROM {$events_table}
				WHERE request_type = 'page_engagement'
					AND is_robot = 0
					AND created_at >= %s
				ORDER BY created_at DESC
				LIMIT 4000",
				$since
			),
			ARRAY_A
		);
		$aggregates = array();

		foreach ($rows as $row) {
			$path = (string) ($row['request_path'] ?? '/');
			$meta = json_decode((string) ($row['meta_json'] ?? ''), true);

			if (! is_array($meta)) {
				continue;
			}

			if (! isset($aggregates[ $path ])) {
				$aggregates[ $path ] = array(
					'request_path'         => $path,
					'page_title'           => (string) ($row['page_title'] ?? ''),
					'views'                => 0,
					'total_duration'       => 0,
					'total_active'         => 0,
					'total_scroll_depth'   => 0,
					'avg_duration_seconds' => 0,
					'avg_active_seconds'   => 0,
					'avg_scroll_depth'     => 0,
				);
			}

			$aggregates[ $path ]['views']++;
			$aggregates[ $path ]['total_duration'] += max(0, (int) ($meta['duration_seconds'] ?? 0));
			$aggregates[ $path ]['total_active'] += max(0, (int) ($meta['active_seconds'] ?? 0));
			$aggregates[ $path ]['total_scroll_depth'] += max(0, min(100, (int) ($meta['scroll_depth'] ?? 0)));

			if ('' === (string) $aggregates[ $path ]['page_title'] && ! empty($row['page_title'])) {
				$aggregates[ $path ]['page_title'] = (string) $row['page_title'];
			}
		}

		foreach ($aggregates as $path => $aggregate) {
			$views = max(1, (int) $aggregate['views']);

			$aggregates[ $path ]['avg_duration_seconds'] = (int) round(((int) $aggregate['total_duration']) / $views);
			$aggregates[ $path ]['avg_active_seconds']   = (int) round(((int) $aggregate['total_active']) / $views);
			$aggregates[ $path ]['avg_scroll_depth']     = (int) round(((int) $aggregate['total_scroll_depth']) / $views);
		}

		$aggregates = array_values($aggregates);

		usort(
			$aggregates,
			static function ($left, $right) {
				$left_active  = (int) ($left['total_active'] ?? 0);
				$right_active = (int) ($right['total_active'] ?? 0);

				if ($left_active === $right_active) {
					return (int) ($right['views'] ?? 0) <=> (int) ($left['views'] ?? 0);
				}

				return $right_active <=> $left_active;
			}
		);

		return array_slice($aggregates, 0, $limit);
	}

	/**
	 * Return the peak point from a chart series.
	 *
	 * @param array<int,array<string,mixed>> $series Series values.
	 * @return array<string,mixed>
	 */
	private function get_peak_chart_point($series) {
		$peak = array(
			'label' => '-',
			'value' => 0,
		);

		foreach ($series as $point) {
			$value = (int) ($point['value'] ?? 0);

			if ($value > (int) $peak['value']) {
				$peak = $point;
			}
		}

		return $peak;
	}

	/**
	 * Render one compact chart meta item.
	 *
	 * @param string $label Label.
	 * @param string $value Main value.
	 * @param string $subvalue Optional subvalue.
	 * @return void
	 */
	private function render_chart_meta_item($label, $value, $subvalue = '') {
		?>
		<div class="feuer-cookie-chart__meta-item">
			<span class="feuer-cookie-chart__meta-label"><?php echo esc_html($label); ?></span>
			<strong class="feuer-cookie-chart__meta-value"><?php echo esc_html($value); ?></strong>
			<?php if ('' !== $subvalue) : ?>
				<span class="feuer-cookie-chart__meta-subvalue"><?php echo esc_html($subvalue); ?></span>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render an SVG area chart for hourly traffic.
	 *
	 * @param array<int,array<string,mixed>> $series Hourly series.
	 * @return void
	 */
	private function render_hourly_area_chart($series) {
		if (empty($series)) {
			echo '<div class="feuer-cookie-chart__empty">Noch keine Seitenaufrufe fuer das Diagramm vorhanden.</div>';
			return;
		}

		$values        = array_map('intval', wp_list_pluck($series, 'value'));
		$count         = count($series);
		$width         = 860;
		$height        = 280;
		$padding_left  = 56;
		$padding_right = 20;
		$padding_top   = 20;
		$padding_bottom = 42;
		$plot_width    = max(1, $width - $padding_left - $padding_right);
		$plot_height   = max(1, $height - $padding_top - $padding_bottom);
		$max_value     = max(1, max($values));
		$max_scale     = max(4, (int) ceil($max_value / 4) * 4);
		$points        = array();
		$grid_steps    = 4;
		$tick_indices  = $this->get_chart_tick_indices($count, 6);
		$baseline_y    = $padding_top + $plot_height;
		$peak_index    = array_search(max($values), $values, true);

		foreach ($series as $index => $point) {
			$value = (int) ($point['value'] ?? 0);
			$x     = 1 === $count ? $padding_left + ($plot_width / 2) : $padding_left + ($index * $plot_width / ($count - 1));
			$y     = $baseline_y - (($value / $max_scale) * $plot_height);

			$points[] = array(
				'x'     => round($x, 2),
				'y'     => round($y, 2),
				'label' => (string) ($point['label'] ?? ''),
				'value' => $value,
			);
		}

		$line_path = $this->build_svg_path_from_points($points);

		if (empty($line_path)) {
			echo '<div class="feuer-cookie-chart__empty">Noch keine Seitenaufrufe fuer das Diagramm vorhanden.</div>';
			return;
		}

		$first_point = reset($points);
		$last_point  = end($points);
		$area_path   = $line_path . ' L ' . $last_point['x'] . ' ' . $baseline_y . ' L ' . $first_point['x'] . ' ' . $baseline_y . ' Z';
		$peak_point  = isset($points[ $peak_index ]) ? $points[ $peak_index ] : $last_point;
		?>
		<div class="feuer-cookie-chart">
			<svg class="feuer-cookie-chart__svg" viewBox="0 0 <?php echo esc_attr((string) $width); ?> <?php echo esc_attr((string) $height); ?>" role="img" aria-label="Stundenverlauf der Seitenaufrufe">
				<defs>
					<linearGradient id="feuer-cookie-chart-fill" x1="0" x2="0" y1="0" y2="1">
						<stop offset="0%" stop-color="#2271b1" stop-opacity="0.28"></stop>
						<stop offset="100%" stop-color="#2271b1" stop-opacity="0.03"></stop>
					</linearGradient>
				</defs>
				<?php for ($step = 0; $step <= $grid_steps; $step++) : ?>
					<?php
					$y     = $padding_top + (($plot_height / $grid_steps) * $step);
					$value = (int) round($max_scale - (($max_scale / $grid_steps) * $step));
					?>
					<line class="feuer-cookie-chart__grid" x1="<?php echo esc_attr((string) $padding_left); ?>" y1="<?php echo esc_attr((string) round($y, 2)); ?>" x2="<?php echo esc_attr((string) ($width - $padding_right)); ?>" y2="<?php echo esc_attr((string) round($y, 2)); ?>"></line>
					<text class="feuer-cookie-chart__axis" x="<?php echo esc_attr((string) ($padding_left - 10)); ?>" y="<?php echo esc_attr((string) round($y + 4, 2)); ?>" text-anchor="end"><?php echo esc_html(number_format_i18n($value)); ?></text>
				<?php endfor; ?>
				<path class="feuer-cookie-chart__area" d="<?php echo esc_attr($area_path); ?>"></path>
				<path class="feuer-cookie-chart__line" d="<?php echo esc_attr($line_path); ?>"></path>
				<circle class="feuer-cookie-chart__point feuer-cookie-chart__point--peak" cx="<?php echo esc_attr((string) $peak_point['x']); ?>" cy="<?php echo esc_attr((string) $peak_point['y']); ?>" r="4"></circle>
				<circle class="feuer-cookie-chart__point feuer-cookie-chart__point--last" cx="<?php echo esc_attr((string) $last_point['x']); ?>" cy="<?php echo esc_attr((string) $last_point['y']); ?>" r="4"></circle>
				<?php foreach ($tick_indices as $index) : ?>
					<?php if (! isset($points[ $index ], $series[ $index ])) : ?>
						<?php continue; ?>
					<?php endif; ?>
					<text class="feuer-cookie-chart__axis feuer-cookie-chart__axis--x" x="<?php echo esc_attr((string) $points[ $index ]['x']); ?>" y="<?php echo esc_attr((string) ($height - 12)); ?>" text-anchor="middle"><?php echo esc_html((string) $series[ $index ]['label']); ?></text>
				<?php endforeach; ?>
			</svg>
		</div>
		<?php
	}

	/**
	 * Render an SVG area chart for daily traffic.
	 *
	 * @param array<int,array<string,mixed>> $series Daily series.
	 * @param string                         $selected_day Date in Y-m-d format.
	 * @return void
	 */
	private function render_daily_area_chart($series, $selected_day) {
		if (empty($series)) {
			echo '<div class="feuer-cookie-chart__empty">Noch keine Tageswerte fuer das Diagramm vorhanden.</div>';
			return;
		}

		$values         = array_map('intval', wp_list_pluck($series, 'value'));
		$count          = count($series);
		$width          = 980;
		$height         = 300;
		$padding_left   = 58;
		$padding_right  = 24;
		$padding_top    = 22;
		$padding_bottom = 48;
		$plot_width     = max(1, $width - $padding_left - $padding_right);
		$plot_height    = max(1, $height - $padding_top - $padding_bottom);
		$max_value      = max(1, max($values));
		$max_scale      = max(4, (int) ceil($max_value / 4) * 4);
		$points         = array();
		$grid_steps     = 4;
		$tick_indices   = $this->get_chart_tick_indices($count, 8);
		$baseline_y     = $padding_top + $plot_height;
		$peak_index     = array_search(max($values), $values, true);
		$selected_point = null;

		foreach ($series as $index => $point) {
			$value = (int) ($point['value'] ?? 0);
			$x     = 1 === $count ? $padding_left + ($plot_width / 2) : $padding_left + ($index * $plot_width / ($count - 1));
			$y     = $baseline_y - (($value / $max_scale) * $plot_height);
			$bucket = (string) ($point['bucket'] ?? '');

			$points[] = array(
				'x'      => round($x, 2),
				'y'      => round($y, 2),
				'label'  => (string) ($point['label'] ?? ''),
				'value'  => $value,
				'bucket' => $bucket,
			);

			if ($bucket === $selected_day) {
				$selected_point = end($points);
			}
		}

		$line_path = $this->build_svg_path_from_points($points);

		if (empty($line_path)) {
			echo '<div class="feuer-cookie-chart__empty">Noch keine Tageswerte fuer das Diagramm vorhanden.</div>';
			return;
		}

		$first_point = reset($points);
		$last_point  = end($points);
		$area_path   = $line_path . ' L ' . $last_point['x'] . ' ' . $baseline_y . ' L ' . $first_point['x'] . ' ' . $baseline_y . ' Z';
		$peak_point  = isset($points[ $peak_index ]) ? $points[ $peak_index ] : $last_point;
		$selected_point = $selected_point ? $selected_point : $last_point;
		?>
		<div class="feuer-cookie-chart feuer-cookie-chart--daily">
			<svg class="feuer-cookie-chart__svg" viewBox="0 0 <?php echo esc_attr((string) $width); ?> <?php echo esc_attr((string) $height); ?>" role="img" aria-label="Tagesverlauf der Seitenaufrufe">
				<defs>
					<linearGradient id="feuer-cookie-daily-chart-fill" x1="0" x2="0" y1="0" y2="1">
						<stop offset="0%" stop-color="#2271b1" stop-opacity="0.24"></stop>
						<stop offset="100%" stop-color="#2271b1" stop-opacity="0.04"></stop>
					</linearGradient>
				</defs>
				<?php for ($step = 0; $step <= $grid_steps; $step++) : ?>
					<?php
					$y     = $padding_top + (($plot_height / $grid_steps) * $step);
					$value = (int) round($max_scale - (($max_scale / $grid_steps) * $step));
					?>
					<line class="feuer-cookie-chart__grid" x1="<?php echo esc_attr((string) $padding_left); ?>" y1="<?php echo esc_attr((string) round($y, 2)); ?>" x2="<?php echo esc_attr((string) ($width - $padding_right)); ?>" y2="<?php echo esc_attr((string) round($y, 2)); ?>"></line>
					<text class="feuer-cookie-chart__axis" x="<?php echo esc_attr((string) ($padding_left - 10)); ?>" y="<?php echo esc_attr((string) round($y + 4, 2)); ?>" text-anchor="end"><?php echo esc_html(number_format_i18n($value)); ?></text>
				<?php endfor; ?>
				<path class="feuer-cookie-chart__area feuer-cookie-chart__area--daily" d="<?php echo esc_attr($area_path); ?>"></path>
				<path class="feuer-cookie-chart__line" d="<?php echo esc_attr($line_path); ?>"></path>
				<line class="feuer-cookie-chart__selected-line" x1="<?php echo esc_attr((string) $selected_point['x']); ?>" y1="<?php echo esc_attr((string) $padding_top); ?>" x2="<?php echo esc_attr((string) $selected_point['x']); ?>" y2="<?php echo esc_attr((string) $baseline_y); ?>"></line>
				<circle class="feuer-cookie-chart__point feuer-cookie-chart__point--peak" cx="<?php echo esc_attr((string) $peak_point['x']); ?>" cy="<?php echo esc_attr((string) $peak_point['y']); ?>" r="4"></circle>
				<circle class="feuer-cookie-chart__point feuer-cookie-chart__point--selected" cx="<?php echo esc_attr((string) $selected_point['x']); ?>" cy="<?php echo esc_attr((string) $selected_point['y']); ?>" r="5"></circle>
				<?php foreach ($tick_indices as $index) : ?>
					<?php if (! isset($points[ $index ], $series[ $index ])) : ?>
						<?php continue; ?>
					<?php endif; ?>
					<text class="feuer-cookie-chart__axis feuer-cookie-chart__axis--x" x="<?php echo esc_attr((string) $points[ $index ]['x']); ?>" y="<?php echo esc_attr((string) ($height - 14)); ?>" text-anchor="middle"><?php echo esc_html((string) $series[ $index ]['label']); ?></text>
				<?php endforeach; ?>
			</svg>
		</div>
		<?php
	}

	/**
	 * Render a horizontal page-traffic bar chart.
	 *
	 * @param array<int,array<string,mixed>> $pages Page rows.
	 * @return void
	 */
	private function render_page_bar_chart($pages) {
		if (empty($pages)) {
			echo '<div class="feuer-cookie-chart__empty">Noch keine Seitenaufrufe fuer dieses Ranking vorhanden.</div>';
			return;
		}

		$max_hits = 1;

		foreach ($pages as $page) {
			$max_hits = max($max_hits, (int) ($page['hits'] ?? 0));
		}
		?>
		<div class="feuer-cookie-bars">
			<?php foreach ($pages as $page) : ?>
				<?php
				$hits        = (int) ($page['hits'] ?? 0);
				$path        = (string) ($page['request_path'] ?? '/');
				$display     = $this->format_admin_path_label($path);
				$width       = max(6, round(($hits / $max_hits) * 100, 2));
				?>
				<div class="feuer-cookie-bars__item">
					<div class="feuer-cookie-bars__head">
						<span class="feuer-cookie-bars__label" title="<?php echo esc_attr($path); ?>"><?php echo esc_html($display); ?></span>
						<strong class="feuer-cookie-bars__value"><?php echo esc_html(number_format_i18n($hits)); ?></strong>
					</div>
					<div class="feuer-cookie-bars__track">
						<span class="feuer-cookie-bars__fill" style="width: <?php echo esc_attr((string) $width); ?>%;"></span>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Render a horizontal IP ranking chart.
	 *
	 * @param array<int,array<string,mixed>> $ips Ranked IP rows.
	 * @return void
	 */
	private function render_ip_bar_chart($ips) {
		if (empty($ips)) {
			echo '<div class="feuer-cookie-chart__empty">Noch keine IP-Daten fuer dieses Ranking vorhanden.</div>';
			return;
		}

		$max_hits = 1;

		foreach ($ips as $ip) {
			$max_hits = max($max_hits, (int) ($ip['hits'] ?? 0));
		}
		?>
		<div class="feuer-cookie-bars">
			<?php foreach ($ips as $ip) : ?>
				<?php
				$hits    = (int) ($ip['hits'] ?? 0);
				$label   = (string) ($ip['ip_address'] ?? '-');
				$width   = max(6, round(($hits / $max_hits) * 100, 2));
				?>
				<div class="feuer-cookie-bars__item">
					<div class="feuer-cookie-bars__head">
						<span class="feuer-cookie-bars__label"><code><?php echo esc_html($label); ?></code></span>
						<strong class="feuer-cookie-bars__value"><?php echo esc_html(number_format_i18n($hits)); ?></strong>
					</div>
					<div class="feuer-cookie-bars__track">
						<span class="feuer-cookie-bars__fill" style="width: <?php echo esc_attr((string) $width); ?>%;"></span>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Render country coverage tiles with a deterministic intensity scale.
	 *
	 * @param array<int,array<string,mixed>> $countries Country rows.
	 * @return void
	 */
	private function render_country_coverage($countries) {
		if (empty($countries)) {
			echo '<div class="feuer-cookie-chart__empty">Noch keine Länderinformationen verfügbar. Ein CDN oder Proxy muss Geo-Header bereitstellen.</div>';
			return;
		}

		$max_hits = max(1, max(array_map(static function ($row) { return (int) ($row['hits'] ?? 0); }, $countries)));
		?>
		<div class="feuer-cookie-country-grid">
			<?php foreach ($countries as $country) : ?>
				<?php $intensity = max(1, min(5, (int) ceil(((int) ($country['hits'] ?? 0) / $max_hits) * 5))); ?>
				<div class="feuer-cookie-country-tile feuer-cookie-country-tile--<?php echo esc_attr((string) $intensity); ?>" title="<?php echo esc_attr((string) ($country['hits'] ?? 0)); ?> Aufrufe">
					<span class="feuer-cookie-country-tile__code"><?php echo esc_html((string) ($country['country_code'] ?? '')); ?></span>
					<strong><?php echo esc_html((string) ($country['country_name'] ?: $country['country_code'])); ?></strong>
					<small><?php echo esc_html(number_format_i18n((int) ($country['hits'] ?? 0))); ?> Aufrufe</small>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Render an external-referrer ranking with a semantic source badge.
	 *
	 * @param array<int,array<string,mixed>> $sources Source rows.
	 * @return void
	 */
	private function render_referrer_source_chart($sources) {
		if (empty($sources)) {
			echo '<div class="feuer-cookie-chart__empty">Noch keine externen Verweise im gewählten Zeitraum.</div>';
			return;
		}

		$max_hits = max(1, max(array_map(static function ($row) { return (int) ($row['hits'] ?? 0); }, $sources)));
		?>
		<div class="feuer-cookie-bars feuer-cookie-bars--sources">
			<?php foreach ($sources as $source) : ?>
				<?php $hits = (int) ($source['hits'] ?? 0); $width = max(6, round(($hits / $max_hits) * 100, 2)); ?>
				<div class="feuer-cookie-bars__item">
					<div class="feuer-cookie-bars__head">
						<span class="feuer-cookie-bars__label"><span class="feuer-cookie-source-badge feuer-cookie-source-badge--<?php echo esc_attr((string) ($source['type'] ?? 'referral')); ?>"><?php echo 'search' === ($source['type'] ?? '') ? 'Suche' : 'Verweis'; ?></span> <?php echo esc_html((string) ($source['label'] ?? 'Unbekannt')); ?></span>
						<strong class="feuer-cookie-bars__value"><?php echo esc_html(number_format_i18n($hits)); ?></strong>
					</div>
					<div class="feuer-cookie-bars__track"><span class="feuer-cookie-bars__fill" style="width: <?php echo esc_attr((string) $width); ?>%;"></span></div>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Render a generic hit ranking chart.
	 *
	 * @param array<int,array<string,mixed>> $rows Ranked rows with label and hits.
	 * @return void
	 */
	private function render_generic_bar_chart($rows) {
		if (empty($rows)) {
			echo '<div class="feuer-cookie-chart__empty">Noch keine Daten fuer dieses Ranking vorhanden.</div>';
			return;
		}

		$max_hits = 1;

		foreach ($rows as $row) {
			$max_hits = max($max_hits, (int) ($row['hits'] ?? 0));
		}
		?>
		<div class="feuer-cookie-bars">
			<?php foreach ($rows as $row) : ?>
				<?php
				$hits  = (int) ($row['hits'] ?? 0);
				$label = (string) ($row['label'] ?? 'Unbekannt');
				$width = max(6, round(($hits / $max_hits) * 100, 2));
				?>
				<div class="feuer-cookie-bars__item">
					<div class="feuer-cookie-bars__head">
						<span class="feuer-cookie-bars__label" title="<?php echo esc_attr($label); ?>"><?php echo esc_html($label); ?></span>
						<strong class="feuer-cookie-bars__value"><?php echo esc_html(number_format_i18n($hits)); ?></strong>
					</div>
					<div class="feuer-cookie-bars__track">
						<span class="feuer-cookie-bars__fill" style="width: <?php echo esc_attr((string) $width); ?>%;"></span>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Render total engagement duration bars per page.
	 *
	 * @param array<int,array<string,mixed>> $pages Page engagement rows.
	 * @return void
	 */
	private function render_duration_bar_chart($pages) {
		if (empty($pages)) {
			echo '<div class="feuer-cookie-chart__empty">Noch keine Aufenthaltszeiten fuer dieses Ranking vorhanden.</div>';
			return;
		}

		$max_seconds = 1;

		foreach ($pages as $page) {
			$max_seconds = max($max_seconds, (int) ($page['total_active'] ?? 0));
		}
		?>
		<div class="feuer-cookie-bars">
			<?php foreach ($pages as $page) : ?>
				<?php
				$seconds = (int) ($page['total_active'] ?? 0);
				$path    = (string) ($page['request_path'] ?? '/');
				$display = $this->format_admin_path_label($path);
				$width   = max(6, round(($seconds / $max_seconds) * 100, 2));
				?>
				<div class="feuer-cookie-bars__item">
					<div class="feuer-cookie-bars__head">
						<span class="feuer-cookie-bars__label" title="<?php echo esc_attr($path); ?>"><?php echo esc_html($display); ?></span>
						<strong class="feuer-cookie-bars__value"><?php echo esc_html($this->format_seconds_compact($seconds)); ?></strong>
					</div>
					<div class="feuer-cookie-bars__track">
						<span class="feuer-cookie-bars__fill feuer-cookie-bars__fill--warm" style="width: <?php echo esc_attr((string) $width); ?>%;"></span>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Shorten long admin path labels.
	 *
	 * @param string $path Request path.
	 * @return string
	 */
	private function format_admin_path_label($path) {
		$path = trim($path);

		if ('' === $path) {
			return '/';
		}

		if (strlen($path) > 54) {
			return substr($path, 0, 51) . '...';
		}

		return $path;
	}

	/**
	 * Format seconds into a compact admin label.
	 *
	 * @param int $seconds Seconds.
	 * @return string
	 */
	private function format_seconds_compact($seconds) {
		$seconds = max(0, (int) $seconds);

		if ($seconds < 60) {
			return $seconds . 's';
		}

		$minutes = (int) floor($seconds / 60);
		$rest    = $seconds % 60;

		if ($minutes < 60) {
			return $minutes . 'm ' . $rest . 's';
		}

		$hours   = (int) floor($minutes / 60);
		$minutes = $minutes % 60;

		return $hours . 'h ' . $minutes . 'm';
	}

	/**
	 * Decode one event metadata blob.
	 *
	 * @param array<string,mixed> $event Event row.
	 * @return array<string,mixed>
	 */
	private function get_event_meta($event) {
		$meta = json_decode((string) ($event['meta_json'] ?? ''), true);

		return is_array($meta) ? $meta : array();
	}

	/**
	 * Format a compact metadata summary for the admin events table.
	 *
	 * @param array<string,mixed> $event Event row.
	 * @return string
	 */
	private function format_event_meta_summary($event) {
		$type = (string) ($event['request_type'] ?? '');
		$meta = $this->get_event_meta($event);

		if ('page_engagement' === $type) {
			$parts = array(
				'Aktiv ' . $this->format_seconds_compact((int) ($meta['active_seconds'] ?? 0)),
				'Gesamt ' . $this->format_seconds_compact((int) ($meta['duration_seconds'] ?? 0)),
				'Scroll ' . max(0, min(100, (int) ($meta['scroll_depth'] ?? 0))) . '%',
			);

			return implode(' | ', $parts);
		}

		if (in_array($type, array('consent_accept_all', 'consent_custom', 'consent_necessary', 'consent_service_allow'), true)) {
			$decision = ! empty($meta['decision']) ? (string) $meta['decision'] : $type;
			$service  = ! empty($meta['service_id']) ? ' | Dienst ' . (string) $meta['service_id'] : '';

			return 'Decision ' . $decision . $service;
		}

		if ('blocked_ip' === $type && ! empty($meta['blocked'])) {
			return 'Geblockte Anfrage';
		}

		return '';
	}

	/**
	 * Render consent decisions grouped by visitor session.
	 *
	 * @param array<int,array<string,mixed>> $visitors Visitor rows.
	 * @param string                         $mode Decision group.
	 * @return void
	 */
	private function render_consent_visitors_table($visitors, $mode = 'optional') {
		$empty = 'necessary' === $mode
			? 'Noch keine Besucher mit ausschliesslich erforderlichen Diensten vorhanden.'
			: 'Noch keine Besucher mit optionaler Freigabe vorhanden.';
		?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th>Zeit</th>
					<th>Entscheidung</th>
					<th>Pfad</th>
					<th>IP</th>
					<th>Geraet</th>
					<th>Browser</th>
					<th>System</th>
				</tr>
			</thead>
			<tbody>
				<?php if ($visitors) : ?>
					<?php foreach ($visitors as $visitor) : ?>
						<tr>
							<td><?php echo esc_html((string) $visitor['created_at']); ?></td>
							<td><?php echo esc_html($this->format_consent_decision_label((string) $visitor['request_type'])); ?></td>
							<td>
								<div class="feuer-cookie-path-cell">
									<span><?php echo esc_html((string) $visitor['request_path']); ?></span>
									<?php if (! empty($visitor['page_title'])) : ?>
										<span class="feuer-cookie-path-title"><?php echo esc_html((string) $visitor['page_title']); ?></span>
									<?php endif; ?>
								</div>
							</td>
							<td><code><?php echo esc_html((string) $visitor['ip_address']); ?></code></td>
							<td><?php echo esc_html((string) ($visitor['device_type'] ?: 'Unbekannt')); ?></td>
							<td><?php echo esc_html((string) ($visitor['browser_name'] ?: 'Unbekannt')); ?></td>
							<td><?php echo esc_html((string) ($visitor['os_name'] ?: 'Unbekannt')); ?></td>
						</tr>
					<?php endforeach; ?>
				<?php else : ?>
					<tr><td colspan="7"><?php echo esc_html($empty); ?></td></tr>
				<?php endif; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Human-readable consent decision label.
	 *
	 * @param string $type Event type.
	 * @return string
	 */
	private function format_consent_decision_label($type) {
		switch ($type) {
			case 'consent_accept_all':
				return 'Alle akzeptiert';
			case 'consent_custom':
				return 'Auswahl gespeichert';
			case 'consent_service_allow':
				return 'Dienst freigegeben';
			case 'consent_necessary':
				return 'Nur erforderlich';
			case 'consent_deleted':
				return 'Einwilligung geloescht';
			default:
				return $type;
		}
	}

	/**
	 * Build a simple SVG line path from points.
	 *
	 * @param array<int,array<string,mixed>> $points Point list.
	 * @return string
	 */
	private function build_svg_path_from_points($points) {
		$commands = array();

		foreach ($points as $index => $point) {
			$prefix     = 0 === $index ? 'M' : 'L';
			$commands[] = $prefix . ' ' . $point['x'] . ' ' . $point['y'];
		}

		return implode(' ', $commands);
	}

	/**
	 * Calculate evenly spaced x-axis ticks.
	 *
	 * @param int $count Number of points.
	 * @param int $ticks Desired number of ticks.
	 * @return array<int,int>
	 */
	private function get_chart_tick_indices($count, $ticks = 6) {
		$count = max(1, (int) $count);
		$ticks = max(2, (int) $ticks);

		if ($count <= $ticks) {
			return range(0, $count - 1);
		}

		$indices = array();

		for ($i = 0; $i < $ticks; $i++) {
			$indices[] = (int) round(($count - 1) * ($i / ($ticks - 1)));
		}

		return array_values(array_unique($indices));
	}

	/**
	 * Render the events table.
	 *
	 * @param array<int,array<string,mixed>> $events Event rows.
	 * @return void
	 */
	private function render_events_table($events, $compact = false) {
		$table_class = $compact ? 'feuer-cookie-events-table feuer-cookie-events-table--compact' : 'feuer-cookie-events-table';
		$ua_length   = $compact ? 42 : 90;
		?>
		<div class="feuer-cookie-table-scroll">
		<table class="widefat striped <?php echo esc_attr($table_class); ?>">
			<thead>
				<tr>
					<th>Zeit</th>
					<th>Typ</th>
					<th>Status</th>
					<th>Pfad</th>
					<th>Referrer</th>
					<th>IP</th>
					<th>Meta</th>
					<th>Robot</th>
					<th>User Agent</th>
				</tr>
			</thead>
			<tbody>
				<?php if ($events) : ?>
					<?php foreach ($events as $event) : ?>
						<tr>
							<td><?php echo esc_html((string) $event['created_at']); ?></td>
							<td><?php echo esc_html((string) $event['request_type']); ?></td>
							<td><?php echo esc_html((string) $event['consent_state']); ?></td>
							<td>
								<div class="feuer-cookie-path-cell">
									<span><?php echo esc_html((string) $event['request_path']); ?></span>
									<?php if (! empty($event['page_title'])) : ?>
										<span class="feuer-cookie-path-title"><?php echo esc_html((string) $event['page_title']); ?></span>
									<?php endif; ?>
								</div>
							</td>
							<td><?php echo esc_html((string) $event['referrer_path']); ?></td>
							<td><code><?php echo esc_html((string) $event['ip_address']); ?></code></td>
							<td><?php echo esc_html($this->format_event_meta_summary($event)); ?></td>
							<td><?php echo ! empty($event['is_robot']) ? 'Ja' : 'Nein'; ?></td>
							<td class="feuer-cookie-ua" title="<?php echo esc_attr((string) $event['user_agent']); ?>"><?php echo esc_html(wp_html_excerpt((string) $event['user_agent'], $ua_length, '...')); ?></td>
						</tr>
					<?php endforeach; ?>
				<?php else : ?>
					<tr><td colspan="9">Noch keine Ereignisse vorhanden.</td></tr>
				<?php endif; ?>
			</tbody>
		</table>
		</div>
		<?php
	}

	/**
	 * Translate redirect notices.
	 *
	 * @param string $notice Notice identifier.
	 * @return string
	 */
	private function notice_label($notice) {
		switch ($notice) {
			case 'saved':
				return 'Die Einstellungen wurden gespeichert.';
			case 'blocked_added':
				return 'Die IP-Sperre wurde gespeichert.';
			case 'blocked_deleted':
				return 'Die IP-Sperre wurde entfernt.';
			case 'diagnostics_run':
				return 'Die Autodiagnose wurde ausgefuehrt.';
			case 'seo_audit_run':
				return 'Der SEO-Audit wurde ausgefuehrt.';
			case 'update_available':
				return 'Auf dem Update-Server wurde eine neuere Plugin-Version gefunden. WordPress zeigt das Update jetzt in der Plugin-Verwaltung an.';
			case 'update_current':
				return 'Es wurde erfolgreich nach Updates gesucht. Die installierte Version ist aktuell.';
			case 'update_check_failed':
				return 'Die Update-Pruefung konnte nicht abgeschlossen werden. Bitte pruefen Sie die Erreichbarkeit von wdmin.com und versuchen Sie es erneut.';
			default:
				return 'Aenderungen wurden uebernommen.';
		}
	}

	/**
	 * Resolve the CSS class for one admin notice.
	 *
	 * @param string $notice Notice identifier.
	 * @return string
	 */
	private function notice_css_class($notice) {
		switch ($notice) {
			case 'update_check_failed':
				return 'notice-error';
			default:
				return 'notice-success';
		}
	}
}
