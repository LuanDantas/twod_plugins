<?php
/**
 * Plugin Name: TranslateX Integration
 * Description: Tradução server-side com TranslateX + cache, URLs por idioma com exceção do idioma original do WordPress: /{lang} para todos exceto o idioma padrão (sem prefixo). Mantém idioma em toda a navegação.
 * Version: 2.8
 * Author: Seu Nome
 */

if ( ! defined( 'ABSPATH' ) ) exit;

require_once plugin_dir_path( __FILE__ ) . 'includes/class-translatex-client.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-translatex-cache.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-translatex-strings.php';

if ( ! defined( 'TRANSLATEX_CACHE_DB_VERSION' ) ) {
    define( 'TRANSLATEX_CACHE_DB_VERSION', 3 );
}
if ( ! defined( 'TRANSLATEX_DEBUG' ) ) {
    define( 'TRANSLATEX_DEBUG', WP_DEBUG );
}

// Constante para cookie de idioma
if ( ! defined( 'TRANSLATEX_LANG_COOKIE' ) ) {
    define( 'TRANSLATEX_LANG_COOKIE', 'translatex_lang' );
}

// Variável global para debug
$GLOBALS['translatex_debug'] = array();

/* ============================================================
 * SUPPORTED + NORMALIZAÇÃO (EXATAMENTE COMO NO JSON)
 * ============================================================ */
function translatex_supported_langs_with_names() {
    return array(
        array('language' => 'ro',    'name' => 'Romanian'),
        array('language' => 'ar',    'name' => 'Arabic'),
        array('language' => 'no',    'name' => 'Norwegian'),
        array('language' => 'iw',    'name' => 'Hebrew'),
        array('language' => 'vi',    'name' => 'Vietnamese'),
        array('language' => 'ko',    'name' => 'Korean'),
        array('language' => 'bg',    'name' => 'Bulgarian'),
        array('language' => 'cs',    'name' => 'Czech'),
        array('language' => 'hr',    'name' => 'Croatian'),
        array('language' => 'th',    'name' => 'Thai'),
        array('language' => 'lt',    'name' => 'Lithuanian'),
        array('language' => 'uk',    'name' => 'Ukrainian'),
        array('language' => 'fi',    'name' => 'Finnish'),
        array('language' => 'hi',    'name' => 'Hindi'),
        array('language' => 'hu',    'name' => 'Hungarian'),
        array('language' => 'bn',    'name' => 'Bengali'),
        array('language' => 'sk',    'name' => 'Slovak'),
        array('language' => 'sl',    'name' => 'Slovenian'),
        array('language' => 'id',    'name' => 'Indonesian'),
        array('language' => 'en',    'name' => 'English'),
        array('language' => 'fr',    'name' => 'French'),
        array('language' => 'es',    'name' => 'Spanish'),
        array('language' => 'de',    'name' => 'German'),
        array('language' => 'it',    'name' => 'Italian'),
        array('language' => 'nl',    'name' => 'Dutch'),
        array('language' => 'ru',    'name' => 'Russian'),
        array('language' => 'pt',    'name' => 'Portuguese'),
        array('language' => 'ja',    'name' => 'Japanese'),
        array('language' => 'tr',    'name' => 'Turkish'),
        array('language' => 'pl',    'name' => 'Polish'),
        array('language' => 'sv',    'name' => 'Swedish'),
        array('language' => 'da',    'name' => 'Danish'),
        array('language' => 'zh-CN', 'name' => 'Chinese (Simplified)'),
        array('language' => 'zh-TW', 'name' => 'Chinese (Traditional)'),
        array('language' => 'el',    'name' => 'Greek'),
    );
}

function translatex_supported_langs() {
    static $codes = null;
    if ($codes === null) {
        $codes = array();
        foreach (translatex_supported_langs_with_names() as $entry) {
            if (!empty($entry['language'])) {
                $codes[] = $entry['language'];
            }
        }
    }
    return $codes;
}
/** Normaliza para um dos códigos acima; mantém exatamente a forma do JSON. */
function translatex_normalize_lang($langRaw) {
    if (!is_string($langRaw) || $langRaw === '') return $langRaw;
    $l = strtolower(trim($langRaw));
    $map = array(
        'pt-br'=>'pt','pt_br'=>'pt','pt-pt'=>'pt','ptbr'=>'pt',
        'zh'=>'zh-CN','cn'=>'zh-CN','zh_cn'=>'zh-CN','zh-hans'=>'zh-CN','zhcn'=>'zh-CN',
        'zh-hant'=>'zh-TW','tw'=>'zh-TW','zh_tw'=>'zh-TW','zhtw'=>'zh-TW',
        'kr'=>'ko','korean'=>'ko','jp'=>'ja','japanese'=>'ja',
        'he'=>'iw','hebrew'=>'iw',
        'es-419'=>'es','es_es'=>'es','es-mx'=>'es','spanish'=>'es',
        'en-us'=>'en','en-gb'=>'en','english'=>'en',
        'nb'=>'no','nn'=>'no','norwegian'=>'no',
        'fr'=>'fr','french'=>'fr','français'=>'fr',
        'de'=>'de','german'=>'de','deutsch'=>'de',
        'it'=>'it','italian'=>'it','italiano'=>'it',
        'ru'=>'ru','russian'=>'ru','русский'=>'ru',
        'ar'=>'ar','arabic'=>'ar','العربية'=>'ar',
        'hi'=>'hi','hindi'=>'hi','हिन्दी'=>'hi'
    );
    if (isset($map[$l])) $normalized = $map[$l];
    else if (preg_match('~^([a-z]{2})(?:[-_]?([a-z]{2}))$~i', $l, $m)) $normalized = strtolower($m[1]).'-'.strtoupper($m[2]);
    else $normalized = $l;

    $supported = translatex_supported_langs();
    if (in_array($normalized, $supported, true)) return $normalized;
    if (strlen($normalized)===2 && in_array($normalized, $supported, true)) return $normalized;

    // convergência simples zh-*
    if (in_array(strtolower($normalized), array('zh-cn','zh-tw'), true)) {
        return (stripos($normalized,'tw')!==false) ? 'zh-TW' : 'zh-CN';
    }
    if (isset($map[$l])) return $map[$l];
    return $normalized;
}

/**
 * Detecta o idioma padrão do WordPress e retorna o código normalizado
 * 
 * @return string Código do idioma padrão normalizado (ex: 'es', 'en', 'pt', 'fr')
 */
function translatex_get_default_language() {
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    
    // Obtém o locale do WordPress (ex: 'es_ES', 'en_US', 'pt_BR')
    $locale = get_locale();
    
    if (empty($locale)) {
        // Fallback para 'en' se não conseguir detectar
        $cached = 'en';
        return $cached;
    }
    
    // Extrai o código de idioma (primeiras 2 letras antes do underscore)
    // Ex: 'es_ES' -> 'es', 'en_US' -> 'en', 'pt_BR' -> 'pt'
    $lang_code = strtolower(substr($locale, 0, 2));
    
    // Normaliza usando a função existente
    $normalized = translatex_normalize_lang($lang_code);
    
    // Valida se está na lista de idiomas suportados
    $supported = translatex_supported_langs();
    if (!in_array($normalized, $supported, true)) {
        // Se não for suportado, usa 'en' como fallback seguro
        $cached = 'en';
        return $cached;
    }
    
    $cached = $normalized;
    return $cached;
}

/* ============================================================
 * UTILITÁRIOS DE LOG E PLACEHOLDERS
 * ============================================================ */
function translatex_log($message) {
    if (!TRANSLATEX_DEBUG) {
        return;
    }
    $message = is_scalar($message) ? $message : wp_json_encode($message);
    error_log('[TranslateX] ' . $message); // phpcs:ignore
}

