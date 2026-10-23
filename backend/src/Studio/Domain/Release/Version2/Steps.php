<?php

declare(strict_types=1);

namespace App\Studio\Domain\Release\Version2;

use App\Randomness\Domain\DiceExpression;
use App\Randomness\Domain\InvalidDiceExpression;
use App\Studio\Domain\Release\InvalidReleaseContent;
use App\Studio\Domain\Release\ReleaseFields;

/**
 * Step lists of schema version 2 (a Scene Type part or a phase hook): steps are a tagged union on
 * "kind". Keys are unique within the list and "end" is reserved; every "next" names a later step of
 * the same list or "end", so a part only moves forward.
 *
 * @internal
 */
final class Steps
{
    public const int MAX_STEPS = 50;
    public const int MAX_TITLE_LENGTH = 100;
    public const int MAX_TEXT_LENGTH = 2000;
    public const int MIN_OPTIONS = 2;
    public const int MAX_OPTIONS = 10;
    public const int MAX_TABLE_BRANCHES = 1000;
    public const int MAX_PICK_COUNT = 10;
    public const int MAX_INSPIRATION = 5;
    public const string END = 'end';

    /** What a pick or a condition count ranges over. */
    public const array ITEM_KINDS = ['npc', 'thread', 'character'];

    private const array COMMON = ['key' => true, 'kind' => true, 'title' => true, 'prompt' => false, 'tip' => false, 'mandatory' => false, 'next' => false, 'effects' => false];

    /** The fields each kind adds to COMMON, in schema order: name => required. */
    private const array KINDS = [
        'prompt' => ['inspiration' => false],
        'oracle' => ['oracle' => true, 'likelihood' => false, 'branches' => false],
        'table' => ['table' => true, 'branches' => false, 'otherwise' => false],
        'roll' => ['dice' => true, 'bands' => false],
        'choice' => ['options' => true, 'skip' => false],
        'condition' => ['tracker' => false, 'count' => false, 'bands' => true],
        'pick' => ['of' => true, 'tags' => false, 'mode' => true, 'count' => false, 'branches' => false],
        'character' => [],
        'check' => ['check' => true, 'difficulty' => false, 'outcomes' => false],
    ];

    private const array PICK_BRANCHES = ['found' => false, 'none' => false];
    private const array PICK_COUNT = ['min' => true, 'max' => true];
    private const array CONDITION_COUNT = ['of' => true, 'tags' => false];
    private const array ORACLE_BRANCHES = ['yes' => false, 'no' => false, 'exceptionalYes' => false, 'exceptionalNo' => false];
    private const array OUTCOME = ['next' => false, 'effects' => false];
    private const array CHECK_OUTCOME = ['band' => true, 'next' => false, 'effects' => false];
    private const array TABLE_BRANCH = ['entry' => true, 'next' => false, 'effects' => false];
    private const array OPTION = ['key' => true, 'label' => true, 'next' => false, 'effects' => false];

    /**
     * @return list<array<string, mixed>> canonical steps
     */
    public static function list(mixed $value, string $path, Catalog $catalog): array
    {
        $steps = [];
        $positions = [];
        foreach (ReleaseFields::sizedList($value, $path, 0, self::MAX_STEPS, 'steps') as $index => $step) {
            $stepPath = \sprintf('%s[%d]', $path, $index);
            $step = self::step($step, $stepPath, $catalog);
            /** @var string $key */
            $key = $step['key'];
            if (isset($positions[$key])) {
                throw InvalidReleaseContent::at($stepPath.'.key', \sprintf('duplicate step key "%s"', $key));
            }

            $positions[$key] = $index;
            $steps[] = $step;
        }

        foreach ($steps as $index => $step) {
            foreach (StepParts::nexts($step, \sprintf('%s[%d]', $path, $index)) as $nextPath => $next) {
                if (self::END !== $next && ($positions[$next] ?? -1) <= $index) {
                    throw InvalidReleaseContent::at($nextPath, \sprintf('must name a later step of the same list or "%s", "%s" given', self::END, $next));
                }
            }
        }

        return $steps;
    }

