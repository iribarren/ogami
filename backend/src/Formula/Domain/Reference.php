<?php

declare(strict_types=1);

namespace App\Formula\Domain;

/**
 * A value a formula reads: `{key}`, `{key.max}` or `{tracker:key}`. Keys follow the key rule of the
 * release contract: 1 to 64 characters of `a-z`, `0-9` and `-`.
 */
final readonly class Reference
{
    public const string KEY_PATTERN = '[a-z0-9-]{1,64}';
    private const string TRACKER_PREFIX = 'tracker:';
    private const string MAX_SUFFIX = '.max';

    private function __construct(
        public ReferenceKind $kind,
        public string $key,
    ) {
    }

    /**
     * @throws InvalidFormula when the key breaks the key rule
     */
    public static function field(string $key): self
    {
        return new self(ReferenceKind::Field, self::checkedKey($key));
    }

    /**
     * @throws InvalidFormula when the key breaks the key rule
     */
    public static function trackMax(string $key): self
    {
        return new self(ReferenceKind::TrackMax, self::checkedKey($key));
    }

    /**
     * @throws InvalidFormula when the key breaks the key rule
     */
    public static function tracker(string $key): self
    {
        return new self(ReferenceKind::Tracker, self::checkedKey($key));
    }

    /**
     * Reads what is written between the braces ("str", "hp.max" or "tracker:chaos"); null when it is none of the three.
     */
    public static function fromInner(string $inner): ?self
    {
        $key = '/^'.self::KEY_PATTERN.'$/D';

        if (str_starts_with($inner, self::TRACKER_PREFIX)) {
            $trackerKey = substr($inner, \strlen(self::TRACKER_PREFIX));

            return 1 === preg_match($key, $trackerKey) ? self::tracker($trackerKey) : null;
        }

        if (str_ends_with($inner, self::MAX_SUFFIX)) {
            $trackKey = substr($inner, 0, -\strlen(self::MAX_SUFFIX));

            return 1 === preg_match($key, $trackKey) ? self::trackMax($trackKey) : null;
        }

        return 1 === preg_match($key, $inner) ? self::field($inner) : null;
    }

    /**
     * The canonical spelling inside the braces: "str", "hp.max" or "tracker:chaos".
     */
    public function toString(): string
    {
        return match ($this->kind) {
            ReferenceKind::Field => $this->key,
            ReferenceKind::TrackMax => $this->key.self::MAX_SUFFIX,
            ReferenceKind::Tracker => self::TRACKER_PREFIX.$this->key,
        };
    }

    public function equals(self $other): bool
    {
        return $this->kind === $other->kind && $this->key === $other->key;
    }

    private static function checkedKey(string $key): string
    {
        if (1 !== preg_match('/^'.self::KEY_PATTERN.'$/D', $key)) {
            throw InvalidFormula::invalidKey($key);
        }

        return $key;
    }
}
