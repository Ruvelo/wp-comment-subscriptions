<?php

declare(strict_types=1);

namespace Ruvelo\WP\CommentSubscriptions\Tests\Feature;

use Ruvelo\WP\CommentSubscriptions\Frontend\Forms;
use Ruvelo\WP\CommentSubscriptions\Frontend\Route;
use Ruvelo\WP\CommentSubscriptions\Frontend\Views;
use Ruvelo\WP\CommentSubscriptions\Notifications;
use Ruvelo\WP\CommentSubscriptions\Subscriptions;
use Ruvelo\WP\CommentSubscriptions\Support\Redirect;
use Ruvelo\WP\CommentSubscriptions\Tests\TestCase;
use Ruvelo\WP\CommentSubscriptions\Tokens;
use Ruvelo\WP\CommentSubscriptions\Urls;

final class UnsubscribeTest extends TestCase
{
    private function onRoute(string $method, array $get, array $post = []): ?Redirect
    {
        set_query_var(Urls::QUERY_VAR, '1');
        $_SERVER['REQUEST_METHOD'] = $method;
        $_GET = $get;
        $_POST = $post;
        Route::reset();
        try {
            Route::handle();
        } catch (Redirect $redirect) {
            return $redirect;
        }

        return null;
    }

    private function unsubscribeUrlFromEmail(int $post, string $email): string
    {
        $this->comment($post, ['comment_author_email' => 'author@example.com']);
        Notifications::run();
        $header = $this->lastMail()['header'];
        $this->assertSame(1, preg_match('#List-Unsubscribe: <([^>]+)>#', $header, $m));

        return $m[1];
    }

    public function test_one_click_post_unsubscribes_without_a_page(): void
    {
        $post = $this->post();
        $id = $this->subscribe($post, 'follower@example.com');
        $url = $this->unsubscribeUrlFromEmail($post, 'follower@example.com');

        $done = $this->onRoute('POST', ['rcs' => 'unsubscribe', 'rcs_token' => $this->tokenFrom($url)], ['List-Unsubscribe' => 'One-Click']);

        $this->assertNotNull($done);
        $this->assertSame(200, $done->status);
        $this->assertNull(Subscriptions::find($id));
    }

    public function test_get_only_asks(): void
    {
        $post = $this->post();
        $id = $this->subscribe($post, 'follower@example.com');
        $token = $this->tokenFrom($this->unsubscribeUrlFromEmail($post, 'follower@example.com'));

        $this->assertNull($this->onRoute('GET', ['rcs' => 'unsubscribe', 'rcs_token' => $token]));
        $html = Views::page();

        $this->assertNotNull(Subscriptions::find($id), 'A link scanner opening the URL changes nothing.');
        $this->assertStringContainsString('Unsubscribe from this post', $html);
        $this->assertStringContainsString('_ruvelo_cs_nonce', $html);
    }

    public function test_the_button_needs_a_nonce(): void
    {
        $post = $this->post();
        $id = $this->subscribe($post, 'follower@example.com');
        $token = Urls::unsubscribeToken(Subscriptions::find($id));

        $redirect = $this->onRoute('POST', ['rcs' => 'unsubscribe', 'rcs_token' => $token], ['rcs' => 'unsubscribe', 'rcs_token' => $token]);
        $this->assertStringContainsString('rcs_msg=expired', $redirect->url);
        $this->assertNotNull(Subscriptions::find($id));

        $redirect = $this->onRoute('POST', [], ['rcs' => 'unsubscribe', 'rcs_token' => $token, '_ruvelo_cs_nonce' => wp_create_nonce('ruvelo_cs_unsubscribe')]);
        $this->assertStringContainsString('rcs_msg=unsubscribed', $redirect->url);
        $this->assertNull(Subscriptions::find($id));
    }

    public function test_unsubscribe_from_everything(): void
    {
        $one = $this->post();
        $two = $this->post();
        $a = $this->subscribe($one, 'follower@example.com');
        $this->subscribe($two, 'follower@example.com');
        $other = $this->subscribe($one, 'other@example.com');
        $token = Urls::unsubscribeToken(Subscriptions::find($a));

        $this->onRoute('POST', [], ['rcs' => 'unsubscribe', 'rcs_token' => $token, 'all' => '1', '_ruvelo_cs_nonce' => wp_create_nonce('ruvelo_cs_unsubscribe')]);

        $this->assertSame([], Subscriptions::forEmail('follower@example.com', null));
        $this->assertNotNull(Subscriptions::find($other));
    }

