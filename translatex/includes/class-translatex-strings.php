<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class TranslateX_Strings {
    const TABLE = 'translatex_strings';
    const STATUS_MACHINE = 1;
    protected static $table_ready = false;

    public static function table_name() {
        global $wpdb;
        return $wpdb->prefix . self::TABLE;
    }

    public static function install_table() {
        if ( self::$table_ready ) {
            return;
        }
        global $wpdb;

        $table   = self::table_name();
        $charset = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            lang varchar(12) NOT NULL,
            text_hash char(64) NOT NULL,
            original longtext NOT NULL,
            translated longtext NOT NULL,
            status tinyint(3) unsigned NOT NULL DEFAULT 1,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY lang_hash (lang, text_hash),
            KEY status_idx (status),
            KEY updated_at_idx (updated_at)
        ) {$charset};";

        dbDelta( $sql );

        self::$table_ready = true;
    }

    public static function get_translations( $lang, array $hashes ) {
        self::install_table();

        global $wpdb;

        $lang = translatex_normalize_lang( $lang );
        if ( empty( $lang ) || empty( $hashes ) ) {
            return array();
        }

        $hashes = array_values( array_unique( array_filter( array_map( 'strval', $hashes ) ) ) );
        if ( empty( $hashes ) ) {
            return array();
        }

        $placeholders = array_fill( 0, count( $hashes ), '%s' );
        $query        = $wpdb->prepare(
            'SELECT text_hash, translated FROM ' . self::table_name() . ' WHERE lang = %s AND text_hash IN (' . implode( ',', $placeholders ) . ')',
            array_merge( array( $lang ), $hashes )
        );

        $rows = $wpdb->get_results( $query, ARRAY_A );
        if ( empty( $rows ) ) {
            return array();
        }

        $translations = array();
        foreach ( $rows as $row ) {
            if ( isset( $row['text_hash'], $row['translated'] ) ) {
                $translations[ $row['text_hash'] ] = $row['translated'];
            }
        }

        return $translations;
    }

    public static function save_machine_translations( $lang, array $entries ) {
        global $wpdb;

        $lang = translatex_normalize_lang( $lang );
        if ( empty( $lang ) || empty( $entries ) ) {
            return;
        }

        self::install_table();

        $now         = current_time( 'mysql', true );
        $table       = self::table_name();
        $insert_rows = array();

        foreach ( $entries as $entry ) {
            if ( empty( $entry['hash'] ) || ! isset( $entry['translation'] ) ) {
                continue;
            }

            $insert_rows[] = $wpdb->prepare(
                '( %s, %s, %s, %s, %d, %s, %s )',
                $lang,
                substr( strtolower( $entry['hash'] ), 0, 64 ),
                isset( $entry['original'] ) ? (string) $entry['original'] : '',
                (string) $entry['translation'],
                self::STATUS_MACHINE,
                $now,
                $now
            );
        }

        if ( empty( $insert_rows ) ) {
            return;
        }

        $sql = 'INSERT INTO ' . $table . ' (lang, text_hash, original, translated, status, created_at, updated_at) VALUES ';
        $sql .= implode( ', ', $insert_rows );
        $sql .= ' ON DUPLICATE KEY UPDATE translated = VALUES(translated), status = VALUES(status), updated_at = VALUES(updated_at)';

        $wpdb->query( $sql );
    }
}

