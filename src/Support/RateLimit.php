<?php

declare(strict_types=1);

namespace Ruvelo\WP\CommentSubscriptions\Support;

use Ruvelo\WP\CommentSubscriptions\Settings;

/**
 * Fixed-window rate limits kept in transients. Keys are keyed hashes, so
 * neither IP addresses nor email addresses are stored in plain text.
 */
final class RateLimit
{
    /**
     * Subscribe and "email me a link" requests from one IP address: the
     * `ip_limit` setting, per hour.
     */
    public static function ip(string $action = 'subscribe'): bool
    {
        return self::hit($action.'|ip|'.self::address(), Settings::int('ip_limit'), HOUR_IN_SECONDS, $action);
    }

    /**
     * Emails to one address: the `email_limit` setting, per day. Stops anyone
     * from using the site to flood a stranger's inbox with confirmations.
     */
    public static function email(string $email, string $action = 'confirm'): bool
    {
        return self::hit($action.'|email|'.strtolower(trim($email)), Settings::int('email_limit'), DAY_IN_SECONDS, $action.'_email');
    }

    public static function hit(string $key, int $limit, int $window, string $action): bool
    {
        /**
         * Filters a rate limit. Return 0 to turn it off.
         *
         * @param int $limit Hits allowed per window.
         * @param string $action "subscribe", "manage_link", "confirm_email" or "manage_link_email".
         */
        $limit = (int) apply_filters('ruvelo_comment_subscriptions_rate_limit', $limit, $action);
        if ($limit <= 0) {
            return true;
        }
        $transient = 'ruvelo_cs_rl_'.substr(wp_hash($key), 0, 32);
        $hits = (int) get_transient($transient);
        if ($hits >= $limit) {
            return false;
        }
        set_transient($transient, $hits + 1, $window);

        return true;
    }

    private static function address(): string
    {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';

        /**
         * Filters the visitor's IP address, for sites behind a proxy that
         * passes the real address in a header you trust.
         *
         * @param string $ip
         */
        return (string) apply_filters('ruvelo_comment_subscriptions_client_ip', $ip);
    }
}
