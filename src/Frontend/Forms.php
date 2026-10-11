<?php

declare(strict_types=1);

namespace Ruvelo\WP\CommentSubscriptions\Frontend;

use Ruvelo\WP\CommentSubscriptions\Inbox;
use Ruvelo\WP\CommentSubscriptions\Mailer;
use Ruvelo\WP\CommentSubscriptions\Subscription;
use Ruvelo\WP\CommentSubscriptions\Subscriptions;
use Ruvelo\WP\CommentSubscriptions\Support\RateLimit;
use Ruvelo\WP\CommentSubscriptions\Support\Redirect;
use Ruvelo\WP\CommentSubscriptions\Tokens;
use Ruvelo\WP\CommentSubscriptions\Urls;
use WP_Error;

/**
 * The no-JavaScript path: forms post to admin-post.php and come back with a
 * notice. JavaScript uses the REST API for the same actions.
 */
final class Forms
{
    public static function register(): void
    {
        foreach (['subscribe', 'manage', 'request_link'] as $action) {
            add_action('admin_post_ruvelo_cs_'.$action, [self::class, $action]);
            add_action('admin_post_nopriv_ruvelo_cs_'.$action, [self::class, $action]);
        }
    }

    /**
     * "Subscribe without commenting".
     */
    public static function subscribe(): void
    {
        $postId = self::int('post_id');
        $return = (string) get_permalink($postId);
        $return = $return !== '' ? $return.'#ruvelo-cs-subscribe' : home_url('/');
        if (! wp_verify_nonce(self::str('_ruvelo_cs_nonce'), 'ruvelo_cs_subscribe')) {
            self::go($return, 'expired');
        }
        $result = self::subscribeRequest($postId, self::str('email'), self::str('website'));
        self::go($return, is_string($result) ? $result : self::code($result));
    }

    /**
     * Shared by the form and REST: honeypot, rate limits, double opt-in.
     *
     * @return string|WP_Error "subscribed", "check-email" (also for anything a guest shouldn't learn), or an error.
     */
    public static function subscribeRequest(int $postId, string $email, string $honeypot = '', string $type = 'all'): string|WP_Error
    {
        if ($honeypot !== '') {
            return 'check-email'; // A bot filled the hidden field: pretend it worked.
        }
        $open = Subscriptions::canSubscribeTo($postId);
        if ($open instanceof WP_Error) {
            return $open;
        }
        $userId = get_current_user_id();
        $user = $userId > 0 ? get_userdata($userId) : false;
        if ($user !== false) {
            $result = Subscriptions::subscribe($postId, $user->user_email, $type, ['user_id' => $userId, 'confirmed' => true, 'source' => 'form']);

            return $result instanceof WP_Error ? $result : 'subscribed';
        }

        $email = Subscriptions::normalize($email);
        if (! is_email($email)) {
            return new WP_Error('ruvelo_cs_email', __('Enter a valid email address.', 'ruvelo-comment-subscriptions'), ['status' => 422]);
        }
        if (! RateLimit::ip()) {
            return new WP_Error('ruvelo_cs_rate', __('That’s a lot of requests. Wait a little and try again.', 'ruvelo-comment-subscriptions'), ['status' => 429]);
        }
        $result = Subscriptions::subscribe($postId, $email, $type, ['source' => 'form']);
        if ($result instanceof WP_Error) {
            return $result;
        }
        // Already subscribed or over the per-address limit: the same answer, so
        // nobody can find out who follows what by trying addresses.
        if ($result['status'] === 'pending' && RateLimit::email($email)) {
            Mailer::confirmation($result['subscription'], $result['token']);
        }

        return 'check-email';
    }

    /**
     * Changes on the manage page: type, frequency, delivery, unsubscribe.
     */
    public static function manage(): void
    {
        $token = self::str('rcs_token');
        $back = static fn (string $msg, array $extra = []): never => self::redirect(Urls::page(array_filter(['rcs' => 'manage', 'rcs_token' => $token, 'rcs_msg' => $msg] + $extra)));
        if (! wp_verify_nonce(self::str('_ruvelo_cs_nonce'), 'ruvelo_cs_manage')) {
            $back('expired');
        }
        $owner = self::owner($token);
        if ($owner === null) {
            self::redirect(Urls::page(['rcs' => 'manage', 'rcs_token' => $token]));
        }

        $op = self::str('op');
        $id = self::int('id');
        $subscription = $id > 0 ? Subscriptions::find($id) : null;
        if ($subscription !== null && ! self::owns($owner, $subscription)) {
            $subscription = null;
        }

        switch ($op) {
            case 'unsubscribe':
                if ($subscription === null) {
                    $back('invalid');
                }
                Subscriptions::delete($subscription->id);
                $back('unsubscribed', ['rcs_post' => (string) $subscription->postId]);
                // no break: $back() exits.
            case 'type':
                if ($subscription === null) {
                    $back('invalid');
                }
                Subscriptions::update($subscription->id, ['type' => self::str('type')]);
                $back('updated');
                // no break
            case 'frequency':
                foreach (self::emailsOf($owner) as $email) {
                    Subscriptions::setFrequency($email, self::str('frequency'));
                }
                if ($owner['user'] > 0 && Inbox::available()) {
                    Inbox::setPreference($owner['user'], self::str('delivery'));
                }
                $back('updated');
                // no break
            case 'unsubscribe_all':
                foreach (self::emailsOf($owner) as $email) {
                    Subscriptions::deleteForEmail($email);
                }
                $back('unsubscribed-all');
                // no break
            default:
                $back('invalid');
        }
    }