function translatex_prepare_html_for_translation($html) {
    $placeholders = array();
    $counter = 0;
    $patterns = array(
        '#<script\b[^>]*>.*?</script>#is',
        '#<style\b[^>]*>.*?</style>#is',
        '#<noscript\b[^>]*>.*?</noscript>#is',
        '#<iframe\b[^>]*>.*?</iframe>#is',
        '#<template\b[^>]*>.*?</template>#is',
    );

    $clean = $html;
    foreach ($patterns as $pattern) {
        $clean = preg_replace_callback(
            $pattern,
            function ($matches) use (&$placeholders, &$counter) {
                $token = sprintf('<!--TRANSLATEX_BLOCK_%d-->', ++$counter);
                $placeholders[$token] = $matches[0];
                return $token;
            },
            $clean
        );
    }

    return array($clean, $placeholders);
}

function translatex_restore_placeholders($html, $placeholders) {
    if (empty($placeholders)) {
        return $html;
    }
    return strtr($html, $placeholders);
}

function translatex_build_chunk_payload($html) {
    $build_start = microtime(true);
    if (!is_string($html) || $html === '') {
        return null;
    }
    if (!class_exists('DOMDocument')) {
        return null;
    }

    libxml_use_internal_errors(true);
    $doc = new DOMDocument('1.0', 'UTF-8');

    $flags = LIBXML_HTML_NODEFDTD | LIBXML_HTML_NOIMPLIED;
    $encoded_html = $html;
    if (function_exists('mb_encode_numericentity')) {
        $encoded_html = mb_encode_numericentity($encoded_html, array(0x80, 0x10FFFF, 0, ~0), 'UTF-8');
    } elseif (function_exists('mb_convert_encoding')) {
        $encoded_html = mb_convert_encoding($encoded_html, 'HTML-ENTITIES', 'UTF-8');
    }

    $loaded = $doc->loadHTML('<?xml encoding="UTF-8" ?>' . $encoded_html, $flags);
    libxml_clear_errors();

    if (!$loaded) {
        return null;
    }

    $chunks = array();
    $counter = 0;
    $skip_tags = array('script','style','noscript','iframe','template','textarea','code','pre','kbd','samp','svg','math');
    $translatable_attributes = array('title','alt','placeholder','aria-label','aria-placeholder');

    $stats = array(
        'chunk_count'  => 0,
        'text_chunks'  => 0,
        'attr_chunks'  => 0,
        'total_chars'  => 0,
        'max_chars'    => 0,
    );

    $walker = function($node) use (&$walker, &$chunks, &$counter, $skip_tags, $translatable_attributes, &$stats) {
        if ($node->nodeType === XML_ELEMENT_NODE) {
            $tag = strtolower($node->nodeName);
            if (in_array($tag, $skip_tags, true)) {
                return;
            }

            foreach ($translatable_attributes as $attr) {
                if ($node->hasAttribute($attr)) {
                    $value = $node->getAttribute($attr);
                    if ($value !== '' && preg_match('/\S/u', $value)) {
                        $token = sprintf('@@TRANSLATEX_CHUNK_%05d@@', ++$counter);
                        $chunks[] = array(
                            'token' => $token,
                            'text'  => $value,
                            'kind'  => 'attr'
                        );
                        $length = function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
                        $stats['chunk_count']++;
                        $stats['attr_chunks']++;
                        $stats['total_chars'] += $length;
                        if ($length > $stats['max_chars']) {
                            $stats['max_chars'] = $length;
                        }
                        $node->setAttribute($attr, $token);
                    }
                }
            }

            if ($node->hasChildNodes()) {
                foreach (iterator_to_array($node->childNodes) as $child) {
                    $walker($child);
                }
            }
            return;
        }

        if ($node->nodeType === XML_TEXT_NODE || $node->nodeType === XML_CDATA_SECTION_NODE) {
            $text = $node->nodeValue;
            if (!preg_match('/\S/u', $text)) {
                return;
            }

            $token = sprintf('@@TRANSLATEX_CHUNK_%05d@@', ++$counter);
            if (preg_match('/^(\s*)(.*?)(\s*)$/us', $text, $parts)) {
                $prefix = $parts[1];
                $core   = $parts[2];
                $suffix = $parts[3];
            } else {
                $prefix = '';
                $core   = $text;
                $suffix = '';
            }

            $chunks[] = array(
                'token' => $token,
                'text'  => $core,
                'kind'  => 'text'
            );
            $length = function_exists('mb_strlen') ? mb_strlen($core) : strlen($core);
            $stats['chunk_count']++;
            $stats['text_chunks']++;
            $stats['total_chars'] += $length;
            if ($length > $stats['max_chars']) {
                $stats['max_chars'] = $length;
            }

            $node->nodeValue = $prefix . $token . $suffix;
        }
    };

    foreach (iterator_to_array($doc->childNodes) as $child) {
        $walker($child);
    }

    if (empty($chunks)) {
        return null;
    }

    $tokenized_html = $doc->saveHTML();
    if (!is_string($tokenized_html)) {
        return null;
    }
    $tokenized_html = preg_replace('/^<\?xml.+?\?>/i', '', $tokenized_html);

    $texts = array();
    $unique_lookup = array();
    foreach ($chunks as $index => $chunk) {
        $text = $chunk['text'];
        $hash = md5($text);
        if (!isset($unique_lookup[$hash])) {
            $unique_lookup[$hash] = array(
                'index' => count($texts),
                'text'  => $text,
            );
            $texts[] = $text;
        } else {
            // Collision guard: ensure identical string, otherwise force unique entry.
            if ($unique_lookup[$hash]['text'] !== $text) {
                $unique_lookup[$hash = md5($text . '|' . $index)] = array(
                    'index' => count($texts),
                    'text'  => $text,
                );
                $texts[] = $text;
            }
        }

        $chunks[$index]['text_index'] = $unique_lookup[$hash]['index'];
        $chunks[$index]['text_hash']  = $hash;
    }
    unset($chunk);

    $stats['build_ms'] = round((microtime(true) - $build_start) * 1000, 2);
    $stats['tokenized_bytes'] = strlen($tokenized_html);
    $stats['unique_texts'] = count($texts);
    $stats['deduped'] = max(0, $stats['chunk_count'] - $stats['unique_texts']);

    if (function_exists('translatex_log')) {
        translatex_log('Chunk payload stats: ' . wp_json_encode($stats));
    }

    return array(
        'tokenized_html' => $tokenized_html,
        'chunks'         => $chunks,
        'texts'          => $texts,
        'stats'          => $stats,
    );
}

function translatex_apply_chunk_translations($chunk_payload, $translated_texts) {
    if (empty($chunk_payload) || !is_array($chunk_payload)) {
        return false;
    }
    if (empty($chunk_payload['chunks']) || empty($chunk_payload['tokenized_html'])) {
        return false;
    }
    if (!is_array($translated_texts)) {
        return false;
    }
    $expected = isset($chunk_payload['stats']['unique_texts'])
        ? (int) $chunk_payload['stats']['unique_texts']
        : count($chunk_payload['texts']);
    if ($expected !== count($translated_texts)) {
        return false;
    }

    $prepared_translations = array();
    $replacements = array();

    foreach ($chunk_payload['chunks'] as $chunk) {
        $text_index = isset($chunk['text_index']) ? (int) $chunk['text_index'] : null;
        if ($text_index === null || !array_key_exists($text_index, $translated_texts)) {
            return false;
        }

        if (!isset($prepared_translations[$text_index])) {
            $raw = $translated_texts[$text_index];
            if (!is_string($raw)) {
                $raw = '';
            }
            $prepared_translations[$text_index] = array(
                'text' => esc_html($raw),
                'attr' => esc_attr($raw),
            );
        }

        $replacements[$chunk['token']] = ($chunk['kind'] === 'attr')
            ? $prepared_translations[$text_index]['attr']
            : $prepared_translations[$text_index]['text'];
    }

    return strtr($chunk_payload['tokenized_html'], $replacements);
}

