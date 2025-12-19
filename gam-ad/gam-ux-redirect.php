<?php
/**
 * Plugin Name: RedirectID AD
 * Description: Cria cards de apps (nome/ícone/tamanho/preço/nota) e URLs internas /go/<token> para acionar regras de interstitial do site. NÃO exibe anúncios. Metabox com múltiplos apps e posição (parágrafo) por app.
 * Version: 3.0.0
 * Author: TwoD
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: redirectid-ad
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 *
 * @package RedirectID_AD
 */

if (!defined('ABSPATH')) exit;

/**
 * Main plugin class
 *
 * @since 2.0.0
 */
class RedirectID_AD {
	const OPT = 'rid_settings';
	const QV  = 'rid_go_token';
	const PMK = '_rid_apps';
	const TEXT_DOMAIN = 'redirectid-ad';
	const VERSION = '3.0.0';
	const CACHE_TTL = 24 * HOUR_IN_SECONDS; // 24 hours
	const META_CACHE_TTL = 24 * HOUR_IN_SECONDS;

	/**
	 * Singleton instance
	 *
	 * @var RedirectID_AD
	 */
	private static $instance = null;

	/**
	 * Get singleton instance
	 *
	 * @return RedirectID_AD
	 */
	public static function get_instance() {
		if (null === self::$instance) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor
	 */
	private function __construct() {
		$this->init();
	}

	/**
	 * Initialize plugin
	 */
	private function init() {
		// Load text domain
		add_action('plugins_loaded', array($this, 'load_textdomain'));

		// Admin
		add_action('admin_menu', array($this, 'menu'));
		add_action('admin_init', array($this, 'register_settings'));
		add_action('add_meta_boxes', array($this, 'metabox'));
		add_action('admin_enqueue_scripts', array($this, 'admin_assets'));
		add_action('save_post', array($this, 'save_apps'), 20, 3);

		// Front
		add_action('wp_enqueue_scripts', array($this, 'front_assets'));
		add_filter('the_content', array($this, 'inject_cards'), 8);

		// Shortcode
		add_shortcode('rid_app_card', array($this, 'shortcode_single'));

		// /go/<token>
		add_action('init', array($this, 'register_go_endpoint'));
		add_action('init', array($this, 'early_intercept_go_request'), 1);
		add_action('parse_request', array($this, 'intercept_go_request'), 1);
		add_action('template_redirect', array($this, 'handle_go_request'));

		// Cleanup
		add_action('wp_scheduled_delete', array($this, 'cleanup_transients'));

		// Activation/Deactivation
		register_activation_hook(__FILE__, array($this, 'on_activate'));
		register_deactivation_hook(__FILE__, 'flush_rewrite_rules');
	}

	/**
	 * Load plugin textdomain
	 *
	 * @since 3.0.0
	 */
	public function load_textdomain() {
		load_plugin_textdomain(
			self::TEXT_DOMAIN,
			false,
			dirname(plugin_basename(__FILE__)) . '/languages'
		);
	}

	# ===== Admin =====

	/**
	 * Add admin menu
	 *
	 * @since 2.0.0
	 */
	public function menu() {
		add_options_page(
			__('RedirectID AD', self::TEXT_DOMAIN),
			__('RedirectID AD', self::TEXT_DOMAIN),
			'manage_options',
			'redirectid-ad',
			array($this, 'settings_page')
		);
	}

	/**
	 * Register settings
	 *
	 * @since 2.0.0
	 */
	public function register_settings() {
		register_setting(self::OPT, self::OPT);

		add_settings_section('general', __('Opções', self::TEXT_DOMAIN), '__return_false', self::OPT);

		add_settings_field('token_ttl', __('Tempo de vida do token (/go/) em minutos', self::TEXT_DOMAIN), function() {
			$o = get_option(self::OPT, []);
			$v = intval($o['token_ttl'] ?? 10);
			echo '<input type="number" min="1" step="1" style="width:120px" name="' . esc_attr(self::OPT) . '[token_ttl]" value="' . esc_attr($v) . '">';
		}, self::OPT, 'general');

		add_settings_field('hold_ms', __('Espera na /go/ (ms)', self::TEXT_DOMAIN), function() {
			$o = get_option(self::OPT, []);
			$v = intval($o['hold_ms'] ?? 150);
			echo '<input type="number" min="0" step="50" style="width:120px" name="' . esc_attr(self::OPT) . '[hold_ms]" value="' . esc_attr($v) . '">';
			echo '<p class="description">' . esc_html__('0 = redireciona imediatamente; 100–300ms ajuda regras de interstitial por navegação interna.', self::TEXT_DOMAIN) . '</p>';
		}, self::OPT, 'general');

		add_settings_field('primary_color', __('Cor do botão (fallback)', self::TEXT_DOMAIN), function() {
			$o = get_option(self::OPT, []);
			$v = esc_attr($o['primary_color'] ?? '#3b82f6');
			echo '<input type="color" name="' . esc_attr(self::OPT) . '[primary_color]" value="' . esc_attr($v) . '">';
			echo '<p class="description">' . esc_html__('Tentaremos detectar a cor primária do tema automaticamente; esta é usada como fallback.', self::TEXT_DOMAIN) . '</p>';
		}, self::OPT, 'general');
	}

	/**
	 * Settings page
	 *
	 * @since 2.0.0
	 */
	public function settings_page() {
		if (!current_user_can('manage_options')) {
			wp_die(__('Você não tem permissão para acessar esta página.', self::TEXT_DOMAIN));
		}

		// Handle cache clearing
		if (isset($_POST['rid_clear_cache']) && check_admin_referer('rid_clear_cache_action', 'rid_clear_cache_nonce')) {
			$deleted = $this->clear_all_cache();
			echo '<div class="notice notice-success is-dismissible"><p>';
			echo esc_html(sprintf(
				__('Cache limpo com sucesso! %d transients foram removidos.', self::TEXT_DOMAIN),
				$deleted
			));
			echo '</p></div>';
		}

		echo '<div class="wrap"><h1>' . esc_html__('RedirectID AD', self::TEXT_DOMAIN) . '</h1>';
		echo '<p>' . esc_html__('Cria cards de apps + URLs internas', self::TEXT_DOMAIN) . ' <code>/go/&lt;token&gt;</code>. ';
		echo esc_html__('NÃO exibe anúncios — as regras de interstitial ficam no seu script do site.', self::TEXT_DOMAIN) . '</p>';
		echo '<form method="post" action="options.php">';
		settings_fields(self::OPT);
		do_settings_sections(self::OPT);
		submit_button();
		echo '</form>';

		// Cache clearing section
		echo '<hr>';
		echo '<h2>' . esc_html__('Cache', self::TEXT_DOMAIN) . '</h2>';
		echo '<form method="post" action="">';
		wp_nonce_field('rid_clear_cache_action', 'rid_clear_cache_nonce');
		echo '<p>' . esc_html__('Limpe todo o cache do plugin (metadados de apps, contagem de parágrafos, tokens, etc.)', self::TEXT_DOMAIN) . '</p>';
		echo '<p><button type="submit" name="rid_clear_cache" class="button button-secondary" onclick="return confirm(\'' . esc_js(__('Tem certeza que deseja limpar todo o cache do plugin?', self::TEXT_DOMAIN)) . '\');">';
		echo esc_html__('Limpar Cache', self::TEXT_DOMAIN);
		echo '</button></p>';
		echo '</form></div>';
	}

	/**
	 * Add metabox
	 *
	 * @since 2.0.0
	 */
	public function metabox() {
		if (!current_user_can('edit_posts')) {
			return;
		}
		add_meta_box('rid_apps', __('Apps (RedirectID)', self::TEXT_DOMAIN), array($this, 'metabox_html'), ['post'], 'side', 'high');
	}

	/**
	 * Enqueue admin assets
	 *
	 * @param string $hook Current admin page hook.
	 * @since 2.0.0
	 */
	public function admin_assets($hook) {
		if (!in_array($hook, ['post.php', 'post-new.php'], true)) {
			return;
		}

		if (!current_user_can('edit_posts')) {
			return;
		}

		$css_file = plugin_dir_path(__FILE__) . 'assets/rid-admin.css';
		$js_file = plugin_dir_path(__FILE__) . 'assets/rid-admin.js';
		$css_version = file_exists($css_file) ? filemtime($css_file) : self::VERSION;
		$js_version = file_exists($js_file) ? filemtime($js_file) : self::VERSION;

		wp_enqueue_style(
			'rid-admin',
			plugin_dir_url(__FILE__) . 'assets/rid-admin.css',
			array(),
			$css_version
		);
		wp_enqueue_script(
			'rid-admin',
			plugin_dir_url(__FILE__) . 'assets/rid-admin.js',
			array(),
			$js_version,
			true
		);
	}

	/**
	 * Count paragraphs in post content
	 *
	 * @param WP_Post $post Post object.
	 * @return int Number of paragraphs.
	 * @since 2.0.0
	 */
	private function count_paragraphs($post) {
		if (!$post || empty($post->post_content)) {
			return 0;
		}

		// Cache paragraph count
		$cache_key = 'rid_para_count_' . $post->ID;
		$cached = get_transient($cache_key);
		if (false !== $cached) {
			return intval($cached);
		}

		$html = wpautop($post->post_content);
		$count = substr_count($html, '</p>');

		set_transient($cache_key, $count, 3600); // Cache for 1 hour
		return $count;
	}

	/**
	 * Metabox HTML
	 *
	 * @param WP_Post $post Post object.
	 * @since 2.0.0
	 */
	public function metabox_html($post) {
		if (!current_user_can('edit_post', $post->ID)) {
			return;
		}

		$apps = get_post_meta($post->ID, self::PMK, true);
		if (!is_array($apps)) {
			$apps = [];
		}
		$countP = $this->count_paragraphs($post);
		wp_nonce_field('rid_apps', 'rid_apps_nonce');
		?>
		<div id="rid-box" data-paragraphs="<?php echo esc_attr($countP); ?>">
			<div class="rid-head">
				<button type="button" class="button button-primary" id="rid-add">+ <?php echo esc_html__('adicionar app', self::TEXT_DOMAIN); ?></button>
				<small><?php echo esc_html__('Parágrafos detectados:', self::TEXT_DOMAIN); ?> <?php echo intval($countP); ?></small>
			</div>
			<div id="rid-list"></div>
			<input type="hidden" name="rid_apps_json" id="rid_apps_json" value="<?php echo esc_attr(wp_json_encode($apps)); ?>">
			<template id="rid-item-tpl">
				<div class="rid-item" draggable="true">
					<span class="rid-drag">↕</span>
					<a class="rid-del" href="#" title="<?php echo esc_attr__('remover', self::TEXT_DOMAIN); ?>">×</a>
					<p><label><?php echo esc_html__('Google Play', self::TEXT_DOMAIN); ?></label><input type="url" class="rid-gplay" placeholder="https://play.google.com/store/apps/details?id=..."></p>
					<p><label><?php echo esc_html__('Apple Store', self::TEXT_DOMAIN); ?></label><input type="url" class="rid-appstore" placeholder="https://apps.apple.com/app/id..."></p>
					<p><label><?php echo esc_html__('Inserir', self::TEXT_DOMAIN); ?></label>
						<select class="rid-pos"></select>
					</p>
				</div>
			</template>
		</div>
		<?php
	}

	/**
	 * Save apps meta
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 * @param bool    $update  Whether this is an existing post being updated.
	 * @since 2.0.0
	 */
	public function save_apps($post_id, $post, $update) {
		if (wp_is_post_revision($post_id) || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)) {
			return;
		}

		if (!isset($_POST['rid_apps_nonce']) || !wp_verify_nonce($_POST['rid_apps_nonce'], 'rid_apps')) {
			return;
		}

		if (!current_user_can('edit_post', $post_id)) {
			return;
		}

		$json = isset($_POST['rid_apps_json']) ? wp_unslash($_POST['rid_apps_json']) : '[]';
		$arr  = json_decode($json, true);
		$out  = [];

		if (is_array($arr)) {
			foreach ($arr as $it) {
				$g = isset($it['gplay']) ? esc_url_raw($it['gplay']) : '';
				$a = isset($it['appstore']) ? esc_url_raw($it['appstore']) : '';
				$p = isset($it['pos']) ? intval($it['pos']) : 0;

				// Validate URLs
				if ($g && !$this->validate_store_url($g, 'gplay')) {
					continue;
				}
				if ($a && !$this->validate_store_url($a, 'appstore')) {
					continue;
				}

				if ($g || $a) {
					$out[] = ['gplay' => $g, 'appstore' => $a, 'pos' => $p];
				}
			}
		}

		update_post_meta($post_id, self::PMK, $out);

		// Clear paragraph count cache
		delete_transient('rid_para_count_' . $post_id);
	}

