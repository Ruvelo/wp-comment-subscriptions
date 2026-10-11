<?php

declare(strict_types=1);

namespace Ruvelo\WP\CommentSubscriptions\Frontend;

use Ruvelo\WP\CommentSubscriptions\Settings;
use Ruvelo\WP\CommentSubscriptions\Subscription;
use Ruvelo\WP\CommentSubscriptions\Subscriptions;
use Ruvelo\WP\CommentSubscriptions\Support\Redirect;
use Ruvelo\WP\CommentSubscriptions\Tokens;
use Ruvelo\WP\CommentSubscriptions\Urls;

/**
 * The subscriptions page at /comment-subscriptions/: confirm, unsubscribe
 * and manage, reached from links in emails. It isn't a WordPress page, so
 * there's nothing to set up; block themes show it through a block template
 * (editable in the Site Editor), classic themes inside their header and footer.
 *
 * GET only ever shows a page with a button. Changes need a POST: the button,
 * or a mail client's RFC 8058 one-click unsubscribe. Link scanners that open
 * every URL in an email can't confirm or unsubscribe anyone.
 */
final class Route
{
    public const TEMPLATE = 'ruvelo-comment-subscriptions';

    /** @var array<string, mixed>|null */
    private static ?array $state = null;

    public static function register(): void
    {
        add_action('init', [self::class, 'rewrite']);
        add_filter('query_vars', [self::class, 'queryVars']);
        add_action('init', [self::class, 'blockTemplate'], 20);
        add_action('template_redirect', [self::class, 'handle'], 1);
        add_filter('template_include', [self::class, 'template'], 50);
        add_filter('document_title_parts', [self::class, 'title']);
        add_action('parse_query', [self::class, 'query']);
        add_filter('posts_pre_query', [self::class, 'noPosts'], 10, 2);
        add_filter('body_class', [self::class, 'bodyClass']);
        add_filter('pre_handle_404', static fn (bool $handled): bool => self::isRoute() ? true : $handled);
    }

    /**
     * The page isn't the blog: no "home" flags, and no posts to fetch.
     */
    public static function query(\WP_Query $query): void
    {
        if ($query->is_main_query() && (string) $query->get(Urls::QUERY_VAR) === '1') {
            $query->is_home = false;
            $query->is_archive = false;
            $query->is_404 = false;
        }
    }

    /**
     * @param array<int, mixed>|null $posts
     * @return array<int, mixed>|null
     */
    public static function noPosts(?array $posts, \WP_Query $query): ?array
    {
        return $query->is_main_query() && (string) $query->get(Urls::QUERY_VAR) === '1' ? [] : $posts;
    }

    /**
     * @param list<string> $classes
     * @return list<string>
     */
    public static function bodyClass(array $classes): array
    {
        if (self::isRoute()) {
            $classes = array_values(array_diff($classes, ['home', 'blog']));
            $classes[] = 'ruvelo-comment-subscriptions-page';
        }

        return $classes;
    }

    public static function rewrite(): void
    {
        add_rewrite_rule('^'.preg_quote(Urls::slug(), '/').'/?$', 'index.php?'.Urls::QUERY_VAR.'=1', 'top');
    }

    /**
     * @param list<string> $vars
     * @return list<string>
     */
    public static function queryVars(array $vars): array
    {
        $vars[] = Urls::QUERY_VAR;

        return $vars;
    }

    public static function isRoute(): bool
    {
        return (string) get_query_var(Urls::QUERY_VAR) === '1';
    }

    public static function blockTemplate(): void
    {
        if (! function_exists('register_block_template') || ! wp_is_block_theme()) {
            return;
        }
        register_block_template('ruvelo-comment-subscriptions//'.self::TEMPLATE, [
            'title' => __('Comment subscriptions', 'ruvelo-comment-subscriptions'),
            'description' => __('Where readers confirm, change and stop their comment subscriptions, from the links in their emails.', 'ruvelo-comment-subscriptions'),
            'content' => '<!-- wp:template-part {"slug":"header","area":"header","tagName":"header"} /-->'."\n"
                .'<!-- wp:group {"tagName":"main","style":{"spacing":{"padding":{"top":"var:preset|spacing|50","bottom":"var:preset|spacing|60"}}},"layout":{"type":"constrained","contentSize":"46rem"}} -->'."\n"
                .'<main class="wp-block-group" style="padding-top:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--60)"><!-- wp:ruvelo/manage-subscriptions /--></main>'."\n"
                .'<!-- /wp:group -->'."\n"
                .'<!-- wp:template-part {"slug":"footer","area":"footer","tagName":"footer"} /-->',
        ]);
    }

