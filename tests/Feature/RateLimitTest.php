<?php

declare(strict_types=1);

namespace Ruvelo\WP\CommentSubscriptions\Tests\Feature;

use Ruvelo\WP\CommentSubscriptions\Frontend\Forms;
use Ruvelo\WP\CommentSubscriptions\Tests\TestCase;
use WP_Error;

final class RateLimitTest extends TestCase
{
    public function test_one_ip_address_is_limited(): void
    {
        $this->settings(['ip_limit' => 3]);
        $post = $this->post();

        foreach (range(1, 3) as $i) {
            $this->assertSame('check-email', Forms::subscribeRequest($post, "person{$i}@example.com"));
        }
        $blocked = Forms::subscribeRequest($post, 'person4@example.com');

        $this->assertInstanceOf(WP_Error::class, $blocked);
        $this->assertSame(429, $blocked->get_error_data()['status']);

        $_SERVER['REMOTE_ADDR'] = '198.51.100.7';
        $this->assertSame('check-email', Forms::subscribeRequest($post, 'person5@example.com'));
    }

    public function test_one_address_gets_a_limited_number_of_emails(): void
    {
        $this->settings(['email_limit' => 2, 'ip_limit' => 0]);
        $posts = [$this->post(), $this->post(), $this->post()];

        foreach ($posts as $post) {
            // Same answer every time, so nobody learns the limit was hit.
            $this->assertSame('check-email', Forms::subscribeRequest($post, 'victim@example.com'));
        }

        $this->assertCount(2, $this->sent());
    }

    public function test_limits_can_be_turned_off_with_a_filter(): void
    {
        $this->settings(['ip_limit' => 1]);
        add_filter('ruvelo_comment_subscriptions_rate_limit', '__return_zero');
        $post = $this->post();

        $this->assertSame('check-email', Forms::subscribeRequest($post, 'a@example.com'));
        $this->assertSame('check-email', Forms::subscribeRequest($post, 'b@example.com'));
    }

    public function test_manage_link_requests_are_limited_and_never_reveal_anything(): void
    {
        $this->settings(['email_limit' => 1]);
        $this->subscribe($this->post(), 'known@example.com');

        $this->assertSame('link-sent', Forms::requestLink('known@example.com'));
        $this->assertSame('link-sent', Forms::requestLink('known@example.com'));
        $this->assertSame('link-sent', Forms::requestLink('stranger@example.com'));

        $this->assertSame(['known@example.com'], $this->sentTo());
        $this->assertStringContainsString('rcs=manage', $this->lastMail()['body']);
    }
}
