<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Domain\Campaign\FlowRun;

use App\Formula\Domain\Formula;
use App\Play\Domain\GameSystem\Check\Check;
use App\Play\Domain\GameSystem\Check\CheckBand;
use App\Play\Domain\GameSystem\Check\CheckDifficulty;
use App\Play\Domain\GameSystem\Check\CheckPool;
use App\Play\Domain\GameSystem\Check\CheckPush;
use App\Play\Domain\GameSystem\Flow\CheckStep;
use App\Play\Domain\GameSystem\Flow\Effect;
use App\Play\Domain\GameSystem\Flow\Flow;
use App\Play\Domain\GameSystem\Flow\FlowView;
use App\Play\Domain\GameSystem\Flow\Outcome;
use App\Play\Domain\GameSystem\Flow\Phase;
use App\Play\Domain\GameSystem\Flow\PhaseMode;
use App\Play\Domain\GameSystem\Flow\PromptStep;
use App\Play\Domain\GameSystem\Flow\SceneSelection;
use App\Play\Domain\GameSystem\Flow\StepList;
use App\Play\Domain\GameSystem\GameSystemSnapshot;
use App\Play\Domain\GameSystem\SceneType;
use App\Play\Domain\GameSystem\Sheet\NumberField;
use App\Play\Domain\GameSystem\Sheet\SheetTemplate;
use App\Play\Domain\GameSystem\Tracker;
use App\Randomness\Domain\DiceExpression;

/**
 * Snapshots built by hand for check steps (the translator reads them in the next commit): the release
 * "checking" has the sheet number "hands" (default 4), the Tracker "heat" (counter 0 to 20 from 10)
 * and the checks "skill" (1d10 + hands * 2, a difficulty of 13 within 9 to 29, bands "fail" up to
 * difficulty - 1, "success" up to difficulty + 4, "crit") "flat" (1d6, no modifier or
 * difficulty, bands "low" up to 3 and "high"), "broken" (a modifier that divides by zero), "grit"
 * (a pool of 3d6 with successes on 5+, bands "miss" up to 0, "weak" up to 1 and "strong", pushable
 * at no cost, ADR 0041) and "steady" (the same pool without a push).
 *
 * The Flow "play" has one loop phase picking the Scene Type "job", whose setup is the given check
 * step, the prompts "pass" and "fumble", or the prompt "wrap" when the step runs in the world turn
 * (followed by "pass").
 */
final class CheckStepReleases
{
    /**
     * @param array<string, Outcome> $outcomes
     * @param list<Effect>           $effects
     */
    public static function step(string $check = 'skill', int|Formula|null $difficulty = null, array $outcomes = [], ?string $next = null, array $effects = []): CheckStep
    {
        return new CheckStep('sneak', 'Sneak past the guard', null, null, false, $next, $effects, $check, $difficulty, $outcomes);
    }

    public static function release(CheckStep $step, bool $inTheWorldTurn = false, int $version = 1): GameSystemSnapshot
    {
        $prompt = static fn (string $key): PromptStep => new PromptStep($key, ucfirst($key), null, null, false, null, []);
        $sceneType = new SceneType('job', 'Job', 'Do the job.', null, [], new StepList($inTheWorldTurn ? [$prompt('wrap')] : [$step, $prompt('pass'), $prompt('fumble')]), new StepList(), new StepList());
        $phase = new Phase('roam', 'Roaming', null, PhaseMode::Loop, SceneSelection::player(['job']), worldTurn: new StepList($inTheWorldTurn ? [$step, $prompt('pass')] : []));
        $poolBands = [new CheckBand('miss', 'Miss', 0), new CheckBand('weak', 'Weak', 1), new CheckBand('strong', 'Strong')];
        $checks = [
            new Check('skill', 'Skill check', 'Sneak quietly.', DiceExpression::fromString('1d10'), Formula::fromString('{hands} * 2'), new CheckDifficulty('Difficulty Value', 13, 9, 29), [
                new CheckBand('fail', 'Fail', Formula::fromString('{check:difficulty} - 1')),
                new CheckBand('success', 'Success', Formula::fromString('{check:difficulty} + 4')),
                new CheckBand('crit', 'Critical success'),
            ]),
            new Check('broken', 'Broken', null, DiceExpression::fromString('1d6'), Formula::fromString('{hands} / 0'), null, [new CheckBand('only', 'Only')]),
            new Check('flat', 'Flat', null, DiceExpression::fromString('1d6'), null, null, [new CheckBand('low', 'Low', 3), new CheckBand('high', 'High')]),
            new Check('grit', 'Grit', null, null, null, null, $poolBands, new CheckPool(3, 6, 5), new CheckPush('Push it')),
            new Check('steady', 'Steady', null, null, null, null, $poolBands, new CheckPool(3, 6, 5)),
        ];

        return new GameSystemSnapshot('checking', 'Checking', $version, null, [], [], [Tracker::counter('heat', 'Heat', null, 0, 20, 10)], sceneTypes: [$sceneType], flows: [
            new Flow('play', 'Play', null, null, true, FlowView::Journal, [], ['heat'], [$phase]),
        ], sheet: new SheetTemplate([new NumberField('hands', 'Hands', default: 4)]), checks: $checks);
    }
}
