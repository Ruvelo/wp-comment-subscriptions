<?php

declare(strict_types=1);

namespace Ruvelo\WP\CommentSubscriptions;

use WP_Comment;

/**
 * Builds and sends every email: HTML with a plain-text part, from
 * templates a theme can override (copy templates/emails/<name>.php to
 * <theme>/ruvelo-comment-subscriptions/emails/<name>.php).
 */
final class Mailer
{
    public const TEMPLATES = ['confirm', 'notification', 'digest', 'manage-link'];

    /** The plain-text part of the email being sent. */
    private static string $altBody = '';

    /**
     * The confirmation email a guest gets after asking to subscribe.
     */
    public static function confirmation(Subscription $subscription, string $token): bool
    {
        return self::send($subscription->email, 'confirm', [
            'post_title' => self::title($subscription->postId),
            'post_url' => (string) get_permalink($subscription->postId),
            'type' => $subscription->type,
            'confirm_url' => Urls::confirm($token),
            'days' => max(1, Settings::int('pending_days')),
        ]);
    }

    /**
     * A fresh link to the manage page.
     */
    public static function manageLink(string $email): bool
    {
        return self::send($email, 'manage-link', [
            'manage_url' => Urls::manage($email),
            'days' => max(1, Settings::int('link_days')),
            'count' => count(Subscriptions::forEmail($email)),
        ]);
    }

    /**
     * One new comment, for one subscriber.
     */
    public static function notification(Subscription $subscription, WP_Comment $comment): bool
    {
        $email = $subscription->recipient();
        $unsubscribe = Urls::unsubscribe($subscription);

        return self::send($email, 'notification', self::notificationData($subscription, $comment), self::listHeaders($unsubscribe));
    }

    /**
     * @return array<string, mixed>
     */
    public static function notificationData(Subscription $subscription, WP_Comment $comment): array
    {
        $parent = (int) $comment->comment_parent > 0 ? get_comment((int) $comment->comment_parent) : null;
        $isReply = $subscription->type === 'replies' && $parent instanceof WP_Comment;

        return [
            'post_title' => self::title($subscription->postId),
            'post_url' => (string) get_permalink($subscription->postId),
            'comment_url' => (string) get_comment_link($comment),
            'author' => self::author($comment),
            'initials' => self::initials(self::author($comment)),
            'date' => self::date($comment),
            'excerpt' => self::excerpt($comment),
            'is_reply' => $isReply,
            'parent_author' => $parent instanceof WP_Comment ? self::author($parent) : '',
            'type' => $subscription->type,
            'unsubscribe_url' => Urls::unsubscribe($subscription),
            'manage_url' => Urls::manage($subscription->recipient()),
        ];
    }

    /**
     * The daily digest: new comments on every post an address follows.
     *
     * @param list<array{subscription: Subscription, comment: WP_Comment}> $items
     */
    public static function digest(string $email, array $items): bool
    {
        if ($items === []) {
            return false;
        }
        $unsubscribe = Urls::unsubscribeAll($email);

        return self::send($email, 'digest', self::digestData($email, $items), self::listHeaders($unsubscribe));
    }

    /**
     * @param list<array{subscription: Subscription, comment: WP_Comment}> $items
     * @return array<string, mixed>
     */
    public static function digestData(string $email, array $items): array
    {
        $posts = [];
        foreach ($items as $item) {
            $postId = $item['subscription']->postId;
            $comment = $item['comment'];
            $posts[$postId] ??= ['title' => self::title($postId), 'url' => (string) get_permalink($postId), 'unsubscribe_url' => Urls::unsubscribe($item['subscription']), 'comments' => []];
            $posts[$postId]['comments'][] = [
                'author' => self::author($comment),
                'initials' => self::initials(self::author($comment)),
                'date' => self::date($comment),
                'excerpt' => self::excerpt($comment),
                'url' => (string) get_comment_link($comment),
            ];
        }

        return [
            'posts' => array_values($posts),
            'count' => count($items),
            'unsubscribe_url' => Urls::unsubscribeAll($email),
            'manage_url' => Urls::manage($email),
        ];
    }

