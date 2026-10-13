<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Infrastructure\GameSystem;

use App\Play\Domain\GameSystem\Credits;
use App\Play\Domain\GameSystem\GameSystemSnapshot;
use App\Play\Domain\GameSystem\InvalidGameSystemRelease;
use App\Play\Infrastructure\GameSystem\GameSystemReleaseTranslator;
use App\Tests\Support\Play\ReleaseViews;
use App\Tests\Support\Studio\ReleaseArrays;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Play's anti-corruption layer reads the optional release "credits" of schema version 2 (ADR 0021)
 * and fails closed on a shape Studio would have rejected.
 */
#[CoversClass(GameSystemReleaseTranslator::class)]
#[CoversClass(Credits::class)]
final class GameSystemReleaseTranslatorCreditsTest extends TestCase
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
     * @return array<mixed>
     */
    private function withCredits(mixed $credits): array
    {
        $content = ReleaseViews::fixtureContent('valid/v2-minimal');
        $gameSystem = $content['gameSystem'];
        self::assertIsArray($gameSystem);

        return ReleaseArrays::with($content, 'gameSystem', [...$gameSystem, 'credits' => $credits]);
    }

    #[Test]
    public function itReadsEveryCreditsField(): void
    {
        $credits = $this->translate(ReleaseViews::fixtureContent('valid/v2-credits'))->credits();

        self::assertEquals(
            new Credits(
                'Night Job is unofficial content provided under the Homebrew Content Policy of R. Talsorian Games.',
                'R. Talsorian Games Homebrew Content Policy',
                'https://rtalsoriangames.com/homebrew-content-policy/',
            ),
            $credits,
        );
    }

    #[Test]
    public function aNoticeAloneIsEnough(): void
    {
        self::assertEquals(new Credits('Fan content.'), $this->translate(ReleaseViews::fixtureContent('valid/v2-credits-notice-only'))->credits());
    }

    /**
     * Releases published before credits existed have no key; null reads the same.
     */
    #[Test]
    public function aReleaseWithoutCreditsReadsAsNone(): void
    {
        self::assertNull($this->translate(ReleaseViews::fixtureContent('valid/v2-minimal'))->credits());
        self::assertNull($this->translate(ReleaseViews::fixtureContent('valid/v2-every-part'))->credits());
        self::assertNull($this->translate(ReleaseViews::contractDocExampleContent())->credits(), 'A schema version 1 release has no credits.');
        self::assertNull($this->translate($this->withCredits(null))->credits());
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function badCredits(): iterable
    {
        yield 'a string' => ['Fan content.', 'gameSystem.credits: must be an object.'];
        yield 'a list' => [['Fan content.'], 'gameSystem.credits: must be an object.'];
        yield 'no notice' => [['license' => 'MIT'], 'gameSystem.credits.notice: must be a string.'];
        yield 'blank notice' => [['notice' => '  '], 'gameSystem.credits.notice: must not be blank.'];
        yield 'notice not a string' => [['notice' => 3], 'gameSystem.credits.notice: must be a string.'];
        yield 'license not a string' => [['notice' => 'x', 'license' => 3], 'gameSystem.credits.license: must be a string.'];
        yield 'http url' => [['notice' => 'x', 'url' => 'http://example.com/policy'], 'gameSystem.credits.url: must be an absolute https:// URL.'];
        yield 'relative url' => [['notice' => 'x', 'url' => '/policy'], 'gameSystem.credits.url: must be an absolute https:// URL.'];
        yield 'unknown property' => [['notice' => 'x', 'author' => 'me'], 'gameSystem.credits.author: unknown property.'];
    }

    #[Test]
    #[DataProvider('badCredits')]
    public function itFailsClosedOnCreditsStudioWouldHaveRejected(mixed $credits, string $message): void
    {
        $this->expectException(InvalidGameSystemRelease::class);
        $this->expectExceptionMessageIsOrContains($message);

        $this->translate($this->withCredits($credits));
    }
}
