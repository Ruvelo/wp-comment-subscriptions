<?php

declare(strict_types=1);

namespace Ruvelo\WP\CommentSubscriptions;

use Ruvelo\WP\CommentSubscriptions\Import\Importer;
use WP_Comment;

/**
 * Halyard's blog, for the Playground demo and screenshots:
 * `wp ruvelo-comment-subscriptions demo --yes`. Halyard is a made-up B2B
 * billing platform; Maya Okafor runs product. No email is sent.
 */
final class Demo
{
    public const GUESTS = [
        'Sam Patel' => 'sam@patel.example',
        'Lena Fischer' => 'lena.fischer@example.org',
        'Ravi Shah' => 'ravi.shah@example.net',
        'Ana Costa' => 'ana@costa.example',
        'Jonas Berg' => 'jonas@berg.example',
    ];

    /** @var array<string, int> name => user id */
    private static array $people = [];

    /**
     * @return array{posts: int, comments: int, subscriptions: int, stcr: int}
     */
    public static function seed(): array
    {
        $content = require RUVELO_COMMENT_SUBSCRIPTIONS_DIR.'demo/content.php';
        if (! is_array($content)) {
            return ['posts' => 0, 'comments' => 0, 'subscriptions' => 0, 'stcr' => 0];
        }

        Notifications::pause(true);
        Install::install();
        update_option('blogname', 'Halyard');
        update_option('blogdescription', 'Billing for B2B software');
        update_option('comment_moderation', 0);
        update_option('comment_previously_approved', 0);
        update_option('thread_comments', 1);
        update_option('thread_comments_depth', 3);
        update_option('require_name_email', 1);
        update_option('show_avatars', 0);

        foreach (['Maya Okafor' => 'administrator', 'Tom Reyes' => 'author', 'Inès Laurent' => 'subscriber', 'Kenji Mori' => 'subscriber'] as $name => $role) {
            self::person($name, $role);
        }

        $posts = [];
        foreach ($content['posts'] as $key => $post) {
            $posts[$key] = self::post((string) $post['title'], (string) $post['content'], (float) $post['days']);
        }
        $usage = $posts['usage'];
        // The newest post goes on top of the blog, with the conversation.
        $hello = get_page_by_path('hello-world', OBJECT, 'post');
        if ($hello !== null) {
            wp_delete_post($hello->ID, true);
        }

        $comments = [];
        foreach ($content['comments'] as $row) {
            [$key, $author, $hours, $text, $parent, $status] = $row;
            $post = $posts[$row[6] ?? 'usage'];
            $comments[$key] = self::comment($post, (string) $author, (float) $hours, (string) $text, $parent !== null ? ($comments[$parent] ?? 0) : 0, $status);
        }

        $subscriptions = 0;
        foreach ($content['subscriptions'] as [$postKey, $who, $type, $frequency, $status, $source, $days]) {
            $subscriptions += self::subscribe($posts[$postKey], (string) $who, (string) $type, (string) $frequency, $status === 'active', (string) $source, (float) $days) ? 1 : 0;
        }

        // What went out as the thread grew, so the numbers on the Subscribers screen aren't all zero.
        self::history($usage);

        $stcr = 0;
        foreach ($content['stcr'] as [$postKey, $email, $days, $code]) {
            $postId = is_int($postKey) ? $postKey : $posts[$postKey];
            $date = wp_date('Y-m-d H:i:s', time() - (int) round((float) $days * DAY_IN_SECONDS));
            add_post_meta($postId, Importer::STCR_PREFIX.$email, $date.'|'.$code);
            $stcr++;
        }

        Notifications::pause(false);

        return ['posts' => count($posts), 'comments' => count($comments), 'subscriptions' => $subscriptions, 'stcr' => $stcr];
    }

