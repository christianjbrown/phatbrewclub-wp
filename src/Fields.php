<?php

declare(strict_types=1);

namespace ChristianBrown\PhatWp;

use Carbon_Fields\Container;
use Carbon_Fields\Field;
use Carbon_Fields\Field\Field as CarbonField;

/**
 * The editing surface, mirroring apps/cms/src/collections in the Payload repo.
 *
 * Carbon Fields rather than ACF because the model needs repeatable groups that
 * nest — a venue's opening hours, a menu's sections and the items inside them,
 * the navigation's drop-downs — and ACF has no repeater at all outside the paid
 * edition. Carbon's Complex field nests, it is MIT, and it is defined only in
 * code, which is what lets the whole editing surface bake into an immutable
 * image rather than being clicked together in an admin nobody can diff.
 *
 * Field names carry a `phat_` prefix so the meta keys are legible in the
 * database and cannot collide with core or a future plugin.
 */
final class Fields
{
    private const ALLERGENS = ['Lactose', 'Gluten', 'Wheat', 'Nuts', 'Soy'];
    private const AMENITIES = [
        'Beer garden', 'Kids zone', 'Arcade games', 'Dog friendly', 'Ocean views',
        'Live music', 'Wheelchair accessible', 'Parking', 'Function spaces', 'Fresh seafood',
        'Family friendly', 'Sports screens', 'Outdoor seating',
    ];

    /**
     * Fixed lists, kept as select options rather than taxonomies.
     *
     * Payload declares these as hard-coded option lists, and the front end maps
     * each one to a specific icon. A taxonomy would let an editor invent an
     * eighth amenity that renders as a blank space, and would add a join to
     * every query to gain a UI Payload does not have.
     */
    /**
     * Payload's four beer categories, and the labels the site groups them under.
     */
    private const BEER_CATEGORIES = [
        'core' => 'Core range',
        'seasonal' => 'Seasonal',
        'limited' => 'Limited release',
        'collab' => 'Collaboration',
    ];

    /**
     * The seven days, in the order the front end's hours table renders them.
     */
    private const DAYS = [
        'mon' => 'Monday',
        'tue' => 'Tuesday',
        'wed' => 'Wednesday',
        'thu' => 'Thursday',
        'fri' => 'Friday',
        'sat' => 'Saturday',
        'sun' => 'Sunday',
    ];
    private const EVENT_CATEGORIES = [
        'Quiz' => 'Quiz',
        'Live music' => 'Live music',
        'Food special' => 'Food special',
        'Beer release' => 'Beer release',
        'Sport' => 'Sport',
        'Competition' => 'Competition',
        'Other' => 'Other',
    ];
    private const RECURRENCE = [
        'once' => 'One-off',
        'weekly' => 'Every week',
        'fortnightly' => 'Every fortnight',
        'monthly' => 'Every month',
    ];
    private const SEATING = [
        'standing' => 'Standing',
        'seated' => 'Seated',
        'mixed' => 'Mixed',
    ];

    public static function register(): void
    {
        self::venue();
        self::beer();
        self::event();
        self::merch();
        self::functionPackage();
        self::tapList();
        self::menu();
    }

