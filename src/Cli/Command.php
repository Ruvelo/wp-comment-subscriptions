<?php

declare(strict_types=1);

namespace Ruvelo\WP\CommentSubscriptions\Cli;

use Ruvelo\WP\CommentSubscriptions\Demo;
use Ruvelo\WP\CommentSubscriptions\Import\Importer;
use Ruvelo\WP\CommentSubscriptions\Import\Report;
use Ruvelo\WP\CommentSubscriptions\Mailer;
use Ruvelo\WP\CommentSubscriptions\Notifications;
use Ruvelo\WP\CommentSubscriptions\Subscription;
use Ruvelo\WP\CommentSubscriptions\Subscriptions;
use Ruvelo\WP\CommentSubscriptions\Tokens;
use WP_CLI;
use WP_CLI\Utils;
use WP_Error;

/**
 * Manage comment subscriptions.
 *
 * ## EXAMPLES
 *
 *     wp ruvelo-comment-subscriptions list --post=42
 *     wp ruvelo-comment-subscriptions import stcr --dry-run
 *     wp ruvelo-comment-subscriptions send-test you@example.com --template=notification
 */
final class Command
{
    public const NAME = 'ruvelo-comment-subscriptions';

    public static function register(): void
    {
        WP_CLI::add_command(self::NAME.' list', [self::class, 'list']);
        WP_CLI::add_command(self::NAME.' add', [self::class, 'add']);
        WP_CLI::add_command(self::NAME.' delete', [self::class, 'delete']);
        WP_CLI::add_command(self::NAME.' import', [self::class, 'import']);
        WP_CLI::add_command(self::NAME.' prune', [self::class, 'prune']);
        WP_CLI::add_command(self::NAME.' send-test', [self::class, 'sendTest']);
        WP_CLI::add_command(self::NAME.' queue', QueueCommand::class);
        WP_CLI::add_command(self::NAME.' stats', [self::class, 'stats']);
        WP_CLI::add_command(self::NAME.' rotate-secret', [self::class, 'rotateSecret']);
        WP_CLI::add_command(self::NAME.' demo', [self::class, 'demo']);
    }

    /**
     * Lists subscriptions.
     *
     * ## OPTIONS
     *
     * [--post=<id>]
     * : Only this post's.
     *
     * [--email=<email>]
     * : Only this address's (or search for part of an address or a post title).
     *
     * [--status=<status>]
     * : active or pending.
     *
     * [--type=<type>]
     * : all or replies.
     *
     * [--per-page=<n>]
     * : How many.
     * ---
     * default: 100
     * ---
     *
     * [--format=<format>]
     * : table, csv, json, yaml, count or ids.
     * ---
     * default: table
     * ---
     *
     * ## EXAMPLES
     *
     *     wp ruvelo-comment-subscriptions list --post=42 --status=active
     *     wp ruvelo-comment-subscriptions list --email=maya@halyard.test --format=json
     *
     * @param list<string> $args
     * @param array<string, string> $assoc
     */
    public static function list(array $args = [], array $assoc = []): void
    {
        $result = Subscriptions::query([
            'post_id' => (int) ($assoc['post'] ?? 0),
            'search' => (string) ($assoc['email'] ?? ''),
            'status' => (string) ($assoc['status'] ?? ''),
            'type' => (string) ($assoc['type'] ?? ''),
            'per_page' => (int) ($assoc['per-page'] ?? 100),
        ]);
        $format = $assoc['format'] ?? 'table';
        if ($format === 'count') {
            WP_CLI::line((string) $result['total']);

            return;
        }
        if ($format === 'ids') {
            WP_CLI::line(implode(' ', array_map(static fn (Subscription $s): int => $s->id, $result['items'])));

            return;
        }
        $rows = array_map(static fn (Subscription $s): array => [
            'id' => $s->id,
            'post_id' => $s->postId,
            'post' => wp_specialchars_decode(get_the_title($s->postId), ENT_QUOTES),
            'email' => $s->email,
            'type' => $s->type,
            'status' => $s->status,
            'frequency' => $s->frequency,
            'source' => $s->source,
            'created_at' => $s->createdAt,
        ], $result['items']);
        Utils\format_items($format, $rows, ['id', 'post_id', 'post', 'email', 'type', 'status', 'frequency', 'source', 'created_at']);
    }

