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
    /**
     * Fixed lists, kept as select options rather than taxonomies.
     *
     * Payload declares these as hard-coded option lists, and the front end maps
     * each one to a specific icon. A taxonomy would let an editor invent an
     * eighth amenity that renders as a blank space, and would add a join to
     * every query to gain a UI Payload does not have.
     */
    private const AMENITIES = [
        'Beer garden', 'Kids zone', 'Arcade games', 'Dog friendly', 'Ocean views',
        'Live music', 'Wheelchair accessible', 'Parking', 'Function spaces', 'Fresh seafood',
        'Family friendly', 'Sports screens', 'Outdoor seating',
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

    public static function register(): void
    {
        self::venue();
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
