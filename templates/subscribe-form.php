<?php
/**
 * "Subscribe without commenting": the Subscribe Form block, the
 * [ruvelo_subscribe] shortcode, and the compact form under the comment form.
 *
 * Override: copy to <theme>/ruvelo-comment-subscriptions/subscribe-form.php.
 *
 * @var array<string, mixed> $view wrapper, post_id, post_title, show_heading, compact, signed_in, user_email, existing, message, return, manage_url.
 */

declare(strict_types=1);

use Ruvelo\WP\CommentSubscriptions\Subscription;

defined('ABSPATH') || exit;

$ruvelo_existing = $view['existing'] instanceof Subscription ? $view['existing'] : null;
$ruvelo_message = is_array($view['message']) && $view['message']['code'] !== 'confirm-comment' ? $view['message'] : null;
$ruvelo_id = 'ruvelo-cs-email-'.(int) $view['post_id'];
$ruvelo_compact = (bool) $view['compact'];
?>
<div <?php echo $view['wrapper']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped attributes.?> id="ruvelo-cs-subscribe">
    <?php if ($ruvelo_compact) { ?>
    <details class="ruvelo-cs-sub"<?php echo $ruvelo_message !== null ? ' open' : ''; ?>>
        <summary class="ruvelo-cs-sub__summary"><?php esc_html_e('Subscribe without commenting', 'ruvelo-comment-subscriptions'); ?></summary>
    <?php } else { ?>
    <div class="ruvelo-cs-sub ruvelo-cs-card">
        <?php if ($view['show_heading']) { ?>
            <h2 class="ruvelo-cs-sub__title"><?php esc_html_e('Follow the conversation', 'ruvelo-comment-subscriptions'); ?></h2>
        <?php } ?>
    <?php } ?>
        <?php if ($ruvelo_existing !== null && $ruvelo_existing->isActive()) { ?>
            <p class="ruvelo-cs-sub__text">
                <?php echo esc_html($ruvelo_existing->type === 'all' ? __('You get an email for every new comment on this post.', 'ruvelo-comment-subscriptions') : __('You get an email when someone replies to your comments here.', 'ruvelo-comment-subscriptions')); ?>
                <a href="<?php echo esc_url((string) $view['manage_url']); ?>"><?php esc_html_e('Manage subscriptions', 'ruvelo-comment-subscriptions'); ?></a>
            </p>
        <?php } ?>
        <?php if ($ruvelo_existing === null || $ruvelo_existing->type !== 'all') { ?>
        <form class="ruvelo-cs-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" data-ruvelo-cs-subscribe>
            <input type="hidden" name="action" value="ruvelo_cs_subscribe">
            <input type="hidden" name="post_id" value="<?php echo esc_attr((string) $view['post_id']); ?>">
            <?php wp_nonce_field('ruvelo_cs_subscribe', '_ruvelo_cs_nonce', false); ?>
            <div class="ruvelo-cs-hp" aria-hidden="true">
                <label><?php esc_html_e('Leave this empty', 'ruvelo-comment-subscriptions'); ?> <input type="text" name="website" value="" tabindex="-1" autocomplete="off"></label>
            </div>
            <?php if ($view['signed_in']) { ?>
                <p class="ruvelo-cs-sub__text">
                    <?php
                    /* translators: 1: post title, 2: the user's email address */
                    echo esc_html(sprintf(__('Get an email when someone comments on “%1$s”. We’ll send it to %2$s.', 'ruvelo-comment-subscriptions'), (string) $view['post_title'], (string) $view['user_email']));
                ?>
                </p>
                <div class="ruvelo-cs-row">
                    <button type="submit" class="ruvelo-cs-button"><?php esc_html_e('Subscribe', 'ruvelo-comment-subscriptions'); ?></button>
                </div>
            <?php } else { ?>
                <p class="ruvelo-cs-sub__text">
                    <?php
                /* translators: %s: post title */
                echo esc_html(sprintf(__('Get an email when someone comments on “%s”, without leaving a comment yourself.', 'ruvelo-comment-subscriptions'), (string) $view['post_title']));
                ?>
                </p>
                <label class="ruvelo-cs-label" for="<?php echo esc_attr($ruvelo_id); ?>"><?php esc_html_e('Your email', 'ruvelo-comment-subscriptions'); ?></label>
                <div class="ruvelo-cs-row">
                    <input class="ruvelo-cs-input" type="email" id="<?php echo esc_attr($ruvelo_id); ?>" name="email" required autocomplete="email" inputmode="email" placeholder="<?php esc_attr_e('you@example.com', 'ruvelo-comment-subscriptions'); ?>">
                    <button type="submit" class="ruvelo-cs-button"><?php esc_html_e('Subscribe', 'ruvelo-comment-subscriptions'); ?></button>
                </div>
                <p class="ruvelo-cs-help"><?php esc_html_e('We’ll email you a link to confirm. Unsubscribe in one click, any time.', 'ruvelo-comment-subscriptions'); ?></p>
            <?php } ?>
            <p class="ruvelo-cs-msg<?php echo $ruvelo_message !== null ? ' is-'.esc_attr($ruvelo_message['tone']) : ''; ?>" role="status" aria-live="polite" data-ruvelo-cs-msg><?php echo $ruvelo_message !== null ? esc_html($ruvelo_message['text']) : ''; ?></p>
        </form>
        <?php } ?>
    <?php if ($ruvelo_compact) { ?>
    </details>
    <?php } else { ?>
    </div>
    <?php } ?>
</div>
