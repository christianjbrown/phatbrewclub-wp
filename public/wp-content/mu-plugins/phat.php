<?php

/**
 * Plugin Name: Phat Brew Club
 * Description: Content model and headless API for the Phat Brew Club site.
 * Author: Christian Brown
 * Version: 1.0.0
 *
 * A must-use plugin rather than a normal one, deliberately. Everything here is
 * structural — the post types, the fields, the API the front end reads — and
 * none of it is something an editor should be able to switch off from the
 * Plugins screen. There is no Plugins screen worth speaking of anyway: the
 * image is immutable and DISALLOW_FILE_MODS is set.
 *
 * This file is only a loader. The code lives in src/ under PSR-4 so it can be
 * namespaced, statically analysed and unit tested like the rest of the estate,
 * which none of it could be sitting loose in wp-content.
 */

declare(strict_types=1);

$phatAutoload = dirname(__DIR__, 3) . '/vendor/autoload.php';

if (!is_file($phatAutoload)) {
    return;
}

require_once $phatAutoload;

ChristianBrown\PhatWp\Plugin::boot();
