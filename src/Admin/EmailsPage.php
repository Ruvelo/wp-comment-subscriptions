<?php

declare(strict_types=1);

namespace Ruvelo\WP\CommentSubscriptions\Admin;

use Ruvelo\WP\CommentSubscriptions\Mailer;
use Ruvelo\WP\CommentSubscriptions\Settings;
use Ruvelo\WP\CommentSubscriptions\Support\Redirect;

/**
 * The Preview emails tab: every email as subscribers get it, with sample
 * data from your own site, without sending anything. Useful when changing
 * a template, and in places that can't send mail (like WordPress Playground).
 */
final class EmailsPage
{
    public static function register(): void
    {
        add_action('admin_post_ruvelo_cs_preview', [self::class, 'raw']);
        add_action('admin_post_ruvelo_cs_send_test', [self::class, 'sendTest']);
    }

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            'confirm' => __('Confirmation', 'ruvelo-comment-subscriptions'),
            'notification' => __('New reply', 'ruvelo-comment-subscriptions'),
            'digest' => __('Daily digest', 'ruvelo-comment-subscriptions'),
            'manage-link' => __('Manage link', 'ruvelo-comment-subscriptions'),
        ];
    }

    private static function current(): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Which preview to show.
        $template = isset($_GET['email']) ? sanitize_key(wp_unslash($_GET['email'])) : 'confirm';

        return in_array($template, Mailer::TEMPLATES, true) ? $template : 'confirm';
    }

    /**
     * The email alone, in its own tab (admins only).
     */
    public static function raw(): void
    {
        check_admin_referer('ruvelo_cs_preview');
        if (! current_user_can(Settings::CAP)) {
            wp_die(esc_html__('You can’t manage comment subscriptions on this site.', 'ruvelo-comment-subscriptions'), '', ['response' => 403]);
        }
        $email = Mailer::sample(self::current(), wp_get_current_user()->user_email);
        nocache_headers();
        header('Content-Type: text/html; charset=utf-8');
        header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src data:");
        echo $email['html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The rendered email; every value in it is escaped by its template.
        exit;
    }

    public static function sendTest(): void
    {
        check_admin_referer('ruvelo_cs_send_test');
        if (! current_user_can(Settings::CAP)) {
            wp_die(esc_html__('You can’t manage comment subscriptions on this site.', 'ruvelo-comment-subscriptions'), '', ['response' => 403]);
        }
        $template = isset($_POST['email']) ? sanitize_key(wp_unslash($_POST['email'])) : 'confirm';
        $template = in_array($template, Mailer::TEMPLATES, true) ? $template : 'confirm';
        $sent = Mailer::sendSample(wp_get_current_user()->user_email, $template);
        $url = Admin::url('emails', ['email' => $template, 'ruvelo_cs_notice' => $sent ? 'sent' : 'not_sent']);
        if (defined('RUVELO_COMMENT_SUBSCRIPTIONS_TESTS')) {
            throw new Redirect($url); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Test-only signal.
        }
        wp_safe_redirect($url);
        exit;
    }

    public static function render(): void
    {
        $current = self::current();
        $user = wp_get_current_user();
        $email = Mailer::sample($current, $user->user_email);
        $raw = wp_nonce_url(add_query_arg(['action' => 'ruvelo_cs_preview', 'email' => $current], admin_url('admin-post.php')), 'ruvelo_cs_preview');

        Admin::notice();
        ?>
        <p class="ruvelo-cs-intro"><?php esc_html_e('What subscribers get, filled in with a post from your site. Nothing is sent. To change an email, copy its template from the plugin’s templates/emails folder to ruvelo-comment-subscriptions/emails in your theme.', 'ruvelo-comment-subscriptions'); ?></p>
        <div class="ruvelo-cs-preview">
            <nav class="ruvelo-cs-seg" aria-label="<?php esc_attr_e('Emails', 'ruvelo-comment-subscriptions'); ?>">
                <?php foreach (self::labels() as $key => $label) { ?>
                    <a href="<?php echo esc_url(Admin::url('emails', ['email' => $key])); ?>"<?php echo $key === $current ? ' aria-current="page"' : ''; ?>><?php echo esc_html($label); ?></a>
                <?php } ?>
            </nav>
            <div class="ruvelo-cs-mail">
                <dl class="ruvelo-cs-mail-head">
                    <div><dt><?php esc_html_e('From', 'ruvelo-comment-subscriptions'); ?></dt><dd><?php echo esc_html(Settings::fromName()); ?></dd></div>
                    <div><dt><?php esc_html_e('To', 'ruvelo-comment-subscriptions'); ?></dt><dd><?php echo esc_html($user->user_email); ?></dd></div>
                    <div><dt><?php esc_html_e('Subject', 'ruvelo-comment-subscriptions'); ?></dt><dd><strong><?php echo esc_html($email['subject']); ?></strong></dd></div>
                </dl>
                <iframe class="ruvelo-cs-mail-frame" title="<?php echo esc_attr(self::labels()[$current]); ?>" sandbox="" srcdoc="<?php echo esc_attr($email['html']); ?>"></iframe>
                <details class="ruvelo-cs-mail-text">
                    <summary><?php esc_html_e('Plain-text version', 'ruvelo-comment-subscriptions'); ?></summary>
                    <pre><?php echo esc_html($email['text']); ?></pre>
                </details>
            </div>
            <div class="ruvelo-cs-preview-actions">
                <a class="button" href="<?php echo esc_url($raw); ?>" target="_blank" rel="noopener"><?php esc_html_e('Open in a new tab', 'ruvelo-comment-subscriptions'); ?></a>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="ruvelo_cs_send_test">
                    <input type="hidden" name="email" value="<?php echo esc_attr($current); ?>">
                    <?php wp_nonce_field('ruvelo_cs_send_test'); ?>
                    <button type="submit" class="button button-primary">
                        <?php
                        /* translators: %s: email address */
                        echo esc_html(sprintf(__('Send a test to %s', 'ruvelo-comment-subscriptions'), $user->user_email));
        ?>
                    </button>
                </form>
            </div>
        </div>
        <?php
    }
}
