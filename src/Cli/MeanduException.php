<?php

declare(strict_types=1);

namespace ChristianBrown\PhatWp\Cli;

use RuntimeException;

/**
 * Something upstream was not what the sync expected.
 *
 * Its own type rather than a plain RuntimeException so the sync can catch the
 * problems it knows how to survive — a venue that does not publish a category,
 * a gateway that returns a shape it does not recognise — while a genuine bug
 * in this code still crashes the job rather than being swallowed as "the menu
 * is unavailable today".
 */
final class MeanduException extends RuntimeException
{
}
