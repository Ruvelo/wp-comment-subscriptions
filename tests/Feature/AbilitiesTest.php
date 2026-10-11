<?php

declare(strict_types=1);

namespace Ruvelo\WP\CommentSubscriptions\Tests\Feature;

use Ruvelo\WP\CommentSubscriptions\Abilities;
use Ruvelo\WP\CommentSubscriptions\Subscriptions;
use Ruvelo\WP\CommentSubscriptions\Tests\TestCase;
use WP_Error;

final class AbilitiesTest extends TestCase
{
    public function test_the_abilities_are_registered_for_rest_on_7_0_and_7_1(): void
    {
        foreach ([Abilities::COUNT, Abilities::LIST] as $name) {
            $ability = wp_get_ability($name);
            $this->assertNotNull($ability, $name);
            $this->assertTrue($ability->get_meta_item('show_in_rest'));
            $this->assertTrue($ability->get_meta_item('public'));
        }
    }

    public function test_they_need_the_capability(): void
    {
        $post = $this->post();
        $this->subscribe($post, 'a@example.com');

        wp_set_current_user($this->user('editor'));
        $this->assertInstanceOf(WP_Error::class, wp_get_ability(Abilities::COUNT)->execute(['post_id' => $post]));
        $this->assertInstanceOf(WP_Error::class, wp_get_ability(Abilities::LIST)->execute(['post_id' => $post]));
    }

    public function test_counting_and_listing_a_posts_subscribers(): void
    {
        $post = $this->post(['post_title' => 'Usage-based billing is here']);
        $this->subscribe($post, 'a@example.com');
        $this->subscribe($post, 'b@example.com', 'replies');
        Subscriptions::subscribe($post, 'c@example.com');
        wp_set_current_user($this->admin());

        $count = wp_get_ability(Abilities::COUNT)->execute(['post_id' => $post]);
        $this->assertSame(['post_id' => $post, 'title' => 'Usage-based billing is here', 'all' => 1, 'replies' => 1, 'pending' => 1, 'total' => 2], $count);

        $list = wp_get_ability(Abilities::LIST)->execute(['post_id' => $post]);
        $this->assertSame(['a@example.com', 'b@example.com'], array_column($list, 'email'));
        $this->assertArrayNotHasKey('source', $list[0]);
    }
}
