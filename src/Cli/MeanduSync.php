<?php

declare(strict_types=1);

namespace ChristianBrown\PhatWp\Cli;

use ChristianBrown\PhatWp\PostTypes;
use ChristianBrown\PhatWp\Val;
use WP_CLI;
use WP_Error;
use WP_Post;
use WP_Query;

/**
 * Mirror the me&u menus and tap lists, as the Payload side does.
 *
 * A port of apps/cms/src/meandu/sync.ts, deliberately line for line where it
 * matters. The parts that look like defensive clutter are the parts worth
 * keeping: this reads an undocumented internal API that can change without
 * notice, and the failure mode to avoid is not an error — it is quietly
 * replacing a live menu with an empty one.
 *
 * **It ships switched off.** MEANDU_SYNC_ENABLED must literally be "true".
 * docs/known-issues.md in the Payload repository records why: publicly readable
 * is not the same as licensed to republish, and the brewery should have a
 * supported feed or a written agreement before this runs against anyone's
 * production site. Porting it was asked for; enabling it is a separate
 * decision, and not one this file makes.
 */
final class MeanduSync
{
    private const MENU_QUERY = 'query menu($venueSlug: String!, $orderingType: OrderingType!, $categorySlug: String!) {
      guestMenuCategory(venueSlug: $venueSlug, categorySlug: $categorySlug, orderingType: $orderingType) {
        id name slug
        menuSections {
          id name slug isUnavailable
          menuItems {
            id slug name descriptionPlain dietaryTags isAvailable isPopular imageCredit
            image { id originalImageUrl }
            priceData { displayPrice priceInCents }
          }
        }
      }
    }';
    private const UA = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 '
    .'(KHTML, like Gecko) Chrome/140.0 Safari/537.36';
    private const VENUE_QUERY = 'query venue($venueSlug: String!) {
      guestVenue(slug: $venueSlug) { id name slug isClosed isLive timezone }
    }';
    private string $gateway = '';
    private int $skipped = 0;
    private int $updated = 0;

    /**
     * @param list<string>          $args
     * @param array<string, string> $options
     */
    public function __invoke(array $args, array $options): void
    {
        if ('true' !== getenv('MEANDU_SYNC_ENABLED')) {
            WP_CLI::log('meandu-sync: disabled (MEANDU_SYNC_ENABLED is not "true") — exiting without writing');

            return;
        }

        $this->gateway = Val::text(getenv('MEANDU_GATEWAY'), 'https://ap1-guest-gateway.meandu.app/graphql');
        $categories = array_map('trim', explode(',', Val::text(getenv('MEANDU_CATEGORIES'), 'beers-ciders,food')));

        foreach (self::venues() as $venue) {
            $slug = Val::str(carbon_get_post_meta($venue->ID, 'phat_meandu_slug'));
            $shortName = Val::text(carbon_get_post_meta($venue->ID, 'phat_short_name'), $venue->post_title);

            if (null === $slug) {
                WP_CLI::log(sprintf('  %s: no me&u slug set, skipping', $shortName));
                ++$this->skipped;

                continue;
            }

            try {
                $upstream = $this->query(self::VENUE_QUERY, ['venueSlug' => $slug], 'venue');

                if (!is_array($upstream['guestVenue'] ?? null)) {
                    throw new MeanduException(sprintf('venue "%s" not found upstream', $slug));
                }

                foreach ($categories as $category) {
                    $this->category($venue, $shortName, $slug, $category);
                }
            } catch (MeanduException $e) {
                WP_CLI::warning(sprintf(
                    '  %s: %s — previous snapshot left in place',
                    $shortName,
                    $e->getMessage(),
                ));
                ++$this->skipped;
            }
        }

        WP_CLI::success(sprintf('meandu-sync: %d menus updated, %d skipped', $this->updated, $this->skipped));
    }

    /**
     * Refuse to write anything that does not look like a menu.
     *
     * The contract is undocumented, so a shape change has to fail loudly and
     * leave the previous snapshot in place. A stale menu is recoverable; a blank
     * one loses a customer mid-decision.
     *
     * @return array<string, mixed>
     */
    private static function assertUsable(mixed $cat, string $where): array
    {
        if (!is_array($cat)) {
            throw new MeanduException($where.': no category returned');
        }

        if (!is_array($cat['menuSections'] ?? null)) {
            throw new MeanduException($where.': menuSections is not an array');
        }

        $items = [];

        foreach (Val::rows($cat['menuSections']) as $section) {
            foreach (Val::rows($section['menuItems'] ?? null) as $item) {
                $items[] = $item;
            }
        }

        if ([] === $items) {
            throw new MeanduException($where.': zero items, refusing to overwrite');
        }

        foreach ($items as $item) {
            if (null === Val::str($item['name'] ?? null)) {
                throw new MeanduException($where.': an item has no name');
            }
        }

        /**
 * @var array<string, mixed> $shaped
*/
        $shaped = $cat;

        return $shaped;
    }

    /**
     * @return array<string, int>
     */
    private static function beersByName(): array
    {
        $out = [];

        foreach (self::query_posts(PostTypes::BEER) as $beer) {
            $out[self::normalise($beer->post_title)] = $beer->ID;
        }

        return $out;
    }

    /**
     * One category for one venue.
     *
     * Handled independently of the others on purpose. The venues do not carry
     * the same categories — Hillarys publishes food but no drinks — and one
     * absent category must not cost a venue the menus it does have.
     */
    private function category(WP_Post $venue, string $shortName, string $slug, string $category): void
    {
        try {
            $data = $this->query(
                self::MENU_QUERY,
                ['venueSlug' => $slug, 'orderingType' => 'MENU', 'categorySlug' => $category],
                'menu',
            );

            $cat = self::assertUsable($data['guestMenuCategory'] ?? null, $shortName.'/'.$category);
            $sections = self::sections($cat, $shortName);

            self::upsertMenu($venue, $shortName, $cat, $sections);

            $items = array_sum(array_map(static fn (array $s): int => count($s['items']), $sections));
            WP_CLI::log(sprintf('  %s/%s: %d sections, %d items', $shortName, $category, count($sections), $items));
            ++$this->updated;

            self::tapList($venue, $shortName, $cat);
        } catch (MeanduException $e) {
            // "Not Found" means the venue simply does not publish this category,
            // which is information rather than a failure.
            $message = $e->getMessage();
            $absent = 1 === preg_match('/not found/i', $message);

            WP_CLI::log(sprintf(
                '  %s/%s: %s',
                $shortName,
                $category,
                $absent ? 'not published by this venue' : $message,
            ));
            ++$this->skipped;
        }
    }

    private static function findByMeta(string $postType, string $key, string $value): ?int
    {
        $found = (new WP_Query([
            'post_type' => $postType,
            'post_status' => 'any',
            'posts_per_page' => 1,
            'fields' => 'ids',
            'meta_query' => [['key' => $key, 'value' => $value]],
        ]))->posts;

        $id = $found[0] ?? null;

        return is_int($id) ? $id : null;
    }

    /**
     * Bring a me&u photo into our own media library.
     *
     * Linking their CDN directly would be less code, but it hotlinks a third
     * party and serves the untouched original — the whole reason the rest of the
     * site generates derivatives. Copying it in once means menu photos get the
     * same WebP ladder as everything else.
     *
     * Keyed on me&u's own image id, so a re-run reuses the file. A failure
     * returns nothing: a menu without photos is fine, a sync that dies because
     * one image 404d is not.
     */
    private static function image(mixed $image, string $alt): string
    {
        if (!is_array($image)) {
            return '';
        }

        $id = Val::str($image['id'] ?? null);
        $url = Val::str($image['originalImageUrl'] ?? null);

        if (null === $id || null === $url) {
            return '';
        }

        $existing = self::findByMeta('attachment', '_phat_meandu_image', $id);

        if (null !== $existing) {
            return (string) $existing;
        }

        include_once ABSPATH.'wp-admin/includes/media.php';
        include_once ABSPATH.'wp-admin/includes/file.php';
        include_once ABSPATH.'wp-admin/includes/image.php';

        $attachment = media_sideload_image($url, 0, $alt, 'id');

        if ($attachment instanceof WP_Error) {
            WP_CLI::log(sprintf('  image menu-%s: %s', $id, $attachment->get_error_message()));

            return '';
        }

        $attachmentId = Val::int($attachment);
        update_post_meta($attachmentId, '_phat_meandu_image', $id);
        update_post_meta($attachmentId, '_wp_attachment_image_alt', $alt);

        return (string) $attachmentId;
    }

    /**
     * Find the beer a tap is pouring.
     *
     * me&u writes names differently from the CMS — "West Is Best" against "West
     * is Best Lager" — so the comparison is on letters and digits only, then
     * containment either way. The length guard stops a short name matching half
     * the range.
     *
     * @param array<string, int> $beers
     */
    private static function matchBeer(array $beers, string $name): ?int
    {
        $key = self::normalise($name);

        if (isset($beers[$key])) {
            return $beers[$key];
        }

        foreach ($beers as $beerName => $id) {
            if (mb_strlen($beerName) > 4 && (str_contains($key, $beerName) || str_contains($beerName, $key))) {
                return $id;
            }
        }

        return null;
    }

    private static function normalise(string $value): string
    {
        return (string) preg_replace('/[^a-z0-9]/', '', mb_strtolower($value));
    }

    /**
     * @param array<string, mixed> $variables
     *
     * @return array<string, mixed>
     */
    private function query(string $query, array $variables, string $operationName): array
    {
        $response = wp_remote_post($this->gateway, [
            'timeout' => 30,
            'headers' => ['Content-Type' => 'application/json', 'User-Agent' => self::UA],
            'body' => (string) wp_json_encode([
                'query' => $query,
                'variables' => $variables,
                'operationName' => $operationName,
            ]),
        ]);

        if ($response instanceof WP_Error) {
            throw new MeanduException($response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);

        if (200 !== $code) {
            throw new MeanduException(sprintf('gateway HTTP %s', (string) $code));
        }

        $json = json_decode((string) wp_remote_retrieve_body($response), true);

        if (!is_array($json)) {
            throw new MeanduException('gateway returned no JSON');
        }

        $errors = Val::rows($json['errors'] ?? null);

        if ([] !== $errors) {
            throw new MeanduException('gateway: '.Val::text($errors[0]['message'] ?? null, 'unknown error'));
        }

        if (!is_array($json['data'] ?? null)) {
            throw new MeanduException('gateway returned no data');
        }

        /**
 * @var array<string, mixed> $data
*/
        $data = $json['data'];

        return $data;
    }

    /**
     * @return list<WP_Post>
     */
    private static function query_posts(string $postType): array
    {
        $found = (new WP_Query([
            'post_type' => $postType,
            'post_status' => 'publish',
            'posts_per_page' => 200,
            'orderby' => 'ID',
            'order' => 'ASC',
        ]))->posts;

        $out = [];

        foreach ($found as $post) {
            if ($post instanceof WP_Post) {
                $out[] = $post;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $cat
     *
     * @return list<array{name: string, items: list<array<string, mixed>>}>
     */
    private static function sections(array $cat, string $shortName): array
    {
        $sections = [];

        foreach (Val::rows($cat['menuSections'] ?? null) as $section) {
            if (Val::bool($section['isUnavailable'] ?? null)) {
                continue;
            }

            $items = [];

            foreach (Val::rows($section['menuItems'] ?? null) as $item) {
                if (($item['isAvailable'] ?? null) === false) {
                    continue;
                }

                $name = Val::text($item['name'] ?? null);
                $tags = Val::strings($item['dietaryTags'] ?? null);
                $price = Val::rows([$item['priceData'] ?? []])[0] ?? [];

                $items[] = [
                    'name' => $name,
                    'price' => Val::text($price['displayPrice'] ?? null),
                    'dietary' => implode(', ', $tags),
                    'description' => Val::text($item['descriptionPlain'] ?? null),
                    // Sequential, not batched: these come from someone else's
                    // CDN, and a burst of parallel requests is not how to treat
                    // an undocumented service we are already a guest of.
                    'image' => self::image($item['image'] ?? null, $name.' at '.$shortName),
                    'image_credit' => Val::text($item['imageCredit'] ?? null),
                ];
            }

            $sections[] = ['name' => Val::text($section['name'] ?? null), 'items' => $items];
        }

        return $sections;
    }

    /**
     * The "On Tap" section is the live tap list.
     *
     * Mirrored separately so the site can answer "what is pouring right now"
     * without anyone retyping it.
     *
     * @param array<string, mixed> $cat
     */
    private static function tapList(WP_Post $venue, string $shortName, array $cat): void
    {
        $onTap = null;

        foreach (Val::rows($cat['menuSections'] ?? null) as $section) {
            if (1 === preg_match('/on tap/i', Val::text($section['name'] ?? null))) {
                $onTap = $section;

                break;
            }
        }

        if (null === $onTap) {
            return;
        }

        $beers = self::beersByName();
        $taps = [];
        $matched = 0;
        $number = 0;

        foreach (Val::rows($onTap['menuItems'] ?? null) as $item) {
            if (($item['isAvailable'] ?? null) === false) {
                continue;
            }

            ++$number;
            $name = Val::text($item['name'] ?? null);
            $beerId = self::matchBeer($beers, $name);
            $price = Val::rows([$item['priceData'] ?? []])[0] ?? [];

            $taps[] = [
                'tap_number' => (string) $number,
                'beer' => null === $beerId
                    ? []
                    : [['id' => $beerId, 'type' => 'post', 'subtype' => PostTypes::BEER]],
                'guest_name' => null === $beerId ? $name : '',
                'guest_style' => '',
                'price' => Val::text($price['displayPrice'] ?? null),
                'keg_blown' => false,
            ];

            if (null !== $beerId) {
                ++$matched;
            }
        }

        $existing = self::findByMeta(PostTypes::TAP_LIST, '_phat_venue_source', (string) $venue->ID);

        $inserted = wp_insert_post([
            'ID' => $existing ?? 0,
            'post_type' => PostTypes::TAP_LIST,
            'post_status' => 'publish',
            'post_title' => $shortName.' tap list',
        ], true);

        if ($inserted instanceof WP_Error) {
            throw new MeanduException($inserted->get_error_message());
        }

        update_post_meta($inserted, '_phat_venue_source', (string) $venue->ID);
        carbon_set_post_meta($inserted, 'phat_venue', [
            ['id' => $venue->ID, 'type' => 'post', 'subtype' => PostTypes::VENUE],
        ]);
        carbon_set_post_meta($inserted, 'phat_taps', $taps);
        carbon_set_post_meta($inserted, 'phat_source', 'meandu');
        carbon_set_post_meta($inserted, 'phat_synced_at', gmdate('c'));

        WP_CLI::log(sprintf(
            '  %s: tap list refreshed, %d taps (%d matched to a beer, %d guest)',
            $shortName,
            count($taps),
            $matched,
            count($taps) - $matched,
        ));
    }

    /**
     * @param array<string, mixed>                                         $cat
     * @param list<array{name: string, items: list<array<string, mixed>>}> $sections
     */
    private static function upsertMenu(WP_Post $venue, string $shortName, array $cat, array $sections): void
    {
        $meanduId = Val::text($cat['id'] ?? null);
        $existing = self::findByMeta(PostTypes::MENU, '_phat_meandu_id_source', $meanduId);

        $inserted = wp_insert_post([
            'ID' => $existing ?? 0,
            'post_type' => PostTypes::MENU,
            'post_status' => 'publish',
            'post_title' => sprintf('%s — %s', $shortName, Val::text($cat['name'] ?? null)),
        ], true);

        if ($inserted instanceof WP_Error) {
            throw new MeanduException($inserted->get_error_message());
        }

        // A plain meta key alongside Carbon's own, purely so the next run can
        // find this menu again: Carbon stores its fields under a hierarchical
        // scheme that a meta_query cannot match.
        update_post_meta($inserted, '_phat_meandu_id_source', $meanduId);

        carbon_set_post_meta($inserted, 'phat_venue', [
            ['id' => $venue->ID, 'type' => 'post', 'subtype' => PostTypes::VENUE],
        ]);
        carbon_set_post_meta($inserted, 'phat_meandu_id', $meanduId);
        carbon_set_post_meta($inserted, 'phat_synced_at', gmdate('c'));
        carbon_set_post_meta($inserted, 'phat_sections', $sections);
    }

    /**
     * @return list<WP_Post>
     */
    private static function venues(): array
    {
        return self::query_posts(PostTypes::VENUE);
    }
}