    public static function person(string $name, string $role = 'subscriber'): int
    {
        if (isset(self::$people[$name])) {
            return self::$people[$name];
        }
        $login = self::slug($name);
        $user = get_user_by('login', $login);
        if ($user === false && $name === 'Maya Okafor') {
            // The site's first administrator plays Maya.
            $admins = get_users(['role' => 'administrator', 'number' => 1, 'orderby' => 'ID']);
            $user = $admins[0] ?? false;
        }
        if ($user !== false) {
            if ($user->display_name !== $name) {
                wp_update_user(['ID' => $user->ID, 'display_name' => $name, 'first_name' => strtok($name, ' ') ?: $name, 'last_name' => trim((string) strstr($name, ' '))]);
            }

            return self::$people[$name] = $user->ID;
        }
        $id = wp_insert_user([
            'user_login' => $login,
            'user_email' => str_replace(' ', '.', $login).'@halyard.test',
            'user_pass' => wp_generate_password(24),
            'display_name' => $name,
            'first_name' => strtok($name, ' ') ?: $name,
            'last_name' => trim((string) strstr($name, ' ')),
            'role' => $role,
        ]);

        return self::$people[$name] = is_int($id) ? $id : 0;
    }

    private static function post(string $title, string $content, float $days): int
    {
        $existing = get_page_by_path(sanitize_title($title), OBJECT, 'post');
        if ($existing !== null) {
            return $existing->ID;
        }
        $date = gmdate('Y-m-d H:i:s', time() - (int) round($days * DAY_IN_SECONDS));
        $id = wp_insert_post([
            'post_type' => 'post',
            'post_status' => 'publish',
            'post_title' => $title,
            'post_content' => $content,
            'post_author' => self::person('Maya Okafor'),
            'post_date' => get_date_from_gmt($date),
            'post_date_gmt' => $date,
            'comment_status' => 'open',
        ]);

        return is_int($id) ? $id : 0;
    }

    private static function comment(int $post, string $author, float $hours, string $text, int $parent, int|string $status): int
    {
        $userId = isset(self::$people[$author]) ? self::$people[$author] : 0;
        $user = $userId > 0 ? get_userdata($userId) : false;
        $date = gmdate('Y-m-d H:i:s', time() - (int) round($hours * HOUR_IN_SECONDS));
        $id = wp_insert_comment([
            'comment_post_ID' => $post,
            'comment_author' => $author,
            'comment_author_email' => $user !== false ? $user->user_email : (self::GUESTS[$author] ?? ''),
            'comment_content' => $text,
            'comment_parent' => $parent,
            'user_id' => $user !== false ? $user->ID : 0,
            'comment_date' => get_date_from_gmt($date),
            'comment_date_gmt' => $date,
            'comment_approved' => $status,
        ]);

        return is_int($id) ? $id : 0;
    }

    private static function subscribe(int $post, string $who, string $type, string $frequency, bool $active, string $source, float $days): bool
    {
        $userId = self::$people[$who] ?? 0;
        $user = $userId > 0 ? get_userdata($userId) : false;
        $email = $user !== false ? $user->user_email : $who;
        $date = gmdate('Y-m-d H:i:s', time() - (int) round($days * DAY_IN_SECONDS));
        $result = Subscriptions::subscribe($post, $email, $type, [
            'user_id' => $user !== false ? $user->ID : 0,
            'confirmed' => $active,
            'force' => true,
            'source' => $source,
            'frequency' => $frequency,
            'created_at' => $date,
            'confirmed_at' => gmdate('Y-m-d H:i:s', strtotime($date.' UTC') + 600),
        ]);

        return is_array($result);
    }

    /**
     * Records, for each approved comment on the post, how many subscribers
     * it went to: the queue rows the Subscribers screen counts.
     */
    private static function history(int $postId): void
    {
        global $wpdb;

        $comments = get_comments(['post_id' => $postId, 'status' => 'approve', 'order' => 'ASC']);
        $subscriptions = Subscriptions::forPost($postId);
        foreach (is_array($comments) ? $comments : [] as $comment) {
            if (! $comment instanceof WP_Comment) {
                continue;
            }
            $sent = 0;
            foreach ($subscriptions as $subscription) {
                $sent += Notifications::wants($subscription, $comment) ? 1 : 0;
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Demo seeding.
            $wpdb->replace(Install::queueTable(), [
                'comment_id' => (int) $comment->comment_ID,
                'post_id' => $postId,
                'last_id' => PHP_INT_MAX,
                'sent' => $sent,
                'created_at' => $comment->comment_date_gmt,
                'done_at' => $comment->comment_date_gmt,
            ]);
        }
    }

    private static function slug(string $name): string
    {
        return sanitize_user(strtolower(remove_accents(str_replace(' ', '.', $name))), true);
    }
}
