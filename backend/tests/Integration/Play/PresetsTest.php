<?php

declare(strict_types=1);

namespace App\Tests\Integration\Play;

use App\Formula\Domain\Formula;
use App\Play\Application\GetGameSystemSnapshot;
use App\Play\Domain\GameSystem\CreditsBadge;
use App\Play\Domain\GameSystem\Flow\CheckStep;
use App\Play\Domain\GameSystem\Flow\ChoiceStep;
use App\Play\Domain\GameSystem\Flow\ConditionStep;
use App\Play\Domain\GameSystem\Flow\EndPhaseEffect;
use App\Play\Domain\GameSystem\Flow\FlowView;
use App\Play\Domain\GameSystem\Flow\RollStep;
use App\Play\Domain\GameSystem\Flow\SheetEffect;
use App\Play\Domain\GameSystem\Flow\SheetOperation;
use App\Play\Domain\GameSystem\Flow\TableStep;
use App\Play\Domain\GameSystem\GameSystemSnapshot;
use App\Play\Domain\GameSystem\Sheet\ConditionsField;
use App\Play\Domain\GameSystem\Sheet\DerivedField;
use App\Play\Domain\GameSystem\Sheet\Field;
use App\Play\Domain\GameSystem\Sheet\ListField;
use App\Play\Domain\GameSystem\Sheet\NumberField;
use App\Play\Domain\GameSystem\Sheet\TextField;
use App\Play\Domain\GameSystem\Sheet\TrackField;
use App\Randomness\Domain\Oracle\InvalidLikelihoodOracle;
use App\Randomness\Domain\Oracle\LikelihoodAnswer;
use App\Randomness\Domain\Oracle\LikelihoodLevel;
use App\Randomness\Domain\Oracle\YesNoAnswer;
use App\Shared\Application\Bus\QueryBus;
use App\Studio\Infrastructure\Console\PublishGameSystemReleaseConsoleCommand;
use App\Tests\Support\Randomness\ScriptedRandomNumberGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The shipped presets publish through the console like `make presets`, and Play resolves every
 * oracle in their snapshots.
 */
#[CoversClass(PublishGameSystemReleaseConsoleCommand::class)]
final class PresetsTest extends KernelTestCase
{
    private const string PRESETS = __DIR__.'/../../../presets';

    private CommandTester $tester;

    protected function setUp(): void
    {
        $application = new Application(self::bootKernel());
        $this->tester = new CommandTester($application->find('app:gamesystem:publish'));
    }

    #[Test]
    public function freeJournalPublishesOnceAndResolvesItsOracles(): void
    {
        $this->assertPublishesOnce('free-journal');

        $snapshot = $this->snapshot('free-journal');
        self::assertSame('Free journal', $snapshot->name());
        self::assertSame(1, $snapshot->releaseVersion());
        self::assertSame([], $snapshot->flowSteps());

        self::assertSame(['action', 'theme', 'descriptor', 'place'], $snapshot->oracleTableKeys());
        $this->assertWeightedTableResolves($snapshot, 'action', 24, 'Seek', 'Rest');
        $this->assertWeightedTableResolves($snapshot, 'theme', 24, 'Loyalty', 'Homecoming');
        $this->assertWeightedTableResolves($snapshot, 'descriptor', 24, 'Rusted', 'Gilded');
        $this->assertWeightedTableResolves($snapshot, 'place', 24, 'A crossroads inn', 'A lighthouse with no keeper');

        self::assertSame(['yes-no'], $this->likelihoodKeys($snapshot));
        $yesNo = $snapshot->likelihoodOracle('yes-no');
        self::assertSame('Yes/no question', $yesNo->name());
        $oracle = $yesNo->oracle();
        self::assertNull($oracle->chaos());
        self::assertSame(['very-unlikely' => 10, 'unlikely' => 30, 'even' => 50, 'likely' => 70, 'very-likely' => 90], $this->targets($oracle->levels()));

        $this->assertAnswer(YesNoAnswer::ExceptionalYes, 70, $oracle->ask('likely', null, new ScriptedRandomNumberGenerator(7)));
        $this->assertAnswer(YesNoAnswer::Yes, 70, $oracle->ask('likely', null, new ScriptedRandomNumberGenerator(70)));
        $this->assertAnswer(YesNoAnswer::No, 70, $oracle->ask('likely', null, new ScriptedRandomNumberGenerator(97)));
        $this->assertAnswer(YesNoAnswer::ExceptionalNo, 70, $oracle->ask('likely', null, new ScriptedRandomNumberGenerator(98)));

        $this->expectException(InvalidLikelihoodOracle::class);
        $oracle->ask('likely', 5, new ScriptedRandomNumberGenerator(50));
    }

