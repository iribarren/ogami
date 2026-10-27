<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Infrastructure\GameSystem;

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
 * Play's anti-corruption layer refuses the parts of ADR 0045 until the slices of
 * play-items-economy that play them: the gear catalog, the sheet currency and inventory fields
 * (slice 2), shop steps (slice 3). A release using them publishes but cannot be played yet. Run
 * against examples/cpr-campaign-in-acts without those parts.
 */
#[CoversClass(GameSystemReleaseTranslator::class)]
final class GameSystemReleaseTranslatorGearTest extends TestCase
{
    /**
     * examples/cpr-campaign-in-acts with every part of ADR 0045 left out: the rent choices still
     * spend "eddies", a number field Play reads.
     *
     * @return array<string, mixed>
     */
    public static function playable(): array
    {
        $content = ReleaseViews::fixtureContent('examples/cpr-campaign-in-acts');
        /** @var array{fields: list<array<string, mixed>>} $sheet */
        $sheet = $content['sheet'];
        $content = ReleaseArrays::with(ReleaseArrays::without($content, 'gear'), 'sheet', ['fields' => [$sheet['fields'][0]]]);
        /** @var array<string, mixed> $content */
        $content = ReleaseArrays::with($content, 'sceneTypes.20.play', []);

        return $content;
    }

    /**
     * @param array<mixed> $content
     */
    private function translate(array $content): GameSystemSnapshot
    {
        /** @var array<string, mixed> $typed */
        $typed = $content;

        return new GameSystemReleaseTranslator()->translate(ReleaseViews::of($typed));
    }

    #[Test]
    public function theReleaseWithoutThemIsPlayable(): void
    {
        $snapshot = $this->translate(self::playable());

        self::assertFalse($snapshot->sheet()->isEmpty());
    }

    /**
     * @return iterable<string, array{string, mixed, string}> the path set on the playable release, its value and the refusal
     */
    public static function unplayedParts(): iterable
    {
        yield 'a gear catalog' => ['gear', [['key' => 'kibble', 'name' => 'Kibble', 'cost' => 10]], 'gear: a gear catalog is not played yet (ADR 0045).'];
        yield 'a null gear catalog' => ['gear', null, 'gear: a gear catalog is not played yet (ADR 0045).'];
        yield 'a currency' => ['sheet.currency', ['field' => 'eddies'], 'sheet.currency: a currency is not played yet (ADR 0045).'];
        yield 'an inventory field' => ['sheet.fields.1', ['key' => 'gear', 'label' => 'Gear', 'type' => 'inventory'], 'sheet.fields[1].type: inventory fields are not played yet (ADR 0045).'];
        yield 'a shop step' => ['sceneTypes.20.play', [['key' => 'stalls', 'kind' => 'shop', 'title' => 'Shop', 'offer' => ['gear' => ['kibble']]]], 'sceneTypes[20].play[0].kind: shop steps are not played yet (ADR 0045).'];
    }

    #[Test]
    #[DataProvider('unplayedParts')]
    public function itRefusesAPartItDoesNotPlayYet(string $path, mixed $value, string $message): void
    {
        $this->expectException(InvalidGameSystemRelease::class);
        $this->expectExceptionMessageIsOrContains($message);

        $this->translate(ReleaseArrays::with(self::playable(), $path, $value));
    }
}
