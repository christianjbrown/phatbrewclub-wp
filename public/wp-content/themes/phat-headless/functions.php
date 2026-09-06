<?php

/**
 * Strip the front end down to nothing.
 *
 * The public site is a separate service, so everything WordPress would emit for
 * a visitor here is dead weight at best and a second, unmanaged copy of the
 * brewery's content at worst.
 */

declare(strict_types=1);

add_action('init', static function (): void {
    remove_action('wp_head', 'wp_generator');
    remove_action('wp_head', 'rsd_link');
    remove_action('wp_head', 'wlwmanifest_link');
    remove_action('wp_head', 'feed_links', 2);
    remove_action('wp_head', 'feed_links_extra', 3);
});

/** XML-RPC is a login surface with no use in a headless install. */
add_filter('xmlrpc_enabled', '__return_false');
