<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Infrastructure\GameSystem;

use App\Play\Domain\GameSystem\Credits;
use App\Play\Domain\GameSystem\CreditsBadge;
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
#[CoversClass(CreditsBadge::class)]
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
    public function itReadsTheBadgeOfTheCredits(): void
    {
        $credits = $this->translate(ReleaseViews::fixtureContent('valid/v2-hunger-dice'))->credits();

        self::assertInstanceOf(Credits::class, $credits);
        self::assertSame(CreditsBadge::DarkPack, $credits->badge);
        self::assertSame('Dark Pack Agreement', $credits->license);
    }

    #[Test]
    public function aNullOrMissingBadgeReadsAsNone(): void
    {
        $withNull = $this->translate($this->withCredits(['notice' => 'x', 'badge' => null]))->credits();

        self::assertInstanceOf(Credits::class, $withNull);
        self::assertNull($withNull->badge);
        self::assertNull($this->translate(ReleaseViews::fixtureContent('valid/v2-credits'))->credits()?->badge);
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
        yield 'notice over the limit' => [['notice' => str_repeat('n', 2001)], 'gameSystem.credits.notice: must be at most 2000 characters.'];
        yield 'license over the limit' => [['notice' => 'x', 'license' => str_repeat('l', 201)], 'gameSystem.credits.license: must be at most 200 characters.'];
        yield 'url over the limit' => [['notice' => 'x', 'url' => 'https://example.com/'.str_repeat('u', 481)], 'gameSystem.credits.url: must be at most 500 characters.'];
        yield 'an unknown badge' => [['notice' => 'x', 'badge' => 'gold'], 'gameSystem.credits.badge: unknown badge "gold" (ADR 0044).'];
        yield 'a badge that is no string' => [['notice' => 'x', 'badge' => 3], 'gameSystem.credits.badge: must be a string.'];
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

    #[Test]
    public function itAcceptsCreditsExactlyAtTheLimits(): void
    {
        $url = 'https://example.com/'.str_repeat('u', 480);
        $credits = $this->translate($this->withCredits(['notice' => str_repeat('n', 2000), 'license' => str_repeat('l', 200), 'url' => $url]))->credits();

        self::assertEquals(new Credits(str_repeat('n', 2000), str_repeat('l', 200), $url), $credits);
    }

    /**
     * Credits exist from schema version 2 on; stored version 1 content never yields them, even with a stray key.
     */
    #[Test]
    public function aSchemaVersionOneReleaseNeverYieldsCredits(): void
    {
        $content = ReleaseViews::contractDocExampleContent();
        $gameSystem = $content['gameSystem'];
        self::assertIsArray($gameSystem);
        $content = ReleaseArrays::with($content, 'gameSystem', [...$gameSystem, 'credits' => ['notice' => 'Stray.']]);

        self::assertNull($this->translate($content)->credits());
    }
}
