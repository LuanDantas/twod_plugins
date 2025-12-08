<?php
/**
 * Plugin Name: Akeno Interno
 * Description: Bloco de botões para manter o usuário navegando no site. Design otimizado para celular e links internos já sinalizados para o post principal.
 * Version: 2.0.0
 * Author: TwoD
 * Text Domain: akeno-interno
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package Akeno_Interno
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'AKENO_URL', plugin_dir_url( __FILE__ ) );
define( 'AKENO_DIR', plugin_dir_path( __FILE__ ) );
define( 'AKENO_VERSION', '2.0.0' );
define( 'AKENO_TEXT_DOMAIN', 'akeno-interno' );

/**
 * Main plugin class
 *
 * @since 1.0.0
 */
class Akeno_Interno {
	/**
	 * Cache group for transients
	 */
	const CACHE_GROUP = 'akeno_cache';
	const CACHE_TTL = 3600; // 1 hour

	/**
	 * Singleton instance
	 *
	 * @var Akeno_Interno
	 */
	private static $instance = null;

	/**
	 * Get singleton instance
	 *
	 * @return Akeno_Interno
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
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
		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );

		// Register block
		add_action( 'init', array( $this, 'register_block' ) );

		// Register shortcode
		add_shortcode( 'akeno_buttons', array( $this, 'shortcode_handler' ) );

		// AJAX handlers
		add_action( 'wp_ajax_akeno_post_info', array( $this, 'ajax_post_info' ) );
		add_action( 'wp_ajax_akeno_suggest', array( $this, 'ajax_suggest' ) );

		// Cleanup expired transients
		add_action( 'wp_scheduled_delete', array( $this, 'cleanup_transients' ) );
	}

	/**
	 * Load plugin textdomain
	 *
	 * @since 2.0.0
	 */
	public function load_textdomain() {
		load_plugin_textdomain(
			AKENO_TEXT_DOMAIN,
			false,
			dirname( plugin_basename( __FILE__ ) ) . '/languages'
		);
	}