    /**
     * Renders an email without sending it: subject, HTML and text.
     *
     * @param array<string, mixed> $data
     * @return array{subject: string, html: string, text: string}
     */
    public static function render(string $template, array $data): array
    {
        $view = $data + [
            'site_name' => Settings::siteName(),
            'site_url' => home_url('/'),
            'template' => $template,
        ];
        $view['subject'] = self::subject($template, $view);
        $view['preheader'] = self::preheader($template, $view);

        $content = self::file('emails/'.$template, $view);
        $text = self::file('emails/'.$template.'.txt', $view);

        return [
            'subject' => $view['subject'],
            'html' => self::file('emails/layout', $view + ['content' => $content]),
            'text' => trim(self::file('emails/layout.txt', $view + ['content' => trim($text)])),
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string> $headers
     */
    public static function send(string $to, string $template, array $data, array $headers = []): bool
    {
        if (! is_email($to)) {
            return false;
        }

        return self::deliver($to, self::render($template, $data), $headers, $template, $data);
    }

    /**
     * Sends an email that's already rendered.
     *
     * @param array{subject: string, html: string, text: string} $email
     * @param list<string> $headers
     * @param array<string, mixed> $data
     */
    public static function deliver(string $to, array $email, array $headers = [], string $template = '', array $data = []): bool
    {
        $headers[] = 'Content-Type: text/html; charset=UTF-8';
        $replyTo = sanitize_email(Settings::string('reply_to'));
        if ($replyTo !== '' && is_email($replyTo)) {
            $headers[] = 'Reply-To: '.$replyTo;
        }
        $message = ['to' => $to, 'subject' => $email['subject'], 'html' => $email['html'], 'text' => $email['text'], 'headers' => $headers];

        /**
         * Filters an email before it's sent: change the subject, the HTML or
         * text, or the headers. Return false to not send it.
         *
         * @param array{to: string, subject: string, html: string, text: string, headers: list<string>}|false $message
         * @param string $template "confirm", "notification", "digest" or "manage-link".
         * @param array<string, mixed> $data What the template was given.
         */
        $message = apply_filters('ruvelo_comment_subscriptions_email', $message, $template, $data);
        if (! is_array($message) || ! isset($message['to'], $message['subject'], $message['html'])) {
            return false;
        }

        self::$altBody = isset($message['text']) && is_string($message['text']) ? $message['text'] : '';
        $fromName = static fn (): string => Settings::fromName();
        add_action('phpmailer_init', [self::class, 'addTextPart']);
        add_filter('wp_mail_from_name', $fromName);
        try {
            $sent = wp_mail((string) $message['to'], (string) $message['subject'], (string) $message['html'], is_array($message['headers'] ?? null) ? $message['headers'] : []);
        } finally {
            remove_action('phpmailer_init', [self::class, 'addTextPart']);
            remove_filter('wp_mail_from_name', $fromName);
            self::$altBody = '';
        }

        return $sent;
    }

    /**
     * Sends a sample email ("[Test] …"), for checking looks and delivery.
     */
    public static function sendSample(string $email, string $template): bool
    {
        $sample = self::sample($template, $email);
        /* translators: %s: email subject */
        $sample['subject'] = sprintf(__('[Test] %s', 'ruvelo-comment-subscriptions'), $sample['subject']);

        return self::deliver($email, $sample, [], $template);
    }

    /**
     * Adds the plain-text alternative to the HTML email being sent.
     */
    public static function addTextPart(mixed $phpmailer): void
    {
        if (is_object($phpmailer) && property_exists($phpmailer, 'AltBody') && self::$altBody !== '') {
            $phpmailer->AltBody = self::$altBody;
        }
    }

    /**
     * RFC 8058 one-click unsubscribe: mail clients show an Unsubscribe
     * button and POST "List-Unsubscribe=One-Click" to the link.
     *
     * @return list<string>
     */
    public static function listHeaders(string $unsubscribeUrl): array
    {
        return [
            'List-Unsubscribe: <'.esc_url_raw($unsubscribeUrl).'>',
            'List-Unsubscribe-Post: List-Unsubscribe=One-Click',
        ];
    }

    /**
     * Example data for each email, for the Preview emails screen and `send-test`.
     *
     * @return array{subject: string, html: string, text: string}
     */
    public static function sample(string $template, string $email = ''): array
    {
        $email = $email !== '' ? $email : 'you@example.com';
        $post = self::samplePost();
        $title = $post !== null ? self::title($post) : __('Usage-based billing is here', 'ruvelo-comment-subscriptions');
        $url = $post !== null ? (string) get_permalink($post) : home_url('/');
        $sub = new Subscription(0, $post ?? 0, 0, $email, 'replies', 'active', 'instant', 'sample', current_time('mysql', true), current_time('mysql', true));
        $unsubscribe = Urls::page(['rcs' => 'unsubscribe', 'rcs_token' => Tokens::random()]);
        $manage = Urls::manage($email);
        $excerpt = __('Existing price IDs work. Halyard maps each meter to a price, so you only add a “meter” key. Nothing changes for customers on flat plans.', 'ruvelo-comment-subscriptions');

        $data = match ($template) {
            'confirm' => ['post_title' => $title, 'post_url' => $url, 'type' => 'replies', 'confirm_url' => Urls::confirm(Tokens::random()), 'days' => max(1, Settings::int('pending_days'))],
            'manage-link' => ['manage_url' => $manage, 'days' => max(1, Settings::int('link_days')), 'count' => 3],
            'digest' => [
                'posts' => [
                    ['title' => $title, 'url' => $url, 'unsubscribe_url' => $unsubscribe, 'comments' => [
                        ['author' => 'Maya Okafor', 'initials' => 'MO', 'date' => self::sampleDate(5), 'excerpt' => $excerpt, 'url' => $url.'#comments'],
                        ['author' => 'Kenji Mori', 'initials' => 'KM', 'date' => self::sampleDate(3), 'excerpt' => __('Heads-up for anyone on the API: usage timestamps are in UTC. Our dashboard was a day off until I noticed.', 'ruvelo-comment-subscriptions'), 'url' => $url.'#comments'],
                    ]],
                    ['title' => __('How we price API calls', 'ruvelo-comment-subscriptions'), 'url' => home_url('/'), 'unsubscribe_url' => $unsubscribe, 'comments' => [
                        ['author' => 'Inès Laurent', 'initials' => 'IL', 'date' => self::sampleDate(1), 'excerpt' => __('Could the CSV export include the meter name? Finance asked within the hour.', 'ruvelo-comment-subscriptions'), 'url' => home_url('/')],
                    ]],
                ],
                'count' => 3,
                'unsubscribe_url' => $unsubscribe,
                'manage_url' => $manage,
            ],
            default => [
                'post_title' => $title,
                'post_url' => $url,
                'comment_url' => $url.'#comments',
                'author' => 'Maya Okafor',
                'initials' => 'MO',
                'date' => self::sampleDate(2),
                'excerpt' => $excerpt,
                'is_reply' => true,
                'parent_author' => 'Tom Reyes',
                'type' => $sub->type,
                'unsubscribe_url' => $unsubscribe,
                'manage_url' => $manage,
            ],
        };

        return self::render(in_array($template, self::TEMPLATES, true) ? $template : 'notification', $data);
    }

    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $view
     */
    private static function subject(string $template, array $view): string
    {
        $title = is_string($view['post_title'] ?? null) ? $view['post_title'] : '';
        $site = (string) $view['site_name'];

        return match ($template) {
            /* translators: %s: post title */
            'confirm' => sprintf(__('Confirm your subscription to “%s”', 'ruvelo-comment-subscriptions'), $title),
            /* translators: %s: site name */
            'manage-link' => sprintf(__('Manage your comment subscriptions at %s', 'ruvelo-comment-subscriptions'), $site),
            'digest' => sprintf(
                /* translators: 1: number of comments, 2: site name */
                _n('%1$s new comment at %2$s', '%1$s new comments at %2$s', (int) $view['count'], 'ruvelo-comment-subscriptions'),
                number_format_i18n((int) $view['count']),
                $site
            ),
            default => ! empty($view['is_reply'])
                /* translators: 1: comment author, 2: post title */
                ? sprintf(__('%1$s replied to you on “%2$s”', 'ruvelo-comment-subscriptions'), (string) $view['author'], $title)
                /* translators: 1: comment author, 2: post title */
                : sprintf(__('%1$s commented on “%2$s”', 'ruvelo-comment-subscriptions'), (string) $view['author'], $title),
        };
    }

    /**
     * @param array<string, mixed> $view
     */
    private static function preheader(string $template, array $view): string
    {
        return match ($template) {
            'confirm' => __('One click and you’re subscribed. If you didn’t ask for this, ignore this email.', 'ruvelo-comment-subscriptions'),
            'manage-link' => __('Change or stop your comment subscriptions.', 'ruvelo-comment-subscriptions'),
            'digest' => __('Today’s new comments on the posts you follow.', 'ruvelo-comment-subscriptions'),
            default => wp_trim_words((string) ($view['excerpt'] ?? ''), 18, '…'),
        };
    }

    /**
     * @param array<string, mixed> $view
     */
    public static function file(string $template, array $view): string
    {
        $file = locate_template('ruvelo-comment-subscriptions/'.$template.'.php');
        if ($file === '') {
            $file = RUVELO_COMMENT_SUBSCRIPTIONS_DIR.'templates/'.$template.'.php';
        }

        /** This filter is documented in src/Frontend/Views.php */
        $file = (string) apply_filters('ruvelo_comment_subscriptions_template', $file, $template, $view);
        if (! is_readable($file)) {
            return '';
        }

        ob_start();
        (static function (string $__file, array $view): void {
            include $__file;
        })($file, $view);

        return (string) ob_get_clean();
    }

    private static function title(int $postId): string
    {
        return wp_specialchars_decode(wp_strip_all_tags(get_the_title($postId)), ENT_QUOTES);
    }

    public static function author(WP_Comment $comment): string
    {
        $name = trim(wp_specialchars_decode(wp_strip_all_tags((string) $comment->comment_author), ENT_QUOTES));

        return $name !== '' ? $name : __('Someone', 'ruvelo-comment-subscriptions');
    }

    public static function initials(string $name): string
    {
        $parts = preg_split('/\s+/u', trim($name)) ?: [];
        $letters = '';
        foreach (array_slice(array_filter($parts), 0, 2) as $part) {
            $letters .= mb_strtoupper(mb_substr($part, 0, 1));
        }

        return $letters !== '' ? $letters : '?';
    }

    /**
     * Plain text from the comment, trimmed to `excerpt_words`. Emails never
     * carry the comment's HTML.
     */
    public static function excerpt(WP_Comment $comment): string
    {
        $words = Settings::int('excerpt_words');
        if ($words <= 0 || post_password_required((int) $comment->comment_post_ID)) {
            return '';
        }
        $text = trim(wp_specialchars_decode(wp_strip_all_tags((string) $comment->comment_content), ENT_QUOTES));

        return wp_trim_words($text, $words, '…');
    }

    private static function date(WP_Comment $comment): string
    {
        $time = strtotime($comment->comment_date_gmt.' UTC');

        return $time !== false ? (string) wp_date((string) get_option('date_format').' '.(string) get_option('time_format'), $time) : '';
    }

    private static function sampleDate(int $hoursAgo): string
    {
        return (string) wp_date((string) get_option('date_format').' '.(string) get_option('time_format'), time() - $hoursAgo * HOUR_IN_SECONDS);
    }

    private static function samplePost(): ?int
    {
        $posts = get_posts(['post_type' => 'post', 'post_status' => 'publish', 'posts_per_page' => 1, 'orderby' => 'comment_count', 'order' => 'DESC', 'fields' => 'ids']);

        return $posts !== [] ? (int) $posts[0] : null;
    }
}