    /**
     * @return array<string, mixed>
     */
    private static function step(mixed $value, string $path, Catalog $catalog): array
    {
        $kind = ReleaseFields::tag($value, $path, 'kind', array_keys(self::KINDS));
        $step = ReleaseFields::object($value, $path, [...self::COMMON, ...self::KINDS[$kind]]);

        $key = ReleaseFields::key($step['key'], $path.'.key');
        if (self::END === $key) {
            throw InvalidReleaseContent::at($path.'.key', \sprintf('"%s" is reserved', self::END));
        }

        ReleaseFields::text($step['title'], $path.'.title', 1, self::MAX_TITLE_LENGTH);
        foreach (['prompt', 'tip'] as $text) {
            if (isset($step[$text])) {
                ReleaseFields::text($step[$text], $path.'.'.$text, 0, self::MAX_TEXT_LENGTH);
            }
        }

        if (isset($step['mandatory']) && ReleaseFields::boolean($step['mandatory'], $path.'.mandatory') && 'condition' === $kind) {
            throw InvalidReleaseContent::at($path.'.mandatory', 'a condition step is never mandatory');
        }

        // A character step completes with a Character, so its own effects may change the sheet.
        $step = [...$step, ...self::outcome($step, $path, $catalog, 'character' === $kind)];
        $step = match ($kind) {
            'prompt' => self::prompt($step, $path, $catalog),
            'character' => $step,
            'oracle' => self::oracle($step, $path, $catalog),
            'table' => self::table($step, $path, $catalog),
            'roll' => self::roll($step, $path, $catalog),
            'choice' => self::choice($step, $path, $catalog),
            'condition' => self::condition($step, $path, $catalog),
            'pick' => self::pick($step, $path, $catalog),
            'check' => self::check($step, $path, $catalog),
        };

        return array_filter($step, static fn (mixed $field): bool => [] !== $field && false !== $field);
    }

    /**
     * Checks "next" and "effects" of anything that has them and returns both (effects canonical).
     *
     * @param array<string, mixed> $outcome
     * @param bool                 $sheet   whether the effects may change the sheet (see Effects::list())
     *
     * @return array{next?: string, effects: list<array<string, mixed>>}
     */
    private static function outcome(array $outcome, string $path, Catalog $catalog, bool $sheet = false): array
    {
        $checked = [];
        if (isset($outcome['next'])) {
            $checked['next'] = ReleaseFields::key($outcome['next'], $path.'.next');
        }

        $checked['effects'] = Effects::list($outcome['effects'] ?? null, $path.'.effects', $catalog, $sheet);

        return $checked;
    }

    /**
     * A {next?, effects?} object; empty means the same as absent.
     *
     * @return array<string, mixed>
     */
    private static function outcomeObject(mixed $value, string $path, Catalog $catalog): array
    {
        return array_filter(
            self::outcome(ReleaseFields::object($value, $path, self::OUTCOME), $path, $catalog),
            static fn (mixed $field): bool => [] !== $field,
        );
    }

    /**
     * @param array<string, mixed> $step
     *
     * @return array<string, mixed>
     */
    private static function oracle(array $step, string $path, Catalog $catalog): array
    {
        $oracle = ReleaseFields::key($step['oracle'], $path.'.oracle');
        if (!$catalog->hasLikelihood($oracle)) {
            throw InvalidReleaseContent::at($path.'.oracle', \sprintf('unknown likelihood oracle "%s"', $oracle));
        }

        if (isset($step['likelihood'])) {
            $level = ReleaseFields::key($step['likelihood'], $path.'.likelihood');
            if (!isset($catalog->likelihoodLevels[$oracle][$level])) {
                throw InvalidReleaseContent::at($path.'.likelihood', \sprintf('unknown level "%s" of likelihood oracle "%s"', $level, $oracle));
            }
        }

        if (isset($step['branches'])) {
            $branches = [];
            foreach (ReleaseFields::object($step['branches'], $path.'.branches', self::ORACLE_BRANCHES) as $answer => $branch) {
                $branches[$answer] = self::outcomeObject($branch, $path.'.branches.'.$answer, $catalog);
            }

            $step['branches'] = array_filter($branches, static fn (array $branch): bool => [] !== $branch);
        }

        return $step;
    }

    /**
     * The inspiration tables are suggestions: each is an oracle table of the catalog, once, and
     * none counts as rolled by the step.
     *
     * @param array<string, mixed> $step
     *
     * @return array<string, mixed>
     */
    private static function prompt(array $step, string $path, Catalog $catalog): array
    {
        if (!isset($step['inspiration'])) {
            return $step;
        }

        $seen = [];
        foreach (ReleaseFields::sizedList($step['inspiration'], $path.'.inspiration', 1, self::MAX_INSPIRATION, 'inspiration tables') as $index => $key) {
            $keyPath = \sprintf('%s.inspiration[%d]', $path, $index);
            $key = ReleaseFields::key($key, $keyPath);
            if (!$catalog->hasTable($key)) {
                throw InvalidReleaseContent::at($keyPath, \sprintf('unknown oracle table "%s"', $key));
            }

            if (isset($seen[$key])) {
                throw InvalidReleaseContent::at($keyPath, \sprintf('duplicate inspiration table "%s"', $key));
            }

            $seen[$key] = true;
        }

        return $step;
    }

