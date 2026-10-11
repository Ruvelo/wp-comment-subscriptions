<?php
/**
 * Confirmation email (double opt-in).
 *
 * @var array<string, mixed> $view post_title, post_url, type ("all" or "replies"), confirm_url, days.
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

$ruvelo_what = $view['type'] === 'replies'
    ? __('replies to your comments', 'ruvelo-comment-subscriptions')
    : __('new comments', 'ruvelo-comment-subscriptions');
?>
<h1 class="rcs-ink rcs-h1" style="margin:0 0 14px;font-size:23px;line-height:1.25;font-weight:600;letter-spacing:-0.02em;color:#16162a;"><?php esc_html_e('Confirm your subscription', 'ruvelo-comment-subscriptions'); ?></h1>
<p style="margin:0 0 22px;">
    <?php
    printf(
        /* translators: 1: "new comments" or "replies to your comments", 2: linked post title */
        esc_html__('Click the button to get an email when there are %1$s on %2$s.', 'ruvelo-comment-subscriptions'),
        esc_html($ruvelo_what),
        '<a href="'.esc_url((string) $view['post_url']).'" style="color:#2b38d6;font-weight:600;text-decoration:none;">'.esc_html((string) $view['post_title']).'</a>'
    );
?>
</p>
<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 24px;">
    <tr>
        <td style="border-radius:8px;background:#3d4eff;">
            <a class="rcs-btn" href="<?php echo esc_url((string) $view['confirm_url']); ?>" style="display:inline-block;padding:12px 22px;border-radius:8px;background:#3d4eff;color:#ffffff;font-weight:600;font-size:15px;line-height:1.2;text-decoration:none;"><?php esc_html_e('Yes, subscribe me', 'ruvelo-comment-subscriptions'); ?></a>
        </td>
    </tr>
</table>
<p class="rcs-t3" style="margin:0 0 6px;font-size:13px;color:#74748b;">
    <?php
    /* translators: %d: number of days */
    echo esc_html(sprintf(_n('The link works for %d day. Every email has a one-click unsubscribe link.', 'The link works for %d days. Every email has a one-click unsubscribe link.', (int) $view['days'], 'ruvelo-comment-subscriptions'), (int) $view['days']));
?>
</p>
<p class="rcs-t3" style="margin:0;font-size:13px;color:#74748b;word-break:break-all;">
    <?php esc_html_e('Button not working? Paste this into your browser:', 'ruvelo-comment-subscriptions'); ?><br>
    <a href="<?php echo esc_url((string) $view['confirm_url']); ?>" style="color:#2b38d6;"><?php echo esc_html((string) $view['confirm_url']); ?></a>
</p>
