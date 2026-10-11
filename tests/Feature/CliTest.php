<?php

declare(strict_types=1);

namespace Ruvelo\WP\CommentSubscriptions\Tests\Feature;

require_once dirname(__DIR__).'/Support/FakeCli.php';

use Ruvelo\WP\CommentSubscriptions\Cli\Command;
use Ruvelo\WP\CommentSubscriptions\Cli\QueueCommand;
use Ruvelo\WP\CommentSubscriptions\Import\Importer;
use Ruvelo\WP\CommentSubscriptions\Notifications;
use Ruvelo\WP\CommentSubscriptions\Subscriptions;
use Ruvelo\WP\CommentSubscriptions\Tests\TestCase;
use WP_CLI;

final class CliTest extends TestCase
{
    public function set_up(): void
    {
        parent::set_up();
        WP_CLI::$out = [];
    }

    public function test_add_list_and_delete(): void
    {
        $post = $this->post();

        Command::add([(string) $post, 'cli@example.com'], ['type' => 'replies', 'confirmed' => true]);
        $this->assertStringContainsString('is active', WP_CLI::last());
        Command::add([(string) $post, 'guest@example.com'], []);
        $this->assertStringContainsString('confirmation email', WP_CLI::last());
        $this->assertSame(['guest@example.com'], $this->sentTo());

        Command::list([], ['post' => (string) $post, 'format' => 'count']);
        $this->assertSame('2', WP_CLI::last());

        $id = Subscriptions::findFor($post, 'cli@example.com')->id;
        Command::delete([(string) $id]);
        $this->assertNull(Subscriptions::find($id));
        Command::delete([], ['email' => 'guest@example.com']);
        $this->assertSame([], Subscriptions::forEmail('guest@example.com', null));
    }

    public function test_import_stcr_with_a_dry_run(): void
    {
        $post = $this->post();
        add_post_meta($post, Importer::STCR_PREFIX.'old@example.com', '2026-01-01 10:00:00|Y');

        Command::import(['stcr'], ['dry-run' => true]);
        $this->assertStringContainsString('Dry run: 1 of 1', WP_CLI::last());
        $this->assertNull(Subscriptions::findFor($post, 'old@example.com'));

        Command::import(['stcr'], ['format' => 'json']);
        $this->assertSame(1, json_decode(WP_CLI::last(), true)['imported']);
        $this->assertNotNull(Subscriptions::findFor($post, 'old@example.com'));
    }

    public function test_send_test_and_prune(): void
    {
        Command::sendTest(['me@example.com'], ['template' => 'digest']);
        $this->assertSame(['me@example.com'], $this->sentTo());
        $this->assertStringStartsWith('[Test]', $this->lastMail()['subject']);

        $this->expectException(\RuntimeException::class);
        Command::sendTest(['me@example.com'], ['template' => 'nope']);
    }

    public function test_queue_run_sends_everything_waiting(): void
    {
        $post = $this->post();
        $this->subscribe($post, 'a@example.com');
        $this->subscribe($post, 'b@example.com', 'all', ['frequency' => 'daily']);
        $this->comment($post);

        (new QueueCommand)->run([], ['digests' => true]);

        $this->assertSame(['a@example.com', 'b@example.com'], $this->sentTo());
        $this->assertSame(0, Notifications::pending());
        $this->assertStringContainsString('sent 1 digests', WP_CLI::last());
    }

    public function test_prune(): void
    {
        Command::prune();
        $this->assertStringContainsString('Deleted 0 expired', WP_CLI::last());
    }
}
