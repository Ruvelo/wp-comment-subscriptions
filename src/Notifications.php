<?php

declare(strict_types=1);

namespace Ruvelo\WP\CommentSubscriptions;

use WP_Comment;

/**
 * When a comment is approved, one row goes into the queue. WP-Cron then
 * works through the post's subscribers in batches, so a post with thousands
 * of followers never sends mail inside a page load. Subscribers on the daily
 * digest have the comment saved for the next digest instead.
 *
 * Only approved comments are ever sent: held, spam and trashed comments
 * never queue, and the queue checks again just before sending.
 */
final class Notifications
{
    public const SEND_HOOK = 'ruvelo_comment_subscriptions_send';

    public const DAILY_HOOK = 'ruvelo_comment_subscriptions_daily';

    public const DIGEST_DUE = 'ruvelo_comment_subscriptions_digest_due';

    private static bool $paused = false;

    /**
     * Stops queueing while true: for imports and the demo.
     */
    public static function pause(bool $paused = true): void
    {
        self::$paused = $paused;
    }

    public static function register(): void
    {
        add_action('wp_insert_comment', [self::class, 'inserted'], 20, 2);
        add_action('transition_comment_status', [self::class, 'transitioned'], 20, 3);
        add_action(self::SEND_HOOK, [self::class, 'cron']);
        add_action(self::DAILY_HOOK, [self::class, 'daily']);
    }

    public static function inserted(int $id, WP_Comment $comment): void
    {
        if ((string) $comment->comment_approved === '1') {
            self::queue($comment);
        }
    }

    public static function transitioned(string $new, string $old, WP_Comment $comment): void
    {
        if ($new === 'approved' && $old !== 'approved') {
            self::queue($comment);
        }
    }

    /**
     * Whether subscribers should hear about this comment at all.
     */
    public static function shouldNotify(WP_Comment $comment): bool
    {
        if ((string) $comment->comment_approved !== '1') {
            return false;
        }

        /**
         * Filters which comment types reach subscribers. Pingbacks,
         * trackbacks and editor notes don't, by default.
         *
         * @param list<string> $types
         */
        $types = (array) apply_filters('ruvelo_comment_subscriptions_comment_types', ['comment']);
        $type = $comment->comment_type === '' ? 'comment' : $comment->comment_type;
        $post = get_post((int) $comment->comment_post_ID);
        $notify = in_array($type, $types, true)
            && $post !== null
            && $post->post_status === 'publish'
            && is_post_type_viewable($post->post_type)
            && $post->post_password === ''
            && ! Subscriptions::disabledFor($post->ID);

        /**
         * Filters whether a comment is sent to the post's subscribers.
         *
         * @param bool $notify
         * @param WP_Comment $comment
         */
        return (bool) apply_filters('ruvelo_comment_subscriptions_should_notify', $notify, $comment);
    }

    /**
     * Queues an approved comment, once.
     */
    public static function queue(WP_Comment $comment): int
    {
        global $wpdb;

        if (self::$paused || ! self::shouldNotify($comment)) {
            return 0;
        }
        $table = Install::queueTable();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
        $exists = $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE comment_id = %d', $table, (int) $comment->comment_ID));
        if ($exists !== null) {
            return 0; // Approved before (then unapproved and approved again): never twice.
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
        $inserted = $wpdb->insert($table, [
            'comment_id' => (int) $comment->comment_ID,
            'post_id' => (int) $comment->comment_post_ID,
            'created_at' => current_time('mysql', true),
        ], ['%d', '%d', '%s']);
        if ($inserted === false) {
            return 0;
        }
        self::schedule();

        /**
         * Fires when an approved comment is queued for its post's subscribers.
         *
         * @param WP_Comment $comment
         */
        do_action('ruvelo_comment_subscriptions_queued', $comment);

        return (int) $wpdb->insert_id;
    }

    public static function schedule(int $delay = 30): void
    {
        if (! wp_next_scheduled(self::SEND_HOOK)) {
            wp_schedule_single_event(time() + $delay, self::SEND_HOOK);
        }
    }

    /**
     * The daily job runs at the `digest_hour` setting, in the site's time zone.
     */
    public static function scheduleDaily(): void
    {
        wp_clear_scheduled_hook(self::DAILY_HOOK);
        $hour = max(0, min(23, Settings::int('digest_hour')));
        $next = new \DateTimeImmutable('today '.$hour.':00', wp_timezone());
        if ($next->getTimestamp() <= time()) {
            $next = $next->modify('+1 day');
        }
        wp_schedule_event($next->getTimestamp(), 'daily', self::DAILY_HOOK);
    }

    public static function pending(): int
    {
        global $wpdb;

        $table = Install::queueTable();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
        return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE done_at IS NULL', $table));
    }

    /**
     * Addresses with a digest waiting.
     */
    public static function digestsWaiting(): int
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables.
        return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(DISTINCT s.email) FROM %i d INNER JOIN %i s ON s.id = d.subscription_id', Install::digestTable(), Install::table()));
    }

