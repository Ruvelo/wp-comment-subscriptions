<?php

declare(strict_types=1);

namespace Ruvelo\WP\CommentSubscriptions\Tests\Feature;

use Ruvelo\WP\CommentSubscriptions\Subscriptions;
use Ruvelo\WP\CommentSubscriptions\Tests\TestCase;
use Ruvelo\WP\CommentSubscriptions\Urls;
use WP_REST_Request;
use WP_REST_Response;

final class RestTest extends TestCase
{
    private function call(string $method, string $route, array $params = []): WP_REST_Response
    {
        $request = new WP_REST_Request($method, '/ruvelo-comment-subscriptions/v1'.$route);
        foreach ($params as $key => $value) {
            $request->set_param($key, $value);
        }

        return rest_get_server()->dispatch($request);
    }

    public function test_guests_subscribe_with_double_opt_in(): void
    {
        $post = $this->post();

        $response = $this->call('POST', '/subscribe', ['post' => $post, 'email' => 'reader@example.com']);

        $this->assertSame(202, $response->get_status());
        $this->assertSame('pending', $response->get_data()['status']);
        $token = $this->tokenFrom($this->link($this->lastMail()['body'], 'confirm'));

        $confirmed = $this->call('POST', '/confirm', ['token' => $token]);
        $this->assertSame(200, $confirmed->get_status());
        $this->assertSame($post, $confirmed->get_data()['subscription']['post_id']);
        $this->assertArrayNotHasKey('user_id', $confirmed->get_data()['subscription']);

        $this->assertSame(404, $this->call('POST', '/confirm', ['token' => $token])->get_status());
    }

    public function test_signed_in_users_subscribe_at_once(): void
    {
        wp_set_current_user($this->user());

        $response = $this->call('POST', '/subscribe', ['post' => $this->post(), 'type' => 'replies']);

        $this->assertSame(201, $response->get_status());
        $this->assertSame('active', $response->get_data()['status']);
    }

    public function test_bad_input_is_rejected(): void
    {
        $this->assertSame(422, $this->call('POST', '/subscribe', ['post' => $this->post(), 'email' => 'nope'])->get_status());
        $this->assertSame(404, $this->call('POST', '/subscribe', ['post' => 999999, 'email' => 'a@example.com'])->get_status());
        $this->assertSame(400, $this->call('POST', '/subscribe', ['post' => $this->post(), 'email' => 'a@example.com', 'type' => 'everything'])->get_status());
    }

    public function test_manage_by_token(): void
    {
        $post = $this->post();
        $id = $this->subscribe($post, 'me@example.com');
        $token = Urls::manageToken('me@example.com');

        $this->assertSame(403, $this->call('GET', '/manage', ['token' => 'forged.token'])->get_status());
        $this->assertSame(401, $this->call('GET', '/manage')->get_status());

        $overview = $this->call('GET', '/manage', ['token' => $token]);
        $this->assertSame('me@example.com', $overview->get_data()['email']);
        $this->assertCount(1, $overview->get_data()['subscriptions']);

        $this->call('POST', '/manage', ['token' => $token, 'op' => 'frequency', 'frequency' => 'daily']);
        $this->assertSame('daily', Subscriptions::find($id)->frequency);

        $this->call('POST', '/manage', ['token' => $token, 'op' => 'unsubscribe', 'id' => $id]);
        $this->assertNull(Subscriptions::find($id));
    }

    public function test_one_click_unsubscribe_endpoint(): void
    {
        $id = $this->subscribe($this->post(), 'me@example.com');
        $token = Urls::unsubscribeToken(Subscriptions::find($id));

        $this->assertSame(200, $this->call('POST', '/unsubscribe', ['token' => $token])->get_status());
        $this->assertSame(404, $this->call('POST', '/unsubscribe', ['token' => $token])->get_status());
    }

    public function test_manage_link_never_reveals_anything(): void
    {
        $this->subscribe($this->post(), 'known@example.com');

        $known = $this->call('POST', '/manage-link', ['email' => 'known@example.com']);
        $unknown = $this->call('POST', '/manage-link', ['email' => 'unknown@example.com']);

        $this->assertSame($known->get_status(), $unknown->get_status());
        $this->assertSame($known->get_data(), $unknown->get_data());
        $this->assertSame(['known@example.com'], $this->sentTo());
    }

    public function test_admin_routes_need_the_capability(): void
    {
        $post = $this->post();
        $id = $this->subscribe($post, 'a@example.com');

        wp_set_current_user($this->user('editor'));
        $this->assertSame(403, $this->call('GET', '/subscriptions')->get_status());
        $this->assertSame(403, $this->call('DELETE', '/subscriptions/'.$id)->get_status());

        wp_set_current_user($this->admin());
        $list = $this->call('GET', '/subscriptions', ['post' => $post]);
        $this->assertSame(200, $list->get_status());
        $this->assertSame('1', $list->get_headers()['X-WP-Total']);
        $this->assertSame(1, $this->call('GET', '/posts/'.$post.'/subscriptions')->get_data()['all']);
        $this->assertSame(1, $this->call('GET', '/stats')->get_data()['active']);
        $this->assertSame(200, $this->call('DELETE', '/subscriptions/'.$id)->get_status());
        $this->assertNull(Subscriptions::find($id));
    }
}
