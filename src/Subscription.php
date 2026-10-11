<?php

declare(strict_types=1);

namespace Ruvelo\WP\CommentSubscriptions;

/**
 * One row: an address that follows a post's comments.
 */
final class Subscription
{
    public const TYPES = ['all', 'replies'];

    public const STATUSES = ['active', 'pending'];

    public const FREQUENCIES = ['instant', 'daily'];

    public function __construct(
        public readonly int $id,
        public readonly int $postId,
        public readonly int $userId,
        public readonly string $email,
        public readonly string $type,
        public readonly string $status,
        public readonly string $frequency,
        public readonly string $source,
        public readonly string $createdAt,
        public readonly ?string $confirmedAt,
    ) {}

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $string = static fn (string $key): string => isset($row[$key]) && is_scalar($row[$key]) ? (string) $row[$key] : '';

        return new self(
            (int) $string('id'),
            (int) $string('post_id'),
            (int) $string('user_id'),
            $string('email'),
            $string('type') === 'replies' ? 'replies' : 'all',
            $string('status') === 'active' ? 'active' : 'pending',
            $string('frequency') === 'daily' ? 'daily' : 'instant',
            $string('source'),
            $string('created_at'),
            $string('confirmed_at') !== '' ? $string('confirmed_at') : null,
        );
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Where emails go: a signed-in subscriber's current address, or the one given.
     */
    public function recipient(): string
    {
        if ($this->userId > 0) {
            $user = get_userdata($this->userId);
            if ($user !== false && is_email($user->user_email)) {
                return strtolower($user->user_email);
            }
        }

        return $this->email;
    }

    public function typeLabel(): string
    {
        return $this->type === 'replies'
            ? __('Replies to my comments', 'ruvelo-comment-subscriptions')
            : __('All new comments', 'ruvelo-comment-subscriptions');
    }

    /**
     * @return array{id: int, post_id: int, post_title: string, post_url: string, user_id: int, email: string, type: string, status: string, frequency: string, source: string, created_at: string, confirmed_at: string|null}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'post_id' => $this->postId,
            'post_title' => wp_specialchars_decode(get_the_title($this->postId), ENT_QUOTES),
            'post_url' => (string) get_permalink($this->postId),
            'user_id' => $this->userId,
            'email' => $this->email,
            'type' => $this->type,
            'status' => $this->status,
            'frequency' => $this->frequency,
            'source' => $this->source,
            'created_at' => gmdate('c', (int) strtotime($this->createdAt.' UTC')),
            'confirmed_at' => $this->confirmedAt !== null ? gmdate('c', (int) strtotime($this->confirmedAt.' UTC')) : null,
        ];
    }
}
