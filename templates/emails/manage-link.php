<?php
/**
 * A link to the manage page, sent on request.
 *
 * @var array<string, mixed> $view manage_url, days, count.
 */

declare(strict_types=1);

defined('ABSPATH') || exit;
?>
<h1 class="rcs-ink rcs-h1" style="margin:0 0 14px;font-size:23px;line-height:1.25;font-weight:600;letter-spacing:-0.02em;color:#16162a;"><?php esc_html_e('Your comment subscriptions', 'ruvelo-comment-subscriptions'); ?></h1>
<p style="margin:0 0 22px;">
    <?php
    echo esc_html(
        (int) $view['count'] > 0
            /* translators: %s: number of posts */
            ? sprintf(_n('You follow the comments on %s post. Change how often you hear, or unsubscribe, from this page.', 'You follow the comments on %s posts. Change how often you hear, or unsubscribe, from this page.', (int) $view['count'], 'ruvelo-comment-subscriptions'), number_format_i18n((int) $view['count']))
            : __('Change how often you hear, or unsubscribe, from this page.', 'ruvelo-comment-subscriptions')
    );
?>
</p>
<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 24px;">
    <tr>
        <td style="border-radius:8px;background:#3d4eff;">
            <a class="rcs-btn" href="<?php echo esc_url((string) $view['manage_url']); ?>" style="display:inline-block;padding:12px 22px;border-radius:8px;background:#3d4eff;color:#ffffff;font-weight:600;font-size:15px;line-height:1.2;text-decoration:none;"><?php esc_html_e('Manage my subscriptions', 'ruvelo-comment-subscriptions'); ?></a>
        </td>
    </tr>
</table>
<p class="rcs-t3" style="margin:0;font-size:13px;color:#74748b;">
    <?php
    /* translators: %d: number of days */
    echo esc_html(sprintf(_n('The link works for %d day and only for you: don’t forward this email.', 'The link works for %d days and only for you: don’t forward this email.', (int) $view['days'], 'ruvelo-comment-subscriptions'), (int) $view['days']));
?>
</p>
