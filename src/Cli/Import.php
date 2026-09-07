<?php

declare(strict_types=1);

namespace ChristianBrown\PhatWp\Cli;

use ChristianBrown\PhatWp\PostTypes;
use ChristianBrown\PhatWp\Val;
use WP_CLI;
use WP_Error;
use WP_Query;

use const PHP_URL_PATH;

/**
 * Copy the content across from the live Payload site.
 *
 * A WP-CLI command rather than a Node script, for three reasons. It needs no
 * write credential, because it calls wp_insert_post in process rather than
 * needing an application password and REST write endpoints for every nested
 * field. media_sideload_image puts each file through the real upload pipeline,
 * so images get the size ladder and the WebP conversion exactly as a hand
 * upload would. And it drops into the deployment pattern that already exists as
 * one more Cloud Run job.
 *
 * The source is the public Payload API, which can only see published documents,
 * so drafts cannot leak into the copy.
 *
 * Idempotent, keyed on the Payload id held in _phat_source_id rather than on the
 * slug, because slugs change and ids do not. Re-running re-syncs the two sites
 * before a comparison.
 *
 * Two passes, because the graph is circular: beers point at venues and tap lists
 * point at both. Everything is created first with its scalar fields, then the
 * relationships are wired once every target exists.
 */
final class Import
{
    private const SOURCE_ID = '_phat_source_id';

    /**
     * Every type this command writes, named explicitly.
     *
     * @var list<string>
     */
    private const TYPES = [
        PostTypes::VENUE,
        PostTypes::BEER,
        PostTypes::EVENT,
        PostTypes::MERCH,
        PostTypes::FUNCTION_PACKAGE,
        PostTypes::MENU,
        PostTypes::TAP_LIST,
        'post',
        'page',
        'attachment',
    ];
    private string $api = '';

    /**
     * @var array<string, int> payload id => WordPress post id, filled in pass one
     */
    private array $map = [];

    /**
     * @var array<string, int> payload media id => attachment id
     */
    private array $media = [];

    /**
     * @param list<string>          $args
     * @param array<string, string> $options
     */
    public function __invoke(array $args, array $options): void
    {
        $this->api = mb_rtrim($options['from'] ?? 'https://cms.pbc.christianbrown.uk', '/').'/api';
        $dryRun = isset($options['dry-run']);

        if (isset($options['fresh']) && !$dryRun) {
            self::purge();
        }

        if (!$dryRun) {
            self::removeSampleContent();
        }

        WP_CLI::log(sprintf('Reading %s%s', $this->api, $dryRun ? ' (dry run)' : ''));

        // Order matters only in that venues must exist before anything that
        // points at them is wired up in pass two; pass one is independent.
        $plan = [
            ['venues', PostTypes::VENUE, 'name'],
            ['beers', PostTypes::BEER, 'name'],
            ['events', PostTypes::EVENT, 'title'],
            ['merch', PostTypes::MERCH, 'title'],
            ['function-packages', PostTypes::FUNCTION_PACKAGE, 'name'],
            ['menus', PostTypes::MENU, 'name'],
            ['tap-lists', PostTypes::TAP_LIST, 'title'],
            ['posts', 'post', 'title'],
            ['pages', 'page', 'title'],
        ];

        /**
         * @var list<array{string, list<array<string, mixed>>}> $fetched
         */
        $fetched = [];

        foreach ($plan as [$collection, $postType, $titleField]) {
            $docs = $this->fetch($collection);
            $fetched[] = [$collection, $docs];
            WP_CLI::log(sprintf('  %-18s %d', $collection, count($docs)));

            if ($dryRun) {
                continue;
            }

            foreach ($docs as $doc) {
                $this->upsert($collection, $postType, $titleField, $doc);
            }
        }

        if ($dryRun) {
            WP_CLI::success('Dry run: nothing written.');

            return;
        }

        foreach ($fetched as [$collection, $docs]) {
            foreach ($docs as $doc) {
                $this->relate($collection, $doc);
            }
        }

        $this->settings();

        WP_CLI::success(sprintf('Imported %d documents and %d images.', count($this->map), count($this->media)));
    }