    /**
     * @param array<string, mixed> $step
     *
     * @return array<string, mixed>
     */
    private static function table(array $step, string $path, Catalog $catalog): array
    {
        $table = ReleaseFields::key($step['table'], $path.'.table');
        if (!$catalog->hasTable($table)) {
            throw InvalidReleaseContent::at($path.'.table', \sprintf('unknown oracle table "%s"', $table));
        }

        if (isset($step['branches'])) {
            $branches = [];
            $entries = [];
            foreach (ReleaseFields::sizedList($step['branches'], $path.'.branches', 0, self::MAX_TABLE_BRANCHES, 'branches') as $index => $branch) {
                $branchPath = \sprintf('%s.branches[%d]', $path, $index);
                $branch = ReleaseFields::object($branch, $branchPath, self::TABLE_BRANCH);
                $entry = ReleaseFields::key($branch['entry'], $branchPath.'.entry');
                if (!isset($catalog->tableEntryKeys[$table][$entry])) {
                    throw InvalidReleaseContent::at($branchPath.'.entry', \sprintf('unknown entry "%s" of table "%s"', $entry, $table));
                }

                if (isset($entries[$entry])) {
                    throw InvalidReleaseContent::at($branchPath.'.entry', \sprintf('duplicate branch entry "%s"', $entry));
                }

                $entries[$entry] = true;
                $branches[] = array_filter([...$branch, ...self::outcome($branch, $branchPath, $catalog)], static fn (mixed $field): bool => [] !== $field);
            }

            $step['branches'] = $branches;
        }

        if (isset($step['otherwise'])) {
            $step['otherwise'] = self::outcomeObject($step['otherwise'], $path.'.otherwise', $catalog);
        }

        return $step;
    }

    /**
     * @param array<string, mixed> $step
     *
     * @return array<string, mixed>
     */
    private static function roll(array $step, string $path, Catalog $catalog): array
    {
        self::dice($step['dice'], $path.'.dice');
        $step['bands'] = Bands::outcomes($step['bands'] ?? null, $path.'.bands', 0, $catalog);

        return $step;
    }

    /**
     * Dice notation of a roll step or a check, parsed by the Randomness kernel.
     */
    public static function dice(mixed $value, string $path): string
    {
        $dice = ReleaseFields::text($value, $path, 1, DiceExpression::MAX_LENGTH);

        try {
            DiceExpression::fromString($dice);
        } catch (InvalidDiceExpression $invalid) {
            throw InvalidReleaseContent::at($path, 'invalid dice notation: '.$invalid->getMessage(), $invalid);
        }

        return $dice;
    }

    /**
     * A check step names a check of the release; its difficulty is fixed by an integer, a tracker or
     * a flow formula, and each outcome names a band of the check, once.
     *
     * @param array<string, mixed> $step
     *
     * @return array<string, mixed>
     */
    private static function check(array $step, string $path, Catalog $catalog): array
    {
        $checkKey = ReleaseFields::key($step['check'], $path.'.check');
        $check = $catalog->check($checkKey) ?? throw InvalidReleaseContent::at($path.'.check', \sprintf('unknown check "%s"', $checkKey));

        if (isset($step['difficulty'])) {
            $difficultyPath = $path.'.difficulty';
            $range = $check['difficulty'] ?? throw InvalidReleaseContent::at($difficultyPath, \sprintf('check "%s" declares no difficulty', $checkKey));
            $step['difficulty'] = \is_int($step['difficulty'])
                ? self::fixedDifficulty($step['difficulty'], $difficultyPath, $checkKey, $range)
                : Effects::reference($step['difficulty'], $difficultyPath, $catalog);
        }

        if (isset($step['outcomes'])) {
            $outcomes = [];
            foreach (ReleaseFields::sizedList($step['outcomes'], $path.'.outcomes', 0, Checks::MAX_BANDS, 'outcomes') as $index => $outcome) {
                $outcomePath = \sprintf('%s.outcomes[%d]', $path, $index);
                $outcome = ReleaseFields::object($outcome, $outcomePath, self::CHECK_OUTCOME);
                $band = ReleaseFields::key($outcome['band'], $outcomePath.'.band');
                if (!isset($check['bands'][$band])) {
                    throw InvalidReleaseContent::at($outcomePath.'.band', \sprintf('unknown band "%s" of check "%s"', $band, $checkKey));
                }

                if (isset($outcomes[$band])) {
                    throw InvalidReleaseContent::at($outcomePath.'.band', \sprintf('duplicate outcome band "%s"', $band));
                }

                $outcomes[$band] = array_filter([...$outcome, ...self::outcome($outcome, $outcomePath, $catalog, true)], static fn (mixed $field): bool => [] !== $field);
            }

            $step['outcomes'] = array_values($outcomes);
        }

        return $step;
    }

