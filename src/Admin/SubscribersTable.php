<?php

declare(strict_types=1);

namespace Ruvelo\WP\CommentSubscriptions\Admin;

use Ruvelo\WP\CommentSubscriptions\Subscription;
use Ruvelo\WP\CommentSubscriptions\Subscriptions;
use WP_List_Table;

if (! defined('ABSPATH')) {
    exit;
}

if (! class_exists('WP_List_Table')) {
    require_once ABSPATH.'wp-admin/includes/class-wp-list-table.php';
}

/**
 * The subscribers list: search by address or post, filter, sort, bulk delete.
 */
final class SubscribersTable extends WP_List_Table
{
    public function __construct()
    {
        parent::__construct(['singular' => 'subscription', 'plural' => 'subscriptions', 'ajax' => false]);
    }

    /**
     * @return array<string, string>
     */
    public function get_columns(): array
    {
        return [
            'cb' => '<input type="checkbox">',
            'email' => __('Email', 'ruvelo-comment-subscriptions'),
            'post' => __('Post', 'ruvelo-comment-subscriptions'),
            'type' => __('Emails about', 'ruvelo-comment-subscriptions'),
            'status' => __('Status', 'ruvelo-comment-subscriptions'),
            'frequency' => __('How often', 'ruvelo-comment-subscriptions'),
            'created' => __('Subscribed', 'ruvelo-comment-subscriptions'),
        ];
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    protected function get_sortable_columns(): array
    {
        return [
            'email' => ['email', false],
            'post' => ['post', false],
            'type' => ['type', false],
            'status' => ['status', false],
            'created' => ['created', true],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function get_bulk_actions(): array
    {
        return ['delete' => __('Delete', 'ruvelo-comment-subscriptions')];
    }

    public function prepare_items(): void
    {
        $perPage = $this->get_items_per_page('ruvelo_cs_per_page', 20);
        $result = Subscriptions::query([
            'search' => self::get('s'),
            'post_id' => absint(self::get('post_id')),
            'status' => self::get('status'),
            'type' => self::get('type'),
            'orderby' => self::get('orderby') !== '' ? self::get('orderby') : 'created',
            'order' => self::get('order') !== '' ? self::get('order') : 'desc',
            'page' => $this->get_pagenum(),
            'per_page' => $perPage,
        ]);
        $this->items = $result['items'];
        $this->set_pagination_args(['total_items' => $result['total'], 'per_page' => $perPage]);
        $this->_column_headers = [$this->get_columns(), [], $this->get_sortable_columns(), 'email'];
        // Warm the post cache for the titles.
        _prime_post_caches(array_values(array_unique(array_map(static fn (Subscription $s): int => $s->postId, $result['items']))), false, false);
    }

    /**
     * @return array<string, string>
     */
    protected function get_views(): array
    {
        $stats = Subscriptions::stats();
        $current = self::get('status');
        $base = remove_query_arg(['status', 'paged', 'ruvelo_cs_notice', 'count']);
        $views = [
            '' => [__('All', 'ruvelo-comment-subscriptions'), $stats['active'] + $stats['pending']],
            'active' => [__('Active', 'ruvelo-comment-subscriptions'), $stats['active']],
            'pending' => [__('Waiting for confirmation', 'ruvelo-comment-subscriptions'), $stats['pending']],
        ];
        $out = [];
        foreach ($views as $key => [$label, $count]) {
            $out[$key !== '' ? $key : 'all'] = sprintf(
                '<a href="%s"%s>%s <span class="count">(%s)</span></a>',
                esc_url($key !== '' ? add_query_arg('status', $key, $base) : $base),
                $current === $key ? ' class="current" aria-current="page"' : '',
                esc_html($label),
                esc_html(number_format_i18n($count))
            );
        }

        return $out;
    }

    public function no_items(): void
    {
        if (self::get('s') !== '' || self::get('post_id') !== '') {
            esc_html_e('No subscriptions match. Try part of an address or a post title.', 'ruvelo-comment-subscriptions');

            return;
        }
        esc_html_e('No subscriptions yet. Readers subscribe from the comment form, or import them from another plugin.', 'ruvelo-comment-subscriptions');
    }

    /**
     * @param Subscription $item
     */
    protected function column_cb($item): string
    {
        return sprintf(
            '<label class="screen-reader-text" for="ruvelo-cs-%1$d">%2$s</label><input type="checkbox" id="ruvelo-cs-%1$d" name="ids[]" value="%1$d">',
            $item->id,
            /* translators: %s: email address */
            esc_html(sprintf(__('Select %s', 'ruvelo-comment-subscriptions'), $item->email))
        );
    }

    protected function column_email(Subscription $item): string
    {
        $user = $item->userId > 0 ? get_userdata($item->userId) : false;
        $html = '<strong><span class="ruvelo-cs-avatar" aria-hidden="true">'.esc_html(strtoupper(substr($item->email, 0, 1))).'</span>'.esc_html($item->email).'</strong>';
        if ($user !== false) {
            /* translators: %s: display name */
            $html .= '<span class="ruvelo-cs-sub-line">'.esc_html(sprintf(__('Account: %s', 'ruvelo-comment-subscriptions'), $user->display_name)).'</span>';
        }
        $delete = wp_nonce_url(add_query_arg(['action' => 'ruvelo_cs_delete', 'ids' => $item->id], admin_url('admin-post.php')), 'ruvelo_cs_delete');
        $actions = [
            'filter' => '<a href="'.esc_url(Admin::url('subscribers', ['s' => $item->email])).'">'.esc_html__('All for this address', 'ruvelo-comment-subscriptions').'</a>',
            'delete' => '<a class="submitdelete" href="'.esc_url($delete).'">'.esc_html__('Delete', 'ruvelo-comment-subscriptions').'</a>',
        ];

        return $html.$this->row_actions($actions);
    }

    protected function column_post(Subscription $item): string
    {
        $title = get_the_title($item->postId);
        $title = $title !== '' ? $title : __('(no title)', 'ruvelo-comment-subscriptions');
        if (get_post($item->postId) === null) {
            return '<em>'.esc_html__('Deleted post', 'ruvelo-comment-subscriptions').'</em>';
        }

        return '<a href="'.esc_url(Admin::url('subscribers', ['post_id' => $item->postId])).'">'.esc_html($title).'</a>'
            .'<span class="ruvelo-cs-sub-line"><a href="'.esc_url((string) get_permalink($item->postId)).'">'.esc_html__('View post', 'ruvelo-comment-subscriptions').'</a></span>';
    }

    protected function column_type(Subscription $item): string
    {
        return esc_html($item->typeLabel());
    }

    protected function column_status(Subscription $item): string
    {
        return $item->isActive()
            ? '<span class="ruvelo-cs-chip is-success">'.esc_html__('Active', 'ruvelo-comment-subscriptions').'</span>'
            : '<span class="ruvelo-cs-chip is-warning">'.esc_html__('Waiting', 'ruvelo-comment-subscriptions').'</span>';
    }

    protected function column_frequency(Subscription $item): string
    {
        return esc_html($item->frequency === 'daily' ? __('Daily digest', 'ruvelo-comment-subscriptions') : __('As they arrive', 'ruvelo-comment-subscriptions'));
    }

    protected function column_created(Subscription $item): string
    {
        $time = strtotime($item->createdAt.' UTC');
        $date = $time !== false ? (string) wp_date((string) get_option('date_format'), $time) : '';
        $source = match ($item->source) {
            'stcr' => __('Imported from Subscribe To Comments Reloaded', 'ruvelo-comment-subscriptions'),
            'stc' => __('Imported from Subscribe to Comments', 'ruvelo-comment-subscriptions'),
            'comment' => __('From the comment form', 'ruvelo-comment-subscriptions'),
            'form' => __('Without commenting', 'ruvelo-comment-subscriptions'),
            'cli' => __('From WP-CLI', 'ruvelo-comment-subscriptions'),
            default => '',
        };

        return esc_html($date).($source !== '' ? '<span class="ruvelo-cs-sub-line">'.esc_html($source).'</span>' : '');
    }

    /**
     * @param Subscription $item
     * @param string $column_name
     */
    protected function column_default($item, $column_name): string
    {
        return '';
    }

    private static function get(string $key): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filters.
        return isset($_REQUEST[$key]) && is_string($_REQUEST[$key]) ? sanitize_text_field(wp_unslash($_REQUEST[$key])) : '';
    }
}