	/**
	 * Validate store URL
	 *
	 * @param string $url  URL to validate.
	 * @param string $type Store type (gplay or appstore).
	 * @return bool True if valid.
	 * @since 3.0.0
	 */
	private function validate_store_url($url, $type) {
		if (empty($url)) {
			return false;
		}

		if ('gplay' === $type) {
			return (bool) preg_match('#^https?://(play\.google\.com|apps\.android\.com)/store/apps#i', $url);
		}

		if ('appstore' === $type) {
			return (bool) preg_match('#^https?://(apps\.apple\.com|itunes\.apple\.com)/#i', $url);
		}

		return false;
	}

	# ===== Front =====

	/**
	 * Enqueue front assets
	 *
	 * @since 2.0.0
	 */
	public function front_assets() {
		$css_file = plugin_dir_path(__FILE__) . 'assets/rid-front.css';
		$js_file = plugin_dir_path(__FILE__) . 'assets/rid-front.js';
		$css_version = file_exists($css_file) ? filemtime($css_file) : self::VERSION;
		$js_version = file_exists($js_file) ? filemtime($js_file) : self::VERSION;

		wp_enqueue_style(
			'rid-front',
			plugin_dir_url(__FILE__) . 'assets/rid-front.css',
			array(),
			$css_version
		);

		// Fallback color from options
		$o = get_option(self::OPT, []);
		$primary = function_exists('sanitize_hex_color') ? sanitize_hex_color($o['primary_color'] ?? '#3b82f6') : ($o['primary_color'] ?? '#3b82f6');
		wp_add_inline_style('rid-front', ":root{--rid-primary: {$primary};}");

		// Theme color detection script
		wp_enqueue_script(
			'rid-front',
			plugin_dir_url(__FILE__) . 'assets/rid-front.js',
			array(),
			$js_version,
			true
		);
	}

