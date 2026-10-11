<?php

declare(strict_types=1);

namespace Ruvelo\WP\CommentSubscriptions;

use WP_Error;

/**
 * The one write path for subscriptions: the comment form, the standalone
 * form, the manage page, REST, WP-CLI, the importer and the admin all go
 * through here, so the rules hold everywhere and every hook fires.
 *
 * It doesn't check capabilities or rate limits; callers do.
 */
final class Subscriptions
{
    public const DISABLED_META = '_ruvelo_cs_disabled';

    /**
     * Subscribes an address to a post's comments.
     *
     * Without `confirmed`, a new subscription waits for the address to
     * confirm (double opt-in) and the result carries the raw token to email.
     * With it (a signed-in user, an import, an admin), it's active at once.
     *
     * @param array{user_id?: int, source?: string, frequency?: string, confirmed?: bool, created_at?: string, confirmed_at?: string, force?: bool} $context
     * @return array{status: string, subscription: Subscription, token: string}|WP_Error status is "active", "pending" or "exists".
     */
    public static function subscribe(int $postId, string $email, string $type = 'all', array $context = []): array|WP_Error
    {
        global $wpdb;

        $email = self::normalize($email);
        if (! is_email($email)) {
            return new WP_Error('ruvelo_cs_email', __('Enter a valid email address.', 'ruvelo-comment-subscriptions'), ['status' => 422]);
        }
        if (! in_array($type, Subscription::TYPES, true)) {
            return new WP_Error('ruvelo_cs_type', __('Choose all new comments or replies to your comments.', 'ruvelo-comment-subscriptions'), ['status' => 422]);
        }
        $open = self::canSubscribeTo($postId);
        if (empty($context['force']) && $open instanceof WP_Error) {
            return $open;
        }

        $confirmed = ! empty($context['confirmed']);
        $userId = (int) ($context['user_id'] ?? 0);
        if ($userId === 0) {
            $user = get_user_by('email', $email);
            // Link the row to an account only when that account is the one asking.
            $userId = $user !== false && $confirmed ? $user->ID : 0;
        }
        $frequency = in_array($context['frequency'] ?? '', Subscription::FREQUENCIES, true) ? (string) $context['frequency'] : self::frequencyFor($email);
        $existing = self::findFor($postId, $email);
        $now = current_time('mysql', true);

        if ($existing !== null && $existing->isActive()) {
            // Someone who proved it's their address can widen a subscription; nobody narrows one by accident.
            if ($confirmed && $type === 'all' && $existing->type === 'replies') {
                self::update($existing->id, ['type' => 'all']);
                $existing = self::find($existing->id) ?? $existing;
            }

            return ['status' => 'exists', 'subscription' => $existing, 'token' => ''];
        }

        $token = $confirmed ? '' : Tokens::random();
        $row = [
            'user_id' => $userId,
            'type' => $type,
            'status' => $confirmed ? 'active' : 'pending',
            'frequency' => $frequency,
            'token_hash' => $confirmed ? '' : Tokens::hash($token),
            'source' => sanitize_key($context['source'] ?? 'form'),
            'created_at' => isset($context['created_at']) && is_string($context['created_at']) ? $context['created_at'] : $now,
            'confirmed_at' => $confirmed ? (isset($context['confirmed_at']) && is_string($context['confirmed_at']) ? $context['confirmed_at'] : $now) : null,
        ];

        if ($existing !== null) {
            // Still pending: a new request replaces the old token, so only the newest link works.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
            $wpdb->update(Install::table(), $row, ['id' => $existing->id]);
            $id = $existing->id;
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
            $inserted = $wpdb->insert(Install::table(), ['post_id' => $postId, 'email' => $email] + $row);
            if ($inserted === false) {
                // Lost a race with an identical request: the unique index kept one row.
                $again = self::findFor($postId, $email);
                if ($again === null) {
                    return new WP_Error('ruvelo_cs_db', __('The subscription couldn’t be saved. Try again in a moment.', 'ruvelo-comment-subscriptions'), ['status' => 500]);
                }

                return ['status' => 'exists', 'subscription' => $again, 'token' => ''];
            }
            $id = (int) $wpdb->insert_id;
        }

        self::flush($postId);
        $subscription = self::find($id);
        if ($subscription === null) {
            return new WP_Error('ruvelo_cs_db', __('The subscription couldn’t be saved. Try again in a moment.', 'ruvelo-comment-subscriptions'), ['status' => 500]);
        }

        if ($confirmed) {
            /**
             * Fires when a subscription becomes active: straight away for
             * signed-in users and imports, or when a guest confirms.
             *
             * @param Subscription $subscription
             */
            do_action('ruvelo_comment_subscriptions_subscribed', $subscription);
        } else {
            /**
             * Fires when a guest asks to subscribe and a confirmation email is due.
             *
             * @param Subscription $subscription
             */
            do_action('ruvelo_comment_subscriptions_pending', $subscription);
        }

        return ['status' => $confirmed ? 'active' : 'pending', 'subscription' => $subscription, 'token' => $token];
    }