    #[Test]
    public function mythicStylePublishesOnceAndResolvesItsOracles(): void
    {
        $this->assertPublishesOnce('mythic-style');

        $snapshot = $this->snapshot('mythic-style');
        self::assertSame('Mythic-style', $snapshot->name());
        self::assertSame(1, $snapshot->releaseVersion());
        // Schema version 2: the Flow "mythic" is the release's default, with the chaos Tracker its fate oracle takes the chaos factor from.
        self::assertSame(['mythic', 'mythic', ['chaos'], ['scene']], [$snapshot->defaultFlow()?->key, $snapshot->flows()[0]->key, array_map(static fn (\App\Play\Domain\GameSystem\Tracker $tracker): string => $tracker->key, $snapshot->trackers()), array_map(static fn (\App\Play\Domain\GameSystem\SceneType $type): string => $type->key, $snapshot->sceneTypes())]);

        self::assertSame(['scene-adjustments', 'meaning-action', 'meaning-subject', 'random-event-focus'], $snapshot->oracleTableKeys());
        $this->assertWeightedTableResolves($snapshot, 'meaning-action', 30, 'Mend', 'Awaken');
        $this->assertWeightedTableResolves($snapshot, 'meaning-subject', 30, 'A debt', 'A key');
        $this->assertRangedTableResolves($snapshot, 'random-event-focus', 'Something changes far away', 'A quiet moment hides a clue');

        // Tags the picks filter by, the parchment Theme, the journal as the Flow's opening view.
        self::assertSame(['rival', 'ally'], array_map(static fn (\App\Play\Domain\GameSystem\Tag $tag): string => $tag->key, $snapshot->tags()));
        self::assertSame(['parchment', FlowView::Journal], [$snapshot->theme(), $snapshot->defaultFlow()?->defaultView]);

        self::assertSame(['fate'], $this->likelihoodKeys($snapshot));
        $fate = $snapshot->likelihoodOracle('fate');
        self::assertSame('Fate question', $fate->name());
        $oracle = $fate->oracle();
        // Doubles within the chaos factor offer the random event table (ADR 0019).
        self::assertSame('random-event-focus', $oracle->randomEventTable());
        self::assertSame('random-event-focus', $oracle->ask('even', 5, new ScriptedRandomNumberGenerator(44))->randomEvent());
        self::assertNull($oracle->ask('even', 5, new ScriptedRandomNumberGenerator(66))->randomEvent());
        self::assertSame([1, 9, 5, 5], [$oracle->chaos()?->min(), $oracle->chaos()?->max(), $oracle->chaos()?->neutral(), $oracle->chaos()?->shiftPerPoint()]);
        self::assertSame([
            'impossible' => 5,
            'nearly-impossible' => 15,
            'very-unlikely' => 25,
            'unlikely' => 35,
            'even' => 50,
            'likely' => 65,
            'very-likely' => 75,
            'nearly-certain' => 85,
            'certain' => 95,
        ], $this->targets($oracle->levels()));

        // Without a chaos factor the neutral one applies: target 50, exceptional bands 1–10 and 91–100.
        $this->assertAnswer(YesNoAnswer::ExceptionalYes, 50, $oracle->ask('even', null, new ScriptedRandomNumberGenerator(10)));
        $this->assertAnswer(YesNoAnswer::Yes, 50, $oracle->ask('even', null, new ScriptedRandomNumberGenerator(50)));
        $this->assertAnswer(YesNoAnswer::No, 50, $oracle->ask('even', null, new ScriptedRandomNumberGenerator(90)));
        $this->assertAnswer(YesNoAnswer::ExceptionalNo, 50, $oracle->ask('even', null, new ScriptedRandomNumberGenerator(91)));

        // Each chaos point moves the target by 5, clamped to the die.
        $this->assertAnswer(YesNoAnswer::Yes, 70, $oracle->ask('even', 9, new ScriptedRandomNumberGenerator(70)));
        $this->assertAnswer(YesNoAnswer::No, 30, $oracle->ask('even', 1, new ScriptedRandomNumberGenerator(31)));
        $this->assertAnswer(YesNoAnswer::Yes, 100, $oracle->ask('certain', 9, new ScriptedRandomNumberGenerator(100)));
        $this->assertAnswer(YesNoAnswer::No, 0, $oracle->ask('impossible', 1, new ScriptedRandomNumberGenerator(1)));
    }

