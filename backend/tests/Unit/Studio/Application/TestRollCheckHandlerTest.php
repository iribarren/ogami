<?php

declare(strict_types=1);

namespace App\Tests\Unit\Studio\Application;

use App\Studio\Application\CheckTestRollBandView;
use App\Studio\Application\CheckTestRollView;
use App\Studio\Application\OwnedGameSystems;
use App\Studio\Application\TestRollCheck;
use App\Studio\Application\TestRollCheckHandler;
use App\Studio\Domain\Check\CheckTestRollFailed;
use App\Studio\Domain\Check\InvalidTestRoll;
use App\Studio\Domain\GameSystem\CheckNotFound;
use App\Studio\Domain\GameSystem\GameSystem;
use App\Studio\Domain\GameSystem\GameSystemId;
use App\Studio\Domain\GameSystem\GameSystemNotFound;
use App\Studio\Domain\Release\InvalidReleaseContent;
use App\Studio\Domain\Release\ReleaseContent;
use App\Tests\Support\Randomness\ScriptedRandomNumberGenerator;
use App\Tests\Support\Studio\InMemoryGameSystemRepository;
use App\Tests\Support\Studio\ReleaseArrays;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Test rolls on drafts that copy valid/v2-checks: the checks skill (1d10 + {hands} + {edge}, a
 * difficulty 9 to 29 defaulting to 13, bands bounded by it) and luck (d%, bounded by 20 and the
 * alarm clock), the number hands (0 to 10, default 4), the derived edge ({hands}/2) and the alarm
 * clock of 6 segments.
 */
#[CoversClass(TestRollCheckHandler::class)]
#[CoversClass(CheckTestRollView::class)]
#[CoversClass(CheckTestRollBandView::class)]
final class TestRollCheckHandlerTest extends TestCase
{
    private InMemoryGameSystemRepository $gameSystems;
    private OwnedGameSystems $owned;
    private int $drafts = 0;

    protected function setUp(): void
    {
        $this->gameSystems = new InMemoryGameSystemRepository();
        $this->owned = new OwnedGameSystems($this->gameSystems);
    }

    #[Test]
    public function aRollWithoutSamplesUsesTheDefaultsOfTheSavedDraft(): void
    {
        $this->draft('mine');

        $view = $this->roll([7], new TestRollCheck('ann', 'mine', 'skill', [], [], null, null));

        self::assertSame(['1d10', 7], [$view->dice->expression, $view->dice->total]);
        self::assertSame([6, 0, 13, 13], [$view->modifier, $view->situational, $view->difficulty, $view->total]);
        self::assertEquals(
            [new CheckTestRollBandView('fail', 'Fail', 12), new CheckTestRollBandView('success', 'Success', 17), new CheckTestRollBandView('crit', 'Critical success', null)],
            $view->bands,
        );
        self::assertSame('success', $view->band);
    }

    #[Test]
    public function theSamplesTheDifficultyAndTheSituationalModifierShapeTheRoll(): void
    {
        $this->draft('mine');

        $view = $this->roll([10], new TestRollCheck('ann', 'mine', 'skill', ['hands' => 10], [], 29, -4));

        // 10 + (10 + 5) - 4 = 21, against bounds 28 and 33.
        self::assertSame([15, -4, 29, 21, 'fail'], [$view->modifier, $view->situational, $view->difficulty, $view->total, $view->band]);
        self::assertSame([28, 33, null], array_map(static fn (CheckTestRollBandView $band): ?int => $band->bound, $view->bands));
    }

    #[Test]
    public function aTrackerBoundReadsTheTrackerSample(): void
    {
        $this->draft('mine');

        $view = $this->roll([50], new TestRollCheck('ann', 'mine', 'luck', [], ['alarm' => 4], null, null));

        self::assertSame([20, 4, null], array_map(static fn (CheckTestRollBandView $band): ?int => $band->bound, $view->bands));
        self::assertNull($view->difficulty);
        self::assertSame('good', $view->band);
    }

    #[Test]
    public function aTestRollNeverChangesTheDraft(): void
    {
        $draft = $this->draft('mine');
        $before = [$draft->version(), $draft->updatedAt(), $draft->document()];

        $this->roll([3], new TestRollCheck('ann', 'mine', 'skill', ['hands' => 1], [], null, null));

        self::assertSame($before, [$draft->version(), $draft->updatedAt(), $draft->document()]);
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DoesNotPerformAssertions]
    public function anotherUsersDraftOrAnUnknownOneIsNotFound(): void
    {
        $this->draft('mine');

        foreach ([['bob', 'mine'], ['ann', 'nothing']] as [$owner, $key]) {
            try {
                $this->roll([], new TestRollCheck($owner, $key, 'skill', [], [], null, null));
                self::fail('The draft is not found.');
            } catch (GameSystemNotFound) {
            }
        }
    }

