<?php

declare(strict_types=1);

namespace RAEK;

final class Installer
{
    private const DB_VERSION = '1';

    public static function activate(): void
    {
        self::create_tables();
    }

    /** Cheap autoloaded-option check, so schema upgrades ship with plugin updates. */
    public static function maybe_upgrade(): void
    {
        if (get_option('raek_db_version') !== self::DB_VERSION) {
            self::create_tables();
        }
    }

    private static function create_tables(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $p = $wpdb->prefix;

        dbDelta("CREATE TABLE {$p}raek_webhooks (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  url varchar(2000) NOT NULL,
  description varchar(200) NOT NULL DEFAULT '',
  events text NOT NULL,
  secret text NOT NULL,
  active tinyint(1) NOT NULL DEFAULT 1,
  failure_count int(11) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY user_id (user_id)
) $charset;");

        dbDelta("CREATE TABLE {$p}raek_deliveries (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  webhook_id bigint(20) unsigned NOT NULL,
  event varchar(50) NOT NULL,
  event_id char(36) NOT NULL,
  payload longtext NOT NULL,
  status varchar(20) NOT NULL DEFAULT 'pending',
  attempt smallint(5) unsigned NOT NULL DEFAULT 0,
  response_code smallint(5) unsigned NULL,
  response_body varchar(500) NULL,
  duration_ms int(10) unsigned NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY webhook_status (webhook_id,status)
) $charset;");

        update_option('raek_db_version', self::DB_VERSION);
    }
}
