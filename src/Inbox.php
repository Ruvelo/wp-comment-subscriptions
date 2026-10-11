<?php

declare(strict_types=1);

namespace Ruvelo\WP\CommentSubscriptions;

use WP_Comment;

/**
 * Optional delivery through Ruvelo Inbox (or anything else that listens to
 * the `ruvelo_inbox_notify` action). Only signed-in subscribers can choose
 * it, and only while something listens; otherwise everything is email.
 */
final class Inbox
{
    public const ACTION = 'ruvelo_inbox_notify';

    public const META = 'ruvelo_comment_subscriptions_delivery';

    public const CHOICES = ['email', 'inbox', 'both'];

    public static function available(): bool
    {
        /**
         * Filters whether an inbox is there to deliver to. By default: when
         * something listens to the `ruvelo_inbox_notify` action.
         *
         * @param bool $available
         */
        return (bool) apply_filters('ruvelo_comment_subscriptions_inbox_available', has_action(self::ACTION) !== false);
    }

    /**
     * "email", "inbox" or "both".
     */
    public static function preference(int $userId): string
    {
        $value = $userId > 0 ? get_user_meta($userId, self::META, true) : '';

        return is_string($value) && in_array($value, self::CHOICES, true) ? $value : 'email';
    }

    public static function setPreference(int $userId, string $choice): void
    {
        if (in_array($choice, self::CHOICES, true)) {
            update_user_meta($userId, self::META, $choice);
        }
    }

    public static function deliveryFor(Subscription $subscription): string
    {
        if ($subscription->userId <= 0 || ! self::available()) {
            return 'email';
        }

        return self::preference($subscription->userId);
    }

    /**
     * Hands one comment to the inbox. Never lets a failing listener stop the
     * batch: it falls back to email instead.
     */
    public static function send(Subscription $subscription, WP_Comment $comment): bool
    {
        if (! self::available() || $subscription->userId <= 0) {
            return false;
        }
        $data = Mailer::notificationData($subscription, $comment);
        $message = [
            'title' => ! empty($data['is_reply'])
                /* translators: 1: comment author, 2: post title */
                ? sprintf(__('%1$s replied to you on “%2$s”', 'ruvelo-comment-subscriptions'), (string) $data['author'], (string) $data['post_title'])
                /* translators: 1: comment author, 2: post title */
                : sprintf(__('%1$s commented on “%2$s”', 'ruvelo-comment-subscriptions'), (string) $data['author'], (string) $data['post_title']),
            'body' => (string) $data['excerpt'],
            'url' => (string) $data['comment_url'],
            'type' => 'comment',
            'source' => 'ruvelo-comment-subscriptions',
            'group' => 'post-'.$subscription->postId,
            'actor' => (string) $data['author'],
            'object_id' => (int) $comment->comment_ID,
        ];

        /**
         * Filters the message handed to the inbox.
         *
         * @param array<string, mixed> $message
         * @param Subscription $subscription
         * @param WP_Comment $comment
         */
        $message = apply_filters('ruvelo_comment_subscriptions_inbox_message', $message, $subscription, $comment);

        try {
            do_action('ruvelo_inbox_notify', [$subscription->userId], $message);
        } catch (\Throwable $error) {
            if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
                error_log('Ruvelo Comment Subscriptions: the inbox failed ('.$error->getMessage().'), sending email instead.'); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug logging only.
            }

            return false;
        }

        return true;
    }
}