    /**
     * @param array<string, mixed>          $doc
     * @param callable(string, mixed): void $set
     */
    private function beer(int $postId, array $doc, callable $set): void
    {
        $set('phat_style', Val::text($doc['style'] ?? null));
        $set('phat_abv', Val::text($doc['abv'] ?? null));
        $set('phat_ibu', Val::text($doc['ibu'] ?? null));
        $set('phat_category', Val::text($doc['category'] ?? null, 'core'));
        $set('phat_description', Val::text($doc['description'] ?? null));
        $set('phat_tasting_notes', $this->lexical($doc['tastingNotes'] ?? null));
        $set('phat_allergens', Val::strings($doc['allergens'] ?? null));
        $set('phat_price', Val::text($doc['price'] ?? null));
        $set('phat_pack_size', Val::text($doc['packSize'] ?? null));
        $set('phat_shop_url', Val::text($doc['shopUrl'] ?? null));
        $set('phat_untappd_url', Val::text($doc['untappdUrl'] ?? null));
        $set('phat_video_id', Val::text($doc['videoId'] ?? null));
        $set('phat_can_artwork', $this->image($doc['canArtwork'] ?? null));
        $set('phat_gallery', $this->images($doc['gallery'] ?? null));

        $set('phat_ingredients', array_map(static fn (array $r): array => [
            'producer' => Val::text($r['producer'] ?? null),
            'contribution' => Val::text($r['contribution'] ?? null),
        ], Val::rows($doc['ingredients'] ?? null)));
    }

