<?php
/**
 * The subscriptions page: confirm, unsubscribe, manage, or ask for a link.
 * Every change is a small form that posts, so it all works without JavaScript.
 *
 * Override: copy to <theme>/ruvelo-comment-subscriptions/page.php.
 *
 * @var array<string, mixed> $view wrapper, state, email, subscriptions, frequency, inbox, delivery, message, action, self.
 */

declare(strict_types=1);

use Ruvelo\WP\CommentSubscriptions\Frontend\Route;
use Ruvelo\WP\CommentSubscriptions\Settings;
use Ruvelo\WP\CommentSubscriptions\Subscription;

defined('ABSPATH') || exit;

/** @var array{view: string, token: string, msg: string, email: string, user: int, subscription: Subscription|null, all: bool, post: int} $ruvelo_state */
$ruvelo_state = $view['state'];
/** @var list<Subscription> $ruvelo_subscriptions */
$ruvelo_subscriptions = $view['subscriptions'];
$ruvelo_message = is_array($view['message']) ? $view['message'] : null;
$ruvelo_sub = $ruvelo_state['subscription'];
$ruvelo_site = Settings::siteName();
$ruvelo_post_link = static function (int $postId): string {
    $title = get_the_title($postId);

    return '<a href="'.esc_url((string) get_permalink($postId)).'">'.esc_html(wp_specialchars_decode($title !== '' ? $title : __('(no title)', 'ruvelo-comment-subscriptions'), ENT_QUOTES)).'</a>';
};
$ruvelo_request_form = static function () use ($view): void {
    ?>
    <form class="ruvelo-cs-form ruvelo-cs-request" method="post" action="<?php echo esc_url((string) $view['action']); ?>">
        <input type="hidden" name="action" value="ruvelo_cs_request_link">
        <?php wp_nonce_field('ruvelo_cs_request_link', '_ruvelo_cs_nonce', false); ?>
        <div class="ruvelo-cs-hp" aria-hidden="true">
            <label><?php esc_html_e('Leave this empty', 'ruvelo-comment-subscriptions'); ?> <input type="text" name="website" value="" tabindex="-1" autocomplete="off"></label>
        </div>
        <label class="ruvelo-cs-label" for="ruvelo-cs-request-email"><?php esc_html_e('Your email', 'ruvelo-comment-subscriptions'); ?></label>
        <div class="ruvelo-cs-row">
            <input class="ruvelo-cs-input" type="email" id="ruvelo-cs-request-email" name="email" required autocomplete="email" inputmode="email" placeholder="<?php esc_attr_e('you@example.com', 'ruvelo-comment-subscriptions'); ?>">
            <button type="submit" class="ruvelo-cs-button"><?php esc_html_e('Email me a link', 'ruvelo-comment-subscriptions'); ?></button>
        </div>
        <p class="ruvelo-cs-help"><?php esc_html_e('For your privacy we never show here which posts an address follows: the link goes to that inbox.', 'ruvelo-comment-subscriptions'); ?></p>
    </form>
    <?php
};
$ruvelo_hidden = static function (string $view_name) use ($ruvelo_state): void {
    ?>
    <input type="hidden" name="rcs" value="<?php echo esc_attr($view_name); ?>">
    <input type="hidden" name="rcs_token" value="<?php echo esc_attr($ruvelo_state['token']); ?>">
    <?php
    wp_nonce_field('ruvelo_cs_'.$view_name, '_ruvelo_cs_nonce', false);
};
$ruvelo_manage_fields = static function (string $op, int $id = 0) use ($ruvelo_state): void {
    ?>
    <input type="hidden" name="action" value="ruvelo_cs_manage">
    <input type="hidden" name="op" value="<?php echo esc_attr($op); ?>">
    <?php if ($id > 0) { ?><input type="hidden" name="id" value="<?php echo esc_attr((string) $id); ?>"><?php } ?>
    <input type="hidden" name="rcs_token" value="<?php echo esc_attr($ruvelo_state['token']); ?>">
    <?php
    wp_nonce_field('ruvelo_cs_manage', '_ruvelo_cs_nonce', false);
};
?>
<div <?php echo $view['wrapper']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped attributes.?>>
    <p class="ruvelo-cs-eyebrow"><span class="ruvelo-cs-dot" aria-hidden="true"></span><?php echo esc_html($ruvelo_site); ?> · <?php esc_html_e('Comment subscriptions', 'ruvelo-comment-subscriptions'); ?></p>

    <?php if ($ruvelo_message !== null) { ?>
        <p class="ruvelo-cs-notice is-<?php echo esc_attr($ruvelo_message['tone']); ?>" role="<?php echo $ruvelo_message['tone'] === 'danger' ? 'alert' : 'status'; ?>"><?php echo esc_html($ruvelo_message['text']); ?></p>
    <?php } ?>

    <?php if ($ruvelo_state['view'] === 'confirm' && $ruvelo_sub instanceof Subscription) { ?>
        <h1 class="ruvelo-cs-title"><?php esc_html_e('Confirm your subscription', 'ruvelo-comment-subscriptions'); ?></h1>
        <p class="ruvelo-cs-lede">
            <?php
            printf(
                /* translators: 1: "new comments" or "replies to your comments", 2: linked post title */
                esc_html__('Get an email when there are %1$s on %2$s.', 'ruvelo-comment-subscriptions'),
                esc_html($ruvelo_sub->type === 'replies' ? __('replies to your comments', 'ruvelo-comment-subscriptions') : __('new comments', 'ruvelo-comment-subscriptions')),
                $ruvelo_post_link($ruvelo_sub->postId) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in the closure.
            );
        ?>
        </p>
        <form class="ruvelo-cs-actions" method="post" action="<?php echo esc_url((string) $view['self']); ?>">
            <?php $ruvelo_hidden('confirm'); ?>
            <button type="submit" class="ruvelo-cs-button"><?php esc_html_e('Confirm my subscription', 'ruvelo-comment-subscriptions'); ?></button>
        </form>
        <p class="ruvelo-cs-help"><?php esc_html_e('Didn’t ask for this? Close this page: nothing happens unless you press the button.', 'ruvelo-comment-subscriptions'); ?></p>

    <?php } elseif ($ruvelo_state['view'] === 'unsubscribe') { ?>
        <h1 class="ruvelo-cs-title"><?php esc_html_e('Unsubscribe', 'ruvelo-comment-subscriptions'); ?></h1>
        <?php if ($ruvelo_state['all']) { ?>
            <p class="ruvelo-cs-lede">
                <?php
                /* translators: 1: site name, 2: email address */
                echo esc_html(sprintf(__('Stop every comment email from %1$s to %2$s?', 'ruvelo-comment-subscriptions'), $ruvelo_site, $ruvelo_state['email']));
            ?>
            </p>
            <form class="ruvelo-cs-actions" method="post" action="<?php echo esc_url((string) $view['self']); ?>">
                <?php $ruvelo_hidden('unsubscribe'); ?>
                <input type="hidden" name="all" value="1">
                <button type="submit" class="ruvelo-cs-button"><?php esc_html_e('Unsubscribe from everything', 'ruvelo-comment-subscriptions'); ?></button>
            </form>
        <?php } elseif ($ruvelo_sub instanceof Subscription) { ?>
            <p class="ruvelo-cs-lede">
                <?php
                printf(
                    /* translators: %s: linked post title */
                    esc_html__('Stop emails about new comments on %s?', 'ruvelo-comment-subscriptions'),
                    $ruvelo_post_link($ruvelo_sub->postId) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in the closure.
                );
            ?>
            </p>
            <form class="ruvelo-cs-actions" method="post" action="<?php echo esc_url((string) $view['self']); ?>">
                <?php $ruvelo_hidden('unsubscribe'); ?>
                <button type="submit" class="ruvelo-cs-button"><?php esc_html_e('Unsubscribe from this post', 'ruvelo-comment-subscriptions'); ?></button>
                <button type="submit" name="all" value="1" class="ruvelo-cs-button is-secondary"><?php esc_html_e('Unsubscribe from everything', 'ruvelo-comment-subscriptions'); ?></button>
            </form>
        <?php } ?>

    <?php } elseif ($ruvelo_state['view'] === 'manage') { ?>
        <h1 class="ruvelo-cs-title"><?php esc_html_e('Your comment subscriptions', 'ruvelo-comment-subscriptions'); ?></h1>
        <p class="ruvelo-cs-lede">
            <?php
            /* translators: %s: email address */
            echo esc_html(sprintf(__('Emails go to %s.', 'ruvelo-comment-subscriptions'), (string) $view['email']));
        ?>
            <?php if ($ruvelo_state['user'] === 0) { ?>
                <?php esc_html_e('This page’s link is just for you: please don’t share it.', 'ruvelo-comment-subscriptions'); ?>
            <?php } ?>
        </p>

        <?php if ($ruvelo_subscriptions === []) { ?>
            <div class="ruvelo-cs-empty">
                <p><strong><?php esc_html_e('You’re not following any posts.', 'ruvelo-comment-subscriptions'); ?></strong></p>
                <p><?php esc_html_e('Tick “Notify me” under any comment form to follow a conversation.', 'ruvelo-comment-subscriptions'); ?></p>
            </div>
        <?php } else { ?>
            <ul class="ruvelo-cs-list" aria-label="<?php esc_attr_e('Posts you follow', 'ruvelo-comment-subscriptions'); ?>">
                <?php foreach ($ruvelo_subscriptions as $ruvelo_item) { ?>
                    <li class="ruvelo-cs-item">
                        <div class="ruvelo-cs-item__main">
                            <p class="ruvelo-cs-item__title"><?php echo $ruvelo_post_link($ruvelo_item->postId); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in the closure.?></p>
                            <p class="ruvelo-cs-item__meta">
                                <?php
                            $ruvelo_since = strtotime(($ruvelo_item->confirmedAt ?? $ruvelo_item->createdAt).' UTC');
                    /* translators: %s: date */
                    echo esc_html(sprintf(__('Since %s', 'ruvelo-comment-subscriptions'), (string) wp_date((string) get_option('date_format'), $ruvelo_since !== false ? $ruvelo_since : time())));
                    ?>
                            </p>
                        </div>
                        <form class="ruvelo-cs-item__type" method="post" action="<?php echo esc_url((string) $view['action']); ?>">
                            <?php $ruvelo_manage_fields('type', $ruvelo_item->id); ?>
                            <label class="screen-reader-text ruvelo-cs-sr" for="ruvelo-cs-type-<?php echo esc_attr((string) $ruvelo_item->id); ?>"><?php esc_html_e('Email me about', 'ruvelo-comment-subscriptions'); ?></label>
                            <select class="ruvelo-cs-select" id="ruvelo-cs-type-<?php echo esc_attr((string) $ruvelo_item->id); ?>" name="type">
                                <option value="all" <?php selected($ruvelo_item->type, 'all'); ?>><?php esc_html_e('All new comments', 'ruvelo-comment-subscriptions'); ?></option>
                                <option value="replies" <?php selected($ruvelo_item->type, 'replies'); ?>><?php esc_html_e('Replies to my comments', 'ruvelo-comment-subscriptions'); ?></option>
                            </select>
                            <button type="submit" class="ruvelo-cs-button is-quiet"><?php esc_html_e('Save', 'ruvelo-comment-subscriptions'); ?></button>
                        </form>
                        <form class="ruvelo-cs-item__stop" method="post" action="<?php echo esc_url((string) $view['action']); ?>">
                            <?php $ruvelo_manage_fields('unsubscribe', $ruvelo_item->id); ?>
                            <button type="submit" class="ruvelo-cs-button is-secondary">
                                <?php esc_html_e('Unsubscribe', 'ruvelo-comment-subscriptions'); ?><span class="ruvelo-cs-sr">
                                <?php
                        /* translators: %s: post title */
                        echo esc_html(sprintf(__(' from “%s”', 'ruvelo-comment-subscriptions'), wp_specialchars_decode(get_the_title($ruvelo_item->postId), ENT_QUOTES)));
                    ?>
                                </span>
                            </button>
                        </form>
                    </li>
                <?php } ?>
            </ul>

            <form class="ruvelo-cs-panel" method="post" action="<?php echo esc_url((string) $view['action']); ?>">
                <?php $ruvelo_manage_fields('frequency'); ?>
                <fieldset class="ruvelo-cs-fieldset">
                    <legend><?php esc_html_e('How often', 'ruvelo-comment-subscriptions'); ?></legend>
                    <label class="ruvelo-cs-choice"><input type="radio" name="frequency" value="instant" <?php checked($view['frequency'], 'instant'); ?>> <span><strong><?php esc_html_e('As comments arrive', 'ruvelo-comment-subscriptions'); ?></strong><small><?php esc_html_e('One email per comment.', 'ruvelo-comment-subscriptions'); ?></small></span></label>
                    <label class="ruvelo-cs-choice"><input type="radio" name="frequency" value="daily" <?php checked($view['frequency'], 'daily'); ?>> <span><strong><?php esc_html_e('Once a day', 'ruvelo-comment-subscriptions'); ?></strong><small>
                    <?php
                /* translators: %s: time of day */
                echo esc_html(sprintf(__('A single digest around %s.', 'ruvelo-comment-subscriptions'), (string) wp_date((string) get_option('time_format'), (int) strtotime('today '.max(0, min(23, Settings::int('digest_hour'))).':00 UTC'), new DateTimeZone('UTC'))));
            ?>
                    </small></span></label>
                </fieldset>
                <?php if ($view['inbox']) { ?>
                    <fieldset class="ruvelo-cs-fieldset">
                        <legend><?php esc_html_e('Where', 'ruvelo-comment-subscriptions'); ?></legend>
                        <label class="ruvelo-cs-choice"><input type="radio" name="delivery" value="email" <?php checked($view['delivery'], 'email'); ?>> <span><strong><?php esc_html_e('Email', 'ruvelo-comment-subscriptions'); ?></strong></span></label>
                        <label class="ruvelo-cs-choice"><input type="radio" name="delivery" value="inbox" <?php checked($view['delivery'], 'inbox'); ?>> <span><strong><?php esc_html_e('My inbox on this site', 'ruvelo-comment-subscriptions'); ?></strong><small><?php esc_html_e('No emails.', 'ruvelo-comment-subscriptions'); ?></small></span></label>
                        <label class="ruvelo-cs-choice"><input type="radio" name="delivery" value="both" <?php checked($view['delivery'], 'both'); ?>> <span><strong><?php esc_html_e('Both', 'ruvelo-comment-subscriptions'); ?></strong></span></label>
                    </fieldset>
                <?php } ?>
                <button type="submit" class="ruvelo-cs-button"><?php esc_html_e('Save preferences', 'ruvelo-comment-subscriptions'); ?></button>
            </form>

            <form class="ruvelo-cs-stop-all" method="post" action="<?php echo esc_url((string) $view['action']); ?>">
                <?php $ruvelo_manage_fields('unsubscribe_all'); ?>
                <p><?php esc_html_e('Done with all of it?', 'ruvelo-comment-subscriptions'); ?></p>
                <button type="submit" class="ruvelo-cs-button is-danger"><?php esc_html_e('Unsubscribe from everything', 'ruvelo-comment-subscriptions'); ?></button>
            </form>
        <?php } ?>

    <?php } elseif ($ruvelo_state['view'] === 'invalid') { ?>
        <?php if (Route::param('rcs') === 'unsubscribe') { ?>
            <h1 class="ruvelo-cs-title"><?php esc_html_e('Nothing to unsubscribe', 'ruvelo-comment-subscriptions'); ?></h1>
            <p class="ruvelo-cs-lede"><?php esc_html_e('You’re not subscribed to this any more, or the link was changed on its way here. To see what you still follow, ask for a link:', 'ruvelo-comment-subscriptions'); ?></p>
        <?php } else { ?>
            <h1 class="ruvelo-cs-title"><?php esc_html_e('This link doesn’t work any more', 'ruvelo-comment-subscriptions'); ?></h1>
            <p class="ruvelo-cs-lede">
                <?php
                /* translators: 1: days a confirmation link works, 2: days a manage link works */
                echo esc_html(sprintf(__('Confirmation links work for %1$d days and manage links for %2$d, so old ones stop working. Ask for a new one:', 'ruvelo-comment-subscriptions'), max(1, Settings::int('pending_days')), max(1, Settings::int('link_days'))));
            ?>
            </p>
        <?php } ?>
        <?php $ruvelo_request_form(); ?>

    <?php } else { ?>
        <h1 class="ruvelo-cs-title"><?php esc_html_e('Manage your comment subscriptions', 'ruvelo-comment-subscriptions'); ?></h1>
        <p class="ruvelo-cs-lede"><?php esc_html_e('Enter the address you subscribed with and we’ll email you a link to see, change or stop your subscriptions.', 'ruvelo-comment-subscriptions'); ?></p>
        <?php $ruvelo_request_form(); ?>
    <?php } ?>
</div>
