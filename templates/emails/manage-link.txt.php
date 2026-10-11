<?php

/**
 * A link to the manage page, plain text.
 *
 * @var array<string, mixed> $view
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain text: nothing here is HTML.
echo __('Your comment subscriptions', 'ruvelo-comment-subscriptions')."\n\n";
echo __('Change how often you hear, or unsubscribe, from this page:', 'ruvelo-comment-subscriptions')."\n\n";
echo $view['manage_url']."\n\n";
/* translators: %d: number of days */
echo sprintf(_n('The link works for %d day and only for you: don’t forward this email.', 'The link works for %d days and only for you: don’t forward this email.', (int) $view['days'], 'ruvelo-comment-subscriptions'), (int) $view['days']);