    #[Test]
    public function nightJobPublishesOnceWithItsCreditsThemeAndOracles(): void
    {
        $this->assertPublishesOnce('night-job');

        $snapshot = $this->snapshot('night-job');
        self::assertSame('Night Job', $snapshot->name());
        self::assertSame(1, $snapshot->releaseVersion());
        // The publisher's disclaimer (ADR 0021) and the neon Theme travel with the release through the ACL.
        self::assertSame('neon', $snapshot->theme());
        $credits = $snapshot->credits();
        self::assertNotNull($credits);
        self::assertStringStartsWith('Night Job is unofficial content provided under the Homebrew Content Policy of R. Talsorian Games', $credits->notice);
        self::assertSame(['R. Talsorian Games Homebrew Content Policy', 'https://rtalsoriangames.com/homebrew-content-policy/'], [$credits->license, $credits->url]);

        $flow = $snapshot->defaultFlow();
        self::assertSame(['heist', FlowView::Focus], [$flow?->key, $flow?->defaultView]);
        self::assertSame(['the-job', 'legwork', 'the-heist', 'escape', 'epilogue'], array_map(static fn (\App\Play\Domain\GameSystem\Flow\Phase $phase): string => $phase->key, $flow->phases ?? []));
        self::assertSame(['alarm', 'edge', 'objective'], array_map(static fn (\App\Play\Domain\GameSystem\Tracker $tracker): string => $tracker->key, $snapshot->trackers()));

        // Every table of the flow resolves at both ends; "rumors" is for free play only.
        foreach (['job-clients' => 12, 'job-targets' => 12, 'complications' => 12, 'net-floors' => 12, 'black-ice' => 10, 'security-response' => 12, 'npc-name' => 16, 'rumors' => 12] as $table => $entries) {
            $this->assertWeightedTableResolves($snapshot, $table, $entries, $snapshot->resolveOracleTable($table, new ScriptedRandomNumberGenerator(1))->steps()[0]->text(), $snapshot->resolveOracleTable($table, new ScriptedRandomNumberGenerator($entries))->steps()[0]->text());
        }

        self::assertContains('rumors', $snapshot->oracleTableKeys());
        self::assertNotContains('rumors', $flow->oracles ?? []);
        self::assertSame(['fate'], $this->likelihoodKeys($snapshot));
        self::assertSame(['very-unlikely' => 10, 'unlikely' => 30, 'even' => 50, 'likely' => 70, 'very-likely' => 90], $this->targets($snapshot->likelihoodOracle('fate')->oracle()->levels()));
    }

    #[Test]
    public function nightJobHasAnOriginalSheet(): void
    {
        $this->assertPublishesOnce('night-job');
        $snapshot = $this->snapshot('night-job');

        $sheet = $snapshot->sheet();
        self::assertSame(
            ['role', 'nerve', 'hands', 'wits', 'grit', 'charm', 'cool', 'hp', 'wounded', 'humanity', 'gear', 'cyberware'],
            array_map(static fn (Field $field): string => $field->key, $sheet->fields()),
        );
        foreach (['role' => TextField::class, 'nerve' => NumberField::class, 'hp' => TrackField::class, 'humanity' => TrackField::class, 'gear' => ListField::class, 'cyberware' => ListField::class] as $key => $type) {
            self::assertInstanceOf($type, $sheet->field($key), $key);
        }

        // HP and Humanity are tracks with a fixed maximum, so a new Character starts full.
        $hp = $sheet->field('hp');
        $humanity = $sheet->field('humanity');
        self::assertInstanceOf(TrackField::class, $hp);
        self::assertInstanceOf(TrackField::class, $humanity);
        self::assertSame([40, 50], [$hp->max, $humanity->max]);
        self::assertSame(['Identity', 'Stats', 'Condition', 'Kit'], array_values(array_unique(array_map(static fn (Field $field): ?string => $field->group, $sheet->fields()))));

        // Cool and Wounded at are derived: computed from Nerve and the Alarm Tracker, and from the HP maximum.
        $formulas = [];
        foreach (['cool', 'wounded'] as $key) {
            $derived = $sheet->field($key);
            self::assertInstanceOf(DerivedField::class, $derived, $key);
            $formulas[$key] = $derived->formula->toString();
        }
        self::assertSame(['cool' => 'max(0, {nerve} - {tracker:alarm} / 2)', 'wounded' => '{hp.max} / 2'], $formulas);
    }

    #[Test]
    public function percentileHorrorPublishesOnceWithItsOpenLicenseCreditsAndTheEldritchTheme(): void
    {
        $this->assertPublishesOnce('percentile-horror');

        $snapshot = $this->snapshot('percentile-horror');
        self::assertSame('Percentile Horror', $snapshot->name());
        self::assertSame(1, $snapshot->releaseVersion());
        self::assertSame('eldritch', $snapshot->theme());

        // The ORC License notice (ADR 0021) names the base rules and disclaims the publisher, and carries no trademark.
        $credits = $snapshot->credits();
        self::assertNotNull($credits);
        self::assertStringContainsString('Basic Roleplaying: Universal Game Engine', $credits->notice);
        self::assertStringContainsString('Chaosium Inc.', $credits->notice);
        self::assertStringContainsString('www.chaosium.com/orclicense', $credits->notice);
        self::assertStringContainsString('not published, endorsed or approved by Chaosium Inc.', $credits->notice);
        self::assertSame(['ORC License', 'https://www.chaosium.com/orclicense'], [$credits->license, $credits->url]);
        self::assertStringNotContainsString('Call of Cthulhu', json_encode($snapshot->name().$credits->notice, \JSON_THROW_ON_ERROR));

        $flow = $snapshot->defaultFlow();
        self::assertSame(['kingsport-mystery', ['prologue', 'investigation', 'confrontation', 'epilogue']], [$flow?->key, array_map(static fn (\App\Play\Domain\GameSystem\Flow\Phase $phase): string => $phase->key, $flow->phases ?? [])]);
        foreach ($flow->phases ?? [] as $phase) {
            self::assertNotEmpty($phase->description, \sprintf('Phase "%s" explains itself.', $phase->key));
        }
        self::assertSame(['contact', 'hook', 'place', 'lair', 'horror'], array_map(static fn (\App\Play\Domain\GameSystem\FactSlot $slot): string => $slot->key, $snapshot->factSlots()));
        self::assertSame(['names', 'locations', 'hooks', 'clues', 'strange-events', 'horrors', 'nouns', 'verbs', 'adjectives'], $snapshot->oracleTableKeys());
        self::assertSame(['clues', 'dread'], array_map(static fn (\App\Play\Domain\GameSystem\Tracker $tracker): string => $tracker->key, $snapshot->trackers()));
        self::assertSame(['fate'], $this->likelihoodKeys($snapshot));
    }