    /**
     * The single-event cron callback: the queue, then any digests still due.
     */
    public static function cron(): void
    {
        self::run();
        self::runDigests();
    }

    /**
     * The daily cron callback: send digests, forget expired requests.
     */
    public static function daily(): void
    {
        update_option(self::DIGEST_DUE, current_time('mysql', true), false);
        self::runDigests();
        Subscriptions::prune();
        self::prune();
    }

    /**
     * Sends up to one batch, then schedules itself again if there's more.
     *
     * @return int Subscribers handled in this run.
     */
    public static function run(?int $batch = null): int
    {
        global $wpdb;

        $batch = $batch ?? max(1, Settings::int('batch_size'));
        $table = Install::queueTable();
        $handled = 0;
        $guard = 0;

        while ($handled < $batch && $guard++ < 100) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
            $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE done_at IS NULL ORDER BY id ASC LIMIT 1', $table), ARRAY_A);
            if (! is_array($row)) {
                break;
            }
            $handled += self::process($row, $batch - $handled);
        }

        if (self::pending() > 0) {
            self::schedule(60);
        }

        return $handled;
    }

    /**
     * Works through one queued comment's subscribers, in id order,
     * remembering where it stopped.
     *
     * @param array<string, mixed> $row
     */
    private static function process(array $row, int $budget): int
    {
        global $wpdb;

        $queue = Install::queueTable();
        $id = (int) $row['id'];
        $comment = get_comment((int) $row['comment_id']);

        // Checked again now: the comment may have been unapproved, marked as spam or trashed since.
        if (! $comment instanceof WP_Comment || ! self::shouldNotify($comment)) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
            $wpdb->update($queue, ['done_at' => current_time('mysql', true)], ['id' => $id]);

            return 1;
        }

        $cursor = (int) $row['last_id'];
        $limit = max(1, $budget);
        $table = Install::table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE post_id = %d AND status = %s AND id > %d ORDER BY id ASC LIMIT %d', $table, (int) $row['post_id'], 'active', $cursor, $limit), ARRAY_A);
        $rows = is_array($rows) ? $rows : [];
        $sent = 0;

        foreach ($rows as $data) {
            $subscription = Subscription::fromRow($data);
            $cursor = $subscription->id;
            if (! self::wants($subscription, $comment)) {
                continue;
            }
            $sent += self::deliver($subscription, $comment) ? 1 : 0;
        }

        $finished = count($rows) < $limit;
        $update = ['last_id' => $cursor, 'sent' => (int) $row['sent'] + $sent];
        if ($finished) {
            $update['done_at'] = current_time('mysql', true);
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
        $wpdb->update($queue, $update, ['id' => $id]);

        return max(1, count($rows));
    }

    /**
     * Whether this subscriber should hear about this comment. Nobody hears
     * about their own comment. "Replies" subscribers hear about replies to
     * any of their comments on the post, however deep in the thread.
     */
    public static function wants(Subscription $subscription, WP_Comment $comment): bool
    {
        if (self::isAuthor($subscription, $comment)) {
            return false;
        }
        $wants = true;
        if ($subscription->type === 'replies') {
            $wants = false;
            $parentId = (int) $comment->comment_parent;
            $seen = 0;
            while ($parentId > 0 && $seen++ < 50) {
                $parent = get_comment($parentId);
                if (! $parent instanceof WP_Comment) {
                    break;
                }
                if (self::isAuthor($subscription, $parent)) {
                    $wants = true;

                    break;
                }
                $parentId = (int) $parent->comment_parent;
            }
        }

        /**
         * Filters whether one subscriber hears about one comment.
         *
         * @param bool $wants
         * @param Subscription $subscription
         * @param WP_Comment $comment
         */
        return (bool) apply_filters('ruvelo_comment_subscriptions_recipient', $wants, $subscription, $comment);
    }

    private static function isAuthor(Subscription $subscription, WP_Comment $comment): bool
    {
        $userId = (int) $comment->user_id;
        if ($userId > 0 && $subscription->userId === $userId) {
            return true;
        }
        $email = strtolower(trim((string) $comment->comment_author_email));

        return $email !== '' && ($email === $subscription->email || $email === $subscription->recipient());
    }

    /**
     * Tells one subscriber: through Ruvelo Inbox if they chose it, by email
     * now, or saved for their daily digest.
     */
    public static function deliver(Subscription $subscription, WP_Comment $comment): bool
    {
        $delivery = Inbox::deliveryFor($subscription);
        $delivered = false;

        if ($delivery !== 'email') {
            $delivered = Inbox::send($subscription, $comment);
            if (! $delivered) {
                $delivery = 'email'; // The inbox went away: fall back to email.
            }
        }

        /**
         * Fires for every subscriber told about a comment, whatever the channel.
         *
         * @param Subscription $subscription
         * @param WP_Comment $comment
         */
        do_action('ruvelo_comment_subscriptions_notify', $subscription, $comment);

        if ($delivery === 'inbox') {
            return $delivered;
        }

        if ($subscription->frequency === 'daily') {
            return self::saveForDigest($subscription, $comment) || $delivered;
        }

        return Mailer::notification($subscription, $comment) || $delivered;
    }

    private static function saveForDigest(Subscription $subscription, WP_Comment $comment): bool
    {
        global $wpdb;

        $table = Install::digestTable();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
        $exists = $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE subscription_id = %d AND comment_id = %d', $table, $subscription->id, (int) $comment->comment_ID));
        if ($exists !== null) {
            return false;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
        return (bool) $wpdb->insert($table, [
            'subscription_id' => $subscription->id,
            'comment_id' => (int) $comment->comment_ID,
            'created_at' => current_time('mysql', true),
        ], ['%d', '%d', '%s']);
    }

    /**
     * Sends the digests due: one email per address, covering every post it
     * follows. Works in batches of addresses and picks up where it stopped.
     *
     * @return int Digests sent.
     */
    public static function runDigests(?int $batch = null): int
    {
        global $wpdb;

        $due = get_option(self::DIGEST_DUE);
        if (! is_string($due) || $due === '') {
            return 0;
        }
        $batch = $batch ?? max(1, Settings::int('batch_size'));
        $digest = Install::digestTable();
        $table = Install::table();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables.
        $emails = $wpdb->get_col($wpdb->prepare('SELECT DISTINCT s.email FROM %i d INNER JOIN %i s ON s.id = d.subscription_id WHERE d.created_at <= %s ORDER BY s.email ASC LIMIT %d', $digest, $table, $due, $batch));
        $sent = 0;
        foreach ($emails as $email) {
            $sent += self::sendDigest((string) $email, $due) ? 1 : 0;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
        $left = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE created_at <= %s', $digest, $due));
        if ($left > 0 && count($emails) > 0) {
            self::schedule(60);
        } elseif ($left === 0) {
            delete_option(self::DIGEST_DUE);
        }

        return $sent;
    }

    /**
     * One address's digest. Comments that stopped being approved since are dropped.
     */
    public static function sendDigest(string $email, string $due): bool
    {
        global $wpdb;

        $digest = Install::digestTable();
        $table = Install::table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables.
        $rows = $wpdb->get_results($wpdb->prepare('SELECT d.id AS digest_id, d.comment_id, s.* FROM %i d INNER JOIN %i s ON s.id = d.subscription_id WHERE s.email = %s AND d.created_at <= %s ORDER BY s.post_id ASC, d.comment_id ASC LIMIT 200', $digest, $table, $email, $due), ARRAY_A);
        $items = [];
        $ids = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $ids[] = (int) $row['digest_id'];
            $comment = get_comment((int) $row['comment_id']);
            $subscription = Subscription::fromRow($row);
            if ($comment instanceof WP_Comment && $subscription->isActive() && self::shouldNotify($comment)) {
                $items[] = ['subscription' => $subscription, 'comment' => $comment];
            }
        }
        if ($ids !== []) {
            $placeholders = implode(',', array_fill(0, count($ids), '%d'));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Custom table; one %d per id.
            $wpdb->query($wpdb->prepare("DELETE FROM %i WHERE id IN ({$placeholders})", $digest, ...$ids));
        }

        return $items !== [] && Mailer::digest($items[0]['subscription']->recipient(), $items);
    }

    /**
     * Forgets finished queue rows after 30 days (they only feed the stats).
     */
    public static function prune(): int
    {
        global $wpdb;

        $before = gmdate('Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS);
        $table = Install::queueTable();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
        return (int) $wpdb->query($wpdb->prepare('DELETE FROM %i WHERE done_at IS NOT NULL AND done_at < %s', $table, $before));
    }

    /**
     * A comment was deleted: drop it from the queue and from digests.
     */
    public static function forget(int $commentId): void
    {
        global $wpdb;

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables.
        $wpdb->delete(Install::digestTable(), ['comment_id' => $commentId], ['%d']);
        $wpdb->update(Install::queueTable(), ['done_at' => current_time('mysql', true)], ['comment_id' => $commentId, 'done_at' => null]);
        // phpcs:enable
    }
}
