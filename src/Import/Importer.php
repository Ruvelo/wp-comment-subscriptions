<?php

declare(strict_types=1);

namespace Ruvelo\WP\CommentSubscriptions\Import;

use Ruvelo\WP\CommentSubscriptions\Subscriptions;
use WP_Error;

/**
 * Imports subscriptions from the plugins sites are moving away from. Source
 * data is only read, never changed or deleted, so an import can be repeated
 * and the old plugin still works until you remove it.
 *
 * Subscribe To Comments Reloaded (closed on WordPress.org in April 2026)
 * keeps one postmeta row per address per post: the key is "_stcr@_" followed
 * by the address, the value "Y-m-d H:i:s|<status>" in the site's time zone.
 * Statuses: Y all comments, R replies only, D daily digest (rare); a trailing
 * C (YC, RC, DC, C) means not confirmed yet, or suspended by the subscriber.
 *
 * Subscribe to Comments (the original, by Mark Jaquith) keeps a
 * "_sg_subscribe-to-comments" postmeta row per address per post, for all
 * comments, and "-pending" keys for addresses that never confirmed.
 */
final class Importer
{
    public const SOURCES = ['stcr', 'stc'];

    public const STCR_PREFIX = '_stcr@_';

    public const STC_KEY = '_sg_subscribe-to-comments';

    public const STC_PENDING = ['_sg_subscribe-to-comments-pending', '_sg_subscribe-to-comments-pending-with-email'];

    public static function label(string $source): string
    {
        return $source === 'stc' ? 'Subscribe to Comments' : 'Subscribe To Comments Reloaded';
    }

