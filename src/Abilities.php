<?php

declare(strict_types=1);

namespace Ruvelo\WP\CommentSubscriptions;

use WP_Error;

/**
 * Abilities API (WordPress 6.9+): what AI agents and other tools may read.
 * Subscriber lists are personal data, so both abilities need the
 * ruvelo_manage_comment_subscriptions capability. The MCP adapter picks
 * them up when it's installed.
 */
final class Abilities
{
    public const CATEGORY = 'ruvelo-comment-subscriptions';

    public const COUNT = 'ruvelo-comment-subscriptions/count-subscribers';

    public const LIST = 'ruvelo-comment-subscriptions/list-subscribers';

    public static function register(): void
    {
        add_action('wp_abilities_api_categories_init', [self::class, 'category']);
        add_action('wp_abilities_api_init', [self::class, 'abilities']);
    }

    public static function category(): void
    {
        wp_register_ability_category(self::CATEGORY, [
            'label' => __('Comment subscriptions', 'ruvelo-comment-subscriptions'),
            'description' => __('Who follows a post’s comments by email.', 'ruvelo-comment-subscriptions'),
        ]);
    }

    public static function abilities(): void
    {
        $meta = [
            'public' => true,
            // WordPress 7.0 exposes abilities over REST through show_in_rest; 7.1 added public.
            'show_in_rest' => true,
            'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
        ];
        $permission = static fn (): bool => current_user_can(Settings::CAP);
        $post = ['type' => 'integer', 'minimum' => 1, 'description' => __('The post’s ID.', 'ruvelo-comment-subscriptions')];

        wp_register_ability(self::COUNT, [
            'label' => __('Count a post’s comment subscribers', 'ruvelo-comment-subscriptions'),
            'description' => __('How many addresses follow a post’s comments by email: all new comments, replies only, and requests still waiting for confirmation.', 'ruvelo-comment-subscriptions'),
            'category' => self::CATEGORY,
            'input_schema' => [
                'type' => 'object',
                'properties' => ['post_id' => $post],
                'required' => ['post_id'],
                'additionalProperties' => false,
            ],
            'output_schema' => [
                'type' => 'object',
                'properties' => [
                    'post_id' => ['type' => 'integer'],
                    'title' => ['type' => 'string'],
                    'all' => ['type' => 'integer'],
                    'replies' => ['type' => 'integer'],
                    'pending' => ['type' => 'integer'],
                    'total' => ['type' => 'integer'],
                ],
            ],
            'execute_callback' => [self::class, 'count'],
            'permission_callback' => $permission,
            'meta' => $meta,
        ]);

        wp_register_ability(self::LIST, [
            'label' => __('List a post’s comment subscribers', 'ruvelo-comment-subscriptions'),
            'description' => __('The addresses that follow a post’s comments, with what they follow, how often, and since when. Personal data: use it only to answer the site owner.', 'ruvelo-comment-subscriptions'),
            'category' => self::CATEGORY,
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'post_id' => $post,
                    'status' => ['type' => 'string', 'enum' => Subscription::STATUSES, 'default' => 'active'],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 500, 'default' => 100],
                ],
                'required' => ['post_id'],
                'additionalProperties' => false,
            ],
            'output_schema' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'id' => ['type' => 'integer'],
                        'email' => ['type' => 'string'],
                        'type' => ['type' => 'string'],
                        'status' => ['type' => 'string'],
                        'frequency' => ['type' => 'string'],
                        'created_at' => ['type' => 'string'],
                        'confirmed_at' => ['type' => ['string', 'null']],
                    ],
                ],
            ],
            'execute_callback' => [self::class, 'list'],
            'permission_callback' => $permission,
            'meta' => $meta,
        ]);
    }

    /**
     * @param array<string, mixed>|null $input
     * @return array<string, mixed>|WP_Error
     */
    public static function count(?array $input = null): array|WP_Error
    {
        $postId = (int) ($input['post_id'] ?? 0);
        if (get_post($postId) === null) {
            return new WP_Error('ruvelo_cs_post', __('That post doesn’t exist.', 'ruvelo-comment-subscriptions'));
        }

        return ['post_id' => $postId, 'title' => wp_specialchars_decode(get_the_title($postId), ENT_QUOTES)] + Subscriptions::counts($postId);
    }

    /**
     * @param array<string, mixed>|null $input
     * @return list<array<string, mixed>>|WP_Error
     */
    public static function list(?array $input = null): array|WP_Error
    {
        $postId = (int) ($input['post_id'] ?? 0);
        if (get_post($postId) === null) {
            return new WP_Error('ruvelo_cs_post', __('That post doesn’t exist.', 'ruvelo-comment-subscriptions'));
        }
        $status = is_string($input['status'] ?? null) && in_array($input['status'], Subscription::STATUSES, true) ? $input['status'] : 'active';

        return array_map(static function (Subscription $subscription): array {
            $data = $subscription->toArray();

            return array_intersect_key($data, array_flip(['id', 'email', 'type', 'status', 'frequency', 'created_at', 'confirmed_at']));
        }, Subscriptions::forPost($postId, $status, max(1, min(500, (int) ($input['limit'] ?? 100)))));
    }
}
