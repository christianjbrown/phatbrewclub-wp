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
    /**
     * Paired with crop false this is a ceiling, not a target: "scale to the
     * given width, whatever height that produces".
     */
    private const HEIGHT_CEILING = 9999;
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
                add_image_size($name, $width, self::HEIGHT_CEILING, false);
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

        /*
         * Make core's idea of `thumbnail` agree with the ladder's.
         *
         * `thumbnail` is one of WordPress's own sizes, and core takes its
         * dimensions from options rather than from add_image_size. Generation
         * used the registered 400 and produced the right file, but every read
         * went through image_constrain_size_for_editor, which clamps a core
         * size to its option box — so the site advertised the 400px file as
         * 150w, or 84w for a portrait, and browsers dutifully skipped it for a
         * larger rung. Nothing looked wrong; the pages were simply heavier than
         * the ones next door.
         *
         * Filtering the options rather than writing them keeps the ladder in
         * one place: the numbers cannot drift apart, because there is only one
         * set of them.
         */
        add_filter('pre_option_thumbnail_size_w', static fn (): int => self::SIZES['thumbnail']);
        add_filter('pre_option_thumbnail_size_h', static fn (): int => self::HEIGHT_CEILING);
        add_filter('pre_option_thumbnail_crop', static fn (): int => 0);

        /*
         * Generate a rung whose width exactly equals the original's.
         *
         * WordPress skips a derivative when it would come out the same size as
         * the source, which is right when the derivative would be a copy — but
         * here it is not a copy, it is the WebP conversion, and skipping it left
         * a 1600x900 hero with no hero rung at all. The page then fell back to
         * the 800 one, so the Payload site served a 1600px social card and this
         * one served half that, from the same image.
         *
         * Only exact equality is overridden. A source narrower than a rung
         * still produces no rung, because that would be an upscale and the
         * Payload side does not do it either — a 1080px original has four
         * derivatives on both sites.
         *
         * @param array<int, int>|null $output
         *
         * @return array<int, int>|null
         */
        add_filter(
            'image_resize_dimensions',
            static function (?array $output, int $origW, int $origH, int $destW, int $destH, bool $crop): ?array {
                if (null !== $output || $crop || $destW !== $origW) {
                    return $output;
                }

                // dst_x, dst_y, src_x, src_y, dst_w, dst_h, src_w, src_h.
                return [0, 0, 0, 0, $origW, $origH, $origW, $origH];
            },
            10,
            6,
        );

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