    #[Test]
    public function percentileHorrorPromptsOfferExistingTablesAsInspirationAndUseEveryFactSlot(): void
    {
        $this->assertPublishesOnce('percentile-horror');
        $snapshot = $this->snapshot('percentile-horror');

        $inspired = [];
        $filled = [];
        foreach ($snapshot->sceneTypes() as $sceneType) {
            foreach ([$sceneType->setup, $sceneType->play, $sceneType->closing] as $part) {
                foreach ($part->steps as $step) {
                    foreach ($step->effects as $effect) {
                        if ($effect instanceof \App\Play\Domain\GameSystem\Flow\FillFactEffect) {
                            $filled[] = $effect->slot;
                        }
                    }
                    if ($step instanceof \App\Play\Domain\GameSystem\Flow\PromptStep) {
                        foreach ($step->inspiration as $table) {
                            self::assertContains($table, $snapshot->oracleTableKeys(), \sprintf('Step "%s" offers an unknown table.', $step->key));
                            $inspired[] = $table;
                        }
                    }
                    self::assertNotInstanceOf(TableStep::class, $step, \sprintf('Step "%s": tables are inspiration, not rolled for facts.', $step->key));
                }
            }
        }

        // No orphan table and no unfilled fact slot.
        self::assertEqualsCanonicalizing($snapshot->oracleTableKeys(), array_values(array_unique($inspired)));
        self::assertEqualsCanonicalizing(array_map(static fn (\App\Play\Domain\GameSystem\FactSlot $slot): string => $slot->key, $snapshot->factSlots()), array_values(array_unique($filled)));
    }

    #[Test]
    public function percentileHorrorHasAnInvestigatorSheetWithDerivedValuesAndOneCheckPerSkill(): void
    {
        $this->assertPublishesOnce('percentile-horror');
        $snapshot = $this->snapshot('percentile-horror');

        $sheet = $snapshot->sheet();
        self::assertSame(['Investigator', 'Characteristics', 'Derived', 'Tracks', 'Skills', 'Gear'], array_values(array_unique(array_map(static fn (Field $field): ?string => $field->group, $sheet->fields()))));
        foreach (['occupation' => TextField::class, 'str' => NumberField::class, 'library-use' => NumberField::class, 'hp' => TrackField::class, 'sanity' => TrackField::class, 'luck' => TrackField::class, 'gear' => ListField::class] as $key => $type) {
            self::assertInstanceOf($type, $sheet->field($key), $key);
        }

        // Sanity and Luck are tracks with a fixed maximum; hit points and magic points are sized per Character from the derived values.
        $sanity = $sheet->field('sanity');
        $hp = $sheet->field('hp');
        self::assertInstanceOf(TrackField::class, $sanity);
        self::assertInstanceOf(TrackField::class, $hp);
        self::assertSame([99, null], [$sanity->max, $hp->max]);
        self::assertSame('{hp-max}', $hp->startMax?->toString());

        // Characters are created by rolling or by spending 460 points over the eight characteristics (ADR 0040).
        $budget = $sheet->budgets()[0] ?? null;
        self::assertNotNull($budget);
        self::assertSame([460, 15, 90, ['str', 'con', 'siz', 'dex', 'app', 'int', 'pow', 'edu']], [$budget->total, $budget->min, $budget->max, $budget->fields]);
        $siz = $sheet->field('siz');
        self::assertInstanceOf(NumberField::class, $siz);
        self::assertSame('(2d6+6)*5', $siz->roll?->notation());

        $formulas = [];
        foreach (['hp-max', 'mp-max', 'starting-sanity'] as $key) {
            $derived = $sheet->field($key);
            self::assertInstanceOf(DerivedField::class, $derived, $key);
            $formulas[$key] = $derived->formula->toString();
        }
        self::assertSame(['hp-max' => '({con} + {siz}) / 10', 'mp-max' => '{pow} / 5', 'starting-sanity' => '{pow}'], $formulas);

        // One check per skill (17), one per characteristic that is rolled directly (4), and Luck and Sanity.
        $checks = array_map(static fn (\App\Play\Domain\GameSystem\Check\Check $check): string => $check->key, $snapshot->checks());
        self::assertCount(23, $checks);
        foreach (['library-use', 'spot-hidden', 'persuade', 'psychology', 'pow', 'luck', 'sanity'] as $key) {
            self::assertContains($key, $checks);
        }
    }