function translatex_failure_transient_key($cache_key) {
    return 'translatex_fail_' . $cache_key;
}

function translatex_should_skip_due_to_recent_failure($cache_key) {
    $failure = get_transient(translatex_failure_transient_key($cache_key));
    if ($failure === false) {
        return false;
    }
    return true;
}

function translatex_register_failure($cache_key, $status, $response_snippet = '') {
    $ttl = translatex_failure_ttl($status);
    $data = array(
        'status' => $status,
        'time'   => time(),
        'body'   => $response_snippet,
        'ttl'    => $ttl,
    );
    set_transient(
        translatex_failure_transient_key($cache_key),
        $data,
        $ttl
    );
    translatex_log('Falha registrada: ' . wp_json_encode($data));
}

function translatex_clear_failure($cache_key) {
    delete_transient(translatex_failure_transient_key($cache_key));
}

function translatex_failure_ttl($status) {
    $ttl = 5 * MINUTE_IN_SECONDS;

    if (is_numeric($status)) {
        $code = (int) $status;
        if ($code === 413) {
            $ttl = 10 * MINUTE_IN_SECONDS;
        } elseif ($code === 429) {
            $ttl = 6 * MINUTE_IN_SECONDS;
        } elseif ($code >= 500) {
            $ttl = 2 * MINUTE_IN_SECONDS;
        } elseif ($code >= 400) {
            $ttl = 4 * MINUTE_IN_SECONDS;
        }
    } else {
        switch ((string) $status) {
            case 'ERROR':
                $ttl = 2 * MINUTE_IN_SECONDS;
                break;
            case 'CHUNK_BATCH_MISMATCH':
            case 'CHUNK_REASSEMBLY_ERROR':
            case 'CHUNK_REASSEMBLY_MISSING':
                $ttl = 3 * MINUTE_IN_SECONDS;
                break;
        }
    }

    return (int) apply_filters('translatex_failure_ttl', $ttl, $status);
}

/* ============================================================
 * CONSTANTES / COOKIE
 * ============================================================ */
// Constantes já definidas acima
if ( ! defined( 'TRANSLATEX_LANG_COOKIE_TTL' ) ) {
    define('TRANSLATEX_LANG_COOKIE_TTL', 60*60*24*30); // 30 dias
}

/* ============================================================
 * REWRITE RULES: /{lang}/... (todas as línguas suportadas por 2 letras; zh-CN/zh-TW tratadas por queryvar)
 * ============================================================ */
function translatex_add_rewrite_rules() {
    // /en  ou /en/
    add_rewrite_rule('^([a-z]{2})/?$', 'index.php?translatex_lang=$matches[1]', 'top');
    // /en/qualquer/coisa
    add_rewrite_rule('^([a-z]{2})/(.+)/?$', 'index.php?translatex_lang=$matches[1]&translatex_path=$matches[2]', 'top');
    // zh-CN e zh-TW (dois passos porque têm hífen e maiúsculas)
    add_rewrite_rule('^(zh\-CN)/?$', 'index.php?translatex_lang=$matches[1]', 'top');
    add_rewrite_rule('^(zh\-TW)/?$', 'index.php?translatex_lang=$matches[1]', 'top');
    add_rewrite_rule('^(zh\-CN)/(.+)/?$', 'index.php?translatex_lang=$matches[1]&translatex_path=$matches[2]', 'top');
    add_rewrite_rule('^(zh\-TW)/(.+)/?$', 'index.php?translatex_lang=$matches[1]&translatex_path=$matches[2]', 'top');
}
add_action('init', 'translatex_add_rewrite_rules');

// Força flush das rewrite rules na ativação do plugin
register_activation_hook(__FILE__, function() {
    translatex_add_rewrite_rules();
    TranslateX_Cache::install_table();
    TranslateX_Strings::install_table();
    update_option('translatex_cache_db_version', TRANSLATEX_CACHE_DB_VERSION);
    translatex_schedule_cache_events(true);
    flush_rewrite_rules();
});

// Flush das rewrite rules na desativação
register_deactivation_hook(__FILE__, function() {
    translatex_clear_scheduled_events();
    flush_rewrite_rules();
});

// Força flush das rewrite rules se necessário
add_action('init', function() {
    if (defined('WP_DEBUG') && WP_DEBUG && isset($_GET['flush_rules'])) {
        translatex_add_rewrite_rules();
        flush_rewrite_rules();
    }
}, 999);

function translatex_register_query_vars($vars) {
    $vars[] = 'translatex_lang';
    $vars[] = 'translatex_path';
    return $vars;
}
add_filter('query_vars', 'translatex_register_query_vars');

add_action('plugins_loaded', 'translatex_maybe_upgrade_cache_table');
function translatex_maybe_upgrade_cache_table() {
    $stored_version = (int) get_option('translatex_cache_db_version', 0);
    if ($stored_version < TRANSLATEX_CACHE_DB_VERSION) {
        TranslateX_Cache::install_table();
        TranslateX_Strings::install_table();
        update_option('translatex_cache_db_version', TRANSLATEX_CACHE_DB_VERSION);
        translatex_purge_legacy_option_cache();
    }
}

function translatex_purge_legacy_option_cache() {
    global $wpdb;
    $pattern = $wpdb->esc_like('translatex_cache_') . '%';
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
            $pattern
        )
    );
}

/* ============================================================
 * LER LÍNGUA DA URL/QUERY E DEFINIR COOKIE
 * ============================================================ */
function translatex_set_language_from_url() {
    $default_lang = translatex_get_default_language();
    
    // 1) query ?language=
    if (!empty($_GET['language'])) {
        $lang = translatex_normalize_lang($_GET['language']);
        if ($lang === $default_lang) {
            // idioma base -> remove cookie
            if (!headers_sent()) {
                @setcookie(TRANSLATEX_LANG_COOKIE, '', time()-3600, '/', defined('COOKIE_DOMAIN') ? COOKIE_DOMAIN : '');
            }
            unset($_COOKIE[TRANSLATEX_LANG_COOKIE]);
        } else {
            if (!headers_sent()) {
                @setcookie(TRANSLATEX_LANG_COOKIE, $lang, time()+TRANSLATEX_LANG_COOKIE_TTL, '/', defined('COOKIE_DOMAIN') ? COOKIE_DOMAIN : '');
            }
            $_COOKIE[TRANSLATEX_LANG_COOKIE] = $lang;
        }
        // não redirecionamos aqui; a lógica de redirect global cuida abaixo
    }

    // 2) prefixo /{lang}
    $langSeg = get_query_var('translatex_lang');
    if ($langSeg) {
        $lang = translatex_normalize_lang($langSeg);
        // Se alguém usar o idioma padrão na URL, normalizamos para base (sem prefixo) via redirect mais adiante
        if ($lang !== $default_lang) {
            if (!headers_sent()) {
                @setcookie(TRANSLATEX_LANG_COOKIE, $lang, time()+TRANSLATEX_LANG_COOKIE_TTL, '/', defined('COOKIE_DOMAIN') ? COOKIE_DOMAIN : '');
            }
            $_COOKIE[TRANSLATEX_LANG_COOKIE] = $lang;
        } else {
            if (!headers_sent()) {
                @setcookie(TRANSLATEX_LANG_COOKIE, '', time()-3600, '/', defined('COOKIE_DOMAIN') ? COOKIE_DOMAIN : '');
            }
            unset($_COOKIE[TRANSLATEX_LANG_COOKIE]);
        }
        $_GET['language'] = $lang; // compatibilidade com o restante do plugin
    }
}
add_action('parse_request', 'translatex_set_language_from_url');

