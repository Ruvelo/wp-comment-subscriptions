<?php

declare(strict_types=1);

namespace Ruvelo\WP\CommentSubscriptions\Frontend;

use Ruvelo\WP\CommentSubscriptions\Inbox;
use Ruvelo\WP\CommentSubscriptions\Mailer;
use Ruvelo\WP\CommentSubscriptions\Settings;
use Ruvelo\WP\CommentSubscriptions\Subscriptions;
use Ruvelo\WP\CommentSubscriptions\Urls;

/**
 * Server-side rendering for the blocks, the comment form and the
 * subscriptions page. Each view loads a template from templates/, which a
 * theme can override by copying it to <theme>/ruvelo-comment-subscriptions/<name>.php.
 */
final class Views
{
    /**
     * @param array<string, mixed> $view
     */
    public static function render(string $template, array $view): string
    {
        /*
         * Filters the template file used for a view or an email.
         *
         * @param string $file
         * @param string $template e.g. "page", "comment-options", "emails/notification".
         * @param array<string, mixed> $view
         */
        return Mailer::file($template, $view);
    }

    /**
     * Wrapper attributes: the scope class and the colour scheme.
     *
     * @return array<string, string>
     */
    public static function wrapperArgs(string $name, string $extra = ''): array
    {
        $args = ['class' => trim('ruvelo-cs ruvelo-cs--'.$name.' '.$extra)];
        $scheme = Settings::string('color_scheme');
        if ($scheme === 'light' || $scheme === 'dark') {
            $args['data-theme'] = $scheme;
        }

        return $args;
    }

    public static function wrapper(string $name, string $extra = ''): string
    {
        $html = [];
        foreach (self::wrapperArgs($name, $extra) as $key => $value) {
            $html[] = $key.'="'.esc_attr($value).'"';
        }

        return implode(' ', $html);
    }

    /**
     * The checkboxes under the comment form.
     */
    public static function commentOptions(int $postId): string
    {
        wp_enqueue_style('ruvelo-comment-subscriptions');
        $userId = get_current_user_id();
        $existing = $userId > 0 ? Subscriptions::findForUser($postId, $userId) : null;
        $checked = Settings::string('checked');

        return self::render('comment-options', [
            'post_id' => $postId,
            'offer_replies' => Settings::bool('offer_replies'),
            'offer_all' => Settings::bool('offer_all'),
            'checked' => in_array($checked, ['replies', 'all'], true) ? $checked : '',
            'signed_in' => $userId > 0,
            'existing' => $existing,
            'manage_url' => Urls::page(),
            'message' => self::message(),
        ]);
    }

    /**
     * "Subscribe without commenting": the block, the shortcode, and the
     * form under the comment form.
     *
     * @param array<string, mixed> $attributes postId, showHeading, compact.
     */
    public static function subscribeForm(array $attributes = [], string $wrapper = ''): string
    {
        $postId = (int) ($attributes['postId'] ?? 0);
        if ($postId <= 0) {
            $postId = (int) get_the_ID();
        }
        if ($postId <= 0 || Subscriptions::canSubscribeTo($postId) !== true) {
            return '';
        }
        wp_enqueue_style('ruvelo-comment-subscriptions');
        wp_enqueue_script('ruvelo-comment-subscriptions');
        $userId = get_current_user_id();
        $user = $userId > 0 ? get_userdata($userId) : false;

        return self::render('subscribe-form', [
            'wrapper' => $wrapper !== '' ? $wrapper : self::wrapper('subscribe', ! empty($attributes['compact']) ? 'is-compact' : ''),
            'post_id' => $postId,
            'post_title' => wp_specialchars_decode(get_the_title($postId), ENT_QUOTES),
            'show_heading' => ($attributes['showHeading'] ?? true) !== false,
            'compact' => ! empty($attributes['compact']),
            'signed_in' => $user !== false,
            'user_email' => $user !== false ? $user->user_email : '',
            'existing' => $userId > 0 ? Subscriptions::findForUser($postId, $userId) : null,
            'message' => self::message(),
            'return' => (string) get_permalink($postId),
            'manage_url' => Urls::page(),
        ]);
    }