	# ===== Helpers (Stores) =====

	/**
	 * Parse Google Play ID from URL
	 *
	 * @param string $v URL or ID.
	 * @return string|null App ID or null.
	 * @since 2.0.0
	 */
	private function parse_gplay_id($v) {
		if (!$v) {
			return null;
		}
		if (preg_match('~^https?://~i', $v)) {
			$q = [];
			parse_str(wp_parse_url($v, PHP_URL_QUERY) ?: '', $q);
			return isset($q['id']) ? sanitize_text_field($q['id']) : null;
		}
		return sanitize_text_field($v);
	}

	/**
	 * Parse App Store ID from URL
	 *
	 * @param string $v URL or ID.
	 * @return string|null App ID or null.
	 * @since 2.0.0
	 */
	private function parse_appstore_id($v) {
		if (!$v) {
			return null;
		}
		if (preg_match('/id(\d+)/', $v, $m)) {
			return sanitize_text_field($m[1]);
		}
		return preg_match('/^\d+$/', $v) ? sanitize_text_field($v) : null;
	}

	/**
	 * Clean Play Store title
	 *
	 * @param string $name Title to clean.
	 * @return string Cleaned title.
	 * @since 2.0.0
	 */
	private function clean_play_title($name) {
		return preg_replace('/\s+[–-]\s+Apps on Google Play$/i', '', $name);
	}

