<?php

declare(strict_types=1);

namespace Ruvelo\WP\CommentSubscriptions\Admin;

use Ruvelo\WP\CommentSubscriptions\Settings;

/**
 * Comments → Subscriptions: subscribers, import, email previews and
 * settings, as tabs of one screen. Nothing shows outside the plugin's own
 * screens except the post meta box, a column on post lists and a section
 * on the profile page.
 */
final class Admin
{
    public const SLUG = 'ruvelo-comment-subscriptions';

    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_enqueue_scripts', [self::class, 'assets']);
        add_filter('plugin_action_links_'.plugin_basename(RUVELO_COMMENT_SUBSCRIPTIONS_FILE), [self::class, 'links']);

        SubscribersPage::register();
        SettingsPage::register();
        ImportPage::register();
        EmailsPage::register();
        PostBox::register();
        Profile::register();
    }

    public static function menu(): void
    {
        $hook = add_comments_page(__('Comment subscriptions', 'ruvelo-comment-subscriptions'), __('Subscriptions', 'ruvelo-comment-subscriptions'), Settings::CAP, self::SLUG, [self::class, 'render']);
        if (is_string($hook)) {
            add_action('load-'.$hook, [SubscribersPage::class, 'load']);
        }
    }

    /**
     * @return array<string, string>
     */
    public static function tabs(): array
    {
        $tabs = [
            'subscribers' => __('Subscribers', 'ruvelo-comment-subscriptions'),
            'import' => __('Import', 'ruvelo-comment-subscriptions'),
            'emails' => __('Preview emails', 'ruvelo-comment-subscriptions'),
        ];
        if (current_user_can('manage_options')) {
            $tabs['settings'] = __('Settings', 'ruvelo-comment-subscriptions');
        }

        return $tabs;
    }

    public static function tab(): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Which tab to show.
        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'subscribers';

        return array_key_exists($tab, self::tabs()) ? $tab : 'subscribers';
    }

    /**
     * @param array<string, string|int> $args
     */
    public static function url(string $tab = 'subscribers', array $args = []): string
    {
        return add_query_arg(array_merge(['page' => self::SLUG, 'tab' => $tab], $args), admin_url('edit-comments.php'));
    }

    public static function render(): void
    {
        if (! current_user_can(Settings::CAP)) {
            return;
        }
        $tab = self::tab();
        ?>
        <div class="wrap ruvelo-cs-admin">
            <div class="ruvelo-cs-admin-head">
                <span class="ruvelo-cs-admin-mark" aria-hidden="true">C</span>
                <div>
                    <h1><?php esc_html_e('Comment subscriptions', 'ruvelo-comment-subscriptions'); ?></h1>
                    <p><?php esc_html_e('Readers who follow your posts’ comments by email.', 'ruvelo-comment-subscriptions'); ?></p>
                </div>
            </div>
            <nav class="nav-tab-wrapper ruvelo-cs-tabs" aria-label="<?php esc_attr_e('Comment subscriptions', 'ruvelo-comment-subscriptions'); ?>">
                <?php foreach (self::tabs() as $key => $label) { ?>
                    <a href="<?php echo esc_url(self::url($key)); ?>" class="nav-tab<?php echo $key === $tab ? ' nav-tab-active' : ''; ?>"<?php echo $key === $tab ? ' aria-current="page"' : ''; ?>><?php echo esc_html($label); ?></a>
                <?php } ?>
            </nav>
            <?php
            match ($tab) {
                'import' => ImportPage::render(),
                'emails' => EmailsPage::render(),
                'settings' => SettingsPage::render(),
                default => SubscribersPage::render(),
            };
        ?>
        </div>
        <?php
    }

    public static function assets(string $hook): void
    {
        $screen = get_current_screen();
        $ours = str_contains($hook, self::SLUG);
        $post = $screen !== null && in_array($screen->base, ['post', 'edit', 'profile', 'user-edit'], true);
        if (! $ours && ! $post) {
            return;
        }
        wp_enqueue_style('ruvelo-comment-subscriptions-admin', RUVELO_COMMENT_SUBSCRIPTIONS_URL.'assets/css/admin.css', [], RUVELO_COMMENT_SUBSCRIPTIONS_VERSION);
    }

    /**
     * @param array<int|string, string> $links
     * @return array<int|string, string>
     */
    public static function links(array $links): array
    {
        if (current_user_can(Settings::CAP)) {
            array_unshift($links, '<a href="'.esc_url(self::url()).'">'.esc_html__('Subscribers', 'ruvelo-comment-subscriptions').'</a>');
        }

        return $links;
    }

    /**
     * A notice at the top of one of our tabs, from `ruvelo_cs_notice`.
     */
    public static function notice(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
        $code = isset($_GET['ruvelo_cs_notice']) ? sanitize_key(wp_unslash($_GET['ruvelo_cs_notice'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
        $count = isset($_GET['count']) ? absint($_GET['count']) : 0;
        $messages = [
            /* translators: %s: number of subscriptions */
            'deleted' => sprintf(_n('%s subscription deleted.', '%s subscriptions deleted.', $count, 'ruvelo-comment-subscriptions'), number_format_i18n($count)),
            'sent' => __('Test email sent. Check your inbox (and spam folder).', 'ruvelo-comment-subscriptions'),
            'not_sent' => __('WordPress couldn’t send the email. Check your mail setup: most sites need an SMTP plugin to deliver email reliably.', 'ruvelo-comment-subscriptions'),
        ];
        if (! isset($messages[$code])) {
            return;
        }
        $type = $code === 'not_sent' ? 'error' : 'success';
        printf('<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr($type), esc_html($messages[$code]));
    }
}