    /**
     * How many rows each source has on this site.
     *
     * @return array<string, int>
     */
    public static function detect(): array
    {
        global $wpdb;

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Counting another plugin's rows, on an admin screen.
        $stcr = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $wpdb->esc_like(self::STCR_PREFIX).'%'));
        $stc = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key IN (%s, %s, %s)", self::STC_KEY, self::STC_PENDING[0], self::STC_PENDING[1]));
        // phpcs:enable

        return ['stcr' => $stcr, 'stc' => $stc];
    }

    /**
     * Imports one source, in batches, until it's done or the time budget
     * runs out (then `$report->done` is false and `$report->cursor` says
     * where to continue).
     *
     * @param int $seconds Time budget; 0 for no limit (WP-CLI), -1 for one batch.
     */
    public static function run(string $source, bool $dryRun = false, int $after = 0, int $seconds = 0, int $batch = 500): Report|WP_Error
    {
        if (! in_array($source, self::SOURCES, true)) {
            return new WP_Error('ruvelo_cs_source', __('Choose “stcr” (Subscribe To Comments Reloaded) or “stc” (Subscribe to Comments).', 'ruvelo-comment-subscriptions'));
        }
        $report = new Report($source, $dryRun);
        $report->cursor = $after;
        $started = microtime(true);
        if ($source === 'stcr' && $after === 0) {
            self::disabledPosts($report, $dryRun);
        }

        while (true) {
            $rows = self::rows($source, $report->cursor, $batch);
            foreach ($rows as $row) {
                $report->cursor = (int) $row['meta_id'];
                $report->scanned++;
                $source === 'stcr' ? self::stcr($row, $report, $dryRun) : self::stc($row, $report, $dryRun);
            }
            if (count($rows) < $batch) {
                $report->done = true;

                break;
            }
            if ($seconds < 0 || ($seconds > 0 && microtime(true) - $started > $seconds)) {
                break;
            }
        }

        /**
         * Fires after an import run (or a batch of one).
         *
         * @param Report $report
         */
        do_action('ruvelo_comment_subscriptions_imported', $report);

        return $report;
    }

    /**
     * Posts where the old plugin had subscriptions turned off stay off.
     */
    private static function disabledPosts(Report $report, bool $dryRun): void
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Reading another plugin's rows once.
        $ids = $wpdb->get_col($wpdb->prepare("SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value <> ''", 'stcr_disable_subscriptions'));
        foreach ($ids as $id) {
            $report->disabledPosts++;
            if (! $dryRun) {
                update_post_meta((int) $id, Subscriptions::DISABLED_META, 1);
            }
        }
    }

    /**
     * @return list<array{meta_id: int|string, post_id: int|string, meta_key: string, meta_value: string}>
     */
    private static function rows(string $source, int $after, int $batch): array
    {
        global $wpdb;

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Reading another plugin's rows once, in batches.
        if ($source === 'stcr') {
            $rows = $wpdb->get_results($wpdb->prepare("SELECT meta_id, post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE meta_key LIKE %s AND meta_id > %d ORDER BY meta_id ASC LIMIT %d", $wpdb->esc_like(self::STCR_PREFIX).'%', $after, $batch), ARRAY_A);
        } else {
            $rows = $wpdb->get_results($wpdb->prepare("SELECT meta_id, post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE meta_key IN (%s, %s, %s) AND meta_id > %d ORDER BY meta_id ASC LIMIT %d", self::STC_KEY, self::STC_PENDING[0], self::STC_PENDING[1], $after, $batch), ARRAY_A);
        }
        // phpcs:enable

        /** @var list<array{meta_id: int|string, post_id: int|string, meta_key: string, meta_value: string}> */
        return is_array($rows) ? array_values($rows) : [];
    }

    /**
     * @param array{meta_id: int|string, post_id: int|string, meta_key: string, meta_value: string} $row
     */
    private static function stcr(array $row, Report $report, bool $dryRun): void
    {
        $postId = (int) $row['post_id'];
        $email = self::email(substr((string) $row['meta_key'], strlen(self::STCR_PREFIX)));
        $parts = explode('|', (string) $row['meta_value'], 2);
        $date = trim($parts[0]);
        $status = strtoupper(trim($parts[1] ?? ''));

        if ($status !== '' && str_ends_with($status, 'C')) {
            $report->unconfirmed++;

            return;
        }
        $map = ['Y' => ['all', 'instant'], 'R' => ['replies', 'instant'], 'D' => ['all', 'daily']];
        if (! isset($map[$status])) {
            $report->unknown++;
            /* translators: 1: masked email, 2: post ID, 3: the old plugin's status code */
            $report->note(sprintf(__('%1$s on post %2$d: unknown status “%3$s”', 'ruvelo-comment-subscriptions'), Report::mask($email), $postId, sanitize_text_field($status)));

            return;
        }
        [$type, $frequency] = $map[$status];
        $created = preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $date) === 1 ? get_gmt_from_date($date) : current_time('mysql', true);

        self::add($postId, $email, $type, $frequency, $created, 'stcr', $report, $dryRun);
    }

    /**
     * @param array{meta_id: int|string, post_id: int|string, meta_key: string, meta_value: string} $row
     */
    private static function stc(array $row, Report $report, bool $dryRun): void
    {
        if (in_array($row['meta_key'], self::STC_PENDING, true)) {
            $report->unconfirmed++;

            return;
        }
        self::add((int) $row['post_id'], self::email((string) $row['meta_value']), 'all', 'instant', current_time('mysql', true), 'stc', $report, $dryRun);
    }

    private static function add(int $postId, string $email, string $type, string $frequency, string $created, string $source, Report $report, bool $dryRun): void
    {
        if (! is_email($email)) {
            $report->invalidEmail++;
            /* translators: %d: post ID */
            $report->note(sprintf(__('Post %d: an address that isn’t valid', 'ruvelo-comment-subscriptions'), $postId));

            return;
        }
        $post = get_post($postId);
        if ($post === null || in_array($post->post_status, ['trash', 'auto-draft'], true)) {
            $report->missingPost++;
            /* translators: 1: masked email, 2: post ID */
            $report->note(sprintf(__('%1$s: post %2$d no longer exists', 'ruvelo-comment-subscriptions'), Report::mask($email), $postId));

            return;
        }
        $existing = Subscriptions::findFor($postId, $email);
        if ($existing !== null && $existing->isActive()) {
            $report->exists++;

            return;
        }
        if (! $dryRun) {
            $result = Subscriptions::subscribe($postId, $email, $type, [
                'confirmed' => true,
                'force' => true,
                'source' => $source,
                'frequency' => $frequency,
                'created_at' => $created,
                'confirmed_at' => $created,
            ]);
            if ($result instanceof WP_Error) {
                $report->unknown++;
                $report->note(Report::mask($email).': '.$result->get_error_message());

                return;
            }
        }
        $report->imported++;
        $report->{$type}++;
        if ($frequency === 'daily') {
            $report->daily++;
        }
    }

    private static function email(string $raw): string
    {
        // The same clean-up the old plugin did before storing an address.
        $raw = (string) preg_replace('/(to|from|bcc|cc|content-transfer-encoding|content-type|mime-version):/i', '', $raw);

        return Subscriptions::normalize(wp_strip_all_tags($raw));
    }
}
