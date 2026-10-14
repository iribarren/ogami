<?php

declare(strict_types=1);

namespace App\Tests\Integration\Play;

use App\Play\Application\GetGameSystemSnapshot;
use App\Play\Domain\GameSystem\Flow\FlowView;
use App\Play\Domain\GameSystem\GameSystemSnapshot;
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
        self::assertSame(['kingsport-letter', ['the-letter', 'investigation', 'confrontation', 'epilogue']], [$flow?->key, array_map(static fn (\App\Play\Domain\GameSystem\Flow\Phase $phase): string => $phase->key, $flow->phases ?? [])]);
        self::assertSame(['clues', 'dread'], array_map(static fn (\App\Play\Domain\GameSystem\Tracker $tracker): string => $tracker->key, $snapshot->trackers()));
        self::assertSame(['fate'], $this->likelihoodKeys($snapshot));
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
