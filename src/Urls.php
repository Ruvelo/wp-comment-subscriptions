<?php

declare(strict_types=1);

namespace Ruvelo\WP\CommentSubscriptions;

/**
 * Every link the plugin sends. They all point at one page,
 * /comment-subscriptions/, with a view and a token.
 */
final class Urls
{
    public const QUERY_VAR = 'ruvelo_cs_page';

    public static function slug(): string
    {
        /**
         * Filters the address of the subscriptions page (without slashes).
         *
         * @param string $slug
         */
        $slug = trim((string) apply_filters('ruvelo_comment_subscriptions_slug', 'comment-subscriptions'), '/');

        return $slug !== '' ? $slug : 'comment-subscriptions';
    }

    /**
     * @param array<string, string> $args
     */
    public static function page(array $args = []): string
    {
        $base = get_option('permalink_structure')
            ? home_url(user_trailingslashit(self::slug()))
            : add_query_arg(self::QUERY_VAR, '1', home_url('/'));

        /**
         * Filters the subscriptions page address, e.g. to use a page of your
         * own that holds the Manage Subscriptions block.
         *
         * @param string $base
         */
        $base = (string) apply_filters('ruvelo_comment_subscriptions_page_url', $base);

        return $args === [] ? $base : add_query_arg(array_map('rawurlencode', $args), $base);
    }

    public static function confirm(string $token): string
    {
        return self::page(['rcs' => 'confirm', 'rcs_token' => $token]);
    }

    /**
     * Stops one subscription. Doesn't expire: it's in the List-Unsubscribe
     * header, which mail clients may use long after the email arrived.
     */
    public static function unsubscribe(Subscription $subscription): string
    {
        return self::page(['rcs' => 'unsubscribe', 'rcs_token' => self::unsubscribeToken($subscription)]);
    }

    public static function unsubscribeToken(Subscription $subscription): string
    {
        return Tokens::sign('unsubscribe', ['i' => $subscription->id, 'f' => Tokens::fingerprint($subscription->email)]);
    }

    /**
     * Stops every subscription an address has (the digest's one-click link).
     */
    public static function unsubscribeAll(string $email): string
    {
        return self::page(['rcs' => 'unsubscribe', 'rcs_token' => Tokens::sign('unsubscribe-all', ['e' => Subscriptions::normalize($email)])]);
    }

    /**
     * The manage page for an address, signed and expiring after `link_days`.
     */
    public static function manage(string $email): string
    {
        return self::page(['rcs' => 'manage', 'rcs_token' => self::manageToken($email)]);
    }

    public static function manageToken(string $email): string
    {
        return Tokens::sign('manage', ['e' => Subscriptions::normalize($email)], max(1, Settings::int('link_days')) * DAY_IN_SECONDS);
    }
}
