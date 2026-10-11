<?php

declare(strict_types=1);

namespace Ruvelo\WP\CommentSubscriptions\Tests\Feature;

use Ruvelo\WP\CommentSubscriptions\Notifications;
use Ruvelo\WP\CommentSubscriptions\Subscriptions;
use Ruvelo\WP\CommentSubscriptions\Tests\TestCase;

final class NotificationsTest extends TestCase
{
    public function test_an_approved_comment_is_queued_never_sent_inline(): void
    {
        $post = $this->post();
        $this->subscribe($post, 'follower@example.com');

        $this->comment($post);

        $this->assertSame([], $this->sent());
        $this->assertSame(1, Notifications::pending());
        $this->assertNotFalse(wp_next_scheduled(Notifications::SEND_HOOK));

        Notifications::run();
        $this->assertSame(['follower@example.com'], $this->sentTo());
        $this->assertSame(0, Notifications::pending());
    }

    public function test_held_and_spam_comments_are_never_sent(): void
    {
        $post = $this->post();
        $this->subscribe($post, 'follower@example.com');

        $this->comment($post, ['comment_approved' => 0]);
        $this->comment($post, ['comment_approved' => 'spam']);
        $this->comment($post, ['comment_approved' => 'trash']);
        Notifications::run();

        $this->assertSame(0, Notifications::pending());
        $this->assertSame([], $this->sent());
    }

    public function test_approving_a_held_comment_sends_it_once(): void
    {
        $post = $this->post();
        $this->subscribe($post, 'follower@example.com');
        $comment = $this->comment($post, ['comment_approved' => 0]);

        wp_set_comment_status($comment, 'approve');
        wp_set_comment_status($comment, 'hold');
        wp_set_comment_status($comment, 'approve');
        Notifications::run();

        $this->assertSame(['follower@example.com'], $this->sentTo());
    }

    public function test_a_comment_marked_as_spam_after_queueing_is_dropped(): void
    {
        $post = $this->post();
        $this->subscribe($post, 'follower@example.com');
        $comment = $this->comment($post);

        wp_spam_comment($comment);
        Notifications::run();

        $this->assertSame([], $this->sent());
        $this->assertSame(0, Notifications::pending());
    }

    public function test_nobody_hears_about_their_own_comment(): void
    {
        $post = $this->post();
        $this->subscribe($post, 'me@example.com');
        $this->subscribe($post, 'you@example.com');

        $this->comment($post, ['comment_author_email' => 'ME@example.com']);
        Notifications::run();

        $this->assertSame(['you@example.com'], $this->sentTo());
    }

    public function test_replies_subscribers_hear_only_about_replies_to_their_comments(): void
    {
        $post = $this->post();
        $this->subscribe($post, 'asker@example.com', 'replies');
        $mine = $this->comment($post, ['comment_author_email' => 'asker@example.com']);
        $other = $this->comment($post, ['comment_author_email' => 'other@example.com']);
        Notifications::run();
        reset_phpmailer_instance();

        $this->comment($post, ['comment_parent' => $other, 'comment_author_email' => 'x@example.com']);
        Notifications::run();
        $this->assertSame([], $this->sent(), 'Not a reply to them.');

        $reply = $this->comment($post, ['comment_parent' => $mine, 'comment_author_email' => 'y@example.com', 'comment_author' => 'Maya Okafor']);
        $this->comment($post, ['comment_parent' => $reply, 'comment_author_email' => 'z@example.com']);
        Notifications::run();

        $this->assertSame(['asker@example.com', 'asker@example.com'], $this->sentTo(), 'Direct and deeper replies.');
        $this->assertStringContainsString('replied to you', $this->sent()[0]['subject']);
    }

    public function test_notes_and_pingbacks_are_not_sent(): void
    {
        $post = $this->post();
        $this->subscribe($post, 'follower@example.com');

        $this->comment($post, ['comment_type' => 'note']);
        $this->comment($post, ['comment_type' => 'pingback']);
        Notifications::run();

        $this->assertSame([], $this->sent());
    }

    public function test_emails_go_out_in_batches(): void
    {
        $post = $this->post();
        foreach (range(1, 7) as $i) {
            $this->subscribe($post, "f{$i}@example.com");
        }
        $this->comment($post);

        $this->assertSame(3, Notifications::run(3));
        $this->assertCount(3, $this->sent());
        $this->assertSame(1, Notifications::pending());
        $this->assertNotFalse(wp_next_scheduled(Notifications::SEND_HOOK));

        Notifications::run(3);
        Notifications::run(3);
        $this->assertCount(7, $this->sent());
        $this->assertSame(0, Notifications::pending());
    }

