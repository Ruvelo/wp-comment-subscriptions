<?php

declare(strict_types=1);

namespace Ruvelo\WP\CommentSubscriptions;

/**
 * Tables, the signing secret, capabilities and the daily job. Runs on
 * activation and whenever the stored schema version is behind the code.
 */
final class Install
{
    public const DB_VERSION = '1';

    public const DB_OPTION = 'ruvelo_comment_subscriptions_db_version';

    public static function activate(): void
    {
        self::install();
        Frontend\Route::rewrite();
        flush_rewrite_rules();
    }

    public static function deactivate(): void
    {
        wp_clear_scheduled_hook(Notifications::SEND_HOOK);
        wp_clear_scheduled_hook(Notifications::DAILY_HOOK);
        flush_rewrite_rules();
    }

    public static function maybeUpgrade(): void
    {
        if (get_option(self::DB_OPTION) !== self::DB_VERSION) {
            self::install();
        }
        if (! wp_next_scheduled(Notifications::DAILY_HOOK)) {
            Notifications::scheduleDaily();
        }
    }

    public static function install(): void
    {
        self::createTables();
        self::grantCapabilities();
        Tokens::secret();
        Notifications::scheduleDaily();
        update_option(self::DB_OPTION, self::DB_VERSION, false);
    }

    public static function table(): string
    {
        global $wpdb;

        return $wpdb->prefix.'ruvelo_comment_subscriptions';
    }

    public static function queueTable(): string
    {
        global $wpdb;

        return $wpdb->prefix.'ruvelo_comment_subscriptions_queue';
    }

    public static function digestTable(): string
    {
        global $wpdb;

        return $wpdb->prefix.'ruvelo_comment_subscriptions_digest';
    }

    public static function createTables(): void
    {
        global $wpdb;

        require_once ABSPATH.'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();
        $subscriptions = self::table();
        $queue = self::queueTable();
        $digest = self::digestTable();

        // One row per address per post: only what sending and unsubscribing
        // need. `token_hash` is the SHA-256 of a pending row's confirmation
        // token; the token itself is only ever in the email.
        dbDelta("CREATE TABLE {$subscriptions} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  post_id bigint(20) unsigned NOT NULL,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  email varchar(190) NOT NULL,
  type varchar(10) NOT NULL DEFAULT 'all',
  status varchar(10) NOT NULL DEFAULT 'pending',
  frequency varchar(10) NOT NULL DEFAULT 'instant',
  token_hash char(64) NOT NULL DEFAULT '',
  source varchar(20) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  confirmed_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY post_email (post_id,email),
  KEY email (email),
  KEY user_id (user_id),
  KEY status_created (status,created_at),
  KEY token_hash (token_hash)
) {$charset};");

        // Approved comments waiting to be sent. WP-Cron works through each
        // one's subscribers in batches; `last_id` is the last subscription handled.
        dbDelta("CREATE TABLE {$queue} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  comment_id bigint(20) unsigned NOT NULL,
  post_id bigint(20) unsigned NOT NULL,
  last_id bigint(20) unsigned NOT NULL DEFAULT 0,
  sent int(10) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  done_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY comment_id (comment_id),
  KEY done_at (done_at)
) {$charset};");

        // Comments saved for the next daily digest.
        dbDelta("CREATE TABLE {$digest} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  subscription_id bigint(20) unsigned NOT NULL,
  comment_id bigint(20) unsigned NOT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY subscription_comment (subscription_id,comment_id),
  KEY comment_id (comment_id)
) {$charset};");
    }

    /**
     * Administrators manage subscriptions by default. Filter
     * `ruvelo_comment_subscriptions_manager_roles` to change who gets the capability.
     */
    public static function grantCapabilities(): void
    {
        $roles = apply_filters('ruvelo_comment_subscriptions_manager_roles', ['administrator']);
        foreach (is_array($roles) ? $roles : [] as $name) {
            $role = is_string($name) ? get_role($name) : null;
            $role?->add_cap(Settings::CAP);
        }
    }
}
