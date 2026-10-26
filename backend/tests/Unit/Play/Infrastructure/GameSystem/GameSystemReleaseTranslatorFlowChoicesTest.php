<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Infrastructure\GameSystem;

use App\Play\Domain\GameSystem\Flow\CheckStep;
use App\Play\Domain\GameSystem\Flow\ChoiceStep;
use App\Play\Domain\GameSystem\Flow\PromptStep;
use App\Play\Domain\GameSystem\Flow\SheetEffect;
use App\Play\Domain\GameSystem\Flow\SheetOperation;
use App\Play\Domain\GameSystem\Flow\TableStep;
use App\Play\Domain\GameSystem\InvalidGameSystemRelease;
use App\Play\Infrastructure\GameSystem\GameSystemReleaseTranslator;
use App\Tests\Support\Play\ReleaseViews;
use App\Tests\Support\Studio\ReleaseArrays;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Studio publishes sheet effects on choice options and prompts, check sets and tables by Scene Type
 * (ADR 0042): the translator reads all three, and refuses a sheet effect on the skip option and a
 * malformed check set or table map with a clear error. Run against valid/v2-flow-choices with the
 * Town steps swapped for the one under test.
 */
#[CoversClass(GameSystemReleaseTranslator::class)]
final class GameSystemReleaseTranslatorFlowChoicesTest extends TestCase
{
    private const string UNREADABLE = 'GameSystem "flow-choices" v1 cannot be read by Play: ';

    private const array SHEET = ['kind' => 'sheet', 'field' => 'xp', 'op' => 'add', 'value' => 1];

    /**
     * @return iterable<string, array{list<array<string, mixed>>, string}> the Town play steps and the expected message
     */
    public static function refusedShapes(): iterable
    {
        $at = 'sceneTypes[0].play[0]';
        $options = [['key' => 'yes', 'label' => 'Yes'], ['key' => 'no', 'label' => 'No', 'effects' => [self::SHEET]]];

        yield 'a sheet effect on the skip option' => [[['key' => 'learned', 'kind' => 'choice', 'title' => 'Learned?', 'skip' => 'no', 'options' => $options]], $at.'.options[1].effects[0]: a sheet effect is allowed only in '];

        $check = ['key' => 'defy', 'kind' => 'check', 'title' => 'Defy'];
        yield 'a check and a check set' => [[[...$check, 'check' => 'grit', 'checks' => ['grit', 'wits']]], $at.': must set exactly one of check and checks.'];
        yield 'a check set of one' => [[[...$check, 'checks' => ['grit']]], $at.'.checks: must list 2 to 10 distinct checks.'];
        yield 'a check set with a repeat' => [[[...$check, 'checks' => ['grit', 'grit']]], $at.'.checks: must list 2 to 10 distinct checks.'];
        yield 'a check set of eleven' => [[[...$check, 'checks' => array_map(static fn (int $n): string => 'check-'.$n, range(1, 11))]], $at.'.checks: must list 2 to 10 distinct checks.'];
        yield 'a check set that is not a list' => [[[...$check, 'checks' => 'grit']], $at.'.checks: must be a list.'];

        yield 'a check set with an unknown check' => [[[...$check, 'checks' => ['grit', 'ghost']]], $at.'.checks[1]: unknown check "ghost".'];
        yield 'a check set with other band keys' => [[[...$check, 'checks' => ['grit', 'luck']]], $at.'.checks[1]: check "luck" has other band keys than the first check of the set.'];
        yield 'a check set with an outcome of no band' => [[[...$check, 'checks' => ['grit', 'wits'], 'outcomes' => [['band' => 'bad', 'next' => 'x']]]], $at.'.outcomes[0].band: unknown band "bad" of check "grit".'];

        $table = ['key' => 'trouble', 'kind' => 'table', 'title' => 'Trouble', 'table' => 'any-trouble'];
        yield 'an empty table map' => [[[...$table, 'tableBySceneType' => []]], 'sceneTypes[0].play[0].tableBySceneType: must map at least one Scene Type.'];
        yield 'a table map with branches' => [[[...$table, 'tableBySceneType' => ['wild' => 'wild-trouble'], 'branches' => [['entry' => 'a', 'next' => 'x']]]], $at.'.tableBySceneType: cannot go with branches.'];
        yield 'a table map to something else than a table key' => [[[...$table, 'tableBySceneType' => ['wild' => 5]]], $at.'.tableBySceneType.wild: must be a string.'];
    }

    /**
     * @param list<array<string, mixed>> $play
     */
    #[Test]
    #[DataProvider('refusedShapes')]
    public function aMalformedShapeIsRefused(array $play, string $expectedMessage): void
    {
        /** @var array<string, mixed> $content */
        $content = ReleaseArrays::with(ReleaseViews::fixtureContent('valid/v2-flow-choices'), 'sceneTypes.0.play', $play);

        $this->expectException(InvalidGameSystemRelease::class);
        $this->expectExceptionMessageIsOrContains(self::UNREADABLE.$expectedMessage);

        new GameSystemReleaseTranslator()->translate(ReleaseViews::of($content));
    }