    #[Test]
    public function aCheckTheDraftLacksIsNotFound(): void
    {
        $this->draft('mine');

        $this->expectException(CheckNotFound::class);

        $this->roll([], new TestRollCheck('ann', 'mine', 'ghost', [], [], null, null));
    }

    #[Test]
    public function aVersion1DraftIsRefusedOnTheSchemaVersion(): void
    {
        $this->draft('journal', 'valid/contract-doc-example');

        $this->expectException(InvalidReleaseContent::class);
        $this->expectExceptionMessageIsOrContains('schemaVersion: checks need schema version 2');

        $this->roll([], new TestRollCheck('ann', 'journal', 'skill', [], [], null, null));
    }

    /**
     * @return iterable<string, array{TestRollCheck, string}>
     */
    public static function badRequests(): iterable
    {
        yield 'a sample out of range' => [new TestRollCheck('ann', 'mine', 'skill', ['hands' => 11], [], null, null), 'sheet.hands: must be 0 to 10, got 11'];
        yield 'an unknown tracker' => [new TestRollCheck('ann', 'mine', 'skill', [], ['ghost' => 1], null, null), 'trackers.ghost: is not a tracker of the draft'];
        yield 'a difficulty that is a string' => [new TestRollCheck('ann', 'mine', 'skill', [], [], '13', null), 'difficulty: must be an integer'];
        yield 'a difficulty out of range' => [new TestRollCheck('ann', 'mine', 'skill', [], [], 30, null), 'difficulty: must be 9 to 29, got 30'];
        yield 'a difficulty on a check without one' => [new TestRollCheck('ann', 'mine', 'luck', [], [], 5, null), 'difficulty: check "luck" declares no difficulty'];
        yield 'a situational modifier that is a float' => [new TestRollCheck('ann', 'mine', 'skill', [], [], null, 1.5), 'situational: must be an integer'];
        yield 'a situational modifier out of range' => [new TestRollCheck('ann', 'mine', 'skill', [], [], null, 101), 'situational: must be -100 to 100, got 101'];
        yield 'samples that are not an object' => [new TestRollCheck('ann', 'mine', 'skill', 'x', [], null, null), 'sheet: must be an object'];
    }

    #[Test]
    #[DataProvider('badRequests')]
    public function aValueTheCheckOrTheSheetDoesNotAcceptIsRefusedOnItsPathBeforeRolling(TestRollCheck $query, string $message): void
    {
        $this->draft('mine');

        $this->expectException(InvalidTestRoll::class);
        $this->expectExceptionMessageIsOrContains($message);

        // A generator with no number left throws a LogicException if the dice are rolled.
        $this->roll([], $query);
    }

    #[Test]
    public function aFormulaThatFailsForTheSamplesRollsNothing(): void
    {
        $draft = $this->draft('mine');
        // edge is {hands}/2, so hands 1 makes the modifier divide by zero.
        $draft->saveCheck('luck', ['key' => 'luck', 'label' => 'Luck', 'dice' => 'd%', 'modifier' => '10 / {edge}', 'bands' => [['key' => 'only', 'label' => 'Only']]], new \DateTimeImmutable());

        $this->expectException(CheckTestRollFailed::class);
        $this->expectExceptionMessageIsOrContains('Check "luck" cannot be test-rolled: ');

        $this->roll([], new TestRollCheck('ann', 'mine', 'luck', ['hands' => 1], [], null, null));
    }

    /**
     * @param list<int> $dice
     */
    private function roll(array $dice, TestRollCheck $query): CheckTestRollView
    {
        return (new TestRollCheckHandler($this->owned, new ScriptedRandomNumberGenerator(...$dice)))($query);
    }

    private function draft(string $key, string $fixture = 'valid/v2-checks'): GameSystem
    {
        $source = ReleaseContent::fromArray(ReleaseArrays::fixture($fixture))->toArray();
        $draft = GameSystem::createFromRelease(GameSystemId::fromString(\sprintf('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f%04x', 0x300 + ++$this->drafts)), $key, 'Draft', null, 'ann', $source, new \DateTimeImmutable('2026-10-17T10:00:00+00:00'));
        $this->gameSystems->add($draft);

        return $draft;
    }
}
