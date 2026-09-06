<?php

declare(strict_types=1);

namespace ChristianBrown\PhatWp;

use Carbon_Fields\Carbon_Fields;
use ChristianBrown\PhatWp\Cli\Import;
use WP_CLI;

/**
 * The plugin's single entry point.
 *
 * Registration order matters and is the reason this is a class rather than a
 * pile of add_action calls in a file: post types must exist before Carbon
 * Fields attaches containers to them, and Carbon Fields must have booted before
 * any field is declared.
 */
final class Plugin
{
    public static function boot(): void
    {
        // Carbon Fields insists on after_setup_theme, and on being booted before
        // any container is declared. Its own docs are quiet about the ordering;
        // declaring a field first fails with an unrelated-looking container error.
        add_action('after_setup_theme', static fn () => Carbon_Fields::boot(), 1);

        add_action('init', [PostTypes::class, 'register'], 5);
        add_action('carbon_fields_register_fields', [Fields::class, 'register']);
        add_action('carbon_fields_register_fields', [Blocks::class, 'register']);
        add_action('carbon_fields_register_fields', [Settings::class, 'register']);

        Uploads::register();
        Media::register();
        Rest::register();

        /**
         * On cli_init, not inline.
         *
         * Registering during the mu-plugin's own load was too early: WP-CLI
         * resolves the command it was given before WordPress has finished
         * booting, so `wp phat import` came back as "not a registered wp
         * command" while the same class worked perfectly over HTTP. cli_init
         * fires once WordPress is up and is the documented place for this.
         *
         * Guarded so a web request never loads a class that exists only to talk
         * to another site's API.
         */
        if (defined('WP_CLI') && WP_CLI) {
            add_action('cli_init', static function (): void {
                WP_CLI::add_command('phat import', new Import());
            });
        }
    }
}
