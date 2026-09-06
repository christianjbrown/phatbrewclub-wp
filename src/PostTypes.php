<?php

declare(strict_types=1);

namespace ChristianBrown\PhatWp;

/**
 * The content model, mirroring the Payload collections one for one.
 *
 * Seven custom types; pages, posts and media stay WordPress natives because
 * Payload's `pages`, `posts` and `media` collections are the same idea and the
 * native ones bring the editor, revisions and the media library for free.
 *
 * `show_in_rest` is false throughout. The front end reads a purpose-built
 * `phat/v1` namespace that returns the exact shapes it already expects, so
 * exposing the raw `wp/v2` shape as well would be a second, subtly different
 * public contract for the same data — and the one nobody is testing is the one
 * that breaks. Gutenberg is not needed on these types either; their editing is
 * entirely Carbon Fields.
 */
final class PostTypes
{
    /**
     * The slugs, alphabetical because the style fixer sorts them.
     *
     * BEER, EVENT, FUNCTION_PACKAGE, MERCH and VENUE all appear in a public URL,
     * so their `post_name` has to be unique and must not change once a link
     * exists. MENU and TAP_LIST are never linked to directly — they hang off a
     * venue — so their slugs are free to change.
     */
    public const BEER = 'phat_beer';
    public const EVENT = 'phat_event';
    public const FUNCTION_PACKAGE = 'phat_function';
    public const MENU = 'phat_menu';
    public const MERCH = 'phat_merch';
    public const TAP_LIST = 'phat_tap_list';
    public const VENUE = 'phat_venue';

    public static function register(): void
    {
        self::add(self::VENUE, 'Venue', 'Venues', 'dashicons-store');
        self::add(self::BEER, 'Beer', 'Beers', 'dashicons-beer');
        self::add(self::EVENT, 'Event', 'Events', 'dashicons-calendar-alt');
        self::add(self::MERCH, 'Merch item', 'Merch', 'dashicons-cart');
        self::add(self::FUNCTION_PACKAGE, 'Function space', 'Function spaces', 'dashicons-groups');

        // A tap list belongs to exactly one venue and is titled after it by a
        // hook, so there is nothing useful to type into the title box.
        self::add(self::TAP_LIST, 'Tap list', 'Tap lists', 'dashicons-list-view', ['title']);

        // Menus mirror me&u, which is the source of truth. They are registered
        // so the data has somewhere to live and so an editor can see what was
        // synced, but everything on them is read-only in the admin.
        self::add(self::MENU, 'Menu', 'Menus', 'dashicons-food', ['title']);
    }

    /**
     * Register one type.
     *
     * @param string       $slug     the post type key
     * @param string       $singular the label for one of them
     * @param string       $plural   the label for several
     * @param string       $icon     a dashicons class for the admin menu
     * @param list<string> $supports what the editor screen offers
     */
    private static function add(
        string $slug,
        string $singular,
        string $plural,
        string $icon,
        array $supports = ['title', 'revisions'],
    ): void {
        register_post_type($slug, [
            'labels' => [
                'name' => $plural,
                'singular_name' => $singular,
                'add_new_item' => sprintf('Add %s', mb_strtolower($singular)),
                'edit_item' => sprintf('Edit %s', mb_strtolower($singular)),
                'search_items' => sprintf('Search %s', mb_strtolower($plural)),
                'not_found' => sprintf('No %s yet', mb_strtolower($plural)),
            ],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => true,
            'show_in_rest' => false,
            'menu_icon' => $icon,
            'supports' => $supports,
            'has_archive' => false,
            'rewrite' => false,
            'capability_type' => 'post',
            'map_meta_cap' => true,
        ]);
    }
}
