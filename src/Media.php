<?php

declare(strict_types=1);

namespace ChristianBrown\PhatWp;

/**
 * The image ladder, matching the Payload version rung for rung.
 *
 * Two things have to be corrected about WordPress's defaults for the front end
 * to behave identically.
 *
 * **Cropping.** `add_image_size` with a width and a height crops to fit, and
 * WordPress's own defaults do exactly that. The Payload side learned this the
 * hard way: the event and news artwork is portrait — one quiz poster is
 * 989x1400 — and squaring it threw away the half of the poster carrying the
 * day, the time and the host. Every size here is width-only, so a derivative
 * may be smaller than the original but never says less than it.
 *
 * **Format.** WordPress emits JPEG derivatives from a JPEG original. The front
 * end's srcset assumes WebP, and the whole transfer-size difference the site was
 * measured on depends on it, so the output format is filtered.
 *
 * The master upload is left exactly as it was uploaded. It is never served —
 * every surface asks for a derivative — so the only cost is storage.
 */
final class Media
{
    /**
     * Matches the Payload side: 80 for the working sizes, 78 for the largest.
     */
    private const QUALITY = 80;
    private const QUALITY_HERO = 78;

    /**
     * Width in pixels, keyed by the name the front end asks for.
     *
     * The bottom rung matters as much as the top: with 400 as the smallest, the
     * menu thumbnails shipped 400px into a 112px slot. 240 covers that at 2x.
     *
     * @var array<string, int>
     */
    private const SIZES = [
        'micro' => 240,
        'thumbnail' => 400,
        'small' => 600,
        'card' => 800,
        'hero' => 1600,
    ];

    /**
     * The largest rung, for callers that want the quality knob rather than the list.
     */
    public static function heroQuality(): int
    {
        return self::QUALITY_HERO;
    }

    public static function register(): void
    {
        add_action('after_setup_theme', static function (): void {
            add_theme_support('post-thumbnails');

            foreach (self::SIZES as $name => $width) {
                // The 9999 is a height ceiling, not a target: paired with false
                // it means "scale to this width, whatever height that gives".
                add_image_size($name, $width, 9999, false);
            }
        });

        /*
         * Keep the uploaded file exactly as uploaded.
         *
         * Since 5.3 WordPress silently downscales anything wider than 2560px
         * and stores the *scaled* copy as the original, keeping the real one
         * only as `-scaled`. Payload stores the master untouched. Without this
         * the two media libraries diverge at the source, and every derivative
         * on this side is generated from an already-resampled intermediate.
         */
        add_filter('big_image_size_threshold', '__return_false');

        /*
         * WordPress's own thumbnail, medium, medium_large, large, 1536 and 2048
         * would otherwise be generated alongside ours — eleven derivatives per
         * upload instead of five, for sizes nothing on the site ever asks for.
         *
         * `intermediate_image_sizes_advanced` rather than the simpler
         * `intermediate_image_sizes`: this is the one wp_generate_attachment_metadata
         * actually consults, and it carries the dimensions, so a size added by
         * some other filter cannot slip past.
         *
         * @param array<string, array{width: int, height: int, crop: bool}> $sizes
         *
         * @return array<string, array{width: int, height: int, crop: bool}>
         */
        add_filter('intermediate_image_sizes_advanced', static fn (array $sizes): array => array_intersect_key(
            $sizes,
            self::SIZES,
        ));

        add_filter(
            'image_editor_output_format',
            static fn (): array => [
                'image/jpeg' => 'image/webp',
                'image/png' => 'image/webp',
            ],
        );

        add_filter(
            'wp_editor_set_quality',
            static fn (int $quality, string $mime): int => 'image/webp' === $mime ? self::QUALITY : $quality,
            10,
            2,
        );
    }

    /**
     * @return array<string, int>
     */
    public static function sizes(): array
    {
        return self::SIZES;
    }
}
