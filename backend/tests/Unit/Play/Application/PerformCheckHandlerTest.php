<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Application;

use App\Formula\Domain\Formula;
use App\Play\Application\CampaignNotFound;
use App\Play\Application\PerformCheck;
use App\Play\Application\PerformCheckHandler;
use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\CampaignId;
use App\Play\Domain\Campaign\Characters\CharacterId;
use App\Play\Domain\Campaign\Characters\InvalidCharacter;
use App\Play\Domain\Campaign\Characters\UnknownCharacter;
use App\Play\Domain\Campaign\NoCurrentScene;
use App\Play\Domain\Campaign\PinnedRelease;
use App\Play\Domain\GameSystem\Check\Check;
use App\Play\Domain\GameSystem\Check\CheckBand;
use App\Play\Domain\GameSystem\Check\CheckDifficultyNotDeclared;
use App\Play\Domain\GameSystem\Check\CheckDifficultyOutOfRange;
use App\Play\Domain\GameSystem\Check\CheckEvaluationFailed;
use App\Play\Domain\GameSystem\Check\SituationalModifierOutOfRange;
use App\Play\Domain\GameSystem\Check\UnknownCheck;
use App\Play\Domain\GameSystem\GameSystemSnapshot;
use App\Play\Domain\GameSystem\Sheet\DerivedField;
use App\Play\Domain\GameSystem\Sheet\NumberField;
use App\Play\Domain\GameSystem\Sheet\SheetTemplate;
use App\Play\Domain\Journal\JournalEntryAlreadyExists;
use App\Randomness\Domain\DiceExpression;
use App\Tests\Support\Play\Snapshots;
use App\Tests\Support\Randomness\ScriptedRandomNumberGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * "campaign-3" plays the release "rolling" (the checks "skill" and "luck" of Snapshots::withChecks,
 * plus "broken", whose modifier divides by zero) in scene 1 of session 1. Vi (hands 6, so edge 3
 * and a skill modifier of 9) joined before the session and is not in the scene; Ada (hands 4, so a
 * skill modifier of 6) joined during it and is.
 */
#[CoversClass(PerformCheck::class)]
#[CoversClass(PerformCheckHandler::class)]
final class PerformCheckHandlerTest extends JournalTestCase
{
    private const string VI = 'char-vi';
    private const string ADA = 'char-ada';

    protected function setUp(): void
    {
        parent::setUp();
        $withChecks = Snapshots::withChecks('rolling', 'Rolling', 1);
        $sheet = new SheetTemplate([
            new NumberField('hands', 'Hands', default: 4),
            new NumberField('zero', 'Zero'),
            new DerivedField('edge', 'Edge', null, null, Formula::fromString('{hands} / 2')),
        ]);
        $release = new GameSystemSnapshot('rolling', 'Rolling', 1, null, [], [], sheet: $sheet, checks: [
            ...$withChecks->checks(),
            new Check('broken', 'Broken', null, DiceExpression::fromString('1d6'), Formula::fromString('{hands} / {zero}'), null, [new CheckBand('only', 'Only')]),
        ]);
        $this->releases->add($release);

        $campaign = Campaign::create(CampaignId::fromString('campaign-3'), 'user-1', 'The job', PinnedRelease::of('rolling', 1, 'Rolling'), new \DateTimeImmutable('2026-10-05T10:00:00+00:00'));
        $campaign->addCharacter(CharacterId::fromString(self::VI), 'Vi', [], [], ['hands' => 6], $release);
        $campaign->startSession(new \DateTimeImmutable('2026-10-06T09:00:00+00:00'));
        $campaign->startScene('At the gate', new \DateTimeImmutable('2026-10-06T09:05:00+00:00'));
        $campaign->addCharacter(CharacterId::fromString(self::ADA), 'Ada', [], [], [], $release);
        $this->campaigns->add($campaign);
    }

    #[Test]
    public function itPerformsTheCheckForACharacterOutsideTheSceneAndRecordsIt(): void
    {
        // 1d10 shows 7; Vi's modifier is 9 (6 + edge 3), the difficulty 15 and the situational modifier 2.
        $handler = new PerformCheckHandler($this->journal, new ScriptedRandomNumberGenerator(7));

        $handler(new PerformCheck('entry-1', 'campaign-3', 'user-1', 'skill', self::VI, 15, 2));

        $entry = $this->onlyEntryOf('campaign-3');
        self::assertSame('entry-1', $entry->id()->toString());
        self::assertSame([1, 1], [$entry->sessionNumber(), $entry->sceneNumber()]);
        self::assertEquals(new \DateTimeImmutable(self::NOW), $entry->recordedAt());
        self::assertSame([
            'kind' => 'check',
            'characterId' => self::VI,
            'characterName' => 'Vi',
            'checkKey' => 'skill',
            'checkLabel' => 'Skill check',
            'expression' => '1d10',
            'groups' => [['notation' => '1d10', 'sides' => 10, 'dice' => [['value' => 7, 'kept' => true]], 'subtotal' => 7]],
            'diceTotal' => 7,
            'modifier' => 9,
            'situational' => 2,
            'difficulty' => 15,
            'total' => 18,
            'bandKey' => 'success',
            'bandLabel' => 'Success',
            'hint' => 'A stat against a Difficulty Value.',
            'modifierFormula' => '{hands} + {edge}',
            'modifierTerms' => [
                ['reference' => '{hands}', 'label' => 'Hands', 'value' => 6],
                ['reference' => '{edge}', 'label' => 'Edge', 'value' => 3],
            ],
        ], $entry->content()->toArray());
    }

