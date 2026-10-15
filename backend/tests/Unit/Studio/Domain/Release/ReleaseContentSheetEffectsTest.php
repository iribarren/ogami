<?php

declare(strict_types=1);

namespace App\Tests\Unit\Studio\Domain\Release;

use App\Studio\Domain\Release\InvalidReleaseContent;
use App\Studio\Domain\Release\ReleaseContent;
use App\Studio\Domain\Release\Version2\Effects;
use App\Tests\Support\Studio\ReleaseArrays;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Sheet effects of schema version 2 (ADR 0027): additive, so releases without them keep their
 * canonical form and hash; the agreement test covers each rule, this one the canonical form, the
 * hashes and the trackers a sheet formula reads.
 */
#[CoversClass(ReleaseContent::class)]
#[CoversClass(Effects::class)]
final class ReleaseContentSheetEffectsTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}> the fixture and the hash it had before sheet effects existed
     */
    public static function existingReleases(): iterable
    {
        yield 'checks' => ['valid/v2-checks', '53bcf9f8648adcfeea90430e63a4cc730a244ac13c6ceb41ada7b1274ce91283'];
        yield 'every part' => ['valid/v2-every-part', 'd4ea555f0fa39a6c88d818de49ac5e60564559b1995ab0ac76febbc9c834a7b5'];
        yield 'sheet' => ['valid/v2-sheet', '80e866bbc910b09ca4b4b6ae2d6d8348e776e5ca8573c300f84a46bbf4d9a1c6'];
        yield 'inspiration' => ['valid/v2-inspiration', '8a00bb5152cd767147790a16c4e209f4ec5dd43d6371b2c3e913752b6a8460ff'];
    }

    #[Test]
    #[DataProvider('existingReleases')]
    public function releasesWithoutSheetEffectsKeepTheirHash(string $fixture, string $hash): void
    {
        self::assertSame($hash, ReleaseContent::fromArray(ReleaseArrays::fixture($fixture))->hash());
    }

    #[Test]
    public function sheetEffectsKeepTheirPropertyOrderAndTheirValueForm(): void
    {
        $release = ReleaseArrays::fixture('valid/v2-sheet-effects');
        $content = ReleaseContent::fromArray(ReleaseArrays::reverseKeys($release));
        /** @var list<array{setup: list<array{effects: list<array<string, mixed>>}>, play: list<array{outcomes: list<array{effects: list<array<string, mixed>>}>}>}> $sceneTypes */
        $sceneTypes = $content->toArray()['sceneTypes'];

        self::assertSame(ReleaseContent::fromArray($release)->hash(), $content->hash());
        self::assertSame(['kind', 'field', 'op', 'value'], array_keys($sceneTypes[0]['setup'][0]['effects'][0]));
        self::assertSame(
            [1, ['dice' => '1d6'], ['formula' => '{bonus} - {tracker:dread}']],
            [$sceneTypes[0]['play'][0]['outcomes'][1]['effects'][0]['value'], $sceneTypes[0]['play'][0]['outcomes'][0]['effects'][0]['value'], $sceneTypes[0]['play'][0]['outcomes'][0]['effects'][2]['value']],
        );
    }

    #[Test]
    public function aSheetFormulaReadsOnlyTheTrackersOfTheFlow(): void
    {
        $flow = static fn (array $trackers): array => ['key' => 'main', 'name' => 'Main', 'defaultView' => 'journal', 'oracles' => [], 'trackers' => $trackers, 'phases' => [['key' => 'only', 'name' => 'Only', 'mode' => 'loop', 'selection' => ['rule' => 'player', 'sceneTypes' => ['setup']]]]];
        $release = ReleaseArrays::with(ReleaseArrays::fixture('valid/v2-sheet-effects'), 'flows', [$flow(['dread'])]);

        self::assertNotSame('', ReleaseContent::fromArray($release)->hash(), 'A tracker the flow declares is accepted.');

        try {
            ReleaseContent::fromArray(ReleaseArrays::with($release, 'flows', [$flow([])]));
            self::fail('The flow does not declare the tracker the sheet effect reads.');
        } catch (InvalidReleaseContent $exception) {
            self::assertStringContainsString('"dread" is not one of the trackers of flow "main"', $exception->getMessage());
        }
    }
}
