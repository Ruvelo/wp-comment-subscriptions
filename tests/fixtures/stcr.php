<?php

declare(strict_types=1);

/*
 * Subscribe To Comments Reloaded's storage, as its last release (trunk on
 * plugins.svn.wordpress.org) wrote it: one postmeta row per address per post,
 * key "_stcr@_<address>", value "<Y-m-d H:i:s in site time>|<status>".
 * Y = all comments, R = replies only; a trailing C = not confirmed / suspended.
 * Plus its per-post switch, meta "stcr_disable_subscriptions".
 *
 * [post key, meta key, meta value]
 */

return [
    ['a', '_stcr@_hannah@brooks.example', '2026-03-02 09:14:00|Y'],
    ['a', '_stcr@_amara@nwosu.example', '2026-03-05 18:40:12|R'],
    ['a', '_stcr@_old.customer@example.com', '2026-03-09 11:00:00|YC'],
    ['a', '_stcr@_suspended@example.com', '2026-03-10 11:00:00|RC'],
    ['a', '_stcr@_Mixed.Case@Example.com', '2026-03-12 08:00:00|Y'],
    ['b', '_stcr@_hannah@brooks.example', '2025-11-20 07:30:00|Y'],
    ['b', '_stcr@_priya@raman.example', '2025-11-21 07:30:00|D'],
    ['b', '_stcr@_not-an-address', '2025-11-22 07:30:00|Y'],
    ['b', '_stcr@_weird@example.com', '2025-11-23 07:30:00|Q'],
    ['gone', '_stcr@_gone@example.com', '2025-10-01 07:30:00|Y'],
];