    /**
     * @param array{min: int|null, max: int|null} $range the difficulty range the check declares
     */
    private static function fixedDifficulty(int $value, string $path, string $checkKey, array $range): int
    {
        ReleaseFields::integer($value, $path, -Checks::MAX_DIFFICULTY, Checks::MAX_DIFFICULTY);
        if (null !== $range['min'] && $value < $range['min']) {
            throw InvalidReleaseContent::at($path, \sprintf('must be at least %d, the difficulty min of check "%s", %d given', $range['min'], $checkKey, $value));
        }

        if (null !== $range['max'] && $value > $range['max']) {
            throw InvalidReleaseContent::at($path, \sprintf('must be at most %d, the difficulty max of check "%s", %d given', $range['max'], $checkKey, $value));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $step
     *
     * @return array<string, mixed>
     */
    private static function choice(array $step, string $path, Catalog $catalog): array
    {
        $options = [];
        $keys = [];
        foreach (ReleaseFields::sizedList($step['options'], $path.'.options', self::MIN_OPTIONS, self::MAX_OPTIONS, 'options') as $index => $option) {
            $optionPath = \sprintf('%s.options[%d]', $path, $index);
            $option = ReleaseFields::object($option, $optionPath, self::OPTION);
            $key = ReleaseFields::key($option['key'], $optionPath.'.key');
            if (isset($keys[$key])) {
                throw InvalidReleaseContent::at($optionPath.'.key', \sprintf('duplicate option key "%s"', $key));
            }

            $keys[$key] = true;
            ReleaseFields::text($option['label'], $optionPath.'.label', 1, self::MAX_TITLE_LENGTH);
            $options[] = array_filter([...$option, ...self::outcome($option, $optionPath, $catalog)], static fn (mixed $field): bool => [] !== $field);
        }

        $step['options'] = $options;
        if (isset($step['skip'])) {
            $skip = ReleaseFields::key($step['skip'], $path.'.skip');
            if (!isset($keys[$skip])) {
                throw InvalidReleaseContent::at($path.'.skip', \sprintf('unknown option "%s"', $skip));
            }
        } elseif (true !== ($step['mandatory'] ?? false)) {
            throw InvalidReleaseContent::at($path.'.skip', 'required, a suggested choice names the option a skip follows');
        }

        return $step;
    }

    /**
     * @param array<string, mixed> $step
     *
     * @return array<string, mixed>
     */
    private static function condition(array $step, string $path, Catalog $catalog): array
    {
        if (isset($step['tracker']) && isset($step['count'])) {
            throw InvalidReleaseContent::at($path, 'give either tracker or count, not both');
        }

        if (isset($step['tracker'])) {
            $step['tracker'] = Effects::tracker($step['tracker'], $path.'.tracker', $catalog);
        } elseif (isset($step['count'])) {
            $count = ReleaseFields::object($step['count'], $path.'.count', self::CONDITION_COUNT);
            $of = ReleaseFields::oneOf($count['of'], $path.'.count.of', self::ITEM_KINDS);
            $count['tags'] = Effects::tags($count['tags'] ?? null, $path.'.count.tags', $catalog, $of);
            $step['count'] = array_filter($count, static fn (mixed $field): bool => [] !== $field);
        } else {
            throw InvalidReleaseContent::at($path, 'requires tracker or count');
        }

        $step['bands'] = Bands::outcomes($step['bands'], $path.'.bands', 1, $catalog);

        return $step;
    }

    /**
     * @param array<string, mixed> $step
     *
     * @return array<string, mixed>
     */
    private static function pick(array $step, string $path, Catalog $catalog): array
    {
        $of = ReleaseFields::oneOf($step['of'], $path.'.of', self::ITEM_KINDS);
        ReleaseFields::oneOf($step['mode'], $path.'.mode', ['random', 'choose']);
        $step['tags'] = Effects::tags($step['tags'] ?? null, $path.'.tags', $catalog, $of);

        if (isset($step['count'])) {
            $count = ReleaseFields::object($step['count'], $path.'.count', self::PICK_COUNT);
            $min = ReleaseFields::integer($count['min'], $path.'.count.min', 1, self::MAX_PICK_COUNT);
            $max = ReleaseFields::integer($count['max'], $path.'.count.max', 1, self::MAX_PICK_COUNT);
            if ($max < $min) {
                throw InvalidReleaseContent::at($path.'.count.max', \sprintf('must be at least min (%d), %d given', $min, $max));
            }

            // {1, 1} is the default.
            $step['count'] = 1 === $min && 1 === $max ? [] : ['min' => $min, 'max' => $max];
        }

        if (isset($step['branches'])) {
            $branches = [];
            foreach (ReleaseFields::object($step['branches'], $path.'.branches', self::PICK_BRANCHES) as $outcome => $branch) {
                $branches[$outcome] = self::outcomeObject($branch, $path.'.branches.'.$outcome, $catalog);
            }

            $step['branches'] = array_filter($branches, static fn (array $branch): bool => [] !== $branch);
        }

        return $step;
    }
}
