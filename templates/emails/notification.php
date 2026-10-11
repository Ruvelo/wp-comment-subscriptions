<?php
/**
 * A new comment (or a reply to the subscriber's comment).
 *
 * @var array<string, mixed> $view post_title, post_url, comment_url, author, initials, date, excerpt, is_reply, parent_author, type, unsubscribe_url, manage_url.
 */

declare(strict_types=1);

defined('ABSPATH') || exit;
?>
<p class="rcs-t3" style="margin:0 0 6px;font-size:13px;color:#74748b;">
    <?php echo esc_html(! empty($view['is_reply']) ? __('New reply to your comment', 'ruvelo-comment-subscriptions') : __('New comment', 'ruvelo-comment-subscriptions')); ?>
</p>
<h1 class="rcs-ink rcs-h1" style="margin:0 0 20px;font-size:23px;line-height:1.25;font-weight:600;letter-spacing:-0.02em;color:#16162a;">
    <a href="<?php echo esc_url((string) $view['post_url']); ?>" class="rcs-ink" style="color:#16162a;text-decoration:none;"><?php echo esc_html((string) $view['post_title']); ?></a>
</h1>
<table role="presentation" class="rcs-quote" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#eef0ff;border-radius:14px;margin:0 0 24px;">
    <tr>
        <td style="padding:18px 20px;">
            <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 10px;">
                <tr>
                    <td class="rcs-avatar" width="34" height="34" align="center" valign="middle" style="width:34px;height:34px;border-radius:17px;background:#ffffff;color:#2b38d6;font-size:12px;font-weight:600;line-height:34px;"><?php echo esc_html((string) $view['initials']); ?></td>
                    <td style="padding-left:12px;line-height:1.35;">
                        <span class="rcs-ink" style="display:block;color:#16162a;font-weight:600;font-size:15px;"><?php echo esc_html((string) $view['author']); ?></span>
                        <span class="rcs-t3" style="display:block;color:#74748b;font-size:13px;">
                            <?php
                            if (! empty($view['is_reply'])) {
                                /* translators: %s: date */
                                echo esc_html(sprintf(__('%s · replied to your comment', 'ruvelo-comment-subscriptions'), (string) $view['date']));
                            } else {
                                echo esc_html((string) $view['date']);
                            }
?>
                        </span>
                    </td>
                </tr>
            </table>
            <?php if ((string) $view['excerpt'] !== '') { ?>
                <p class="rcs-ink" style="margin:0;color:#16162a;font-size:15px;line-height:1.65;"><?php echo nl2br(esc_html((string) $view['excerpt'])); ?></p>
            <?php } ?>
        </td>
    </tr>
</table>
<table role="presentation" cellpadding="0" cellspacing="0" border="0">
    <tr>
        <td style="border-radius:8px;background:#3d4eff;">
            <a class="rcs-btn" href="<?php echo esc_url((string) $view['comment_url']); ?>" style="display:inline-block;padding:12px 22px;border-radius:8px;background:#3d4eff;color:#ffffff;font-weight:600;font-size:15px;line-height:1.2;text-decoration:none;"><?php esc_html_e('Read and reply', 'ruvelo-comment-subscriptions'); ?></a>
        </td>
    </tr>
</table>
