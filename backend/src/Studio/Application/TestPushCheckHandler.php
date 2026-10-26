<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Randomness\Domain\RandomNumberGenerator;
use App\Shared\Application\Bus\QueryHandler;
use App\Studio\Domain\Check\CheckTestRoll;
use App\Studio\Domain\Check\CheckTestRollFailed;
use App\Studio\Domain\Check\CheckTestRollNotPushable;
use App\Studio\Domain\Check\InvalidTestRoll;
use App\Studio\Domain\Check\SampleSheet;
use App\Studio\Domain\GameSystem\CheckNotFound;
use App\Studio\Domain\GameSystem\GameSystemNotFound;
use App\Studio\Domain\Release\InvalidReleaseContent;

final readonly class TestPushCheckHandler implements QueryHandler
{
    public function __construct(
        private OwnedGameSystems $owned,
        private RandomNumberGenerator $random,
    ) {
    }

    /**
     * @throws GameSystemNotFound       when the user has no such GameSystem
     * @throws InvalidReleaseContent    when the draft is schema version 1 (it has no checks)
     * @throws CheckNotFound            when the draft has no check with this key
     * @throws InvalidTestRoll          when a sample, the difficulty, the situational modifier or the faces are not values the draft accepts; nothing is re-rolled
     * @throws CheckTestRollNotPushable when the check declares no push (a dice check never does), or no die of the roll failed
     * @throws CheckTestRollFailed      when the saved draft is not readable, or a formula fails for these samples; nothing is re-rolled
     */
    public function __invoke(TestPushCheck $query): CheckTestRollView
    {
        $gameSystem = $this->owned->get($query->ownerId, $query->key);
        $check = CheckTestRoll::fromStored($gameSystem->check($query->check));
        $sheet = SampleSheet::fromDocument($gameSystem->document(), $query->sheet, $query->trackers);
        $difficulty = $this->optionalInteger($query->difficulty, 'difficulty');
        $situational = $this->optionalInteger($query->situational, 'situational') ?? 0;

        return CheckTestRollView::fromResult($check->push($this->random, $sheet, $difficulty, $situational, $this->faces($query->faces)));
    }

    private function optionalInteger(mixed $value, string $path): ?int
    {
        return null === $value || \is_int($value) ? $value : throw InvalidTestRoll::notAnInteger($path);
    }

    /**
     * @return list<int>
     */
    private function faces(mixed $faces): array
    {
        if (!\is_array($faces) || !array_is_list($faces)) {
            throw InvalidTestRoll::at('faces', 'must be a list of integers');
        }

        return array_map(static fn (mixed $face): int => \is_int($face) ? $face : throw InvalidTestRoll::at('faces', 'must be a list of integers'), $faces);
    }
}
