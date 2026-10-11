<?php

declare(strict_types=1);

namespace Ruvelo\WP\CommentSubscriptions;

use Ruvelo\WP\CommentSubscriptions\Frontend\Views;

/**
 * Assets, blocks and shortcodes.
 */
final class Blocks
{
    public const BLOCKS = ['subscribe-form', 'manage-subscriptions'];

    public static function register(): void
    {
        self::assets();
        foreach (self::BLOCKS as $block) {
            register_block_type(RUVELO_COMMENT_SUBSCRIPTIONS_DIR.'blocks/'.$block);
        }
        add_shortcode('ruvelo_subscribe', [self::class, 'subscribeShortcode']);
        add_shortcode('ruvelo_comment_subscriptions', [self::class, 'pageShortcode']);
    }

    public static function assets(): void
    {
        $version = RUVELO_COMMENT_SUBSCRIPTIONS_VERSION;
        wp_register_style('ruvelo-comment-subscriptions', RUVELO_COMMENT_SUBSCRIPTIONS_URL.'assets/css/subscriptions.css', [], $version);
        wp_register_script('ruvelo-comment-subscriptions', RUVELO_COMMENT_SUBSCRIPTIONS_URL.'assets/js/subscriptions.js', [], $version, ['in_footer' => true, 'strategy' => 'defer']);
        wp_localize_script('ruvelo-comment-subscriptions', 'ruveloCommentSubscriptions', [
            'rest' => esc_url_raw(rest_url('ruvelo-comment-subscriptions/v1/')),
            'nonce' => is_user_logged_in() ? wp_create_nonce('wp_rest') : '',
            'error' => __('That didn’t work. Check your connection and try again.', 'ruvelo-comment-subscriptions'),
        ]);
        wp_register_script(
            'ruvelo-comment-subscriptions-blocks',
            RUVELO_COMMENT_SUBSCRIPTIONS_URL.'assets/js/blocks.js',
            ['wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-server-side-render', 'wp-i18n'],
            $version,
            ['in_footer' => false]
        );
        wp_set_script_translations('ruvelo-comment-subscriptions-blocks', 'ruvelo-comment-subscriptions', RUVELO_COMMENT_SUBSCRIPTIONS_DIR.'languages');
    }

    /**
     * [ruvelo_subscribe post="42" heading="no"]
     *
     * @param array<string, string>|string $atts
     */
    public static function subscribeShortcode(array|string $atts = []): string
    {
        $atts = shortcode_atts(['post' => '0', 'heading' => 'yes'], is_array($atts) ? $atts : [], 'ruvelo_subscribe');

        return Views::subscribeForm([
            'postId' => absint($atts['post']),
            'showHeading' => ! in_array(strtolower((string) $atts['heading']), ['0', 'no', 'false', 'off'], true),
        ]);
    }

    /**
     * [ruvelo_comment_subscriptions]: the manage page on a page of your own.
     */
    public static function pageShortcode(): string
    {
        return Views::page();
    }
}
