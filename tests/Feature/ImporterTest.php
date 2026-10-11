<?php

declare(strict_types=1);

namespace Ruvelo\WP\CommentSubscriptions\Tests\Feature;

use Ruvelo\WP\CommentSubscriptions\Import\Importer;
use Ruvelo\WP\CommentSubscriptions\Import\Report;
use Ruvelo\WP\CommentSubscriptions\Subscriptions;
use Ruvelo\WP\CommentSubscriptions\Tests\TestCase;

final class ImporterTest extends TestCase
{
    /** @var array<string, int> */
    private array $posts = [];

    private function seedStcr(): void
    {
        $this->posts = ['a' => $this->post(), 'b' => $this->post(['comment_status' => 'closed']), 'gone' => 987654];
        global $wpdb;
        foreach (require dirname(__DIR__).'/fixtures/stcr.php' as [$post, $key, $value]) {
            // Written straight to the table, as the old plugin did.
            $wpdb->insert($wpdb->postmeta, ['post_id' => $this->posts[$post], 'meta_key' => $key, 'meta_value' => $value]);
        }
        update_post_meta($this->posts['b'], 'stcr_disable_subscriptions', 'yes');
        wp_cache_flush();
    }

    public function test_it_detects_the_old_data(): void
    {
        $this->seedStcr();

        $this->assertSame(10, Importer::detect()['stcr']);
    }

    public function test_a_dry_run_reports_and_changes_nothing(): void
    {
        $this->seedStcr();

        $report = Importer::run('stcr', true);

        $this->assertInstanceOf(Report::class, $report);
        $this->assertTrue($report->dryRun);
        $this->assertSame(10, $report->scanned);
        $this->assertSame(5, $report->imported);
        $this->assertSame(2, $report->unconfirmed);
        $this->assertSame(1, $report->invalidEmail);
        $this->assertSame(1, $report->unknown);
        $this->assertSame(1, $report->missingPost);
        $this->assertSame(1, $report->disabledPosts);
        $this->assertSame([], Subscriptions::query()['items']);
        $this->assertSame('', get_post_meta($this->posts['b'], Subscriptions::DISABLED_META, true));
    }

    public function test_confirmed_subscriptions_stay_confirmed(): void
    {
        $this->seedStcr();

        $report = Importer::run('stcr');

        $this->assertSame(5, $report->imported);
        $this->assertSame(4, $report->all);
        $this->assertSame(1, $report->replies);
        $this->assertSame(1, $report->daily);
        $hannah = Subscriptions::findFor($this->posts['a'], 'hannah@brooks.example');
        $this->assertNotNull($hannah);
        $this->assertTrue($hannah->isActive());
        $this->assertSame('all', $hannah->type);
        $this->assertSame('stcr', $hannah->source);
        $this->assertSame(get_gmt_from_date('2026-03-02 09:14:00'), $hannah->createdAt, 'Keeps the original date, in UTC.');
        $this->assertSame('replies', Subscriptions::findFor($this->posts['a'], 'amara@nwosu.example')->type);
        $this->assertNotNull(Subscriptions::findFor($this->posts['a'], 'mixed.case@example.com'));
        $this->assertSame('daily', Subscriptions::findFor($this->posts['b'], 'priya@raman.example')->frequency);
        $this->assertNull(Subscriptions::findFor($this->posts['a'], 'old.customer@example.com'), 'Never confirmed: left out.');
        $this->assertNull(Subscriptions::findFor($this->posts['a'], 'suspended@example.com'));
        $this->assertSame('1', get_post_meta($this->posts['b'], Subscriptions::DISABLED_META, true));
        $this->assertSame([], $this->sent(), 'Nobody gets a confirmation email.');
    }

    public function test_the_source_data_is_left_alone_and_a_second_run_adds_nobody(): void
    {
        $this->seedStcr();
        Importer::run('stcr');

        $again = Importer::run('stcr');

        $this->assertSame(0, $again->imported);
        $this->assertSame(5, $again->exists);
        $this->assertSame(10, Importer::detect()['stcr']);
    }

    public function test_it_runs_in_steps(): void
    {
        $this->seedStcr();

        $first = Importer::run('stcr', false, 0, 0, 4);
        $this->assertInstanceOf(Report::class, $first);
        $this->assertTrue($first->done);
        $this->assertSame(10, $first->scanned);

        $total = new Report('stcr', false);
        $cursor = 0;
        do {
            $step = Importer::run('stcr', true, $cursor, -1, 3);
            $total->merge($step);
            $cursor = $step->cursor;
        } while (! $step->done);
        $this->assertSame(10, $total->scanned);
    }

    public function test_the_original_subscribe_to_comments(): void
    {
        $post = $this->post();
        add_post_meta($post, Importer::STC_KEY, 'reader@example.com');
        add_post_meta($post, Importer::STC_KEY, 'Other@Example.com');
        add_post_meta($post, '_sg_subscribe-to-comments-pending', 'pending@example.com');

        $report = Importer::run('stc');

        $this->assertSame(2, $report->imported);
        $this->assertSame(1, $report->unconfirmed);
        $this->assertTrue(Subscriptions::findFor($post, 'other@example.com')->isActive());
    }

    public function test_an_unknown_source_is_an_error(): void
    {
        $this->assertInstanceOf(\WP_Error::class, Importer::run('jetpack'));
    }

    public function test_addresses_are_masked_in_notes(): void
    {
        $this->assertSame('w****@example.com', Report::mask('weird@example.com'));
    }
}