    public function test_a_forged_token_unsubscribes_nobody(): void
    {
        $post = $this->post();
        $id = $this->subscribe($post, 'follower@example.com');

        $done = $this->onRoute('POST', ['rcs' => 'unsubscribe', 'rcs_token' => 'eyJwIjoidW5zdWJzY3JpYmUifQ.forged'], ['List-Unsubscribe' => 'One-Click']);

        $this->assertSame(404, $done->status);
        $this->assertNotNull(Subscriptions::find($id));
    }

    public function test_confirming_needs_the_button(): void
    {
        $post = $this->post();
        $result = Subscriptions::subscribe($post, 'new@example.com');
        $token = $result['token'];

        $this->assertNull($this->onRoute('GET', ['rcs' => 'confirm', 'rcs_token' => $token]));
        $this->assertStringContainsString('Confirm my subscription', Views::page());
        $this->assertFalse(Subscriptions::find($result['subscription']->id)->isActive());

        $redirect = $this->onRoute('POST', [], ['rcs' => 'confirm', 'rcs_token' => $token, '_ruvelo_cs_nonce' => wp_create_nonce('ruvelo_cs_confirm')]);

        $this->assertStringContainsString('rcs_msg=confirmed', $redirect->url);
        $this->assertTrue(Subscriptions::find($result['subscription']->id)->isActive());
    }

    public function test_the_manage_page_lists_and_changes_only_the_owners_subscriptions(): void
    {
        $post = $this->post(['post_title' => 'Followed post']);
        $mine = $this->subscribe($post, 'me@example.com');
        $theirs = $this->subscribe($post, 'them@example.com');
        $token = Urls::manageToken('me@example.com');

        $this->onRoute('GET', ['rcs' => 'manage', 'rcs_token' => $token]);
        $html = Views::page();
        $this->assertStringContainsString('Followed post', $html);
        $this->assertStringContainsString('me@example.com', $html);
        $this->assertStringNotContainsString('them@example.com', $html);

        $_POST = ['rcs_token' => $token, 'op' => 'unsubscribe', 'id' => (string) $theirs, '_ruvelo_cs_nonce' => wp_create_nonce('ruvelo_cs_manage')];
        try {
            Forms::manage();
        } catch (Redirect $redirect) {
            $this->assertStringContainsString('rcs_msg=invalid', $redirect->url);
        }
        $this->assertNotNull(Subscriptions::find($theirs));

        $_POST = ['rcs_token' => $token, 'op' => 'type', 'id' => (string) $mine, 'type' => 'replies', '_ruvelo_cs_nonce' => wp_create_nonce('ruvelo_cs_manage')];
        try {
            Forms::manage();
        } catch (Redirect $redirect) {
            $this->assertStringContainsString('rcs_msg=updated', $redirect->url);
        }
        $this->assertSame('replies', Subscriptions::find($mine)->type);

        $_POST = ['rcs_token' => $token, 'op' => 'frequency', 'frequency' => 'daily', '_ruvelo_cs_nonce' => wp_create_nonce('ruvelo_cs_manage')];
        try {
            Forms::manage();
        } catch (Redirect) {
        }
        $this->assertSame('daily', Subscriptions::find($mine)->frequency);
        $this->assertSame('instant', Subscriptions::find($theirs)->frequency);
    }

    public function test_expired_manage_links_ask_for_a_new_one(): void
    {
        $this->settings(['link_days' => 1]);
        $token = Tokens::sign('manage', ['e' => 'me@example.com'], -10);

        $this->onRoute('GET', ['rcs' => 'manage', 'rcs_token' => $token]);

        $this->assertSame('invalid', Route::state()['view']);
        $this->assertStringContainsString('Email me a link', Views::page());
    }

    public function test_signed_in_users_manage_without_a_link(): void
    {
        $user = $this->user();
        wp_set_current_user($user);
        $this->subscribe($this->post(['post_title' => 'My followed post']), get_userdata($user)->user_email, 'all', ['user_id' => $user]);

        $this->onRoute('GET', []);

        $this->assertSame('manage', Route::state()['view']);
        $this->assertStringContainsString('My followed post', Views::page());
    }
}