    /**
     * Subscribes an address to a post's comments.
     *
     * Without --confirmed, the address gets a confirmation email first,
     * exactly as if it had used the form.
     *
     * ## OPTIONS
     *
     * <post>
     * : The post ID.
     *
     * <email>
     * : The address.
     *
     * [--type=<type>]
     * : all or replies.
     * ---
     * default: all
     * ---
     *
     * [--frequency=<frequency>]
     * : instant or daily.
     *
     * [--confirmed]
     * : Skip the confirmation email (only when you have the person's consent).
     *
     * ## EXAMPLES
     *
     *     wp ruvelo-comment-subscriptions add 42 tom@halyard.test --type=replies
     *
     * @param list<string> $args
     * @param array<string, string|bool> $assoc
     */
    public static function add(array $args, array $assoc = []): void
    {
        [$postId, $email] = [(int) ($args[0] ?? 0), (string) ($args[1] ?? '')];
        $confirmed = ! empty($assoc['confirmed']);
        $result = Subscriptions::subscribe($postId, $email, (string) ($assoc['type'] ?? 'all'), array_filter([
            'confirmed' => $confirmed,
            'source' => 'cli',
            'frequency' => isset($assoc['frequency']) ? (string) $assoc['frequency'] : null,
        ], static fn ($value): bool => $value !== null));
        if ($result instanceof WP_Error) {
            self::fail($result);
        }
        if ($result['status'] === 'exists') {
            WP_CLI::success(sprintf('%s already follows post %d (subscription %d).', $result['subscription']->email, $postId, $result['subscription']->id));

            return;
        }
        if ($result['status'] === 'pending') {
            Mailer::confirmation($result['subscription'], $result['token']);
            WP_CLI::success(sprintf('Subscription %d is waiting: a confirmation email went to %s.', $result['subscription']->id, $result['subscription']->email));

            return;
        }
        WP_CLI::success(sprintf('Subscription %d is active.', $result['subscription']->id));
    }

    /**
     * Deletes subscriptions.
     *
     * ## OPTIONS
     *
     * [<id>...]
     * : Subscription IDs.
     *
     * [--email=<email>]
     * : Every subscription this address has.
     *
     * ## EXAMPLES
     *
     *     wp ruvelo-comment-subscriptions delete 12 13
     *     wp ruvelo-comment-subscriptions delete --email=old@example.com
     *
     * @param list<string> $args
     * @param array<string, string> $assoc
     */
    public static function delete(array $args = [], array $assoc = []): void
    {
        $count = 0;
        foreach ($args as $id) {
            $count += Subscriptions::delete((int) $id, 'admin') ? 1 : 0;
        }
        if (isset($assoc['email'])) {
            $count += Subscriptions::deleteForEmail((string) $assoc['email'], 'admin');
        }
        if ($args === [] && ! isset($assoc['email'])) {
            self::stop('Give subscription IDs or --email.');
        }
        WP_CLI::success(sprintf('Deleted %d.', $count));
    }

    /**
     * Imports subscriptions from another plugin. The other plugin's data is
     * only read, never changed. Confirmed subscriptions stay confirmed; ones
     * never confirmed (or suspended) are left out.
     *
     * ## OPTIONS
     *
     * <source>
     * : stcr (Subscribe To Comments Reloaded) or stc (Subscribe to Comments).
     * ---
     * options:
     *   - stcr
     *   - stc
     * ---
     *
     * [--dry-run]
     * : Show what would be imported, without importing.
     *
     * [--format=<format>]
     * : table or json.
     * ---
     * default: table
     * ---
     *
     * ## EXAMPLES
     *
     *     wp ruvelo-comment-subscriptions import stcr --dry-run
     *     wp ruvelo-comment-subscriptions import stcr
     *
     * @param list<string> $args
     * @param array<string, string|bool> $assoc
     */
    public static function import(array $args, array $assoc = []): void
    {
        $source = (string) ($args[0] ?? '');
        $dryRun = ! empty($assoc['dry-run']);
        $report = Importer::run($source, $dryRun);
        if ($report instanceof WP_Error) {
            self::fail($report);
        }
        if (($assoc['format'] ?? 'table') === 'json') {
            WP_CLI::line((string) wp_json_encode($report->toArray()));

            return;
        }
        self::report($report);
    }

