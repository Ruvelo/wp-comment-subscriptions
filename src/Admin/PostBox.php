<?php

declare(strict_types=1);

namespace Ruvelo\WP\CommentSubscriptions\Admin;

use Ruvelo\WP\CommentSubscriptions\Settings;
use Ruvelo\WP\CommentSubscriptions\Subscriptions;
use WP_Post;

/**
 * Per post: a "Comment subscriptions" box in the editor (who follows it,
 * and a switch to turn subscriptions off) and a column on post lists.
 */
final class PostBox
{
    public static function register(): void
    {
        add_action('add_meta_boxes', [self::class, 'add'], 10, 2);
        add_action('save_post', [self::class, 'save'], 10, 2);
        add_action('admin_init', [self::class, 'columns']);
    }

    /**
     * @return list<string>
     */
    public static function postTypes(): array
    {
        $types = [];
        foreach (get_post_types(['public' => true], 'names') as $type) {
            if (post_type_supports($type, 'comments')) {
                $types[] = $type;
            }
        }

        return $types;
    }

    public static function add(string $postType, mixed $post = null): void
    {
        if (! in_array($postType, self::postTypes(), true) || ! current_user_can(Settings::CAP)) {
            return;
        }
        add_meta_box('ruvelo-comment-subscriptions', __('Comment subscriptions', 'ruvelo-comment-subscriptions'), [self::class, 'render'], $postType, 'side', 'low');
    }

    public static function render(WP_Post $post): void
    {
        $counts = Subscriptions::counts($post->ID);
        wp_nonce_field('ruvelo_cs_post_'.$post->ID, '_ruvelo_cs_post_nonce');
        ?>
        <div class="ruvelo-cs-box">
            <p class="ruvelo-cs-box-count">
                <strong><?php echo esc_html(number_format_i18n($counts['total'])); ?></strong>
                <?php echo esc_html(_n('subscriber', 'subscribers', $counts['total'], 'ruvelo-comment-subscriptions')); ?>
            </p>
            <ul class="ruvelo-cs-box-list">
                <li><span><?php esc_html_e('All new comments', 'ruvelo-comment-subscriptions'); ?></span> <b><?php echo esc_html(number_format_i18n($counts['all'])); ?></b></li>
                <li><span><?php esc_html_e('Replies only', 'ruvelo-comment-subscriptions'); ?></span> <b><?php echo esc_html(number_format_i18n($counts['replies'])); ?></b></li>
                <li><span><?php esc_html_e('Waiting for confirmation', 'ruvelo-comment-subscriptions'); ?></span> <b><?php echo esc_html(number_format_i18n($counts['pending'])); ?></b></li>
            </ul>
            <?php if ($counts['total'] + $counts['pending'] > 0) { ?>
                <p><a href="<?php echo esc_url(Admin::url('subscribers', ['post_id' => $post->ID])); ?>"><?php esc_html_e('See who', 'ruvelo-comment-subscriptions'); ?></a></p>
            <?php } ?>
            <p>
                <label>
                    <input type="checkbox" name="ruvelo_cs_disabled" value="1" <?php checked((bool) get_post_meta($post->ID, Subscriptions::DISABLED_META, true)); ?>>
                    <?php esc_html_e('Turn off subscriptions for this post', 'ruvelo-comment-subscriptions'); ?>
                </label>
            </p>
        </div>
        <?php
    }

    public static function save(int $postId, WP_Post $post): void
    {
        if (! isset($_POST['_ruvelo_cs_post_nonce']) || ! wp_verify_nonce(sanitize_key(wp_unslash($_POST['_ruvelo_cs_post_nonce'])), 'ruvelo_cs_post_'.$postId)) {
            return;
        }
        if (wp_is_post_revision($postId) || ! current_user_can(Settings::CAP) || ! current_user_can('edit_post', $postId)) {
            return;
        }
        if (! empty($_POST['ruvelo_cs_disabled'])) {
            update_post_meta($postId, Subscriptions::DISABLED_META, 1);
        } else {
            delete_post_meta($postId, Subscriptions::DISABLED_META);
        }
    }

    public static function columns(): void
    {
        if (! current_user_can(Settings::CAP)) {
            return;
        }
        foreach (self::postTypes() as $type) {
            add_filter('manage_'.$type.'_posts_columns', [self::class, 'column']);
            add_action('manage_'.$type.'_posts_custom_column', [self::class, 'cell'], 10, 2);
        }
    }

    /**
     * @param array<string, string> $columns
     * @return array<string, string>
     */
    public static function column(array $columns): array
    {
        $label = '<span class="ruvelo-cs-col" title="'.esc_attr__('Comment subscribers', 'ruvelo-comment-subscriptions').'"><span class="dashicons dashicons-email-alt" aria-hidden="true"></span><span class="screen-reader-text">'.esc_html__('Comment subscribers', 'ruvelo-comment-subscriptions').'</span></span>';
        $out = [];
        foreach ($columns as $key => $value) {
            $out[$key] = $value;
            if ($key === 'comments') {
                $out['ruvelo_cs'] = $label;
            }
        }
        if (! isset($out['ruvelo_cs'])) {
            $out['ruvelo_cs'] = $label;
        }

        return $out;
    }

    public static function cell(string $column, int $postId): void
    {
        if ($column !== 'ruvelo_cs') {
            return;
        }
        global $wp_query;
        static $primed = false;
        if (! $primed && isset($wp_query->posts) && is_array($wp_query->posts)) {
            // One query for the whole page of posts.
            Subscriptions::countsFor(array_values(array_map(static fn ($post): int => $post instanceof WP_Post ? $post->ID : (int) $post, $wp_query->posts)));
            $primed = true;
        }
        $total = Subscriptions::counts($postId)['total'];
        if ($total === 0) {
            echo '<span aria-hidden="true">—</span><span class="screen-reader-text">'.esc_html__('No subscribers', 'ruvelo-comment-subscriptions').'</span>';

            return;
        }
        printf('<a href="%s">%s</a>', esc_url(Admin::url('subscribers', ['post_id' => $postId])), esc_html(number_format_i18n($total)));
    }
}