    /**
     * Headers for every view, and the POSTs: confirm, unsubscribe, one-click.
     */
    public static function handle(): void
    {
        if (! self::isRoute()) {
            return;
        }
        if (! headers_sent()) {
            nocache_headers();
            header('X-Robots-Tag: noindex, nofollow');
            header('Referrer-Policy: no-referrer');
        }
        if (! isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
            return;
        }

        $view = self::param('rcs');
        $token = self::param('rcs_token');

        // phpcs:disable WordPress.Security.NonceVerification.Missing -- One-click unsubscribe comes from a mail client: the signed token is the authorisation (RFC 8058).
        $oneClick = isset($_POST['List-Unsubscribe']) && is_string($_POST['List-Unsubscribe']) && sanitize_text_field(wp_unslash($_POST['List-Unsubscribe'])) === 'One-Click';
        // phpcs:enable
        if ($view === 'unsubscribe' && $oneClick) {
            $done = self::unsubscribe($token);
            self::finish($done ? 'Unsubscribed' : 'Not found', $done ? 200 : 404);
        }

        if (! isset($_POST['_ruvelo_cs_nonce']) || ! wp_verify_nonce(sanitize_key(wp_unslash($_POST['_ruvelo_cs_nonce'])), 'ruvelo_cs_'.$view)) {
            self::go(Urls::page(['rcs' => $view, 'rcs_token' => $token, 'rcs_msg' => 'expired']));
        }

        if ($view === 'confirm') {
            $subscription = Subscriptions::confirm($token);
            if ($subscription === null) {
                self::go(Urls::page(['rcs' => 'confirm', 'rcs_token' => $token]));
            }
            self::go(Urls::page(['rcs' => 'manage', 'rcs_token' => Urls::manageToken($subscription->email), 'rcs_msg' => 'confirmed', 'rcs_post' => (string) $subscription->postId]));
        }

        if ($view === 'unsubscribe') {
            $target = self::unsubscribeTarget($token);
            $email = $target['email'];
            $all = ! empty($_POST['all']) || $target['all'];
            if ($email === '') {
                self::go(Urls::page(['rcs' => 'unsubscribe', 'rcs_token' => $token]));
            }
            if ($all) {
                Subscriptions::deleteForEmail($email);
            } elseif ($target['subscription'] instanceof Subscription) {
                Subscriptions::delete($target['subscription']->id);
            }
            $args = ['rcs' => 'manage', 'rcs_token' => Urls::manageToken($email), 'rcs_msg' => $all ? 'unsubscribed-all' : 'unsubscribed'];
            if (! $all && $target['subscription'] instanceof Subscription) {
                $args['rcs_post'] = (string) $target['subscription']->postId;
            }
            self::go(Urls::page($args));
        }
    }

    /**
     * Who an unsubscribe token is for.
     *
     * @return array{email: string, subscription: Subscription|null, all: bool}
     */
    public static function unsubscribeTarget(string $token): array
    {
        $all = Tokens::verify('unsubscribe-all', $token);
        if ($all !== null && is_string($all['e'] ?? null) && is_email($all['e'])) {
            return ['email' => $all['e'], 'subscription' => null, 'all' => true];
        }
        $one = Tokens::verify('unsubscribe', $token);
        if ($one === null) {
            return ['email' => '', 'subscription' => null, 'all' => false];
        }
        $subscription = Subscriptions::find((int) ($one['i'] ?? 0));
        // The fingerprint ties the token to the address: a reused row id never matches.
        if ($subscription === null || ! hash_equals(Tokens::fingerprint($subscription->email), (string) ($one['f'] ?? ''))) {
            return ['email' => '', 'subscription' => null, 'all' => false];
        }

        return ['email' => $subscription->email, 'subscription' => $subscription, 'all' => false];
    }

    /**
     * One-click: unsubscribe without a page.
     */
    public static function unsubscribe(string $token): bool
    {
        $target = self::unsubscribeTarget($token);
        if ($target['all'] && $target['email'] !== '') {
            Subscriptions::deleteForEmail($target['email']);

            return true;
        }
        if ($target['subscription'] instanceof Subscription) {
            return Subscriptions::delete($target['subscription']->id);
        }

        return false;
    }