    /**
     * Confirms a pending subscription from the token in its email.
     */
    public static function confirm(string $token): ?Subscription
    {
        global $wpdb;

        $pending = self::pendingByToken($token);
        if ($pending === null) {
            return null;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
        $wpdb->update(Install::table(), ['status' => 'active', 'token_hash' => '', 'confirmed_at' => current_time('mysql', true)], ['id' => $pending->id, 'status' => 'pending']);
        self::flush($pending->postId);
        $subscription = self::find($pending->id);
        if ($subscription === null || ! $subscription->isActive()) {
            return null;
        }

        /** This action is documented in src/Subscriptions.php */
        do_action('ruvelo_comment_subscriptions_subscribed', $subscription);

        return $subscription;
    }

    /**
     * The pending subscription a confirmation token belongs to, if it hasn't expired.
     */
    public static function pendingByToken(string $token): ?Subscription
    {
        global $wpdb;

        $token = trim($token);
        if ($token === '' || strlen($token) > 64) {
            return null;
        }
        $table = Install::table();
        $since = gmdate('Y-m-d H:i:s', time() - self::pendingSeconds());
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE token_hash = %s AND status = %s AND created_at >= %s LIMIT 1', $table, Tokens::hash($token), 'pending', $since), ARRAY_A);

        return is_array($row) ? Subscription::fromRow($row) : null;
    }

    public static function pendingSeconds(): int
    {
        return max(1, Settings::int('pending_days')) * DAY_IN_SECONDS;
    }

    /**
     * Whether a post takes subscriptions: published, comments open, not turned off.
     */
    public static function canSubscribeTo(int $postId): true|WP_Error
    {
        $post = get_post($postId);
        if ($post === null || $post->post_status !== 'publish' || ! is_post_type_viewable($post->post_type)) {
            return new WP_Error('ruvelo_cs_post', __('That post doesn’t exist or isn’t public.', 'ruvelo-comment-subscriptions'), ['status' => 404]);
        }
        if (! comments_open($post) || self::disabledFor($postId)) {
            return new WP_Error('ruvelo_cs_closed', __('This post doesn’t take comment subscriptions.', 'ruvelo-comment-subscriptions'), ['status' => 409]);
        }

        return true;
    }

    public static function disabledFor(int $postId): bool
    {
        /**
         * Filters whether a post takes comment subscriptions.
         *
         * @param bool $disabled
         * @param int $postId
         */
        return (bool) apply_filters('ruvelo_comment_subscriptions_disabled', (bool) get_post_meta($postId, self::DISABLED_META, true), $postId);
    }

    public static function find(int $id): ?Subscription
    {
        global $wpdb;

        $table = Install::table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d', $table, $id), ARRAY_A);

        return is_array($row) ? Subscription::fromRow($row) : null;
    }

    public static function findFor(int $postId, string $email): ?Subscription
    {
        global $wpdb;

        $table = Install::table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE post_id = %d AND email = %s', $table, $postId, self::normalize($email)), ARRAY_A);

        return is_array($row) ? Subscription::fromRow($row) : null;
    }

    /**
     * A signed-in user's subscription to a post, by account or by address.
     */
    public static function findForUser(int $postId, int $userId): ?Subscription
    {
        foreach (self::forUser($userId) as $subscription) {
            if ($subscription->postId === $postId) {
                return $subscription;
            }
        }

        return null;
    }

    /**
     * @return list<Subscription>
     */
    public static function forEmail(string $email, ?string $status = 'active'): array
    {
        global $wpdb;

        $table = Install::table();
        $email = self::normalize($email);
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
        $rows = $status === null
            ? $wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE email = %s ORDER BY created_at DESC, id DESC', $table, $email), ARRAY_A)
            : $wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE email = %s AND status = %s ORDER BY created_at DESC, id DESC', $table, $email, $status), ARRAY_A);
        // phpcs:enable

        return self::map($rows);
    }

    /**
     * A signed-in user's active subscriptions: those made with their account,
     * and those made with their address before they signed up.
     *
     * @return list<Subscription>
     */
    public static function forUser(int $userId): array
    {
        global $wpdb;

        $user = get_userdata($userId);
        if ($user === false) {
            return [];
        }
        $table = Install::table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE (user_id = %d OR email = %s) AND status = %s ORDER BY created_at DESC, id DESC', $table, $userId, self::normalize($user->user_email), 'active'), ARRAY_A);

        return self::map($rows);
    }

    /**
     * @return list<Subscription>
     */
    public static function forPost(int $postId, ?string $status = 'active', int $limit = 500): array
    {
        global $wpdb;

        $table = Install::table();
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
        $rows = $status === null
            ? $wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE post_id = %d ORDER BY id ASC LIMIT %d', $table, $postId, $limit), ARRAY_A)
            : $wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE post_id = %d AND status = %s ORDER BY id ASC LIMIT %d', $table, $postId, $status, $limit), ARRAY_A);
        // phpcs:enable

        return self::map($rows);
    }

    /**
     * Changes a subscription's type or frequency.
     *
     * @param array{type?: string, frequency?: string} $changes
     */
    public static function update(int $id, array $changes): bool
    {
        global $wpdb;

        $data = [];
        if (isset($changes['type']) && in_array($changes['type'], Subscription::TYPES, true)) {
            $data['type'] = $changes['type'];
        }
        if (isset($changes['frequency']) && in_array($changes['frequency'], Subscription::FREQUENCIES, true)) {
            $data['frequency'] = $changes['frequency'];
        }
        $subscription = self::find($id);
        if ($data === [] || $subscription === null) {
            return false;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
        $wpdb->update(Install::table(), $data, ['id' => $id]);
        self::flush($subscription->postId);

        /**
         * Fires after a subscription's type or frequency changes.
         *
         * @param int $id
         * @param array<string, string> $data
         */
        do_action('ruvelo_comment_subscriptions_updated', $id, $data);

        return true;
    }

    /**
     * Sets how often an address hears: as comments arrive, or once a day.
     */
    public static function setFrequency(string $email, string $frequency): int
    {
        global $wpdb;

        if (! in_array($frequency, Subscription::FREQUENCIES, true)) {
            return 0;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
        $changed = $wpdb->update(Install::table(), ['frequency' => $frequency], ['email' => self::normalize($email)]);

        return is_int($changed) ? $changed : 0;
    }

    /**
     * The frequency an address already chose, for its next subscription.
     */
    public static function frequencyFor(string $email): string
    {
        global $wpdb;

        $table = Install::table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
        $frequency = $wpdb->get_var($wpdb->prepare('SELECT frequency FROM %i WHERE email = %s AND status = %s ORDER BY id DESC LIMIT 1', $table, self::normalize($email), 'active'));

        return is_string($frequency) && in_array($frequency, Subscription::FREQUENCIES, true) ? $frequency : Settings::frequency();
    }

    /**
     * Deletes one subscription. Unsubscribing deletes the row: nothing about
     * the address is kept once it stops following a post.
     *
     * @param string $reason "unsubscribe", "admin", "expired", "privacy", "post_deleted"…
     */
    public static function delete(int $id, string $reason = 'unsubscribe'): bool
    {
        global $wpdb;

        $subscription = self::find($id);
        if ($subscription === null) {
            return false;
        }
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables.
        $wpdb->delete(Install::table(), ['id' => $id], ['%d']);
        $wpdb->delete(Install::digestTable(), ['subscription_id' => $id], ['%d']);
        // phpcs:enable
        self::flush($subscription->postId);

        /**
         * Fires after a subscription is deleted.
         *
         * @param Subscription $subscription
         * @param string $reason "unsubscribe", "admin", "expired", "privacy", "post_deleted", "user_deleted"…
         */
        do_action('ruvelo_comment_subscriptions_deleted', $subscription, $reason);

        return true;
    }

    /**
     * Unsubscribes an address from everything.
     */
    public static function deleteForEmail(string $email, string $reason = 'unsubscribe'): int
    {
        $count = 0;
        foreach (self::forEmail($email, null) as $subscription) {
            $count += self::delete($subscription->id, $reason) ? 1 : 0;
        }

        return $count;
    }

    public static function deleteForPost(int $postId, string $reason = 'post_deleted'): int
    {
        $count = 0;
        do {
            $batch = self::forPost($postId, null, 200);
            foreach ($batch as $subscription) {
                $count += self::delete($subscription->id, $reason) ? 1 : 0;
            }
        } while (count($batch) === 200);

        return $count;
    }

    /**
     * Deletes pending subscriptions nobody confirmed in time.
     */
    public static function prune(): int
    {
        global $wpdb;

        $table = Install::table();
        $before = gmdate('Y-m-d H:i:s', time() - self::pendingSeconds());
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
        $ids = $wpdb->get_col($wpdb->prepare('SELECT id FROM %i WHERE status = %s AND created_at < %s LIMIT 1000', $table, 'pending', $before));
        $count = 0;
        foreach ($ids as $id) {
            $count += self::delete((int) $id, 'expired') ? 1 : 0;
        }

        return $count;
    }

    /**
     * A post's subscribers, by type and status.
     *
     * @return array{all: int, replies: int, pending: int, total: int}
     */
    public static function counts(int $postId): array
    {
        $counts = self::countsFor([$postId]);

        return $counts[$postId] ?? ['all' => 0, 'replies' => 0, 'pending' => 0, 'total' => 0];
    }

    /**
     * Counts for several posts in one query (for list screens).
     *
     * @param list<int> $postIds
     * @return array<int, array{all: int, replies: int, pending: int, total: int}>
     */
    public static function countsFor(array $postIds): array
    {
        global $wpdb;

        $postIds = array_values(array_unique(array_filter(array_map('intval', $postIds))));
        $out = [];
        $missing = [];
        foreach ($postIds as $id) {
            $cached = wp_cache_get('counts_'.$id, 'ruvelo_comment_subscriptions');
            if (is_array($cached)) {
                $out[$id] = $cached;
            } else {
                $missing[] = $id;
                $out[$id] = ['all' => 0, 'replies' => 0, 'pending' => 0, 'total' => 0];
            }
        }
        if ($missing !== []) {
            $table = Install::table();
            $placeholders = implode(',', array_fill(0, count($missing), '%d'));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Custom table; one %d per id.
            $rows = $wpdb->get_results($wpdb->prepare("SELECT post_id, type, status, COUNT(*) AS n FROM %i WHERE post_id IN ({$placeholders}) GROUP BY post_id, type, status", $table, ...$missing), ARRAY_A);
            foreach (is_array($rows) ? $rows : [] as $row) {
                $id = (int) $row['post_id'];
                $n = (int) $row['n'];
                if ($row['status'] === 'pending') {
                    $out[$id]['pending'] += $n;
                } else {
                    $out[$id][$row['type'] === 'replies' ? 'replies' : 'all'] += $n;
                    $out[$id]['total'] += $n;
                }
            }
            foreach ($missing as $id) {
                wp_cache_set('counts_'.$id, $out[$id], 'ruvelo_comment_subscriptions', HOUR_IN_SECONDS);
            }
        }

        return array_map(static fn (array $c): array => [
            'all' => (int) ($c['all'] ?? 0),
            'replies' => (int) ($c['replies'] ?? 0),
            'pending' => (int) ($c['pending'] ?? 0),
            'total' => (int) ($c['total'] ?? 0),
        ], $out);
    }

    /**
     * For the admin screen: search by address or post, filter, sort, page.
     *
     * @param array{search?: string, post_id?: int, status?: string, type?: string, orderby?: string, order?: string, page?: int, per_page?: int} $args
     * @return array{items: list<Subscription>, total: int}
     */
    public static function query(array $args = []): array
    {
        global $wpdb;

        $table = Install::table();
        $where = ['1=1'];
        $params = [];
        $search = trim((string) ($args['search'] ?? ''));
        if ($search !== '') {
            $like = '%'.$wpdb->esc_like(strtolower($search)).'%';
            $postIds = self::postsMatching($search);
            if ($postIds !== []) {
                $where[] = '(email LIKE %s OR post_id IN ('.implode(',', array_fill(0, count($postIds), '%d')).'))';
                $params = array_merge($params, [$like], $postIds);
            } else {
                $where[] = 'email LIKE %s';
                $params[] = $like;
            }
        }
        if (! empty($args['post_id'])) {
            $where[] = 'post_id = %d';
            $params[] = (int) $args['post_id'];
        }
        if (isset($args['status']) && in_array($args['status'], Subscription::STATUSES, true)) {
            $where[] = 'status = %s';
            $params[] = $args['status'];
        }
        if (isset($args['type']) && in_array($args['type'], Subscription::TYPES, true)) {
            $where[] = 'type = %s';
            $params[] = $args['type'];
        }
        $columns = ['email' => 'email', 'post' => 'post_id', 'created' => 'created_at', 'confirmed' => 'confirmed_at', 'type' => 'type', 'status' => 'status'];
        $orderby = $columns[$args['orderby'] ?? 'created'] ?? 'created_at';
        $order = strtolower((string) ($args['order'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';
        $perPage = max(1, min(500, (int) ($args['per_page'] ?? 20)));
        $offset = (max(1, (int) ($args['page'] ?? 1)) - 1) * $perPage;
        $sqlWhere = implode(' AND ', $where);

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Custom table; $sqlWhere is built from placeholders above, $orderby/$order from allow-lists.
        $total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i WHERE {$sqlWhere}", $table, ...$params)); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Built from placeholders above.
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM %i WHERE {$sqlWhere} ORDER BY {$orderby} {$order}, id DESC LIMIT %d OFFSET %d", $table, ...array_merge($params, [$perPage, $offset])), ARRAY_A); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Built from placeholders above.
        // phpcs:enable

        return ['items' => self::map($rows), 'total' => $total];
    }

    /**
     * Numbers for the admin screen, WP-CLI and stats.
     *
     * @return array{active: int, pending: int, subscribers: int, posts: int, daily: int, sent_30d: int, top: list<array{post_id: int, count: int}>}
     */
    public static function stats(): array
    {
        global $wpdb;

        $table = Install::table();
        $queue = Install::queueTable();
        $since = gmdate('Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS);
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables.
        $active = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE status = %s', $table, 'active'));
        $pending = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE status = %s', $table, 'pending'));
        $subscribers = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(DISTINCT email) FROM %i WHERE status = %s', $table, 'active'));
        $posts = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(DISTINCT post_id) FROM %i WHERE status = %s', $table, 'active'));
        $daily = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(DISTINCT email) FROM %i WHERE status = %s AND frequency = %s', $table, 'active', 'daily'));
        $sent = (int) $wpdb->get_var($wpdb->prepare('SELECT COALESCE(SUM(sent), 0) FROM %i WHERE created_at >= %s', $queue, $since));
        $top = $wpdb->get_results($wpdb->prepare('SELECT post_id, COUNT(*) AS n FROM %i WHERE status = %s GROUP BY post_id ORDER BY n DESC, post_id DESC LIMIT 5', $table, 'active'), ARRAY_A);
        // phpcs:enable

        return [
            'active' => $active,
            'pending' => $pending,
            'subscribers' => $subscribers,
            'posts' => $posts,
            'daily' => $daily,
            'sent_30d' => $sent,
            'top' => array_values(array_map(static fn (array $row): array => ['post_id' => (int) $row['post_id'], 'count' => (int) $row['n']], is_array($top) ? $top : [])),
        ];
    }

    /**
     * Keeps a signed-in user's rows pointing at their current address.
     */
    public static function emailChanged(int $userId, string $email): void
    {
        global $wpdb;

        $email = self::normalize($email);
        if (! is_email($email)) {
            return;
        }
        foreach (self::forUser($userId) as $subscription) {
            if ($subscription->email === $email) {
                continue;
            }
            if (self::findFor($subscription->postId, $email) !== null) {
                self::delete($subscription->id, 'merged'); // Already following with the new address.

                continue;
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
            $wpdb->update(Install::table(), ['email' => $email, 'user_id' => $userId], ['id' => $subscription->id]);
        }
    }

    public static function normalize(string $email): string
    {
        return strtolower(trim(sanitize_email($email)));
    }

    public static function flush(int $postId): void
    {
        wp_cache_delete('counts_'.$postId, 'ruvelo_comment_subscriptions');
    }

    /**
     * @return list<int>
     */
    private static function postsMatching(string $search): array
    {
        if (ctype_digit($search)) {
            return [(int) $search];
        }
        $ids = get_posts([
            'post_type' => 'any',
            'post_status' => 'any',
            's' => $search,
            'search_columns' => ['post_title'],
            'fields' => 'ids',
            'posts_per_page' => 50,
            'no_found_rows' => true,
        ]);

        return array_values(array_map('intval', $ids));
    }

    /**
     * @return list<Subscription>
     */
    private static function map(mixed $rows): array
    {
        $out = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (is_array($row)) {
                $out[] = Subscription::fromRow($row);
            }
        }

        return $out;
    }
}
