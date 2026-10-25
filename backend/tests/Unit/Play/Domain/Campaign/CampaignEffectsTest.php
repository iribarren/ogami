<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Domain\Campaign;

use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\CampaignEffects;
use App\Play\Domain\Campaign\Characters\CharacterId;
use App\Play\Domain\Campaign\FlowRun\SheetChange;
use App\Play\Domain\GameSystem\Flow\SheetEffect;
use App\Play\Domain\GameSystem\Flow\SheetOperation;
use App\Play\Domain\GameSystem\Flow\TrackerEffect;
use App\Play\Domain\GameSystem\Flow\TrackerOperation;
use App\Play\Domain\GameSystem\GameSystemSnapshot;
use App\Tests\Support\Play\ReleaseViews;
use App\Tests\Support\Studio\ReleaseArrays;
use App\Tests\Unit\Play\Domain\Campaign\FlowRun\FlowRunTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * The sheet and Tracker Effects applied outside a FlowRun (ADR 0041, the cost of a push), on
 * valid/v2-sheet-effects with a conditions field "wounds" (hurt, shaken) and the Tracker "dread".
 * Ida has Power 60. The FlowRun's own use of these Effects is covered by FlowRunSheetEffectsTest.
 */
#[CoversClass(CampaignEffects::class)]
final class CampaignEffectsTest extends FlowRunTestCase
{
    #[Test]
    public function aMarkWithoutAnOptionMarksThePick(): void
    {
        [$effects, $campaign] = $this->effects(['wounds' => ['shaken']]);

        $change = $effects->applySheetEffect(new SheetEffect('wounds', SheetOperation::Mark), 'ida', 'hurt');

        self::assertEquals(new SheetChange('wounds', 1, 2), $change);
        self::assertSame(['hurt', 'shaken'], $this->value($campaign, 'wounds'));
    }

    #[Test]
    public function aMarkWithoutAnOptionAndWithoutAPickMarksNothing(): void
    {
        [$effects, $campaign] = $this->effects(['wounds' => ['hurt', 'shaken']]);

        $change = $effects->applySheetEffect(new SheetEffect('wounds', SheetOperation::Mark), 'ida');

        self::assertEquals(new SheetChange('wounds', 2, 2), $change);
        self::assertSame(['hurt', 'shaken'], $this->value($campaign, 'wounds'));
    }

    #[Test]
    public function theUnmarkedOptionsFollowTheDeclaredOrderAndWhatIsMarkedNow(): void
    {
        [$effects] = $this->effects(['wounds' => ['hurt']]);

        self::assertSame(['shaken'], $effects->unmarkedOptions('wounds', 'ida'));

        $effects->applySheetEffect(new SheetEffect('wounds', SheetOperation::Mark, 'shaken'), 'ida');

        self::assertSame([], $effects->unmarkedOptions('wounds', 'ida'));
    }

    #[Test]
    public function aCharacterStoredBeforeTheFieldExistedHasEveryOptionUnmarked(): void
    {
        [$effects] = $this->effects();

        self::assertSame(['hurt', 'shaken'], $effects->unmarkedOptions('wounds', 'ida'));
    }

    #[Test]
    public function aPickIsRefusedWhereNoneIsDue(): void
    {
        [$effects] = $this->effects();

        foreach ([new SheetEffect('wounds', SheetOperation::Mark, 'hurt'), new SheetEffect('wounds', SheetOperation::Clear)] as $effect) {
            try {
                $effects->applySheetEffect($effect, 'ida', 'shaken');
                self::fail('A pick belongs to a mark without an option.');
            } catch (\LogicException $logic) {
                self::assertStringContainsString('takes a pick only for a mark without an option', $logic->getMessage());
            }
        }

        $this->expectException(\LogicException::class);

        $effects->applySheetEffect(new SheetEffect('wounds', SheetOperation::Mark), 'ida', 'ghost');
    }

    #[Test]
    public function aPickOfAnOptionMarkedAlreadyIsRefusedAndChangesNothing(): void
    {
        [$effects, $campaign] = $this->effects(['wounds' => ['hurt']]);

        try {
            $effects->applySheetEffect(new SheetEffect('wounds', SheetOperation::Mark), 'ida', 'hurt');
            self::fail('A pick marked an option marked already.');
        } catch (\LogicException $logic) {
            self::assertSame('Conditions field "wounds" has option "hurt" marked already: a pick takes an unmarked option.', $logic->getMessage());
        }

        self::assertSame(['hurt'], $this->value($campaign, 'wounds'));
    }

    #[Test]
    public function aNumberEffectAndATrackerEffectChangeTheCampaign(): void
    {
        [$effects, $campaign] = $this->effects();

        $change = $effects->applySheetEffect(new SheetEffect('pow', SheetOperation::Subtract, 5), 'ida');
        $tracker = $effects->applyTrackerEffect(new TrackerEffect('dread', TrackerOperation::Add, 2));

        self::assertEquals(new SheetChange('pow', 60, 55), $change);
        self::assertSame(55, $this->value($campaign, 'pow'));
        self::assertSame(['from' => 0, 'to' => 2], $tracker);
        self::assertSame(2, $effects->trackerValue('dread'));
    }

    /**
     * @param array<string, mixed> $values more values Ida starts with
     *
     * @return array{CampaignEffects, Campaign}
     */
    private function effects(array $values = []): array
    {
        $release = $this->pushRelease();
        $campaign = self::guided($release, 'play');
        $campaign->startSession(self::at(), $release);
        $campaign->addCharacter(CharacterId::fromString('ida'), 'Ida', [], [], ['pow' => 60, ...$values], $release);

        return [new CampaignEffects($campaign, $release), $campaign];
    }

    private function pushRelease(): GameSystemSnapshot
    {
        /** @var array{sheet: array{fields: list<array<string, mixed>>}} $content */
        $content = ReleaseViews::fixtureContent('valid/v2-sheet-effects');
        $wounds = ['key' => 'wounds', 'label' => 'Wounds', 'type' => 'conditions', 'options' => [['key' => 'hurt', 'label' => 'Hurt'], ['key' => 'shaken', 'label' => 'Shaken']]];
        $content = ReleaseArrays::with($content, 'sheet.fields', [...$content['sheet']['fields'], $wounds]);
        $content = ReleaseArrays::with($content, 'flows', [['key' => 'play', 'name' => 'Play', 'defaultView' => 'journal', 'oracles' => [], 'trackers' => ['dread'], 'phases' => [
            ['key' => 'prologue', 'name' => 'Prologue', 'mode' => 'once', 'selection' => ['rule' => 'player', 'sceneTypes' => ['setup']]],
        ]]]);

        /** @var array<string, mixed> $translated */
        $translated = $content;

        return self::translate($translated);
    }

    private function value(Campaign $campaign, string $field): mixed
    {
        return $campaign->character(CharacterId::fromString('ida'))?->values()[$field] ?? null;
    }
}