/* ============================================================
 * REDIRECIONAMENTO DE CANONICALIZAÇÃO DE LÍNGUA
 * - Se cookie != idioma padrão e URL não tem prefixo, redireciona para /{lang}{PATH}
 * - Se URL tem prefixo do idioma padrão, remove prefixo (redireciona p/ base)
 * - Não aplica em admin, login, feeds, REST, cron, sitemap/robots, 404
 * ============================================================ */
function translatex_should_skip_redirect() {
    if (is_admin()) return true;
    if (defined('REST_REQUEST') && REST_REQUEST) return true;
    if (defined('WP_CLI') && WP_CLI) return true;
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    if (strpos($uri, '/wp-login.php') !== false) return true;
    if (strpos($uri, '/wp-json/') === 0) return true;
    if (strpos($uri, '/robots.txt') === 0) return true;
    if (strpos($uri, '/sitemap') === 0) return true;
    if (is_feed()) return true;
    return false;
}

add_action('template_redirect', function () {
    if (translatex_should_skip_redirect()) return;

    $default_lang = translatex_get_default_language();
    $supported = translatex_supported_langs();
    $cookieLang = !empty($_COOKIE[TRANSLATEX_LANG_COOKIE]) ? translatex_normalize_lang($_COOKIE[TRANSLATEX_LANG_COOKIE]) : null;
    $cookieLang = $cookieLang && in_array($cookieLang, $supported, true) ? $cookieLang : null;

    $reqUri = $_SERVER['REQUEST_URI'] ?? '/';
    $parsed = wp_parse_url($reqUri);
    $path   = $parsed['path'] ?? '/';
    $qs     = isset($parsed['query']) ? ('?'.$parsed['query']) : '';
    $path   = $path ?: '/';

    // Detecta prefixo atual
    $currentLang = null;
    $pathTrim = ltrim($path,'/');
    if ($pathTrim !== '') {
        $firstSeg = strtok($pathTrim, '/');
        // zh-CN/zh-TW tem hífen e maiúsculas — preserve
        if ($firstSeg === 'zh-CN' || $firstSeg === 'zh-TW') $currentLang = $firstSeg;
        else if (preg_match('~^[a-z]{2}$~', $firstSeg)) $currentLang = $firstSeg;
        $currentLang = $currentLang ? translatex_normalize_lang($currentLang) : null;
    }

    // Caso 1: URL com prefixo do idioma padrão → remover prefixo
    if ($currentLang === $default_lang) {
        $newPath = substr($path, strlen('/'.$firstSeg));
        if ($newPath === false || $newPath === '') $newPath = '/';
        if ($newPath[0] !== '/') $newPath = '/'.$newPath;
        $location = $newPath . $qs;
        wp_redirect($location, 301);
        exit;
    }

    // Caso 2: Cookie lang ≠ idioma padrão e URL sem prefixo → adicionar prefixo
    if ($cookieLang && $cookieLang !== $default_lang && !$currentLang) {
        $newPath = '/'.$cookieLang . $path;
        $location = $newPath . $qs;
        wp_redirect($location, 302);
        exit;
    }

    // Caso 3: Cookie lang ≠ currentLang (e ambos ≠ idioma padrão) → alinhar à do cookie
    if ($cookieLang && $cookieLang !== $default_lang && $currentLang && $cookieLang !== $currentLang) {
        // substitui primeiro segmento pelo cookieLang
        $rest = substr($path, strlen('/'.$firstSeg));
        if ($rest === false) $rest = '';
        if ($rest === '' || $rest[0] !== '/') $rest = '/'.$rest;
        $location = '/'.$cookieLang . $rest . $qs;
        wp_redirect($location, 302);
        exit;
    }

    // Caso 4: Cookie = idioma padrão e URL com qualquer prefixo (exceto zh-CN/zh-TW) → remover prefixo
    if ($cookieLang === $default_lang && $currentLang) {
        $rest = substr($path, strlen('/'.$firstSeg));
        if ($rest === false || $rest === '') $rest = '/';
        if ($rest[0] !== '/') $rest = '/'.$rest;
        $location = $rest . $qs;
        wp_redirect($location, 302);
        exit;
    }
});

/* ============================================================
 * RESOLVER DE REQUISIÇÕES (home, posts, páginas, taxonomias, autor, data, busca, CPTs, paginação)
 * (como nas versões anteriores) + correção segura para páginas fixas
 * ============================================================ */
