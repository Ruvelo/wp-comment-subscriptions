<?php
/**
 * The subscription checkboxes inside the comment form, just above the
 * submit button. They inherit the theme's fonts and colours.
 *
 * Override: copy to <theme>/ruvelo-comment-subscriptions/comment-options.php.
 *
 * @var array<string, mixed> $view post_id, offer_replies, offer_all, checked, signed_in, existing (Subscription|null), manage_url, message.
 */

declare(strict_types=1);

use Ruvelo\WP\CommentSubscriptions\Frontend\CommentForm;
use Ruvelo\WP\CommentSubscriptions\Subscription;

defined('ABSPATH') || exit;

$ruvelo_existing = $view['existing'] instanceof Subscription ? $view['existing'] : null;
$ruvelo_message = is_array($view['message']) && $view['message']['code'] === 'confirm-comment' ? $view['message'] : null;
?>
<fieldset class="ruvelo-cs-options" id="ruvelo-cs-options">
    <legend class="ruvelo-cs-options__legend"><?php esc_html_e('Email notifications', 'ruvelo-comment-subscriptions'); ?></legend>
    <?php if ($ruvelo_message !== null) { ?>
        <p class="ruvelo-cs-options__notice" role="status"><?php echo esc_html($ruvelo_message['text']); ?></p>
    <?php } ?>
    <?php if ($ruvelo_existing !== null && $ruvelo_existing->type === 'all') { ?>
        <p class="ruvelo-cs-options__help">
            <?php esc_html_e('You get an email for every new comment on this post.', 'ruvelo-comment-subscriptions'); ?>
            <a href="<?php echo esc_url((string) $view['manage_url']); ?>"><?php esc_html_e('Manage subscriptions', 'ruvelo-comment-subscriptions'); ?></a>
        </p>
    <?php } else { ?>
        <?php if ($view['offer_replies'] && $ruvelo_existing === null) { ?>
            <p class="ruvelo-cs-options__choice">
                <input type="checkbox" id="ruvelo-cs-replies" name="<?php echo esc_attr(CommentForm::FIELD); ?>[]" value="replies" <?php checked($view['checked'], 'replies'); ?>>
                <label for="ruvelo-cs-replies"><?php esc_html_e('Notify me of replies to my comment', 'ruvelo-comment-subscriptions'); ?></label>
            </p>
        <?php } ?>
        <?php if ($view['offer_all']) { ?>
            <p class="ruvelo-cs-options__choice">
                <input type="checkbox" id="ruvelo-cs-all" name="<?php echo esc_attr(CommentForm::FIELD); ?>[]" value="all" <?php checked($view['checked'], 'all'); ?>>
                <label for="ruvelo-cs-all"><?php esc_html_e('Notify me of all new comments on this post', 'ruvelo-comment-subscriptions'); ?></label>
            </p>
        <?php } ?>
        <p class="ruvelo-cs-options__help">
            <?php if ($view['signed_in']) { ?>
                <?php esc_html_e('Every email has a one-click unsubscribe link.', 'ruvelo-comment-subscriptions'); ?>
                <a href="<?php echo esc_url((string) $view['manage_url']); ?>"><?php esc_html_e('Manage subscriptions', 'ruvelo-comment-subscriptions'); ?></a>
            <?php } else { ?>
                <?php esc_html_e('We’ll email you a link to confirm first. Every email has a one-click unsubscribe link.', 'ruvelo-comment-subscriptions'); ?>
            <?php } ?>
        </p>
    <?php } ?>
</fieldset>
