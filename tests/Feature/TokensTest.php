<?php

declare(strict_types=1);

namespace Ruvelo\WP\CommentSubscriptions\Tests\Feature;

use Ruvelo\WP\CommentSubscriptions\Frontend\Route;
use Ruvelo\WP\CommentSubscriptions\Install;
use Ruvelo\WP\CommentSubscriptions\Subscriptions;
use Ruvelo\WP\CommentSubscriptions\Tests\TestCase;
use Ruvelo\WP\CommentSubscriptions\Tokens;
use Ruvelo\WP\CommentSubscriptions\Urls;

final class TokensTest extends TestCase
{
    public function test_a_signed_token_round_trips(): void
    {
        $token = Tokens::sign('manage', ['e' => 'a@example.com'], 60);

        $this->assertSame(['e' => 'a@example.com'], Tokens::verify('manage', $token));
    }

    public function test_tampered_tokens_fail(): void
    {
        $token = Tokens::sign('manage', ['e' => 'a@example.com']);
        [$payload, $signature] = explode('.', $token);
        $forged = rtrim(strtr(base64_encode((string) wp_json_encode(['p' => 'manage', 'd' => ['e' => 'b@example.com'], 'x' => 0])), '+/', '-_'), '=');

        $this->assertNull(Tokens::verify('manage', $forged.'.'.$signature));
        $this->assertNull(Tokens::verify('manage', $payload.'.'.strrev($signature)));
        $this->assertNull(Tokens::verify('manage', $payload));
        $this->assertNull(Tokens::verify('manage', ''));
    }

    public function test_a_token_only_works_for_its_purpose(): void
    {
        $token = Tokens::sign('unsubscribe-all', ['e' => 'a@example.com']);

        $this->assertNull(Tokens::verify('manage', $token));
    }

    public function test_tokens_expire(): void
    {
        $token = Tokens::sign('manage', ['e' => 'a@example.com'], -1);

        $this->assertNull(Tokens::verify('manage', $token));
    }

    public function test_rotating_the_secret_invalidates_every_link(): void
    {
        $token = Urls::manageToken('a@example.com');
        Tokens::rotate();

        $this->assertNull(Tokens::verify('manage', $token));
    }

    public function test_an_unsubscribe_token_is_tied_to_the_address(): void
    {
        $post = $this->post();
        $id = $this->subscribe($post, 'first@example.com');
        $token = Urls::unsubscribeToken(Subscriptions::find($id));

        // The row goes, and a new one happens to reuse the id for someone else.
        global $wpdb;
        $wpdb->update(Install::table(), ['email' => 'second@example.com'], ['id' => $id]);

        $this->assertSame('', Route::unsubscribeTarget($token)['email']);
    }

    public function test_links_carry_no_guessable_ids(): void
    {
        $post = $this->post();
        $id = $this->subscribe($post, 'a@example.com');
        $url = Urls::unsubscribe(Subscriptions::find($id));

        $this->assertStringNotContainsString('a@example.com', $url);
        $this->assertDoesNotMatchRegularExpression('/[?&](id|sub|subscription)=\d+/', $url);
    }
}
