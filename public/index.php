<?php

/**
 * Front controller.
 *
 * WordPress core lives in public/wp because Composer owns it — johnpbloch's
 * installer replaces that whole directory on every update, so nothing of ours
 * can live inside it. wp-content sits beside core rather than under it for the
 * same reason.
 */

declare(strict_types=1);

define('WP_USE_THEMES', true);

require __DIR__ . '/wp/wp-blog-header.php';
