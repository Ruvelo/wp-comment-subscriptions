<?php

declare(strict_types=1);

namespace Ruvelo\WP\CommentSubscriptions\Tests\Feature;

use Ruvelo\WP\CommentSubscriptions\Notifications;
use Ruvelo\WP\CommentSubscriptions\Privacy;
use Ruvelo\WP\CommentSubscriptions\Subscriptions;
use Ruvelo\WP\CommentSubscriptions\Tests\TestCase;

final class PrivacyTest extends TestCase
{
    public function test_the_exporter_and_eraser_are_registered(): void
    {
        $this->assertArrayHasKey(Privacy::KEY, apply_filters('wp_privacy_personal_data_exporters', []));
        $this->assertArrayHasKey(Privacy::KEY, apply_filters('wp_privacy_personal_data_erasers', []));
    }

    public function test_export_lists_every_subscription_for_the_address(): void
    {
        $post = $this->post(['post_title' => 'Usage-based billing is here']);
        $this->subscribe($post, 'reader@example.com', 'replies');
        Subscriptions::subscribe($this->post(), 'reader@example.com');
        $this->subscribe($post, 'someone.else@example.com');

        $export = Privacy::export('reader@example.com');

        $this->assertCount(2, $export['data']);
        $this->assertTrue($export['done']);
        $values = wp_list_pluck($export['data'][0]['data'], 'value');
        $this->assertContains('reader@example.com', $values);
        $this->assertStringNotContainsString('someone.else', (string) wp_json_encode($export));
    }

    public function test_export_includes_an_accounts_subscriptions(): void
    {
        $user = $this->user();
        $email = get_userdata($user)->user_email;
        $this->subscribe($this->post(), $email, 'all', ['user_id' => $user]);

        $this->assertCount(1, Privacy::export($email)['data']);
    }

    public function test_erase_deletes_subscriptions_and_waiting_digests(): void
    {
        $post = $this->post();
        $this->subscribe($post, 'reader@example.com', 'all', ['frequency' => 'daily']);
        $keep = $this->subscribe($post, 'other@example.com');
        $this->comment($post);
        Notifications::run();
        $this->assertSame(1, Notifications::digestsWaiting());

        $result = Privacy::erase('reader@example.com');

        $this->assertTrue($result['items_removed']);
        $this->assertFalse($result['items_retained']);
        $this->assertSame([], Subscriptions::forEmail('reader@example.com', null));
        $this->assertSame(0, Notifications::digestsWaiting());
        $this->assertNotNull(Subscriptions::find($keep));
    }

    public function test_erasing_an_unknown_address_removes_nothing(): void
    {
        $this->assertFalse(Privacy::erase('nobody@example.com')['items_removed']);
    }
}
