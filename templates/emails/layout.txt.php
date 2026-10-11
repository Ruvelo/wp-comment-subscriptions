<?php

/**
 * The plain-text part of every email.
 *
 * @var array<string, mixed> $view
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain text: nothing here is HTML.
echo $view['content']."\n\n— \n";
$ruvelo_post = isset($view['post_title']) && is_string($view['post_title']) ? $view['post_title'] : '';
if ($view['template'] === 'notification') {
    /* translators: 1: post title, 2: site name */
    echo sprintf(__('You’re getting this because you follow the comments on “%1$s” at %2$s.', 'ruvelo-comment-subscriptions'), $ruvelo_post, (string) $view['site_name'])."\n";
    /* translators: %s: link */
    echo sprintf(__('Unsubscribe from this post: %s', 'ruvelo-comment-subscriptions'), (string) $view['unsubscribe_url'])."\n";
    /* translators: %s: link */
    echo sprintf(__('Manage your subscriptions: %s', 'ruvelo-comment-subscriptions'), (string) $view['manage_url'])."\n";
} elseif ($view['template'] === 'digest') {
    /* translators: %s: site name */
    echo sprintf(__('You’re getting this daily digest because you follow comments at %s.', 'ruvelo-comment-subscriptions'), (string) $view['site_name'])."\n";
    /* translators: %s: link */
    echo sprintf(__('Manage your subscriptions: %s', 'ruvelo-comment-subscriptions'), (string) $view['manage_url'])."\n";
    /* translators: %s: link */
    echo sprintf(__('Unsubscribe from everything: %s', 'ruvelo-comment-subscriptions'), (string) $view['unsubscribe_url'])."\n";
} else {
    /* translators: %s: site name */
    echo sprintf(__('Someone entered this address at %s. If it wasn’t you, ignore this email: you won’t hear from us again.', 'ruvelo-comment-subscriptions'), (string) $view['site_name'])."\n";
}
