<?php

declare(strict_types=1);

namespace ChristianBrown\PhatWp;

use Carbon_Fields\Container;
use Carbon_Fields\Field;
use WP_Post;

/**
 * The page builder: ten block types, mirroring Payload's `pages.layout`.
 *
 * A Carbon Fields complex field with ten named groups, not ten native Gutenberg
 * blocks. Gutenberg would be the better editing experience — in-canvas and
 * visual — and if the deliverable were "a WordPress site" it would win. It is
 * not: the deliverable is a provable clone, and the front end renders a
 * `Block[]` array rather than HTML, so native blocks would cost a JavaScript
 * build inside this image, ten block.json files, custom controls for the venue
 * picker and three nested repeaters, and a parse_blocks mapping layer — all to
 * produce byte-identical output.
 *
 * The group names are the Payload block slugs exactly, because the front end
 * switches on `blockType` and a rename would silently render nothing.
 *
 * Worth saying plainly: Payload gives editors a live preview of the page as they
 * build it. This does not, and there is no cheap way to add one.
 */
final class Blocks
{
    /**
     * Carbon's group name for each block, and the blockType the website expects.
     *
     * They differ because Carbon rejects a name that is not lowercase — its
     * factory returns null rather than throwing, so a camelCase name produces a
     * field that is silently absent and, here, a fatal from the type guard.
     * The website switches on the camelCase form, so the two are mapped rather
     * than one being bent to the other.
     */
    private const BLOCK_TYPES = [
        'rich_text' => 'richText',
        'venue_cards' => 'venueCards',
        'beer_grid' => 'beerGrid',
        'event_list' => 'eventList',
        'tap_list' => 'tapList',
    ];

    /**
     * @return array<string, mixed>
     */
    public static function page(WP_Post $post): array
    {
        $layout = [];
        $raw = carbon_get_post_meta($post->ID, 'phat_layout');

        foreach (Val::rows($raw) as $block) {
            $type = Val::str($block['_type'] ?? null);

            if (null === $type) {
                continue;
            }

            $layout[] = self::block($type, $block);
        }

        return [
            'id' => $post->ID,
            'title' => $post->post_title,
            'slug' => $post->post_name,
            'layout' => $layout,
            'seo' => Shape::seo($post->ID),
        ];
    }

    public static function register(): void
    {
        Container::make('post_meta', 'Page content')
            ->where('post_type', '=', 'page')
            ->add_fields([
                FieldFactory::complex('phat_layout', 'Blocks')
                    ->set_layout('tabbed-vertical')
                    ->add_fields('hero', 'Hero', [
                        Field::make('text', 'eyebrow', 'Eyebrow'),
                        Field::make('text', 'heading', 'Heading')->set_required(true),
                        Field::make('textarea', 'lede', 'Lede'),
                        Field::make('image', 'image', 'Image'),
                        FieldFactory::complex('actions', 'Buttons')
                            ->set_max(2)
                            ->add_fields([
                                Field::make('text', 'label', 'Label')->set_required(true)->set_width(40),
                                Field::make('text', 'url', 'URL')->set_required(true)->set_width(60),
                            ]),
                    ])
                    ->add_fields('rich_text', 'Text', [
                        Field::make('text', 'heading', 'Heading'),
                        Field::make('rich_text', 'body', 'Body')->set_required(true),
                    ])
                    ->add_fields('venue_cards', 'Venue cards', [
                        Field::make('text', 'heading', 'Heading'),
                        FieldFactory::association('venues', 'Venues', PostTypes::VENUE),
                    ])
                    ->add_fields('beer_grid', 'Beer grid', [
                        Field::make('text', 'heading', 'Heading'),
                        FieldFactory::select('filter_by', 'Show', [
                            'all' => 'Everything',
                            'core' => 'Core range only',
                            'seasonal' => 'Seasonal only',
                            'limited' => 'Limited only',
                        ])->set_default_value('all'),
                        FieldFactory::number('limit', 'How many', 12),
                    ])
                    ->add_fields('event_list', 'Event list', [
                        Field::make('text', 'heading', 'Heading'),
                        FieldFactory::association('venue', 'Venue', PostTypes::VENUE, 1)
                            ->set_help_text('Leave empty for both venues.'),
                        FieldFactory::number('limit', 'How many', 4),
                    ])
                    ->add_fields('tap_list', 'Tap list', [
                        Field::make('text', 'heading', 'Heading'),
                        FieldFactory::association('venue', 'Venue', PostTypes::VENUE, 1),
                    ])
                    ->add_fields('faq', 'FAQ', [
                        Field::make('text', 'heading', 'Heading'),
                        FieldFactory::complex('questions', 'Questions')
                            ->add_fields([
                                Field::make('text', 'question', 'Question')->set_required(true),
                                Field::make('rich_text', 'answer', 'Answer')->set_required(true),
                            ]),
                    ])
                    ->add_fields('awards', 'Awards', [
                        Field::make('text', 'heading', 'Heading'),
                        FieldFactory::complex('entries', 'Awards')
                            ->add_fields([
                                Field::make('text', 'year', 'Year')->set_required(true)->set_width(20),
                                Field::make('text', 'body', 'What')->set_required(true)->set_width(50),
                                Field::make('text', 'detail', 'Detail')->set_width(30),
                            ]),
                    ])
                    ->add_fields('gallery', 'Gallery', [
                        Field::make('text', 'heading', 'Heading'),
                        Field::make('media_gallery', 'images', 'Images'),
                    ])
                    ->add_fields('quote', 'Quote', [
                        Field::make('textarea', 'quote', 'Quote')->set_required(true),
                        Field::make('text', 'attribution', 'Who said it'),
                    ]),
            ]);

        Container::make('post_meta', 'Search and social')
            ->where('post_type', '=', 'page')
            ->add_fields([
                FieldFactory::limitedText('text', 'phat_seo_title', 'Title', 65),
                FieldFactory::limitedText('textarea', 'phat_seo_description', 'Description', 165),
                Field::make('image', 'phat_seo_image', 'Share image'),
            ]);
    }

