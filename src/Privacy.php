<?php

declare(strict_types=1);

namespace Ruvelo\WP\CommentSubscriptions;

/**
 * Tools → Export/Erase Personal Data, and the paragraph for the privacy policy.
 */
final class Privacy
{
    public const KEY = 'ruvelo-comment-subscriptions';

    public static function register(): void
    {
        add_filter('wp_privacy_personal_data_exporters', [self::class, 'exporters']);
        add_filter('wp_privacy_personal_data_erasers', [self::class, 'erasers']);
        add_action('admin_init', [self::class, 'policy']);
    }

    /**
     * @param array<string, mixed> $exporters
     * @return array<string, mixed>
     */
    public static function exporters(array $exporters): array
    {
        $exporters[self::KEY] = ['exporter_friendly_name' => __('Comment subscriptions', 'ruvelo-comment-subscriptions'), 'callback' => [self::class, 'export']];

        return $exporters;
    }

    /**
     * @param array<string, mixed> $erasers
     * @return array<string, mixed>
     */
    public static function erasers(array $erasers): array
    {
        $erasers[self::KEY] = ['eraser_friendly_name' => __('Comment subscriptions', 'ruvelo-comment-subscriptions'), 'callback' => [self::class, 'erase']];

        return $erasers;
    }

    /**
     * @return array{data: list<array<string, mixed>>, done: bool}
     */
    public static function export(string $email, int $page = 1): array
    {
        $data = [];
        foreach (self::subscriptions($email) as $subscription) {
            $data[] = [
                'group_id' => 'ruvelo-comment-subscriptions',
                'group_label' => __('Comment subscriptions', 'ruvelo-comment-subscriptions'),
                'group_description' => __('Posts whose comments you follow by email.', 'ruvelo-comment-subscriptions'),
                'item_id' => 'ruvelo-cs-'.$subscription->id,
                'data' => [
                    ['name' => __('Post', 'ruvelo-comment-subscriptions'), 'value' => get_the_title($subscription->postId)],
                    ['name' => __('Post address', 'ruvelo-comment-subscriptions'), 'value' => (string) get_permalink($subscription->postId)],
                    ['name' => __('Email', 'ruvelo-comment-subscriptions'), 'value' => $subscription->email],
                    ['name' => __('Emails about', 'ruvelo-comment-subscriptions'), 'value' => $subscription->typeLabel()],
                    ['name' => __('Status', 'ruvelo-comment-subscriptions'), 'value' => $subscription->isActive() ? __('Active', 'ruvelo-comment-subscriptions') : __('Waiting for confirmation', 'ruvelo-comment-subscriptions')],
                    ['name' => __('How often', 'ruvelo-comment-subscriptions'), 'value' => $subscription->frequency === 'daily' ? __('Daily digest', 'ruvelo-comment-subscriptions') : __('As comments arrive', 'ruvelo-comment-subscriptions')],
                    ['name' => __('Subscribed', 'ruvelo-comment-subscriptions'), 'value' => $subscription->createdAt.' UTC'],
                    ['name' => __('Confirmed', 'ruvelo-comment-subscriptions'), 'value' => $subscription->confirmedAt !== null ? $subscription->confirmedAt.' UTC' : '—'],
                ],
            ];
        }

        return ['data' => $data, 'done' => true];
    }

    /**
     * Deletes every subscription for the address (and for the account that
     * uses it), along with anything waiting in a digest.
     *
     * @return array{items_removed: bool, items_retained: bool, messages: list<string>, done: bool}
     */
    public static function erase(string $email, int $page = 1): array
    {
        $removed = false;
        foreach (self::subscriptions($email) as $subscription) {
            $removed = Subscriptions::delete($subscription->id, 'privacy') || $removed;
        }

        return ['items_removed' => $removed, 'items_retained' => false, 'messages' => [], 'done' => true];
    }

    /**
     * @return list<Subscription>
     */
    private static function subscriptions(string $email): array
    {
        $found = [];
        foreach (Subscriptions::forEmail($email, null) as $subscription) {
            $found[$subscription->id] = $subscription;
        }
        $user = get_user_by('email', $email);
        if ($user !== false) {
            foreach (Subscriptions::forUser($user->ID) as $subscription) {
                $found[$subscription->id] = $subscription;
            }
        }

        return array_values($found);
    }

    public static function policy(): void
    {
        if (! function_exists('wp_add_privacy_policy_content')) {
            return;
        }
        $text = '<p>'.__('When you subscribe to comments on a post, we store your email address, the post, whether you want all new comments or only replies to yours, how often you want to hear from us, and when you subscribed and confirmed. If you subscribe without an account, we first email you a link to confirm; unconfirmed requests are deleted after a few days. We use your address only to send you these notifications. Every email has a one-click unsubscribe link, and unsubscribing deletes the record. Nothing is shared with anyone outside this site.', 'ruvelo-comment-subscriptions').'</p>'
            .'<p>'.__('To stop people using this site to send unwanted email, we briefly keep a count of subscription requests per IP address and per email address, in a form that can’t be read back. These counts expire within a day.', 'ruvelo-comment-subscriptions').'</p>';
        wp_add_privacy_policy_content('Ruvelo Comment Subscriptions', wp_kses_post($text));
    }
}
