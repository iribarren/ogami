<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Domain\Campaign\FlowRun;

use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\CampaignId;
use App\Play\Domain\Campaign\FlowRun\FlowRun;
use App\Play\Domain\Campaign\FlowRun\FlowRunHistoryEntry;
use App\Play\Domain\Campaign\FlowRun\StepResult;
use App\Play\Domain\Campaign\PinnedRelease;
use App\Play\Domain\GameSystem\GameSystemSnapshot;
use App\Play\Infrastructure\GameSystem\GameSystemReleaseTranslator;
use App\Randomness\Domain\DiceExpression;
use App\Randomness\Domain\Oracle\LikelihoodAnswer;
use App\Randomness\Domain\Oracle\OracleTableResult;
use App\Randomness\Domain\Oracle\OracleTableStep;
use App\Randomness\Domain\Oracle\YesNoAnswer;
use App\Tests\Support\Play\ReleaseViews;
use App\Tests\Support\Randomness\ScriptedRandomNumberGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Guided campaigns on releases read through Play's anti-corruption layer, and typed step results.
 */
abstract class FlowRunTestCase extends TestCase
{
    /**
     * @param string $fixture a fixture of tests/Fixtures/Studio/releases, e.g. "examples/cpr-heist"
     */
    protected static function release(string $fixture): GameSystemSnapshot
    {
        return self::translate(ReleaseViews::fixtureContent($fixture));
    }

    /**
     * @param array<string, mixed> $content a release of schema version 2
     */
    protected static function translate(array $content): GameSystemSnapshot
    {
        return new GameSystemReleaseTranslator()->translate(ReleaseViews::of($content));
    }

    protected static function guided(GameSystemSnapshot $release, string $flow): Campaign
    {
        return Campaign::create(CampaignId::fromString('campaign-1'), 'user-1', 'The job', PinnedRelease::of($release->gameSystemKey(), $release->releaseVersion(), $release->name()), self::at(), $release->trackers(), $release->flow($flow));
    }

    protected static function flowRunOf(Campaign $campaign): FlowRun
    {
        return $campaign->flowRun() ?? self::fail('The campaign has no FlowRun.');
    }

    /**
     * @return list<string> each history entry as "event" or "event:first detail"
     */
    protected static function history(Campaign $campaign): array
    {
        return array_map(static fn (FlowRunHistoryEntry $entry): string => $entry->event->value.([] === $entry->details ? '' : ':'.array_first($entry->details)), self::flowRunOf($campaign)->history());
    }

    /**
     * @return array{?string, ?string} the current part and step key
     */
    protected static function position(Campaign $campaign): array
    {
        return [self::flowRunOf($campaign)->part()?->value, self::flowRunOf($campaign)->stepKey()];
    }

    protected static function at(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-10-10T09:00:00+00:00');
    }

    protected static function prompt(string $text): StepResult
    {
        return StepResult::prompt($text);
    }

    protected static function table(string $table, string $text, int $total = 1): StepResult
    {
        return StepResult::table(new OracleTableResult([new OracleTableStep($table, $table, '1d6', $total, $text, null)]));
    }

    protected static function oracle(YesNoAnswer $answer): StepResult
    {
        return StepResult::oracle(new LikelihoodAnswer($answer, 40, 100, 50, 'even', '50/50', 5));
    }

    protected static function roll(string $dice, int $total): StepResult
    {
        return StepResult::roll(DiceExpression::fromString($dice)->roll(new ScriptedRandomNumberGenerator($total)));
    }
}