    /**
     * @param array<string, mixed> $block
     *
     * @return array<string, mixed>
     */
    private static function block(string $stored, array $block): array
    {
        $type = self::BLOCK_TYPES[$stored] ?? $stored;

        $common = ['blockType' => $type, 'heading' => Val::str($block['heading'] ?? null)];

        return match ($type) {
            'hero' => [
                'blockType' => 'hero',
                'eyebrow' => Val::str($block['eyebrow'] ?? null),
                'heading' => Val::text($block['heading'] ?? null),
                'lede' => Val::str($block['lede'] ?? null),
                'image' => Shape::media($block['image'] ?? null),
                'actions' => self::pairs($block['actions'] ?? null, 'label', 'url'),
            ],
            'richText' => $common + ['body' => Shape::html($block['body'] ?? null)],
            'venueCards' => $common + [
                'venues' => Shape::expand(Shape::related($block['venues'] ?? null), 'venue'),
            ],
            'beerGrid' => $common + [
                'filterBy' => Val::text($block['filter_by'] ?? null, 'all'),
                'limit' => Val::int($block['limit'] ?? null, 12),
            ],
            'eventList' => $common + [
                'venue' => Shape::expand(Shape::related($block['venue'] ?? null), 'venue')[0] ?? null,
                'limit' => Val::int($block['limit'] ?? null, 4),
            ],
            'tapList' => $common + [
                'venue' => Shape::expand(Shape::related($block['venue'] ?? null), 'venue')[0] ?? null,
            ],
            'faq' => $common + ['questions' => self::questions($block['questions'] ?? null)],
            'awards' => $common + [
                'entries' => self::triples($block['entries'] ?? null),
            ],
            'gallery' => $common + ['images' => Shape::mediaList($block['images'] ?? null)],
            'quote' => [
                'blockType' => 'quote',
                'quote' => Val::text($block['quote'] ?? null),
                'attribution' => Val::str($block['attribution'] ?? null),
            ],
            default => ['blockType' => $type],
        };
    }

    /**
     * @return list<array<string, string>>
     */
    private static function pairs(mixed $value, string $a, string $b): array
    {
        $out = [];

        foreach (Val::rows($value) as $row) {
            $out[] = [$a => Val::text($row[$a] ?? null), $b => Val::text($row[$b] ?? null)];
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function questions(mixed $value): array
    {
        $out = [];

        foreach (Val::rows($value) as $row) {
            $out[] = [
                'question' => Val::text($row['question'] ?? null),
                'answer' => Shape::html($row['answer'] ?? null),
            ];
        }

        return $out;
    }

    /**
     * @return list<array<string, null|string>>
     */
    private static function triples(mixed $value): array
    {
        $out = [];

        foreach (Val::rows($value) as $row) {
            $out[] = [
                'year' => Val::text($row['year'] ?? null),
                'body' => Val::text($row['body'] ?? null),
                'detail' => Val::str($row['detail'] ?? null),
            ];
        }

        return $out;
    }
}