add_filter('request', function($qv){
    $lang = isset($qv['translatex_lang']) ? translatex_normalize_lang($qv['translatex_lang']) : null;
    
    // /{lang} → Home
    if (!empty($lang) && empty($qv['translatex_path'])) {
        if (get_option('show_on_front') === 'page') {
            $front_id = (int) get_option('page_on_front');
            if ($front_id > 0) {
                foreach (array('pagename','name','page_id','p','category_name','tag','taxonomy','term','author_name','year','monthnum','day','s','post_type','paged') as $k) unset($qv[$k]);
                $qv['page_id'] = $front_id;
                return $qv;
            }
        }
        return $qv;
    }

    if (!empty($lang) && !empty($qv['translatex_path'])) {
        global $wp_rewrite;
        $rest = trim($qv['translatex_path'], '/');

        $paged = null;
        $page_base = !empty($wp_rewrite->pagination_base) ? $wp_rewrite->pagination_base : 'page';
        if (preg_match('#/(?:' . preg_quote($page_base, '#') . ')/([0-9]{1,3})/?$#i', $rest, $m)) {
            $paged = (int) $m[1];
            $rest  = preg_replace('#/(?:' . preg_quote($page_base, '#') . ')/([0-9]{1,3})/?$#i', '', $rest);
            $rest  = trim($rest, '/');
        }

        /* =======================
         * ✅ NOVO BLOCO SEGURO: priorizar páginas fixas (ex.: /quien-somos)
         *    Somente se o path não iniciar com bases reservadas (categoria, tag, autor, busca),
         *    não parecer data e não for "ID/slug".
         * ======================= */
        $cat_base   = get_option('category_base') ?: 'category';
        $tag_base   = get_option('tag_base') ?: 'tag';
        $author_base = !empty($wp_rewrite->author_base) ? trim($wp_rewrite->author_base, '/') : 'author';
        $search_base = !empty($wp_rewrite->search_base) ? trim($wp_rewrite->search_base, '/') : 'search';

        $starts_with_reserved = (
            $rest === $cat_base || strpos($rest, $cat_base.'/') === 0 ||
            $rest === $tag_base || strpos($rest, $tag_base.'/') === 0 ||
            $rest === $author_base || strpos($rest, $author_base.'/') === 0 ||
            $rest === $search_base || strpos($rest, $search_base.'/') === 0
        );

        $looks_like_date = (bool) preg_match('#^(\d{4})(?:/(\d{1,2})(?:/(\d{1,2}))?)?(?:/|$)#', $rest);
        $looks_like_id_slug = (bool) preg_match('#^\d+/.+#', $rest);

        if (!$starts_with_reserved && !$looks_like_date && !$looks_like_id_slug) {
            $page_early = get_page_by_path($rest);
            if ($page_early) {
                foreach (array('translatex_path','pagename','name','page_id','p','category_name','tag','taxonomy','term','author_name','year','monthnum','day','s','post_type') as $k) unset($qv[$k]);
                $qv['page_id'] = $page_early->ID;
                if ($paged) $qv['paged'] = $paged;
                return $qv;
            }
        }
        /* ====== FIM NOVO BLOCO ====== */

        $parts = explode('/', $rest);
        if (count($parts) >= 2 && ctype_digit($parts[0])) {
            $maybe_id = (int) $parts[0];
            $post = get_post($maybe_id);
            if ($post && $post->post_status === 'publish') {
                foreach (array('translatex_path','pagename','name','page_id','p','category_name','tag','taxonomy','term','author_name','year','monthnum','day','s','post_type') as $k) unset($qv[$k]);
                $qv['p'] = $maybe_id;
                if ($paged) $qv['paged'] = $paged;
                return $qv;
            }
        }

        // Bases já calculadas acima ($cat_base, $tag_base)
        if ($rest === $cat_base || strpos($rest, $cat_base . '/') === 0) {
            $slug_path = trim(substr($rest, strlen($cat_base)), '/');
            if ($slug_path !== '') {
                foreach (array('translatex_path','pagename','name','page_id','p','tag','taxonomy','term','author_name','year','monthnum','day','s','post_type') as $k) unset($qv[$k]);
                $qv['category_name'] = $slug_path;
                if ($paged) $qv['paged'] = $paged;
                return $qv;
            }
        }

        if ($rest === $tag_base || strpos($rest, $tag_base . '/') === 0) {
            $slug_path = trim(substr($rest, strlen($tag_base)), '/');
            if ($slug_path !== '') {
                foreach (array('translatex_path','pagename','name','page_id','p','category_name','taxonomy','term','author_name','year','monthnum','day','s','post_type') as $k) unset($qv[$k]);
                $qv['tag'] = $slug_path;
                if ($paged) $qv['paged'] = $paged;
                return $qv;
            }
        }

        $tax_objs = get_taxonomies(array('public' => true), 'objects');
        if (!empty($tax_objs) && is_array($tax_objs)) {
            foreach ($tax_objs as $tax) {
                if (in_array($tax->name, array('category','post_tag'), true)) continue;
                $slug  = (!empty($tax->rewrite) && !empty($tax->rewrite['slug'])) ? trim($tax->rewrite['slug'], '/') : '';
                $qvar  = !empty($tax->query_var) ? $tax->query_var : $tax->name;
                if ($slug && ($rest === $slug || strpos($rest, $slug . '/') === 0)) {
                    $term_path = trim(substr($rest, strlen($slug)), '/');
                    if ($term_path !== '') {
                        foreach (array('translatex_path','pagename','name','page_id','p','category_name','tag','author_name','year','monthnum','day','s','post_type') as $k) unset($qv[$k]);
                        $qv[$qvar] = $term_path;
                        if ($paged) $qv['paged'] = $paged;
                        return $qv;
                    }
                }
            }
        }

        // $author_base e $search_base já definidos acima
        if ($rest === $author_base || strpos($rest, $author_base . '/') === 0) {
            $nicename = trim(substr($rest, strlen($author_base)), '/');
            if ($nicename !== '') {
                foreach (array('translatex_path','pagename','name','page_id','p','category_name','tag','taxonomy','term','year','monthnum','day','s','post_type') as $k) unset($qv[$k]);
                $qv['author_name'] = $nicename;
                if ($paged) $qv['paged'] = $paged;
                return $qv;
            }
        }

        if (preg_match('#^(\d{4})(?:/(\d{1,2})(?:/(\d{1,2}))?)?$#', $rest, $m)) {
            foreach (array('translatex_path','pagename','name','page_id','p','category_name','tag','taxonomy','term','author_name','s','post_type') as $k) unset($qv[$k]);
            $qv['year'] = (int)$m[1];
            if (!empty($m[2])) $qv['monthnum'] = (int)$m[2];
            if (!empty($m[3])) $qv['day']      = (int)$m[3];
            if ($paged) $qv['paged'] = $paged;
            return $qv;
        }

        if ($rest === $search_base || strpos($rest, $search_base . '/') === 0) {
            $term = trim(substr($rest, strlen($search_base)), '/');
            if ($term !== '') {
                foreach (array('translatex_path','pagename','name','page_id','p','category_name','tag','taxonomy','term','author_name','year','monthnum','day','post_type') as $k) unset($qv[$k]);
                $qv['s'] = $term;
                if ($paged) $qv['paged'] = $paged;
                return $qv;
            }
        }

        $pt_objs = get_post_types(array('public' => true), 'objects');
        if (!empty($pt_objs) && is_array($pt_objs)) {
            foreach ($pt_objs as $pt) {
                if (in_array($pt->name, array('post','page','attachment'), true)) continue;
                if (!empty($pt->has_archive)) {
                    $slug = (!empty($pt->rewrite) && !empty($pt->rewrite['slug'])) ? trim($pt->rewrite['slug'], '/') : $pt->name;
                    if ($rest === $slug) {
                        foreach (array('translatex_path','pagename','name','page_id','p','category_name','tag','taxonomy','term','author_name','year','monthnum','day','s') as $k) unset($qv[$k]);
                        $qv['post_type'] = $pt->name;
                        if ($paged) $qv['paged'] = $paged;
                        return $qv;
                    }
                }
            }
        }

        $try_url = home_url('/' . $rest . '/');
        $post_id = url_to_postid($try_url);
        if ($post_id) {
            foreach (array('translatex_path','pagename','name','page_id','p') as $k) unset($qv[$k]);
            $qv['p'] = $post_id;
            if ($paged) $qv['paged'] = $paged;
            return $qv;
        }

        // (Mantido) Resolução de página por path, caso não tenha sido capturada no bloco "early"
        $page = get_page_by_path($rest);
        if ($page) {
            foreach (array('translatex_path','pagename','name','page_id','p') as $k) unset($qv[$k]);
            $qv['page_id'] = $page->ID;
            if ($paged) $qv['paged'] = $paged;
            return $qv;
        }
        
        // Fallback: tenta resolver como pagename
        if (!empty($rest)) {
            foreach (array('translatex_path','name','page_id','p') as $k) unset($qv[$k]);
            $qv['pagename'] = $rest;
            if ($paged) $qv['paged'] = $paged;
            return $qv;
        }

        if ($paged) $qv['paged'] = $paged;
        return $qv;
    }

    return $qv;
});

/* ============================================================
 * DESABILITA redirect canônico do WP em rotas com idioma
 * ============================================================ */
add_filter('redirect_canonical', function($redirect_url, $requested_url = null){
    $has_lang = get_query_var('translatex_lang') || (!empty($_GET['language']));
    if ($has_lang) {
        return false;
    }
    return $redirect_url;
}, 10, 2);

/* ============================================================
 * BUFFER: intercepta HTML e traduz (com cache por URL+idioma)
 * ============================================================ */
add_action('template_redirect', function () {
    if (translatex_should_skip_buffer()) {
        return;
    }

    $lang = translatex_current_language();
    $default_lang = translatex_get_default_language();
    if (empty($lang) || $lang === $default_lang) {
        return;
    }

    $_GET['language'] = $lang;
    ob_start('translatex_buffer_callback');
});

function translatex_should_skip_buffer() {
    if (is_admin()) return true;
    if (defined('REST_REQUEST') && REST_REQUEST) return true;
    if (defined('WP_CLI') && WP_CLI) return true;
    if (is_feed()) return true;
    if (wp_doing_ajax()) return true;

    $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper($_SERVER['REQUEST_METHOD']) : 'GET';
    if ($method !== 'GET') return true;

    $uri = $_SERVER['REQUEST_URI'] ?? '';
    if (strpos($uri, '/wp-login.php') !== false) return true;
    if (strpos($uri, '/wp-json/') === 0) return true;

    return false;
}

