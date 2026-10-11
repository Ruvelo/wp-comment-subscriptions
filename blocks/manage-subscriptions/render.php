<?php

/**
 * Renders the Manage Comment Subscriptions block.
 *
 * @var array<string, mixed> $attributes
 */

declare(strict_types=1);

use Ruvelo\WP\CommentSubscriptions\Frontend\Views;

defined('ABSPATH') || exit;

echo Views::page(get_block_wrapper_attributes(Views::wrapperArgs('page'))); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in the view.
