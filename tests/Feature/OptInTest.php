<?php

declare(strict_types=1);

namespace Ruvelo\WP\CommentSubscriptions\Tests\Feature;

use Ruvelo\WP\CommentSubscriptions\Frontend\CommentForm;
use Ruvelo\WP\CommentSubscriptions\Frontend\Forms;
use Ruvelo\WP\CommentSubscriptions\Install;
use Ruvelo\WP\CommentSubscriptions\Subscriptions;
use Ruvelo\WP\CommentSubscriptions\Tests\TestCase;
use Ruvelo\WP\CommentSubscriptions\Tokens;
use WP_Error;

final class OptInTest extends TestCase
{
    public function test_a_guest_waits_for_confirmation_and_only_the_token_hash_is_stored(): void
    {
        $post = $this->post();

        $result = Forms::subscribeRequest($post, 'Reader@Example.com');

        $this->assertSame('check-email', $result);
        $subscription = Subscriptions::findFor($post, 'reader@example.com');
        $this->assertNotNull($subscription);
        $this->assertSame('pending', $subscription->status);
        $this->assertSame(['reader@example.com'], $this->sentTo());

        $token = $this->tokenFrom($this->link($this->lastMail()['body'], 'confirm'));
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d', Install::table(), $subscription->id), ARRAY_A);
        $this->assertSame(Tokens::hash($token), $row['token_hash']);
        $this->assertStringNotContainsString($token, (string) wp_json_encode($row));
    }

    public function test_confirming_activates_once(): void
    {
        $post = $this->post();
        Forms::subscribeRequest($post, 'reader@example.com');
        $token = $this->tokenFrom($this->link($this->lastMail()['body'], 'confirm'));

        $confirmed = Subscriptions::confirm($token);

        $this->assertNotNull($confirmed);
        $this->assertTrue($confirmed->isActive());
        $this->assertNotNull($confirmed->confirmedAt);
        $this->assertNull(Subscriptions::confirm($token), 'A token works once.');
        $this->assertNull(Subscriptions::confirm('not-a-token'));
    }

    public function test_pending_requests_expire_and_are_deleted(): void
    {
        global $wpdb;
        $this->settings(['pending_days' => 3]);
        $post = $this->post();
        $result = Subscriptions::subscribe($post, 'late@example.com');
        $this->assertIsArray($result);
        $wpdb->update(Install::table(), ['created_at' => gmdate('Y-m-d H:i:s', time() - 4 * DAY_IN_SECONDS)], ['id' => $result['subscription']->id]);

        $this->assertNull(Subscriptions::confirm($result['token']));
        $this->assertSame(1, Subscriptions::prune());
        $this->assertNull(Subscriptions::find($result['subscription']->id));
    }

    public function test_a_new_request_replaces_the_old_token(): void
    {
        $post = $this->post();
        $first = Subscriptions::subscribe($post, 'twice@example.com');
        $second = Subscriptions::subscribe($post, 'twice@example.com');
        $this->assertIsArray($first);
        $this->assertIsArray($second);

        $this->assertNull(Subscriptions::confirm($first['token']));
        $this->assertNotNull(Subscriptions::confirm($second['token']));
    }

    public function test_signed_in_users_subscribe_directly(): void
    {
        $post = $this->post();
        $user = $this->user();
        wp_set_current_user($user);

        $this->assertSame('subscribed', Forms::subscribeRequest($post, 'ignored@example.com'));

        $subscription = Subscriptions::findForUser($post, $user);
        $this->assertNotNull($subscription);
        $this->assertTrue($subscription->isActive());
        $this->assertSame([], $this->sent(), 'No confirmation email for a signed-in user.');
    }

    public function test_the_answer_never_reveals_an_existing_subscription(): void
    {
        $post = $this->post();
        $this->subscribe($post, 'known@example.com');

        $this->assertSame('check-email', Forms::subscribeRequest($post, 'known@example.com'));
        $this->assertSame('check-email', Forms::subscribeRequest($post, 'unknown@example.com'));
        $this->assertSame(['unknown@example.com'], $this->sentTo(), 'Already-subscribed addresses get no email.');
    }

