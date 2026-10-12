<?php

declare(strict_types=1);

namespace App\Tests\Unit\Studio\Domain\Release;

use App\Studio\Domain\Release\InvalidReleaseContent;
use App\Studio\Domain\Release\ReleaseContent;
use App\Studio\Domain\Release\Version2\ReleaseVersion2;
use App\Tests\Support\Studio\ReleaseArrays;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The optional top-level "theme" of schema version 2 (feature design-foundation): one of the five
 * curated themes, omitted from the canonical form when absent.
 */
#[CoversClass(ReleaseContent::class)]
#[CoversClass(ReleaseVersion2::class)]
final class ReleaseContentThemeTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function themes(): iterable
    {
        foreach (['parchment', 'neon', 'noir', 'eldritch', 'starship'] as $theme) {
            yield $theme => [$theme];
        }
    }

    #[Test]
    #[DataProvider('themes')]
    public function itAcceptsEachCuratedThemeAndKeepsItInTheCanonicalForm(string $theme): void
    {
        $release = ReleaseArrays::with(ReleaseArrays::fixture('valid/v2-minimal'), 'theme', $theme);

        $canonical = ReleaseContent::fromArray($release)->toArray();

        self::assertSame($theme, $canonical['theme']);
        self::assertSame(['schemaVersion', 'gameSystem', 'theme', 'oracles', 'trackers', 'factSlots', 'sceneTypes', 'flows', 'sheet', 'checks'], array_keys($canonical));
    }

    #[Test]
    public function itOmitsTheThemeFromTheCanonicalFormWhenAbsentOrNull(): void
    {
        $absent = ReleaseContent::fromArray(ReleaseArrays::fixture('valid/v2-minimal'));
        $null = ReleaseContent::fromArray(ReleaseArrays::with(ReleaseArrays::fixture('valid/v2-minimal'), 'theme', null));

        self::assertArrayNotHasKey('theme', $absent->toArray());
        self::assertArrayNotHasKey('theme', $null->toArray());
        self::assertSame($absent->hash(), $null->hash());
    }

    #[Test]
    public function aThemeChangesTheHash(): void
    {
        $plain = ReleaseContent::fromArray(ReleaseArrays::fixture('valid/v2-minimal'));
        $themed = ReleaseContent::fromArray(ReleaseArrays::fixture('valid/v2-theme-noir'));

        self::assertNotSame($plain->hash(), $themed->hash());
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function badThemes(): iterable
    {
        $oneOf = 'theme: must be one of "parchment", "neon", "noir", "eldritch", "starship", ';

        yield 'unknown name' => ['steampunk', $oneOf."'steampunk' given"];
        yield 'wrong case' => ['Noir', $oneOf."'Noir' given"];
        yield 'empty' => ['', $oneOf."'' given"];
        yield 'number' => [3, $oneOf.'3 given'];
        yield 'list' => [['noir'], $oneOf.'array given'];
    }

    #[Test]
    #[DataProvider('badThemes')]
    public function itRejectsAnythingButACuratedTheme(mixed $theme, string $message): void
    {
        $release = ReleaseArrays::with(ReleaseArrays::fixture('valid/v2-minimal'), 'theme', $theme);

        $this->expectException(InvalidReleaseContent::class);
        $this->expectExceptionMessageIsOrContains($message);

        ReleaseContent::fromArray($release);
    }

    #[Test]
    public function schemaVersionOneHasNoTheme(): void
    {
        $release = ReleaseArrays::with(ReleaseArrays::fixture('valid/minimal'), 'theme', 'noir');

        $this->expectException(InvalidReleaseContent::class);
        $this->expectExceptionMessageIsOrContains('theme: unknown property');

        ReleaseContent::fromArray($release);
    }
}