    public static function report(Report $report): void
    {
        $rows = [
            ['what' => 'Rows looked at', 'count' => $report->scanned],
            ['what' => $report->dryRun ? 'Would import' : 'Imported', 'count' => $report->imported],
            ['what' => '  all new comments', 'count' => $report->all],
            ['what' => '  replies only', 'count' => $report->replies],
            ['what' => '  daily digest', 'count' => $report->daily],
            ['what' => 'Already subscribed here', 'count' => $report->exists],
            ['what' => 'Never confirmed or suspended (skipped)', 'count' => $report->unconfirmed],
            ['what' => 'Invalid address (skipped)', 'count' => $report->invalidEmail],
            ['what' => 'Post gone (skipped)', 'count' => $report->missingPost],
            ['what' => 'Unknown status (skipped)', 'count' => $report->unknown],
            ['what' => 'Posts with subscriptions turned off', 'count' => $report->disabledPosts],
        ];
        Utils\format_items('table', $rows, ['what', 'count']);
        foreach ($report->notes as $note) {
            WP_CLI::log('  · '.$note);
        }
        $label = Importer::label($report->source);
        WP_CLI::success($report->dryRun
            ? sprintf('Dry run: %d of %d %s subscriptions would be imported. Nothing was changed.', $report->imported, $report->scanned, $label)
            : sprintf('Imported %d of %d %s subscriptions.', $report->imported, $report->scanned, $label));
    }

    /**
     * Deletes subscriptions nobody confirmed in time, and old queue rows.
     *
     * The daily job does this too.
     *
     * @param list<string> $args
     */
    public static function prune(array $args = []): void
    {
        $pending = Subscriptions::prune();
        $queue = Notifications::prune();
        WP_CLI::success(sprintf('Deleted %d expired requests and %d old queue rows.', $pending, $queue));
    }

    /**
     * Sends a sample email, to check how it looks and that mail is delivered.
     *
     * ## OPTIONS
     *
     * <email>
     * : Where to send it.
     *
     * [--template=<template>]
     * : confirm, notification, digest or manage-link.
     * ---
     * default: notification
     * ---
     *
     * ## EXAMPLES
     *
     *     wp ruvelo-comment-subscriptions send-test you@example.com --template=digest
     *
     * @param list<string> $args
     * @param array<string, string> $assoc
     */
    public static function sendTest(array $args, array $assoc = []): void
    {
        $email = sanitize_email((string) ($args[0] ?? ''));
        $template = (string) ($assoc['template'] ?? 'notification');
        if (! is_email($email)) {
            self::stop('Give a valid email address.');
        }
        if (! in_array($template, Mailer::TEMPLATES, true)) {
            self::stop('Choose one of: '.implode(', ', Mailer::TEMPLATES).'.');
        }
        if (! Mailer::sendSample($email, $template)) {
            self::stop('WordPress couldn’t send the email. Check your mail setup (an SMTP plugin, or the server’s mail).');
        }
        WP_CLI::success(sprintf('Sent the “%s” email to %s.', $template, $email));
    }

    /**
     * Shows subscription counts.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : table, json or yaml.
     * ---
     * default: table
     * ---
     *
     * @param list<string> $args
     * @param array<string, string> $assoc
     */
    public static function stats(array $args = [], array $assoc = []): void
    {
        $stats = Subscriptions::stats();
        $rows = [];
        foreach (['active', 'pending', 'subscribers', 'posts', 'daily', 'sent_30d'] as $key) {
            $rows[] = ['metric' => $key, 'value' => $stats[$key]];
        }
        $rows[] = ['metric' => 'queue', 'value' => Notifications::pending()];
        Utils\format_items($assoc['format'] ?? 'table', $rows, ['metric', 'value']);
    }

    /**
     * Replaces the secret that signs manage and unsubscribe links. Every link
     * already sent stops working: use it if you think links leaked.
     *
     * ## OPTIONS
     *
     * [--yes]
     * : Don't ask for confirmation.
     *
     * @param list<string> $args
     * @param array<string, string> $assoc
     */
    public static function rotateSecret(array $args = [], array $assoc = []): void
    {
        WP_CLI::confirm('Every manage and unsubscribe link sent so far will stop working. Continue?', $assoc);
        Tokens::rotate();
        WP_CLI::success('New secret in place.');
    }

    /**
     * Fills the site with Halyard's demo: a blog post with a lively comment
     * thread, subscribers, and old Subscribe To Comments Reloaded data to
     * import. For trying the plugin out.
     *
     * ## OPTIONS
     *
     * [--yes]
     * : Don't ask for confirmation.
     *
     * @param list<string> $args
     * @param array<string, string> $assoc
     */
    public static function demo(array $args = [], array $assoc = []): void
    {
        WP_CLI::confirm('This adds demo users, posts, comments and subscriptions to this site. Continue?', $assoc);
        $summary = Demo::seed();
        WP_CLI::success(sprintf('Demo ready: %d posts, %d comments, %d subscriptions, %d rows of Subscribe To Comments Reloaded data to import.', $summary['posts'], $summary['comments'], $summary['subscriptions'], $summary['stcr']));
    }

    public static function fail(WP_Error $error): never
    {
        self::stop($error->get_error_message());
    }

    public static function stop(string $message): never
    {
        WP_CLI::error($message);
        exit(1);
    }
}