    /**
     * "Email me a link": always the same answer, whether or not the address
     * follows anything.
     */
    public static function request_link(): void // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- Matches the action name.
    {
        if (! wp_verify_nonce(self::str('_ruvelo_cs_nonce'), 'ruvelo_cs_request_link')) {
            self::redirect(Urls::page(['rcs_msg' => 'expired']));
        }
        $result = self::requestLink(self::str('email'), self::str('website'));
        self::redirect(Urls::page(['rcs_msg' => is_string($result) ? $result : self::code($result)]));
    }

    public static function requestLink(string $email, string $honeypot = ''): string|WP_Error
    {
        if ($honeypot !== '') {
            return 'link-sent';
        }
        $email = Subscriptions::normalize($email);
        if (! is_email($email)) {
            return new WP_Error('ruvelo_cs_email', __('Enter a valid email address.', 'ruvelo-comment-subscriptions'), ['status' => 422]);
        }
        if (! RateLimit::ip('manage_link')) {
            return new WP_Error('ruvelo_cs_rate', __('That’s a lot of requests. Wait a little and try again.', 'ruvelo-comment-subscriptions'), ['status' => 429]);
        }
        if (Subscriptions::forEmail($email) !== [] && RateLimit::email($email, 'manage_link')) {
            Mailer::manageLink($email);
        }

        return 'link-sent';
    }

    /**
     * Who's managing: the address in a valid manage token, or the signed-in user.
     *
     * @return array{email: string, user: int}|null
     */
    public static function owner(string $token): ?array
    {
        if ($token !== '') {
            $data = Tokens::verify('manage', $token);
            if ($data !== null && is_string($data['e'] ?? null) && is_email($data['e'])) {
                return ['email' => $data['e'], 'user' => 0];
            }

            return null;
        }
        $user = wp_get_current_user();

        return $user->ID > 0 ? ['email' => Subscriptions::normalize($user->user_email), 'user' => $user->ID] : null;
    }

    /**
     * @param array{email: string, user: int} $owner
     */
    public static function owns(array $owner, Subscription $subscription): bool
    {
        return $subscription->email === $owner['email'] || ($owner['user'] > 0 && $subscription->userId === $owner['user']);
    }

    /**
     * @param array{email: string, user: int} $owner
     * @return list<string>
     */
    public static function emailsOf(array $owner): array
    {
        $emails = [$owner['email']];
        if ($owner['user'] > 0) {
            foreach (Subscriptions::forUser($owner['user']) as $subscription) {
                $emails[] = $subscription->email;
            }
        }

        return array_values(array_unique($emails));
    }

    private static function code(WP_Error $error): string
    {
        return match ($error->get_error_code()) {
            'ruvelo_cs_email' => 'email',
            'ruvelo_cs_rate' => 'rate',
            'ruvelo_cs_closed', 'ruvelo_cs_post' => 'closed',
            default => 'invalid',
        };
    }

    private static function go(string $url, string $message): never
    {
        $hash = '';
        if (str_contains($url, '#')) {
            [$url, $hash] = explode('#', $url, 2);
            $hash = '#'.$hash;
        }
        self::redirect(add_query_arg('rcs_msg', $message, remove_query_arg(['rcs_msg', 'rcs_post'], $url)).$hash);
    }

    private static function redirect(string $url): never
    {
        if (defined('RUVELO_COMMENT_SUBSCRIPTIONS_TESTS')) {
            throw new Redirect($url); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Test-only signal, never shown.
        }
        wp_safe_redirect($url, 303);
        exit;
    }

    private static function str(string $key): string
    {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- Each handler verifies its nonce first.
        if (! isset($_POST[$key]) || ! is_string($_POST[$key])) {
            return '';
        }

        return sanitize_text_field(wp_unslash($_POST[$key]));
        // phpcs:enable
    }

    private static function int(string $key): int
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Each handler verifies its nonce first.
        return isset($_POST[$key]) && is_numeric($_POST[$key]) ? absint($_POST[$key]) : 0;
    }
}