	/**
	 * Enrich metadata from stores (with async support)
	 *
	 * @param string $gplayUrl    Google Play URL.
	 * @param string $appstoreUrl App Store URL.
	 * @return array Metadata array.
	 * @since 2.0.0
	 */
	private function enrich_meta($gplayUrl, $appstoreUrl) {
		$key = 'rid_meta_' . md5($gplayUrl . '|' . $appstoreUrl);
		$cached = get_transient($key);
		if (false !== $cached) {
			return $cached;
		}

		$out = [];

		// Schedule async fetch if not in admin
		if (!is_admin()) {
			$this->schedule_async_fetch($gplayUrl, $appstoreUrl);
			// Return empty array, will be populated on next request
			return $out;
		}

		// Synchronous fetch in admin
		$out = $this->fetch_meta_sync($gplayUrl, $appstoreUrl);
		set_transient($key, $out, self::META_CACHE_TTL);
		return $out;
	}

	/**
	 * Schedule async metadata fetch
	 *
	 * @param string $gplayUrl    Google Play URL.
	 * @param string $appstoreUrl App Store URL.
	 * @since 3.0.0
	 */
	private function schedule_async_fetch($gplayUrl, $appstoreUrl) {
		// Use WP Cron for async processing
		$key = 'rid_meta_' . md5($gplayUrl . '|' . $appstoreUrl);
		if (false === get_transient($key . '_fetching')) {
			set_transient($key . '_fetching', true, 300); // 5 min lock
			wp_schedule_single_event(time() + 1, 'rid_fetch_meta', array($gplayUrl, $appstoreUrl));
		}
	}

