<?php
/**
 * Plugin Name: Akeno Interno
 * Description: Bloco de botões para manter o usuário navegando no site. Design otimizado para celular e links internos já sinalizados para o post principal.
 * Version: 1.0
 * Author: TwoD
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'AKENO_URL', plugin_dir_url( __FILE__ ) );
define( 'AKENO_DIR', plugin_dir_path( __FILE__ ) );

add_action( 'init', function () {
	$deps = [ 'wp-blocks', 'wp-element', 'wp-components', 'wp-i18n', 'wp-block-editor', 'wp-data', 'wp-api-fetch', 'wp-server-side-render' ];

	wp_register_script(
		'akeno-editor-js',
		AKENO_URL . 'assets/editor.js',
		$deps,
		filemtime( AKENO_DIR . 'assets/editor.js' )
	);

	wp_localize_script( 'akeno-editor-js', 'AKENO_SETTINGS', [
		'ajaxUrl' => admin_url('admin-ajax.php'),
		'nonce'   => wp_create_nonce('akeno-nonce'),
	]);

	wp_register_style( 'akeno-editor-css', AKENO_URL . 'assets/editor.css', [], filemtime( AKENO_DIR . 'assets/editor.css' ) );
	wp_register_style( 'akeno-front-css',  AKENO_URL . 'assets/style.css',  [], filemtime( AKENO_DIR . 'assets/style.css' ) );

	$common = [
		'render_callback' => 'akeno_render_block',
		'editor_script'   => 'akeno-editor-js',
		'editor_style'    => 'akeno-editor-css',
		'style'           => 'akeno-front-css',
		'attributes'      => [
			'includeSrcGlobal' => [ 'type' => 'boolean', 'default' => true ],
			'buttons'          => [ 'type' => 'array',   'default' => [] ],
			'variant'          => [ 'type' => 'string',  'default' => 'inverse' ],
		],
		'supports'        => [
			'className'        => false,
			'customClassName'  => false,
			'anchor'           => false,
			'html'             => false,
		],
	];

	// Main block in inserter
	register_block_type( 'twod/akeno-interno', $common );

	// Legacy compat block (hidden from inserter)
	$compat = $common;
	$compat['supports']['inserter'] = false;
	register_block_type( 'twod/redirect-buttons', $compat );
} );

// ---------------- helpers ----------------
function akeno_get_post_from_dest( $dest ) {
	$dest = trim( (string) $dest );
	if ( $dest === '' ) { return false; }
	if ( ctype_digit( $dest ) ) {
		$p = get_post( intval($dest) );
		return $p ?: false;
	}
	$p = get_page_by_path( $dest, OBJECT, get_post_types( [ 'public' => true ] ) );
	if ( $p ) return $p;
	if ( preg_match( '#^https?://#i', $dest ) ) {
		$home_host = parse_url( home_url(), PHP_URL_HOST );
		$host = parse_url( $dest, PHP_URL_HOST );
		if ( $host && strtolower($host) === strtolower($home_host) ) {
			$path = trim( (string) parse_url( $dest, PHP_URL_PATH ), '/' );
			if ( $path !== '' ) {
				$p = get_page_by_path( $path, OBJECT, get_post_types( [ 'public' => true ] ) );
				if ( $p ) return $p;
			}
		}
	}
	return false;
}
function akeno_resolve_url( $dest ) {
	$dest = trim( (string) $dest );
	if ( $dest === '' ) { return ''; }
	if ( preg_match( '#^https?://#i', $dest ) ) { return esc_url( $dest ); }
	if ( ctype_digit( $dest ) ) {
		$plink = get_permalink( intval( $dest ) );
		return $plink ? esc_url( $plink ) : '';
	}
	$post = get_page_by_path( $dest, OBJECT, get_post_types( [ 'public' => true ] ) );
	if ( $post ) {
		$plink = get_permalink( $post );
		return $plink ? esc_url( $plink ) : '';
	}
	return '';
}
function akeno_is_internal_url( $url ) {
	$home_host = parse_url( home_url(), PHP_URL_HOST );
	$host = parse_url( $url, PHP_URL_HOST );
	if ( $host === null || $host === '' ) { return true; }
	return ( strtolower($host) === strtolower($home_host) );
}
function akeno_apply_tracking( $url, $source_id ) {
	$url = remove_query_arg( [ 'src' ], $url );
	$url = add_query_arg( [ 'tp' => 'new', 'src' => $source_id ], $url );
	return $url;
}

// ---------------- render ----------------
function akeno_render_block( $attrs ) {
	$buttons = isset( $attrs['buttons'] ) && is_array( $attrs['buttons'] ) ? $attrs['buttons'] : [];
	$variant = isset( $attrs['variant'] ) ? sanitize_key( $attrs['variant'] ) : 'inverse';
	$enable_tracking = ! empty( $attrs['includeSrcGlobal'] );

	$current_post  = get_post();
	$current_id    = $current_post ? $current_post->ID : 0;
	$current_title = $current_post ? get_the_title( $current_post ) : '';

	// 1) Detect src from current URL (?tp=new&src=...)
	$src_from_url = 0;
	if ( isset($_GET['tp']) && $_GET['tp'] === 'new' && isset($_GET['src']) ) {
		$raw = preg_replace('/[^0-9]/', '', (string) $_GET['src']);
		if ( $raw !== '' ) { $src_from_url = intval($raw); }
	}

	$out = '<div class="akeno-wrapper variant-' . esc_attr( $variant ) . '">';
	foreach ( $buttons as $btn ) {
		$dest_raw = isset( $btn['dest'] ) ? $btn['dest'] : '';
		if ( $dest_raw === '' ) { continue; }

		// 2) Text fallback: if empty -> title of DEST post; else -> current post title
		$text = isset( $btn['text'] ) ? wp_strip_all_tags( $btn['text'] ) : '';
		if ( $text === '' ) {
			$dp = akeno_get_post_from_dest( $dest_raw );
			$text = $dp ? get_the_title( $dp ) : $current_title;
		}

		// 3) Resolve URL
		$url = akeno_resolve_url( $dest_raw );
		if ( ! $url ) { continue; }

		// 4) Choose source id by precedence
		$source_id = $current_id;
		if ( ! empty( $btn['src'] ) && ! empty( $btn['srcValue'] ) ) {
			$source_id = intval( $btn['srcValue'] );
		} elseif ( $src_from_url ) {
			$source_id = $src_from_url;
		}

		// 5) Apply tracking if internal
		if ( $enable_tracking && $source_id && akeno_is_internal_url( $url ) ) {
			$url = akeno_apply_tracking( $url, $source_id );
		}

		$icon = isset( $btn['icon'] ) ? wp_strip_all_tags( $btn['icon'] ) : '';
		$icon_html = $icon ? '<span class="akeno-icon" aria-hidden="true">' . $icon . '</span>' : '';

		$out .= '<a class="akeno-button" href="' . esc_url( $url ) . '">';
		$out .= '<span class="akeno-inner">' . $icon_html . '<span class="akeno-text">' . esc_html( $text ) . '</span>';
		$out .= '<span class="akeno-caret" aria-hidden="true">›</span></span>';
		$out .= '</a>';
	}
	$out .= '</div>';

	if ( ! is_admin() ) {
		$out .= '<p class="akeno-note">Nota: todos los enlaces son a contenidos dentro de nuestro propio sitio.</p>';
	}

	return $out;
}

// ------- shortcode (opcional) -------
add_shortcode( 'akeno_buttons', function( $atts ){
	$atts = shortcode_atts( [
		'buttons'        => '[]',
		'src_global'     => '1',
		'variant'        => 'inverse',
	], $atts, 'akeno_buttons' );
	$attrs = [
		'buttons'          => json_decode( (string) $atts['buttons'], true ) ?: [],
		'includeSrcGlobal' => ( $atts['src_global'] === '1' ),
		'variant'          => sanitize_key( $atts['variant'] ),
	];
	return akeno_render_block( $attrs );
} );

// ------- AJAX helpers -------
add_action( 'wp_ajax_akeno_post_info', function(){
	check_ajax_referer('akeno-nonce','nonce');
	$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
	if(!$id){ wp_send_json_error(['message'=>'missing id']); }
	$post = get_post($id);
	if(!$post){ wp_send_json_error(['message'=>'not found']); }
	wp_send_json_success([
		'id'    => $id,
		'title' => get_the_title($post),
		'link'  => get_permalink($post),
		'type'  => $post->post_type,
	]);
});
add_action( 'wp_ajax_akeno_suggest', function(){
	check_ajax_referer('akeno-nonce','nonce');
	$q  = isset($_GET['q']) ? sanitize_text_field( wp_unslash($_GET['q']) ) : '';
	if(!$q){ wp_send_json_success(['items'=>[], 'provider'=>null, 'error'=>null]); }
	$url = add_query_arg([ 'client' => 'firefox', 'hl' => 'pt', 'q' => $q ], 'https://suggestqueries.google.com/complete/search' );
	$args = [ 'timeout' => 8, 'headers' => [ 'User-Agent' => 'Mozilla/5.0', 'Accept-Language' => 'pt,en;q=0.8', 'Accept' => 'application/json,text/plain,*/*' ] ];
	$response = wp_remote_get( $url, $args );
	if ( is_wp_error( $response ) ) { wp_send_json_success([ 'items' => [], 'provider' => 'suggestqueries-json', 'error' => $response->get_error_message() ]); }
	$code = wp_remote_retrieve_response_code( $response );
	$body = wp_remote_retrieve_body( $response );
	$items = [];
	if ( $code === 200 && $body ) {
		$data = json_decode( $body, true );
		if ( is_array($data) && isset($data[1]) && is_array($data[1]) ) {
			foreach( $data[1] as $s ) { if ( is_string($s) && $s !== '' ) $items[] = $s; }
		}
	}
	wp_send_json_success([ 'items' => $items, 'provider' => 'suggestqueries-json', 'error' => null ]);
});
