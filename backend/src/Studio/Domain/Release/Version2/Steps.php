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
 * @phpstan-import-type CheckShape from Catalog
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
    public const int MIN_CHECK_SET = 2;
    public const int MAX_CHECK_SET = 10;
    public const int MAX_OFFER_GEAR = 100;
    public const int MAX_STOCK = 50;
    public const int MAX_SELL_RATE = 100;
    public const string END = 'end';

    /** What a pick or a condition count ranges over. */
    public const array ITEM_KINDS = ['npc', 'thread', 'place', 'character'];

    private const array COMMON = ['key' => true, 'kind' => true, 'title' => true, 'prompt' => false, 'tip' => false, 'mandatory' => false, 'next' => false, 'effects' => false];

    /** The fields each kind adds to COMMON, in schema order: name => required. */
    private const array KINDS = [
        'prompt' => ['inspiration' => false],
        'oracle' => ['oracle' => true, 'likelihood' => false, 'branches' => false],
        'table' => ['table' => true, 'tableBySceneType' => false, 'branches' => false, 'otherwise' => false],
        'roll' => ['dice' => true, 'bands' => false],
        'choice' => ['options' => true, 'skip' => false],
        'condition' => ['tracker' => false, 'count' => false, 'bands' => true],
        'pick' => ['of' => true, 'tags' => false, 'mode' => true, 'count' => false, 'branches' => false],
        'character' => [],
        'check' => ['check' => false, 'checks' => false, 'difficulty' => false, 'outcomes' => false],
        'shop' => ['offer' => true, 'stock' => false, 'sellRate' => false],
    ];

    private const array PICK_BRANCHES = ['found' => false, 'none' => false];
    private const array PICK_COUNT = ['min' => true, 'max' => true];
    private const array CONDITION_COUNT = ['of' => true, 'tags' => false];
    private const array ORACLE_BRANCHES = ['yes' => false, 'no' => false, 'exceptionalYes' => false, 'exceptionalNo' => false];
    private const array OUTCOME = ['next' => false, 'effects' => false];
    private const array CHECK_OUTCOME = ['band' => false, 'special' => false, 'next' => false, 'effects' => false];
    private const array TABLE_BRANCH = ['entry' => true, 'next' => false, 'effects' => false];
    private const array OPTION = ['key' => true, 'label' => true, 'next' => false, 'effects' => false];
    private const array OFFER = ['gear' => false, 'categories' => false];

    /**
     * @return list<array<string, mixed>> canonical steps
     */
    public static function list(mixed $value, string $path, Catalog $catalog, bool $setup = false): array
    {
        $steps = [];
        $positions = [];
        foreach (ReleaseFields::sizedList($value, $path, 0, self::MAX_STEPS, 'steps') as $index => $step) {
            $stepPath = \sprintf('%s[%d]', $path, $index);
            $step = self::step($step, $stepPath, $catalog, $setup);
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
    private static function step(mixed $value, string $path, Catalog $catalog, bool $setup): array
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

        // A shop is left with an empty transaction (ADR 0045), so nothing is ever skipped there.
        if (isset($step['mandatory']) && 'shop' === $kind) {
            throw InvalidReleaseContent::at($path.'.mandatory', 'a shop step is never skipped, an empty transaction leaves it');
        }

        if (isset($step['mandatory']) && ReleaseFields::boolean($step['mandatory'], $path.'.mandatory') && 'condition' === $kind) {
            throw InvalidReleaseContent::at($path.'.mandatory', 'a condition step is never mandatory');
        }

        // A character step and a prompt step complete with a Character the player picks, so their own effects may change the sheet.
        // A Scene Type's setup has no Character context (ADR 0042): a prompt there, like a choice option, refuses sheet effects.
        $step = [...$step, ...self::outcome($step, $path, $catalog, 'character' === $kind || ('prompt' === $kind && !$setup))];
        $step = match ($kind) {
            'prompt' => self::prompt($step, $path, $catalog),
            'character' => $step,
            'oracle' => self::oracle($step, $path, $catalog),
            'table' => self::table($step, $path, $catalog),
            'roll' => self::roll($step, $path, $catalog),
            'choice' => self::choice($step, $path, $catalog, $setup),
            'condition' => self::condition($step, $path, $catalog),
            'pick' => self::pick($step, $path, $catalog),
            'check' => self::check($step, $path, $catalog),
            'shop' => self::shop($step, $path, $catalog, $setup),
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

        if (isset($step['tableBySceneType'])) {
            if (isset($step['branches'])) {
                throw InvalidReleaseContent::at($path.'.branches', 'not allowed with tableBySceneType, the entries differ per table');
            }

            $step['tableBySceneType'] = self::tableBySceneType($step['tableBySceneType'], $path.'.tableBySceneType', $catalog);
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
     * The tables a table step rolls per Scene Type (ADR 0042): one or more known Scene Types, each
     * naming a known table, in key order so the canonical form does not depend on the author's order.
     *
     * @return array<string, string>
     */
    private static function tableBySceneType(mixed $value, string $path, Catalog $catalog): array
    {
        if (!\is_array($value) || ([] !== $value && array_is_list($value))) {
            throw InvalidReleaseContent::at($path, 'must be an object');
        }

        if ([] === $value) {
            throw InvalidReleaseContent::at($path, 'at least 1 Scene Type, 0 given');
        }

        $tables = [];
        foreach ($value as $sceneType => $table) {
            $entryPath = $path.'.'.$sceneType;
            $sceneType = Effects::sceneType((string) $sceneType, $entryPath, $catalog);
            $table = ReleaseFields::key($table, $entryPath);
            if (!$catalog->hasTable($table)) {
                throw InvalidReleaseContent::at($entryPath, \sprintf('unknown oracle table "%s"', $table));
            }

            $tables[$sceneType] = $table;
        }

        ksort($tables);

        return $tables;
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
     * A check step names one check of the release or a set of them the player picks from (ADR 0042);
     * its difficulty is fixed by an integer, a tracker or a flow formula, and each outcome names a
     * band the checks share, or a special result every check can produce (ADR 0044), once.
     *
     * @param array<string, mixed> $step
     *
     * @return array<string, mixed>
     */
    private static function check(array $step, string $path, Catalog $catalog): array
    {
        $checks = self::stepChecks($step, $path, $catalog);
        $keys = array_keys($checks);
        // The checks of a set share their band keys, so any of them tells the bands of the step.
        $bands = $checks[$keys[0]]['bands'];
        $named = 1 === \count($keys) ? \sprintf('check "%s"', $keys[0]) : \sprintf('checks "%s"', implode('", "', $keys));

        if (isset($step['difficulty'])) {
            $difficultyPath = $path.'.difficulty';
            foreach ($checks as $checkKey => $check) {
                $range = $check['difficulty'] ?? throw InvalidReleaseContent::at($difficultyPath, \sprintf('check "%s" declares no difficulty', $checkKey));
                if (\is_int($step['difficulty'])) {
                    self::fixedDifficulty($step['difficulty'], $difficultyPath, $checkKey, $range);
                }
            }

            if (!\is_int($step['difficulty'])) {
                $step['difficulty'] = Effects::reference($step['difficulty'], $difficultyPath, $catalog);
            }
        }

        if (isset($step['outcomes'])) {
            $outcomes = [];
            foreach (ReleaseFields::sizedList($step['outcomes'], $path.'.outcomes', 0, Checks::MAX_OUTCOMES, 'outcomes') as $index => $outcome) {
                $outcomePath = \sprintf('%s.outcomes[%d]', $path, $index);
                $outcome = ReleaseFields::object($outcome, $outcomePath, self::CHECK_OUTCOME);
                // Bands and special results are keyed apart, so a band named like a special result never collides.
                $outcomeKey = isset($outcome['special']) ? 'special:'.self::special($outcome, $outcomePath, $checks) : 'band:'.self::outcomeBand($outcome, $outcomePath, $bands, $named);
                if (isset($outcomes[$outcomeKey])) {
                    [$part, $value] = explode(':', $outcomeKey, 2);
                    throw InvalidReleaseContent::at($outcomePath.'.'.$part, 'band' === $part ? \sprintf('duplicate outcome band "%s"', $value) : \sprintf('duplicate outcome special result "%s"', $value));
                }

                $outcomes[$outcomeKey] = array_filter([...$outcome, ...self::outcome($outcome, $outcomePath, $catalog, true)], static fn (mixed $field): bool => [] !== $field);
            }

            $step['outcomes'] = array_values($outcomes);
        }

        return $step;
    }

    /**
     * Whether two maps hold the same keys, in any order.
     *
     * @param array<array-key, mixed> $left
     * @param array<array-key, mixed> $right
     */
    private static function sameKeys(array $left, array $right): bool
    {
        $leftKeys = array_map(strval(...), array_keys($left));
        $rightKeys = array_map(strval(...), array_keys($right));
        sort($leftKeys);
        sort($rightKeys);

        return $leftKeys === $rightKeys;
    }

    /**
     * The band a check step outcome names: required unless it names a special result, and a band of its checks.
     *
     * @param array<string, mixed> $outcome
     * @param array<string, true>  $bands
     */
    private static function outcomeBand(array $outcome, string $path, array $bands, string $named): string
    {
        if (!isset($outcome['band'])) {
            throw InvalidReleaseContent::at($path.'.band', 'required unless the outcome names a special result');
        }

        $band = ReleaseFields::key($outcome['band'], $path.'.band');
        if (!isset($bands[$band])) {
            throw InvalidReleaseContent::at($path.'.band', \sprintf('unknown band "%s" of %s', $band, $named));
        }

        return $band;
    }

    /**
     * The special result a check step outcome names (ADR 0044), never beside a band, and one every
     * check of the step can produce: an outcome no roll reaches would be a dead branch.
     *
     * @param array<string, mixed>      $outcome
     * @param array<string, CheckShape> $checks
     */
    private static function special(array $outcome, string $path, array $checks): string
    {
        if (isset($outcome['band'])) {
            throw InvalidReleaseContent::at($path, 'name either a band or a special result, not both');
        }

        $special = ReleaseFields::oneOf($outcome['special'], $path.'.special', Checks::SPECIALS);
        foreach ($checks as $checkKey => $check) {
            if (!isset($check['specials'][$special])) {
                $needs = ['critical' => 'pool.criticals', 'messyCritical' => 'pool.criticals and pool.hunger', 'bestialFailure' => 'pool.hunger'][$special];

                throw InvalidReleaseContent::at($path.'.special', \sprintf('check "%s" never has a %s, it needs %s', $checkKey, $special, $needs));
            }
        }

        return $special;
    }

    /**
     * The checks a check step names, by key: exactly one of "check" and "checks" (2 to 10 unique
     * checks with the same band keys).
     *
     * @param array<string, mixed> $step
     *
     * @return non-empty-array<string, CheckShape>
     */
    private static function stepChecks(array $step, string $path, Catalog $catalog): array
    {
        if (isset($step['check']) && isset($step['checks'])) {
            throw InvalidReleaseContent::at($path.'.checks', 'give either check or checks, not both');
        }

        if (!isset($step['checks'])) {
            if (!isset($step['check'])) {
                throw InvalidReleaseContent::at($path.'.check', 'required, give check or checks');
            }

            $checkKey = ReleaseFields::key($step['check'], $path.'.check');

            return [$checkKey => $catalog->check($checkKey) ?? throw InvalidReleaseContent::at($path.'.check', \sprintf('unknown check "%s"', $checkKey))];
        }

        $checks = [];
        foreach (ReleaseFields::sizedList($step['checks'], $path.'.checks', self::MIN_CHECK_SET, self::MAX_CHECK_SET, 'checks') as $index => $key) {
            $checkPath = \sprintf('%s.checks[%d]', $path, $index);
            $checkKey = ReleaseFields::key($key, $checkPath);
            $check = $catalog->check($checkKey) ?? throw InvalidReleaseContent::at($checkPath, \sprintf('unknown check "%s"', $checkKey));
            if (isset($checks[$checkKey])) {
                throw InvalidReleaseContent::at($checkPath, \sprintf('duplicate check "%s"', $checkKey));
            }

            $first = array_key_first($checks);
            if (null !== $first && !self::sameKeys($check['bands'], $checks[$first]['bands'])) {
                throw InvalidReleaseContent::at($checkPath, \sprintf('check "%s" has other bands than check "%s", the checks of a set share their band keys', $checkKey, $first));
            }

            $checks[$checkKey] = $check;
        }

        // sizedList() guarantees the minimum of the set.
        \assert([] !== $checks);

        return $checks;
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
    private static function choice(array $step, string $path, Catalog $catalog, bool $setup): array
    {
        $options = [];
        $keys = [];
        $skipKey = \is_string($step['skip'] ?? null) ? $step['skip'] : null;
        foreach (ReleaseFields::sizedList($step['options'], $path.'.options', self::MIN_OPTIONS, self::MAX_OPTIONS, 'options') as $index => $option) {
            $optionPath = \sprintf('%s.options[%d]', $path, $index);
            $option = ReleaseFields::object($option, $optionPath, self::OPTION);
            $key = ReleaseFields::key($option['key'], $optionPath.'.key');
            if (isset($keys[$key])) {
                throw InvalidReleaseContent::at($optionPath.'.key', \sprintf('duplicate option key "%s"', $key));
            }

            $keys[$key] = true;
            ReleaseFields::text($option['label'], $optionPath.'.label', 1, self::MAX_TITLE_LENGTH);
            // The option may change the sheet of a Character the player picks, which a skip never has (ADR 0042).
            if ($key === $skipKey) {
                self::refuseSheetEffects($option['effects'] ?? null, $optionPath.'.effects');
            }

            $options[] = array_filter([...$option, ...self::outcome($option, $optionPath, $catalog, $key !== $skipKey && !$setup)], static fn (mixed $field): bool => [] !== $field);
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
     * Refuses a sheet effect in the effects of the option a choice's skip names: a skip completes
     * without a Character.
     */
    private static function refuseSheetEffects(mixed $effects, string $path): void
    {
        if (!\is_array($effects)) {
            return;
        }

        foreach ($effects as $index => $effect) {
            if (\is_array($effect) && 'sheet' === ($effect['kind'] ?? null)) {
                throw InvalidReleaseContent::at(\sprintf('%s[%s]', $path, $index), 'a sheet effect is not allowed on the option the skip names, a skip completes without a Character');
            }
        }
    }

    /**
     * A shop step (ADR 0045) sells the entries of its offer, catalog keys and categories resolved to
     * their union, and buys at its sell rate. It needs a Character, so never sits in a setup list, and
     * the sheet's currency; every offered entry lands in some inventory field, and an integer stock
     * draws at most the whole offer (a dice stock is clamped when rolled). Its own effects never
     * change the sheet: the transaction does (see step()).
     *
     * @param array<string, mixed> $step
     *
     * @return array<string, mixed>
     */
    private static function shop(array $step, string $path, Catalog $catalog, bool $setup): array
    {
        if ($setup) {
            throw InvalidReleaseContent::at($path, 'a shop step needs a Character, which a Scene Type\'s setup list has none of');
        }

        if (null === $catalog->currency) {
            throw InvalidReleaseContent::at($path, 'a shop step needs sheet.currency');
        }

        $offerPath = $path.'.offer';
        $offer = ReleaseFields::object($step['offer'], $offerPath, self::OFFER);
        $offered = [];
        foreach (ReleaseFields::sizedList($offer['gear'] ?? [], $offerPath.'.gear', 0, self::MAX_OFFER_GEAR, 'gear entries') as $index => $key) {
            $keyPath = \sprintf('%s.gear[%d]', $offerPath, $index);
            $key = ReleaseFields::key($key, $keyPath);
            if (!\array_key_exists($key, $catalog->gear)) {
                throw InvalidReleaseContent::at($keyPath, \sprintf('unknown gear "%s"', $key));
            }

            if (isset($offered[$key])) {
                throw InvalidReleaseContent::at($keyPath, \sprintf('duplicate gear "%s"', $key));
            }

            self::assertLands($key, $catalog, $keyPath);
            $offered[$key] = true;
        }

        $offer['categories'] = Gear::categories($offer['categories'] ?? [], $offerPath.'.categories', $catalog->gearCategories(), 0);
        foreach ($offer['categories'] as $index => $category) {
            foreach (array_keys($catalog->gear, $category, true) as $key) {
                self::assertLands($key, $catalog, \sprintf('%s.categories[%d]', $offerPath, $index));
                $offered[$key] = true;
            }
        }

        if ([] === $offered) {
            throw InvalidReleaseContent::at($offerPath, 'give gear or categories, at least one entry');
        }

        $step['offer'] = array_filter($offer, static fn (mixed $part): bool => [] !== $part);
        if (isset($step['stock'])) {
            $step['stock'] = self::stock($step['stock'], $path.'.stock', \count($offered));
        }

        if (isset($step['sellRate'])) {
            ReleaseFields::integer($step['sellRate'], $path.'.sellRate', 1, self::MAX_SELL_RATE);
        }

        return $step;
    }

    /**
     * An offered entry needs an inventory field that accepts it: a purchase must land somewhere.
     */
    private static function assertLands(string $key, Catalog $catalog, string $path): void
    {
        $category = $catalog->gear[$key];
        if (!$catalog->hasInventoryFor($category)) {
            throw InvalidReleaseContent::at($path, null === $category ? \sprintf('no inventory field accepts gear "%s", which has no category', $key) : \sprintf('no inventory field accepts gear "%s" of category "%s"', $key, $category));
        }
    }

    /**
     * How many distinct entries of the offer are on sale: an integer up to the offer's size, or dice.
     *
     * @return int|array{dice: string}
     */
    private static function stock(mixed $value, string $path, int $offered): int|array
    {
        if (\is_array($value)) {
            $dice = ReleaseFields::object($value, $path, ['dice' => true]);

            return ['dice' => self::dice($dice['dice'], $path.'.dice')];
        }

        if (!\is_int($value)) {
            throw InvalidReleaseContent::at($path, 'must be an integer or {dice: string}');
        }

        $stock = ReleaseFields::integer($value, $path, 1, self::MAX_STOCK);
        if ($stock > $offered) {
            throw InvalidReleaseContent::at($path, \sprintf('must be at most %d, the entries the offer resolves to, %d given', $offered, $stock));
        }

        return $stock;
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
