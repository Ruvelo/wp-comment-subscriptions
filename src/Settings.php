<?php

declare(strict_types=1);

namespace Ruvelo\WP\CommentSubscriptions;

/**
 * Every setting in one place: defaults, the saved option, and the
 * `ruvelo_comment_subscriptions_settings` filter so a site can define them in code.
 */
final class Settings
{
    public const OPTION = 'ruvelo_comment_subscriptions_settings';

    public const CAP = 'ruvelo_manage_comment_subscriptions';

    /** @var array<string, mixed>|null */
    private static ?array $cache = null;

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'offer_replies' => true,        // "Notify me of replies to my comment"
            'offer_all' => true,            // "Notify me of all new comments on this post"
            'checked' => '',                // '' | replies | all: ticked by default (off: consent is opt-in)
            'without_commenting' => true,   // "Subscribe without commenting" under the comment form
            'frequency' => 'instant',       // instant | daily: the default for new subscribers
            'digest_hour' => 8,             // When daily digests go out, in the site's time zone
            'pending_days' => 3,            // Unconfirmed subscriptions are deleted after this
            'link_days' => 14,              // How long a "manage my subscriptions" link works
            'excerpt_words' => 55,          // How much of a comment an email quotes (0: none)
            'from_name' => '',              // Defaults to the site name
            'reply_to' => '',
            'batch_size' => 50,
            'ip_limit' => 10,               // Subscribe requests per IP address per hour
            'email_limit' => 3,             // Confirmation emails per address per day
            'color_scheme' => 'light',      // light | auto | dark
            'delete_data' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        if (self::$cache === null) {
            $saved = get_option(self::OPTION, []);
            $settings = array_merge(self::defaults(), is_array($saved) ? $saved : []);

            /**
             * Filters the settings. Use it to define settings in code.
             *
             * @param array<string, mixed> $settings
             */
            $filtered = apply_filters('ruvelo_comment_subscriptions_settings', $settings);
            self::$cache = is_array($filtered) ? $filtered : $settings;
        }

        return self::$cache;
    }

    public static function get(string $key): mixed
    {
        return self::all()[$key] ?? self::defaults()[$key] ?? null;
    }

    public static function string(string $key): string
    {
        $value = self::get($key);

        return is_scalar($value) ? (string) $value : '';
    }

    public static function int(string $key): int
    {
        $value = self::get($key);

        return is_numeric($value) ? (int) $value : 0;
    }

    public static function bool(string $key): bool
    {
        return (bool) self::get($key);
    }

    /**
     * @param array<string, mixed> $values
     */
    public static function update(array $values): void
    {
        $saved = get_option(self::OPTION, []);
        update_option(self::OPTION, array_merge(is_array($saved) ? $saved : [], $values));
        self::flush();
    }

    public static function flush(): void
    {
        self::$cache = null;
    }

    public static function siteName(): string
    {
        return wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES);
    }

    public static function fromName(): string
    {
        $name = trim(self::string('from_name'));

        return $name !== '' ? $name : self::siteName();
    }

    public static function frequency(): string
    {
        return self::string('frequency') === 'daily' ? 'daily' : 'instant';
    }
}
