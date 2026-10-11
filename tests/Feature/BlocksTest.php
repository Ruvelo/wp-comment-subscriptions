<?php

declare(strict_types=1);

namespace Ruvelo\WP\CommentSubscriptions\Tests\Feature;

use Ruvelo\WP\CommentSubscriptions\Tests\TestCase;

final class BlocksTest extends TestCase
{
    public function test_the_blocks_are_registered(): void
    {
        $registry = \WP_Block_Type_Registry::get_instance();

        $this->assertTrue($registry->is_registered('ruvelo/subscribe-form'));
        $this->assertTrue($registry->is_registered('ruvelo/manage-subscriptions'));
        $this->assertTrue(shortcode_exists('ruvelo_subscribe'));
        $this->assertTrue(shortcode_exists('ruvelo_comment_subscriptions'));
    }

    public function test_the_subscribe_form_works_without_javascript(): void
    {
        $post = $this->post(['post_title' => 'Usage-based billing is here']);

        $html = do_blocks('<!-- wp:ruvelo/subscribe-form {"postId":'.$post.'} /-->');

        $this->assertStringContainsString('action="'.esc_url(admin_url('admin-post.php')).'"', $html);
        $this->assertStringContainsString('name="action" value="ruvelo_cs_subscribe"', $html);
        $this->assertStringContainsString('_ruvelo_cs_nonce', $html);
        $this->assertStringContainsString('name="website"', $html, 'Honeypot.');
        $this->assertStringContainsString('aria-live="polite"', $html);
        $this->assertStringContainsString('<label class="ruvelo-cs-label" for="ruvelo-cs-email-'.$post.'">', $html);
    }

    public function test_nothing_renders_for_closed_posts(): void
    {
        $post = $this->post(['comment_status' => 'closed']);

        $this->assertSame('', trim(do_shortcode('[ruvelo_subscribe post="'.$post.'"]')));
    }

    public function test_the_manage_block_asks_for_a_link_by_default(): void
    {
        $html = do_blocks('<!-- wp:ruvelo/manage-subscriptions /-->');

        $this->assertStringContainsString('Manage your comment subscriptions', $html);
        $this->assertStringContainsString('ruvelo_cs_request_link', $html);
    }
}
