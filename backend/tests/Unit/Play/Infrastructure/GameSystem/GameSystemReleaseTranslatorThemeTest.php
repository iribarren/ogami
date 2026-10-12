<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Infrastructure\GameSystem;

use App\Play\Domain\GameSystem\GameSystemSnapshot;
use App\Play\Domain\GameSystem\InvalidGameSystemRelease;
use App\Play\Domain\GameSystem\Theme;
use App\Play\Infrastructure\GameSystem\GameSystemReleaseTranslator;
use App\Studio\Domain\Release\Version2\ReleaseVersion2;
use App\Tests\Support\Play\ReleaseViews;
use App\Tests\Support\Studio\ReleaseArrays;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Play's anti-corruption layer reads the optional release "theme" of schema version 2, and fails
 * closed on a name Studio would have rejected.
 */
#[CoversClass(GameSystemReleaseTranslator::class)]
#[CoversClass(Theme::class)]
final class GameSystemReleaseTranslatorThemeTest extends TestCase
{
    /**
     * @param array<mixed> $content
     */
    private function translate(array $content): GameSystemSnapshot
    {
        /** @var array<string, mixed> $typed */
        $typed = $content;

        return new GameSystemReleaseTranslator()->translate(ReleaseViews::of($typed));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function themes(): iterable
    {
        foreach (Theme::cases() as $theme) {
            yield $theme->value => [$theme->value];
        }
    }

    #[Test]
    #[DataProvider('themes')]
    public function itReadsEachCuratedTheme(string $theme): void
    {
        $content = ReleaseArrays::with(ReleaseViews::fixtureContent('valid/v2-minimal'), 'theme', $theme);

        self::assertSame($theme, $this->translate($content)->theme());
    }

    #[Test]
    public function theCuratedSetIsTheOneStudioValidates(): void
    {
        $studio = ReleaseVersion2::THEMES;
        $play = array_map(static fn (Theme $theme): string => $theme->value, Theme::cases());

        self::assertEqualsCanonicalizing($studio, $play);
    }

    #[Test]
    public function aReleaseWithoutAThemeReadsAsTheDefaultTheme(): void
    {
        self::assertNull($this->translate(ReleaseViews::fixtureContent('valid/v2-minimal'))->theme());
        self::assertNull($this->translate(ReleaseViews::fixtureContent('valid/v2-every-part'))->theme());
        self::assertNull($this->translate(ReleaseViews::contractDocExampleContent())->theme(), 'A schema version 1 release has no theme.');
        self::assertNull($this->translate(ReleaseArrays::with(ReleaseViews::fixtureContent('valid/v2-minimal'), 'theme', null))->theme());
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function badThemes(): iterable
    {
        yield 'unknown name' => ['steampunk', 'theme: unknown theme "steampunk".'];
        yield 'wrong case' => ['Noir', 'theme: unknown theme "Noir".'];
        yield 'empty' => ['', 'theme: unknown theme "".'];
        yield 'number' => [3, 'theme: must be a string.'];
        yield 'list' => [['noir'], 'theme: must be a string.'];
    }

    #[Test]
    #[DataProvider('badThemes')]
    public function itFailsClosedOnAnythingButACuratedTheme(mixed $theme, string $message): void
    {
        $content = ReleaseArrays::with(ReleaseViews::fixtureContent('valid/v2-minimal'), 'theme', $theme);

        $this->expectException(InvalidGameSystemRelease::class);
        $this->expectExceptionMessageIsOrContains($message);

        $this->translate($content);
    }
}
