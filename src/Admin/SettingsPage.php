<?php

declare(strict_types=1);

namespace Ruvelo\WP\CommentSubscriptions\Admin;

use Ruvelo\WP\CommentSubscriptions\Inbox;
use Ruvelo\WP\CommentSubscriptions\Settings;
use Ruvelo\WP\CommentSubscriptions\Urls;

/**
 * The Settings tab (administrators only).
 */
final class SettingsPage
{
    public static function register(): void
    {
        add_action('admin_init', [self::class, 'settings']);
    }

    public static function settings(): void
    {
        register_setting('ruvelo_comment_subscriptions', Settings::OPTION, [
            'type' => 'object',
            'sanitize_callback' => [self::class, 'sanitize'],
            'default' => Settings::defaults(),
            'show_in_rest' => false,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function sanitize(mixed $input): array
    {
        $input = is_array($input) ? $input : [];
        $pick = static fn (string $key, array $allowed, string $default): string => isset($input[$key]) && is_string($input[$key]) && in_array($input[$key], $allowed, true) ? $input[$key] : $default;
        $number = static fn (string $key, int $min, int $max, int $default): int => isset($input[$key]) && is_numeric($input[$key]) ? max($min, min($max, (int) $input[$key])) : $default;
        $replyTo = isset($input['reply_to']) && is_string($input['reply_to']) ? sanitize_email($input['reply_to']) : '';

        $clean = [
            'offer_replies' => ! empty($input['offer_replies']),
            'offer_all' => ! empty($input['offer_all']),
            'checked' => $pick('checked', ['', 'replies', 'all'], ''),
            'without_commenting' => ! empty($input['without_commenting']),
            'frequency' => $pick('frequency', ['instant', 'daily'], 'instant'),
            'digest_hour' => $number('digest_hour', 0, 23, 8),
            'pending_days' => $number('pending_days', 1, 30, 3),
            'link_days' => $number('link_days', 1, 90, 14),
            'excerpt_words' => $number('excerpt_words', 0, 500, 55),
            'from_name' => isset($input['from_name']) && is_string($input['from_name']) ? sanitize_text_field($input['from_name']) : '',
            'reply_to' => is_email($replyTo) ? $replyTo : '',
            'batch_size' => $number('batch_size', 1, 500, 50),
            'ip_limit' => $number('ip_limit', 0, 1000, 10),
            'email_limit' => $number('email_limit', 0, 100, 3),
            'color_scheme' => $pick('color_scheme', ['light', 'auto', 'dark'], 'light'),
            'delete_data' => ! empty($input['delete_data']),
        ];
        Settings::flush();

        return $clean;
    }

    public static function render(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }
        $s = Settings::all();
        $name = Settings::OPTION;
        $checkbox = static function (string $key, string $label, string $help = '') use ($s, $name): void {
            printf(
                '<label class="ruvelo-cs-check"><input type="checkbox" name="%s[%s]" value="1" %s> <span>%s%s</span></label>',
                esc_attr($name),
                esc_attr($key),
                checked(! empty($s[$key]), true, false),
                esc_html($label),
                $help !== '' ? '<span class="description">'.esc_html($help).'</span>' : ''
            );
        };
        $number = static function (string $key, int $min, int $max, string $suffix) use ($s, $name): void {
            printf(
                '<input type="number" class="small-text" id="ruvelo-cs-%2$s" name="%1$s[%2$s]" value="%3$d" min="%4$d" max="%5$d"> %6$s',
                esc_attr($name),
                esc_attr($key),
                (int) ($s[$key] ?? 0),
                (int) $min,
                (int) $max,
                esc_html($suffix)
            );
        };
        settings_errors();
        ?>
        <form method="post" action="options.php" class="ruvelo-cs-settings">
            <?php settings_fields('ruvelo_comment_subscriptions'); ?>

            <h2><?php esc_html_e('The comment form', 'ruvelo-comment-subscriptions'); ?></h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e('Options to offer', 'ruvelo-comment-subscriptions'); ?></th>
                    <td>
                        <fieldset>
                            <legend class="screen-reader-text"><?php esc_html_e('Options to offer', 'ruvelo-comment-subscriptions'); ?></legend>
                            <?php
                        $checkbox('offer_replies', __('“Notify me of replies to my comment”', 'ruvelo-comment-subscriptions'));
        $checkbox('offer_all', __('“Notify me of all new comments on this post”', 'ruvelo-comment-subscriptions'));
        $checkbox('without_commenting', __('“Subscribe without commenting” under the comment form', 'ruvelo-comment-subscriptions'), __('Or place the Subscribe to Comments block yourself.', 'ruvelo-comment-subscriptions'));
        ?>
                        </fieldset>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="ruvelo-cs-checked"><?php esc_html_e('Ticked by default', 'ruvelo-comment-subscriptions'); ?></label></th>
                    <td>
                        <select id="ruvelo-cs-checked" name="<?php echo esc_attr($name); ?>[checked]">
                            <option value="" <?php selected($s['checked'], ''); ?>><?php esc_html_e('Nothing (recommended)', 'ruvelo-comment-subscriptions'); ?></option>
                            <option value="replies" <?php selected($s['checked'], 'replies'); ?>><?php esc_html_e('Replies to my comment', 'ruvelo-comment-subscriptions'); ?></option>
                            <option value="all" <?php selected($s['checked'], 'all'); ?>><?php esc_html_e('All new comments', 'ruvelo-comment-subscriptions'); ?></option>
                        </select>
                        <p class="description"><?php esc_html_e('Under the GDPR and similar laws, consent must be an active choice, so boxes start unticked. Guests always confirm by email either way.', 'ruvelo-comment-subscriptions'); ?></p>
                    </td>
                </tr>
            </table>

            <h2><?php esc_html_e('Emails', 'ruvelo-comment-subscriptions'); ?></h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e('New subscribers get', 'ruvelo-comment-subscriptions'); ?></th>
                    <td>
                        <fieldset>
                            <legend class="screen-reader-text"><?php esc_html_e('New subscribers get', 'ruvelo-comment-subscriptions'); ?></legend>
                            <label class="ruvelo-cs-check"><input type="radio" name="<?php echo esc_attr($name); ?>[frequency]" value="instant" <?php checked($s['frequency'], 'instant'); ?>> <span><?php esc_html_e('An email per comment, as they’re approved', 'ruvelo-comment-subscriptions'); ?></span></label>
                            <label class="ruvelo-cs-check"><input type="radio" name="<?php echo esc_attr($name); ?>[frequency]" value="daily" <?php checked($s['frequency'], 'daily'); ?>> <span><?php esc_html_e('A daily digest', 'ruvelo-comment-subscriptions'); ?></span></label>
                            <p class="description"><?php esc_html_e('Subscribers can change this on their manage page.', 'ruvelo-comment-subscriptions'); ?></p>
                        </fieldset>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="ruvelo-cs-digest-hour"><?php esc_html_e('Send digests at', 'ruvelo-comment-subscriptions'); ?></label></th>
                    <td>
                        <select id="ruvelo-cs-digest-hour" name="<?php echo esc_attr($name); ?>[digest_hour]">
                            <?php for ($hour = 0; $hour < 24; $hour++) { ?>
                                <option value="<?php echo esc_attr((string) $hour); ?>" <?php selected((int) $s['digest_hour'], $hour); ?>><?php echo esc_html((string) wp_date((string) get_option('time_format'), $hour * HOUR_IN_SECONDS, new \DateTimeZone('UTC'))); ?></option>
                            <?php } ?>
                        </select>
                        <span class="description"><?php esc_html_e('In your site’s time zone.', 'ruvelo-comment-subscriptions'); ?></span>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="ruvelo-cs-excerpt_words"><?php esc_html_e('Quote from the comment', 'ruvelo-comment-subscriptions'); ?></label></th>
                    <td><?php $number('excerpt_words', 0, 500, __('words (0: just a link)', 'ruvelo-comment-subscriptions')); ?></td>
                </tr>
                <tr>
                    <th scope="row"><label for="ruvelo-cs-from-name"><?php esc_html_e('From name', 'ruvelo-comment-subscriptions'); ?></label></th>
                    <td><input type="text" class="regular-text" id="ruvelo-cs-from-name" name="<?php echo esc_attr($name); ?>[from_name]" value="<?php echo esc_attr((string) $s['from_name']); ?>" placeholder="<?php echo esc_attr(Settings::siteName()); ?>"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="ruvelo-cs-reply-to"><?php esc_html_e('Reply-To address', 'ruvelo-comment-subscriptions'); ?></label></th>
                    <td>
                        <input type="email" class="regular-text" id="ruvelo-cs-reply-to" name="<?php echo esc_attr($name); ?>[reply_to]" value="<?php echo esc_attr((string) $s['reply_to']); ?>">
                        <p class="description"><?php esc_html_e('Optional. Where replies to notification emails go.', 'ruvelo-comment-subscriptions'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="ruvelo-cs-batch_size"><?php esc_html_e('Batch size', 'ruvelo-comment-subscriptions'); ?></label></th>
                    <td>
                        <?php $number('batch_size', 1, 500, __('emails a minute, sent by WP-Cron', 'ruvelo-comment-subscriptions')); ?>
                        <p class="description"><?php esc_html_e('Emails never go out while someone waits for a page. On a quiet site, set up a real cron job for WP-Cron, or run “wp ruvelo-comment-subscriptions queue run”.', 'ruvelo-comment-subscriptions'); ?></p>
                    </td>
                </tr>
            </table>

            <h2><?php esc_html_e('Links and limits', 'ruvelo-comment-subscriptions'); ?></h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="ruvelo-cs-pending_days"><?php esc_html_e('Confirmation links work for', 'ruvelo-comment-subscriptions'); ?></label></th>
                    <td><?php $number('pending_days', 1, 30, __('days, then unconfirmed requests are deleted', 'ruvelo-comment-subscriptions')); ?></td>
                </tr>
                <tr>
                    <th scope="row"><label for="ruvelo-cs-link_days"><?php esc_html_e('Manage links work for', 'ruvelo-comment-subscriptions'); ?></label></th>
                    <td><?php $number('link_days', 1, 90, __('days', 'ruvelo-comment-subscriptions')); ?></td>
                </tr>
                <tr>
                    <th scope="row"><label for="ruvelo-cs-ip_limit"><?php esc_html_e('Requests per IP address', 'ruvelo-comment-subscriptions'); ?></label></th>
                    <td><?php $number('ip_limit', 0, 1000, __('an hour (0: no limit)', 'ruvelo-comment-subscriptions')); ?></td>
                </tr>
                <tr>
                    <th scope="row"><label for="ruvelo-cs-email_limit"><?php esc_html_e('Emails to one address', 'ruvelo-comment-subscriptions'); ?></label></th>
                    <td>
                        <?php $number('email_limit', 0, 100, __('confirmation or link emails a day (0: no limit)', 'ruvelo-comment-subscriptions')); ?>
                        <p class="description"><?php esc_html_e('Stops anyone from using your site to flood a stranger’s inbox.', 'ruvelo-comment-subscriptions'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Subscriptions page', 'ruvelo-comment-subscriptions'); ?></th>
                    <td>
                        <a href="<?php echo esc_url(Urls::page()); ?>"><code><?php echo esc_html(Urls::page()); ?></code></a>
                        <p class="description"><?php esc_html_e('Where the links in emails go. Nothing to set up: block themes can restyle it in the Site Editor (Templates → Comment subscriptions).', 'ruvelo-comment-subscriptions'); ?></p>
                    </td>
                </tr>
            </table>

            <h2><?php esc_html_e('Look', 'ruvelo-comment-subscriptions'); ?></h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e('Colour scheme', 'ruvelo-comment-subscriptions'); ?></th>
                    <td>
                        <fieldset>
                            <legend class="screen-reader-text"><?php esc_html_e('Colour scheme', 'ruvelo-comment-subscriptions'); ?></legend>
                            <?php foreach (['light' => __('Light', 'ruvelo-comment-subscriptions'), 'auto' => __('Follow each visitor’s system setting', 'ruvelo-comment-subscriptions'), 'dark' => __('Dark', 'ruvelo-comment-subscriptions')] as $value => $label) { ?>
                                <label class="ruvelo-cs-check"><input type="radio" name="<?php echo esc_attr($name); ?>[color_scheme]" value="<?php echo esc_attr($value); ?>" <?php checked($s['color_scheme'], $value); ?>> <span><?php echo esc_html($label); ?></span></label>
                            <?php } ?>
                            <p class="description"><?php esc_html_e('For the subscriptions page and the Subscribe to Comments block. The checkboxes in the comment form always follow your theme.', 'ruvelo-comment-subscriptions'); ?></p>
                        </fieldset>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Ruvelo Inbox', 'ruvelo-comment-subscriptions'); ?></th>
                    <td>
                        <p><?php echo esc_html(Inbox::available() ? __('Found. Signed-in subscribers can choose their inbox, email, or both.', 'ruvelo-comment-subscriptions') : __('Not installed. Everything goes by email.', 'ruvelo-comment-subscriptions')); ?></p>
                    </td>
                </tr>
            </table>

            <h2><?php esc_html_e('Uninstalling', 'ruvelo-comment-subscriptions'); ?></h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e('When the plugin is deleted', 'ruvelo-comment-subscriptions'); ?></th>
                    <td>
                        <?php $checkbox('delete_data', __('Also delete every subscription and setting', 'ruvelo-comment-subscriptions'), __('Off by default, so subscriptions survive a reinstall. Scheduled emails are always cleared.', 'ruvelo-comment-subscriptions')); ?>
                    </td>
                </tr>
            </table>

            <?php submit_button(__('Save changes', 'ruvelo-comment-subscriptions')); ?>
        </form>
        <?php
    }
}
