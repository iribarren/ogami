<?php

declare(strict_types=1);

namespace App\Tests\Unit\Studio\Domain\Release;

use App\Studio\Domain\Release\InvalidReleaseContent;
use App\Studio\Domain\Release\ReleaseContent;
use App\Studio\Domain\Release\Version2\Catalog;
use App\Studio\Domain\Release\Version2\Effects;
use App\Studio\Domain\Release\Version2\ReleaseVersion2;
use App\Studio\Domain\Release\Version2\Steps;
use App\Tests\Support\Studio\ReleaseArrays;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The optional "appliesTo" of a tag (ADR 0018, addendum of 2026-10-24): which entity kinds may carry
 * it, and the rule that every tag a flow step names applies to what the step targets. Run against
 * valid/v2-threads-npcs.json, whose tags are leader (0), ally (1) and hostile (2); leader is named by
 * the npc picks (play 0) and the npc count (play 3), and the meet prompt (play 4) creates NPCs
 * (effects 0 and 1) and Threads (effects 2 and 3).
 */
#[CoversClass(ReleaseContent::class)]
#[CoversClass(ReleaseVersion2::class)]
#[CoversClass(Catalog::class)]
#[CoversClass(Effects::class)]
#[CoversClass(Steps::class)]
final class ReleaseContentTagScopeTest extends TestCase
{
    /**
     * @return array<mixed>
     */
    private static function release(string $path, mixed $value): array
    {
        return ReleaseArrays::with(ReleaseArrays::fixture('valid/v2-threads-npcs'), $path, $value);
    }

    #[Test]
    public function aTagKeepsItsScopeInTheCanonicalForm(): void
    {
        $content = ReleaseContent::fromArray(self::release('tags.0.appliesTo', ['npc', 'thread']));

        self::assertSame(['npc', 'thread'], $content->toArray()['tags'][0]['appliesTo'] ?? null);
        self::assertSame($content->hash(), ReleaseContent::fromArray($content->toArray())->hash());
    }

    #[Test]
    public function aTagWithoutAScopeOrWithANullOneHasNoScopeInTheCanonicalForm(): void
    {
        $legacy = ReleaseContent::fromArray(ReleaseArrays::fixture('valid/v2-threads-npcs'))->toArray();
        $null = ReleaseContent::fromArray(self::release('tags.1.appliesTo', null))->toArray();

        self::assertArrayNotHasKey('appliesTo', $legacy['tags'][1]);
        self::assertArrayNotHasKey('appliesTo', $null['tags'][1]);
    }

    /**
     * @return iterable<string, array{array<mixed>, non-empty-string}>
     */
    public static function invalidScopes(): iterable
    {
        yield 'not a list' => [self::release('tags.0.appliesTo', 'npc'), 'tags[0].appliesTo: must be a list'];
        yield 'empty' => [self::release('tags.0.appliesTo', []), 'tags[0].appliesTo: at least 1'];
        yield 'an unknown kind' => [self::release('tags.0.appliesTo', ['npc', 'faction']), 'tags[0].appliesTo[1]: must be one of "character", "npc", "thread", \'faction\' given'];
        yield 'not a string' => [self::release('tags.0.appliesTo', [3]), 'tags[0].appliesTo[0]: must be one of'];
        yield 'a duplicate' => [self::release('tags.0.appliesTo', ['npc', 'npc']), 'tags[0].appliesTo[1]: duplicate kind "npc"'];
        yield 'more than three' => [self::release('tags.0.appliesTo', ['npc', 'thread', 'character', 'npc']), 'tags[0].appliesTo: at most 3'];
    }

    /**
     * @param array<mixed> $release
     */
    #[Test]
    #[DataProvider('invalidScopes')]
    public function itRejectsAMalformedScopeWithItsPath(array $release, string $message): void
    {
        $this->expectException(InvalidReleaseContent::class);
        $this->expectExceptionMessageIsOrContains($message);

        ReleaseContent::fromArray($release);
    }

    /**
     * The fixture with one more tag (index 3) that applies to the given kinds only.
     *
     * @param list<string> $appliesTo
     *
     * @return array<mixed>
     */
    private static function withScopedTag(array $appliesTo, string $path, mixed $value, ?array $release = null): array
    {
        $release = ReleaseArrays::with($release ?? ReleaseArrays::fixture('valid/v2-threads-npcs'), 'tags.3', ['key' => 'scoped', 'label' => 'Scoped', 'appliesTo' => $appliesTo]);

        return ReleaseArrays::with($release, $path, $value);
    }

    /**
     * @return iterable<string, array{array<mixed>, non-empty-string}>
     */
    public static function flowsNamingATagOutsideItsScope(): iterable
    {
        yield 'an npc pick' => [self::release('tags.0.appliesTo', ['thread']), 'sceneTypes[0].play[0].tags[0]: tag "leader" does not apply to npcs'];
        yield 'an npc count' => [
            ReleaseArrays::with(self::release('tags.0.appliesTo', ['character']), 'sceneTypes.0.play.0.tags', ['hostile']),
            'sceneTypes[0].play[3].count.tags[0]: tag "leader" does not apply to npcs',
        ];
        yield 'a thread count' => [self::withScopedTag(['npc'], 'sceneTypes.0.play.2.count.tags', ['scoped']), 'sceneTypes[0].play[2].count.tags[0]: tag "scoped" does not apply to threads'];
        yield 'a thread pick' => [self::withScopedTag(['character'], 'sceneTypes.0.play.1.of', 'thread', ReleaseArrays::with(ReleaseArrays::fixture('valid/v2-threads-npcs'), 'sceneTypes.0.play.1.tags', ['scoped'])), 'sceneTypes[0].play[1].tags[0]: tag "scoped" does not apply to threads'];
        yield 'a createNpc effect' => [self::withScopedTag(['thread'], 'sceneTypes.0.play.4.effects.1.tags', ['scoped']), 'sceneTypes[0].play[4].effects[1].tags[0]: tag "scoped" does not apply to npcs'];
        yield 'a createThread effect' => [self::withScopedTag(['npc'], 'sceneTypes.0.play.4.effects.3.tags', ['scoped']), 'sceneTypes[0].play[4].effects[3].tags[0]: tag "scoped" does not apply to threads'];
    }

    /**
     * @param array<mixed> $release
     */
    #[Test]
    #[DataProvider('flowsNamingATagOutsideItsScope')]
    public function everyTagAFlowStepNamesMustApplyToWhatTheStepTargets(array $release, string $message): void
    {
        $this->expectException(InvalidReleaseContent::class);
        $this->expectExceptionMessageIsOrContains($message);

        ReleaseContent::fromArray($release);
    }

    #[Test]
    public function aFlowNamingTagsWithinTheirScopeIsValid(): void
    {
        $release = ReleaseArrays::fixture('valid/v2-threads-npcs');
        $release = ReleaseArrays::with($release, 'tags.0.appliesTo', ['npc', 'character']);
        $release = ReleaseArrays::with($release, 'tags.1.appliesTo', ['npc']);
        $release = ReleaseArrays::with($release, 'tags.2.appliesTo', ['npc', 'thread']);

        self::assertSame([], ReleaseContent::fromArray($release)->warnings());
    }
}
