<?php

declare(strict_types=1);

namespace ChristianBrown\PhatWp;

use Carbon_Fields\Field;
use Carbon_Fields\Field\Association_Field;
use Carbon_Fields\Field\Complex_Field;
use Carbon_Fields\Field\Field as CarbonField;
use Carbon_Fields\Field\Multiselect_Field;
use Carbon_Fields\Field\Select_Field;
use LogicException;

/**
 * Typed wrappers around Carbon Fields' factory.
 *
 * `Field::make('complex', …)` is declared as returning the base `Field`, but the
 * methods worth calling — `add_fields`, `set_options`, `set_layout` — only exist
 * on the concrete subclasses. Static analysis is right to reject those calls,
 * and the honest fix is to narrow the type once, here, rather than to silence it
 * at each of the several dozen call sites.
 *
 * The instanceof check is a real assertion about the factory's contract, not a
 * cast to quiet the analyser: if a future Carbon Fields changes what
 * `Field::make` returns for a given type string, this fails at boot with a
 * message naming the type, instead of fataling somewhere inside a field
 * definition on the first request to wp-admin.
 */
final class FieldFactory
{
    /**
     * A link to one or more posts of a single type.
     *
     * Carbon's association field can point at posts, terms, users and comments
     * at once; every relationship in this model points at exactly one post
     * type, so the type list is built here rather than spelled out at each call
     * site.
     *
     * @param string   $name     the meta key
     * @param string   $label    the label shown in the admin
     * @param string   $postType the post type it may link to
     * @param null|int $max      how many may be chosen, null for no limit
     */
    public static function association(
        string $name,
        string $label,
        string $postType,
        ?int $max = null,
    ): Association_Field {
        $field = Field::make('association', $name, $label);

        if (!$field instanceof Association_Field) {
            throw new LogicException(sprintf('Expected an association field for "%s".', $name));
        }

        $field->set_types([['type' => 'post', 'post_type' => $postType]]);

        if (null !== $max) {
            $field->set_max($max);
        }

        return $field;
    }

    public static function complex(string $name, string $label): Complex_Field
    {
        $field = Field::make('complex', $name, $label);

        if (!$field instanceof Complex_Field) {
            throw new LogicException(sprintf('Expected a complex field for "%s".', $name));
        }

        return $field;
    }

    /**
     * A text field with a character limit, matching the SEO limits Payload enforces.
     */
    public static function limitedText(string $type, string $name, string $label, int $maxLength): CarbonField
    {
        return Field::make($type, $name, $label)->set_attribute('maxLength', (string) $maxLength);
    }

    /**
     * @param string                $name    the meta key
     * @param string                $label   the label shown in the admin
     * @param array<string, string> $options stored value => label
     */
    public static function multiselect(string $name, string $label, array $options): Multiselect_Field
    {
        $field = Field::make('multiselect', $name, $label);

        if (!$field instanceof Multiselect_Field) {
            throw new LogicException(sprintf('Expected a multiselect field for "%s".', $name));
        }

        // Called as a statement, not chained: set_options is declared on the
        // shared parent and returns that parent's type, which would widen the
        // narrowing done immediately above.
        $field->set_options($options);

        return $field;
    }

    /**
     * A whole-number input.
     *
     * Carbon Fields has no integer field; the convention is a text field with the
     * HTML type overridden, which is why this exists rather than being written
     * out each time. `set_attribute` takes strings, so the caller does not have
     * to remember to quote a number.
     */
    public static function number(string $name, string $label, ?int $default = null): CarbonField
    {
        $field = Field::make('text', $name, $label)->set_attribute('type', 'number');

        return null === $default ? $field : $field->set_default_value((string) $default);
    }

    /**
     * @param string                $name    the meta key
     * @param string                $label   the label shown in the admin
     * @param array<string, string> $options stored value => label
     */
    public static function select(string $name, string $label, array $options): Select_Field
    {
        $field = Field::make('select', $name, $label);

        if (!$field instanceof Select_Field) {
            throw new LogicException(sprintf('Expected a select field for "%s".', $name));
        }

        // Called as a statement, not chained: set_options is declared on the
        // shared parent and returns that parent's type, which would widen the
        // narrowing done immediately above.
        $field->set_options($options);

        return $field;
    }
}