	/**
	 * Register Gutenberg block
	 *
	 * @since 1.0.0
	 */
	public function register_block() {
		$deps = array(
			'wp-blocks',
			'wp-element',
			'wp-components',
			'wp-i18n',
			'wp-block-editor',
			'wp-data',
			'wp-api-fetch',
			'wp-server-side-render',
		);

		wp_register_script(
			'akeno-editor-js',
			AKENO_URL . 'assets/editor.js',
			$deps,
			filemtime( AKENO_DIR . 'assets/editor.js' ),
			true
		);

		// Generate nonce per request for better security
		wp_localize_script(
			'akeno-editor-js',
			'AKENO_SETTINGS',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'akeno-nonce' ),
			)
		);

		wp_register_style(
			'akeno-editor-css',
			AKENO_URL . 'assets/editor.css',
			array(),
			filemtime( AKENO_DIR . 'assets/editor.css' )
		);
		wp_register_style(
			'akeno-front-css',
			AKENO_URL . 'assets/style.css',
			array(),
			filemtime( AKENO_DIR . 'assets/style.css' )
		);

		$common = array(
			'render_callback' => array( $this, 'render_block' ),
			'editor_script'   => 'akeno-editor-js',
			'editor_style'    => 'akeno-editor-css',
			'style'           => 'akeno-front-css',
			'attributes'       => array(
				'includeSrcGlobal' => array(
					'type'    => 'boolean',
					'default' => true,
				),
				'buttons'          => array(
					'type'    => 'array',
					'default' => array(),
				),
				'variant'          => array(
					'type'    => 'string',
					'default' => 'inverse',
				),
			),
			'supports'         => array(
				'className'       => false,
				'customClassName' => false,
				'anchor'          => false,
				'html'            => false,
			),
		);

		// Main block in inserter
		register_block_type( 'twod/akeno-interno', $common );

		// Legacy compat block (hidden from inserter)
		$compat = $common;
		$compat['supports']['inserter'] = false;
		register_block_type( 'twod/redirect-buttons', $compat );
	}

	/**
	 * Get post from destination (ID, slug, or URL)
	 *
	 * @param string $dest Destination identifier.
	 * @return WP_Post|false Post object or false if not found.
	 * @since 1.0.0
	 */
	public function get_post_from_dest( $dest ) {
		$dest = trim( (string) $dest );
		if ( '' === $dest ) {
			return false;
		}

		// Check cache first
		$cache_key = 'post_dest_' . md5( $dest );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			$post = get_post( $cached );
			return $post ? $post : false;
		}

		$post = false;

		// Try as post ID
		if ( ctype_digit( $dest ) ) {
			$post = get_post( intval( $dest ) );
			if ( $post ) {
				set_transient( $cache_key, $post->ID, self::CACHE_TTL );
				return $post;
			}
		}

		// Try as slug
		$post = get_page_by_path( $dest, OBJECT, get_post_types( array( 'public' => true ) ) );
		if ( $post ) {
			set_transient( $cache_key, $post->ID, self::CACHE_TTL );
			return $post;
		}

		// Try as URL
		if ( preg_match( '#^https?://#i', $dest ) ) {
			$home_host = wp_parse_url( home_url(), PHP_URL_HOST );
			$host      = wp_parse_url( $dest, PHP_URL_HOST );
			if ( $host && strtolower( $host ) === strtolower( $home_host ) ) {
				$path = trim( (string) wp_parse_url( $dest, PHP_URL_PATH ), '/' );
				if ( '' !== $path ) {
					$post = get_page_by_path( $path, OBJECT, get_post_types( array( 'public' => true ) ) );
					if ( $post ) {
						set_transient( $cache_key, $post->ID, self::CACHE_TTL );
						return $post;
					}
				}
			}
		}

		return false;
	}

	/**
	 * Resolve URL from destination
	 *
	 * @param string $dest Destination identifier.
	 * @return string Resolved URL or empty string.
	 * @since 1.0.0
	 */
	public function resolve_url( $dest ) {
		$dest = trim( (string) $dest );
		if ( '' === $dest ) {
			return '';
		}

		// Check cache
		$cache_key = 'url_dest_' . md5( $dest );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$url = '';

		// Already a full URL
		if ( preg_match( '#^https?://#i', $dest ) ) {
			$url = esc_url_raw( $dest );
			set_transient( $cache_key, $url, self::CACHE_TTL );
			return $url;
		}

		// Try as post ID
		if ( ctype_digit( $dest ) ) {
			$plink = get_permalink( intval( $dest ) );
			if ( $plink ) {
				$url = esc_url( $plink );
				set_transient( $cache_key, $url, self::CACHE_TTL );
				return $url;
			}
		}

		// Try as slug
		$post = get_page_by_path( $dest, OBJECT, get_post_types( array( 'public' => true ) ) );
		if ( $post ) {
			$plink = get_permalink( $post );
			if ( $plink ) {
				$url = esc_url( $plink );
				set_transient( $cache_key, $url, self::CACHE_TTL );
				return $url;
			}
		}

		return '';
	}

	/**
	 * Check if URL is internal
	 *
	 * @param string $url URL to check.
	 * @return bool True if internal, false otherwise.
	 * @since 1.0.0
	 */
	public function is_internal_url( $url ) {
		$home_host = wp_parse_url( home_url(), PHP_URL_HOST );
		$host      = wp_parse_url( $url, PHP_URL_HOST );
		if ( null === $host || '' === $host ) {
			return true;
		}
		return ( strtolower( $host ) === strtolower( $home_host ) );
	}

	/**
	 * Apply tracking parameters to URL
	 *
	 * @param string $url       URL to modify.
	 * @param int    $source_id Source post ID.
	 * @return string Modified URL.
	 * @since 1.0.0
	 */
	public function apply_tracking( $url, $source_id ) {
		$url = remove_query_arg( array( 'src' ), $url );
		$url = add_query_arg(
			array(
				'tp'  => 'new',
				'src' => absint( $source_id ),
			),
			$url
		);
		return $url;
	}

	/**
	 * Render block
	 *
	 * @param array $attrs Block attributes.
	 * @return string Rendered HTML.
	 * @since 1.0.0
	 */
	public function render_block( $attrs ) {
		$buttons       = isset( $attrs['buttons'] ) && is_array( $attrs['buttons'] ) ? $attrs['buttons'] : array();
		$variant       = isset( $attrs['variant'] ) ? sanitize_key( $attrs['variant'] ) : 'inverse';
		$enable_tracking = ! empty( $attrs['includeSrcGlobal'] );

		$current_post  = get_post();
		$current_id    = $current_post ? $current_post->ID : 0;
		$current_title = $current_post ? get_the_title( $current_post ) : '';

		// Detect src from current URL (?tp=new&src=...)
		$src_from_url = 0;
		if ( isset( $_GET['tp'] ) && 'new' === $_GET['tp'] && isset( $_GET['src'] ) ) {
			$raw = preg_replace( '/[^0-9]/', '', (string) $_GET['src'] );
			if ( '' !== $raw ) {
				$src_from_url = intval( $raw );
			}
		}

		$out = '<div class="akeno-wrapper variant-' . esc_attr( $variant ) . '">';

		foreach ( $buttons as $btn ) {
			$dest_raw = isset( $btn['dest'] ) ? sanitize_text_field( $btn['dest'] ) : '';
			if ( '' === $dest_raw ) {
				continue;
			}

			// Validate post exists before rendering
			$dest_post = $this->get_post_from_dest( $dest_raw );
			if ( ! $dest_post && ! preg_match( '#^https?://#i', $dest_raw ) ) {
				// Skip invalid destinations (unless it's an external URL)
				continue;
			}

			// Text fallback: if empty -> title of DEST post; else -> current post title
			$text = isset( $btn['text'] ) ? wp_strip_all_tags( $btn['text'] ) : '';
			if ( '' === $text ) {
				$text = $dest_post ? get_the_title( $dest_post ) : $current_title;
			}

			// Resolve URL
			$url = $this->resolve_url( $dest_raw );
			if ( ! $url ) {
				continue;
			}

			// Choose source id by precedence
			$source_id = $current_id;
			if ( ! empty( $btn['src'] ) && ! empty( $btn['srcValue'] ) ) {
				$source_id = absint( $btn['srcValue'] );
			} elseif ( $src_from_url ) {
				$source_id = $src_from_url;
			}

			// Apply tracking if internal
			if ( $enable_tracking && $source_id && $this->is_internal_url( $url ) ) {
				$url = $this->apply_tracking( $url, $source_id );
			}

			$icon      = isset( $btn['icon'] ) ? wp_strip_all_tags( $btn['icon'] ) : '';
			$icon_html = $icon ? '<span class="akeno-icon" aria-hidden="true">' . esc_html( $icon ) . '</span>' : '';

			$out .= '<a class="akeno-button" href="' . esc_url( $url ) . '" aria-label="' . esc_attr( $text ) . '">';
			$out .= '<span class="akeno-inner">' . $icon_html . '<span class="akeno-text">' . esc_html( $text ) . '</span>';
			$out .= '<span class="akeno-caret" aria-hidden="true">›</span></span>';
			$out .= '</a>';
		}

		$out .= '</div>';

		if ( ! is_admin() ) {
			$out .= '<p class="akeno-note">' . esc_html__( 'Nota: todos los enlaces son a contenidos dentro de nuestro propio sitio.', 'akeno-interno' ) . '</p>';
		}

		/**
		 * Filter the rendered block output
		 *
		 * @since 2.0.0
		 * @param string $out   Rendered HTML.
		 * @param array  $attrs Block attributes.
		 */
		return apply_filters( 'akeno_render_block', $out, $attrs );
	}

	/**
	 * Shortcode handler
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string Rendered HTML.
	 * @since 1.0.0
	 */
	public function shortcode_handler( $atts ) {
		$atts = shortcode_atts(
			array(
				'buttons'    => '[]',
				'src_global' => '1',
				'variant'    => 'inverse',
			),
			$atts,
			'akeno_buttons'
		);

		$attrs = array(
			'buttons'          => json_decode( (string) $atts['buttons'], true ) ?: array(),
			'includeSrcGlobal' => ( '1' === $atts['src_global'] ),
			'variant'          => sanitize_key( $atts['variant'] ),
		);

		return $this->render_block( $attrs );
	}

	/**
	 * AJAX handler: Get post info
	 *
	 * @since 1.0.0
	 */
	public function ajax_post_info() {
		// Verify nonce
		check_ajax_referer( 'akeno-nonce', 'nonce' );

		// Check capabilities
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions', 'akeno-interno' ) ) );
		}

		$id = isset( $_GET['id'] ) ? intval( $_GET['id'] ) : 0;
		if ( ! $id ) {
			wp_send_json_error( array( 'message' => __( 'Missing ID', 'akeno-interno' ) ) );
		}

		// Check cache
		$cache_key = 'post_info_' . $id;
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			wp_send_json_success( $cached );
		}

		$post = get_post( $id );
		if ( ! $post ) {
			wp_send_json_error( array( 'message' => __( 'Post not found', 'akeno-interno' ) ) );
		}

		$data = array(
			'id'    => $id,
			'title' => get_the_title( $post ),
			'link'  => get_permalink( $post ),
			'type'  => $post->post_type,
		);

		set_transient( $cache_key, $data, self::CACHE_TTL );
		wp_send_json_success( $data );
	}

	/**
	 * AJAX handler: Get suggestions
	 *
	 * @since 1.0.0
	 */
	public function ajax_suggest() {
		// Verify nonce
		check_ajax_referer( 'akeno-nonce', 'nonce' );

		// Check capabilities
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions', 'akeno-interno' ) ) );
		}

		$q = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
		if ( ! $q ) {
			wp_send_json_success(
				array(
					'items'    => array(),
					'provider' => null,
					'error'    => null,
				)
			);
		}

		// Check cache
		$cache_key = 'suggest_' . md5( $q );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			wp_send_json_success( $cached );
		}

		$url  = add_query_arg(
			array(
				'client' => 'firefox',
				'hl'     => 'pt',
				'q'      => $q,
			),
			'https://suggestqueries.google.com/complete/search'
		);
		$args = array(
			'timeout' => 8,
			'headers' => array(
				'User-Agent'      => 'Mozilla/5.0',
				'Accept-Language' => 'pt,en;q=0.8',
				'Accept'          => 'application/json,text/plain,*/*',
			),
		);

		$response = wp_remote_get( $url, $args );
		$items    = array();

		if ( is_wp_error( $response ) ) {
			wp_send_json_success(
				array(
					'items'    => array(),
					'provider' => 'suggestqueries-json',
					'error'    => $response->get_error_message(),
				)
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( 200 === $code && $body ) {
			$data = json_decode( $body, true );
			if ( is_array( $data ) && isset( $data[1] ) && is_array( $data[1] ) ) {
				foreach ( $data[1] as $s ) {
					if ( is_string( $s ) && '' !== $s ) {
						$items[] = sanitize_text_field( $s );
					}
				}
			}
		}

		$result = array(
			'items'    => $items,
			'provider' => 'suggestqueries-json',
			'error'    => null,
		);

		set_transient( $cache_key, $result, 1800 ); // 30 minutes
		wp_send_json_success( $result );
	}

	/**
	 * Cleanup expired transients
	 *
	 * @since 2.0.0
	 */
	public function cleanup_transients() {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %d",
				$wpdb->esc_like( '_transient_timeout_akeno_' ) . '%',
				time()
			)
		);
	}
}

// Initialize plugin
Akeno_Interno::get_instance();
