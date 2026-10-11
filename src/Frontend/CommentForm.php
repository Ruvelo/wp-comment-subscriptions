<?php

declare(strict_types=1);

namespace Ruvelo\WP\CommentSubscriptions\Frontend;

use Ruvelo\WP\CommentSubscriptions\Mailer;
use Ruvelo\WP\CommentSubscriptions\Settings;
use Ruvelo\WP\CommentSubscriptions\Subscriptions;
use Ruvelo\WP\CommentSubscriptions\Support\RateLimit;
use WP_Comment;

/**
 * The comment form: "Notify me of replies to my comment" and "…of all new
 * comments on this post", unticked by default, and "Subscribe without
 * commenting" underneath. Works with the core comment form in classic and
 * block themes (the Comments Form block uses it too).
 */
final class CommentForm
{
    public const FIELD = 'ruvelo_cs';

    /** @var array<int, string> Comment ids that asked a guest to confirm, this request. */
    private static array $confirming = [];

    public static function register(): void
    {
        add_filter('comment_form_submit_field', [self::class, 'field'], 20, 2);
        add_action('comment_form_after', [self::class, 'after']);
        add_action('comment_post', [self::class, 'posted'], 20, 3);
        add_filter('comment_post_redirect', [self::class, 'redirect'], 20, 2);
    }

    /**
     * @param array<string, mixed> $args
     */
    public static function field(string $submitField, array $args = []): string
    {
        $postId = (int) get_the_ID();
        if ($postId <= 0 || Subscriptions::canSubscribeTo($postId) !== true || (! Settings::bool('offer_replies') && ! Settings::bool('offer_all'))) {
            return $submitField;
        }

        return Views::commentOptions($postId).$submitField;
    }

    public static function after(): void
    {
        $postId = (int) get_the_ID();
        if (! Settings::bool('without_commenting') || $postId <= 0) {
            return;
        }
        echo Views::subscribeForm(['postId' => $postId, 'compact' => true]); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in the template.
    }

    /**
     * After a comment is saved: subscribe its author if they ticked a box.
     * Spam never subscribes anyone.
     *
     * @param int|string $approved 1, 0, "spam" or "trash".
     * @param array<string, mixed> $data
     */
    public static function posted(int $commentId, int|string $approved, array $data = []): void
    {
        $type = self::requestedType();
        if ($type === '' || $approved === 'spam' || $approved === 'trash') {
            return;
        }
        $comment = get_comment($commentId);
        if (! $comment instanceof WP_Comment) {
            return;
        }
        $postId = (int) $comment->comment_post_ID;
        $userId = (int) $comment->user_id;
        $user = $userId > 0 ? get_userdata($userId) : false;

        if ($user !== false && $userId === get_current_user_id()) {
            Subscriptions::subscribe($postId, $user->user_email, $type, ['user_id' => $userId, 'confirmed' => true, 'source' => 'comment']);

            return;
        }

        $email = Subscriptions::normalize((string) $comment->comment_author_email);
        if (! is_email($email) || ! RateLimit::ip()) {
            return;
        }
        $result = Subscriptions::subscribe($postId, $email, $type, ['source' => 'comment']);
        if (is_array($result) && $result['status'] === 'pending' && RateLimit::email($email)) {
            Mailer::confirmation($result['subscription'], $result['token']);
            self::$confirming[$commentId] = $email;
        }
    }

    /**
     * Tells the guest to check their inbox, back on the post.
     */
    public static function redirect(string $location, WP_Comment $comment): string
    {
        if (! isset(self::$confirming[(int) $comment->comment_ID])) {
            return $location;
        }
        $hash = '';
        if (str_contains($location, '#')) {
            [$location, $hash] = explode('#', $location, 2);
            $hash = '#'.$hash;
        }

        return add_query_arg('rcs_msg', 'confirm-comment', $location).$hash;
    }

    /**
     * "all", "replies", or "" when no box was ticked.
     */
    private static function requestedType(): string
    {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- The core comment form has no nonce for guests; a guest's subscription only starts once they confirm by email.
        $fields = isset($_POST[self::FIELD]) && is_array($_POST[self::FIELD]) ? array_map('sanitize_key', wp_unslash($_POST[self::FIELD])) : [];
        // phpcs:enable
        if (in_array('all', $fields, true) && Settings::bool('offer_all')) {
            return 'all';
        }
        if (in_array('replies', $fields, true) && Settings::bool('offer_replies')) {
            return 'replies';
        }

        return '';
    }
}