    /**
     * The subscriptions page: confirm, unsubscribe, manage, or ask for a link.
     */
    public static function page(string $wrapper = ''): string
    {
        wp_enqueue_style('ruvelo-comment-subscriptions');
        $state = Route::state();
        $subscriptions = [];
        if ($state['view'] === 'manage') {
            $subscriptions = $state['user'] > 0 ? Subscriptions::forUser($state['user']) : Subscriptions::forEmail($state['email']);
        }
        $email = $state['email'];
        if ($state['user'] > 0) {
            $user = get_userdata($state['user']);
            $email = $user !== false ? $user->user_email : '';
        }
        $frequency = $subscriptions !== [] ? $subscriptions[0]->frequency : Settings::frequency();

        return self::render('page', [
            'wrapper' => $wrapper !== '' ? $wrapper : self::wrapper('page'),
            'state' => $state,
            'email' => $email,
            'subscriptions' => $subscriptions,
            'frequency' => $frequency,
            'inbox' => $state['user'] > 0 && Inbox::available(),
            'delivery' => $state['user'] > 0 ? Inbox::preference($state['user']) : 'email',
            'message' => self::message(),
            'action' => admin_url('admin-post.php'),
            'self' => Urls::page(array_filter(['rcs' => Route::param('rcs'), 'rcs_token' => $state['token']])),
        ]);
    }

    /**
     * The notice to show after a form posted, from `rcs_msg`.
     *
     * @return array{text: string, tone: string, code: string}|null
     */
    public static function message(): ?array
    {
        $code = sanitize_key(Route::param('rcs_msg'));
        $post = absint(Route::param('rcs_post'));
        $title = $post > 0 ? wp_specialchars_decode(get_the_title($post), ENT_QUOTES) : '';
        $messages = [
            'check-email' => [__('Almost done: check your inbox and click the link to confirm your subscription.', 'ruvelo-comment-subscriptions'), 'success'],
            'confirm-comment' => [__('Thanks! To start your email notifications, click the link we just sent you.', 'ruvelo-comment-subscriptions'), 'success'],
            'subscribed' => [__('You’re subscribed. We’ll email you when there are new comments.', 'ruvelo-comment-subscriptions'), 'success'],
            'confirmed' => [
                $title !== ''
                    /* translators: %s: post title */
                    ? sprintf(__('You’re subscribed to the comments on “%s”. Every email has a link to stop them.', 'ruvelo-comment-subscriptions'), $title)
                    : __('You’re subscribed. Every email has a link to stop them.', 'ruvelo-comment-subscriptions'),
                'success',
            ],
            'unsubscribed' => [
                $title !== ''
                    /* translators: %s: post title */
                    ? sprintf(__('Done. You won’t get more emails about “%s”.', 'ruvelo-comment-subscriptions'), $title)
                    : __('Done. You won’t get more emails about that post.', 'ruvelo-comment-subscriptions'),
                'success',
            ],
            'unsubscribed-all' => [__('Done. You won’t get any more comment emails from this site.', 'ruvelo-comment-subscriptions'), 'success'],
            'updated' => [__('Saved.', 'ruvelo-comment-subscriptions'), 'success'],
            'link-sent' => [__('If that address follows any posts here, a link to manage them is on its way. Check your inbox.', 'ruvelo-comment-subscriptions'), 'success'],
            'rate' => [__('That’s a lot of requests. Wait a little and try again.', 'ruvelo-comment-subscriptions'), 'danger'],
            'email' => [__('That doesn’t look like an email address. Check it and try again.', 'ruvelo-comment-subscriptions'), 'danger'],
            'closed' => [__('This post doesn’t take new subscriptions.', 'ruvelo-comment-subscriptions'), 'danger'],
            'expired' => [__('This page was open for a while, so nothing changed. Please try again.', 'ruvelo-comment-subscriptions'), 'danger'],
            'invalid' => [__('Something went wrong. Please try again.', 'ruvelo-comment-subscriptions'), 'danger'],
        ];
        if (! isset($messages[$code])) {
            return null;
        }

        return ['text' => $messages[$code][0], 'tone' => $messages[$code][1], 'code' => $code];
    }
}
