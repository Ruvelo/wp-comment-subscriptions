<?php

declare(strict_types=1);

namespace Ruvelo\WP\CommentSubscriptions\Admin;

use Ruvelo\WP\CommentSubscriptions\Inbox;
use Ruvelo\WP\CommentSubscriptions\Settings;
use Ruvelo\WP\CommentSubscriptions\Subscriptions;
use WP_User;

/**
 * Signed-in users manage their subscriptions from their profile.
 */
final class Profile
{
    public static function register(): void
    {
        add_action('show_user_profile', [self::class, 'render']);
        add_action('edit_user_profile', [self::class, 'render']);
        add_action('personal_options_update', [self::class, 'save']);
        add_action('edit_user_profile_update', [self::class, 'save']);
    }

    public static function render(WP_User $user): void
    {
        $own = $user->ID === get_current_user_id();
        if (! $own && ! current_user_can(Settings::CAP)) {
            return;
        }
        $subscriptions = Subscriptions::forUser($user->ID);
        $frequency = $subscriptions !== [] ? $subscriptions[0]->frequency : Settings::frequency();
        wp_nonce_field('ruvelo_cs_profile_'.$user->ID, '_ruvelo_cs_profile_nonce');
        ?>
        <h2 id="ruvelo-comment-subscriptions"><?php esc_html_e('Comment subscriptions', 'ruvelo-comment-subscriptions'); ?></h2>
        <table class="form-table ruvelo-cs-profile" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e('Posts you follow', 'ruvelo-comment-subscriptions'); ?></th>
                <td>
                    <?php if ($subscriptions === []) { ?>
                        <p class="description"><?php esc_html_e('None yet. Tick “Notify me” under a comment form to follow a conversation.', 'ruvelo-comment-subscriptions'); ?></p>
                    <?php } else { ?>
                        <fieldset>
                            <legend class="screen-reader-text"><?php esc_html_e('Posts you follow', 'ruvelo-comment-subscriptions'); ?></legend>
                            <ul class="ruvelo-cs-profile-list">
                                <?php foreach ($subscriptions as $subscription) { ?>
                                    <li>
                                        <span class="ruvelo-cs-profile-post">
                                            <a href="<?php echo esc_url((string) get_permalink($subscription->postId)); ?>"><?php echo esc_html(wp_specialchars_decode(get_the_title($subscription->postId), ENT_QUOTES)); ?></a>
                                            <span class="description"><?php echo esc_html($subscription->typeLabel()); ?></span>
                                        </span>
                                        <label><input type="checkbox" name="ruvelo_cs_unsubscribe[]" value="<?php echo esc_attr((string) $subscription->id); ?>"> <?php esc_html_e('Unsubscribe', 'ruvelo-comment-subscriptions'); ?></label>
                                    </li>
                                <?php } ?>
                            </ul>
                        </fieldset>
                    <?php } ?>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('How often', 'ruvelo-comment-subscriptions'); ?></th>
                <td>
                    <fieldset>
                        <legend class="screen-reader-text"><?php esc_html_e('How often', 'ruvelo-comment-subscriptions'); ?></legend>
                        <label class="ruvelo-cs-check"><input type="radio" name="ruvelo_cs_frequency" value="instant" <?php checked($frequency, 'instant'); ?>> <?php esc_html_e('An email per comment', 'ruvelo-comment-subscriptions'); ?></label>
                        <label class="ruvelo-cs-check"><input type="radio" name="ruvelo_cs_frequency" value="daily" <?php checked($frequency, 'daily'); ?>> <?php esc_html_e('A daily digest', 'ruvelo-comment-subscriptions'); ?></label>
                    </fieldset>
                </td>
            </tr>
            <?php if (Inbox::available()) { ?>
                <tr>
                    <th scope="row"><?php esc_html_e('Where', 'ruvelo-comment-subscriptions'); ?></th>
                    <td>
                        <fieldset>
                            <legend class="screen-reader-text"><?php esc_html_e('Where', 'ruvelo-comment-subscriptions'); ?></legend>
                            <?php
                            $choice = Inbox::preference($user->ID);
                foreach (['email' => __('Email', 'ruvelo-comment-subscriptions'), 'inbox' => __('My inbox on this site', 'ruvelo-comment-subscriptions'), 'both' => __('Both', 'ruvelo-comment-subscriptions')] as $value => $label) {
                    ?>
                                <label class="ruvelo-cs-check"><input type="radio" name="ruvelo_cs_delivery" value="<?php echo esc_attr($value); ?>" <?php checked($choice, $value); ?>> <?php echo esc_html($label); ?></label>
                            <?php } ?>
                        </fieldset>
                    </td>
                </tr>
            <?php } ?>
        </table>
        <?php
    }

    public static function save(int $userId): void
    {
        if (! isset($_POST['_ruvelo_cs_profile_nonce']) || ! wp_verify_nonce(sanitize_key(wp_unslash($_POST['_ruvelo_cs_profile_nonce'])), 'ruvelo_cs_profile_'.$userId)) {
            return;
        }
        if ($userId !== get_current_user_id() && ! current_user_can(Settings::CAP)) {
            return;
        }
        if (! current_user_can('edit_user', $userId)) {
            return;
        }
        $owned = [];
        foreach (Subscriptions::forUser($userId) as $subscription) {
            $owned[$subscription->id] = $subscription;
        }
        $ids = isset($_POST['ruvelo_cs_unsubscribe']) && is_array($_POST['ruvelo_cs_unsubscribe']) ? array_map('absint', wp_unslash($_POST['ruvelo_cs_unsubscribe'])) : [];
        foreach ($ids as $id) {
            if (isset($owned[$id])) {
                Subscriptions::delete($id);
                unset($owned[$id]);
            }
        }
        $frequency = isset($_POST['ruvelo_cs_frequency']) ? sanitize_key(wp_unslash($_POST['ruvelo_cs_frequency'])) : '';
        foreach (array_unique(array_map(static fn ($s): string => $s->email, $owned)) as $email) {
            Subscriptions::setFrequency($email, $frequency);
        }
        if (isset($_POST['ruvelo_cs_delivery']) && Inbox::available()) {
            Inbox::setPreference($userId, sanitize_key(wp_unslash($_POST['ruvelo_cs_delivery'])));
        }
    }
}