    public function test_the_honeypot_pretends_and_does_nothing(): void
    {
        $post = $this->post();

        $this->assertSame('check-email', Forms::subscribeRequest($post, 'bot@example.com', 'https://spam.example'));
        $this->assertNull(Subscriptions::findFor($post, 'bot@example.com'));
        $this->assertSame([], $this->sent());
    }

    public function test_closed_and_disabled_posts_refuse(): void
    {
        $closed = $this->post(['comment_status' => 'closed']);
        $off = $this->post();
        update_post_meta($off, Subscriptions::DISABLED_META, 1);
        $draft = $this->post(['post_status' => 'draft']);

        $this->assertInstanceOf(WP_Error::class, Forms::subscribeRequest($closed, 'a@example.com'));
        $this->assertInstanceOf(WP_Error::class, Forms::subscribeRequest($off, 'a@example.com'));
        $this->assertInstanceOf(WP_Error::class, Forms::subscribeRequest($draft, 'a@example.com'));
        $this->assertInstanceOf(WP_Error::class, Forms::subscribeRequest($this->post(), 'not an email'));
    }

    public function test_the_comment_form_subscribes_a_guest_after_confirmation(): void
    {
        $post = $this->post();
        $_POST[CommentForm::FIELD] = ['replies'];
        $comment = $this->comment($post, ['comment_author_email' => 'guest@example.com']);

        CommentForm::posted($comment, 1);

        $subscription = Subscriptions::findFor($post, 'guest@example.com');
        $this->assertNotNull($subscription);
        $this->assertSame('replies', $subscription->type);
        $this->assertSame('pending', $subscription->status);
        $this->assertSame(['guest@example.com'], $this->sentTo());
        $this->assertStringContainsString('rcs_msg=confirm-comment', CommentForm::redirect('https://example.org/post/#comment-1', get_comment($comment)));
    }

    public function test_all_wins_when_both_boxes_are_ticked(): void
    {
        $post = $this->post();
        $user = $this->user();
        wp_set_current_user($user);
        $_POST[CommentForm::FIELD] = ['replies', 'all'];
        $comment = $this->comment($post, ['user_id' => $user, 'comment_author_email' => get_userdata($user)->user_email]);

        CommentForm::posted($comment, 0); // Held for moderation: still subscribes.

        $subscription = Subscriptions::findForUser($post, $user);
        $this->assertNotNull($subscription);
        $this->assertSame('all', $subscription->type);
        $this->assertTrue($subscription->isActive());
    }

    public function test_spam_never_subscribes_and_unticked_boxes_do_nothing(): void
    {
        $post = $this->post();
        $comment = $this->comment($post, ['comment_author_email' => 'spammer@example.com', 'comment_approved' => 'spam']);
        $_POST[CommentForm::FIELD] = ['all'];
        CommentForm::posted($comment, 'spam');
        $_POST = [];
        $other = $this->comment($post, ['comment_author_email' => 'quiet@example.com']);
        CommentForm::posted($other, 1);

        $this->assertNull(Subscriptions::findFor($post, 'spammer@example.com'));
        $this->assertNull(Subscriptions::findFor($post, 'quiet@example.com'));
        $this->assertSame([], $this->sent());
    }

    public function test_the_comment_form_shows_both_options_unticked(): void
    {
        $post = $this->post();
        $GLOBALS['post'] = get_post($post);
        $html = CommentForm::field('<p class="form-submit"></p>');

        $this->assertStringContainsString('Notify me of replies to my comment', $html);
        $this->assertStringContainsString('Notify me of all new comments on this post', $html);
        $this->assertStringNotContainsString('checked', $html);
        $this->assertStringContainsString('<label for="ruvelo-cs-replies">', $html);
    }
}
