<?php

declare(strict_types=1);

namespace ChristianBrown\PhatWp;

use WP_Post;
use WP_Query;
use WP_REST_Request;
use WP_REST_Response;

/**
 * The read API the website talks to.
 *
 * A purpose-built namespace rather than core's wp/v2, because the front end
 * already has a contract — apps/web/src/lib/types.ts — and the job here is to
 * satisfy it, not to publish a second, subtly different public shape for the
 * same data. The one nobody is testing is the one that breaks.
 *
 * Everything is public and read-only. Published posts only, which also means
 * drafts cannot leak into the comparison site.
 */
final class Rest
{
    /**
     * Matches the front end's own limit, so neither side truncates first.
     */
    private const MAX = 100;
    private const NS = 'phat/v1';

    public static function register(): void
    {
        add_action('rest_api_init', static function (): void {
            self::collection('venues', PostTypes::VENUE, [Shape::class, 'venue'], 'title', 'ASC');
            self::collection('beers', PostTypes::BEER, [Shape::class, 'beer'], 'title', 'ASC');
            self::collection('events', PostTypes::EVENT, [Shape::class, 'event'], 'phat_starts_at', 'ASC');
            self::collection('posts', 'post', [Shape::class, 'post'], 'date', 'DESC');
            self::collection('merch', PostTypes::MERCH, [Shape::class, 'merch'], 'title', 'ASC');

            self::route('/pages/(?P<slug>[a-z0-9-]+)', static fn (WP_REST_Request $r): WP_REST_Response => self::one(self::bySlug('page', Val::text($r['slug'])), [Blocks::class, 'page']));

            self::route('/tap-list', static fn (WP_REST_Request $r): WP_REST_Response => self::one(self::byVenue(PostTypes::TAP_LIST, $r), [Shape::class, 'tapList']));

            self::route('/menus', static fn (WP_REST_Request $r): WP_REST_Response => self::many(self::allByVenue(PostTypes::MENU, $r), [Shape::class, 'menu']));

            self::route('/function-packages', static fn (WP_REST_Request $r): WP_REST_Response => self::many(self::allByVenue(PostTypes::FUNCTION_PACKAGE, $r), [Shape::class, 'functionPackage']));

            self::route('/settings', static fn (): WP_REST_Response => new WP_REST_Response(Settings::shape()));

            self::route('/search', [self::class, 'search']);
        });
    }

    /**
     * Title-only search across the five types the website searches.
     *
     * `search_columns` and `sentence` are both deliberate. Core also matches the
     * excerpt and the body, and splits the query into independent words; Payload
     * does a single contains-match on the title. Without both, this side returns
     * hits the Payload side does not and the two result pages differ.
     */
    public static function search(WP_REST_Request $request): WP_REST_Response
    {
        $term = Val::text($request['q'] ?? null);

        if (mb_strlen($term) < 2) {
            return new WP_REST_Response([]);
        }

        $types = [
            [PostTypes::BEER, 'Beer', '/beers/'],
            [PostTypes::EVENT, "What's on", '/whats-on/'],
            ['post', 'News', '/news/'],
            ['page', 'Page', '/'],
            [PostTypes::MERCH, 'Shop', '/shop'],
        ];

        $hits = [];

        foreach ($types as [$postType, $kind, $prefix]) {
            $found = self::posts(new WP_Query([
                'post_type' => $postType,
                'post_status' => 'publish',
                'posts_per_page' => 20,
                's' => $term,
                'search_columns' => ['post_title'],
                'sentence' => true,
            ]));

            foreach ($found as $post) {
                $hits[] = [
                    'title' => $post->post_title,
                    'href' => PostTypes::MERCH === $postType ? $prefix : $prefix.$post->post_name,
                    'kind' => $kind,
                    'detail' => PostTypes::BEER === $postType
                        ? Val::str(carbon_get_post_meta($post->ID, 'phat_style'))
                        : null,
                ];
            }
        }

        return new WP_REST_Response($hits);
    }

