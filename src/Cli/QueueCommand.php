<?php

declare(strict_types=1);

namespace Ruvelo\WP\CommentSubscriptions\Cli;

use Ruvelo\WP\CommentSubscriptions\Notifications;
use WP_CLI;

/**
 * The email queue and daily digests.
 */
final class QueueCommand
{
    /**
     * Shows how many comments and digests are waiting to go out.
     *
     * @param list<string> $args
     */
    public function status(array $args = []): void
    {
        $next = wp_next_scheduled(Notifications::SEND_HOOK);
        $daily = wp_next_scheduled(Notifications::DAILY_HOOK);
        WP_CLI::log(sprintf('Comments waiting: %d. Next run: %s.', Notifications::pending(), $next !== false ? gmdate('Y-m-d H:i:s', $next).' UTC' : 'not scheduled'));
        WP_CLI::log(sprintf('Digest subscribers waiting: %d. Next digest: %s.', Notifications::digestsWaiting(), $daily !== false ? gmdate('Y-m-d H:i:s', $daily).' UTC' : 'not scheduled'));
    }

    /**
     * Sends waiting notifications now, in batches, until none are left.
     *
     * ## OPTIONS
     *
     * [--batch=<n>]
     * : Subscribers per batch.
     *
     * [--digests]
     * : Also send every waiting daily digest now.
     *
     * ## EXAMPLES
     *
     *     wp ruvelo-comment-subscriptions queue run
     *     wp ruvelo-comment-subscriptions queue run --digests
     *
     * @param list<string> $args
     * @param array<string, string|bool> $assoc
     */
    public function run(array $args, array $assoc = []): void
    {
        $batch = isset($assoc['batch']) ? max(1, (int) $assoc['batch']) : null;
        $total = 0;
        do {
            $handled = Notifications::run($batch);
            $total += $handled;
        } while ($handled > 0 && Notifications::pending() > 0);

        $digests = 0;
        if (! empty($assoc['digests'])) {
            update_option(Notifications::DIGEST_DUE, current_time('mysql', true), false);
            $guard = 0;
            do {
                $digests += Notifications::runDigests($batch);
            } while (get_option(Notifications::DIGEST_DUE) !== false && ++$guard < 1000);
        }
        wp_clear_scheduled_hook(Notifications::SEND_HOOK);
        WP_CLI::success(sprintf('Handled %d subscribers; sent %d digests.', $total, $digests));
    }
}
