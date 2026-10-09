<?php

declare(strict_types=1);

namespace App\Studio\Domain\Release;

use App\Randomness\Domain\Oracle\OracleTable;

/**
 * Checks of decoded JSON values shared by every schema version of the release contract. Each
 * check throws InvalidReleaseContent naming the path of the offending value.
 *
 * @internal used by ReleaseContent and its schema version validators only
 */
final class ReleaseFields
{
    public const string ROOT = '(root)';

    /**
     * Checks a JSON object against its fields and returns it in field order, without null values.
     *
     * @param array<string, bool> $fields name => required, in schema order
     *
     * @return array<string, mixed>
     */
    public static function object(mixed $value, string $path, array $fields): array
    {
        if (!\is_array($value) || ([] !== $value && array_is_list($value))) {
            throw InvalidReleaseContent::at($path, 'must be an object');
        }

        foreach (array_keys($value) as $name) {
            if (!isset($fields[$name])) {
                throw InvalidReleaseContent::at(self::child($path, (string) $name), 'unknown property');
            }
        }

        $object = [];
        foreach ($fields as $name => $required) {
            if ($required && !\array_key_exists($name, $value)) {
                throw InvalidReleaseContent::at(self::child($path, $name), 'required');
            }

            if (null !== ($value[$name] ?? null)) {
                $object[$name] = $value[$name];
            } elseif ($required) {
                throw InvalidReleaseContent::at(self::child($path, $name), 'must not be null');
            }
        }

        return $object;
    }

    /**
     * A JSON list of objects of the same kind, each checked and ordered as object() does.
     *
     * @param array<string, bool> $fields
     *
     * @return list<array<string, mixed>>
     */
    public static function objects(mixed $value, string $path, array $fields): array
    {
        $objects = [];
        foreach (self::list($value, $path) as $index => $item) {
            $objects[] = self::object($item, \sprintf('%s[%d]', $path, $index), $fields);
        }

        return $objects;
    }

    /**
     * @return list<mixed>
     */
    public static function list(mixed $value, string $path): array
    {
        if (!\is_array($value) || !array_is_list($value)) {
            throw InvalidReleaseContent::at($path, 'must be a list');
        }

        return $value;
    }

    /**
     * A list of $min to $max items; $noun names them in the message ("at most 50 trackers").
     *
     * @return list<mixed>
     */
    public static function sizedList(mixed $value, string $path, int $min, int $max, string $noun): array
    {
        $list = self::list($value, $path);
        $count = \count($list);
        if ($count > $max) {
            throw InvalidReleaseContent::at($path, \sprintf('at most %d %s, %d given', $max, $noun, $count));
        }

        if ($count < $min) {
            throw InvalidReleaseContent::at($path, \sprintf('at least %d %s, %d given', $min, $noun, $count));
        }

        return $list;
    }

    public static function key(mixed $value, string $path): string
    {
        if (!\is_string($value)) {
            throw InvalidReleaseContent::at($path, 'must be a string');
        }

        if (!OracleTable::isValidKey($value)) {
            throw InvalidReleaseContent::at($path, \sprintf('must be 1 to %d characters among a-z, 0-9 and "-", "%s" given', OracleTable::MAX_KEY_LENGTH, $value));
        }

        return $value;
    }

    /**
     * At most $maxLength characters, and at least $minLength once trimmed (so a required text is
     * never blank). The string itself is kept as given.
     */
    public static function text(mixed $value, string $path, int $minLength, int $maxLength): string
    {
        if (!\is_string($value)) {
            throw InvalidReleaseContent::at($path, 'must be a string');
        }

        $length = mb_strlen($value);
        if ($length > $maxLength) {
            throw InvalidReleaseContent::at($path, \sprintf('must be at most %d characters, %d given', $maxLength, $length));
        }

        if (mb_strlen(trim($value)) < $minLength) {
            throw InvalidReleaseContent::at($path, 0 === $length ? 'must not be empty' : 'must not be blank');
        }

        return $value;
    }

    public static function integer(mixed $value, string $path, int $min, int $max): int
    {
        if (!\is_int($value)) {
            throw InvalidReleaseContent::at($path, 'must be an integer');
        }

        if ($value < $min) {
            throw InvalidReleaseContent::at($path, \sprintf('must be at least %d, %d given', $min, $value));
        }

        if ($value > $max) {
            throw InvalidReleaseContent::at($path, \sprintf('must be at most %d, %d given', $max, $value));
        }

        return $value;
    }

    /**
     * @param non-empty-list<string> $allowed
     */
    public static function oneOf(mixed $value, string $path, array $allowed): string
    {
        if (!\is_string($value) || !\in_array($value, $allowed, true)) {
            throw InvalidReleaseContent::at($path, \sprintf('must be one of "%s", %s given', implode('", "', $allowed), self::describe($value)));
        }

        return $value;
    }

    /**
     * The tag of a tagged union: the object's $field, one of $allowed. Read before the object
     * itself is checked, since the tag decides its fields.
     *
     * @template T of string
     *
     * @param non-empty-list<T> $allowed
     *
     * @return T
     */
    public static function tag(mixed $value, string $path, string $field, array $allowed): string
    {
        if (!\is_array($value) || ([] !== $value && array_is_list($value))) {
            throw InvalidReleaseContent::at($path, 'must be an object');
        }

        if (!\array_key_exists($field, $value)) {
            throw InvalidReleaseContent::at(self::child($path, $field), 'required');
        }

        foreach ($allowed as $tag) {
            if ($tag === $value[$field]) {
                return $tag;
            }
        }

        throw InvalidReleaseContent::at(self::child($path, $field), \sprintf('must be one of "%s", %s given', implode('", "', $allowed), self::describe($value[$field])));
    }

    public static function child(string $path, string $name): string
    {
        return self::ROOT === $path ? $name : $path.'.'.$name;
    }

    public static function describe(mixed $value): string
    {
        return \is_scalar($value) ? var_export($value, true) : get_debug_type($value);
    }
}
