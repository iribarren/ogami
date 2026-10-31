<?php

declare(strict_types=1);

namespace App\Tests\Unit\Studio\Domain\Release;

use App\Studio\Domain\Release\InvalidReleaseContent;
use App\Studio\Domain\Release\ReleaseContent;
use App\Studio\Domain\Release\Version2\Catalog;
use App\Studio\Domain\Release\Version2\Effects;
use App\Studio\Domain\Release\Version2\ReleaseVersion2;
use App\Studio\Domain\Release\Version2\StepParts;
use App\Studio\Domain\Release\Version2\Steps;
use App\Tests\Support\Studio\ReleaseArrays;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Places in the contract (ADR 0047), run against valid/v2-places.json: the place fact slot type, the
 * createPlace effect, pick and count of places, tags that apply to places and closeThread by fact slot.
 * Its tags are near (0, places only), deep (1, places and threads) and ally (2, everything); play 0 picks
 * a place, play 1 counts them and play 2 (meet) creates and closes.
 */
#[CoversClass(ReleaseContent::class)]
#[CoversClass(ReleaseVersion2::class)]
#[CoversClass(Catalog::class)]
#[CoversClass(Effects::class)]
#[CoversClass(Steps::class)]
#[CoversClass(StepParts::class)]
final class ReleaseContentPlacesTest extends TestCase
{
    private const string MEET = 'sceneTypes.0.play.2';
    private const string MEET_PATH = 'sceneTypes[0].play[2]';

    /**
     * @return array<mixed>
     */
    private static function release(?string $path = null, mixed $value = null): array
    {
        $release = ReleaseArrays::fixture('valid/v2-places');

        return null === $path ? $release : ReleaseArrays::with($release, $path, $value);
    }

    #[Test]
    public function itAcceptsEveryPlacePartAndKeepsItsCanonicalForm(): void
    {
        $content = ReleaseContent::fromArray(self::release());
        $canonical = $content->toArray();

        self::assertSame([], $content->warnings());
        self::assertSame(['place', 'thread'], array_column($canonical['factSlots'], 'type'));
        self::assertSame(['place'], $canonical['tags'][0]['appliesTo']);
        self::assertSame($content->hash(), ReleaseContent::fromArray($canonical)->hash());
    }

    #[Test]
    public function itOmitsAnEmptyCreatePlaceAndACloseThreadWithoutAFact(): void
    {
        $release = self::release(self::MEET.'.effects', [['kind' => 'createPlace', 'name' => null, 'tags' => []], ['kind' => 'closeThread', 'fact' => null]]);

        /** @var list<array{play: list<array{effects: list<array<string, mixed>>}>}> $sceneTypes */
        $sceneTypes = ReleaseContent::fromArray($release)->toArray()['sceneTypes'];

        self::assertSame([['kind' => 'createPlace'], ['kind' => 'closeThread']], $sceneTypes[0]['play'][2]['effects']);
    }

    #[Test]
    public function pickedIsAllowedInACreatePlaceName(): void
    {
        $release = self::release(self::MEET.'.effects.1.name', 'Camp near {picked}');

        self::assertSame([], ReleaseContent::fromArray($release)->warnings());
    }

    /**
     * @return iterable<string, array{array<mixed>, string}>
     */
    public static function invalidParts(): iterable
    {
        yield 'createPlace blank name' => [self::release(self::MEET.'.effects.0.name', ''), self::MEET_PATH.'.effects[0].name: must not be empty'];
        yield 'createPlace name of 201' => [self::release(self::MEET.'.effects.0.name', str_repeat('a', 201)), self::MEET_PATH.'.effects[0].name: must be at most 200 characters'];
        yield 'createPlace unknown tag' => [self::release(self::MEET.'.effects.1.tags', ['ghost']), self::MEET_PATH.'.effects[1].tags[0]: unknown tag "ghost"'];
        yield 'createPlace tag of another kind' => [self::release('tags.2.appliesTo', ['npc']), self::MEET_PATH.'.effects[1].tags[1]: tag "ally" does not apply to places'];
        yield 'pick of places with a tag of another kind' => [self::release('tags.0.appliesTo', ['thread']), 'sceneTypes[0].play[0].tags[0]: tag "near" does not apply to places'];
        yield 'count of places with a tag of another kind' => [ReleaseArrays::with(self::release('tags.2.appliesTo', ['npc']), 'sceneTypes.0.play.1.count.tags', ['ally']), 'sceneTypes[0].play[1].count.tags[0]: tag "ally" does not apply to places'];
        yield 'closeThread fact of a place slot' => [self::release(self::MEET.'.effects.3.fact', 'site'), self::MEET_PATH.'.effects[3].fact: only allowed for a thread slot, fact slot "site" is a place slot'];
        yield 'closeThread fact unknown' => [self::release(self::MEET.'.effects.3.fact', 'ghost'), self::MEET_PATH.'.effects[3].fact: unknown fact slot "ghost"'];
        yield 'closeThread fact not a string' => [self::release(self::MEET.'.effects.3.fact', 3), self::MEET_PATH.'.effects[3].fact: '];
    }

    /**
     * @param array<mixed> $release
     */
    #[Test]
    #[DataProvider('invalidParts')]
    public function itRejectsInvalidPlacePartsWithTheirPath(array $release, string $message): void
    {
        $this->expectException(InvalidReleaseContent::class);
        $this->expectExceptionMessageIsOrContains($message);

        ReleaseContent::fromArray($release);
    }

    #[Test]
    public function aTagWithoutAScopeAppliesToPlaces(): void
    {
        $release = ReleaseArrays::with(self::release(), 'tags.0', ['key' => 'near', 'label' => 'Near']);

        self::assertSame([], ReleaseContent::fromArray($release)->warnings());
    }
}