	/**
	 * Fetch metadata synchronously (public wrapper for cron)
	 *
	 * @param string $gplayUrl    Google Play URL.
	 * @param string $appstoreUrl App Store URL.
	 * @return array Metadata.
	 * @since 3.0.0
	 */
	public function fetch_meta_sync($gplayUrl, $appstoreUrl) {
		$out = [];

		// Google Play
		if ($gplayUrl) {
			$id = $this->parse_gplay_id($gplayUrl);
			if ($id) {
				$url = "https://play.google.com/store/apps/details?id={$id}&hl=en&gl=US";
				$r = wp_remote_get($url, [
					'timeout' => 8,
					'headers' => ['User-Agent' => 'Mozilla/5.0'],
				]);

				if (!is_wp_error($r)) {
					$html = wp_remote_retrieve_body($r);
					$code = wp_remote_retrieve_response_code($r);

					if (200 === $code && $html) {
						// Validate HTML structure before parsing
						if (preg_match('/property="og:title"\s+content="([^"]+)"/i', $html, $m)) {
							$out['name'] = $this->clean_play_title(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'));
						}
						if (preg_match('/property="og:image"\s+content="([^"]+)"/i', $html, $m)) {
							$out['icon'] = esc_url_raw(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'));
						}
						if (preg_match('/"aggregateRating":\{"@type":"AggregateRating","ratingValue":"([^"]+)"/', $html, $m)) {
							$out['rating'] = sanitize_text_field($m[1]);
						}
						$out['gplay'] = esc_url_raw("https://play.google.com/store/apps/details?id={$id}");
					}
				}
			}
		}

		// App Store
		if ($appstoreUrl) {
			$id = $this->parse_appstore_id($appstoreUrl);
			if ($id) {
				$r = wp_remote_get("https://itunes.apple.com/lookup?id={$id}&country=us", ['timeout' => 8]);

				if (!is_wp_error($r)) {
					$body = wp_remote_retrieve_body($r);
					$code = wp_remote_retrieve_response_code($r);

					if (200 === $code && $body) {
						$j = json_decode($body, true);
						if (is_array($j) && !empty($j['results'][0])) {
							$it = $j['results'][0];
							if (empty($out['name']) && !empty($it['trackName'])) {
								$out['name'] = sanitize_text_field($it['trackName']);
							}
							if (empty($out['icon']) && !empty($it['artworkUrl100'])) {
								$out['icon'] = esc_url_raw(str_replace('100x100bb', '512x512bb', $it['artworkUrl100']));
							}
							if (!empty($it['averageUserRating'])) {
								$out['rating'] = $out['rating'] ?? floatval($it['averageUserRating']);
							}
							if (!empty($it['fileSizeBytes'])) {
								$out['size'] = round($it['fileSizeBytes'] / 1048576, 1) . 'MB';
							}
							if (isset($it['price'])) {
								$price = floatval($it['price']);
								$out['price'] = ($price > 0 ? $this->format_price($price) : __('Free', self::TEXT_DOMAIN));
							}
							if (!empty($it['trackViewUrl'])) {
								$out['appstore'] = esc_url_raw($it['trackViewUrl']);
							}
						}
					}
				}
			}
		}

		return $out;
	}

	/**
	 * Format price with localization
	 *
	 * @param float $price Price value.
	 * @return string Formatted price.
	 * @since 3.0.0
	 */
	private function format_price($price) {
		$locale = get_locale();
		$currency = 'USD';

		// Try to detect currency from locale
		if (strpos($locale, 'pt_BR') !== false) {
			$currency = 'BRL';
		} elseif (strpos($locale, 'es_') !== false) {
			$currency = 'EUR';
		}

		if (function_exists('number_format_i18n')) {
			$formatted = number_format_i18n($price, 2);
		} else {
			$formatted = number_format($price, 2, ',', '.');
		}

		// Simple currency symbol mapping
		$symbols = [
			'USD' => '$',
			'BRL' => 'R$',
			'EUR' => '€',
		];

		$symbol = $symbols[$currency] ?? '$';
		return $symbol . $formatted;
	}

	/**
	 * Get current site URL (including subdomain)
	 *
	 * Detects the current site URL based on the request, including subdomains.
	 * Falls back to home_url() if detection is not possible.
	 *
	 * @param string $path Optional path to append.
	 * @return string Current site URL with optional path.
	 * @since 3.0.0
	 */
	private function get_current_site_url($path = '') {
		// Detect current protocol
		$protocol = is_ssl() ? 'https://' : 'http://';
		
		// Detect current host from request
		$host = '';
		if (isset($_SERVER['HTTP_HOST']) && !empty($_SERVER['HTTP_HOST'])) {
			$host = sanitize_text_field($_SERVER['HTTP_HOST']);
		} else {
			// Fallback to home_url if HTTP_HOST is not available
			return home_url($path);
		}
		
		// Build URL
		$url = $protocol . $host;
		
		// Add path if provided
		if (!empty($path)) {
			$path = '/' . ltrim($path, '/');
			$url .= $path;
		}
		
		return esc_url_raw($url);
	}

	/**
	 * Build /go/ URL with token
	 *
	 * @param string $url  Destination URL.
	 * @param string $name App name.
	 * @return string /go/ URL.
	 * @since 2.0.0
	 */
	private function build_go($url, $name = '') {
		$o = get_option(self::OPT, []);
		$ttl = max(1, intval($o['token_ttl'] ?? 10));
		$token = wp_generate_password(20, false, false);
		$data = [
			'url' => esc_url_raw($url),
			'name' => sanitize_text_field($name),
			'ts' => time(),
		];

		// Rate limiting: check if too many tokens created recently
		$rate_key = 'rid_rate_' . wp_get_current_user()->ID;
		$rate_count = get_transient($rate_key) ?: 0;
		if ($rate_count > 100) {
			// Too many requests, delay
			return $this->get_current_site_url('/');
		}
		set_transient($rate_key, $rate_count + 1, 60); // 1 minute window

		set_transient('rid_' . $token, $data, $ttl * MINUTE_IN_SECONDS);
		return $this->get_current_site_url('/go/' . $token . '/');
	}

	/**
	 * Render app card
	 *
	 * @param string $gplayUrl    Google Play URL.
	 * @param string $appstoreUrl App Store URL.
	 * @return string Card HTML.
	 * @since 2.0.0
	 */
	private function render_card($gplayUrl, $appstoreUrl) {
		$m = $this->enrich_meta($gplayUrl, $appstoreUrl);
		$name = $m['name'] ?? __('Aplicativo', self::TEXT_DOMAIN);
		$icon = $m['icon'] ?? '';
		$rating = '';
		if (!empty($m['rating'])) {
			$num = floatval(str_replace(',', '.', (string) $m['rating']));
			if ($num > 0) {
				$rating = number_format_i18n($num, 1);
			}
		}
		$size = $m['size'] ?? '';
		$price = isset($m['price']) ? $m['price'] : __('Free', self::TEXT_DOMAIN);
		$platforms = ($gplayUrl ? 'Android' : '') . (($gplayUrl && $appstoreUrl) ? '/' : '') . ($appstoreUrl ? 'iOS' : '');
		$gplayBtn = $gplayUrl ? $this->build_go($gplayUrl, $name) : '';
		$iosBtn   = $appstoreUrl ? $this->build_go($appstoreUrl, $name) : '';

		// Fallback icon
		if (empty($icon)) {
			$icon = plugin_dir_url(__FILE__) . 'assets/default-app-icon.png';
		}

		ob_start();
		?>
		<section class="rid-card">
			<div class="rid-head">
				<?php if ($icon): ?>
					<img class="rid-icon" src="<?php echo esc_url($icon); ?>" alt="<?php echo esc_attr($name); ?>" loading="lazy" onerror="this.src='<?php echo esc_url(plugin_dir_url(__FILE__) . 'assets/default-app-icon.png'); ?>'">
				<?php endif; ?>
				<h2 class="rid-title"><?php echo esc_html($name); ?></h2>
				<?php if ($rating): ?>
					<span class="rid-rate">★ <?php echo esc_html($rating); ?></span>
				<?php endif; ?>
			</div>
			<div class="rid-grid">
				<?php if ($platforms): ?>
					<div><small><?php echo esc_html__('Plataforma', self::TEXT_DOMAIN); ?></small><strong><?php echo esc_html($platforms); ?></strong></div>
				<?php endif; ?>
				<?php if ($size): ?>
					<div><small><?php echo esc_html__('Tamanho', self::TEXT_DOMAIN); ?></small><strong><?php echo esc_html($size); ?></strong></div>
				<?php endif; ?>
				<div><small><?php echo esc_html__('Preço', self::TEXT_DOMAIN); ?></small><strong><?php echo esc_html($price); ?></strong></div>
			</div>
			<div class="rid-btns">
				<?php if ($gplayBtn): ?>
					<a class="rid-btn rid-btn--primary" rel="nofollow" href="<?php echo esc_url($gplayBtn); ?>">
						<svg class="rid-btn-icon" viewBox="0 0 24 24" fill="currentColor" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
							<path d="M3,20.5V3.5C3,2.91 3.34,2.39 3.84,2.15L13.69,12L3.84,21.85C3.34,21.61 3,21.09 3,20.5M16.81,15.12L6.05,21.34L14.54,12.85L16.81,15.12M20.16,10.81C20.5,11.08 20.75,11.5 20.75,12C20.75,12.5 20.53,12.9 20.18,13.18L17.89,14.5L15.39,12L17.89,9.5L20.16,10.81M6.05,2.66L16.81,8.88L14.54,11.15L6.05,2.66Z"/>
						</svg>
						<span><?php echo esc_html__('Baixar no Google Play', self::TEXT_DOMAIN); ?></span>
					</a>
				<?php endif; ?>
				<?php if ($iosBtn): ?>
					<a class="rid-btn rid-btn--primary" rel="nofollow" href="<?php echo esc_url($iosBtn); ?>">
						<svg class="rid-btn-icon" viewBox="0 0 24 24" fill="currentColor" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
							<path d="M18.71,19.5C17.88,20.74 17,21.95 15.66,21.97C14.32,22 13.89,21.18 12.37,21.18C10.84,21.18 10.37,21.95 9.1,22C7.79,22.05 6.8,20.68 5.96,19.47C4.25,17 2.94,12.45 4.7,9.39C5.57,7.87 7.13,6.91 8.82,6.88C10.1,6.86 11.32,7.75 12.11,7.75C12.89,7.75 14.37,6.68 15.92,6.84C16.57,6.87 18.39,7.1 19.56,8.82C19.47,8.88 17.39,10.1 17.41,12.63C17.44,15.65 20.06,16.66 20.09,16.67C20.06,16.74 19.67,18.11 18.71,19.5M13,3.5C13.73,2.67 14.94,2.04 15.94,2C16.07,3.17 15.6,4.35 14.9,5.19C14.21,6.04 13.07,6.7 11.95,6.61C11.8,5.46 12.36,4.26 13,3.5Z"/>
						</svg>
						<span><?php echo esc_html__('Baixar na App Store', self::TEXT_DOMAIN); ?></span>
					</a>
				<?php endif; ?>
			</div>
			<small class="rid-note"><?php echo esc_html__('As informações sobre tamanho, instalações e avaliação podem variar conforme atualizações nas lojas oficiais.', self::TEXT_DOMAIN); ?></small>
		</section>
		<?php
		return ob_get_clean();
	}

	/**
	 * Inject cards into content
	 *
	 * @param string $content Post content.
	 * @return string Modified content.
	 * @since 2.0.0
	 */
	public function inject_cards($content) {
		if (!is_singular('post') || !in_the_loop() || !is_main_query()) {
			return $content;
		}

		$apps = get_post_meta(get_the_ID(), self::PMK, true);
		if (!is_array($apps) || empty($apps)) {
			return $content;
		}

		// Transform content into paragraph blocks
		$closing = '</p>';
		$parts = explode($closing, $content);
		// Sort by position
		usort($apps, function($a, $b) {
			return intval($a['pos'] ?? 0) <=> intval($b['pos'] ?? 0);
		});

		// Progressive insertion (0 = before everything)
		$bufferBefore = '';
		foreach ($apps as $app) {
			$g = $app['gplay'] ?? '';
			$a = $app['appstore'] ?? '';
			$p = max(0, intval($app['pos'] ?? 0));
			$card = $this->render_card($g, $a);
			if ($p === 0) {
				$bufferBefore .= $card;
				continue;
			}
			// If content has fewer <p> than p, push to end
			$idx = min($p, count($parts));
			// Rebuild adding card right after chosen p
			$parts[$idx - 1] = $parts[$idx - 1] . $closing . $card;
		}

		$html = implode($closing, $parts);
		return $bufferBefore . $html;
	}

	/**
	 * Shortcode handler
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string Card HTML.
	 * @since 2.0.0
	 */
	public function shortcode_single($atts) {
		$a = shortcode_atts(['gplay' => '', 'appstore' => ''], $atts);
		return $this->render_card($a['gplay'], $a['appstore']);
	}

	# ===== /go/ Endpoint =====

	/**
	 * Early intercept /go/ request on init hook
	 * Handles cases where rewrite rules don't work (e.g., subdomains)
	 *
	 * @since 3.0.0
	 */
	public function early_intercept_go_request() {
		if (!isset($_SERVER['REQUEST_URI'])) {
			return;
		}

		$request_uri = sanitize_text_field($_SERVER['REQUEST_URI']);
		// Remove query string if present
		$request_uri = strtok($request_uri, '?');
		
		// Check if this is a /go/ request
		if (preg_match('#/go/([A-Za-z0-9\-_]+)/?#', $request_uri, $matches)) {
			$token = $matches[1];
			
			// Validate token format
			if (preg_match('/^[A-Za-z0-9\-_]{20}$/', $token)) {
				// Process directly without waiting for rewrite rules
				$this->process_go_request($token);
				exit;
			}
		}
	}

	/**
	 * Intercept /go/ request early (before rewrite rules)
	 *
	 * @param WP $wp WordPress environment instance.
	 * @since 3.0.0
	 */
	public function intercept_go_request($wp) {
		if (!isset($_SERVER['REQUEST_URI'])) {
			return;
		}

		$request_uri = sanitize_text_field($_SERVER['REQUEST_URI']);
		// Remove query string if present
		$request_uri = strtok($request_uri, '?');
		
		// Check if this is a /go/ request
		if (preg_match('#/go/([A-Za-z0-9\-_]+)/?#', $request_uri, $matches)) {
			$token = $matches[1];
			
			// Validate token format
			if (preg_match('/^[A-Za-z0-9\-_]{20}$/', $token)) {
				// Set query var manually so handle_go_request can process it
				$wp->query_vars[self::QV] = $token;
			}
		}
	}

	/**
	 * Register /go/ rewrite endpoint
	 *
	 * @since 2.0.0
	 */
	public function register_go_endpoint() {
		add_rewrite_rule('^go/([A-Za-z0-9\-_]+)/?$', 'index.php?' . self::QV . '=$matches[1]', 'top');
		add_rewrite_tag('%' . self::QV . '%', '([A-Za-z0-9\-_]+)');
	}

	/**
	 * Activation hook
	 *
	 * @since 2.0.0
	 */
	public function on_activate() {
		$this->register_go_endpoint();
		flush_rewrite_rules();
	}

	/**
	 * Handle /go/ request
	 *
	 * @since 2.0.0
	 */
	public function handle_go_request() {
		// Get token from query var (works with rewrite rules)
		$token = get_query_var(self::QV);
		
		// If not found via query var, try to extract from request URI directly
		// This handles cases where rewrite rules don't work (e.g., subdomains)
		if (!$token && isset($_SERVER['REQUEST_URI'])) {
			$request_uri = sanitize_text_field($_SERVER['REQUEST_URI']);
			// Remove query string if present
			$request_uri = strtok($request_uri, '?');
			// Match /go/token/ pattern
			if (preg_match('#/go/([A-Za-z0-9\-_]+)/?#', $request_uri, $matches)) {
				$token = $matches[1];
			}
		}
		
		if (!$token) {
			return;
		}

		$this->process_go_request($token);
	}

	/**
	 * Process /go/ request with token
	 *
	 * @param string $token Token to process.
	 * @since 3.0.0
	 */
	private function process_go_request($token) {
		// Validate token format
		if (!preg_match('/^[A-Za-z0-9\-_]{20}$/', $token)) {
			wp_safe_redirect($this->get_current_site_url('/'));
			exit;
		}

		$opts = get_option(self::OPT, []);
		$hold_ms = intval($opts['hold_ms'] ?? 150);
		$data = get_transient('rid_' . $token);

		if (!$data || empty($data['url'])) {
			wp_safe_redirect($this->get_current_site_url('/'));
			exit;
		}

		$final = esc_url_raw($data['url']);
		status_header(200);
		nocache_headers();
		?>
<!doctype html>
<html lang="<?php echo esc_attr(get_locale()); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html__('Redirecionando…', self::TEXT_DOMAIN); ?></title>
<meta name="robots" content="noindex,nofollow">
<script>(function(){var u=<?php echo json_encode($final); ?>,d=<?php echo json_encode($hold_ms); ?>;function go(){location.href=u;} if(d>0) setTimeout(go,d); else go();})();</script>
<noscript><meta http-equiv="refresh" content="0;url=<?php echo esc_attr($final); ?>"></noscript>
</head>
<body></body>
</html>
		<?php
		exit;
	}

	/**
	 * Cleanup expired transients
	 *
	 * @since 3.0.0
	 */
	public function cleanup_transients() {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %d",
				$wpdb->esc_like('_transient_timeout_rid_') . '%',
				time()
			)
		);
	}

	/**
	 * Clear all plugin cache (all transients starting with rid_)
	 *
	 * @since 3.0.0
	 * @return int Number of deleted transients.
	 */
	public function clear_all_cache() {
		global $wpdb;
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like('_transient_rid_') . '%',
				$wpdb->esc_like('_transient_timeout_rid_') . '%'
			)
		);
		wp_cache_flush();
		return $deleted;
	}
}

// Initialize plugin
RedirectID_AD::get_instance();

// Register cron hook for async fetching
add_action('rid_fetch_meta', function($gplayUrl, $appstoreUrl) {
	$instance = RedirectID_AD::get_instance();
	$key = 'rid_meta_' . md5($gplayUrl . '|' . $appstoreUrl);
	$meta = $instance->fetch_meta_sync($gplayUrl, $appstoreUrl);
	set_transient($key, $meta, RedirectID_AD::META_CACHE_TTL);
	delete_transient($key . '_fetching');
}, 10, 2);
