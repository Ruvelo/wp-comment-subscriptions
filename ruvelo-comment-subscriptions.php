<?php

/**
 * Plugin Name:       Ruvelo Comment Subscriptions
 * Plugin URI:        https://ruvelo.github.io/wp-comment-subscriptions/
 * Description:       Readers subscribe to replies and new comments by email, with double opt-in, one-click unsubscribe and a page to manage it all. Imports from Subscribe To Comments Reloaded.
 * Version:           1.0.0
 * Requires at least: 7.0
 * Requires PHP:      8.2
 * Author:            Ruvelo
 * Author URI:        https://github.com/Ruvelo
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ruvelo-comment-subscriptions
 * Domain Path:       /languages
 */

declare(strict_types=1);
use Ruvelo\WP\CommentSubscriptions\Install;
use Ruvelo\WP\CommentSubscriptions\Plugin;

if (! defined('ABSPATH')) {
    exit;
}

define('RUVELO_COMMENT_SUBSCRIPTIONS_VERSION', '1.0.0');
define('RUVELO_COMMENT_SUBSCRIPTIONS_FILE', __FILE__);
define('RUVELO_COMMENT_SUBSCRIPTIONS_DIR', plugin_dir_path(__FILE__));
define('RUVELO_COMMENT_SUBSCRIPTIONS_URL', plugin_dir_url(__FILE__));

// A tiny PSR-4 loader, so the plugin works from a zip without Composer.
spl_autoload_register(static function (string $class): void {
    $prefix = 'Ruvelo\\WP\\CommentSubscriptions\\';
    if (! str_starts_with($class, $prefix)) {
        return;
    }
    $file = RUVELO_COMMENT_SUBSCRIPTIONS_DIR.'src/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
    if (is_readable($file)) {
        require $file;
    }
});

register_activation_hook(__FILE__, [Install::class, 'activate']);
register_deactivation_hook(__FILE__, [Install::class, 'deactivate']);

Plugin::boot();
