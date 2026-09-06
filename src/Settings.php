<?php

declare(strict_types=1);

namespace ChristianBrown\PhatWp;

use Carbon_Fields\Field;

/**
 * Site settings: the announcement bar, the navigation, the social links and the
 * SEO defaults.
 *
 * Everything here is read by the website. That is worth saying because on the
 * Payload side it was not true for a while — six fields on this screen had no
 * code behind them, so editing them changed nothing with no way to tell from the
 * admin, which is worse than the field being absent.
 *
 * Instagram is deliberately not here. The two venues run separate accounts, so
 * the footer builds its Instagram links from the venues and a single site-wide
 * field was the one thing on this screen nobody could make sense of.
 */
final class Settings
{
    public static function register(): void
    {
        FieldFactory::themeOptions('Site settings')
            ->set_page_menu_position(59)
            ->add_tab('Announcement', [
                Field::make('text', 'phat_announcement', 'Message')
                    ->set_help_text('A bar across the top of every page. Blank hides it.'),
                Field::make('text', 'phat_announcement_url', 'Link')
                    ->set_help_text('Optional. Makes the whole bar a link.'),
                Field::make('date', 'phat_announcement_until', 'Until')
                    ->set_help_text('It takes itself down after this date.'),
            ])
            ->add_tab('Navigation', [
                FieldFactory::complex('phat_main_nav', 'Links')
                    ->add_fields([
                        Field::make('text', 'label', 'Label')->set_required(true)->set_width(40),
                        Field::make('text', 'url', 'URL')->set_required(true)->set_width(60),
                        FieldFactory::complex('children', 'Drop-down items')
                            ->add_fields([
                                Field::make('text', 'label', 'Label')->set_required(true)->set_width(40),
                                Field::make('text', 'url', 'URL')->set_required(true)->set_width(60),
                            ]),
                    ])
                    ->set_help_text('Leave empty to use the built-in menu. Drag to reorder.'),
                Field::make('text', 'phat_booking_label', 'Booking button')->set_default_value('Book'),
            ])
            ->add_tab('Social', [
                Field::make('text', 'phat_facebook', 'Facebook'),
                Field::make('text', 'phat_tiktok', 'TikTok'),
                Field::make('text', 'phat_youtube', 'YouTube'),
                Field::make('text', 'phat_untappd', 'Untappd'),
            ])
            ->add_tab('SEO defaults', [
                Field::make('text', 'phat_default_title', 'Title'),
                Field::make('textarea', 'phat_default_description', 'Description'),
                Field::make('image', 'phat_default_image', 'Share image')
                    ->set_help_text('Landscape, at least 1200px wide.'),
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function shape(): array
    {
        $nav = [];

        foreach (Val::rows(carbon_get_theme_option('phat_main_nav')) as $row) {
            $children = [];

            foreach (Val::rows($row['children'] ?? null) as $child) {
                $children[] = [
                    'label' => Val::text($child['label'] ?? null),
                    'url' => Val::text($child['url'] ?? null),
                ];
            }

            $nav[] = [
                'label' => Val::text($row['label'] ?? null),
                'url' => Val::text($row['url'] ?? null),
                'children' => $children,
            ];
        }

        return [
            'announcement' => self::opt('phat_announcement'),
            'announcementUrl' => self::opt('phat_announcement_url'),
            'announcementUntil' => self::opt('phat_announcement_until'),
            'mainNav' => $nav,
            'bookingLabel' => self::opt('phat_booking_label'),
            'facebook' => self::opt('phat_facebook'),
            'tiktok' => self::opt('phat_tiktok'),
            'youtube' => self::opt('phat_youtube'),
            'untappd' => self::opt('phat_untappd'),
            'defaultTitle' => self::opt('phat_default_title'),
            'defaultDescription' => self::opt('phat_default_description'),
            'defaultImage' => Shape::media(carbon_get_theme_option('phat_default_image')),
        ];
    }

    private static function opt(string $name): ?string
    {
        return Val::str(carbon_get_theme_option($name));
    }
}
