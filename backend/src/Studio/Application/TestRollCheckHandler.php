<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Randomness\Domain\RandomNumberGenerator;
use App\Shared\Application\Bus\QueryHandler;
use App\Studio\Domain\Check\CheckTestRoll;
use App\Studio\Domain\Check\CheckTestRollFailed;
use App\Studio\Domain\Check\InvalidTestRoll;
use App\Studio\Domain\Check\SampleSheet;
use App\Studio\Domain\GameSystem\CheckNotFound;
use App\Studio\Domain\GameSystem\GameSystemNotFound;
use App\Studio\Domain\Release\InvalidReleaseContent;

final readonly class TestRollCheckHandler implements QueryHandler
{
    public function __construct(
        private OwnedGameSystems $owned,
        private RandomNumberGenerator $random,
    ) {
    }

    /**
     * @throws GameSystemNotFound    when the user has no such GameSystem
     * @throws InvalidReleaseContent when the draft is schema version 1 (it has no checks)
     * @throws CheckNotFound         when the draft has no check with this key
     * @throws InvalidTestRoll       when a sample, the difficulty or the situational modifier is not a value the draft accepts; nothing is rolled
     * @throws CheckTestRollFailed   when the saved draft is not readable, or a formula or the dice fail for these samples; nothing is rolled
     */
    public function __invoke(TestRollCheck $query): CheckTestRollView
    {
        $gameSystem = $this->owned->get($query->ownerId, $query->key);
        $check = CheckTestRoll::fromStored($gameSystem->check($query->check));
        $sheet = SampleSheet::fromDocument($gameSystem->document(), $query->sheet, $query->trackers);
        $difficulty = $this->optionalInteger($query->difficulty, 'difficulty');
        $situational = $this->optionalInteger($query->situational, 'situational') ?? 0;

        return CheckTestRollView::fromResult($check->perform($this->random, $sheet, $difficulty, $situational));
    }

    private function optionalInteger(mixed $value, string $path): ?int
    {
        return null === $value || \is_int($value) ? $value : throw InvalidTestRoll::notAnInteger($path);
    }
}
