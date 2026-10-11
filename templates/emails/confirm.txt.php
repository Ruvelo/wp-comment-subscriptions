<?php

/**
 * Confirmation email, plain text.
 *
 * @var array<string, mixed> $view
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain text: nothing here is HTML.
echo __('Confirm your subscription', 'ruvelo-comment-subscriptions')."\n\n";
echo sprintf(
    /* translators: 1: "new comments" or "replies to your comments", 2: post title */
    __('Open this link to get an email when there are %1$s on “%2$s”:', 'ruvelo-comment-subscriptions'),
    $view['type'] === 'replies' ? __('replies to your comments', 'ruvelo-comment-subscriptions') : __('new comments', 'ruvelo-comment-subscriptions'),
    (string) $view['post_title']
)."\n\n";
echo $view['confirm_url']."\n\n";
/* translators: %d: number of days */
echo sprintf(_n('The link works for %d day. Every email has a one-click unsubscribe link.', 'The link works for %d days. Every email has a one-click unsubscribe link.', (int) $view['days'], 'ruvelo-comment-subscriptions'), (int) $view['days']);
