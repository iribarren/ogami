<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Infrastructure\GameSystem;

use App\Play\Domain\GameSystem\Flow\ChoiceStep;
use App\Play\Domain\GameSystem\Flow\PromptStep;
use App\Play\Domain\GameSystem\Flow\SheetEffect;
use App\Play\Domain\GameSystem\Flow\SheetOperation;
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
 * (ADR 0042): the translator reads the sheet effects and refuses the other two shapes, and a sheet
 * effect on the skip option, with a clear error, run against
 * valid/v2-flow-choices with the Town steps swapped for the one under test.
 */
#[CoversClass(GameSystemReleaseTranslator::class)]
final class GameSystemReleaseTranslatorFlowChoicesTest extends TestCase
{
    private const string UNREADABLE = 'GameSystem "flow-choices" v1 cannot be read by Play: ';

    private const array SHEET = ['kind' => 'sheet', 'field' => 'xp', 'op' => 'add', 'value' => 1];

    /**
     * @return iterable<string, array{list<array<string, mixed>>, string}> the Town play steps and the expected message
     */
    public static function unplayedShapes(): iterable
    {
        $at = 'sceneTypes[0].play[0]';
        $options = [['key' => 'yes', 'label' => 'Yes'], ['key' => 'no', 'label' => 'No', 'effects' => [self::SHEET]]];

        yield 'a sheet effect on the skip option' => [[['key' => 'learned', 'kind' => 'choice', 'title' => 'Learned?', 'skip' => 'no', 'options' => $options]], $at.'.options[1].effects[0]: a sheet effect is allowed only in '];
        yield 'a check set' => [[['key' => 'defy', 'kind' => 'check', 'title' => 'Defy', 'checks' => ['grit', 'wits']]], $at.'.checks: a check set is not played yet.'];
        yield 'a table by Scene Type' => [[['key' => 'trouble', 'kind' => 'table', 'title' => 'Trouble', 'table' => 'any-trouble', 'tableBySceneType' => ['wild' => 'wild-trouble']]], $at.'.tableBySceneType: a table by Scene Type is not played yet.'];
    }

    /**
     * @param list<array<string, mixed>> $play
     */
    #[Test]
    #[DataProvider('unplayedShapes')]
    public function aReleaseUsingAShapeThatIsNotPlayedYetIsRefused(array $play, string $expectedMessage): void
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