    #[Test]
    public function aCharacterInTheSceneWithTheDefaultDifficultyAndNoSituationalModifier(): void
    {
        $handler = new PerformCheckHandler($this->journal, new ScriptedRandomNumberGenerator(8));

        $handler(new PerformCheck('entry-1', 'campaign-3', 'user-1', 'skill', self::ADA));

        $content = $this->onlyEntryOf('campaign-3')->content()->toArray();
        self::assertSame([6, 0, 13, 14, 'success'], [$content['modifier'], $content['situational'], $content['difficulty'], $content['total'], $content['bandKey']]);
    }

    #[Test]
    public function aCheckWithoutADifficultyRecordsNone(): void
    {
        $handler = new PerformCheckHandler($this->journal, new ScriptedRandomNumberGenerator(55));

        $handler(new PerformCheck('entry-1', 'campaign-3', 'user-1', 'luck', self::ADA));

        $content = $this->onlyEntryOf('campaign-3')->content()->toArray();
        self::assertSame([0, null, 55, 'good'], [$content['modifier'], $content['difficulty'], $content['total'], $content['bandKey']]);
    }

    /**
     * @return iterable<string, array{PerformCheck, class-string<\Throwable>}>
     */
    public static function failures(): iterable
    {
        yield 'an unknown check' => [new PerformCheck('entry-1', 'campaign-3', 'user-1', 'ghost', self::ADA), UnknownCheck::class];
        yield 'an unknown Character' => [new PerformCheck('entry-1', 'campaign-3', 'user-1', 'skill', 'nobody'), UnknownCharacter::class];
        yield 'a blank Character id' => [new PerformCheck('entry-1', 'campaign-3', 'user-1', 'skill', ' '), InvalidCharacter::class];
        yield 'a difficulty for a check without one' => [new PerformCheck('entry-1', 'campaign-3', 'user-1', 'luck', self::ADA, 5), CheckDifficultyNotDeclared::class];
        yield 'a difficulty under the min' => [new PerformCheck('entry-1', 'campaign-3', 'user-1', 'skill', self::ADA, 8), CheckDifficultyOutOfRange::class];
        yield 'a difficulty over the max' => [new PerformCheck('entry-1', 'campaign-3', 'user-1', 'skill', self::ADA, 30), CheckDifficultyOutOfRange::class];
        yield 'a situational modifier under -100' => [new PerformCheck('entry-1', 'campaign-3', 'user-1', 'skill', self::ADA, null, -101), SituationalModifierOutOfRange::class];
        yield 'a situational modifier over 100' => [new PerformCheck('entry-1', 'campaign-3', 'user-1', 'skill', self::ADA, null, 101), SituationalModifierOutOfRange::class];
        yield 'a modifier that cannot be evaluated' => [new PerformCheck('entry-1', 'campaign-3', 'user-1', 'broken', self::ADA), CheckEvaluationFailed::class];
        yield 'a campaign without a current scene' => [new PerformCheck('entry-1', 'campaign-4', 'user-1', 'skill', self::ADA), NoCurrentScene::class];
        yield 'another player\'s campaign' => [new PerformCheck('entry-1', 'campaign-3', 'user-2', 'skill', self::ADA), CampaignNotFound::class];
    }

    /**
     * @param class-string<\Throwable> $exception
     */
    #[Test]
    #[DataProvider('failures')]
    public function aFailureRecordsNothing(PerformCheck $command, string $exception): void
    {
        $this->campaignWithoutScene();
        // Enough numbers for the dice: a failure before the dice leaves them unrolled, a failure after them still records nothing.
        $handler = new PerformCheckHandler($this->journal, new ScriptedRandomNumberGenerator(5, 5));

        try {
            $handler($command);
            self::fail('The check was performed.');
        } catch (\Throwable $thrown) {
            self::assertInstanceOf($exception, $thrown);
            self::assertSame([], $this->journalOf('campaign-3'));
            self::assertSame([], $this->journalOf('campaign-4'));
        }
    }

    #[Test]
    public function aTakenEntryIdRecordsNothingMore(): void
    {
        $handler = new PerformCheckHandler($this->journal, new ScriptedRandomNumberGenerator(5, 5));
        $handler(new PerformCheck('entry-1', 'campaign-3', 'user-1', 'skill', self::ADA));

        $this->expectException(JournalEntryAlreadyExists::class);

        try {
            $handler(new PerformCheck('entry-1', 'campaign-3', 'user-1', 'skill', self::VI));
        } finally {
            self::assertCount(1, $this->journalOf('campaign-3'));
        }
    }

    private function campaignWithoutScene(): void
    {
        $campaign = Campaign::create(CampaignId::fromString('campaign-4'), 'user-1', 'Quiet', PinnedRelease::of('rolling', 1, 'Rolling'), new \DateTimeImmutable('2026-10-05T10:00:00+00:00'));
        $campaign->addCharacter(CharacterId::fromString(self::ADA), 'Ada', [], [], [], $this->releases->get('rolling', 1));
        $campaign->startSession(new \DateTimeImmutable('2026-10-06T09:00:00+00:00'));
        $this->campaigns->add($campaign);
    }
}
