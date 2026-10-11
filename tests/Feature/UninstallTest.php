<?php

declare(strict_types=1);

namespace Ruvelo\WP\CommentSubscriptions\Tests\Feature;

use Ruvelo\WP\CommentSubscriptions\Install;
use Ruvelo\WP\CommentSubscriptions\Notifications;
use Ruvelo\WP\CommentSubscriptions\Settings;
use Ruvelo\WP\CommentSubscriptions\Tests\TestCase;
use Ruvelo\WP\CommentSubscriptions\Tokens;

/**
 * @runTestsInSeparateProcesses
 *
 * @preserveGlobalState disabled
 */
final class UninstallTest extends TestCase
{
    private function uninstall(): void
    {
        if (! defined('WP_UNINSTALL_PLUGIN')) {
            define('WP_UNINSTALL_PLUGIN', 'ruvelo-comment-subscriptions/ruvelo-comment-subscriptions.php');
        }
        // Let DROP TABLE reach the real tables.
        remove_filter('query', [$this, '_drop_temporary_tables']);
        remove_filter('query', [$this, '_create_temporary_tables']);
        include dirname(__DIR__, 2).'/uninstall.php';
    }

    private function tableExists(string $table): bool
    {
        global $wpdb;

        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    }

    public function test_by_default_subscriptions_stay_and_cron_is_cleared(): void
    {
        $post = $this->post();
        $this->subscribe($post, 'a@example.com');
        $this->comment($post);
        Notifications::scheduleDaily();
        $this->assertNotFalse(wp_next_scheduled(Notifications::SEND_HOOK));

        $this->uninstall();

        $this->assertFalse(wp_next_scheduled(Notifications::SEND_HOOK));
        $this->assertFalse(wp_next_scheduled(Notifications::DAILY_HOOK));
        $this->assertTrue($this->tableExists(Install::table()));
    }

    public function test_opting_in_removes_everything(): void
    {
        $post = $this->post();
        $this->subscribe($post, 'a@example.com');
        update_post_meta($post, '_ruvelo_cs_disabled', 1);
        $this->settings(['delete_data' => true]);

        $this->uninstall();

        $this->assertFalse($this->tableExists(Install::table()));
        $this->assertFalse($this->tableExists(Install::queueTable()));
        $this->assertFalse($this->tableExists(Install::digestTable()));
        $this->assertFalse(get_option(Settings::OPTION));
        $this->assertFalse(get_option(Tokens::SECRET_OPTION));
        $this->assertSame('', get_post_meta($post, '_ruvelo_cs_disabled', true));
        $this->assertFalse(get_role('administrator')->has_cap(Settings::CAP));
        $this->assertFalse(wp_next_scheduled(Notifications::DAILY_HOOK));

        Install::install(); // Put the tables back for the other tests.
    }
}
