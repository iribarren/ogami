<?php

declare(strict_types=1);

namespace App\Studio\Domain\GameSystem;

use App\Studio\Domain\Release\InvalidReleaseContent;
use App\Studio\Domain\Release\ReleaseContent;
use App\Studio\Domain\Release\ReleaseFields;
use App\Studio\Domain\Release\Version2\ReleaseVersion2;

/**
 * The metadata of a GameSystem draft that Studio edits as a whole: name, description, credits and
 * theme, each checked against the limits of the release contract. Credits and theme exist from
 * schema version 2; GameSystem::editMetadata() refuses them on a version 1 draft.
 */
final readonly class GameSystemMetadata
{
    /**
     * @param array{notice: string, license?: string, url?: string, badge?: string}|null $credits
     */
    private function __construct(
        public string $name,
        public ?string $description,
        public ?array $credits,
        public ?string $theme,
    ) {
    }

    /**
     * @param array<mixed>|null $credits {notice, license?, url?, badge?}
     *
     * @throws InvalidGameSystemMetadata naming the first offending field
     */
    public static function of(string $name, ?string $description, ?array $credits, ?string $theme): self
    {
        return new self(
            self::name($name),
            self::description($description),
            null === $credits ? null : self::check('credits', static fn (): array => ReleaseVersion2::credits($credits)),
            null === $theme ? null : self::check('theme', static fn (): string => ReleaseFields::oneOf($theme, 'theme', ReleaseVersion2::THEMES)),
        );
    }

    /**
     * @throws InvalidGameSystemMetadata
     */
    public static function name(string $name): string
    {
        return self::check('name', static fn (): string => ReleaseFields::text($name, 'name', 1, ReleaseContent::MAX_GAME_SYSTEM_NAME_LENGTH));
    }

    /**
     * @throws InvalidGameSystemMetadata
     */
    public static function description(?string $description): ?string
    {
        return null === $description ? null : self::check('description', static fn (): string => ReleaseFields::text($description, 'description', 0, ReleaseContent::MAX_DESCRIPTION_LENGTH));
    }

    /**
     * @template T
     *
     * @param \Closure(): T $check
     *
     * @return T
     */
    private static function check(string $field, \Closure $check): mixed
    {
        try {
            return $check();
        } catch (InvalidReleaseContent $rejection) {
            throw InvalidGameSystemMetadata::fromContract($field, $rejection);
        }
    }
}