    private static function beer(): void
    {
        Container::make('post_meta', 'Beer')
            ->where('post_type', '=', PostTypes::BEER)
            ->add_tab('The beer', [
                Field::make('text', 'phat_style', 'Style')->set_required(true)->set_width(50),
                // Text with a number input rather than a numeric field: Carbon
                // has no decimal type, and ABV is written as 4.2 not 4.
                Field::make('text', 'phat_abv', 'ABV %')
                    ->set_required(true)
                    ->set_attribute('type', 'number')
                    ->set_attribute('step', '0.1')
                    ->set_width(25),
                FieldFactory::number('phat_ibu', 'IBU')->set_width(25),
                FieldFactory::select('phat_category', 'Category', self::BEER_CATEGORIES)
                    ->set_default_value('core'),
                Field::make('textarea', 'phat_description', 'Description'),
                Field::make('rich_text', 'phat_tasting_notes', 'Tasting notes'),
                FieldFactory::multiselect(
                    'phat_allergens',
                    'Allergens',
                    array_combine(self::ALLERGENS, self::ALLERGENS),
                ),
            ])
            ->add_tab('Pictures', [
                Field::make('image', 'phat_can_artwork', 'Can artwork'),
                Field::make('media_gallery', 'phat_gallery', 'Gallery'),
                Field::make('text', 'phat_video_id', 'YouTube video id')
                    ->set_help_text('Just the id, not the whole URL.'),
            ])
            ->add_tab('Buying it', [
                Field::make('text', 'phat_price', 'Price (AUD)')
                    ->set_attribute('type', 'number')
                    ->set_attribute('step', '0.01')
                    ->set_width(50),
                Field::make('text', 'phat_pack_size', 'Pack size')
                    ->set_attribute('placeholder', '16 x 375ml cans')
                    ->set_width(50),
                Field::make('text', 'phat_shop_url', 'Shop URL')->set_width(50),
                Field::make('text', 'phat_untappd_url', 'Untappd URL')->set_width(50),
                FieldFactory::association('phat_available_at', 'Pouring at', PostTypes::VENUE),
            ])
            ->add_tab('Who made it', [
                FieldFactory::complex('phat_ingredients', 'Local producers')
                    ->add_fields([
                        Field::make('text', 'producer', 'Producer')->set_required(true)->set_width(50),
                        Field::make('text', 'contribution', 'What they supplied')->set_width(50),
                    ]),
            ])
            ->add_tab('Search and social', self::seo());
    }

    private static function event(): void
    {
        Container::make('post_meta', 'Event')
            ->where('post_type', '=', PostTypes::EVENT)
            ->add_tab('When and where', [
                Field::make('date_time', 'phat_starts_at', 'Starts')->set_required(true)->set_width(50),
                Field::make('date_time', 'phat_ends_at', 'Ends')->set_width(50),
                FieldFactory::select('phat_recurrence', 'Repeats', self::RECURRENCE)
                    ->set_default_value('once')
                    ->set_width(50),
                // Only meaningful for a repeating event, and Carbon shows it
                // regardless — the read API ignores it when recurrence is once.
                Field::make('date', 'phat_repeats_until', 'Repeats until')->set_width(50),
                FieldFactory::association('phat_venues', 'Venues', PostTypes::VENUE),
                FieldFactory::select('phat_category', 'Category', self::EVENT_CATEGORIES),
            ])
            ->add_tab('Details', [
                Field::make('image', 'phat_hero_image', 'Poster'),
                Field::make('rich_text', 'phat_body', 'About this event'),
            ])
            ->add_tab('Cost', [
                Field::make('checkbox', 'phat_is_free', 'Free entry')->set_default_value(true),
                Field::make('text', 'phat_price', 'Admission')
                    ->set_help_text('Only when there is a door charge.'),
                // Separate from admission on purpose: "$25 for Mega Burger
                // Monday" is the price of a burger, not the price of getting in,
                // and labelling it "entry" told people the wrong thing.
                Field::make('text', 'phat_price_note', 'What it costs')
                    ->set_help_text('For a food or drink special — what the money buys.'),
                Field::make('text', 'phat_booking_url', 'Booking URL'),
            ])
            ->add_tab('Search and social', self::seo());
    }

    private static function functionPackage(): void
    {
        Container::make('post_meta', 'Function space')
            ->where('post_type', '=', PostTypes::FUNCTION_PACKAGE)
            ->add_fields([
                FieldFactory::association('phat_venue', 'Venue', PostTypes::VENUE, 1),
                FieldFactory::number('phat_capacity', 'Capacity')->set_width(33),
                FieldFactory::select('phat_seating', 'Seating', self::SEATING)->set_width(33),
                Field::make('text', 'phat_price_guide', 'Price guide')
                    ->set_attribute('placeholder', 'From $45 pp')
                    ->set_width(34),
                Field::make('image', 'phat_image', 'Photograph'),
                Field::make('rich_text', 'phat_description', 'Description'),
                FieldFactory::complex('phat_inclusions', 'What is included')
                    ->add_fields([Field::make('text', 'item', 'Item')->set_required(true)]),
                Field::make('file', 'phat_brochure', 'Brochure (PDF)'),
            ]);
    }

