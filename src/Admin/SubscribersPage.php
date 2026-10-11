<?php

declare(strict_types=1);

namespace Ruvelo\WP\CommentSubscriptions\Admin;

use Ruvelo\WP\CommentSubscriptions\Notifications;
use Ruvelo\WP\CommentSubscriptions\Settings;
use Ruvelo\WP\CommentSubscriptions\Subscriptions;
use Ruvelo\WP\CommentSubscriptions\Support\Redirect;

/**
 * The Subscribers tab: the numbers, then the searchable list.
 */
final class SubscribersPage
{
    public static function register(): void
    {
        add_action('admin_post_ruvelo_cs_delete', [self::class, 'delete']);
        add_filter('set-screen-option', [self::class, 'screenOption'], 10, 3);
    }

    public static function load(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- delete() verifies the bulk nonce.
        $action = isset($_POST['action']) && is_string($_POST['action']) ? sanitize_key($_POST['action']) : '';
        $action2 = isset($_POST['action2']) && is_string($_POST['action2']) ? sanitize_key($_POST['action2']) : '';
        // phpcs:enable
        if ($action === 'delete' || $action2 === 'delete') {
            self::delete();
        }
        if (Admin::tab() !== 'subscribers') {
            return;
        }
        add_screen_option('per_page', ['label' => __('Subscriptions per page', 'ruvelo-comment-subscriptions'), 'default' => 20, 'option' => 'ruvelo_cs_per_page']);
    }

    public static function screenOption(mixed $status, string $option, mixed $value): mixed
    {
        return $option === 'ruvelo_cs_per_page' ? max(1, min(200, absint($value))) : $status;
    }

    /**
     * Bulk and row "Delete".
     */
    public static function delete(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Checked just below (row links and the bulk form use different nonces).
        $bulk = isset($_REQUEST['_wpnonce']) && wp_verify_nonce(sanitize_key(wp_unslash($_REQUEST['_wpnonce'])), 'bulk-subscriptions');
        // phpcs:enable
        if (! $bulk) {
            check_admin_referer('ruvelo_cs_delete');
        }
        if (! current_user_can(Settings::CAP)) {
            wp_die(esc_html__('You can’t manage comment subscriptions on this site.', 'ruvelo-comment-subscriptions'), '', ['response' => 403]);
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified above.
        $ids = isset($_REQUEST['ids']) ? array_filter(array_map('absint', (array) wp_unslash($_REQUEST['ids']))) : [];
        $count = 0;
        foreach ($ids as $id) {
            $count += Subscriptions::delete($id, 'admin') ? 1 : 0;
        }
        $back = wp_get_referer();
        $url = add_query_arg(['ruvelo_cs_notice' => 'deleted', 'count' => $count], $back !== false ? remove_query_arg(['ruvelo_cs_notice', 'count'], $back) : Admin::url());
        if (defined('RUVELO_COMMENT_SUBSCRIPTIONS_TESTS')) {
            throw new Redirect($url); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Test-only signal.
        }
        wp_safe_redirect($url);
        exit;
    }

    public static function render(): void
    {
        $stats = Subscriptions::stats();
        $table = new SubscribersTable;
        $table->prepare_items();
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
        $postId = isset($_GET['post_id']) ? absint($_GET['post_id']) : 0;

        Admin::notice();
        ?>
        <div class="ruvelo-cs-stats">
            <?php
            $cards = [
                [__('Active subscriptions', 'ruvelo-comment-subscriptions'), $stats['active'], ''],
                [__('People subscribed', 'ruvelo-comment-subscriptions'), $stats['subscribers'], $stats['daily'] > 0
                    /* translators: %s: number of people */
                    ? sprintf(_n('%s on the daily digest', '%s on the daily digest', $stats['daily'], 'ruvelo-comment-subscriptions'), number_format_i18n($stats['daily']))
                    : ''],
                [__('Posts followed', 'ruvelo-comment-subscriptions'), $stats['posts'], ''],
                [__('Waiting for confirmation', 'ruvelo-comment-subscriptions'), $stats['pending'], sprintf(
                    /* translators: %d: number of days */
                    _n('Deleted after %d day', 'Deleted after %d days', max(1, Settings::int('pending_days')), 'ruvelo-comment-subscriptions'),
                    max(1, Settings::int('pending_days'))
                )],
                [__('Emails sent, last 30 days', 'ruvelo-comment-subscriptions'), $stats['sent_30d'], Notifications::pending() > 0
                    /* translators: %s: number of comments */
                    ? sprintf(_n('%s comment queued', '%s comments queued', Notifications::pending(), 'ruvelo-comment-subscriptions'), number_format_i18n(Notifications::pending()))
                    : ''],
            ];
        foreach ($cards as [$label, $value, $note]) {
            ?>
                <div class="ruvelo-cs-stat">
                    <small><?php echo esc_html($label); ?></small>
                    <strong><?php echo esc_html(number_format_i18n((int) $value)); ?></strong>
                    <?php if ($note !== '') { ?><span><?php echo esc_html($note); ?></span><?php } ?>
                </div>
            <?php } ?>
        </div>

        <?php if ($stats['top'] !== []) { ?>
            <p class="ruvelo-cs-top">
                <span><?php esc_html_e('Most followed:', 'ruvelo-comment-subscriptions'); ?></span>
                <?php foreach ($stats['top'] as $top) { ?>
                    <a href="<?php echo esc_url(Admin::url('subscribers', ['post_id' => $top['post_id']])); ?>" class="ruvelo-cs-top-item"><?php echo esc_html(wp_specialchars_decode(get_the_title($top['post_id']), ENT_QUOTES)); ?> <b><?php echo esc_html(number_format_i18n($top['count'])); ?></b></a>
                <?php } ?>
            </p>
        <?php } ?>

        <?php if ($postId > 0) { ?>
            <p class="ruvelo-cs-filter-note">
                <?php
                /* translators: %s: post title */
                echo esc_html(sprintf(__('Showing subscribers to “%s”.', 'ruvelo-comment-subscriptions'), wp_specialchars_decode(get_the_title($postId), ENT_QUOTES)));
            ?>
                <a href="<?php echo esc_url(Admin::url()); ?>"><?php esc_html_e('Show all', 'ruvelo-comment-subscriptions'); ?></a>
            </p>
        <?php } ?>

        <?php $table->views(); ?>
        <form method="get">
            <input type="hidden" name="page" value="<?php echo esc_attr(Admin::SLUG); ?>">
            <input type="hidden" name="tab" value="subscribers">
            <?php if ($postId > 0) { ?><input type="hidden" name="post_id" value="<?php echo esc_attr((string) $postId); ?>"><?php } ?>
            <?php $table->search_box(__('Search addresses or posts', 'ruvelo-comment-subscriptions'), 'ruvelo-cs-search'); ?>
        </form>
        <form method="post">
            <?php $table->display(); ?>
        </form>
        <?php
    }
}