    #[Test]
    public function heartsAndRapiersPublishesOnceWithItsOpenLicenseCreditsAndTheOneShotFlow(): void
    {
        $this->assertPublishesOnce('hearts-and-rapiers');

        $snapshot = $this->snapshot('hearts-and-rapiers');
        self::assertSame('Hearts & Rapiers', $snapshot->name());
        self::assertSame(1, $snapshot->releaseVersion());
        self::assertSame('noir', $snapshot->theme());

        // The open license notice (ADR 0021) names the source game and its publisher, and disclaims both.
        $credits = $snapshot->credits();
        self::assertNotNull($credits);
        self::assertStringContainsString('Thirsty Sword Lesbians', $credits->notice);
        self::assertStringContainsString('Evil Hat Productions, LLC', $credits->notice);
        self::assertStringContainsString('The CC BY SA 4.0 license is available at creativecommons.org/licenses/by-sa/4.0/', $credits->notice);
        self::assertStringContainsString('not affiliated with or endorsed by Evil Hat Productions', $credits->notice);
        self::assertSame(['CC BY-SA 4.0 (Powered by Lesbians open license)', 'https://poweredbylesbians.com/license/'], [$credits->license, $credits->url]);
        // The title says "for use with" the game, never that it is the game.
        self::assertStringNotContainsString('Thirsty Sword Lesbians', $snapshot->name());

        $flow = $snapshot->defaultFlow();
        self::assertSame(['one-shot', FlowView::Focus, ['setup', 'scenes', 'climax']], [$flow?->key, $flow?->defaultView, array_map(static fn (\App\Play\Domain\GameSystem\Flow\Phase $phase): string => $phase->key, $flow->phases ?? [])]);
        self::assertSame(['countdown'], array_map(static fn (\App\Play\Domain\GameSystem\Tracker $tracker): string => $tracker->key, $snapshot->trackers()));
        self::assertSame(['swashbuckle', 'heart-to-heart', 'intrigue'], array_map(static fn (\App\Play\Domain\GameSystem\SceneType $type): string => $type->key, $snapshot->sceneTypes()));
        self::assertSame(['villain', 'ally', 'crush'], array_map(static fn (\App\Play\Domain\GameSystem\Tag $tag): string => $tag->key, $snapshot->tags()));
        // The Tags name NPCs only: a Character or a Thread is not offered them.
        foreach ($snapshot->tags() as $tag) {
            self::assertTrue($tag->isFor(\App\Play\Domain\GameSystem\Flow\ItemKind::Npc), $tag->key);
            self::assertFalse($tag->isFor(\App\Play\Domain\GameSystem\Flow\ItemKind::Character), $tag->key);
            self::assertFalse($tag->isFor(\App\Play\Domain\GameSystem\Flow\ItemKind::Thread), $tag->key);
        }

        self::assertSame(['toxic-power', 'story-element', 'romantic-complication', 'swashbuckle-complication', 'intrigue-complication', 'location', 'villains-next-move'], $snapshot->oracleTableKeys());
        foreach (['toxic-power' => 6, 'story-element' => 8, 'romantic-complication' => 8, 'swashbuckle-complication' => 6, 'intrigue-complication' => 6, 'location' => 8, 'villains-next-move' => 8] as $table => $entries) {
            $this->assertWeightedTableResolves($snapshot, $table, $entries, $snapshot->resolveOracleTable($table, new ScriptedRandomNumberGenerator(1))->steps()[0]->text(), $snapshot->resolveOracleTable($table, new ScriptedRandomNumberGenerator($entries))->steps()[0]->text());
        }
        self::assertSame(['will-it-work'], $this->likelihoodKeys($snapshot));

        // ADR 0042: the Scenes move is a check set (Defy Disaster with any stat), the complication table follows the Scene Type, and the Climax XP question awards the XP.
        $phases = array_column($flow->phases ?? [], null, 'key');
        $table = $phases['scenes']->sceneOpening->step('table-1');
        $move = $phases['scenes']->sceneOpening->step('check-1');
        $question = $phases['climax']->phaseClosing->step('choice-1');
        self::assertInstanceOf(TableStep::class, $table);
        self::assertInstanceOf(CheckStep::class, $move);
        self::assertInstanceOf(ChoiceStep::class, $question);
        self::assertSame(['romantic-complication', ['intrigue' => 'intrigue-complication', 'swashbuckle' => 'swashbuckle-complication']], [$table->table, $table->tableBySceneType]);
        self::assertSame(['defy-disaster-daring', 'defy-disaster-grace', 'defy-disaster-heart', 'defy-disaster-wit', 'defy-disaster-spirit'], $move->checks);
        self::assertSame([true, false], [$question->option('yes')?->changesSheet(), $question->option('no')?->changesSheet()]);

        // The sheet has the five stats and five Conditions, the Conditions marked and the XP; the moves are the twelve checks.
        $sheet = $snapshot->sheet();
        foreach (['playbook' => TextField::class, 'daring' => NumberField::class, 'frightened' => NumberField::class, 'conditions-marked' => DerivedField::class, 'xp' => NumberField::class, 'strings' => ListField::class] as $key => $type) {
            self::assertInstanceOf($type, $sheet->field($key), $key);
        }
        // Two columns: Identity and Progress span both, Stats and Conditions sit side by side.
        $layout = $sheet->layout();
        self::assertSame([2, [['Identity', 1, 2], ['Stats', 1, 1], ['Conditions', 1, 1], ['Progress', 1, 2]]], [$layout?->columns, array_map(static fn (\App\Play\Domain\GameSystem\Sheet\SheetGroup $group): array => [$group->name, $group->columns, $group->span], $layout->groups ?? [])]);
        self::assertCount(12, $snapshot->checks());
    }

