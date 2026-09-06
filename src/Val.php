<?php

declare(strict_types=1);

namespace ChristianBrown\PhatWp;

use const PHP_INT_MAX;

/**
 * Narrow the `mixed` that comes back from WordPress and Carbon Fields.
 *
 * Every field value here arrives untyped: post meta is whatever was serialised
 * into it, and a Carbon complex row is an array of unknown shape. Casting it
 * with `(string)` at each of the hundred or so places it is read would satisfy
 * the analyser and hide the actual question, which is what should happen when a
 * value is missing or the wrong type.
 *
 * So the answer is given once, here. A missing string is null or the default; a
 * missing number is null; a missing list is empty. The front end's contract
 * already says which fields are optional, and these mirror it.
 */
final class Val
{
    public static function bool(mixed $value): bool
    {
        return (bool) $value;
    }

    public static function int(mixed $value, int $default = 0): int
    {
        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * A number, keeping whole numbers whole.
     *
     * ABV is 4.2 and a tap number is 20; emitting 20.0 for the second would be
     * harmless in JSON but reads as a bug in the API response.
     */
    public static function num(mixed $value): null|float|int
    {
        if (!is_numeric($value)) {
            return null;
        }

        $float = (float) $value;

        return $float === floor($float) && abs($float) < PHP_INT_MAX ? (int) $float : $float;
    }

    /**
     * The rows of a Carbon complex field, with anything that is not a row dropped.
     *
     * @return list<array<string, mixed>>
     */
    public static function rows(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $out = [];

        foreach ($value as $row) {
            if (is_array($row)) {
                // @var array<string, mixed> $row
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * A non-empty string, or null. Whitespace counts as absent.
     */
    public static function str(mixed $value): ?string
    {
        if (is_string($value)) {
            return '' === mb_trim($value) ? null : $value;
        }

        return is_int($value) || is_float($value) ? (string) $value : null;
    }

    /**
     * @return list<string>
     */
    public static function strings(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $out = [];

        foreach ($value as $item) {
            $string = self::str($item);

            if (null !== $string) {
                $out[] = $string;
            }
        }

        return $out;
    }

    /**
     * A string, falling back rather than returning null.
     */
    public static function text(mixed $value, string $default = ''): string
    {
        return self::str($value) ?? $default;
    }
}
