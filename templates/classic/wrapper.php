<?php
/**
 * Classic themes: the subscriptions page inside the theme's header and footer.
 * A theme can provide ruvelo-comment-subscriptions/page-wrapper.php instead.
 */

declare(strict_types=1);

use Ruvelo\WP\CommentSubscriptions\Frontend\Views;

defined('ABSPATH') || exit;

get_header();
?>
<main id="primary" class="site-main ruvelo-cs-classic" style="max-width: 46rem; margin: 0 auto; padding: 2.5rem 16px 4rem">
    <?php echo Views::page(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in the view.?>
</main>
<?php
get_footer();
