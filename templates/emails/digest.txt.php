<?php

/**
 * The daily digest, plain text.
 *
 * @var array<string, mixed> $view
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain text: nothing here is HTML.
/* translators: %s: number of comments */
echo sprintf(_n('%s new comment on the posts you follow', '%s new comments on the posts you follow', (int) $view['count'], 'ruvelo-comment-subscriptions'), number_format_i18n((int) $view['count']))."\n";
foreach ((array) $view['posts'] as $ruvelo_post) {
    echo "\n## ".$ruvelo_post['title']."\n";
    foreach ((array) $ruvelo_post['comments'] as $ruvelo_comment) {
        echo "\n".$ruvelo_comment['author'].' · '.$ruvelo_comment['date']."\n";
        if ((string) $ruvelo_comment['excerpt'] !== '') {
            echo $ruvelo_comment['excerpt']."\n";
        }
        echo $ruvelo_comment['url']."\n";
    }
    /* translators: %s: link */
    echo "\n".sprintf(__('Stop following this post: %s', 'ruvelo-comment-subscriptions'), (string) $ruvelo_post['unsubscribe_url'])."\n";
}
