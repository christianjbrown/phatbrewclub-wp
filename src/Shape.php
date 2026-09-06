<?php

declare(strict_types=1);

namespace ChristianBrown\PhatWp;

use WP_Post;

/**
 * Turn WordPress posts into the shapes the website already expects.
 *
 * The contract is apps/web/src/lib/types.ts in the phatbrewclub.com repository,
 * and it is not negotiable: the same front end renders both this and the Payload
 * version, so anything emitted here has to match field for field or the two
 * sites differ and the comparison is worthless.
 *
 * The shaping is done here in PHP rather than in the TypeScript adapter for one
 * practical reason. Payload expands relationships server-side with `depth=2`, so
 * a venue page is one request. WordPress's core REST cannot, so doing it in the
 * adapter would mean one request for the venue, one per image, one for the tap
 * list and one per beer on tap — a dozen round trips where Payload makes one.
 * Putting the joins next to the data costs one HTTP call instead of N, and means
 * swapping the field plugin later never touches the website.
 */
final class Shape
{
    /**
     * @return array<string, mixed>
     */
    public static function beer(WP_Post $post, bool $withVenues = true): array
    {
        $id = $post->ID;

        return [
            'id' => $id,
            'name' => $post->post_title,
            'slug' => $post->post_name,
            'style' => Val::text(carbon_get_post_meta($id, 'phat_style')),
            'abv' => Val::num(carbon_get_post_meta($id, 'phat_abv')) ?? 0,
            'ibu' => Val::num(carbon_get_post_meta($id, 'phat_ibu')),
            'videoId' => Val::str(carbon_get_post_meta($id, 'phat_video_id')),
            'category' => Val::text(carbon_get_post_meta($id, 'phat_category'), 'core'),
            'description' => Val::str(carbon_get_post_meta($id, 'phat_description')),
            'tastingNotes' => self::html(carbon_get_post_meta($id, 'phat_tasting_notes')),
            'canArtwork' => self::media(carbon_get_post_meta($id, 'phat_can_artwork')),
            'untappdUrl' => Val::str(carbon_get_post_meta($id, 'phat_untappd_url')),
            'gallery' => self::mediaList(carbon_get_post_meta($id, 'phat_gallery')),
            'allergens' => Val::strings(carbon_get_post_meta($id, 'phat_allergens')),
            'price' => Val::num(carbon_get_post_meta($id, 'phat_price')),
            'packSize' => Val::str(carbon_get_post_meta($id, 'phat_pack_size')),
            'shopUrl' => Val::str(carbon_get_post_meta($id, 'phat_shop_url')),
            // Guarded, because a tap list expands its beers and each beer would
            // otherwise expand its venues, and a venue is a large object to
            // carry twenty times over for a field nothing on that page reads.
            'availableAt' => $withVenues
                ? self::expand(self::related(carbon_get_post_meta($id, 'phat_available_at')), 'venue')
                : [],
            'ingredients' => self::rows(
                carbon_get_post_meta($id, 'phat_ingredients'),
                ['producer', 'contribution'],
            ),
            'seo' => self::seo($id),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function event(WP_Post $post): array
    {
        $id = $post->ID;

        return [
            'id' => $id,
            'title' => $post->post_title,
            'slug' => $post->post_name,
            'startsAt' => self::iso(carbon_get_post_meta($id, 'phat_starts_at')),
            'endsAt' => self::iso(carbon_get_post_meta($id, 'phat_ends_at')),
            'recurrence' => Val::text(carbon_get_post_meta($id, 'phat_recurrence'), 'once'),
            'category' => Val::str(carbon_get_post_meta($id, 'phat_category')),
            'venues' => self::expand(self::related(carbon_get_post_meta($id, 'phat_venues')), 'venue'),
            'heroImage' => self::media(carbon_get_post_meta($id, 'phat_hero_image')),
            'body' => self::html(carbon_get_post_meta($id, 'phat_body')),
            'isFree' => Val::bool(carbon_get_post_meta($id, 'phat_is_free')),
            'price' => Val::str(carbon_get_post_meta($id, 'phat_price')),
            'priceNote' => Val::str(carbon_get_post_meta($id, 'phat_price_note')),
            'bookingUrl' => Val::str(carbon_get_post_meta($id, 'phat_booking_url')),
            'seo' => self::seo($id),
        ];
    }

    /**
     * Expand a list of post ids into shaped objects.
     *
     * @param list<int> $ids
     *
     * @return list<array<string, mixed>>
     */
    public static function expand(array $ids, string $kind): array
    {
        $out = [];

        foreach ($ids as $id) {
            $post = get_post($id);

            if (!$post instanceof WP_Post || 'publish' !== $post->post_status) {
                continue;
            }

            $out[] = 'venue' === $kind ? self::venue($post) : self::beer($post, false);
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public static function functionPackage(WP_Post $post): array
    {
        $id = $post->ID;

        return [
            'id' => $id,
            'name' => $post->post_title,
            'slug' => $post->post_name,
            'capacity' => Val::num(carbon_get_post_meta($id, 'phat_capacity')),
            'seating' => Val::str(carbon_get_post_meta($id, 'phat_seating')),
            'priceGuide' => Val::str(carbon_get_post_meta($id, 'phat_price_guide')),
            'image' => self::media(carbon_get_post_meta($id, 'phat_image')),
            'description' => self::html(carbon_get_post_meta($id, 'phat_description')),
            'inclusions' => self::rows(carbon_get_post_meta($id, 'phat_inclusions'), ['item']),
            'brochure' => self::media(carbon_get_post_meta($id, 'phat_brochure')),
        ];
    }

    /**
     * Rich text, as sanitised HTML.
     *
     * Payload stores a Lexical node tree; WordPress stores HTML, and the front
     * end's RichText component takes either. The allow-list is exactly the tags
     * that component can render — no class, no style, no id, no img — which is a
     * parity measure as much as a security one: the site's stylesheet knows
     * nothing about Gutenberg's wp-block-* classes, so letting them through
     * would produce markup that differs from the Payload version.
     */
    public static function html(mixed $value): ?string
    {
        if (!is_string($value) || '' === mb_trim($value)) {
            return null;
        }

        $rendered = apply_filters('the_content', $value);

        return wp_kses(is_string($rendered) ? $rendered : $value, [
            'p' => [],
            'h2' => [],
            'h3' => [],
            'h4' => [],
            'ul' => [],
            'ol' => [],
            'li' => [],
            'blockquote' => [],
            'strong' => [],
            'em' => [],
            'u' => [],
            'br' => [],
            'a' => ['href' => true, 'title' => true, 'rel' => true, 'target' => true],
        ]);
    }

    /**
     * An attachment, with the whole size ladder.
     *
     * `alt` is a required string in the contract while WordPress's is optional,
     * so the fallback belongs here rather than in the front end, which would
     * otherwise render the word "undefined" into a page.
     *
     * @return null|array<string, mixed>
     */
    public static function media(mixed $id): ?array
    {
        $id = is_array($id) ? ($id[0] ?? null) : $id;

        if (!is_numeric($id) || (int) $id <= 0) {
            return null;
        }

        $id = (int) $id;
        $url = wp_get_attachment_url($id);

        if (false === $url) {
            return null;
        }

        $full = wp_get_attachment_image_src($id, 'full');
        $sizes = [];

        foreach (array_keys(Media::sizes()) as $name) {
            $src = wp_get_attachment_image_src($id, $name);

            // A rung is absent when the original was too small for it —
            // WordPress does not upscale, and neither does Payload. The front
            // end's mediaSize walks down the ladder for exactly this case.
            if (!is_array($src) || true !== $src[3]) {
                continue;
            }

            $sizes[$name] = ['url' => $src[0], 'width' => $src[1], 'height' => $src[2]];
        }

        return [
            'id' => $id,
            'alt' => Val::text(get_post_meta($id, '_wp_attachment_image_alt', true), get_the_title($id)),
            'url' => $url,
            'width' => is_array($full) ? $full[1] : null,
            'height' => is_array($full) ? $full[2] : null,
            'sizes' => $sizes,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function mediaList(mixed $ids): array
    {
        if (!is_array($ids)) {
            return [];
        }

        $out = [];

        foreach ($ids as $id) {
            $shaped = self::media($id);

            if (null !== $shaped) {
                $out[] = $shaped;
            }
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public static function menu(WP_Post $post): array
    {
        $id = $post->ID;
        $sections = [];

        foreach (Val::rows(carbon_get_post_meta($id, 'phat_sections')) as $section) {
            $items = [];

            foreach (Val::rows($section['items'] ?? null) as $item) {
                $items[] = [
                    'name' => Val::str($item['name'] ?? null),
                    'price' => Val::str($item['price'] ?? null),
                    'dietary' => Val::str($item['dietary'] ?? null),
                    'description' => Val::str($item['description'] ?? null),
                    'image' => self::media($item['image'] ?? null),
                    'imageCredit' => Val::str($item['image_credit'] ?? null),
                ];
            }

            $sections[] = ['name' => Val::str($section['name'] ?? null), 'items' => $items];
        }

        $venueIds = self::related(carbon_get_post_meta($id, 'phat_venue'));

        return [
            'id' => $id,
            'name' => $post->post_title,
            'venue' => $venueIds[0] ?? null,
            // The menu page prints "Synced from me&u" and this timestamp, so a
            // hardcoded null was a line of visible text the other site had.
            'syncedAt' => Val::str(carbon_get_post_meta($id, 'phat_synced_at')),
            'sections' => $sections,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function merch(WP_Post $post): array
    {
        $id = $post->ID;

        return [
            'id' => $id,
            'title' => $post->post_title,
            'slug' => $post->post_name,
            'price' => Val::num(carbon_get_post_meta($id, 'phat_price')),
            'soldOut' => Val::bool(carbon_get_post_meta($id, 'phat_sold_out')),
            'shopUrl' => Val::str(carbon_get_post_meta($id, 'phat_shop_url')),
            'description' => Val::str(carbon_get_post_meta($id, 'phat_description')),
            'images' => self::mediaList(carbon_get_post_meta($id, 'phat_images')),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function post(WP_Post $post): array
    {
        return [
            'id' => $post->ID,
            'title' => $post->post_title,
            'slug' => $post->post_name,
            'publishedAt' => get_post_time('c', true, $post) ?: null,
            'excerpt' => Val::str($post->post_excerpt),
            'heroImage' => self::media(get_post_thumbnail_id($post)),
            'body' => self::html($post->post_content),
            'seo' => self::seo($post->ID),
        ];
    }

    /**
     * The post ids behind a Carbon association field.
     *
     * Carbon stores these as rows of ['id' => .., 'type' => 'post', ..], which
     * is never what a caller wants.
     *
     * @return list<int>
     */
    public static function related(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $ids = [];

        foreach ($value as $row) {
            if (is_array($row) && isset($row['id']) && is_numeric($row['id'])) {
                $ids[] = (int) $row['id'];
            }
        }

        return $ids;
    }

    /**
     * @return array<string, mixed>
     */
    public static function seo(int $id): array
    {
        return [
            'title' => Val::str(carbon_get_post_meta($id, 'phat_seo_title')),
            'description' => Val::str(carbon_get_post_meta($id, 'phat_seo_description')),
            'image' => self::media(carbon_get_post_meta($id, 'phat_seo_image')),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function tapList(WP_Post $post): array
    {
        $id = $post->ID;
        $venueIds = self::related(carbon_get_post_meta($id, 'phat_venue'));
        $venues = self::expand($venueIds, 'venue');
        $taps = [];

        foreach (Val::rows(carbon_get_post_meta($id, 'phat_taps')) as $row) {
            $beerIds = self::related($row['beer'] ?? null);
            $beer = [] === $beerIds ? null : get_post($beerIds[0]);

            $taps[] = [
                'tapNumber' => Val::int($row['tap_number'] ?? null),
                // Without the venue list, which would otherwise be carried
                // twenty times over on one page.
                'beer' => $beer instanceof WP_Post ? self::beer($beer, false) : null,
                'guestName' => Val::str($row['guest_name'] ?? null),
                'guestStyle' => Val::str($row['guest_style'] ?? null),
                'price' => Val::str($row['price'] ?? null),
                'kegBlown' => Val::bool($row['keg_blown'] ?? null),
            ];
        }

        return [
            'id' => $id,
            'title' => $post->post_title,
            'venue' => $venues[0] ?? null,
            'taps' => $taps,
            // Carried across from the sync rather than hardcoded: the venue
            // page prints "Synced from me&u" and a timestamp under the tap
            // list, and with these fixed the two sites disagreed on a line of
            // visible text.
            'source' => Val::text(carbon_get_post_meta($id, 'phat_source'), 'manual'),
            'syncedAt' => Val::str(carbon_get_post_meta($id, 'phat_synced_at')),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function venue(WP_Post $post): array
    {
        $id = $post->ID;

        return [
            'id' => $id,
            'name' => $post->post_title,
            'shortName' => Val::str(carbon_get_post_meta($id, 'phat_short_name')) ?? $post->post_title,
            'slug' => $post->post_name,
            'address' => [
                'street' => Val::text(carbon_get_post_meta($id, 'phat_street')),
                'suburb' => Val::text(carbon_get_post_meta($id, 'phat_suburb')),
                'state' => Val::text(carbon_get_post_meta($id, 'phat_state'), 'WA'),
                'postcode' => Val::text(carbon_get_post_meta($id, 'phat_postcode')),
                'latitude' => Val::num(carbon_get_post_meta($id, 'phat_latitude')),
                'longitude' => Val::num(carbon_get_post_meta($id, 'phat_longitude')),
            ],
            'transportNote' => Val::str(carbon_get_post_meta($id, 'phat_transport_note')),
            'phone' => Val::str(carbon_get_post_meta($id, 'phat_phone')),
            'email' => Val::str(carbon_get_post_meta($id, 'phat_email')),
            'capacity' => Val::num(carbon_get_post_meta($id, 'phat_capacity')),
            'tapCount' => Val::num(carbon_get_post_meta($id, 'phat_tap_count')),
            'amenities' => Val::strings(carbon_get_post_meta($id, 'phat_amenities')),
            'bookingUrl' => Val::str(carbon_get_post_meta($id, 'phat_booking_url')),
            'menuUrl' => Val::str(carbon_get_post_meta($id, 'phat_menu_url')),
            'meanduSlug' => Val::str(carbon_get_post_meta($id, 'phat_meandu_slug')),
            'heroImage' => self::media(carbon_get_post_meta($id, 'phat_hero_image')),
            'hoursLabel' => Val::str(carbon_get_post_meta($id, 'phat_hours_label')),
            'publicHolidayNote' => Val::str(carbon_get_post_meta($id, 'phat_public_holiday_note')),
            'faqs' => self::rows(carbon_get_post_meta($id, 'phat_faqs'), ['question', 'answer']),
            'openingHours' => self::hours(carbon_get_post_meta($id, 'phat_opening_hours')),
            'hoursOverrides' => self::rows(
                carbon_get_post_meta($id, 'phat_hours_overrides'),
                ['date', 'label', 'opens', 'closes', 'closed'],
            ),
            'functionsPack' => self::media(carbon_get_post_meta($id, 'phat_functions_pack')),
            'mapsQuery' => Val::str(carbon_get_post_meta($id, 'phat_maps_query')),
            'instagram' => Val::str(carbon_get_post_meta($id, 'phat_instagram')),
            'intro' => Val::str(carbon_get_post_meta($id, 'phat_intro')),
            'seo' => self::seo($id),
        ];
    }

    /**
     * Opening hours, with the day key the front end switches on.
     *
     * @return list<array<string, mixed>>
     */
    private static function hours(mixed $value): array
    {
        $out = [];

        foreach (Val::rows($value) as $row) {
            $out[] = [
                'day' => Val::text($row['day'] ?? null),
                'opens' => Val::str($row['opens'] ?? null),
                'closes' => Val::str($row['closes'] ?? null),
                'closed' => (bool) ($row['closed'] ?? false),
            ];
        }

        return $out;
    }

    /**
     * A date as ISO 8601.
     *
     * Carbon stores what its picker produced, in site time. The front end
     * formats everything in Australia/Perth and schema.org needs a real offset,
     * so an ambiguous local string would put every event an unknown number of
     * hours out.
     */
    private static function iso(mixed $value): ?string
    {
        $raw = Val::str($value);

        if (null === $raw) {
            return null;
        }

        $time = strtotime($raw);

        return false === $time ? null : gmdate('c', $time);
    }

    /**
     * Flatten a Carbon complex field to the named keys, dropping Carbon's own.
     *
     * @param list<string> $keys
     *
     * @return list<array<string, mixed>>
     */
    private static function rows(mixed $value, array $keys): array
    {
        $out = [];

        foreach (Val::rows($value) as $row) {
            $shaped = [];

            foreach ($keys as $key) {
                // camelCase in the contract, snake_case in the field names.
                $camel = lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $key))));
                $shaped[$camel] = 'closed' === $key ? Val::bool($row[$key] ?? null) : Val::str($row[$key] ?? null);
            }

            $out[] = $shaped;
        }

        return $out;
    }
}
