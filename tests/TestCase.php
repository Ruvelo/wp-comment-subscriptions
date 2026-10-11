<?php

declare(strict_types=1);

namespace Ruvelo\WP\CommentSubscriptions\Tests;

use Ruvelo\WP\CommentSubscriptions\Frontend\Route;
use Ruvelo\WP\CommentSubscriptions\Install;
use Ruvelo\WP\CommentSubscriptions\Notifications;
use Ruvelo\WP\CommentSubscriptions\Settings;
use Ruvelo\WP\CommentSubscriptions\Subscriptions;
use WP_UnitTestCase;

abstract class TestCase extends WP_UnitTestCase
{
    public function set_up(): void
    {
        parent::set_up();
        global $wpdb;
        // phpcs:disable WordPress.DB.DirectDatabaseQuery
        $wpdb->query('DELETE FROM '.Install::table());
        $wpdb->query('DELETE FROM '.Install::queueTable());
        $wpdb->query('DELETE FROM '.Install::digestTable());
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_%ruvelo\\_cs\\_%'");
        // phpcs:enable
        wp_cache_flush();
        delete_option(Settings::OPTION);
        delete_option(Notifications::DIGEST_DUE);
        Settings::flush();
        Install::grantCapabilities();
        Notifications::pause(false);
        Route::reset();
        reset_phpmailer_instance();
        // Like an SMTP plugin: PHP's mail() transport makes PHPMailer encode long headers.
        add_action('phpmailer_init', static function ($mailer): void {
            $mailer->Mailer = 'smtp';
        });
        $_POST = [];
        $_GET = [];
        $_REQUEST = [];
        $_SERVER['REMOTE_ADDR'] = '203.0.113.'.wp_rand(1, 250);
        unset($_SERVER['REQUEST_METHOD']);
    }

    protected function user(string $role = 'subscriber'): int
    {
        return self::factory()->user->create(['role' => $role]);
    }

    protected function admin(): int
    {
        return $this->user('administrator');
    }

    protected function post(array $args = []): int
    {
        return self::factory()->post->create($args + ['post_status' => 'publish', 'comment_status' => 'open']);
    }

    protected function comment(int $post, array $args = []): int
    {
        return self::factory()->comment->create($args + ['comment_post_ID' => $post, 'comment_approved' => 1, 'comment_author_email' => 'someone@example.com']);
    }

    /**
     * An active subscription, as if confirmed.
     */
    protected function subscribe(int $post, string $email, string $type = 'all', array $context = []): int
    {
        $result = Subscriptions::subscribe($post, $email, $type, $context + ['confirmed' => true]);
        $this->assertIsArray($result);

        return $result['subscription']->id;
    }

    /**
     * @param array<string, mixed> $settings
     */
    protected function settings(array $settings): void
    {
        Settings::update($settings);
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function sent(): array
    {
        return array_values(tests_retrieve_phpmailer_instance()->mock_sent);
    }

    /**
     * @return list<string>
     */
    protected function sentTo(): array
    {
        $to = array_map(static fn (array $mail): string => $mail['to'][0][0], $this->sent());
        sort($to);

        return $to;
    }

    protected function lastMail(): array
    {
        $sent = $this->sent();
        $this->assertNotEmpty($sent, 'No email was sent.');

        return end($sent);
    }

    /**
     * Pulls the first link of a kind out of an email body.
     */
    protected function link(string $body, string $view): string
    {
        $this->assertSame(1, preg_match('#href="([^"]*rcs='.$view.'[^"]*)"#', $body, $m), 'No '.$view.' link in the email.');

        return html_entity_decode($m[1]);
    }

    protected function tokenFrom(string $url): string
    {
        parse_str((string) wp_parse_url($url, PHP_URL_QUERY), $query);

        return (string) ($query['rcs_token'] ?? '');
    }
}