    /**
     * Everything of a type that belongs to one venue.
     *
     * Filtered in PHP rather than with a meta_query, which is not a shortcut.
     * Carbon Fields does not store an association under the plain key it is
     * declared with: it uses its own hierarchical scheme, so a meta_query on
     * `_phat_venue` matches nothing however the value is written. That looked
     * exactly like missing data — the tap lists and menus were empty on a site
     * whose content was perfectly fine — and cost a fix to the value format
     * that was never the problem.
     *
     * There are three menus and two tap lists, so reading them all and asking
     * Carbon is cheaper than being clever.
     *
     * @return list<WP_Post>
     */
    private static function allByVenue(string $postType, WP_REST_Request $request): array
    {
        $venue = Val::int($request['venue'] ?? null);

        if ($venue <= 0) {
            return [];
        }

        $all = self::posts(new WP_Query([
            'post_type' => $postType,
            'post_status' => 'publish',
            'posts_per_page' => self::MAX,
        ]));

        $out = [];

        foreach ($all as $post) {
            if (in_array($venue, Shape::related(carbon_get_post_meta($post->ID, 'phat_venue')), true)) {
                $out[] = $post;
            }
        }

        return $out;
    }

    private static function bySlug(string $postType, string $slug): ?WP_Post
    {
        return self::posts(new WP_Query([
            'post_type' => $postType,
            'post_status' => 'publish',
            'name' => $slug,
            'posts_per_page' => 1,
        ]))[0] ?? null;
    }

    private static function byVenue(string $postType, WP_REST_Request $request): ?WP_Post
    {
        return self::allByVenue($postType, $request)[0] ?? null;
    }

    /**
     * A list endpoint and a by-slug endpoint, which every content type needs.
     *
     * @param callable(WP_Post): array<string, mixed> $shaper
     */
    private static function collection(
        string $path,
        string $postType,
        callable $shaper,
        string $orderBy,
        string $order,
    ): void {
        self::route("/{$path}", static function (WP_REST_Request $request) use ($postType, $shaper, $orderBy, $order): WP_REST_Response {
            $args = [
                'post_type' => $postType,
                'post_status' => 'publish',
                'posts_per_page' => min(Val::int($request['limit'] ?? null, self::MAX), self::MAX),
                'order' => $order,
            ];

            // A meta key as the sort field needs meta_value, and dates sort
            // lexicographically only because they are stored as ISO strings.
            if (str_starts_with($orderBy, 'phat_')) {
                $args['meta_key'] = '_'.$orderBy;
                $args['orderby'] = 'meta_value';
            } else {
                $args['orderby'] = $orderBy;
            }

            $category = Val::str($request['category'] ?? null);

            if (null !== $category) {
                $args['meta_query'] = [['key' => '_phat_category', 'value' => $category]];
            }

            return self::many(self::posts(new WP_Query($args)), $shaper);
        });

        self::route("/{$path}/(?P<slug>[a-z0-9-]+)", static fn (WP_REST_Request $r): WP_REST_Response => self::one(self::bySlug($postType, Val::text($r['slug'])), $shaper));
    }

    /**
     * @param list<WP_Post>                           $posts
     * @param callable(WP_Post): array<string, mixed> $shaper
     */
    private static function many(array $posts, callable $shaper): WP_REST_Response
    {
        return new WP_REST_Response(array_map($shaper, $posts));
    }

    /**
     * @param callable(WP_Post): array<string, mixed> $shaper
     */
    private static function one(?WP_Post $post, callable $shaper): WP_REST_Response
    {
        return null === $post
        ? new WP_REST_Response(null, 404)
        : new WP_REST_Response($shaper($post));
    }

    /**
     * WP_Query::posts is typed as a plain array; the callers want a list.
     *
     * @return list<WP_Post>
     */
    private static function posts(WP_Query $query): array
    {
        $out = [];

        foreach ($query->posts as $post) {
            if ($post instanceof WP_Post) {
                $out[] = $post;
            }
        }

        return $out;
    }

    private static function route(string $path, callable $handler): void
    {
        register_rest_route(self::NS, $path, [
            'methods' => 'GET',
            'callback' => $handler,
            // Public on purpose: this is the same content the website serves to
            // anyone, and requiring a credential would mean holding one in the
            // front end for no gain.
            'permission_callback' => '__return_true',
        ]);
    }
}