    public function test_the_email_is_html_with_a_text_part_and_one_click_unsubscribe(): void
    {
        $post = $this->post(['post_title' => 'Usage-based billing is here']);
        $this->subscribe($post, 'follower@example.com');
        $this->comment($post, ['comment_author' => 'Tom Reyes', 'comment_content' => 'Does metering work with <b>existing</b> price IDs?']);
        Notifications::run();

        $mail = $this->lastMail();
        $this->assertStringContainsString('Tom Reyes commented on “Usage-based billing is here”', $mail['subject']);
        $this->assertStringContainsString('Content-Type: multipart/alternative', $mail['header']);
        $this->assertStringContainsString('<html', $mail['body']);
        $this->assertMatchesRegularExpression('#List-Unsubscribe: <https?://[^>]+rcs=unsubscribe[^>]+>#', $mail['header']);
        $this->assertStringContainsString('List-Unsubscribe-Post: List-Unsubscribe=One-Click', $mail['header']);
        $this->assertStringContainsString('Does metering work with existing price IDs?', $mail['body']);
        $this->assertStringNotContainsString('<b>existing</b>', $mail['body'], 'The comment’s HTML never reaches the email.');
        $this->assertStringContainsString('Read and reply: ', tests_retrieve_phpmailer_instance()->AltBody);
    }

    public function test_daily_subscribers_get_one_digest(): void
    {
        $post = $this->post();
        $other = $this->post();
        $this->subscribe($post, 'daily@example.com', 'all', ['frequency' => 'daily']);
        $this->subscribe($other, 'daily@example.com', 'all', ['frequency' => 'daily']);
        $this->subscribe($post, 'instant@example.com');

        $this->comment($post);
        $this->comment($post);
        $this->comment($other);
        Notifications::run();
        $this->assertSame(['instant@example.com', 'instant@example.com'], $this->sentTo());
        $this->assertSame(1, Notifications::digestsWaiting());
        reset_phpmailer_instance();

        Notifications::daily();

        $this->assertSame(['daily@example.com'], $this->sentTo());
        $this->assertStringContainsString('3 new comments', $this->lastMail()['subject']);
        $this->assertStringContainsString('List-Unsubscribe-Post', $this->lastMail()['header']);
        $this->assertSame(0, Notifications::digestsWaiting());
    }

    public function test_a_digest_drops_comments_that_were_unapproved_since(): void
    {
        $post = $this->post();
        $this->subscribe($post, 'daily@example.com', 'all', ['frequency' => 'daily']);
        $keep = $this->comment($post);
        $drop = $this->comment($post);
        Notifications::run();
        wp_set_comment_status($drop, 'hold');

        Notifications::daily();

        $this->assertStringContainsString('1 new comment ', $this->lastMail()['subject']);
        $this->assertStringContainsString((string) get_comment_link($keep), $this->lastMail()['body']);
    }

    public function test_inbox_delivery_for_signed_in_users_who_choose_it(): void
    {
        $received = [];
        add_action('ruvelo_inbox_notify', static function (array $users, array $message) use (&$received): void {
            $received[] = [$users, $message];
        }, 10, 2);
        $post = $this->post();
        $user = $this->user();
        update_user_meta($user, 'ruvelo_comment_subscriptions_delivery', 'inbox');
        $this->subscribe($post, get_userdata($user)->user_email, 'all', ['user_id' => $user]);

        $this->comment($post, ['comment_author' => 'Kenji Mori']);
        Notifications::run();

        $this->assertSame([], $this->sent());
        $this->assertCount(1, $received);
        $this->assertSame([$user], $received[0][0]);
        $this->assertStringContainsString('Kenji Mori commented', $received[0][1]['title']);
    }

    public function test_a_failing_inbox_falls_back_to_email(): void
    {
        add_action('ruvelo_inbox_notify', static function (): void {
            throw new \RuntimeException('Inbox is down');
        });
        $post = $this->post();
        $user = $this->user();
        update_user_meta($user, 'ruvelo_comment_subscriptions_delivery', 'inbox');
        $this->subscribe($post, get_userdata($user)->user_email, 'all', ['user_id' => $user]);

        $this->comment($post);
        Notifications::run();

        $this->assertSame([get_userdata($user)->user_email], $this->sentTo());
    }

    public function test_without_an_inbox_everything_is_email(): void
    {
        $post = $this->post();
        $user = $this->user();
        update_user_meta($user, 'ruvelo_comment_subscriptions_delivery', 'inbox');
        $this->subscribe($post, get_userdata($user)->user_email, 'all', ['user_id' => $user]);

        $this->comment($post);
        Notifications::run();

        $this->assertCount(1, $this->sent());
    }

    public function test_deleting_a_post_deletes_its_subscriptions(): void
    {
        $post = $this->post();
        $id = $this->subscribe($post, 'follower@example.com');

        wp_delete_post($post, true);

        $this->assertNull(Subscriptions::find($id));
    }
}
