<?php

declare(strict_types=1);

namespace ChristianBrown\PhatWp;

/**
 * Serve uploads from the bucket rather than through PHP.
 *
 * The uploads directory is a Cloud Storage bucket mounted with FUSE, so
 * WordPress writes to it as if it were local disk. Reading it back the same way
 * would put a PHP process in front of every image on the site. The bucket is
 * public, so the URL is rewritten to its own origin and the images never touch
 * the container at all.
 *
 * WP_UPLOADS_URL is carried in as a constant by wp-config.php, which runs before
 * add_filter exists.
 */
final class Uploads
{
    public static function register(): void
    {
        $configured = defined('PHAT_UPLOADS_URL') ? constant('PHAT_UPLOADS_URL') : null;

        if (!is_string($configured) || '' === $configured) {
            return;
        }

        $base = mb_rtrim($configured, '/');

        add_filter('upload_dir', static function (array $dirs) use ($base): array {

            $dirs['baseurl'] = $base;
            $dirs['url'] = $base.(is_string($dirs['subdir'] ?? null) ? $dirs['subdir'] : '');

            return $dirs;
        });
    }
}