    private static function menu(): void
    {
        Container::make('post_meta', 'Menu')
            ->where('post_type', '=', PostTypes::MENU)
            ->add_fields([
                FieldFactory::association('phat_venue', 'Venue', PostTypes::VENUE, 1),
                Field::make('text', 'phat_meandu_id', 'me&u id'),
                FieldFactory::complex('phat_sections', 'Sections')
                    ->add_fields([
                        Field::make('text', 'name', 'Section'),
                        FieldFactory::complex('items', 'Items')
                            ->add_fields([
                                Field::make('text', 'name', 'Name')->set_width(50),
                                Field::make('text', 'price', 'Price')->set_width(25),
                                Field::make('text', 'dietary', 'Dietary codes')->set_width(25),
                                Field::make('textarea', 'description', 'Description'),
                                Field::make('image', 'image', 'Photograph'),
                                Field::make('text', 'image_credit', 'Photo credit'),
                            ]),
                    ])
                    ->set_help_text('Synced from me&u. Edits here are overwritten on the next sync.'),
            ]);
    }

    private static function merch(): void
    {
        Container::make('post_meta', 'Merch')
            ->where('post_type', '=', PostTypes::MERCH)
            ->add_fields([
                Field::make('text', 'phat_price', 'Price (AUD)')
                    ->set_attribute('type', 'number')
                    ->set_attribute('step', '0.01')
                    ->set_width(50),
                Field::make('checkbox', 'phat_sold_out', 'Sold out')->set_width(50),
                Field::make('text', 'phat_shop_url', 'Shop URL')
                    ->set_help_text('Leave blank for something only sold in the venues.'),
                Field::make('textarea', 'phat_description', 'Description'),
                Field::make('media_gallery', 'phat_images', 'Images')
                    ->set_help_text('The first one is used on the shop grid.'),
            ]);
    }

    /**
     * The per-document SEO group, reused by every type that has one.
     *
     * @return list<CarbonField>
     */
    private static function seo(): array
    {
        return [
            // The limits are the ones Google truncates at, and Payload enforces
            // the same two numbers.
            FieldFactory::limitedText('text', 'phat_seo_title', 'Title', 65)
                ->set_help_text('Blank falls back to the title above.'),
            FieldFactory::limitedText('textarea', 'phat_seo_description', 'Description', 165),
            Field::make('image', 'phat_seo_image', 'Share image'),
        ];
    }

    private static function tapList(): void
    {
        Container::make('post_meta', 'Tap list')
            ->where('post_type', '=', PostTypes::TAP_LIST)
            ->add_fields([
                FieldFactory::association('phat_venue', 'Venue', PostTypes::VENUE, 1)->set_required(true),
                Field::make('text', 'phat_source', 'Source')
                    ->set_help_text('"meandu" when synced, "manual" when edited here.'),
                Field::make('text', 'phat_synced_at', 'Last synced'),
                FieldFactory::complex('phat_taps', 'Taps')
                    ->set_layout('tabbed-vertical')
                    ->add_fields([
                        FieldFactory::number('tap_number', 'Tap')->set_width(20),
                        // Optional on purpose. A blank beer means a guest keg,
                        // which has no record of its own and would otherwise be
                        // dropped from the list entirely.
                        FieldFactory::association('beer', 'Beer', PostTypes::BEER, 1),
                        Field::make('text', 'guest_name', 'Guest beer name')->set_width(40),
                        Field::make('text', 'guest_style', 'Guest beer style')->set_width(40),
                        Field::make('text', 'price', 'Price')->set_width(20),
                        Field::make('checkbox', 'keg_blown', 'Keg blown'),
                    ]),
            ]);
    }

