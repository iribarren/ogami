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
use App\Play\Domain\GameSystem\Flow\Flow;
use App\Play\Domain\GameSystem\Flow\FlowView;
use App\Play\Domain\GameSystem\Flow\PhaseMode;
use App\Play\Domain\GameSystem\Flow\PromptStep;
use App\Play\Domain\GameSystem\Flow\RollStep;
use App\Play\Domain\GameSystem\Flow\SelectionRule;
use App\Play\Domain\GameSystem\Flow\SheetEffect;
use App\Play\Domain\GameSystem\Flow\SheetOperation;
use App\Play\Domain\GameSystem\Flow\TableStep;
use App\Play\Domain\GameSystem\Flow\TrackerEffect;
use App\Play\Domain\GameSystem\Flow\TrackerOperation;
use App\Play\Domain\GameSystem\GameSystemSnapshot;
use App\Play\Domain\GameSystem\Sheet\ConditionsField;
use App\Play\Domain\GameSystem\Sheet\DerivedField;
use App\Play\Domain\GameSystem\Sheet\Field;
use App\Play\Domain\GameSystem\Sheet\InventoryField;
use App\Play\Domain\GameSystem\Sheet\ListField;
use App\Play\Domain\GameSystem\Sheet\NumberField;
use App\Play\Domain\GameSystem\Sheet\SelectField;
use App\Play\Domain\GameSystem\Sheet\SelectOption;
use App\Play\Domain\GameSystem\Sheet\TextField;
use App\Play\Domain\GameSystem\Sheet\TrackField;
use App\Play\Domain\GameSystem\TrackerKind;
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
        // The heist uses three trackers; the release also holds the campaign's (a Campaign keeps tracker values, not the flow).
        self::assertSame(['alarm', 'edge', 'objective'], $flow->trackers ?? []);
        self::assertSame(['alarm', 'edge', 'objective', 'rep', 'heat', 'lifestyle', 'housing', 'month', 'gig'], array_map(static fn (\App\Play\Domain\GameSystem\Tracker $tracker): string => $tracker->key, $snapshot->trackers()));

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
            ['role', 'nerve', 'hands', 'wits', 'grit', 'charm', 'cool', 'hp', 'wounded', 'humanity', 'eddies', 'gear', 'cyberware'],
            array_map(static fn (Field $field): string => $field->key, $sheet->fields()),
        );
        foreach (['role' => TextField::class, 'nerve' => NumberField::class, 'hp' => TrackField::class, 'humanity' => TrackField::class, 'eddies' => NumberField::class, 'gear' => InventoryField::class, 'cyberware' => InventoryField::class] as $key => $type) {
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
    public function nightJobHasASurvivalEconomyOfEddiesInventoriesAndAGearCatalog(): void
    {
        $this->assertPublishesOnce('night-job');
        $snapshot = $this->snapshot('night-job');
        $sheet = $snapshot->sheet();

        // Eddies are the currency; gear and cyberware are inventories that split the catalog by category (ADR 0045).
        $eddies = $sheet->field('eddies');
        self::assertInstanceOf(NumberField::class, $eddies);
        self::assertSame([0, 500], [$eddies->min, $eddies->default]);
        self::assertSame(['eddies', 'eb'], [$sheet->currency()?->field, $sheet->currency()?->unit]);
        $gear = $sheet->field('gear');
        $cyberware = $sheet->field('cyberware');
        self::assertInstanceOf(InventoryField::class, $gear);
        self::assertInstanceOf(InventoryField::class, $cyberware);
        self::assertSame([20, ['Gear', 'Food']], [$gear->capacity, $gear->categories]);
        self::assertSame([null, 10, ['Chrome']], [$cyberware->capacity, $cyberware->maxStacks, $cyberware->categories]);

        // Costs follow the book's price categories; every catalog entry fits one inventory.
        $catalog = [];
        foreach ($snapshot->gear() as $entry) {
            $catalog[$entry->key] = [$entry->category, $entry->cost, $entry->slots];
        }

        self::assertSame([
            'used-pistol' => ['Gear', 50, 1],
            'armor-jacket' => ['Gear', 100, 2],
            'burner-agent' => ['Gear', 50, 1],
            'climbing-gear' => ['Gear', 100, 2],
            'medkit' => ['Gear', 100, 1],
            'kibble' => ['Food', 10, 1],
            'real-coffee' => ['Food', 20, 1],
            'cyberoptic' => ['Chrome', 100, 0],
            'neural-link' => ['Chrome', 500, 0],
            'interface-plugs' => ['Chrome', 100, 0],
        ], $catalog);
        foreach ($catalog as $key => [$category]) {
            self::assertContains($category, [...($gear->categories ?? []), ...($cyberware->categories ?? [])], $key);
        }

        // The counters the campaign tracks: levels name the lifestyle and the housing.
        $levels = static fn (string $key): array => array_map(static fn (\App\Play\Domain\GameSystem\TrackerLevel $level): string => $level->label, $snapshot->tracker($key)->levels ?? []);
        self::assertSame(['Kibble', 'Prepak', 'Good prepak', 'Fresh food'], $levels('lifestyle'));
        self::assertSame(['Cube hotel', 'Cargo container', 'Studio apartment', 'Two-room conapt'], $levels('housing'));
        self::assertSame([1, 0], [$snapshot->tracker('lifestyle')?->initial, $snapshot->tracker('housing')?->initial]);
        self::assertSame([TrackerKind::Clock, 4], [$snapshot->tracker('month')?->kind, $snapshot->tracker('month')?->segments()]);
        self::assertSame('At 8+ someone sends a hit squad', $snapshot->tracker('heat')?->hint);
    }

    #[Test]
    public function nightJobHasAMakingANameCampaignFlowBesideTheHeist(): void
    {
        $display = $this->assertPublishesOnce('night-job');
        $snapshot = $this->snapshot('night-job');

        // The heist stays the default; the campaign is chosen on the New campaign form.
        self::assertSame(['heist', 'campaign'], array_map(static fn (Flow $flow): string => $flow->key, $snapshot->flows()));
        self::assertSame('heist', $snapshot->defaultFlow()?->key);
        $campaign = $snapshot->flow('campaign');
        self::assertNotNull($campaign);
        self::assertSame(['Making a Name', false, FlowView::Focus], [$campaign->name, $campaign->default, $campaign->defaultView]);
        self::assertNotEmpty($campaign->introduction);
        self::assertSame(['rep', 'heat', 'lifestyle', 'housing', 'month', 'gig', 'alarm', 'edge', 'objective'], $campaign->trackers);

        // Three acts as phases, in the order of flow example 4.
        $phases = [];
        foreach ($campaign->phases as $phase) {
            $phases[$phase->key] = [$phase->act, $phase->mode, $phase->selection->rule, $phase->selection->sceneTypes];
        }

        self::assertSame([
            'street-zero' => [null, PhaseMode::Once, SelectionRule::Sequence, ['edgerunner', 'turf', 'fixer']],
            'making-a-name' => ['Act 1: Making a name', PhaseMode::Loop, SelectionRule::Player, ['gig-offer', 'downtime', 'recruit', 'social']],
            'planning' => ['Act 2: The big job', PhaseMode::Loop, SelectionRule::Player, ['social', 'exploration', 'recruit', 'gear-up']],
            'the-job' => ['Act 2: The big job', PhaseMode::Loop, SelectionRule::Player, ['infiltration', 'netrun', 'grab']],
            'fallout' => ['Act 2: The big job', PhaseMode::Once, SelectionRule::Sequence, ['getaway', 'fallout']],
            'finale' => ['Act 3: The twist and conclusion', PhaseMode::Once, SelectionRule::Sequence, ['betrayal', 'showdown', 'epilogue']],
        ], $phases);

        // Every table the campaign rolls resolves, and each is listed among the flow's oracles.
        foreach (['gigs' => 12, 'street-events' => 5, 'twists' => 8, 'night-market-stalls' => 8, 'night-market-finds' => 8] as $table => $entries) {
            self::assertContains($table, $campaign->oracles);
            $this->assertWeightedTableResolves($snapshot, $table, $entries, $snapshot->resolveOracleTable($table, new ScriptedRandomNumberGenerator(1))->steps()[0]->text(), $snapshot->resolveOracleTable($table, new ScriptedRandomNumberGenerator($entries))->steps()[0]->text());
        }

        // Publishing tells no authoring warning about the campaign (the heist's forced Firefight is the only one).
        self::assertStringNotContainsString('flows[1]', $display);
        foreach (['edgerunner', 'turf', 'fixer', 'gig-offer', 'legwork', 'job', 'gig-payday', 'downtime', 'recruit', 'hit-squad', 'gear-up', 'night-market', 'months-end', 'fallout', 'betrayal', 'showdown', 'epilogue'] as $key) {
            self::assertNotNull($snapshot->sceneType($key), $key);
            self::assertStringNotContainsString(\sprintf('sceneTypes.%s', $key), $display);
        }
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
                    if ($step instanceof PromptStep) {
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
        self::assertSame('eldritch', $snapshot->theme());

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
        // The messy critical feeds (Hunger −2) and exposes (Masquerade +1); a plain failure only exposes.
        self::assertEquals(
            [new SheetEffect('hunger', SheetOperation::Subtract, 2), new TrackerEffect('masquerade', TrackerOperation::Add, 1)],
            $hunt->specialOutcomes['messyCritical']->effects,
        );
        self::assertEquals([new TrackerEffect('masquerade', TrackerOperation::Add, 1)], $hunt->outcomes['failure']->effects);

        // The world turn rolls the Second Inquisition against the Masquerade tracker.
        $flow = $snapshot->defaultFlow();
        self::assertNotNull($flow);
        self::assertSame('chronicle', $flow->key);
        $worldTurn = $flow->phase('chronicle')->worldTurn ?? self::fail('No world turn.');
        $inquisition = $worldTurn->step('inquisition');
        self::assertInstanceOf(RollStep::class, $inquisition);
        // The clock check runs on every route, and the Inquisition roll is the turn's last step so a raid wins over a strike.
        self::assertSame('stir', $worldTurn->steps[0]->key);
        self::assertSame('inquisition', $worldTurn->steps[\count($worldTurn->steps) - 1]->key);
        $upTo = $inquisition->bands[0]->upTo;
        self::assertInstanceOf(Formula::class, $upTo);
        self::assertSame('{tracker:masquerade}', $upTo->toString());
        self::assertContains('masquerade', array_map(static fn (\App\Play\Domain\GameSystem\Tracker $tracker): string => $tracker->key, $snapshot->trackers()));

        // The strike's closing prompt resets the Hunters clock, so it cannot be skipped.
        $reset = $snapshot->sceneType('hunters-strike')?->closing->step('prompt-1');
        self::assertInstanceOf(PromptStep::class, $reset);
        self::assertTrue($reset->mandatory);
    }

    #[Test]
    public function westMarchesPublishesOnceWithTheSrdStatementVerbatimAndNoOtherTrademark(): void
    {
        $this->assertPublishesOnce('west-marches');

        $snapshot = $this->snapshot('west-marches');
        self::assertSame(['West Marches', 1, 'parchment'], [$snapshot->name(), $snapshot->releaseVersion(), $snapshot->theme()]);

        // The statement the SRD 5.2.1 requires (CC BY 4.0), word for word, then the note that the rules were adapted (ADR 0021).
        $statement = 'This work includes material from the System Reference Document 5.2.1 (“SRD 5.2.1”) by Wizards of the Coast LLC, available at https://www.dndbeyond.com/srd. The SRD 5.2.1 is licensed under the Creative Commons Attribution 4.0 International License, available at https://creativecommons.org/licenses/by/4.0/legalcode.';
        $credits = $snapshot->credits();
        self::assertNotNull($credits);
        self::assertSame($statement.' Rules summarized and adapted for guided solo play.', $credits->notice);
        self::assertSame(['CC BY 4.0', 'https://creativecommons.org/licenses/by/4.0/legalcode'], [$credits->license, $credits->url]);

        // The SRD allows "5E compatible" and no other reference to the publisher or the game: not outside the statement.
        /** @var array{gameSystem: array{description: string, credits: array<string, string>}} $content */
        $content = json_decode((string) file_get_contents(self::PRESETS.'/west-marches.json'), true, flags: \JSON_THROW_ON_ERROR);
        unset($content['gameSystem']['credits']['notice']);
        $rest = json_encode($content, \JSON_THROW_ON_ERROR);
        foreach (['Dungeons', 'D&D', 'Wizards'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $rest);
        }

        self::assertStringContainsString('5E compatible', $content['gameSystem']['description']);
    }

    #[Test]
    public function westMarchesSheetHasTheSrdChoicesConditionsAndASingleGoldCurrency(): void
    {
        $this->assertPublishesOnce('west-marches');
        $sheet = $this->snapshot('west-marches')->sheet();

        // Class, species and background are selects of the SRD 5.2.1 lists (ADR 0048).
        $options = static function (string $key) use ($sheet): array {
            $field = $sheet->field($key);
            self::assertInstanceOf(SelectField::class, $field, $key);

            return array_map(static fn (SelectOption $option): string => $option->key, $field->options);
        };
        self::assertSame(['barbarian', 'bard', 'cleric', 'druid', 'fighter', 'monk', 'paladin', 'ranger', 'rogue', 'sorcerer', 'warlock', 'wizard'], $options('class'));
        self::assertSame(['dragonborn', 'dwarf', 'elf', 'gnome', 'goliath', 'halfling', 'human', 'orc', 'tiefling'], $options('species'));
        self::assertSame(['acolyte', 'criminal', 'sage', 'soldier'], $options('background'));

        // Fourteen conditions; Exhaustion scales, so it is a number from 0 to 6.
        $conditions = $sheet->field('conditions');
        self::assertInstanceOf(ConditionsField::class, $conditions);
        self::assertCount(14, $conditions->options);
        self::assertContains('poisoned', $conditions->optionKeys());
        self::assertNotContains('exhaustion', $conditions->optionKeys());
        $exhaustion = $sheet->field('exhaustion');
        self::assertInstanceOf(NumberField::class, $exhaustion);
        self::assertSame([0, 6], [$exhaustion->min, $exhaustion->max]);

        // Ability scores roll 4d6 keep 3 or spend 72 points between 8 and 15, which the standard array fits.
        $strength = $sheet->field('str');
        self::assertInstanceOf(NumberField::class, $strength);
        self::assertSame([3, 20, '4d6kh3'], [$strength->min, $strength->max, $strength->roll?->notation()]);
        $budget = $sheet->budgets()[0];
        self::assertSame([72, ['str', 'dex', 'con', 'int', 'wis', 'cha'], 8, 15], [$budget->total, $budget->fields, $budget->min, $budget->max]);

        // Hit points and hit dice start from formulas; the five slot tracks set their own maximum.
        $hp = $sheet->field('hp');
        $hitDice = $sheet->field('hit-dice');
        self::assertInstanceOf(TrackField::class, $hp);
        self::assertInstanceOf(TrackField::class, $hitDice);
        self::assertSame(['max(1,{hit-die}+{con-mod})', '{level}'], [$hp->startMax?->toString(), $hitDice->startMax?->toString()]);
        foreach (range(1, 5) as $level) {
            $slots = $sheet->field('slots-'.$level);
            self::assertInstanceOf(TrackField::class, $slots);
            self::assertNull($slots->max);
        }

        // Modifiers floor at the SRD's table, and the proficiency bonus steps every four levels.
        $derived = static fn (string $key): string => $sheet->field($key) instanceof DerivedField ? $sheet->field($key)->formula->toString() : '';
        self::assertSame(['({str}-10)/2', '({cha}-10)/2', '2+({level}-1)/4'], [$derived('str-mod'), $derived('cha-mod'), $derived('prof')]);

        // Gold is the currency; the pack holds gear, weapons and armor in 30 slots.
        self::assertSame(['gp', 'GP'], [$sheet->currency()?->field, $sheet->currency()?->unit]);
        $pack = $sheet->field('pack');
        self::assertInstanceOf(InventoryField::class, $pack);
        self::assertSame([30, ['Gear', 'Weapons', 'Armor']], [$pack->capacity, $pack->categories]);
        $gold = $sheet->field('gp');
        self::assertInstanceOf(NumberField::class, $gold);
        self::assertSame([0, 50], [$gold->min, $gold->default]);
    }

    #[Test]
    public function westMarchesHasAD20CheckForEveryModeAndAGearCatalogInWholeGold(): void
    {
        $this->assertPublishesOnce('west-marches');
        $snapshot = $this->snapshot('west-marches');

        // Six abilities and three attacks, each in three modes, plus the foe's attack and the death save: 29 checks.
        $keys = array_map(static fn (\App\Play\Domain\GameSystem\Check\Check $check): string => $check->key, $snapshot->checks());
        self::assertCount(29, $keys);
        foreach (['str', 'dex', 'con', 'int', 'wis', 'cha', 'melee', 'ranged', 'spell'] as $base) {
            self::assertSame(['1d20', '2d20kh1', '2d20kl1'], array_map(static fn (string $key): ?string => $snapshot->check($key)?->dice?->notation(), [$base, $base.'-adv', $base.'-dis']), $base);
        }

        // Every d20 test carries the exhaustion penalty; a roll below the DC fails.
        $melee = $snapshot->check('melee');
        self::assertNotNull($melee);
        self::assertSame('{str-mod}+{prof}-2*{exhaustion}', $melee->modifier?->toString());
        self::assertSame(['fail', 'success'], array_map(static fn (\App\Play\Domain\GameSystem\Check\CheckBand $band): string => $band->key, $melee->bands));
        self::assertSame(12, $melee->difficulty?->default);
        self::assertSame(['miss', 'hit'], array_map(static fn (\App\Play\Domain\GameSystem\Check\CheckBand $band): string => $band->key, $snapshot->check('defend')->bands ?? []));
        self::assertSame(['fail', 'success', 'rise'], array_map(static fn (\App\Play\Domain\GameSystem\Check\CheckBand $band): string => $band->key, $snapshot->check('death-save')->bands ?? []));

        // SRD prices rounded up to whole gold; every entry fits the pack's categories.
        $prices = [];
        foreach ($snapshot->gear() as $entry) {
            $prices[$entry->key] = $entry->cost;
            self::assertContains($entry->category, ['Gear', 'Weapons', 'Armor'], $entry->key);
        }

        self::assertSame(['potion-of-healing' => 50, 'rope' => 1, 'thieves-tools' => 25, 'longsword' => 15, 'greataxe' => 30, 'longbow' => 50, 'chain-mail' => 75, 'shield' => 10], array_intersect_key($prices, array_flip(['potion-of-healing', 'rope', 'thieves-tools', 'longsword', 'greataxe', 'longbow', 'chain-mail', 'shield'])));
    }

    #[Test]
    public function westMarchesHasAFoundingThenAnExpeditionsLoopAndPublishesWithoutAWarning(): void
    {
        $display = $this->assertPublishesOnce('west-marches');
        $snapshot = $this->snapshot('west-marches');

        // The authoring warning would name the flow or a Scene Type; none is printed.
        self::assertStringNotContainsString('Warning', $display);

        $flow = $snapshot->defaultFlow();
        self::assertNotNull($flow);
        self::assertSame(['west-marches', FlowView::Focus, ['away', 'rations', 'torches', 'foe', 'threat', 'expeditions']], [$flow->key, $flow->defaultView, $flow->trackers]);
        self::assertNotEmpty($flow->introduction);
        $phases = [];
        foreach ($flow->phases as $phase) {
            self::assertNotEmpty($phase->description, $phase->key);
            $phases[$phase->key] = [$phase->mode, $phase->selection->rule, $phase->selection->sceneTypes];
        }

        self::assertSame([
            'founding' => [PhaseMode::Once, SelectionRule::Sequence, ['founding-town', 'roster']],
            'expeditions' => [PhaseMode::Loop, SelectionRule::Player, ['town', 'travel', 'explore-site', 'delve', 'camp', 'return']],
        ], $phases);

        // The world turn's first two steps guard a fight in progress and one still being settled; the opening picks a thread and a party.
        $expeditions = $flow->phase('expeditions');
        self::assertSame(['fight', 'settling', 'in-town', 'wander', 'distance', 'near', 'mid', 'deep'], array_map(static fn (\App\Play\Domain\GameSystem\Flow\Step $step): string => $step->key, $expeditions?->worldTurn->steps ?? []));
        self::assertSame(['expedition', 'new-rumor', 'party', 'fresh'], array_map(static fn (\App\Play\Domain\GameSystem\Flow\Step $step): string => $step->key, $expeditions?->sessionOpening->steps ?? []));

        // Every Scene Type of the design exists and every table the flow rolls is listed and resolves at both ends.
        foreach (['founding-town', 'roster', 'town', 'travel', 'explore-site', 'delve', 'camp', 'return', 'encounter', 'combat', 'combat-round', 'victory', 'rest'] as $key) {
            self::assertNotNull($snapshot->sceneType($key), $key);
        }

        foreach (['rumors' => 12, 'encounters-near' => 8, 'encounters-mid' => 8, 'encounters-deep' => 8, 'site-features' => 8, 'rooms' => 10, 'stranded' => 6, 'spoils' => 6] as $table => $entries) {
            self::assertContains($table, $flow->oracles);
            $this->assertWeightedTableResolves($snapshot, $table, $entries, $snapshot->resolveOracleTable($table, new ScriptedRandomNumberGenerator(1))->steps()[0]->text(), $snapshot->resolveOracleTable($table, new ScriptedRandomNumberGenerator($entries))->steps()[0]->text());
        }

        self::assertSame(['fate'], $this->likelihoodKeys($snapshot));
        self::assertSame(['away', 'rations', 'torches', 'foe', 'threat', 'expeditions'], array_map(static fn (\App\Play\Domain\GameSystem\Tracker $tracker): string => $tracker->key, $snapshot->trackers()));
        self::assertSame(['None', 'Minor', 'Tough', 'Deadly'], array_map(static fn (\App\Play\Domain\GameSystem\TrackerLevel $level): string => $level->label, $snapshot->tracker('threat')->levels ?? []));
        self::assertSame(['town', 'expedition', 'foe'], array_map(static fn (\App\Play\Domain\GameSystem\FactSlot $slot): string => $slot->key, $snapshot->factSlots()));
        self::assertSame(['town', 'site', 'near', 'mid', 'deep'], array_values(array_map(static fn (\App\Play\Domain\GameSystem\Tag $tag): string => $tag->key, array_filter($snapshot->tags(), static fn (\App\Play\Domain\GameSystem\Tag $tag): bool => [\App\Play\Domain\GameSystem\Flow\ItemKind::Place] === $tag->appliesTo))));
    }

    #[Test]
    public function ironswornPublishesOnceWithItsCcByCreditsPointBudgetAndChallengeChecks(): void
    {
        $this->assertPublishesOnce('ironsworn');

        $snapshot = $this->snapshot('ironsworn');
        self::assertSame(['Ironsworn (unofficial)', 1, 'parchment'], [$snapshot->name(), $snapshot->releaseVersion(), $snapshot->theme()]);

        // CC BY 4.0 (ADR 0021): the notice credits the author, names the licence and disclaims an official product; no badge, as the licence covers text only.
        $credits = $snapshot->credits();
        self::assertNotNull($credits);
        self::assertStringContainsString('created by Shawn Tomkin', $credits->notice);
        self::assertStringContainsString('Creative Commons Attribution 4.0 International License (https://creativecommons.org/licenses/by/4.0/)', $credits->notice);
        self::assertStringContainsString('not an official Tomkin Press or Ironsworn product', $credits->notice);
        self::assertSame(['CC BY 4.0', 'https://creativecommons.org/licenses/by/4.0/'], [$credits->license, $credits->url]);

        // The five stats are spent as 9 points of 1 to 3 each.
        $budgets = $snapshot->sheet()->budgets();
        self::assertCount(1, $budgets);
        self::assertSame([9, ['edge', 'heart', 'iron', 'shadow', 'wits'], 1, 3], [$budgets[0]->total, $budgets[0]->fields, $budgets[0]->min, $budgets[0]->max]);
        self::assertInstanceOf(NumberField::class, $snapshot->sheet()->field('momentum'));
        self::assertSame([-6, 10], [$snapshot->sheet()->field('momentum')->min, $snapshot->sheet()->field('momentum')->max]);

        // An action roll is an action die against two d10 and can burn the momentum; a progress roll has no action die and no burn (ADR 0049).
        $edge = $snapshot->check('edge') ?? self::fail('No edge.');
        $action = $edge->challenge ?? self::fail('No challenge.');
        self::assertSame(['1d6', 2, 10, 10, 'momentum', 'Burn momentum'], [$edge->dice?->notation(), $action->count, $action->sides, $action->scoreMax, $action->momentum, $edge->push?->label]);
        $fulfill = $snapshot->check('fulfill-1') ?? self::fail('No fulfill-1.');
        $progress = $fulfill->challenge ?? self::fail('No challenge.');
        self::assertSame([null, 2, 10, null, null], [$fulfill->dice, $progress->count, $progress->sides, $progress->momentum, $fulfill->push]);
        foreach ($snapshot->checks() as $check) {
            self::assertSame(['miss', 'weak', 'strong'], array_map(static fn (\App\Play\Domain\GameSystem\Check\CheckBand $band): string => $band->key, $check->bands), $check->key);
        }
        self::assertCount(19, $snapshot->checks());

        // The eight SRD tables are 1d100 with an entry for every roll, and the oracle odds are the five of the SRD.
        self::assertSame(['action', 'theme', 'combat-action', 'major-plot-twist', 'challenge-rank', 'pay-the-price', 'endure-harm-table', 'endure-stress-table'], $snapshot->oracleTableKeys());
        $this->assertRangedTableResolves($snapshot, 'challenge-rank', 'Troublesome', 'Epic');
        $this->assertRangedTableResolves($snapshot, 'action', 'Scheme', 'Summon');
        self::assertSame(['ask-the-oracle'], $this->likelihoodKeys($snapshot));
        self::assertSame(['almost-certain' => 90, 'likely' => 75, 'even' => 50, 'unlikely' => 25, 'small-chance' => 10], $this->targets($snapshot->likelihoodOracle('ask-the-oracle')->oracle()->levels()));
    }

    #[Test]
    public function ironswornHasAPrologueAnAdventureLoopOfTwentyMovesAndAnEpilogue(): void
    {
        // The only authoring warnings are the three on the Fight scene, whose guard on the foe's rank sits beside consequences that come from rolls, not from the rank.
        $display = $this->assertPublishesOnce('ironsworn');
        self::assertSame(3, substr_count($display, 'Warning'));
        self::assertSame(3, substr_count($display, 'does not lower tracker fight-rank'));

        $flow = $this->snapshot('ironsworn')->defaultFlow() ?? self::fail('No flow.');
        self::assertSame(['ironsworn', ['prologue', 'adventure', 'epilogue'], [PhaseMode::Once, PhaseMode::Loop, PhaseMode::Once]], [$flow->key, array_map(static fn (\App\Play\Domain\GameSystem\Flow\Phase $phase): string => $phase->key, $flow->phases), array_map(static fn (\App\Play\Domain\GameSystem\Flow\Phase $phase): PhaseMode => $phase->mode, $flow->phases)]);
        // The player picks one of twenty moves; the suffer moves are reached only through the moves that send you there.
        $adventure = $flow->phases[1]->selection;
        self::assertSame([SelectionRule::Player, 20], [$adventure->rule, \count($adventure->sceneTypes)]);
        foreach (['endure-harm', 'endure-stress', 'face-death', 'face-desolation'] as $suffer) {
            self::assertNotContains($suffer, $adventure->sceneTypes);
        }
    }

    /**
     * @return string what the first publication printed, authoring warnings included
     */
    private function assertPublishesOnce(string $preset): string
    {
        $file = self::PRESETS.'/'.$preset.'.json';

        self::assertSame(Command::SUCCESS, $this->tester->execute(['file' => $file, '--if-changed' => true]), $this->tester->getDisplay());
        self::assertStringContainsString(\sprintf('Published %s v1', $preset), $this->tester->getDisplay());
        $published = $this->tester->getDisplay();

        self::assertSame(Command::SUCCESS, $this->tester->execute(['file' => $file, '--if-changed' => true]), $this->tester->getDisplay());
        self::assertStringContainsString(\sprintf('Unchanged %s (v1)', $preset), $this->tester->getDisplay());

        return $published;
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