    #[Test]
    public function lanternsOfTheMirePublishesOnceWithItsFreeTabletopLicenseCreditsPoolChecksAndTheMysteryFlow(): void
    {
        $this->assertPublishesOnce('lanterns-of-the-mire');

        $snapshot = $this->snapshot('lanterns-of-the-mire');
        self::assertSame('Lanterns of the Mire', $snapshot->name());
        self::assertSame(1, $snapshot->releaseVersion());
        self::assertSame('eldritch', $snapshot->theme());

        // The Free Tabletop License (ADR 0021) covers the Year Zero Engine SRD only: the notice disclaims Free League and discloses generative AI.
        $credits = $snapshot->credits();
        self::assertNotNull($credits);
        self::assertStringContainsString('not affiliated with, sponsored, or endorsed by Fria Ligan AB', $credits->notice);
        self::assertStringContainsString("The Year Zero Engine System Reference Document is used under Fria Ligan AB's Free Tabletop License", $credits->notice);
        self::assertStringContainsString('generative AI', $credits->notice);
        self::assertSame(['Year Zero Engine Free Tabletop License 1.1', 'https://freeleaguepublishing.com/wp-content/uploads/2026/03/Year-Zero-Engine-License-Agreement-version-1.1.pdf'], [$credits->license, $credits->url]);
        // The license excludes Free League's brands and other games: the file never names the game it is inspired by.
        self::assertStringNotContainsStringIgnoringCase('Vaesen', (string) file_get_contents(self::PRESETS.'/lanterns-of-the-mire.json'));

        // Thirteen pool checks of d6 that succeed at 6 (ADR 0041): the skills push a Condition of their kind, Stand Fast does not push.
        $checks = $snapshot->checks();
        self::assertCount(13, $checks);
        foreach ($checks as $check) {
            self::assertSame([6, 6], [$check->pool?->sides, $check->pool?->successAt], $check->key);
        }
        $bodySkills = ['brawl', 'endure', 'haul', 'shoot', 'sneak', 'nimble'];
        $mindSkills = ['lore', 'inquiry', 'alertness', 'persuade', 'mend', 'insight'];
        foreach ([...$bodySkills, ...$mindSkills] as $key) {
            $effects = $snapshot->check($key)?->push->effects ?? [];
            self::assertCount(1, $effects, $key);
            self::assertInstanceOf(SheetEffect::class, $effects[0], $key);
            // A mark without an option: the player picks which Condition to mark.
            self::assertSame([\in_array($key, $bodySkills, true) ? 'body' : 'mind', SheetOperation::Mark, null], [$effects[0]->field, $effects[0]->op, $effects[0]->value], $key);
        }
        self::assertNull($snapshot->check('stand-fast')?->push);
        $formulas = [];
        foreach (['brawl', 'inquiry', 'insight', 'stand-fast'] as $key) {
            $count = $snapshot->check($key)?->pool?->count;
            $formulas[$key] = $count instanceof Formula ? $count->toString() : $count;
        }
        self::assertSame(['brawl' => '{vigour} + {brawl} - {body}', 'inquiry' => '{reason} + {inquiry} - {mind}', 'insight' => '{heart} + {insight} - {mind}', 'stand-fast' => '{reason} + {heart} - {mind}'], $formulas);

        // Two Conditions fields, one per kind of harm, and the four attributes share 12 points and the skills 8.
        $sheet = $snapshot->sheet();
        foreach (['body' => ['winded', 'bruised', 'bleeding'], 'mind' => ['rattled', 'dread', 'despair']] as $key => $options) {
            $conditions = $sheet->field($key);
            self::assertInstanceOf(ConditionsField::class, $conditions, $key);
            self::assertSame($options, $conditions->optionKeys(), $key);
        }
        // Two point budgets (ADR 0043): the attributes, and 8 skill points over the twelve skills, at most 3 each.
        $budgets = $sheet->budgets();
        self::assertSame(['points', 'skills'], array_map(static fn (\App\Play\Domain\GameSystem\Sheet\PointBudget $budget): string => $budget->key, $budgets));
        self::assertSame([12, ['vigour', 'finesse', 'reason', 'heart']], [$budgets[0]->total, $budgets[0]->fields]);
        self::assertSame([8, 0, 3, [...$bodySkills, ...$mindSkills]], [$budgets[1]->total, $budgets[1]->min, $budgets[1]->max, $budgets[1]->fields]);

        // The mystery is the default flow; Clues and Nightfall track it, and a full Nightfall ends the Investigation after its scene.
        $flow = $snapshot->defaultFlow();
        self::assertNotNull($flow);
        self::assertSame(['a-mystery', ['prologue', 'investigation', 'unmasking']], [$flow->key, array_map(static fn (\App\Play\Domain\GameSystem\Flow\Phase $phase): string => $phase->key, $flow->phases)]);
        self::assertSame(['clues', 'nightfall'], array_map(static fn (\App\Play\Domain\GameSystem\Tracker $tracker): string => $tracker->key, $snapshot->trackers()));
        $nightfall = $flow->phase('investigation')?->sceneClosing->step('condition-1');
        self::assertInstanceOf(ConditionStep::class, $nightfall);
        self::assertSame('nightfall', $nightfall->tracker);
        $last = $nightfall->bands[\count($nightfall->bands) - 1];
        self::assertContainsOnlyInstancesOf(EndPhaseEffect::class, $last->outcome->effects);
        self::assertCount(1, $last->outcome->effects);
    }

