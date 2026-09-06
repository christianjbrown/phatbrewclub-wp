<?php

declare(strict_types=1);

namespace ChristianBrown\PhatWp;

use WP_Post;

/**
 * Tell the website a publish happened.
 *
 * The website tags every read of this API and exposes /api/revalidate to clear
 * those tags. Without this, an edit here waits out the full 300-second cache
 * window before it appears — which looks like WordPress being slow rather than
 * a missing webhook, and would have made the comparison against the Payload
 * side dishonest in WordPress's disfavour.
 *
 * Two decisions worth recording.
 *
 * **It fires on shutdown, not during the save.** WordPress admin saves end in a
 * redirect, so by the time shutdown runs the editor's browser is already
 * navigating and nothing is waiting on this. That is what makes it safe to send
 * the request blocking, rather than firing and hoping.
 *
 * **It retries once.** The website scales to zero, and a cold instance takes
 * around 3.6 seconds to answer where a warm one takes half a second. A single
 * short timeout therefore fails exactly when the cache most needs clearing —
 * after a quiet period — which is the bug the Payload side shipped with. The
 * first attempt is short because warm is the common case; when it times out,
 * that request has already started the container, so the second lands on an
 * instance on its way up.
 */
final class Revalidate
{
    /**
     * Post type to the cache tag the website uses for it.
     *
     * @var array<string, string>
     */
    private const TAGS = [
        PostTypes::VENUE => 'venues',
        PostTypes::BEER => 'beers',
        PostTypes::EVENT => 'events',
        PostTypes::MERCH => 'merch',
        PostTypes::FUNCTION_PACKAGE => 'function-packages',
        PostTypes::MENU => 'menus',
        PostTypes::TAP_LIST => 'tap-lists',
        'post' => 'posts',
        'page' => 'pages',
    ];

    /**
     * @var list<string> collected during the request, sent once on shutdown
     */
    private static array $pending = [];

    public static function flush(): void
    {
        $tags = array_unique(self::$pending);
        self::$pending = [];

        if ([] === $tags) {
            return;
        }

        $url = getenv('WEB_REVALIDATE_URL');
        $secret = getenv('REVALIDATE_SECRET');

        if (!is_string($url) || '' === $url || !is_string($secret) || '' === $secret) {
            return;
        }

        foreach ($tags as $tag) {
            self::send($url, $secret, $tag);
        }
    }

    public static function register(): void
    {
        // Covers publishing, unpublishing and trashing: a page pulled down has
        // to leave the site as surely as one put up.
        add_action('transition_post_status', static function (string $new, string $old, WP_Post $post): void {
            if ('publish' === $new || 'publish' === $old) {
                self::queue(self::tagFor($post));
            }
        }, 10, 3);

        add_action('deleted_post', static function (int $id, WP_Post $post): void {
            self::queue(self::tagFor($post));
        }, 10, 2);

        /*
         * Media clears everything, deliberately.
         *
         * Replacing an image changes no post, so nothing else here fires and
         * every page keeps the old URL. It is rare, and a wrong image is more
         * visible than a slow refresh. The Payload side does the same.
         */
        add_action('add_attachment', static fn () => self::queue('all'));
        add_action('attachment_updated', static fn () => self::queue('all'));
        add_action('delete_attachment', static fn () => self::queue('all'));

        // Carbon fires this after the Site settings page is saved.
        add_action('carbon_fields_theme_options_container_saved', static fn () => self::queue('settings'));

        add_action('shutdown', [self::class, 'flush'], 999);
    }

    private static function queue(?string $tag): void
    {
        if (null !== $tag) {
            self::$pending[] = $tag;
        }
    }

    private static function send(string $url, string $secret, string $tag): void
    {
        $args = [
            'headers' => ['content-type' => 'application/json', 'x-revalidate-secret' => $secret],
            'body' => (string) wp_json_encode(['collection' => $tag]),
            'blocking' => true,
        ];

        $first = wp_remote_post($url, $args + ['timeout' => 2]);

        if (!is_wp_error($first)) {
            return;
        }

        // The first attempt has already woken the container; this one lands on
        // an instance on its way up rather than paying the cold start again.
        wp_remote_post($url, $args + ['timeout' => 4]);
    }

    /**
     * The tag for a post, or null for something the website never reads —
     * a revision, an autosave, or a post type that is not part of the site.
     */
    private static function tagFor(WP_Post $post): ?string
    {
        if (false !== wp_is_post_revision($post) || false !== wp_is_post_autosave($post)) {
            return null;
        }

        return self::TAGS[$post->post_type] ?? null;
    }
}
