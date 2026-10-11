<?php

declare(strict_types=1);

namespace Ruvelo\WP\CommentSubscriptions\Rest;

use Ruvelo\WP\CommentSubscriptions\Frontend\Forms;
use Ruvelo\WP\CommentSubscriptions\Frontend\Route;
use Ruvelo\WP\CommentSubscriptions\Inbox;
use Ruvelo\WP\CommentSubscriptions\Settings;
use Ruvelo\WP\CommentSubscriptions\Subscription;
use Ruvelo\WP\CommentSubscriptions\Subscriptions;
use Ruvelo\WP\CommentSubscriptions\Urls;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * REST API, namespace ruvelo-comment-subscriptions/v1.
 *
 * The public routes take what the emails carry: a confirmation token, a
 * signed manage or unsubscribe token. Without one, a guest can only ask to
 * subscribe (double opt-in) or ask for a link, and the answers never reveal
 * whether an address follows anything. The admin routes need the
 * ruvelo_manage_comment_subscriptions capability.
 */
final class Controller
{
    public const NAMESPACE = 'ruvelo-comment-subscriptions/v1';

    public static function register(): void
    {
        $public = '__return_true';
        $admin = static fn (): bool => current_user_can(Settings::CAP);
        $token = ['type' => 'string', 'required' => true, 'maxLength' => 1000];

        register_rest_route(self::NAMESPACE, '/subscribe', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [self::class, 'subscribe'],
            'permission_callback' => $public,
            'args' => [
                'post' => ['type' => 'integer', 'required' => true, 'minimum' => 1],
                'email' => ['type' => 'string', 'default' => '', 'maxLength' => 190],
                'type' => ['type' => 'string', 'enum' => Subscription::TYPES, 'default' => 'all'],
                'website' => ['type' => 'string', 'default' => ''],
            ],
        ]);
        register_rest_route(self::NAMESPACE, '/confirm', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [self::class, 'confirm'],
            'permission_callback' => $public,
            'args' => ['token' => $token],
        ]);
        register_rest_route(self::NAMESPACE, '/unsubscribe', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [self::class, 'unsubscribe'],
            'permission_callback' => $public,
            'args' => ['token' => $token],
        ]);
        register_rest_route(self::NAMESPACE, '/manage-link', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [self::class, 'manageLink'],
            'permission_callback' => $public,
            'args' => [
                'email' => ['type' => 'string', 'required' => true, 'maxLength' => 190],
                'website' => ['type' => 'string', 'default' => ''],
            ],
        ]);
        register_rest_route(self::NAMESPACE, '/manage', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [self::class, 'manage'],
                'permission_callback' => $public,
                'args' => ['token' => ['type' => 'string', 'default' => '', 'maxLength' => 1000]],
            ],
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [self::class, 'change'],
                'permission_callback' => $public,
                'args' => [
                    'token' => ['type' => 'string', 'default' => '', 'maxLength' => 1000],
                    'op' => ['type' => 'string', 'required' => true, 'enum' => ['type', 'frequency', 'delivery', 'unsubscribe', 'unsubscribe_all']],
                    'id' => ['type' => 'integer', 'default' => 0],
                    'type' => ['type' => 'string', 'enum' => Subscription::TYPES],
                    'frequency' => ['type' => 'string', 'enum' => Subscription::FREQUENCIES],
                    'delivery' => ['type' => 'string', 'enum' => Inbox::CHOICES],
                ],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/subscriptions', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [self::class, 'list'],
            'permission_callback' => $admin,
            'args' => [
                'post' => ['type' => 'integer', 'default' => 0],
                'search' => ['type' => 'string', 'default' => ''],
                'status' => ['type' => 'string', 'enum' => array_merge([''], Subscription::STATUSES), 'default' => ''],
                'type' => ['type' => 'string', 'enum' => array_merge([''], Subscription::TYPES), 'default' => ''],
                'page' => ['type' => 'integer', 'default' => 1, 'minimum' => 1],
                'per_page' => ['type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 100],
            ],
        ]);
        register_rest_route(self::NAMESPACE, '/subscriptions/(?P<id>\d+)', [
            'methods' => WP_REST_Server::DELETABLE,
            'callback' => [self::class, 'delete'],
            'permission_callback' => $admin,
        ]);
        register_rest_route(self::NAMESPACE, '/posts/(?P<id>\d+)/subscriptions', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [self::class, 'counts'],
            'permission_callback' => $admin,
        ]);
        register_rest_route(self::NAMESPACE, '/stats', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [self::class, 'stats'],
            'permission_callback' => $admin,
        ]);
    }

    public static function subscribe(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $result = Forms::subscribeRequest((int) $request['post'], (string) $request['email'], (string) $request['website'], (string) $request['type']);
        if ($result instanceof WP_Error) {
            return $result;
        }
        $message = $result === 'subscribed'
            ? __('You’re subscribed. We’ll email you when there are new comments.', 'ruvelo-comment-subscriptions')
            : __('Almost done: check your inbox and click the link to confirm your subscription.', 'ruvelo-comment-subscriptions');

        return new WP_REST_Response(['status' => $result === 'subscribed' ? 'active' : 'pending', 'message' => $message], $result === 'subscribed' ? 201 : 202);
    }

    public static function confirm(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $subscription = Subscriptions::confirm((string) $request['token']);
        if ($subscription === null) {
            return new WP_Error('ruvelo_cs_token', __('That link has expired or isn’t valid.', 'ruvelo-comment-subscriptions'), ['status' => 404]);
        }

        return new WP_REST_Response(['subscription' => self::public($subscription), 'manage_token' => Urls::manageToken($subscription->email)]);
    }

    public static function unsubscribe(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (! Route::unsubscribe((string) $request['token'])) {
            return new WP_Error('ruvelo_cs_token', __('Nothing to unsubscribe: the link isn’t valid, or you already unsubscribed.', 'ruvelo-comment-subscriptions'), ['status' => 404]);
        }

        return new WP_REST_Response(['unsubscribed' => true]);
    }

    public static function manageLink(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $result = Forms::requestLink((string) $request['email'], (string) $request['website']);
        if ($result instanceof WP_Error) {
            return $result;
        }

        return new WP_REST_Response(['message' => __('If that address follows any posts here, a link to manage them is on its way.', 'ruvelo-comment-subscriptions')], 202);
    }

    public static function manage(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $owner = Forms::owner((string) $request['token']);
        if ($owner === null) {
            return self::denied((string) $request['token']);
        }

        return new WP_REST_Response(self::overview($owner));
    }

    public static function change(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $owner = Forms::owner((string) $request['token']);
        if ($owner === null) {
            return self::denied((string) $request['token']);
        }
        $op = (string) $request['op'];
        $subscription = (int) $request['id'] > 0 ? Subscriptions::find((int) $request['id']) : null;
        if (in_array($op, ['type', 'unsubscribe'], true) && ($subscription === null || ! Forms::owns($owner, $subscription))) {
            return new WP_Error('ruvelo_cs_not_found', __('That subscription doesn’t exist.', 'ruvelo-comment-subscriptions'), ['status' => 404]);
        }

        if ($op === 'type' && $subscription !== null) {
            Subscriptions::update($subscription->id, ['type' => (string) $request['type']]);
        } elseif ($op === 'unsubscribe' && $subscription !== null) {
            Subscriptions::delete($subscription->id);
        } elseif ($op === 'frequency') {
            foreach (Forms::emailsOf($owner) as $email) {
                Subscriptions::setFrequency($email, (string) $request['frequency']);
            }
        } elseif ($op === 'delivery') {
            if ($owner['user'] === 0) {
                return new WP_Error('ruvelo_cs_delivery', __('Only signed-in subscribers can choose their inbox.', 'ruvelo-comment-subscriptions'), ['status' => 403]);
            }
            Inbox::setPreference($owner['user'], (string) $request['delivery']);
        } elseif ($op === 'unsubscribe_all') {
            foreach (Forms::emailsOf($owner) as $email) {
                Subscriptions::deleteForEmail($email);
            }
        }

        return new WP_REST_Response(self::overview($owner));
    }

    public static function list(WP_REST_Request $request): WP_REST_Response
    {
        $result = Subscriptions::query([
            'post_id' => (int) $request['post'],
            'search' => (string) $request['search'],
            'status' => (string) $request['status'],
            'type' => (string) $request['type'],
            'page' => (int) $request['page'],
            'per_page' => (int) $request['per_page'],
        ]);
        $response = new WP_REST_Response(array_map(static fn (Subscription $s): array => $s->toArray(), $result['items']));
        $response->header('X-WP-Total', (string) $result['total']);
        $response->header('X-WP-TotalPages', (string) max(1, (int) ceil($result['total'] / max(1, (int) $request['per_page']))));

        return $response;
    }

    public static function delete(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (! Subscriptions::delete((int) $request['id'], 'admin')) {
            return new WP_Error('ruvelo_cs_not_found', __('That subscription doesn’t exist.', 'ruvelo-comment-subscriptions'), ['status' => 404]);
        }

        return new WP_REST_Response(['deleted' => true]);
    }

    public static function counts(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $id = (int) $request['id'];
        if (get_post($id) === null) {
            return new WP_Error('ruvelo_cs_post', __('That post doesn’t exist.', 'ruvelo-comment-subscriptions'), ['status' => 404]);
        }

        return new WP_REST_Response(['post_id' => $id] + Subscriptions::counts($id));
    }

    public static function stats(): WP_REST_Response
    {
        return new WP_REST_Response(Subscriptions::stats());
    }

    /**
     * @param array{email: string, user: int} $owner
     * @return array<string, mixed>
     */
    private static function overview(array $owner): array
    {
        $subscriptions = $owner['user'] > 0 ? Subscriptions::forUser($owner['user']) : Subscriptions::forEmail($owner['email']);

        return [
            'email' => $owner['email'],
            'frequency' => $subscriptions !== [] ? $subscriptions[0]->frequency : Settings::frequency(),
            'delivery' => $owner['user'] > 0 && Inbox::available() ? Inbox::preference($owner['user']) : 'email',
            'subscriptions' => array_map([self::class, 'public'], $subscriptions),
        ];
    }

    /**
     * What a subscriber sees about their own subscription.
     *
     * @return array<string, mixed>
     */
    private static function public(Subscription $subscription): array
    {
        $data = $subscription->toArray();
        unset($data['user_id'], $data['source']);

        return $data;
    }

    private static function denied(string $token): WP_Error
    {
        return $token !== ''
            ? new WP_Error('ruvelo_cs_token', __('That link has expired or isn’t valid. Ask for a new one.', 'ruvelo-comment-subscriptions'), ['status' => 403])
            : new WP_Error('ruvelo_cs_auth', __('Sign in, or use the link from one of your emails.', 'ruvelo-comment-subscriptions'), ['status' => 401]);
    }
}