    #[Test]
    public function vtmNightCourtPublishesOnceWithItsDarkPackCreditsHungerPoolChecksAndTheMasqueradeFlow(): void
    {
        $this->assertPublishesOnce('vtm-night-court');

        $snapshot = $this->snapshot('vtm-night-court');
        // Publishing into an empty database makes release 1, whatever version the file was exported at.
        self::assertSame(1, $snapshot->releaseVersion());

        // The Dark Pack Agreement (ADR 0021) is named with its link and its badge, and the notice discloses generative AI.
        $credits = $snapshot->credits();
        self::assertNotNull($credits);
        self::assertStringContainsString('generative AI', $credits->notice);
        self::assertSame(['Dark Pack Agreement', 'https://www.paradoxinteractive.com/games/world-of-darkness/community/dark-pack-agreement', CreditsBadge::DarkPack], [$credits->license, $credits->url, $credits->badge]);

        // Thirteen checks (ADR 0044): twelve Hunger pools of attribute + skill that push by spending Willpower, and Rouse the Blood.
        $checks = $snapshot->checks();
        self::assertCount(13, $checks);
        $pools = array_filter($checks, static fn (\App\Play\Domain\GameSystem\Check\Check $check): bool => 'rouse' !== $check->key);
        self::assertCount(12, $pools);
        foreach ($pools as $check) {
            $pool = $check->pool;
            self::assertSame([10, 6, 2], [$pool?->sides, $pool?->successAt, $pool?->criticals], $check->key);
            self::assertMatchesRegularExpression('/^\{[a-z]+\} \+ \{'.$check->key.'\}$/', $pool?->count instanceof Formula ? $pool->count->toString() : '', $check->key);
            self::assertSame('{hunger}', $pool?->hunger instanceof Formula ? $pool->hunger->toString() : null, $check->key);
            self::assertSame(['Spend Willpower', 3], [$check->push?->label, $check->push?->maxDice], $check->key);
            $cost = $check->push->effects[0] ?? null;
            self::assertInstanceOf(SheetEffect::class, $cost, $check->key);
            self::assertSame(['willpower', SheetOperation::Subtract], [$cost->field, $cost->op], $check->key);
        }

        // Rouse the Blood is one plain die: no Hunger, no critical pairs, no push.
        $rouse = $snapshot->check('rouse');
        self::assertSame([1, 10, 6, null, null, null], [$rouse?->pool?->count, $rouse?->pool?->sides, $rouse?->pool?->successAt, $rouse?->pool?->criticals, $rouse?->pool?->hunger, $rouse?->push]);

        // Two point budgets (ADR 0043): 22 over nine attributes at 1..4, 14 over twelve skills at 0..3.
        $budgets = $snapshot->sheet()->budgets();
        self::assertSame(['points', 'skills'], array_map(static fn (\App\Play\Domain\GameSystem\Sheet\PointBudget $budget): string => $budget->key, $budgets));
        self::assertSame([22, 1, 4, 9], [$budgets[0]->total, $budgets[0]->min, $budgets[0]->max, \count($budgets[0]->fields)]);
        self::assertSame([14, 0, 3, 12], [$budgets[1]->total, $budgets[1]->min, $budgets[1]->max, \count($budgets[1]->fields)]);

        // A Bestial Failure sends a hook roll to the Compulsion step; a Messy Critical on the Hunt has an Outcome of its own.
        $hunt = $snapshot->sceneType('hunt')?->play->step('check-1');
        self::assertInstanceOf(CheckStep::class, $hunt);
        self::assertSame('compulsion', $hunt->specialOutcomes['bestialFailure']->next ?? null);
        self::assertContains('messyCritical', array_keys($hunt->specialOutcomes));
        self::assertSame('end', $hunt->specialOutcomes['messyCritical']->next);

        // The world turn rolls the Second Inquisition against the Masquerade tracker.
        $flow = $snapshot->defaultFlow();
        self::assertSame('chronicle', $flow?->key);
        $inquisition = $flow?->phase('chronicle')?->worldTurn->step('inquisition');
        self::assertInstanceOf(RollStep::class, $inquisition);
        $upTo = $inquisition->bands[0]->upTo;
        self::assertInstanceOf(Formula::class, $upTo);
        self::assertSame('{tracker:masquerade}', $upTo->toString());
        self::assertContains('masquerade', array_map(static fn (\App\Play\Domain\GameSystem\Tracker $tracker): string => $tracker->key, $snapshot->trackers()));
    }

