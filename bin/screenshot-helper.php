<?php
/**
 * Local dev only, never shipped: bin/dev-site.sh copies it into .wp/ as a
 * must-use plugin for bin/screenshots.sh. ?shot_as=<login> renders a page as
 * that user, ?shot_login=<login>&to=<path> signs in for real (for the block
 * editor), ?shot_scheme=dark forces a colour scheme, ?shot_email=<template>
 * shows a sample email, ?shot_manage=<email> opens that address's manage page.
 */
add_filter('determine_current_user', function ($id) {
    if (isset($_GET['shot_as'])) {
        $user = get_user_by('login', sanitize_user(wp_unslash($_GET['shot_as'])));
        return $user ? $user->ID : $id;
    }
    return $id;
}, 99);
add_filter('ruvelo_comment_subscriptions_settings', function ($s) {
    if (isset($_GET['shot_scheme'])) {
        $s['color_scheme'] = sanitize_key($_GET['shot_scheme']);
    }
    return $s;
});
add_filter('show_admin_bar', function ($show) { return isset($_GET['shot_bar']) ? $show : false; });
add_action('wp_head', function () {
    if (isset($_GET['shot_scheme']) && $_GET['shot_scheme'] === 'dark') {
        echo '<style>body,.wp-site-blocks{background:#11111c!important;color:#f1f1f8!important}.wp-site-blocks a:not(.ruvelo-cs *){color:#f1f1f8!important}</style>';
    }
});
add_action('plugins_loaded', function () {
    if (! isset($_GET['shot_as'])) {
        return;
    }
    $user = get_user_by('login', sanitize_user(wp_unslash($_GET['shot_as'])));
    if ($user) {
        $exp = time() + HOUR_IN_SECONDS;
        $_COOKIE[AUTH_COOKIE] = wp_generate_auth_cookie($user->ID, $exp, 'auth');
        $_COOKIE[LOGGED_IN_COOKIE] = wp_generate_auth_cookie($user->ID, $exp, 'logged_in');
    }
}, 0);
add_action('admin_init', function () {
    if (isset($_GET['shot_as'])) {
        remove_action('admin_enqueue_scripts', 'wp_auth_check_load');
        add_action('admin_enqueue_scripts', function () { wp_deregister_script('heartbeat'); wp_register_script('heartbeat', false); }, 0);
    }
});
add_action('init', function () {
    if (isset($_GET['shot_login'], $_GET['to'])) {
        $user = get_user_by('login', sanitize_user(wp_unslash($_GET['shot_login'])));
        if ($user) {
            wp_set_auth_cookie($user->ID, false);
            wp_safe_redirect(home_url(wp_unslash($_GET['to'])));
            exit;
        }
    }
    if (isset($_GET['shot_email'])) {
        $email = \Ruvelo\WP\CommentSubscriptions\Mailer::sample(sanitize_key($_GET['shot_email']), 'kenji.mori@halyard.test');
        echo $email['html'];
        exit;
    }
    if (isset($_GET['shot_manage'])) {
        $to = \Ruvelo\WP\CommentSubscriptions\Urls::manage(sanitize_email(wp_unslash($_GET['shot_manage'])));
        $extra = array_diff_key($_GET, ['shot_manage' => 1]);
        wp_safe_redirect(add_query_arg(array_map('rawurlencode', array_map('wp_unslash', $extra)), $to));
        exit;
    }
}, 20);
add_action('wp_footer', function () {
    if (isset($_GET['shot_scroll'])) {
        printf('<script>addEventListener("load",function(){var e=document.getElementById(%s);if(e){scrollTo(0,e.getBoundingClientRect().top+scrollY-%d)}})</script>', wp_json_encode(sanitize_key($_GET['shot_scroll'])), isset($_GET['shot_offset']) ? (int) $_GET['shot_offset'] : 40);
    }
}, 99);
