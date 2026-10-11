<?php
/**
 * The daily digest.
 *
 * @var array<string, mixed> $view posts (list of title, url, unsubscribe_url, comments: list of author, initials, date, excerpt, url), count, unsubscribe_url, manage_url.
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

$ruvelo_posts = is_array($view['posts']) ? $view['posts'] : [];
?>
<h1 class="rcs-ink rcs-h1" style="margin:0 0 6px;font-size:23px;line-height:1.25;font-weight:600;letter-spacing:-0.02em;color:#16162a;">
    <?php
    /* translators: %s: number of comments */
    echo esc_html(sprintf(_n('%s new comment', '%s new comments', (int) $view['count'], 'ruvelo-comment-subscriptions'), number_format_i18n((int) $view['count'])));
?>
</h1>
<p style="margin:0 0 22px;"><?php esc_html_e('On the posts you follow, since yesterday’s digest.', 'ruvelo-comment-subscriptions'); ?></p>
<?php foreach ($ruvelo_posts as $ruvelo_post) { ?>
    <h2 class="rcs-ink" style="margin:26px 0 12px;font-size:17px;line-height:1.3;font-weight:600;letter-spacing:-0.01em;color:#16162a;">
        <a href="<?php echo esc_url((string) $ruvelo_post['url']); ?>" class="rcs-ink" style="color:#16162a;text-decoration:none;"><?php echo esc_html((string) $ruvelo_post['title']); ?></a>
    </h2>
    <?php foreach ((array) $ruvelo_post['comments'] as $ruvelo_comment) { ?>
        <table role="presentation" class="rcs-quote" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#eef0ff;border-radius:14px;margin:0 0 10px;">
            <tr>
                <td style="padding:14px 18px;">
                    <p style="margin:0 0 4px;font-size:13px;line-height:1.4;">
                        <span class="rcs-ink" style="color:#16162a;font-weight:600;"><?php echo esc_html((string) $ruvelo_comment['author']); ?></span>
                        <span class="rcs-t3" style="color:#74748b;"> · <?php echo esc_html((string) $ruvelo_comment['date']); ?></span>
                    </p>
                    <?php if ((string) $ruvelo_comment['excerpt'] !== '') { ?>
                        <p class="rcs-ink" style="margin:0 0 6px;color:#16162a;font-size:15px;line-height:1.6;"><?php echo esc_html((string) $ruvelo_comment['excerpt']); ?></p>
                    <?php } ?>
                    <a href="<?php echo esc_url((string) $ruvelo_comment['url']); ?>" style="color:#2b38d6;font-size:13px;font-weight:600;text-decoration:none;"><?php esc_html_e('Read and reply →', 'ruvelo-comment-subscriptions'); ?></a>
                </td>
            </tr>
        </table>
    <?php } ?>
    <p class="rcs-t3" style="margin:6px 0 0;font-size:12px;color:#74748b;"><a href="<?php echo esc_url((string) $ruvelo_post['unsubscribe_url']); ?>" style="color:#74748b;"><?php esc_html_e('Stop following this post', 'ruvelo-comment-subscriptions'); ?></a></p>
<?php } ?>