    /**
     * What the page shows, worked out once per request.
     *
     * @return array{view: string, token: string, msg: string, email: string, user: int, subscription: Subscription|null, all: bool, post: int}
     */
    public static function state(): array
    {
        if (self::$state !== null) {
            /** @var array{view: string, token: string, msg: string, email: string, user: int, subscription: Subscription|null, all: bool, post: int} */
            return self::$state;
        }
        $view = self::param('rcs');
        $token = self::param('rcs_token');
        $state = ['view' => 'request', 'token' => $token, 'msg' => sanitize_key(self::param('rcs_msg')), 'email' => '', 'user' => 0, 'subscription' => null, 'all' => false, 'post' => absint(self::param('rcs_post'))];

        if ($view === 'confirm') {
            $pending = Subscriptions::pendingByToken($token);
            $state['view'] = $pending !== null ? 'confirm' : 'invalid';
            $state['subscription'] = $pending;
        } elseif ($view === 'unsubscribe') {
            $target = self::unsubscribeTarget($token);
            $state['view'] = $target['email'] !== '' ? 'unsubscribe' : 'invalid';
            $state['email'] = $target['email'];
            $state['subscription'] = $target['subscription'];
            $state['all'] = $target['all'];
        } elseif ($view === 'manage' && $token !== '') {
            $data = Tokens::verify('manage', $token);
            if ($data !== null && is_string($data['e'] ?? null) && is_email($data['e'])) {
                $state['view'] = 'manage';
                $state['email'] = $data['e'];
            } else {
                $state['view'] = 'invalid';
            }
        }

        if (in_array($state['view'], ['request', 'invalid'], true) && is_user_logged_in() && $view !== 'confirm' && $view !== 'unsubscribe') {
            $state['view'] = 'manage';
            $state['user'] = get_current_user_id();
            $state['token'] = '';
        }

        return self::$state = $state;
    }

    public static function reset(): void
    {
        self::$state = null;
    }

    /**
     * Classic themes: the page inside the theme's header and footer. Block
     * themes: the block template.
     */
    public static function template(string $template): string
    {
        if (! self::isRoute()) {
            return $template;
        }
        if (wp_is_block_theme() && function_exists('locate_block_template')) {
            // Sets up our block template (a theme's own file of the same name wins) and returns the canvas.
            $block = locate_block_template($template, 'page', [self::TEMPLATE]);
            if ($block !== '' && str_ends_with((string) ($GLOBALS['_wp_current_template_id'] ?? ''), '//'.self::TEMPLATE)) {
                return $block;
            }
        }
        $own = locate_template(['ruvelo-comment-subscriptions/page-wrapper.php']);

        return $own !== '' ? $own : RUVELO_COMMENT_SUBSCRIPTIONS_DIR.'templates/classic/wrapper.php';
    }

    /**
     * @param array<string, string> $parts
     * @return array<string, string>
     */
    public static function title(array $parts): array
    {
        if (self::isRoute()) {
            $parts['title'] = __('Comment subscriptions', 'ruvelo-comment-subscriptions');
        }

        return $parts;
    }

    public static function param(string $key): string
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing -- Read-only; changes verify a nonce or a signed token.
        if (isset($_POST[$key]) && is_string($_POST[$key])) {
            return sanitize_text_field(wp_unslash($_POST[$key]));
        }
        if (isset($_GET[$key]) && is_string($_GET[$key])) {
            return sanitize_text_field(wp_unslash($_GET[$key]));
        }
        // phpcs:enable

        return '';
    }

    private static function finish(string $text, int $status): never
    {
        if (defined('RUVELO_COMMENT_SUBSCRIPTIONS_TESTS')) {
            throw new Redirect('one-click:'.$status, $status); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Test-only signal.
        }
        status_header($status);
        header('Content-Type: text/plain; charset=utf-8');
        echo esc_html($text);
        exit;
    }

    private static function go(string $url): never
    {
        if (defined('RUVELO_COMMENT_SUBSCRIPTIONS_TESTS')) {
            throw new Redirect($url); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Test-only signal.
        }
        wp_safe_redirect($url, 303);
        exit;
    }

    public static function scheme(): string
    {
        return Settings::string('color_scheme');
    }
}
