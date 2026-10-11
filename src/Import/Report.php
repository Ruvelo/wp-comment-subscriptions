<?php

declare(strict_types=1);

namespace Ruvelo\WP\CommentSubscriptions\Import;

/**
 * What an import did, or would do in a dry run.
 */
final class Report
{
    public int $scanned = 0;

    public int $imported = 0;

    public int $all = 0;

    public int $replies = 0;

    public int $daily = 0;

    /** Already subscribed here: left as they are. */
    public int $exists = 0;

    /** Never confirmed, or suspended by the subscriber: not imported. */
    public int $unconfirmed = 0;

    public int $invalidEmail = 0;

    public int $missingPost = 0;

    public int $unknown = 0;

    /** Posts where the old plugin had subscriptions turned off. */
    public int $disabledPosts = 0;

    /** The last postmeta row looked at, to continue from. */
    public int $cursor = 0;

    public bool $done = false;

    /** @var list<string> A few examples of rows that were skipped, with addresses masked. */
    public array $notes = [];

    public function __construct(public readonly string $source, public readonly bool $dryRun) {}

    public function note(string $note): void
    {
        if (count($this->notes) < 10) {
            $this->notes[] = $note;
        }
    }

    public function skipped(): int
    {
        return $this->exists + $this->unconfirmed + $this->invalidEmail + $this->missingPost + $this->unknown;
    }

    /**
     * Adds a later batch's numbers to this one.
     */
    public function merge(self $other): void
    {
        foreach (['scanned', 'imported', 'all', 'replies', 'daily', 'exists', 'unconfirmed', 'invalidEmail', 'missingPost', 'unknown', 'disabledPosts'] as $key) {
            $this->{$key} += $other->{$key};
        }
        $this->cursor = $other->cursor;
        $this->done = $other->done;
        foreach ($other->notes as $note) {
            $this->note($note);
        }
    }

    /**
     * @return array<string, int|bool|string|list<string>>
     */
    public function toArray(): array
    {
        return [
            'source' => $this->source,
            'dry_run' => $this->dryRun,
            'scanned' => $this->scanned,
            'imported' => $this->imported,
            'all' => $this->all,
            'replies' => $this->replies,
            'daily' => $this->daily,
            'exists' => $this->exists,
            'unconfirmed' => $this->unconfirmed,
            'invalid_email' => $this->invalidEmail,
            'missing_post' => $this->missingPost,
            'unknown' => $this->unknown,
            'disabled_posts' => $this->disabledPosts,
            'cursor' => $this->cursor,
            'done' => $this->done,
            'notes' => $this->notes,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $report = new self((string) ($data['source'] ?? ''), (bool) ($data['dry_run'] ?? false));
        $map = ['scanned' => 'scanned', 'imported' => 'imported', 'all' => 'all', 'replies' => 'replies', 'daily' => 'daily', 'exists' => 'exists', 'unconfirmed' => 'unconfirmed', 'invalid_email' => 'invalidEmail', 'missing_post' => 'missingPost', 'unknown' => 'unknown', 'disabled_posts' => 'disabledPosts', 'cursor' => 'cursor'];
        foreach ($map as $key => $property) {
            $report->{$property} = (int) ($data[$key] ?? 0);
        }
        $report->done = (bool) ($data['done'] ?? false);
        $report->notes = array_values(array_filter((array) ($data['notes'] ?? []), 'is_string'));

        return $report;
    }

    public static function mask(string $email): string
    {
        $at = strrpos($email, '@');
        if ($at === false || $at === 0) {
            return '***';
        }

        return substr($email, 0, 1).str_repeat('*', max(2, min(6, $at - 1))).substr($email, $at);
    }
}
