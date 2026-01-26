<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class TranslateX_Cache {
    const OBJECT_CACHE_GROUP = 'translatex_cache';
    const MAX_AGE_SECONDS = 172800; // usado apenas para limpezas manuais herdadas
    const MIN_ACCESS_WRITE_INTERVAL = 900; // 15 minutos
    const SOURCE_HASH_LENGTH = 64;

    public static function table_name() {
        global $wpdb;
        return $wpdb->prefix . 'translatex_cache';
    }

    public static function install_table() {
        global $wpdb;

        $table = self::table_name();
        $charset = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            url_hash char(32) NOT NULL,
            url text NOT NULL,
            lang varchar(12) NOT NULL,
            content longtext NOT NULL,
            generated_at bigint(20) unsigned NOT NULL,
            last_accessed bigint(20) unsigned NOT NULL,
            hits bigint(20) unsigned NOT NULL DEFAULT 0,
            source_hash char(" . self::SOURCE_HASH_LENGTH . ") NOT NULL DEFAULT '',
            PRIMARY KEY  (id),
            UNIQUE KEY url_lang (url_hash, lang),
            KEY generated_at (generated_at),
            KEY last_accessed (last_accessed),
            KEY source_hash (source_hash)
        ) {$charset};";

        dbDelta($sql);
    }

    public static function cache_key($url, $lang) {
        return md5($lang . '|' . $url);
    }

    /**
     * Returns list of query parameters to ignore when normalizing URLs for cache.
     * These are typically tracking/marketing parameters that don't affect page content.
     *
     * @return array List of parameter names to ignore.
     */
    public static function get_ignored_query_params() {
        $defaults = array(
            // UTM parameters
            'utm_source',
            'utm_medium',
            'utm_campaign',
            'utm_term',
            'utm_content',
            'utm_id',
            'utm_source_platform',
            'utm_creative_format',
            // Facebook
            'fbclid',
            'fb_action_ids',
            'fb_action_types',
            'fb_source',
            // Google
            'gclid',
            'gclsrc',
            'dclid',
            '_ga',
            '_gid',
            '_gl',
            // Microsoft/Bing
            'msclkid',
            // Twitter/X
            'twclid',
            // LinkedIn
            'li_fat_id',
            // HubSpot
            '__hstc',
            '__hssc',
            '__hsfp',
            // Mailchimp
            'mc_cid',
            'mc_eid',
            // Marketo
            'mkt_tok',
            // Others
            'srsltid',
            'ref',
        );

        return apply_filters('translatex_ignored_query_params', $defaults);
    }

    /**
     * Cleans a query string by removing tracking/marketing parameters.
     * Remaining parameters are sorted alphabetically for cache key consistency.
     *
     * @param string $query_string The query string to clean (without leading '?').
     * @return string Cleaned query string (without leading '?'), or empty string.
     */
    public static function clean_query_string($query_string) {
        if (empty($query_string)) {
            return '';
        }

        parse_str($query_string, $params);

        if (empty($params)) {
            return '';
        }

        $ignored = self::get_ignored_query_params();

        foreach ($params as $key => $value) {
            // Remove exact matches from ignored list
            if (in_array($key, $ignored, true)) {
                unset($params[$key]);
                continue;
            }
            // Remove parameters that start with known tracking prefixes (hsa_*, utm_*)
            if (preg_match('/^(hsa_|utm_)/i', $key)) {
                unset($params[$key]);
            }
        }

        if (empty($params)) {
            return '';
        }

        // Sort alphabetically for cache key consistency
        ksort($params);

        return http_build_query($params);
    }

    public static function normalize_url($url) {
        $url = is_string($url) ? $url : '';
        if ($url === '') {
            $url = home_url('/');
        }

        $parsed = wp_parse_url($url);
        $home_parts = wp_parse_url(home_url());

        $scheme = isset($parsed['scheme']) ? strtolower($parsed['scheme']) : (isset($home_parts['scheme']) ? strtolower($home_parts['scheme']) : 'https');
        $host   = isset($parsed['host']) ? strtolower($parsed['host']) : (isset($home_parts['host']) ? strtolower($home_parts['host']) : '');
        if ($host === '' && isset($_SERVER['HTTP_HOST'])) {
            $host = strtolower(sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST'])));
        }
        $port   = '';
        if (isset($parsed['port'])) {
            $port = ':' . $parsed['port'];
        } elseif (isset($home_parts['port'])) {
            $port = ':' . $home_parts['port'];
        }
        $path   = isset($parsed['path']) ? '/' . ltrim($parsed['path'], '/') : '/';
        $path   = '/' . ltrim(untrailingslashit($path), '/');
        if ($path === '//') {
            $path = '/';
        }

        // Clean query string: remove tracking parameters, sort remaining for consistency
        $clean_query = isset($parsed['query']) ? self::clean_query_string($parsed['query']) : '';
        $query = $clean_query !== '' ? '?' . $clean_query : '';

        return "{$scheme}://{$host}{$port}{$path}{$query}";
    }

    public static function get_current_request_url() {
        $request_uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/';
        $request_uri = $request_uri ? $request_uri : '/';
        return self::normalize_url(home_url($request_uri));
    }

    public static function get($url, $lang) {
        $lang = translatex_normalize_lang($lang);
        if (empty($lang)) {
            return null;
        }

        $url = self::normalize_url($url);
        $key = self::cache_key($url, $lang);

        $now = current_time('timestamp');
        $cached = wp_cache_get($key, self::OBJECT_CACHE_GROUP);
        if ($cached && is_array($cached)) {
            self::maybe_record_access($cached, $key, $now);
            return $cached;
        }

        global $wpdb;
        $table = self::table_name();
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE url_hash = %s AND lang = %s LIMIT 1",
                $key,
                $lang
            ),
            ARRAY_A
        );

        if (!$row) {
            return null;
        }

        $row = self::hydrate_row($row);

        self::maybe_record_access($row, $key, $now, true);
        return $row;
    }

    public static function save($url, $lang, $content, $source_hash = '') {
        $lang = translatex_normalize_lang($lang);
        if (empty($lang)) {
            return false;
        }
        $url = self::normalize_url($url);
        $key = self::cache_key($url, $lang);
        $now = current_time('timestamp');
        $source_hash = self::sanitize_source_hash($source_hash);

        global $wpdb;
        $table = self::table_name();

        $data = array(
            'url_hash'      => $key,
            'url'           => $url,
            'lang'          => $lang,
            'content'       => $content,
            'generated_at'  => $now,
            'last_accessed' => $now,
            'hits'          => 1,
            'source_hash'   => $source_hash,
        );

        $formats = array('%s','%s','%s','%s','%d','%d','%d','%s');

        $existing_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$table} WHERE url_hash = %s AND lang = %s LIMIT 1",
                $key,
                $lang
            )
        );

        if ($existing_id) {
            $wpdb->update(
                $table,
                $data,
                array('id' => $existing_id),
                $formats,
                array('%d')
            );
            $data['id'] = (int) $existing_id;
        } else {
            $wpdb->insert($table, $data, $formats);
            $data['id'] = (int) $wpdb->insert_id;
        }

        wp_cache_set($key, $data, self::OBJECT_CACHE_GROUP, HOUR_IN_SECONDS);
        return $data;
    }

    public static function delete($url, $lang) {
        $lang = translatex_normalize_lang($lang);
        if (empty($lang)) {
            return;
        }
        $url = self::normalize_url($url);
        $key = self::cache_key($url, $lang);

        global $wpdb;
        $table = self::table_name();
        $wpdb->delete(
            $table,
            array('url_hash' => $key, 'lang' => $lang),
            array('%s','%s')
        );

        wp_cache_delete($key, self::OBJECT_CACHE_GROUP);
    }

    public static function delete_by_language($lang) {
        $lang = translatex_normalize_lang($lang);
        if (empty($lang)) {
            return 0;
        }

        global $wpdb;
        $table = self::table_name();
        $count = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table} WHERE lang = %s",
                $lang
            )
        );

        self::flush_object_cache();
        return $count;
    }

    public static function delete_all() {
        global $wpdb;
        $table = self::table_name();
        $wpdb->query("TRUNCATE TABLE {$table}");
        self::flush_object_cache();
    }

    public static function purge_older_than($max_age_seconds = self::MAX_AGE_SECONDS) {
        global $wpdb;
        $table = self::table_name();
        $threshold = current_time('timestamp') - $max_age_seconds;
        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT id FROM {$table} WHERE generated_at < %d",
                $threshold
            )
        );

        if (empty($ids)) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table} WHERE id IN ({$placeholders})",
                $ids
            )
        );

        self::flush_object_cache();
        return count($ids);
    }

    public static function start_of_today($timestamp = null) {
        $timestamp = $timestamp ?: current_time('timestamp');
        return strtotime('today', $timestamp);
    }

    public static function is_expired($entry, $timestamp = null) {
        $timestamp = $timestamp ?: current_time('timestamp');
        return ($timestamp - (int) $entry['generated_at']) > self::MAX_AGE_SECONDS;
    }

    public static function is_stale_for_today($entry, $timestamp = null) {
        $timestamp = $timestamp ?: current_time('timestamp');
        return (int) $entry['generated_at'] < self::start_of_today($timestamp);
    }

    public static function get_stats() {
        global $wpdb;
        $table = self::table_name();
        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        $last_generated = (int) $wpdb->get_var("SELECT MAX(generated_at) FROM {$table}");
        $total_hits = (int) $wpdb->get_var("SELECT SUM(hits) FROM {$table}");
        $per_lang = $wpdb->get_results(
            "SELECT lang, COUNT(*) as total FROM {$table} GROUP BY lang ORDER BY total DESC",
            ARRAY_A
        );

        return array(
            'count'         => $count,
            'last_generated'=> $last_generated,
            'total_hits'    => $total_hits,
            'per_lang'      => $per_lang,
        );
    }

    private static function hydrate_row($row) {
        $row['id'] = isset($row['id']) ? (int) $row['id'] : 0;
        $row['generated_at'] = isset($row['generated_at']) ? (int) $row['generated_at'] : 0;
        $row['last_accessed'] = isset($row['last_accessed']) ? (int) $row['last_accessed'] : 0;
        $row['hits'] = isset($row['hits']) ? (int) $row['hits'] : 0;
        $row['content'] = isset($row['content']) ? $row['content'] : '';
        $row['url'] = isset($row['url']) ? $row['url'] : '';
        $row['lang'] = isset($row['lang']) ? $row['lang'] : '';
        $row['source_hash'] = self::sanitize_source_hash(isset($row['source_hash']) ? $row['source_hash'] : '');
        return $row;
    }

    private static function maybe_record_access(array &$entry, $cache_key, $now = null, $force_db_write = false) {
        $now = $now ?: current_time('timestamp');
        $last_accessed = isset($entry['last_accessed']) ? (int) $entry['last_accessed'] : 0;
        $hits = isset($entry['hits']) ? (int) $entry['hits'] : 0;
        $hits++;
        $entry['hits'] = $hits;
        $entry['last_accessed'] = $now;

        wp_cache_set($cache_key, $entry, self::OBJECT_CACHE_GROUP, HOUR_IN_SECONDS);

        $should_write = $force_db_write || ($now - $last_accessed) >= self::MIN_ACCESS_WRITE_INTERVAL;
        if ($should_write) {
            global $wpdb;
            $table = self::table_name();
            $wpdb->update(
                $table,
                array(
                    'last_accessed' => $now,
                    'hits'          => $hits,
                ),
                array('id' => (int) $entry['id']),
                array('%d','%d'),
                array('%d')
            );
        }
    }

    public static function flush_object_cache() {
        if (function_exists('wp_cache_delete_group')) {
            wp_cache_delete_group(self::OBJECT_CACHE_GROUP);
            return;
        }

        if (
            function_exists('wp_cache_supports')
            && wp_cache_supports('flush_group')
            && function_exists('wp_cache_flush_group')
        ) {
            wp_cache_flush_group(self::OBJECT_CACHE_GROUP);
            return;
        }

        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
        }
    }

    public static function compute_source_hash($html) {
        if (!is_string($html) || $html === '') {
            return '';
        }

        $normalized = self::normalize_source_html($html);

        return hash('sha256', $normalized);
    }

    public static function matches_source_hash($entry, $expected_hash) {
        if (empty($expected_hash)) {
            return false;
        }

        if (!is_array($entry) || empty($entry['source_hash'])) {
            return false;
        }

        return hash_equals($entry['source_hash'], $expected_hash);
    }

    public static function purge_without_fingerprint() {
        global $wpdb;
        $table = self::table_name();
        $ids = $wpdb->get_col(
            "SELECT id FROM {$table} WHERE source_hash IS NULL OR source_hash = ''"
        );

        if (empty($ids)) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table} WHERE id IN ({$placeholders})",
                $ids
            )
        );

        self::flush_object_cache();
        return count($ids);
    }

    private static function normalize_source_html($html) {
        $patterns = array(
            '#<script\b[^>]*>.*?</script>#is',
            '#<style\b[^>]*>.*?</style>#is',
            '#<noscript\b[^>]*>.*?</noscript>#is',
            '#<iframe\b[^>]*>.*?</iframe>#is',
            '#<template\b[^>]*>.*?</template>#is',
        );

        $normalized = $html;
        foreach ($patterns as $pattern) {
            $normalized = preg_replace($pattern, '', $normalized);
            if ($normalized === null) {
                $normalized = '';
            }
        }

        $normalized = preg_replace(
            '/\b(_wpnonce|nonce|data-wpnonce|data-nonce)\s*=\s*(["\']).*?\2/i',
            '$1=$2$2',
            $normalized
        );

        $normalized = preg_replace(
            '/(<input[^>]+name=(["\'])?_wpnonce\2?[^>]*value=)(["\']).*?\3/i',
            '$1$3$3',
            $normalized
        );

        if ($normalized === null) {
            $normalized = '';
        }

        $normalized = preg_replace('/\s+/u', ' ', $normalized);
        $normalized = trim($normalized);

        if ($normalized === null) {
            $normalized = '';
        }

        return $normalized;
    }

    private static function sanitize_source_hash($hash) {
        if (!is_string($hash)) {
            return '';
        }

        $hash = trim($hash);
        if ($hash === '') {
            return '';
        }

        if (!preg_match('/^[a-f0-9]+$/i', $hash)) {
            $hash = hash('sha256', $hash);
        }

        if (strlen($hash) > self::SOURCE_HASH_LENGTH) {
            $hash = substr($hash, 0, self::SOURCE_HASH_LENGTH);
        }

        return $hash;
    }
}

