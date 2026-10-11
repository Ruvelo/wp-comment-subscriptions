<?php

declare(strict_types=1);

namespace Ruvelo\WP\CommentSubscriptions;

use WP_User;

/**
 * Wires the plugin into WordPress. Nothing here talks to the outside world:
 * no remote requests, no telemetry, no admin notices outside its own screens.
 */
final class Plugin
{
    private static bool $booted = false;

    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;

        add_action('init', [self::class, 'init']);
        add_action('rest_api_init', [Rest\Controller::class, 'register']);
        add_action('deleted_post', [self::class, 'postDeleted']);
        add_action('delete_comment', [Notifications::class, 'forget']);
        add_action('deleted_user', [self::class, 'userDeleted'], 10, 3);
        add_action('profile_update', [self::class, 'profileUpdated'], 10, 2);
        add_action('update_option_'.Settings::OPTION, [self::class, 'settingsSaved'], 10, 2);

        Notifications::register();
        Frontend\Route::register();
        Frontend\CommentForm::register();
        Frontend\Forms::register();
        Privacy::register();

        if (function_exists('wp_register_ability')) {
            Abilities::register();
        }
        if (is_admin()) {
            Admin\Admin::register();
        }
        if (defined('WP_CLI') && WP_CLI) {
            Cli\Command::register();
        }
    }

    public static function init(): void
    {
        Install::maybeUpgrade();
        Blocks::register();
    }

    public static function postDeleted(int $postId): void
    {
        Subscriptions::deleteForPost($postId);
    }

    public static function userDeleted(int $userId, ?int $reassign = null, ?WP_User $user = null): void
    {
        global $wpdb;

        $table = Install::table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
        $ids = $wpdb->get_col($wpdb->prepare('SELECT id FROM %i WHERE user_id = %d', $table, $userId));
        foreach ($ids as $id) {
            Subscriptions::delete((int) $id, 'user_deleted');
        }
    }

    public static function profileUpdated(int $userId, WP_User $old): void
    {
        $user = get_userdata($userId);
        if ($user !== false && strtolower($user->user_email) !== strtolower($old->user_email)) {
            Subscriptions::emailChanged($userId, $user->user_email);
        }
    }

    public static function settingsSaved(mixed $old, mixed $new): void
    {
        Settings::flush();
        $before = is_array($old) ? (int) ($old['digest_hour'] ?? -1) : -1;
        $after = is_array($new) ? (int) ($new['digest_hour'] ?? -1) : -1;
        if ($before !== $after) {
            Notifications::scheduleDaily();
        }
    }
}
