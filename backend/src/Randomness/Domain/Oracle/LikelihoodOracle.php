<?php

declare(strict_types=1);

namespace App\Randomness\Domain\Oracle;

use App\Randomness\Domain\RandomNumberGenerator;

/**
 * An oracle that answers a yes/no question asked with a likelihood level.
 *
 * It rolls 1d<sides> = R against the effective target T, the level's target shifted by the
 * optional chaos factor and clamped to 0…sides: yes when R ≤ T, no otherwise. With
 * p = exceptionalPercent, the yes is exceptional when R ≤ floor(T × p / 100), and the no when
 * R > sides − floor((sides − T) × p / 100).
 */
final readonly class LikelihoodOracle
{
    public const int MIN_SIDES = 2;
    public const int MAX_SIDES = 1000;
    public const int MAX_LEVELS = 20;
    public const int MAX_EXCEPTIONAL_PERCENT = 50;

    /**
     * @param array<string, LikelihoodLevel> $levels by key, in definition order
     */
    private function __construct(
        private int $sides,
        private array $levels,
        private ?LikelihoodChaos $chaos,
        private int $exceptionalPercent,
    ) {
    }

    /**
     * Builds a likelihood oracle from decoded data such as JSON:
     *
     *     {"sides": 100,
     *      "levels": [{"key": "unlikely", "label": "Unlikely", "target": 35},
     *                 {"key": "likely", "label": "Likely", "target": 65}],
     *      "chaos": {"min": 1, "max": 9, "neutral": 5, "shiftPerPoint": 5},
     *      "exceptionalPercent": 20}
     *
     * "chaos" is optional, and "exceptionalPercent" defaults to 0.
     *
     * @param array<mixed> $definition
     *
     * @throws InvalidLikelihoodOracle
     */
    public static function fromArray(array $definition): self
    {
        $sides = $definition['sides'] ?? null;
        if (!\is_int($sides)) {
            throw InvalidLikelihoodOracle::missingSides();
        }

        if ($sides < self::MIN_SIDES || $sides > self::MAX_SIDES) {
            throw InvalidLikelihoodOracle::sidesOutOfRange($sides, self::MIN_SIDES, self::MAX_SIDES);
        }

        $exceptionalPercent = $definition['exceptionalPercent'] ?? 0;
        if (!\is_int($exceptionalPercent)) {
            throw InvalidLikelihoodOracle::exceptionalPercentNotAnInteger();
        }

        if ($exceptionalPercent < 0 || $exceptionalPercent > self::MAX_EXCEPTIONAL_PERCENT) {
            throw InvalidLikelihoodOracle::exceptionalPercentOutOfRange($exceptionalPercent, self::MAX_EXCEPTIONAL_PERCENT);
        }

        return new self(
            $sides,
            self::levelsFromArray($definition['levels'] ?? null, $sides),
            self::chaosFromArray($definition['chaos'] ?? null, $sides),
            $exceptionalPercent,
        );
    }

    public function sides(): int
    {
        return $this->sides;
    }

    /**
     * @return list<LikelihoodLevel> in definition order
     */
    public function levels(): array
    {
        return array_values($this->levels);
    }

    /**
     * @throws InvalidLikelihoodOracle when no level has this key
     */
    public function level(string $key): LikelihoodLevel
    {
        return $this->levels[$key] ?? throw InvalidLikelihoodOracle::unknownLevel($key, array_keys($this->levels));
    }

    public function chaos(): ?LikelihoodChaos
    {
        return $this->chaos;
    }

    public function exceptionalPercent(): int
    {
        return $this->exceptionalPercent;
    }

    /**
     * Asks a yes/no question with this likelihood level and chaos factor (the neutral one when null).
     *
     * @throws InvalidLikelihoodOracle when the level is unknown, or the chaos factor is out of range or given without chaos
     */
    public function ask(string $levelKey, ?int $chaosFactor, RandomNumberGenerator $random): LikelihoodAnswer
    {
        $level = $this->level($levelKey);

        if (!$this->chaos instanceof LikelihoodChaos) {
            if (null !== $chaosFactor) {
                throw InvalidLikelihoodOracle::unexpectedChaosFactor($chaosFactor);
            }

            $shift = 0;
        } else {
            $chaosFactor ??= $this->chaos->neutral();
            $shift = $this->chaos->shift($chaosFactor);
        }

        $target = max(0, min($this->sides, $level->target() + $shift));
        $roll = $random->between(1, $this->sides);

        return new LikelihoodAnswer(
            $this->answerFor($roll, $target),
            $roll,
            $this->sides,
            $target,
            $level->key(),
            $level->label(),
            $chaosFactor,
        );
    }

    private function answerFor(int $roll, int $target): YesNoAnswer
    {
        if ($roll <= $target) {
            return $roll <= intdiv($target * $this->exceptionalPercent, 100) ? YesNoAnswer::ExceptionalYes : YesNoAnswer::Yes;
        }

        $exceptionalNoBand = intdiv(($this->sides - $target) * $this->exceptionalPercent, 100);

        return $roll > $this->sides - $exceptionalNoBand ? YesNoAnswer::ExceptionalNo : YesNoAnswer::No;
    }

    /**
     * @return array<string, LikelihoodLevel>
     */
    private static function levelsFromArray(mixed $levels, int $sides): array
    {
        if (!\is_array($levels) || !array_is_list($levels)) {
            throw InvalidLikelihoodOracle::missingLevels();
        }

        if ([] === $levels || \count($levels) > self::MAX_LEVELS) {
            throw InvalidLikelihoodOracle::levelCountOutOfRange(\count($levels), self::MAX_LEVELS);
        }

        $byKey = [];
        foreach ($levels as $index => $level) {
            $level = self::levelFromArray($level, $index + 1, $sides);
            if (isset($byKey[$level->key()])) {
                throw InvalidLikelihoodOracle::duplicateLevelKey($level->key());
            }

            $byKey[$level->key()] = $level;
        }

        return $byKey;
    }

    private static function levelFromArray(mixed $level, int $position, int $sides): LikelihoodLevel
    {
        if (!\is_array($level)) {
            throw InvalidLikelihoodOracle::levelNotAnObject($position);
        }

        $key = $level['key'] ?? null;
        if (!\is_string($key)) {
            throw InvalidLikelihoodOracle::missingLevelKey($position);
        }

        $label = $level['label'] ?? null;
        if (!\is_string($label)) {
            throw InvalidLikelihoodOracle::missingLabel($key);
        }

        $target = $level['target'] ?? null;
        if (!\is_int($target)) {
            throw InvalidLikelihoodOracle::missingTarget($key);
        }

        return new LikelihoodLevel($key, $label, $target, $sides);
    }

    private static function chaosFromArray(mixed $chaos, int $sides): ?LikelihoodChaos
    {
        if (null === $chaos) {
            return null;
        }

        if (!\is_array($chaos)) {
            throw InvalidLikelihoodOracle::chaosNotAnObject();
        }

        $fields = [];
        foreach (['min', 'max', 'neutral', 'shiftPerPoint'] as $field) {
            $value = $chaos[$field] ?? null;
            if (!\is_int($value)) {
                throw InvalidLikelihoodOracle::missingChaosField($field);
            }

            $fields[$field] = $value;
        }

        return new LikelihoodChaos($fields['min'], $fields['max'], $fields['neutral'], $fields['shiftPerPoint'], $sides);
    }
}
