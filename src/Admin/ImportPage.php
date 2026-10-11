<?php

declare(strict_types=1);

namespace Ruvelo\WP\CommentSubscriptions\Admin;

use Ruvelo\WP\CommentSubscriptions\Import\Importer;
use Ruvelo\WP\CommentSubscriptions\Import\Report;
use Ruvelo\WP\CommentSubscriptions\Settings;
use Ruvelo\WP\CommentSubscriptions\Support\Redirect;

/**
 * The Import tab: from Subscribe To Comments Reloaded and Subscribe to
 * Comments, with a dry run first. Big imports run in steps of about 20
 * seconds; WP-CLI does it in one go.
 */
final class ImportPage
{
    public static function register(): void
    {
        add_action('admin_post_ruvelo_cs_import', [self::class, 'handle']);
    }

    private static function key(): string
    {
        return 'ruvelo_cs_import_'.get_current_user_id();
    }

    public static function handle(): void
    {
        check_admin_referer('ruvelo_cs_import');
        if (! current_user_can(Settings::CAP)) {
            wp_die(esc_html__('You can’t manage comment subscriptions on this site.', 'ruvelo-comment-subscriptions'), '', ['response' => 403]);
        }
        $source = isset($_POST['source']) ? sanitize_key(wp_unslash($_POST['source'])) : '';
        $dryRun = ! empty($_POST['dry_run']);
        $after = isset($_POST['cursor']) ? absint($_POST['cursor']) : 0;

        $report = Importer::run($source, $dryRun, $after, 20);
        if ($report instanceof Report) {
            // A continued import adds up with the steps before it.
            $previous = get_transient(self::key());
            if ($after > 0 && is_array($previous) && ($previous['source'] ?? '') === $source && (bool) ($previous['dry_run'] ?? false) === $dryRun) {
                $total = Report::fromArray($previous);
                $total->merge($report);
                $report = $total;
            }
            set_transient(self::key(), $report->toArray(), 30 * MINUTE_IN_SECONDS);
        }

        $url = Admin::url('import', ['ruvelo_cs_report' => '1']);
        if (defined('RUVELO_COMMENT_SUBSCRIPTIONS_TESTS')) {
            throw new Redirect($url); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Test-only signal.
        }
        wp_safe_redirect($url);
        exit;
    }

    public static function render(): void
    {
        $found = Importer::detect();
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
        $show = isset($_GET['ruvelo_cs_report']);
        $saved = $show ? get_transient(self::key()) : false;
        $report = is_array($saved) ? Report::fromArray($saved) : null;
        $descriptions = [
            'stcr' => __('Closed on WordPress.org in April 2026 for a security issue. Its subscriptions live in your posts’ custom fields; this reads them, and never changes or deletes them.', 'ruvelo-comment-subscriptions'),
            'stc' => __('The original plugin by Mark Jaquith. Confirmed subscriptions to all comments are imported.', 'ruvelo-comment-subscriptions'),
        ];
        ?>
        <p class="ruvelo-cs-intro"><?php esc_html_e('Moving from another plugin? Bring your subscribers with you. Confirmed subscriptions stay confirmed, so nobody gets a confirmation email; requests that were never confirmed (or were suspended) are left out. Try a dry run first: it shows what would happen without changing anything.', 'ruvelo-comment-subscriptions'); ?></p>

        <?php if ($report !== null) { ?>
            <?php self::report($report); ?>
        <?php } ?>

        <div class="ruvelo-cs-sources">
            <?php foreach (Importer::SOURCES as $source) { ?>
                <section class="ruvelo-cs-source<?php echo $found[$source] > 0 ? ' is-found' : ''; ?>" aria-labelledby="ruvelo-cs-source-<?php echo esc_attr($source); ?>">
                    <div class="ruvelo-cs-source-head">
                        <h2 id="ruvelo-cs-source-<?php echo esc_attr($source); ?>"><?php echo esc_html(Importer::label($source)); ?></h2>
                        <?php if ($found[$source] > 0) { ?>
                            <span class="ruvelo-cs-chip is-accent">
                                <?php
                                /* translators: %s: number of rows */
                                echo esc_html(sprintf(_n('%s subscription found', '%s subscriptions found', $found[$source], 'ruvelo-comment-subscriptions'), number_format_i18n($found[$source])));
                            ?>
                            </span>
                        <?php } else { ?>
                            <span class="ruvelo-cs-chip"><?php esc_html_e('Nothing found', 'ruvelo-comment-subscriptions'); ?></span>
                        <?php } ?>
                    </div>
                    <p><?php echo esc_html($descriptions[$source]); ?></p>
                    <?php if ($found[$source] > 0) { ?>
                        <div class="ruvelo-cs-source-actions">
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                <input type="hidden" name="action" value="ruvelo_cs_import">
                                <input type="hidden" name="source" value="<?php echo esc_attr($source); ?>">
                                <input type="hidden" name="dry_run" value="1">
                                <?php wp_nonce_field('ruvelo_cs_import'); ?>
                                <button type="submit" class="button"><?php esc_html_e('Dry run', 'ruvelo-comment-subscriptions'); ?></button>
                            </form>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                <input type="hidden" name="action" value="ruvelo_cs_import">
                                <input type="hidden" name="source" value="<?php echo esc_attr($source); ?>">
                                <?php wp_nonce_field('ruvelo_cs_import'); ?>
                                <button type="submit" class="button button-primary"><?php esc_html_e('Import subscriptions', 'ruvelo-comment-subscriptions'); ?></button>
                            </form>
                        </div>
                    <?php } ?>
                    <p class="ruvelo-cs-cli">
                        <?php esc_html_e('With WP-CLI:', 'ruvelo-comment-subscriptions'); ?>
                        <code>wp ruvelo-comment-subscriptions import <?php echo esc_html($source); ?> --dry-run</code>
                    </p>
                </section>
            <?php } ?>
        </div>
        <?php
    }