function translatex_current_language() {
    if (!empty($_GET['language'])) {
        return translatex_normalize_lang($_GET['language']);
    }

    $q_lang = get_query_var('translatex_lang');
    if (!empty($q_lang)) {
        return translatex_normalize_lang($q_lang);
    }

    if (!empty($_COOKIE[TRANSLATEX_LANG_COOKIE])) {
        return translatex_normalize_lang($_COOKIE[TRANSLATEX_LANG_COOKIE]);
    }

    return null;
}

function translatex_buffer_callback($html) {
    $lang = translatex_current_language();
    $default_lang = translatex_get_default_language();
    if (empty($lang) || $lang === $default_lang) {
        return $html;
    }
    $supported = translatex_supported_langs();
    if (!in_array($lang, $supported, true)) {
        return $html;
    }

    $_GET['language'] = $lang;

    $url = TranslateX_Cache::get_current_request_url();
    $cache_key = TranslateX_Cache::cache_key($url, $lang);
    $force_refresh = (!empty($_GET['nocache']) && $_GET['nocache'] == '1');
    $source_hash = TranslateX_Cache::compute_source_hash($html);

    if (!$force_refresh && translatex_should_skip_due_to_recent_failure($cache_key)) {
        translatex_log('Ignorando tradução (falha recente) para ' . $url . ' [' . $lang . ']');
        return $html;
    }

    $entry = TranslateX_Cache::get($url, $lang);
    $has_matching_fingerprint = TranslateX_Cache::matches_source_hash($entry, $source_hash);

    if ($entry && !$force_refresh && $has_matching_fingerprint) {
        return $entry['content'];
    }

    if ($entry && !$force_refresh && !$has_matching_fingerprint) {
        $force_refresh = true;
    }

    $stale_content = ($entry && $has_matching_fingerprint) ? $entry['content'] : null;

    list($prepared_html, $placeholders) = translatex_prepare_html_for_translation($html);
    $chunk_payload = translatex_build_chunk_payload($prepared_html);
    if ($chunk_payload && function_exists('translatex_log')) {
        $stats = isset($chunk_payload['stats']) ? $chunk_payload['stats'] : null;
        translatex_log(sprintf(
            'TranslateX: %d blocos preparados para %s [%s]%s',
            count($chunk_payload['texts']),
            $url,
            $lang,
            $stats ? ' | stats=' . wp_json_encode($stats) : ''
        ));
    }

    $translator = new TranslateX_Client();
    $translated = false;

    if ( $chunk_payload && ! empty( $chunk_payload['texts'] ) ) {
        $hashes_by_index = array();

        if ( ! empty( $chunk_payload['chunks'] ) && is_array( $chunk_payload['chunks'] ) ) {
            foreach ( $chunk_payload['chunks'] as $chunk ) {
                if ( ! isset( $chunk['text_index'] ) ) {
                    continue;
                }
                $idx = (int) $chunk['text_index'];
                if ( ! isset( $hashes_by_index[ $idx ] ) && ! empty( $chunk['text_hash'] ) ) {
                    $hashes_by_index[ $idx ] = $chunk['text_hash'];
                }
            }
        }

        foreach ( $chunk_payload['texts'] as $idx => $text ) {
            if ( ! isset( $hashes_by_index[ $idx ] ) ) {
                $hashes_by_index[ $idx ] = md5( $text );
            }
        }

        $translations_by_index = array();
        if ( ! empty( $hashes_by_index ) ) {
            $stored = TranslateX_Strings::get_translations( $lang, array_values( $hashes_by_index ) );
            if ( ! empty( $stored ) ) {
                foreach ( $hashes_by_index as $idx => $hash ) {
                    if ( isset( $stored[ $hash ] ) ) {
                        $translations_by_index[ $idx ] = $stored[ $hash ];
                    }
                }
            }
        }

        $missing_indices = array_diff( array_keys( $hashes_by_index ), array_keys( $translations_by_index ) );
        $new_entries     = array();

        if ( ! empty( $missing_indices ) ) {
            $pending_texts = array();
            foreach ( $missing_indices as $idx ) {
                if ( isset( $chunk_payload['texts'][ $idx ] ) ) {
                    $pending_texts[ $idx ] = $chunk_payload['texts'][ $idx ];
                }
            }

            if ( ! empty( $pending_texts ) ) {
                $max_dictionary_batch = (int) apply_filters( 'translatex_dictionary_max_missing_texts', 90 );
                if ( $max_dictionary_batch <= 0 ) {
                    $max_dictionary_batch = 90;
                }
                $max_dictionary_batch = max( 1, $max_dictionary_batch );

                $batch_buffer = array();
                $batch_count  = 0;
                $chunk_failed = false;

                foreach ( $pending_texts as $pending_idx => $pending_text ) {
                    $batch_buffer[ $pending_idx ] = $pending_text;
                    if ( count( $batch_buffer ) >= $max_dictionary_batch ) {
                        $batch_count++;
                        $chunk_result = $translator->translate_texts( $batch_buffer, $lang, $html );
                        if ( ! is_array( $chunk_result ) || count( $chunk_result ) !== count( $batch_buffer ) ) {
                            $chunk_failed = true;
                            break;
                        }
                        foreach ( $chunk_result as $idx => $translated_text ) {
                            $translations_by_index[ $idx ] = $translated_text;
                            if ( isset( $hashes_by_index[ $idx ] ) ) {
                                $new_entries[] = array(
                                    'hash'        => $hashes_by_index[ $idx ],
                                    'original'    => isset( $chunk_payload['texts'][ $idx ] ) ? $chunk_payload['texts'][ $idx ] : '',
                                    'translation' => $translated_text,
                                );
                            }
                        }
                        $batch_buffer = array();
                    }
                }

                if ( ! $chunk_failed && ! empty( $batch_buffer ) ) {
                    $batch_count++;
                    $chunk_result = $translator->translate_texts( $batch_buffer, $lang, $html );
                    if ( ! is_array( $chunk_result ) || count( $chunk_result ) !== count( $batch_buffer ) ) {
                        $chunk_failed = true;
                    } else {
                        foreach ( $chunk_result as $idx => $translated_text ) {
                            $translations_by_index[ $idx ] = $translated_text;
                            if ( isset( $hashes_by_index[ $idx ] ) ) {
                                $new_entries[] = array(
                                    'hash'        => $hashes_by_index[ $idx ],
                                    'original'    => isset( $chunk_payload['texts'][ $idx ] ) ? $chunk_payload['texts'][ $idx ] : '',
                                    'translation' => $translated_text,
                                );
                            }
                        }
                    }
                }

                if ( $chunk_failed ) {
                    if ( function_exists( 'translatex_log' ) ) {
                        translatex_log( sprintf(
                            'TranslateX: falha ao traduzir lote de blocos (lote %d, tamanho %d) para %s [%s], aplicando fallback.',
                            $batch_count,
                            $max_dictionary_batch,
                            $url,
                            $lang
                        ) );
                    }
                    $translated = false;
                }
            }
        }

        if ( ! empty( $new_entries ) ) {
            TranslateX_Strings::save_machine_translations( $lang, $new_entries );
        }

        $ordered_translations = array();
        $complete             = true;
        foreach ( $hashes_by_index as $idx => $_hash ) {
            if ( array_key_exists( $idx, $translations_by_index ) ) {
                $ordered_translations[ $idx ] = $translations_by_index[ $idx ];
            } else {
                $complete = false;
                break;
            }
        }

        if ( $complete && count( $ordered_translations ) === count( $chunk_payload['texts'] ) ) {
            ksort( $ordered_translations );
            $ordered_translations = array_values( $ordered_translations );
            $rebuilt              = translatex_apply_chunk_translations( $chunk_payload, $ordered_translations );
            if ( $rebuilt !== false ) {
                $translated = $rebuilt;
            }
        }
    }

    if ( $translated === false ) {
        TranslateX_Client::$last_status   = null;
        TranslateX_Client::$last_response = null;
        $translated = $translator->translate( $prepared_html, $lang, null );
    }

    if ($translated) {
        $translated = translatex_restore_placeholders($translated, $placeholders);
        TranslateX_Cache::save($url, $lang, $translated, $source_hash);
        translatex_clear_failure($cache_key);
        return $translated;
    }

    translatex_register_failure(
        $cache_key,
        TranslateX_Client::$last_status,
        is_string(TranslateX_Client::$last_response)
            ? substr(TranslateX_Client::$last_response, 0, 400)
            : ''
    );

    if ($stale_content) {
        return $stale_content;
    }

    return $html;
}

