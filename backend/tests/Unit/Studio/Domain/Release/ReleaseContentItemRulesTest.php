<?php

declare(strict_types=1);

namespace App\Tests\Unit\Studio\Domain\Release;

use App\Studio\Domain\Release\InvalidReleaseContent;
use App\Studio\Domain\Release\ReleaseContent;
use App\Studio\Domain\Release\Version2\FlowReferences;
use App\Studio\Domain\Release\Version2\ReleaseVersion2;
use App\Tests\Support\Studio\ReleaseArrays;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The item rules (ADR 0030): every rule of the publisher except flow coherence. Runs against
 * valid/v2-contract-doc-example.json: tables heist-scenes (0) and complications (1); trackers alarm,
 * edge, chaos; Scene Types legwork (0), infiltration (1), firefight (2); flow heist with phases
 * legwork (0, player), the-heist (1, oracle) and getaway (2, sequence).
 */
#[CoversClass(ReleaseContent::class)]
#[CoversClass(ReleaseVersion2::class)]
#[CoversClass(FlowReferences::class)]
final class ReleaseContentItemRulesTest extends TestCase
{
    /**
     * @param array<mixed>|null $release
     *
     * @return array<mixed>
     */
    private static function example(?string $path = null, mixed $value = null, ?array $release = null): array
    {
        $release ??= ReleaseArrays::fixture('valid/v2-contract-doc-example');

        return null === $path ? $release : ReleaseArrays::with($release, $path, $value);
    }

    /**
     * The example plus a "noise" clock no flow lists.
     *
     * @return array<mixed>
     */
    private static function withNoise(): array
    {
        return self::example('trackers.3', ['key' => 'noise', 'name' => 'Noise', 'kind' => 'clock', 'segments' => 4]);
    }

    /**
     * @return iterable<string, array{array<mixed>, non-empty-string}>
     */
    public static function coherenceOnly(): iterable
    {
        yield 'oracle selection table with an entry naming no Scene Type' => [
            self::example('flows.0.phases.1.selection.table', 'complications'),
            'flows[0].phases[1].selection.table: every entry of table "complications" must name a sceneType',
        ];
        yield 'Scene Type tracker outside the flow' => [self::example('flows.0.trackers', ['alarm', 'chaos']), 'sceneTypes[0].closing[0].options[0].effects[0].tracker: tracker "edge" is not one of the trackers of flow "heist"'];
        yield 'Scene Type shortcut outside the flow oracles' => [self::example('flows.0.oracles', ['fate']), 'sceneTypes[1].oracles[1]: oracle "complications" is not one of the oracles of flow "heist"'];
        yield 'tracker placeholder outside the flow' => [
            self::example('flows.0.phases.1.sceneOpening.1.prompt', 'Noise at {tracker:noise}', self::withNoise()),
            'flows[0].phases[1].sceneOpening[1].prompt: placeholder {tracker:noise}: tracker "noise" is not one of the trackers of flow "heist"',
        ];
    }

    /**
     * @param array<mixed>     $release
     * @param non-empty-string $publishError
     */
    #[Test]
    #[DataProvider('coherenceOnly')]
    public function aFlowCoherenceIssuePassesTheItemRulesAndFailsPublishing(array $release, string $publishError): void
    {
        ReleaseContent::checkItemRules($release);

        $this->expectException(InvalidReleaseContent::class);
        $this->expectExceptionMessageIsOrContains($publishError);
        ReleaseContent::fromArray($release);
    }

    /**
     * @return iterable<string, array{array<mixed>, non-empty-string}>
     */
    public static function itemRuleViolations(): iterable
    {
        yield 'missing section' => [ReleaseArrays::without(self::example(), 'trackers'), 'trackers: required'];
        yield 'selection naming an unknown Scene Type' => [self::example('flows.0.phases.0.selection.sceneTypes.0', 'dawn'), 'flows[0].phases[0].selection.sceneTypes[0]: unknown Scene Type "dawn"'];
        yield 'unknown entry Scene Type' => [self::example('oracles.tables.1.entries.0.sceneType', 'dawn'), 'oracles.tables[1].entries[0].sceneType: unknown Scene Type "dawn"'];
        yield 'unknown tracker placeholder' => [self::example('sceneTypes.0.setup.0.title', 'At {tracker:noise}'), 'sceneTypes[0].setup[0].title: placeholder {tracker:noise}: unknown tracker "noise"'];
        yield 'unknown step placeholder' => [self::example('flows.0.phases.2.phaseClosing.0.title', 'Was {step:nowhere} worth it?'), 'flows[0].phases[2].phaseClosing[0].title: placeholder {step:nowhere}: unknown step "nowhere"'];
        yield 'unknown theme' => [self::example('theme', 'pastel'), 'theme: must be one of'];
    }

    /**
     * @param array<mixed>     $release
     * @param non-empty-string $error
     */
    #[Test]
    #[DataProvider('itemRuleViolations')]
    public function anItemRuleViolationFailsBothWithTheSameMessage(array $release, string $error): void
    {
        $itemRules = $this->rejection(static fn () => ReleaseContent::checkItemRules($release));
        $publish = $this->rejection(static fn (): ReleaseContent => ReleaseContent::fromArray($release));

        self::assertStringStartsWith($error, $itemRules);
        self::assertSame($publish, $itemRules);
    }

    #[Test]
    public function aValidReleasePassesTheItemRules(): void
    {
        $this->expectNotToPerformAssertions();

        ReleaseContent::checkItemRules(self::example());
    }

    #[Test]
    public function aValidSchemaVersion1DocumentPassesTheItemRules(): void
    {
        $this->expectNotToPerformAssertions();

        ReleaseContent::checkItemRules(ReleaseArrays::fixture('valid/contract-doc-example'));
    }

    #[Test]
    public function anInvalidSchemaVersion1DocumentFailsWithTheMessageOfFromArray(): void
    {
        $release = ReleaseArrays::with(ReleaseArrays::fixture('valid/contract-doc-example'), 'oracles.tables.0.key', 'Not A Key');

        $itemRules = $this->rejection(static fn () => ReleaseContent::checkItemRules($release));
        $publish = $this->rejection(static fn (): ReleaseContent => ReleaseContent::fromArray($release));

        self::assertStringStartsWith('oracles.tables: ', $itemRules);
        self::assertSame($publish, $itemRules);
    }

    private function rejection(\Closure $validation): string
    {
        try {
            $validation();
        } catch (InvalidReleaseContent $invalidReleaseContent) {
            return $invalidReleaseContent->getMessage();
        }

        self::fail('Expected InvalidReleaseContent.');
    }
}
