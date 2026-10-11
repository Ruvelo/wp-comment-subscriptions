<?php

declare(strict_types=1);

namespace Ruvelo\WP\CommentSubscriptions\Tests\Feature;

use Ruvelo\WP\CommentSubscriptions\Admin\Admin;
use Ruvelo\WP\CommentSubscriptions\Admin\EmailsPage;
use Ruvelo\WP\CommentSubscriptions\Admin\ImportPage;
use Ruvelo\WP\CommentSubscriptions\Admin\Profile;
use Ruvelo\WP\CommentSubscriptions\Admin\SettingsPage;
use Ruvelo\WP\CommentSubscriptions\Admin\SubscribersPage;
use Ruvelo\WP\CommentSubscriptions\Import\Importer;
use Ruvelo\WP\CommentSubscriptions\Mailer;
use Ruvelo\WP\CommentSubscriptions\Subscriptions;
use Ruvelo\WP\CommentSubscriptions\Support\Redirect;
use Ruvelo\WP\CommentSubscriptions\Tests\TestCase;

final class AdminTest extends TestCase
{
    public function test_bulk_delete_needs_the_nonce_and_capability(): void
    {
        $id = $this->subscribe($this->post(), 'a@example.com');

        wp_set_current_user($this->user('editor'));
        $_REQUEST = $_POST = ['ids' => [(string) $id], '_wpnonce' => wp_create_nonce('bulk-subscriptions')];
        try {
            SubscribersPage::delete();
            $this->fail('An editor deleted a subscription.');
        } catch (\WPDieException) {
        }
        $this->assertNotNull(Subscriptions::find($id));

        wp_set_current_user($this->admin());
        $_REQUEST = $_POST = ['ids' => [(string) $id], '_wpnonce' => wp_create_nonce('bulk-subscriptions')];
        try {
            SubscribersPage::delete();
        } catch (Redirect $redirect) {
            $this->assertStringContainsString('count=1', $redirect->url);
        }
        $this->assertNull(Subscriptions::find($id));
    }

    public function test_the_subscribers_screen_renders_stats_and_search(): void
    {
        wp_set_current_user($this->admin());
        set_current_screen('comments_page_ruvelo-comment-subscriptions');
        $post = $this->post(['post_title' => 'Usage-based billing is here']);
        $this->subscribe($post, 'maya@example.com');
        $this->subscribe($this->post(), 'tom@example.com');
        $_GET = $_REQUEST = ['s' => 'usage-based'];

        ob_start();
        SubscribersPage::render();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('Active subscriptions', $html);
        $this->assertStringContainsString('maya@example.com', $html);
        $this->assertStringNotContainsString('tom@example.com</strong>', $html);
    }

    public function test_the_import_handler_saves_a_report(): void
    {
        wp_set_current_user($this->admin());
        $post = $this->post();
        add_post_meta($post, Importer::STCR_PREFIX.'old@example.com', '2026-01-01 10:00:00|Y');
        $_POST = $_REQUEST = ['source' => 'stcr', 'dry_run' => '1', '_wpnonce' => wp_create_nonce('ruvelo_cs_import')];

        try {
            ImportPage::handle();
        } catch (Redirect $redirect) {
            $this->assertStringContainsString('ruvelo_cs_report=1', $redirect->url);
        }
        $_GET = ['ruvelo_cs_report' => '1'];
        ob_start();
        ImportPage::render();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('Dry run: nothing was changed', $html);
        $this->assertStringContainsString('1 of 1 Subscribe To Comments Reloaded subscriptions would be imported', $html);
        $this->assertNull(Subscriptions::findFor($post, 'old@example.com'));
    }

    public function test_every_email_previews_without_sending(): void
    {
        wp_set_current_user($this->admin());
        foreach (Mailer::TEMPLATES as $template) {
            $_GET = ['email' => $template];
            ob_start();
            EmailsPage::render();
            $html = (string) ob_get_clean();
            $this->assertStringContainsString('srcdoc=', $html, $template);
            $this->assertStringContainsString('sandbox=""', $html);
            $sample = Mailer::sample($template);
            $this->assertNotSame('', $sample['subject']);
            $this->assertStringContainsString('</html>', $sample['html']);
            $this->assertNotSame('', $sample['text']);
        }
        $this->assertSame([], $this->sent());
    }

    public function test_settings_are_sanitized(): void
    {
        $clean = SettingsPage::sanitize(['checked' => 'everything', 'digest_hour' => '99', 'pending_days' => '-4', 'reply_to' => 'not an email', 'offer_all' => '1', 'color_scheme' => 'dark']);

        $this->assertSame('', $clean['checked']);
        $this->assertSame(23, $clean['digest_hour']);
        $this->assertSame(1, $clean['pending_days']);
        $this->assertSame('', $clean['reply_to']);
        $this->assertTrue($clean['offer_all']);
        $this->assertFalse($clean['offer_replies']);
        $this->assertSame('dark', $clean['color_scheme']);
    }

    public function test_the_tabs_for_managers_and_administrators(): void
    {
        wp_set_current_user($this->admin());
        $this->assertArrayHasKey('settings', Admin::tabs());
    }

    public function test_users_unsubscribe_from_their_profile(): void
    {
        $user = $this->user();
        wp_set_current_user($user);
        $mine = $this->subscribe($this->post(), get_userdata($user)->user_email, 'all', ['user_id' => $user]);
        $theirs = $this->subscribe($this->post(), 'someone@example.com');
        $_POST = ['_ruvelo_cs_profile_nonce' => wp_create_nonce('ruvelo_cs_profile_'.$user), 'ruvelo_cs_unsubscribe' => [(string) $mine, (string) $theirs], 'ruvelo_cs_frequency' => 'daily'];

        Profile::save($user);

        $this->assertNull(Subscriptions::find($mine));
        $this->assertNotNull(Subscriptions::find($theirs), 'Only their own.');
    }
}