    private static function venue(): void
    {
        Container::make('post_meta', 'Venue')
            ->where('post_type', '=', PostTypes::VENUE)
            ->add_tab('Finding us', [
                Field::make('text', 'phat_short_name', 'Short name')
                    ->set_required(true)
                    ->set_help_text('Used wherever the full name is too long — buttons, cards, the footer.'),
                Field::make('text', 'phat_street', 'Street')->set_required(true)->set_width(50),
                Field::make('text', 'phat_suburb', 'Suburb')->set_required(true)->set_width(50),
                Field::make('text', 'phat_state', 'State')->set_default_value('WA')->set_width(50),
                Field::make('text', 'phat_postcode', 'Postcode')->set_required(true)->set_width(50),
                // Without both, the map renders nothing at all rather than a
                // wrong pin — which is the right failure, but a silent one.
                Field::make('text', 'phat_latitude', 'Latitude')->set_width(50),
                Field::make('text', 'phat_longitude', 'Longitude')->set_width(50),
                Field::make('text', 'phat_transport_note', 'Getting here'),
                Field::make('text', 'phat_phone', 'Phone')->set_width(50),
                Field::make('text', 'phat_email', 'Email')->set_width(50),
            ])
            ->add_tab('Hours', [
                Field::make('text', 'phat_hours_label', 'Season label')
                    ->set_help_text('For example "Spring/Summer". Shown above the hours table.'),
                Field::make('text', 'phat_public_holiday_note', 'Public holiday note'),
                FieldFactory::complex('phat_opening_hours', 'Opening hours')
                    ->set_layout('tabbed-horizontal')
                    ->add_fields([
                        FieldFactory::select('day', 'Day', self::DAYS)->set_width(25),
                        Field::make('text', 'opens', 'Opens')->set_attribute('placeholder', '11:00')->set_width(25),
                        Field::make('text', 'closes', 'Closes')->set_attribute('placeholder', '23:00')->set_width(25),
                        Field::make('checkbox', 'closed', 'Closed')->set_width(25),
                    ]),
                FieldFactory::complex('phat_hours_overrides', 'Special hours')
                    ->add_fields([
                        Field::make('date', 'date', 'Date')->set_required(true)->set_width(25),
                        Field::make('text', 'label', 'Label')->set_width(25),
                        Field::make('text', 'opens', 'Opens')->set_width(20),
                        Field::make('text', 'closes', 'Closes')->set_width(20),
                        Field::make('checkbox', 'closed', 'Closed')->set_width(10),
                    ])
                    ->set_help_text('One-off changes — a public holiday, a private function. These win over the weekly hours for that date.'),
            ])
            ->add_tab('Presentation', [
                Field::make('image', 'phat_hero_image', 'Hero image'),
                Field::make('media_gallery', 'phat_gallery', 'Gallery'),
                Field::make('textarea', 'phat_intro', 'Intro')
                    ->set_help_text('One short paragraph under the venue name. Plain text.'),
                FieldFactory::multiselect(
                    'phat_amenities',
                    'Amenities',
                    array_combine(self::AMENITIES, self::AMENITIES),
                ),
                FieldFactory::number('phat_capacity', 'Capacity')->set_width(50),
                FieldFactory::number('phat_tap_count', 'Taps', 20)->set_width(50),
                FieldFactory::complex('phat_faqs', 'Frequently asked questions')
                    ->add_fields([
                        Field::make('text', 'question', 'Question')->set_required(true),
                        Field::make('textarea', 'answer', 'Answer')->set_required(true),
                    ]),
            ])
            ->add_tab('Integrations', [
                Field::make('text', 'phat_maps_query', 'Google Maps search')
                    ->set_help_text('What to search for on the map. Defaults to the name and address if blank.'),
                Field::make('text', 'phat_instagram', 'Instagram')
                    ->set_help_text('Full URL. Each venue has its own account.'),
                Field::make('file', 'phat_functions_pack', 'Functions pack (PDF)'),
                Field::make('text', 'phat_booking_url', 'Booking URL')
                    ->set_help_text('The nowbookit widget URL. Embedded on the site, so keep its colour parameters.'),
                Field::make('text', 'phat_meandu_slug', 'me&u slug')->set_width(50),
                Field::make('text', 'phat_menu_url', 'me&u menu URL')->set_width(50),
            ])
            ->add_tab('Search and social', self::seo());
    }
}
