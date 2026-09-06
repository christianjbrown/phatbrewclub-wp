<?php

/**
 * WordPress configuration, driven entirely by environment.
 *
 * Nothing secret is written here and nothing is written by the installer: the
 * container is immutable and the database credentials and salts arrive from
 * Secret Manager. That is why DISALLOW_FILE_MODS is on — plugins and themes are
 * baked into the image and changed by deploy, so the admin's install and edit
 * screens would only ever offer to write to a filesystem that resets.
 */

declare(strict_types=1);

/**
 * Read a required environment variable, or fail loudly.
 *
 * Silently defaulting a database name or a salt is how you end up with a second
 * WordPress quietly installing itself over the top of the first.
 */
function phat_env(string $name, ?string $default = null): string
{
    $value = getenv($name);

    if ($value === false || $value === '') {
        if ($default !== null) {
            return $default;
        }

        throw new RuntimeException(sprintf('Required environment variable %s is not set.', $name));
    }

    return $value;
}

define('DB_NAME', phat_env('WP_DB_NAME', 'wordpress'));
define('DB_USER', phat_env('WP_DB_USER', 'wordpress'));
define('DB_PASSWORD', phat_env('WP_DB_PASSWORD'));

/**
 * On Cloud Run the database is reached through the Cloud SQL unix socket mounted
 * at /cloudsql, not over TCP. mysqli reads a leading "localhost:" as "use the
 * socket that follows", which is why the host looks odd.
 */
define('DB_HOST', phat_env('WP_DB_HOST', '127.0.0.1'));
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');

/**
 * Eight salts from one secret.
 *
 * They are stored as a single JSON object rather than eight separate secrets so
 * that rotating them is one edit and one revision, and so a half-rotated set —
 * which logs everybody out in a way that looks like a bug rather than a change —
 * cannot happen.
 */
$phatSalts = json_decode(phat_env('WP_SALTS', '{}'), true);

if (!is_array($phatSalts)) {
    throw new RuntimeException('WP_SALTS is not valid JSON.');
}

foreach ([
    'AUTH_KEY',
    'SECURE_AUTH_KEY',
    'LOGGED_IN_KEY',
    'NONCE_KEY',
    'AUTH_SALT',
    'SECURE_AUTH_SALT',
    'LOGGED_IN_SALT',
    'NONCE_SALT',
] as $phatSaltName) {
    define($phatSaltName, (string) ($phatSalts[$phatSaltName] ?? 'insecure-local-development-only'));
}

$table_prefix = 'wp_';

define('WP_HOME', phat_env('WP_HOME', 'http://localhost:8090'));
define('WP_SITEURL', WP_HOME . '/wp');

/**
 * Content lives beside core, not inside it, so a Composer core update cannot
 * take our plugins and uploads with it.
 */
define('WP_CONTENT_DIR', __DIR__ . '/wp-content');
define('WP_CONTENT_URL', WP_HOME . '/wp-content');

/**
 * Uploads are a Cloud Storage bucket mounted with FUSE, so WordPress writes to
 * what looks like a local path while the objects land in the bucket. Serving
 * them is the bucket's job, not ours — UPLOADS_URL points at its public origin
 * so an image on the site never makes a round trip through PHP.
 */
/**
 * No UPLOADS constant, deliberately.
 *
 * It looks like the obvious way to name the uploads directory and it is a trap
 * here: WordPress resolves UPLOADS against ABSPATH, which is public/wp because
 * core lives in its own directory — not against WP_CONTENT_DIR. So defining it
 * sent every upload to public/wp/wp-content/uploads, inside core and nowhere
 * near the Cloud Storage mount, where it lived on the container's own disk
 * until the container went away. The media library listed 137 images and the
 * bucket held none.
 *
 * Left undefined, wp_upload_dir() uses WP_CONTENT_DIR . '/uploads', which is
 * the mount.
 */

/**
 * The rewrite itself is a filter, and filters do not exist yet at this point in
 * the boot — wp-includes/plugin.php is loaded by wp-settings.php, below. So the
 * value is only carried here and applied in the mu-plugin.
 */
define('PHAT_UPLOADS_URL', getenv('WP_UPLOADS_URL') ?: '');

/**
 * Cloud Run terminates TLS at the edge and forwards plain HTTP, so without this
 * WordPress decides the request was insecure, redirects to https, and loops.
 */
if (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') {
    $_SERVER['HTTPS'] = 'on';
}

define('FORCE_SSL_ADMIN', str_starts_with(WP_HOME, 'https://'));

/**
 * No stored HTML that the site cannot render.
 *
 * On a single site, administrators hold unfiltered_html and their content is
 * NOT run through wp_kses on save, so a script tag typed into the editor is
 * stored verbatim. The read API runs everything through a seven-tag allow-list
 * on the way out, but the front end renders that HTML with
 * dangerouslySetInnerHTML, so it is worth nothing dangerous ever being written
 * down either.
 */
define('DISALLOW_UNFILTERED_HTML', true);

/** The image is immutable. Nothing may write to it. */
define('DISALLOW_FILE_MODS', true);
define('DISALLOW_FILE_EDIT', true);
define('AUTOMATIC_UPDATER_DISABLED', true);

/**
 * This install is headless: the front end is a separate Next.js service that
 * reads the REST API. WordPress's own cron would only ever fire on a request
 * that happened to arrive, which on a scale-to-zero service is no schedule at
 * all — the scheduled work runs as Cloud Run jobs instead.
 */
define('DISABLE_WP_CRON', true);

define('WP_DEBUG', getenv('WP_DEBUG') === 'true');
define('WP_DEBUG_DISPLAY', false);
define('WP_DEBUG_LOG', false);

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/wp/');
}

require_once ABSPATH . 'wp-settings.php';