    #[Test]
    public function theSheetEffectsOfAChoiceOptionAndAPromptAreRead(): void
    {
        $options = [['key' => 'yes', 'label' => 'Yes', 'effects' => [self::SHEET]], ['key' => 'no', 'label' => 'No']];
        $play = [
            ['key' => 'learned', 'kind' => 'choice', 'title' => 'Learned?', 'skip' => 'no', 'options' => $options],
            ['key' => 'reflect', 'kind' => 'prompt', 'title' => 'Cost?', 'effects' => [self::SHEET]],
            ['key' => 'note', 'kind' => 'prompt', 'title' => 'Note'],
        ];
        /** @var array<string, mixed> $content */
        $content = ReleaseArrays::with(ReleaseViews::fixtureContent('valid/v2-flow-choices'), 'sceneTypes.0.play', $play);

        $steps = new GameSystemReleaseTranslator()->translate(ReleaseViews::of($content))->sceneType('town')->play->steps ?? self::fail('No Scene Type "town".');
        [$choice, $reflect, $note] = $steps;

        self::assertInstanceOf(ChoiceStep::class, $choice);
        self::assertInstanceOf(PromptStep::class, $reflect);
        self::assertInstanceOf(PromptStep::class, $note);
        self::assertSame([true, false, true, false, true], [$choice->changesSheet(), $choice->option('no')?->changesSheet(), $choice->option('yes')?->changesSheet(), $note->changesSheet(), $reflect->changesSheet()]);
        self::assertEquals([new SheetEffect('xp', SheetOperation::Add, 1)], $choice->option('yes')?->outcome->effects);
    }

    #[Test]
    public function aCheckSetAndATableByASceneTypeAreRead(): void
    {
        $play = [
            ['key' => 'defy', 'kind' => 'check', 'title' => 'Defy', 'checks' => ['grit', 'wits']],
            ['key' => 'single', 'kind' => 'check', 'title' => 'Single', 'check' => 'grit'],
            ['key' => 'trouble', 'kind' => 'table', 'title' => 'Trouble', 'table' => 'any-trouble', 'tableBySceneType' => ['wild' => 'wild-trouble', 'town' => 'any-trouble']],
            ['key' => 'plain', 'kind' => 'table', 'title' => 'Plain', 'table' => 'any-trouble'],
        ];
        /** @var array<string, mixed> $content */
        $content = ReleaseArrays::with(ReleaseViews::fixtureContent('valid/v2-flow-choices'), 'sceneTypes.0.play', $play);

        $steps = new GameSystemReleaseTranslator()->translate(ReleaseViews::of($content))->sceneType('town')->play->steps ?? self::fail('No Scene Type "town".');
        [$set, $single, $trouble, $plain] = $steps;

        self::assertInstanceOf(CheckStep::class, $set);
        self::assertInstanceOf(CheckStep::class, $single);
        self::assertInstanceOf(TableStep::class, $trouble);
        self::assertInstanceOf(TableStep::class, $plain);
        self::assertSame([['grit', 'wits'], true, null], [$set->checks, $set->isSet(), $set->singleCheck()]);
        self::assertSame([['grit'], false, 'grit'], [$single->checks, $single->isSet(), $single->singleCheck()]);
        self::assertSame([['wild' => 'wild-trouble', 'town' => 'any-trouble'], 'wild-trouble', 'any-trouble', ['any-trouble', 'wild-trouble']], [$trouble->tableBySceneType, $trouble->tableFor('wild'), $trouble->tableFor('market'), $trouble->tables()]);
        self::assertSame([[], 'any-trouble', 'any-trouble'], [$plain->tableBySceneType, $plain->tableFor('wild'), $plain->tableFor(null)]);
        self::assertSame('any-trouble', $trouble->tableFor(null));
    }

    #[Test]
    public function theSameStepsWithoutTheNewShapesAreRead(): void
    {
        $play = [
            ['key' => 'learned', 'kind' => 'choice', 'title' => 'Learned?', 'skip' => 'no', 'options' => [['key' => 'yes', 'label' => 'Yes'], ['key' => 'no', 'label' => 'No']]],
            ['key' => 'reflect', 'kind' => 'prompt', 'title' => 'Cost?'],
            ['key' => 'defy', 'kind' => 'check', 'title' => 'Defy', 'check' => 'grit'],
            ['key' => 'trouble', 'kind' => 'table', 'title' => 'Trouble', 'table' => 'any-trouble'],
        ];
        /** @var array<string, mixed> $content */
        $content = ReleaseArrays::with(ReleaseViews::fixtureContent('valid/v2-flow-choices'), 'sceneTypes.0.play', $play);

        $sceneType = new GameSystemReleaseTranslator()->translate(ReleaseViews::of($content))->sceneType('town') ?? self::fail('No Scene Type "town".');

        self::assertCount(4, $sceneType->play->steps);
    }
}