function translatex_schedule_cache_events($force = false) {
    $hook = 'translatex_purge_cache';
    $existing = wp_next_scheduled($hook);

    if ($force && $existing) {
        translatex_clear_scheduled_events();
        $existing = false;
    }

    if (!$existing) {
        wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', $hook);
    }
}

function translatex_clear_scheduled_events() {
    $hook = 'translatex_purge_cache';
    $timestamp = wp_next_scheduled($hook);
    while ($timestamp) {
        wp_unschedule_event($timestamp, $hook);
        $timestamp = wp_next_scheduled($hook);
    }
}

add_action('wp', function () {
    translatex_schedule_cache_events();
});

add_action('translatex_purge_cache', function () {
    TranslateX_Cache::purge_without_fingerprint();
});

/* ============================================================
 * LINKS INTERNOS (prefixar com /{lang} quando lang ativo ≠ idioma padrão)
 * ============================================================ */
function translatex_prefix_path($url) {
    if (is_admin()) return $url;
    $default_lang = translatex_get_default_language();
    $active = !empty($_GET['language']) ? translatex_normalize_lang($_GET['language'])
            : (!empty($_COOKIE[TRANSLATEX_LANG_COOKIE]) ? translatex_normalize_lang($_COOKIE[TRANSLATEX_LANG_COOKIE]) : null);
    if (!$active || $active === $default_lang) return $url;

    $parsed = wp_parse_url($url);
    $path   = $parsed['path'] ?? '';

    if (isset($parsed['host']) && $parsed['host'] && $parsed['host'] !== $_SERVER['HTTP_HOST']) return $url; // externo
    if (preg_match('#^/(' . preg_quote($active, '#') . ')(/|$)#', $path)) return $url; // já prefixado

    $new_path = '/' . $active . $path;

    $new_url  = (isset($parsed['scheme']) ? $parsed['scheme'].'://' : '')
              . ($parsed['host'] ?? '')
              . (!empty($parsed['port']) ? ':' . $parsed['port'] : '')
              . $new_path
              . (isset($parsed['query']) ? '?' . $parsed['query'] : '')
              . (isset($parsed['fragment']) ? '#' . $parsed['fragment'] : '');

    return $new_url ?: $url;
}
add_filter('page_link',                'translatex_prefix_path');
add_filter('post_type_link',           'translatex_prefix_path');
add_filter('post_link',                'translatex_prefix_path');
add_filter('home_url',                 'translatex_prefix_path');
add_filter('author_link',              'translatex_prefix_path');
add_filter('day_link',                 'translatex_prefix_path');
add_filter('month_link',               'translatex_prefix_path');
add_filter('year_link',                'translatex_prefix_path');
add_filter('post_type_archive_link',   'translatex_prefix_path');
add_filter('search_link',              'translatex_prefix_path');

function translatex_prefix_term_link($termlink, $term, $taxonomy) {
    if (is_admin()) return $termlink;
    $default_lang = translatex_get_default_language();
    $active = !empty($_GET['language']) ? translatex_normalize_lang($_GET['language'])
            : (!empty($_COOKIE[TRANSLATEX_LANG_COOKIE]) ? translatex_normalize_lang($_COOKIE[TRANSLATEX_LANG_COOKIE]) : null);
    if (!$active || $active === $default_lang) return $termlink;

    $parsed = wp_parse_url($termlink);
    $path   = $parsed['path'] ?? '';
    if (isset($parsed['host']) && $parsed['host'] && $parsed['host'] !== $_SERVER['HTTP_HOST']) return $termlink;
    if (preg_match('#^/(' . preg_quote($active, '#') . ')(/|$)#', $path)) return $termlink;

    $new_path = '/' . $active . $path;
    $new_url  = (isset($parsed['scheme']) ? $parsed['scheme'].'://' : '')
              . ($parsed['host'] ?? '')
              . (!empty($parsed['port']) ? ':' . $parsed['port'] : '')
              . $new_path
              . (isset($parsed['query']) ? '?' . $parsed['query'] : '')
              . (isset($parsed['fragment']) ? '#' . $parsed['fragment'] : '');
    return $new_url ?: $termlink;
}
add_filter('term_link', 'translatex_prefix_term_link', 10, 3);

/* ============================================================
 * FALLBACK JS: prefixa links hardcoded quando lang ativo ≠ idioma padrão
 * ============================================================ */
add_action('wp_footer', function () {
    $default_lang = translatex_get_default_language();
    $active = !empty($_GET['language']) ? translatex_normalize_lang($_GET['language'])
            : (!empty($_COOKIE[TRANSLATEX_LANG_COOKIE]) ? translatex_normalize_lang($_COOKIE[TRANSLATEX_LANG_COOKIE]) : null);
    if (!$active || $active === $default_lang) return;
    $lang = esc_js($active);
    $default_lang_js = esc_js($default_lang);
    ?>
    <script>
    (function(){
      function normalizeLang(l){
        if(!l) return l;
        l=(l+'').trim().toLowerCase();
        const map={'pt-br':'pt','pt_br':'pt','pt-pt':'pt','zh':'zh-CN','cn':'zh-CN','zh_cn':'zh-CN','zh-hans':'zh-CN','zh-hant':'zh-TW','tw':'zh-TW','zh_tw':'zh-TW','kr':'ko','jp':'ja','he':'iw','es-419':'es','es_es':'es','es-mx':'es','en-us':'en','en-gb':'en','nb':'no','nn':'no'};
        if(map[l]) return map[l];
        const m=l.match(/^([a-z]{2})[-_]?([a-z]{2})$/i);
        if(m){
          const norm=m[1].toLowerCase()+'-'+m[2].toUpperCase();
          if(norm==='zh-CN'||norm==='zh-TW') return norm;
        }
        const supported=new Set(['ro','ar','no','iw','vi','ko','bg','cs','hr','th','lt','uk','fi','hi','hu','bn','sk','sl','id','en','fr','es','de','it','nl','ru','pt','ja','tr','pl','sv','da','zh-CN','zh-TW','el']);
        if(supported.has(l)) return l;
        return l;
      }
      var lang=normalizeLang("<?php echo $lang; ?>");
      var defaultLang=normalizeLang("<?php echo $default_lang_js; ?>");
      if(!lang || lang===defaultLang) return;

      document.addEventListener('DOMContentLoaded', function(){
        document.querySelectorAll('a[href]').forEach(function(link){
          var href=link.getAttribute('href'); if(!href) return;
          try{
            var u=new URL(href, location.origin);
            if(u.hostname!==location.hostname) return;
            if(u.pathname.startsWith('/'+lang)) return;
            u.pathname='/'+lang+u.pathname.replace(/^\/+/, '');
            link.setAttribute('href', u.toString());
          }catch(e){}
        });
      });
    })();
    </script>
    <?php
});

/* ============================================================
 * ADMIN: dashboard de cache (estatísticas + ações de limpeza)
 * ============================================================ */
add_action('admin_menu', function () {
    add_options_page(
        'TranslateX Cache',
        'TranslateX Cache',
        'manage_options',
        'translatex-cache',
        'translatex_render_cache_page'
    );
});