    private static function report(Report $report): void
    {
        $rows = [
            [__('Subscriptions to all new comments', 'ruvelo-comment-subscriptions'), $report->all, 'is-success'],
            [__('Subscriptions to replies only', 'ruvelo-comment-subscriptions'), $report->replies, 'is-success'],
            [__('Already subscribed here (left as they are)', 'ruvelo-comment-subscriptions'), $report->exists, ''],
            [__('Never confirmed or suspended (left out)', 'ruvelo-comment-subscriptions'), $report->unconfirmed, ''],
            [__('Invalid address (left out)', 'ruvelo-comment-subscriptions'), $report->invalidEmail, ''],
            [__('Post no longer exists (left out)', 'ruvelo-comment-subscriptions'), $report->missingPost, ''],
            [__('Unknown status (left out)', 'ruvelo-comment-subscriptions'), $report->unknown, ''],
        ];
        if ($report->daily > 0) {
            $rows[] = [__('…of which on a daily digest', 'ruvelo-comment-subscriptions'), $report->daily, ''];
        }
        if ($report->disabledPosts > 0) {
            $rows[] = [__('Posts with subscriptions turned off (kept off)', 'ruvelo-comment-subscriptions'), $report->disabledPosts, ''];
        }
        ?>
        <section class="ruvelo-cs-report<?php echo $report->dryRun ? ' is-dry' : ''; ?>" aria-labelledby="ruvelo-cs-report-title" role="status">
            <p class="ruvelo-cs-report-kicker"><?php echo esc_html($report->dryRun ? __('Dry run: nothing was changed', 'ruvelo-comment-subscriptions') : __('Import report', 'ruvelo-comment-subscriptions')); ?></p>
            <h2 id="ruvelo-cs-report-title">
                <?php
                echo esc_html($report->dryRun
                    /* translators: 1: number that would be imported, 2: rows looked at, 3: plugin name */
                    ? sprintf(__('%1$s of %2$s %3$s subscriptions would be imported', 'ruvelo-comment-subscriptions'), number_format_i18n($report->imported), number_format_i18n($report->scanned), Importer::label($report->source))
                    /* translators: 1: number imported, 2: rows looked at, 3: plugin name */
                    : sprintf(__('%1$s of %2$s %3$s subscriptions imported', 'ruvelo-comment-subscriptions'), number_format_i18n($report->imported), number_format_i18n($report->scanned), Importer::label($report->source)));
        ?>
            </h2>
            <table class="ruvelo-cs-report-table">
                <tbody>
                    <?php foreach ($rows as [$label, $count, $class]) { ?>
                        <tr class="<?php echo esc_attr($count > 0 ? $class : 'is-zero'); ?>"><th scope="row"><?php echo esc_html($label); ?></th><td><?php echo esc_html(number_format_i18n($count)); ?></td></tr>
                    <?php } ?>
                </tbody>
            </table>
            <?php if ($report->notes !== []) { ?>
                <details class="ruvelo-cs-report-notes">
                    <summary><?php esc_html_e('Examples of rows left out', 'ruvelo-comment-subscriptions'); ?></summary>
                    <ul>
                        <?php foreach ($report->notes as $note) { ?>
                            <li><?php echo esc_html($note); ?></li>
                        <?php } ?>
                    </ul>
                </details>
            <?php } ?>
            <?php if (! $report->done) { ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="ruvelo-cs-report-next">
                    <input type="hidden" name="action" value="ruvelo_cs_import">
                    <input type="hidden" name="source" value="<?php echo esc_attr($report->source); ?>">
                    <input type="hidden" name="cursor" value="<?php echo esc_attr((string) $report->cursor); ?>">
                    <?php if ($report->dryRun) { ?><input type="hidden" name="dry_run" value="1"><?php } ?>
                    <?php wp_nonce_field('ruvelo_cs_import'); ?>
                    <p><?php esc_html_e('There’s more: big imports run in steps.', 'ruvelo-comment-subscriptions'); ?></p>
                    <button type="submit" class="button button-primary"><?php esc_html_e('Continue', 'ruvelo-comment-subscriptions'); ?></button>
                </form>
            <?php } elseif ($report->dryRun && $report->imported > 0) { ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="ruvelo-cs-report-next">
                    <input type="hidden" name="action" value="ruvelo_cs_import">
                    <input type="hidden" name="source" value="<?php echo esc_attr($report->source); ?>">
                    <?php wp_nonce_field('ruvelo_cs_import'); ?>
                    <p><?php esc_html_e('Looks right? The import can be run again safely: nobody is added twice.', 'ruvelo-comment-subscriptions'); ?></p>
                    <button type="submit" class="button button-primary"><?php esc_html_e('Import subscriptions', 'ruvelo-comment-subscriptions'); ?></button>
                </form>
            <?php } ?>
        </section>
        <?php
    }
}
