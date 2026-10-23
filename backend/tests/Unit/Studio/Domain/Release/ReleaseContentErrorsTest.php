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
 * Every error the validation query can reach, not only the first. Runs against
 * valid/v2-contract-doc-example.json: one flow "heist" with phases legwork, the-heist and getaway.
 */
#[CoversClass(ReleaseContent::class)]
#[CoversClass(ReleaseVersion2::class)]
#[CoversClass(FlowReferences::class)]
#[CoversClass(InvalidReleaseContent::class)]
final class ReleaseContentErrorsTest extends TestCase
{
    /**
     * @return array<mixed>
     */
    private static function example(string $path, mixed $value): array
    {
        return ReleaseArrays::with(ReleaseArrays::fixture('valid/v2-contract-doc-example'), $path, $value);
    }

    /**
     * @param array<mixed> $release
     *
     * @return list<string>
     */
    private function paths(array $release): array
    {
        return array_map(static fn (InvalidReleaseContent $error): string => $error->path(), ReleaseContent::errors($release));
    }

    #[Test]
    public function aValidReleaseHasNoErrors(): void
    {
        self::assertSame([], ReleaseContent::errors(ReleaseArrays::fixture('valid/v2-contract-doc-example')));
        self::assertSame([], ReleaseContent::errors(ReleaseArrays::fixture('valid/contract-doc-example')));
    }

    #[Test]
    public function anErrorKeepsItsMessageAndGivesItsPathAndReason(): void
    {
        $error = InvalidReleaseContent::at('flows[0].key', 'duplicate flow key "x"');

        self::assertSame('flows[0].key: duplicate flow key "x"', $error->getMessage());
        self::assertSame('flows[0].key', $error->path());
        self::assertSame('duplicate flow key "x"', $error->reason());
    }

    #[Test]
    public function sectionsNoLaterSectionReadsAreAllReported(): void
    {
        $release = self::example('trackers.0.name', '');
        $release = ReleaseArrays::with($release, 'factSlots.0.label', '');
        $release = ReleaseArrays::with($release, 'tags', [['key' => 'Not A Key', 'label' => 'Tag']]);
        $release = ReleaseArrays::with($release, 'theme', 'pastel');

        // Trackers feed the sheet, the checks and the flows, so nothing after them is reached but the independent sections.
        self::assertSame(['trackers[0].name', 'factSlots[0].label', 'tags[0].key', 'theme'], $this->paths($release));
    }

    #[Test]
    public function aFailedSheetStopsTheChecksThatReadIt(): void
    {
        $release = self::example('sheet', ['fields' => 'nope']);
        $release = ReleaseArrays::with($release, 'checks.0.label', '');

        $paths = $this->paths($release);

        self::assertCount(1, $paths);
        self::assertStringStartsWith('sheet', $paths[0]);
    }

    #[Test]
    public function aFailedCheckStopsTheStepsThatReadIt(): void
    {
        $release = self::example('checks.0.label', '');
        $release = ReleaseArrays::with($release, 'sceneTypes.0.setup.0.title', '');

        // The steps and the flows read the checks, so only the check is reported.
        self::assertSame(['checks[0].label'], $this->paths($release));
    }

    #[Test]
    public function aFailedSceneTypeHeadStopsTheChecks(): void
    {
        $release = self::example('sceneTypes.0.name', '');
        $release = ReleaseArrays::with($release, 'checks.0.label', '');

        self::assertSame(['sceneTypes[0].name'], $this->paths($release));
    }

    #[Test]
    public function everySceneTypePartIsCheckedOnItsOwn(): void
    {
        $release = self::example('sceneTypes.0.setup.0.title', '');
        $release = ReleaseArrays::with($release, 'sceneTypes.1.play.0.title', '');

        self::assertSame(['sceneTypes[0].setup[0].title', 'sceneTypes[1].play[0].title'], $this->paths($release));
    }

    #[Test]
    public function eachFlowReportsItsOwnCoherenceError(): void
    {
        $release = self::example('flows.0.trackers', ['alarm', 'chaos']);
        $flows = $release['flows'];
        self::assertIsArray($flows);
        $second = $flows[0];
        self::assertIsArray($second);
        $second['key'] = 'heist-again';
        unset($second['default']);
        $release['flows'] = [...$flows, $second];

        $errors = ReleaseContent::errors($release);

        self::assertCount(2, $errors);
        self::assertStringContainsString('flow "heist"', $errors[0]->reason());
        self::assertStringContainsString('flow "heist-again"', $errors[1]->reason());
    }

    #[Test]
    public function aFlowIsReportedOnceWhateverItsErrors(): void
    {
        $release = self::example('flows.0.trackers', ['alarm', 'chaos']);
        $release = ReleaseArrays::with($release, 'flows.0.oracles', ['fate']);

        self::assertCount(1, ReleaseContent::errors($release));
    }

    /**
     * @return iterable<string, array{array<mixed>}>
     */
    public static function invalidReleases(): iterable
    {
        yield 'not an object' => [['a', 'b']];
        yield 'nothing at all' => [[]];
        yield 'unsupported version' => [['schemaVersion' => 9]];
        yield 'missing section' => [ReleaseArrays::without(ReleaseArrays::fixture('valid/v2-contract-doc-example'), 'trackers')];
        yield 'broken game system' => [self::example('gameSystem.key', 'Not A Key')];
        yield 'several item rules' => [ReleaseArrays::with(self::example('trackers.0.name', ''), 'theme', 'pastel')];
        yield 'item rule and flow coherence' => [ReleaseArrays::with(self::example('flows.0.trackers', ['alarm', 'chaos']), 'theme', 'pastel')];
        yield 'placeholder' => [self::example('sceneTypes.0.setup.0.title', 'At {tracker:noise}')];
        yield 'schema version 1' => [ReleaseArrays::with(ReleaseArrays::fixture('valid/contract-doc-example'), 'oracles.tables.0.key', 'Not A Key')];
    }

    /**
     * @param array<mixed> $release
     */
    #[Test]
    #[DataProvider('invalidReleases')]
    public function theFirstErrorIsTheOneAPublishWouldRaise(array $release): void
    {
        $errors = ReleaseContent::errors($release);

        try {
            ReleaseContent::fromArray($release);
            self::fail('The release must be invalid.');
        } catch (InvalidReleaseContent $publish) {
            self::assertNotSame([], $errors);
            self::assertSame($publish->getMessage(), $errors[0]->getMessage());
            self::assertSame($publish->path(), $errors[0]->path());
        }
    }
}