function translatex_render_cache_page() {
    if (!current_user_can('manage_options')) {
        return;
    }

    $message_slug = 'translatex_cache';

    if (!empty($_POST['translatex_cache_action'])) {
        check_admin_referer('translatex_cache_action', 'translatex_cache_nonce');
        $action = sanitize_text_field($_POST['translatex_cache_action']);

        switch ($action) {
            case 'clear_all':
                TranslateX_Cache::delete_all();
                add_settings_error($message_slug, 'translatex_cache_cleared', 'Cache limpo (banco + memória).', 'updated');
                break;
            case 'purge_expired':
                $removed = TranslateX_Cache::purge_without_fingerprint();
                if ($removed > 0) {
                    add_settings_error($message_slug, 'translatex_cache_purged', sprintf('%d entradas sem fingerprint foram removidas.', $removed), 'updated');
                } else {
                    add_settings_error($message_slug, 'translatex_cache_purged_none', 'Nenhuma entrada pendente de fingerprint encontrada.', 'info');
                }
                break;
            case 'flush_object':
                TranslateX_Cache::flush_object_cache();
                add_settings_error($message_slug, 'translatex_cache_flushed', 'Cache em memória limpo.', 'updated');
                break;
            case 'delete_entry':
                $lang = !empty($_POST['translatex_lang']) ? translatex_normalize_lang(wp_unslash($_POST['translatex_lang'])) : '';
                $target_url = isset($_POST['translatex_url']) ? trim(wp_unslash($_POST['translatex_url'])) : '';
                
                if (!$lang) {
                    add_settings_error($message_slug, 'translatex_cache_entry_error', 'Informe um idioma.', 'error');
                } elseif ($target_url === '') {
                    // Remover TODAS as entradas do idioma
                    $removed = TranslateX_Cache::delete_by_language($lang);
                    if ($removed > 0) {
                        add_settings_error($message_slug, 'translatex_cache_lang_removed', sprintf('Removidas %d entradas do idioma %s.', $removed, $lang), 'updated');
                    } else {
                        add_settings_error($message_slug, 'translatex_cache_lang_empty', sprintf('Nenhuma entrada encontrada para o idioma %s.', $lang), 'info');
                    }
                } else {
                    // Remover entrada específica (comportamento atual)
                    TranslateX_Cache::delete($target_url, $lang);
                    add_settings_error($message_slug, 'translatex_cache_entry_removed', 'Entrada específica removida.', 'updated');
                }
                break;
        }
    }

    settings_errors($message_slug);

    $stats = TranslateX_Cache::get_stats();
    $now = current_time('timestamp');

    $last_generated_text = 'Nunca gerado';
    if (!empty($stats['last_generated'])) {
        $last_generated_text = sprintf(
            '%s (%s)',
            human_time_diff($stats['last_generated'], $now) . ' atrás',
            wp_date(get_option('date_format') . ' ' . get_option('time_format'), $stats['last_generated'])
        );
    }

    $next_cron_ts = wp_next_scheduled('translatex_purge_cache');
    $next_cron_text = $next_cron_ts
        ? wp_date(get_option('date_format') . ' ' . get_option('time_format'), $next_cron_ts)
        : 'Não agendado';

    $supported_langs = translatex_supported_langs();
    $supported_langs_with_names = translatex_supported_langs_with_names();
    ?>
    <div class="wrap">
        <h1>TranslateX — Cache de Traduções</h1>
        <p>As traduções agora permanecem em cache até que seja detectada uma alteração no conteúdo original. Entradas sem fingerprint são regeneradas durante o próximo acesso.</p>

        <table class="widefat striped" style="max-width: 640px;">
            <tbody>
                <tr>
                    <th scope="row">Itens em cache</th>
                    <td><?php echo number_format_i18n((int) $stats['count']); ?></td>
                </tr>
                <tr>
                    <th scope="row">Última geração</th>
                    <td><?php echo esc_html($last_generated_text); ?></td>
                </tr>
                <tr>
                    <th scope="row">Total de hits registrados</th>
                    <td><?php echo number_format_i18n((int) $stats['total_hits']); ?></td>
                </tr>
                <tr>
                    <th scope="row">Próxima limpeza agendada</th>
                    <td><?php echo esc_html($next_cron_text); ?></td>
                </tr>
            </tbody>
        </table>

        <h2>Ações rápidas</h2>
        <form method="post" style="display:inline-block;margin-right:12px;">
            <?php wp_nonce_field('translatex_cache_action', 'translatex_cache_nonce'); ?>
            <input type="hidden" name="translatex_cache_action" value="clear_all">
            <button type="submit" class="button button-primary" onclick="return confirm('Esta ação remove todas as traduções em cache. Continuar?');">
                Limpar banco + memória
            </button>
        </form>
        <form method="post" style="display:inline-block;margin-right:12px;">
            <?php wp_nonce_field('translatex_cache_action', 'translatex_cache_nonce'); ?>
            <input type="hidden" name="translatex_cache_action" value="purge_expired">
            <button type="submit" class="button">Remover entradas sem fingerprint</button>
        </form>
        <form method="post" style="display:inline-block;">
            <?php wp_nonce_field('translatex_cache_action', 'translatex_cache_nonce'); ?>
            <input type="hidden" name="translatex_cache_action" value="flush_object">
            <button type="submit" class="button">Limpar cache em memória</button>
        </form>

        <h2 style="margin-top:32px;">Idiomas disponíveis</h2>
        <ul class="translatex-language-list" style="columns:2;max-width:640px;">
            <?php foreach ($supported_langs_with_names as $entry) : ?>
                <li>
                    <strong><?php echo esc_html($entry['language']); ?></strong>
                    &mdash;
                    <?php echo esc_html($entry['name']); ?>
                </li>
            <?php endforeach; ?>
        </ul>

        <h2 style="margin-top:32px;">Remover entrada específica ou idioma completo</h2>
        <form method="post" style="max-width:640px;">
            <?php wp_nonce_field('translatex_cache_action', 'translatex_cache_nonce'); ?>
            <input type="hidden" name="translatex_cache_action" value="delete_entry">
            <p>
                <label for="translatex_lang">Idioma:</label><br>
                <select name="translatex_lang" id="translatex_lang">
                    <option value="">Selecione um idioma</option>
                    <?php foreach ($supported_langs as $lang) : ?>
                        <option value="<?php echo esc_attr($lang); ?>"><?php echo esc_html($lang); ?></option>
                    <?php endforeach; ?>
                </select>
            </p>
            <p>
                <label for="translatex_url">URL (deixe vazio para remover TODAS as entradas do idioma):</label><br>
                <input type="text" id="translatex_url" name="translatex_url" class="regular-text" placeholder="/en/sobre-nos ou deixe vazio" />
            </p>
            <p>
                <button type="submit" class="button">Remover entrada</button>
            </p>
            <p class="description">
                <strong>Com URL:</strong> Remove apenas a entrada específica.<br>
                <strong>Sem URL:</strong> Remove TODAS as entradas em cache do idioma selecionado.
            </p>
        </form>

        <h2 style="margin-top:32px;">Itens por idioma</h2>
        <table class="widefat striped" style="max-width: 320px;">
            <thead>
                <tr>
                    <th>Idioma</th>
                    <th style="width:120px;">Itens</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($stats['per_lang'])) : ?>
                    <?php foreach ($stats['per_lang'] as $row) : ?>
                        <tr>
                            <td><?php echo esc_html($row['lang']); ?></td>
                            <td><?php echo number_format_i18n((int) $row['total']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else : ?>
                    <tr>
                        <td colspan="2">Nenhum cache gerado ainda.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}

