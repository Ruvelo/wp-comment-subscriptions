<?php

/**
 * Renders the Subscribe to Comments block.
 *
 * @var array<string, mixed> $attributes
 * @var WP_Block $block
 */

declare(strict_types=1);

use Ruvelo\WP\CommentSubscriptions\Frontend\Views;

defined('ABSPATH') || exit;

$ruvelo_attributes = $attributes;
if (empty($ruvelo_attributes['postId']) && isset($block->context['postId'])) {
    $ruvelo_attributes['postId'] = (int) $block->context['postId'];
}

echo Views::subscribeForm($ruvelo_attributes, get_block_wrapper_attributes(Views::wrapperArgs('subscribe'))); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in the view.