    private function assertPublishesOnce(string $preset): void
    {
        $file = self::PRESETS.'/'.$preset.'.json';

        self::assertSame(Command::SUCCESS, $this->tester->execute(['file' => $file, '--if-changed' => true]), $this->tester->getDisplay());
        self::assertStringContainsString(\sprintf('Published %s v1', $preset), $this->tester->getDisplay());

        self::assertSame(Command::SUCCESS, $this->tester->execute(['file' => $file, '--if-changed' => true]), $this->tester->getDisplay());
        self::assertStringContainsString(\sprintf('Unchanged %s (v1)', $preset), $this->tester->getDisplay());
    }

    private function snapshot(string $key): GameSystemSnapshot
    {
        return self::getContainer()->get(QueryBus::class)->ask(new GetGameSystemSnapshot($key));
    }

    /**
     * Rolls the lowest and the highest number of 1dW, W being the total weight (every weight is 1).
     */
    private function assertWeightedTableResolves(GameSystemSnapshot $snapshot, string $table, int $entries, string $first, string $last): void
    {
        self::assertSame($first, $snapshot->resolveOracleTable($table, new ScriptedRandomNumberGenerator(1))->steps()[0]->text());
        self::assertSame($last, $snapshot->resolveOracleTable($table, new ScriptedRandomNumberGenerator($entries))->steps()[0]->text());

        try {
            $snapshot->resolveOracleTable($table, new ScriptedRandomNumberGenerator($entries + 1));
            self::fail(\sprintf('%s has more than %d entries.', $table, $entries));
        } catch (\LogicException $logicException) {
            self::assertStringContainsString(\sprintf('outside [1, %d]', $entries), $logicException->getMessage());
        }
    }

    private function assertRangedTableResolves(GameSystemSnapshot $snapshot, string $table, string $atMin, string $atMax): void
    {
        $min = $snapshot->resolveOracleTable($table, new ScriptedRandomNumberGenerator(1))->steps()[0];
        self::assertSame('1d100', $min->dice());
        self::assertSame($atMin, $min->text());

        self::assertSame($atMax, $snapshot->resolveOracleTable($table, new ScriptedRandomNumberGenerator(100))->steps()[0]->text());

        foreach (range(1, 100) as $total) {
            self::assertSame($total, $snapshot->resolveOracleTable($table, new ScriptedRandomNumberGenerator($total))->steps()[0]->total(), 'Every roll of 1d100 has an entry.');
        }
    }

    private function assertAnswer(YesNoAnswer $expected, int $effectiveTarget, LikelihoodAnswer $answer): void
    {
        self::assertSame([$expected, $effectiveTarget], [$answer->answer(), $answer->effectiveTarget()]);
    }

    /**
     * @return list<string>
     */
    private function likelihoodKeys(GameSystemSnapshot $snapshot): array
    {
        return array_map(static fn (\App\Play\Domain\GameSystem\SnapshotLikelihoodOracle $oracle): string => $oracle->key(), $snapshot->likelihoodOracles());
    }

    /**
     * @param list<LikelihoodLevel> $levels
     *
     * @return array<string, int> targets by level key
     */
    private function targets(array $levels): array
    {
        $targets = [];
        foreach ($levels as $level) {
            $targets[$level->key()] = $level->target();
        }

        return $targets;
    }
}
