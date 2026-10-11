<?php

/**
 * A new comment, plain text.
 *
 * @var array<string, mixed> $view
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain text: nothing here is HTML.
echo (! empty($view['is_reply'])
    /* translators: 1: comment author, 2: post title */
    ? sprintf(__('%1$s replied to your comment on “%2$s”:', 'ruvelo-comment-subscriptions'), (string) $view['author'], (string) $view['post_title'])
    /* translators: 1: comment author, 2: post title */
    : sprintf(__('%1$s commented on “%2$s”:', 'ruvelo-comment-subscriptions'), (string) $view['author'], (string) $view['post_title']))."\n\n";
if ((string) $view['excerpt'] !== '') {
    echo $view['excerpt']."\n\n";
}
/* translators: %s: link */
echo sprintf(__('Read and reply: %s', 'ruvelo-comment-subscriptions'), (string) $view['comment_url']);
