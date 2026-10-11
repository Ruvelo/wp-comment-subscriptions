<?php
/**
 * The HTML email around every message: a table layout email clients agree
 * on, inline styles, system fonts (no remote fonts or images), and a dark
 * variant for clients that support it.
 *
 * Override: copy to <theme>/ruvelo-comment-subscriptions/emails/layout.php.
 *
 * @var array<string, mixed> $view subject, preheader, content (HTML), template, site_name, site_url, and each template's own data.
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

$ruvelo_font = "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif";
$ruvelo_post = isset($view['post_title']) && is_string($view['post_title']) ? $view['post_title'] : '';
$ruvelo_unsubscribe = isset($view['unsubscribe_url']) && is_string($view['unsubscribe_url']) ? $view['unsubscribe_url'] : '';
$ruvelo_manage = isset($view['manage_url']) && is_string($view['manage_url']) ? $view['manage_url'] : '';
?>
<!doctype html>
<html lang="<?php echo esc_attr((string) get_bloginfo('language')); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light dark">
<meta name="supported-color-schemes" content="light dark">
<title><?php echo esc_html((string) $view['subject']); ?></title>
<style>
body { margin: 0; padding: 0; background: #f8f8fc; }
a { color: #2b38d6; }
.rcs-btn:hover { background: #2b38d6 !important; }
@media (prefers-color-scheme: dark) {
    body, .rcs-bg { background: #11111c !important; }
    .rcs-card { background: #171725 !important; border-color: #2a2a3f !important; }
    .rcs-ink { color: #f1f1f8 !important; }
    .rcs-t2 { color: #b6b6cc !important; }
    .rcs-t3 { color: #8787a3 !important; }
    .rcs-quote { background: #1e2150 !important; }
    .rcs-avatar { background: #11111c !important; color: #b3bbff !important; }
    .rcs-line { border-color: #2a2a3f !important; }
    .rcs-btn { background: #8f9bff !important; color: #0b0b1a !important; }
    a { color: #b3bbff !important; }
}
@media (max-width: 600px) {
    .rcs-pad { padding: 26px 22px !important; }
    .rcs-h1 { font-size: 21px !important; }
}
</style>
</head>
<body class="rcs-bg" style="margin:0;padding:0;background:#f8f8fc;">
<div style="display:none;max-height:0;max-width:0;overflow:hidden;opacity:0;mso-hide:all;"><?php echo esc_html((string) $view['preheader']); ?>&#8199;&#847;&#8199;&#847;&#8199;&#847;&#8199;&#847;</div>
<table role="presentation" class="rcs-bg" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f8f8fc;">
    <tr>
        <td align="center" style="padding:32px 12px 40px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:560px;">
                <tr>
                    <td class="rcs-ink" style="padding:0 6px 14px;font:600 15px/1.4 <?php echo $ruvelo_font; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- A constant font stack.?>;color:#16162a;letter-spacing:-0.01em;">
                        <a href="<?php echo esc_url((string) $view['site_url']); ?>" class="rcs-ink" style="color:#16162a;text-decoration:none;"><?php echo esc_html((string) $view['site_name']); ?></a>
                    </td>
                </tr>
                <tr>
                    <td class="rcs-card" style="background:#ffffff;border:1px solid #e4e4ef;border-radius:14px;overflow:hidden;">
                        <div style="height:3px;line-height:3px;font-size:0;background:#3d4eff;background-image:linear-gradient(90deg,#3d4eff,#a78bfa);border-radius:14px 14px 0 0;">&nbsp;</div>
                        <div class="rcs-pad rcs-t2" style="padding:32px 36px 34px;font:400 15px/1.6 <?php echo $ruvelo_font; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- A constant font stack.?>;color:#4b4b63;">
                            <?php echo $view['content']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in the email template.?>
                        </div>
                    </td>
                </tr>
                <tr>
                    <td class="rcs-t3" style="padding:20px 8px 0;font:400 13px/1.6 <?php echo $ruvelo_font; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- A constant font stack.?>;color:#74748b;">
                        <?php if ($view['template'] === 'notification' && $ruvelo_unsubscribe !== '') { ?>
                            <p style="margin:0 0 8px;">
                                <?php
                                /* translators: 1: post title, 2: site name */
                                echo esc_html(sprintf(__('You’re getting this because you follow the comments on “%1$s” at %2$s.', 'ruvelo-comment-subscriptions'), $ruvelo_post, (string) $view['site_name']));
                            ?>
                            </p>
                            <p style="margin:0;">
                                <a href="<?php echo esc_url($ruvelo_unsubscribe); ?>" style="color:#4b4b63;"><?php esc_html_e('Unsubscribe from this post', 'ruvelo-comment-subscriptions'); ?></a>
                                &nbsp;·&nbsp;
                                <a href="<?php echo esc_url($ruvelo_manage); ?>" style="color:#4b4b63;"><?php esc_html_e('Manage your subscriptions', 'ruvelo-comment-subscriptions'); ?></a>
                            </p>
                        <?php } elseif ($view['template'] === 'digest') { ?>
                            <p style="margin:0 0 8px;">
                                <?php
                            /* translators: %s: site name */
                            echo esc_html(sprintf(__('You’re getting this daily digest because you follow comments at %s.', 'ruvelo-comment-subscriptions'), (string) $view['site_name']));
                            ?>
                            </p>
                            <p style="margin:0;">
                                <a href="<?php echo esc_url($ruvelo_manage); ?>" style="color:#4b4b63;"><?php esc_html_e('Manage your subscriptions', 'ruvelo-comment-subscriptions'); ?></a>
                                &nbsp;·&nbsp;
                                <a href="<?php echo esc_url($ruvelo_unsubscribe); ?>" style="color:#4b4b63;"><?php esc_html_e('Unsubscribe from everything', 'ruvelo-comment-subscriptions'); ?></a>
                            </p>
                        <?php } else { ?>
                            <p style="margin:0;">
                                <?php
                            /* translators: %s: site name */
                            echo esc_html(sprintf(__('Someone entered this address at %s. If it wasn’t you, ignore this email: you won’t hear from us again.', 'ruvelo-comment-subscriptions'), (string) $view['site_name']));
                            ?>
                            </p>
                        <?php } ?>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