    /**
     * @param array<string, mixed>          $doc
     * @param callable(string, mixed): void $set
     */
    private function event(int $postId, array $doc, callable $set): void
    {
        $set('phat_starts_at', Val::text($doc['startsAt'] ?? null));
        $set('phat_ends_at', Val::text($doc['endsAt'] ?? null));
        $set('phat_recurrence', Val::text($doc['recurrence'] ?? null, 'once'));
        $set('phat_category', Val::text($doc['category'] ?? null));
        $set('phat_is_free', Val::bool($doc['isFree'] ?? null));
        $set('phat_price', Val::text($doc['price'] ?? null));
        $set('phat_price_note', Val::text($doc['priceNote'] ?? null));
        $set('phat_booking_url', Val::text($doc['bookingUrl'] ?? null));
        $set('phat_body', $this->lexical($doc['body'] ?? null));
        $set('phat_hero_image', $this->image($doc['heroImage'] ?? null));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetch(string $collection): array
    {
        // depth=3, not 2. A menu's photographs sit three levels down —
        // sections, then items, then the upload — and at depth 2 they arrive as
        // bare ids, so every menu photo was silently dropped and the menu page
        // came back with one image where the Payload one has forty-nine.
        $url = sprintf('%s/%s?depth=3&limit=200', $this->api, $collection);
        $response = wp_remote_get($url, ['timeout' => 60]);

        if ($response instanceof WP_Error) {
            WP_CLI::error(sprintf('%s: %s', $collection, $response->get_error_message()));
        }

        $body = json_decode((string) wp_remote_retrieve_body($response), true);

        return Val::rows(is_array($body) ? ($body['docs'] ?? []) : []);
    }

    private static function findBySource(string $collection, string $sourceId): ?int
    {
        // Not 'any'. WP_Query's "any" silently excludes every post type
        // registered with exclude_from_search, which is what public => false
        // gives you — so this found nothing, every run created a fresh copy of
        // all 58 documents, and four runs produced four sites' worth of
        // content on one site.
        $found = (new WP_Query([
            'post_type' => self::TYPES,
            'post_status' => 'any',
            'posts_per_page' => 1,
            'fields' => 'ids',
            'meta_query' => [[
                'key' => self::SOURCE_ID,
                'value' => $collection.':'.$sourceId,
            ]],
        ]))->posts ?? [];

        $id = $found[0] ?? null;

        return is_int($id) ? $id : null;
    }

    /**
     * @param array<string, mixed>          $doc
     * @param callable(string, mixed): void $set
     */
    private function functionPackage(int $postId, array $doc, callable $set): void
    {
        $set('phat_capacity', Val::text($doc['capacity'] ?? null));
        $set('phat_seating', Val::text($doc['seating'] ?? null));
        $set('phat_price_guide', Val::text($doc['priceGuide'] ?? null));
        $set('phat_description', $this->lexical($doc['description'] ?? null));
        $set('phat_image', $this->image($doc['image'] ?? null));
        $set('phat_brochure', $this->image($doc['brochure'] ?? null));
        $set('phat_inclusions', array_map(static fn (array $r): array => [
            'item' => Val::text($r['item'] ?? null),
        ], Val::rows($doc['inclusions'] ?? null)));
    }

    /**
     * A Payload upload, sideloaded into the media library.
     *
     * Keyed on the Payload media id so a re-run does not download it again, and
     * so two documents sharing an image share one attachment.
     */
    private function image(mixed $value): string
    {
        if (!is_array($value)) {
            return '';
        }

        $sourceId = Val::text($value['id'] ?? null);
        $url = Val::str($value['url'] ?? null);

        if ('' === $sourceId || null === $url) {
            return '';
        }

        if (isset($this->media[$sourceId])) {
            return (string) $this->media[$sourceId];
        }

        $existing = self::findBySource('media', $sourceId);

        if (null !== $existing) {
            $this->media[$sourceId] = $existing;

            return (string) $existing;
        }

        include_once ABSPATH.'wp-admin/includes/media.php';
        include_once ABSPATH.'wp-admin/includes/file.php';
        include_once ABSPATH.'wp-admin/includes/image.php';

        $alt = Val::text($value['alt'] ?? null);

        /**
         * media_sideload_image refuses anything that is not an image, which
         * silently lost the functions pack — a PDF the venue page offers for
         * download. Non-images take the generic path instead.
         */
        $sideloaded = str_ends_with(mb_strtolower($url), '.pdf')
        ? self::sideloadFile($url)
        : media_sideload_image($url, 0, $alt, 'id');

        if ($sideloaded instanceof WP_Error) {
            WP_CLI::warning(sprintf('media %s: %s', $url, $sideloaded->get_error_message()));

            return '';
        }

        $attachmentId = Val::int($sideloaded);

        update_post_meta($attachmentId, self::SOURCE_ID, 'media:'.$sourceId);
        // Required on the contract, and WordPress leaves it empty by default.
        update_post_meta($attachmentId, '_wp_attachment_image_alt', $alt);
        $this->media[$sourceId] = $attachmentId;

        return (string) $attachmentId;
    }

    /**
     * @return list<string>
     */
    private function images(mixed $value): array
    {
        $out = [];

        foreach (is_array($value) ? $value : [] as $item) {
            $id = $this->image($item);

            if ('' !== $id) {
                $out[] = $id;
            }
        }

        return $out;
    }

    /**
     * The page builder's blocks, with the group names Carbon accepts.
     *
     * @return list<array<string, mixed>>
     */
    private function layout(mixed $value): array
    {
        $names = array_flip([
            'rich_text' => 'richText',
            'venue_cards' => 'venueCards',
            'beer_grid' => 'beerGrid',
            'event_list' => 'eventList',
            'tap_list' => 'tapList',
        ]);

        $out = [];

        foreach (Val::rows($value) as $block) {
            $type = Val::text($block['blockType'] ?? null);
            $row = ['_type' => $names[$type] ?? $type];

            foreach ($block as $key => $item) {
                if (in_array($key, ['blockType', 'id'], true)) {
                    continue;
                }

                $row['filterBy' === $key ? 'filter_by' : (string) $key] = match (true) {
                    'body' === $key, 'answer' === $key => $this->lexical($item),
                    'image' === $key => $this->image($item),
                    'images' === $key => $this->images($item),
                    'venues' === $key => $this->refs('venues', $item),
                    'venue' === $key => $this->refs('venues', [$item]),
                    'questions' === $key => array_map(fn (array $q): array => [
                        'question' => Val::text($q['question'] ?? null),
                        'answer' => $this->lexical($q['answer'] ?? null),
                    ], Val::rows($item)),
                    default => $item,
                };
            }

            $out[] = $row;
        }

        return $out;
    }

    /**
     * Lexical to HTML.
     *
     * The mirror image of the website's RichText component, and deliberately as
     * small: the editor toolbar as configured produces paragraphs, headings,
     * lists, quotes, links, line breaks and the bold/italic/underline bitmask,
     * and nothing else. Anything unrecognised contributes its children rather
     * than disappearing.
     */
    private function lexical(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (!is_array($value)) {
            return '';
        }

        $root = $value['root'] ?? null;

        return is_array($root) ? $this->nodes($root['children'] ?? null) : '';
    }

    /**
     * @param array<string, mixed>          $doc
     * @param callable(string, mixed): void $set
     */
    private function menuMeta(int $postId, array $doc, callable $set): void
    {
        $set('phat_sections', $this->sections($doc['sections'] ?? null));
        $set('phat_meandu_id', Val::text($doc['meanduId'] ?? null));
        $set('phat_synced_at', Val::text($doc['syncedAt'] ?? null));
    }

    /**
     * @param array<string, mixed>          $doc
     * @param callable(string, mixed): void $set
     */
    private function merch(int $postId, array $doc, callable $set): void
    {
        $set('phat_price', Val::text($doc['price'] ?? null));
        $set('phat_sold_out', Val::bool($doc['soldOut'] ?? null));
        $set('phat_shop_url', Val::text($doc['shopUrl'] ?? null));
        $set('phat_description', Val::text($doc['description'] ?? null));
        $set('phat_images', $this->images($doc['images'] ?? null));
    }

    /**
     * An ISO date as the UTC MySQL datetime wp_insert_post wants.
     */
    private static function mysqlDate(mixed $value): string
    {
        $raw = Val::str($value);
        $time = null === $raw ? false : strtotime($raw);

        return false === $time ? '' : gmdate('Y-m-d H:i:s', $time);
    }

    private function nodes(mixed $children): string
    {
        $html = '';

        foreach (Val::rows($children) as $node) {
            $type = Val::text($node['type'] ?? null);
            $inner = $this->nodes($node['children'] ?? null);

            if ('text' === $type) {
                $text = esc_html(Val::text($node['text'] ?? null));
                $format = Val::int($node['format'] ?? null);

                // The same bitmask the website reads: 1 bold, 2 italic, 8 underline.
                if (($format & 1) !== 0) {
                    $text = '<strong>'.$text.'</strong>';
                }

                if (($format & 2) !== 0) {
                    $text = '<em>'.$text.'</em>';
                }

                if (($format & 8) !== 0) {
                    $text = '<u>'.$text.'</u>';
                }

                $html .= $text;

                continue;
            }

            $fields = Val::rows([$node['fields'] ?? []])[0] ?? [];

            $html .= match ($type) {
                'heading' => sprintf('<%1$s>%2$s</%1$s>', Val::text($node['tag'] ?? null, 'h3'), $inner),
                'list' => 'number' === Val::text($node['listType'] ?? null)
                ? '<ol>'.$inner.'</ol>'
                : '<ul>'.$inner.'</ul>',
                'listitem' => '<li>'.$inner.'</li>',
                'quote' => '<blockquote>'.$inner.'</blockquote>',
                'paragraph' => '<p>'.$inner.'</p>',
                'linebreak' => '<br />',
                'link', 'autolink' => sprintf(
                    '<a href="%s">%s</a>',
                    esc_url(Val::text($fields['url'] ?? null)),
                    $inner,
                ),
                default => $inner,
            };
        }

        return $html;
    }

    /**
     * Delete everything this command has previously written.
     *
     * Scoped to posts carrying _phat_source_id, so anything authored by hand in
     * wp-admin is left alone. Forced rather than trashed: a trashed post keeps
     * its slug reserved, and the re-import would then get every slug suffixed
     * with -2.
     */
    private static function purge(): void
    {
        WP_CLI::log('  purging previously imported content');

        /**
         * Three separate queries, merged here, rather than one with an OR.
         *
         * An OR of three EXISTS clauses makes WP_Query join postmeta three
         * times and index nothing, and on a db-f1-micro with Carbon Fields'
         * many meta rows per document that never came back — the job hit its
         * timeout twice having logged nothing at all, because it died before
         * the first line of output. One key at a time uses the meta_key index
         * and returns instantly.
         */
        $ids = [];

        foreach ([self::SOURCE_ID, '_phat_meandu_id_source', '_phat_venue_source'] as $key) {
            $found = (new WP_Query([
                'post_type' => self::TYPES,
                'post_status' => 'any',
                'posts_per_page' => -1,
                'fields' => 'ids',
                'no_found_rows' => true,
                'update_post_meta_cache' => false,
                'update_post_term_cache' => false,
                'meta_key' => $key,
                'meta_compare' => 'EXISTS',
            ]))->posts ?? [];

            foreach ($found as $id) {
                if (is_int($id)) {
                    $ids[$id] = true;
                }
            }
        }

        foreach (array_keys($ids) as $id) {
            wp_delete_post($id, true);
        }

        WP_CLI::log(sprintf('  purged %d previously imported items', count($ids)));
    }

    /**
     * Carbon association rows for a set of Payload documents.
     *
     * @return list<array<string, mixed>>
     */
    private function refs(string $collection, mixed $value): array
    {
        $out = [];

        foreach (is_array($value) ? $value : [$value] as $item) {
            // depth=2 means a relationship is usually the whole document, but a
            // bare id turns up wherever the depth ran out.
            $id = is_array($item) ? Val::text($item['id'] ?? null) : Val::text($item);
            $postId = $this->map[$collection.':'.$id] ?? null;

            if (null !== $postId) {
                $out[] = ['id' => $postId, 'type' => 'post', 'subtype' => get_post_type($postId)];
            }
        }

        return $out;
    }

    /**
     * Pass two: everything that points at another document.
     *
     * @param array<string, mixed> $doc
     */
    private function relate(string $collection, array $doc): void
    {
        $postId = $this->map[$collection.':'.Val::text($doc['id'] ?? null)] ?? null;

        if (null === $postId) {
            return;
        }

        match ($collection) {
            'beers' => carbon_set_post_meta(
                $postId,
                'phat_available_at',
                $this->refs('venues', $doc['availableAt'] ?? null),
            ),
            'events' => carbon_set_post_meta(
                $postId,
                'phat_venues',
                $this->refs('venues', $doc['venues'] ?? null),
            ),
            'menus', 'function-packages' => carbon_set_post_meta(
                $postId,
                'phat_venue',
                $this->refs('venues', [$doc['venue'] ?? null]),
            ),
            'tap-lists' => $this->tapList($postId, $doc),
            default => null,
        };
    }

    /**
     * Remove the sample content WordPress installs with.
     *
     * "Hello world!" and "Sample Page" are published the moment core is
     * installed, and this site's news index lists every post — so the brewery's
     * four became five, and the comparison showed a difference that was
     * WordPress's own furniture rather than anything about the content.
     *
     * Matched on slug and only when the post carries no _phat_source_id, so a
     * real post that happens to be called hello-world is never touched.
     */
    private static function removeSampleContent(): void
    {
        $found = (new WP_Query([
            'post_type' => ['post', 'page'],
            'post_status' => 'any',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'post_name__in' => ['hello-world', 'sample-page', 'privacy-policy'],
        ]))->posts ?? [];

        $removed = 0;

        foreach ($found as $id) {
            if (is_int($id) && '' === get_post_meta($id, self::SOURCE_ID, true)) {
                wp_delete_post($id, true);
                ++$removed;
            }
        }

        if ($removed > 0) {
            WP_CLI::log(sprintf('  removed %d WordPress sample posts', $removed));
        }
    }

    /**
     * The fields that do not depend on another document existing.
     *
     * @param array<string, mixed> $doc
     */
    private function scalars(string $collection, int $postId, array $doc): void
    {
        $set = static function (string $key, mixed $value) use ($postId): void {
            carbon_set_post_meta($postId, $key, $value);
        };

        match ($collection) {
            'venues' => $this->venue($postId, $doc, $set),
            'beers' => $this->beer($postId, $doc, $set),
            'events' => $this->event($postId, $doc, $set),
            'merch' => $this->merch($postId, $doc, $set),
            'function-packages' => $this->functionPackage($postId, $doc, $set),
            'menus' => self::menuMeta($postId, $doc, $set),
            'tap-lists' => $this->tapListMeta($postId, $doc),
            'posts' => $this->thumbnail($postId, $doc['heroImage'] ?? null),
            'pages' => $set('phat_layout', $this->layout($doc['layout'] ?? null)),
            default => null,
        };

        $seo = Val::rows([$doc['seo'] ?? []])[0] ?? [];
        $set('phat_seo_title', Val::text($seo['title'] ?? null));
        $set('phat_seo_description', Val::text($seo['description'] ?? null));
        $set('phat_seo_image', $this->image($seo['image'] ?? null));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function sections(mixed $value): array
    {
        $out = [];

        foreach (Val::rows($value) as $section) {
            $items = [];

            foreach (Val::rows($section['items'] ?? null) as $item) {
                $items[] = [
                    'name' => Val::text($item['name'] ?? null),
                    'price' => Val::text($item['price'] ?? null),
                    'dietary' => Val::text($item['dietary'] ?? null),
                    'description' => Val::text($item['description'] ?? null),
                    'image' => $this->image($item['image'] ?? null),
                    'image_credit' => Val::text($item['imageCredit'] ?? null),
                ];
            }

            $out[] = ['name' => Val::text($section['name'] ?? null), 'items' => $items];
        }

        return $out;
    }

    private function settings(): void
    {
        $response = wp_remote_get($this->api.'/globals/settings?depth=1', ['timeout' => 30]);

        if (is_wp_error($response)) {
            return;
        }

        $doc = json_decode((string) wp_remote_retrieve_body($response), true);

        if (!is_array($doc)) {
            return;
        }

        foreach ([
            'phat_announcement' => 'announcement',
            'phat_announcement_url' => 'announcementUrl',
            'phat_announcement_until' => 'announcementUntil',
            'phat_booking_label' => 'bookingLabel',
            'phat_facebook' => 'facebook',
            'phat_tiktok' => 'tiktok',
            'phat_youtube' => 'youtube',
            'phat_untappd' => 'untappd',
            'phat_default_title' => 'defaultTitle',
            'phat_default_description' => 'defaultDescription',
        ] as $target => $source) {
            carbon_set_theme_option($target, Val::text($doc[$source] ?? null));
        }

        carbon_set_theme_option('phat_default_image', $this->image($doc['defaultImage'] ?? null));

        $nav = [];

        foreach (Val::rows($doc['mainNav'] ?? null) as $row) {
            $nav[] = [
                'label' => Val::text($row['label'] ?? null),
                'url' => Val::text($row['url'] ?? null),
                'children' => array_map(static fn (array $c): array => [
                    'label' => Val::text($c['label'] ?? null),
                    'url' => Val::text($c['url'] ?? null),
                ], Val::rows($row['children'] ?? null)),
            ];
        }

        carbon_set_theme_option('phat_main_nav', $nav);
    }

    /**
     * Download a non-image and attach it to the media library.
     */
    private static function sideloadFile(string $url): int|WP_Error
    {
        $tmp = download_url($url);

        if ($tmp instanceof WP_Error) {
            return $tmp;
        }

        $path = parse_url($url, PHP_URL_PATH);
        $file = [
            'name' => basename(is_string($path) ? $path : 'file.pdf'),
            'tmp_name' => $tmp,
        ];

        $id = media_handle_sideload($file, 0);

        if ($id instanceof WP_Error && file_exists($tmp)) {
            wp_delete_file($tmp);

            return $id;
        }

        return Val::int($id);
    }

    /**
     * @param array<string, mixed> $doc
     */
    private function tapList(int $postId, array $doc): void
    {
        carbon_set_post_meta($postId, 'phat_venue', $this->refs('venues', [$doc['venue'] ?? null]));

        $taps = [];

        foreach (Val::rows($doc['taps'] ?? null) as $tap) {
            $taps[] = [
                'tap_number' => Val::text($tap['tapNumber'] ?? null),
                'beer' => $this->refs('beers', [$tap['beer'] ?? null]),
                'guest_name' => Val::text($tap['guestName'] ?? null),
                'guest_style' => Val::text($tap['guestStyle'] ?? null),
                'price' => Val::text($tap['price'] ?? null),
                'keg_blown' => Val::bool($tap['kegBlown'] ?? null),
            ];
        }

        carbon_set_post_meta($postId, 'phat_taps', $taps);
    }

    /**
     * @param array<string, mixed> $doc
     */
    private function tapListMeta(int $postId, array $doc): void
    {
        carbon_set_post_meta($postId, 'phat_source', Val::text($doc['source'] ?? null, 'manual'));
        carbon_set_post_meta($postId, 'phat_synced_at', Val::text($doc['syncedAt'] ?? null));

        // As with menus: the key the sync uses to find this list again.
        $venue = $this->refs('venues', [$doc['venue'] ?? null]);
        $venueId = [] === $venue ? null : $venue[0]['id'];

        if (is_int($venueId)) {
            update_post_meta($postId, '_phat_venue_source', (string) $venueId);
        }
    }

    private function thumbnail(int $postId, mixed $value): void
    {
        $id = $this->image($value);

        if ('' !== $id) {
            set_post_thumbnail($postId, (int) $id);
        }
    }

    /**
     * @param array<string, mixed> $doc
     */
    private function upsert(string $collection, string $postType, string $titleField, array $doc): void
    {
        $sourceId = Val::text($doc['id'] ?? null);

        if ('' === $sourceId) {
            return;
        }

        $existing = self::findBySource($collection, $sourceId);

        $inserted = wp_insert_post([
            'ID' => $existing ?? 0,
            'post_type' => $postType,
            'post_status' => 'publish',
            'post_title' => Val::text($doc[$titleField] ?? null, 'Untitled'),
            'post_name' => Val::text($doc['slug'] ?? null),
            'post_content' => 'post' === $postType ? $this->lexical($doc['body'] ?? null) : '',
            'post_excerpt' => 'post' === $postType ? Val::text($doc['excerpt'] ?? null) : '',
            // Otherwise WordPress stamps the import time, so the news index
            // shows the day it was copied rather than the day it was published
            // — and orders the posts by it.
            'post_date_gmt' => self::mysqlDate($doc['publishedAt'] ?? null),
        ], true);

        if ($inserted instanceof WP_Error) {
            WP_CLI::warning(sprintf('%s %s: %s', $collection, $sourceId, $inserted->get_error_message()));

            return;
        }

        $postId = $inserted;
        update_post_meta($postId, self::SOURCE_ID, $collection.':'.$sourceId);
        $this->map[$collection.':'.$sourceId] = $postId;

        $this->scalars($collection, $postId, $doc);
    }

    /**
     * @param array<string, mixed>          $doc
     * @param callable(string, mixed): void $set
     */
    private function venue(int $postId, array $doc, callable $set): void
    {
        $address = Val::rows([$doc['address'] ?? []])[0] ?? [];

        $set('phat_short_name', Val::text($doc['shortName'] ?? null));
        $set('phat_street', Val::text($address['street'] ?? null));
        $set('phat_suburb', Val::text($address['suburb'] ?? null));
        $set('phat_state', Val::text($address['state'] ?? null, 'WA'));
        $set('phat_postcode', Val::text($address['postcode'] ?? null));
        $set('phat_latitude', Val::text($address['latitude'] ?? null));
        $set('phat_longitude', Val::text($address['longitude'] ?? null));
        $set('phat_transport_note', Val::text($doc['transportNote'] ?? null));
        $set('phat_phone', Val::text($doc['phone'] ?? null));
        $set('phat_email', Val::text($doc['email'] ?? null));
        $set('phat_hours_label', Val::text($doc['hoursLabel'] ?? null));
        $set('phat_public_holiday_note', Val::text($doc['publicHolidayNote'] ?? null));
        $set('phat_intro', Val::text($doc['intro'] ?? null));
        $set('phat_capacity', Val::text($doc['capacity'] ?? null));
        $set('phat_tap_count', Val::text($doc['tapCount'] ?? null, '20'));
        $set('phat_amenities', Val::strings($doc['amenities'] ?? null));
        $set('phat_maps_query', Val::text($doc['mapsQuery'] ?? null));
        $set('phat_instagram', Val::text($doc['instagram'] ?? null));
        $set('phat_booking_url', Val::text($doc['bookingUrl'] ?? null));
        $set('phat_meandu_slug', Val::text($doc['meanduSlug'] ?? null));
        $set('phat_menu_url', Val::text($doc['menuUrl'] ?? null));
        $set('phat_hero_image', $this->image($doc['heroImage'] ?? null));
        $set('phat_functions_pack', $this->image($doc['functionsPack'] ?? null));
        $set('phat_gallery', $this->images($doc['gallery'] ?? null));

        $set('phat_opening_hours', array_map(static fn (array $r): array => [
            'day' => Val::text($r['day'] ?? null),
            'opens' => Val::text($r['opens'] ?? null),
            'closes' => Val::text($r['closes'] ?? null),
            'closed' => Val::bool($r['closed'] ?? null),
        ], Val::rows($doc['openingHours'] ?? null)));

        $set('phat_hours_overrides', array_map(static fn (array $r): array => [
            'date' => Val::text($r['date'] ?? null),
            'label' => Val::text($r['label'] ?? null),
            'opens' => Val::text($r['opens'] ?? null),
            'closes' => Val::text($r['closes'] ?? null),
            'closed' => Val::bool($r['closed'] ?? null),
        ], Val::rows($doc['hoursOverrides'] ?? null)));

        $set('phat_faqs', array_map(static fn (array $r): array => [
            'question' => Val::text($r['question'] ?? null),
            'answer' => Val::text($r['answer'] ?? null),
        ], Val::rows($doc['faqs'] ?? null)));
    }
}
