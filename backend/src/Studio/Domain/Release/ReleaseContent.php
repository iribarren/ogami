<?php

declare(strict_types=1);

namespace App\Studio\Domain\Release;

use App\Randomness\Domain\Oracle\InvalidLikelihoodOracle;
use App\Randomness\Domain\Oracle\InvalidOracleTable;
use App\Randomness\Domain\Oracle\LikelihoodOracle;
use App\Randomness\Domain\Oracle\OracleTable;
use App\Randomness\Domain\Oracle\OracleTableSet;

/**
 * The validated content of a GameSystem release, schema version 1: the Published Language
 * between Studio and Play (docs/contracts/gamesystem-release.md).
 *
 * fromArray() takes decoded JSON (objects as string-keyed arrays) and enforces every rule of the
 * contract; oracle definitions are validated by Randomness. The content is kept in a canonical
 * form: keys in schema order, absent or null optionals omitted, integer-valued floats (1.0) as
 * integers, strings as given. Its hash is the sha256 of that canonical JSON, so the same content
 * hashes the same whatever its key order or number spelling.
 */
final readonly class ReleaseContent
{
    public const int SCHEMA_VERSION = 1;
    public const int MAX_GAME_SYSTEM_NAME_LENGTH = 100;
    public const int MAX_DESCRIPTION_LENGTH = 2000;
    public const int MAX_ORACLE_NAME_LENGTH = 500;
    public const int MAX_LIKELIHOOD_ORACLES = 20;
    public const int MAX_FLOW_STEPS = 100;
    public const int MAX_STEP_TITLE_LENGTH = 100;
    public const int MAX_STEP_PROMPT_LENGTH = 2000;

    private const string ROOT = '(root)';

    /** Fields of each object the contract defines, in schema order: name => required. */
    private const array RELEASE = ['schemaVersion' => true, 'gameSystem' => true, 'oracles' => true, 'flow' => true, 'sheet' => true, 'checks' => true];
    private const array GAME_SYSTEM = ['key' => true, 'name' => true, 'description' => false];
    private const array ORACLES = ['tables' => true, 'likelihood' => true];
    private const array TABLE = ['key' => true, 'name' => true, 'dice' => false, 'entries' => true];
    private const array ENTRY = ['min' => false, 'max' => false, 'weight' => false, 'text' => false, 'table' => false];
    private const array LIKELIHOOD = ['key' => true, 'name' => true, 'sides' => true, 'levels' => true, 'chaos' => false, 'exceptionalPercent' => false];
    private const array LEVEL = ['key' => true, 'label' => true, 'target' => true];
    private const array CHAOS = ['min' => true, 'max' => true, 'neutral' => true, 'shiftPerPoint' => true];
    private const array FLOW = ['steps' => true];
    private const array STEP = ['key' => true, 'title' => true, 'prompt' => false];

    /**
     * @param array<string, mixed> $content canonical content; "sheet" is kept as []
     */
    private function __construct(
        private array $content,
        private string $gameSystemKey,
        private string $gameSystemName,
        private string $hash,
    ) {
    }

    /**
     * @param array<mixed> $data decoded JSON, objects as string-keyed arrays; toArray() is accepted too
     *
     * @throws InvalidReleaseContent naming the path of the first offending value
     */
    public static function fromArray(array $data): self
    {
        $data = self::normalizeNumbers($data);
        if ([] !== $data && array_is_list($data)) {
            throw InvalidReleaseContent::at(self::ROOT, 'must be an object');
        }

        if (!\array_key_exists('schemaVersion', $data)) {
            throw InvalidReleaseContent::at('schemaVersion', 'required');
        }

        if (self::SCHEMA_VERSION !== $data['schemaVersion']) {
            throw InvalidReleaseContent::at('schemaVersion', \sprintf('unsupported schema version %s, expected %d', self::describe($data['schemaVersion']), self::SCHEMA_VERSION));
        }

        $release = self::object($data, self::ROOT, self::RELEASE);
        $gameSystem = self::gameSystem($release['gameSystem']);
        $oracles = self::oracles($release['oracles']);
        $flow = self::flow($release['flow']);

        $sheet = $release['sheet'];
        if ([] !== $sheet && (!$sheet instanceof \stdClass || [] !== get_object_vars($sheet))) {
            throw InvalidReleaseContent::at('sheet', 'not supported in schema version 1, must be {}');
        }

        if ([] !== $release['checks']) {
            throw InvalidReleaseContent::at('checks', 'not supported in schema version 1, must be []');
        }

        $content = [
            'schemaVersion' => self::SCHEMA_VERSION,
            'gameSystem' => $gameSystem,
            'oracles' => $oracles,
            'flow' => $flow,
            'sheet' => [],
            'checks' => [],
        ];
        /** @var string $key */
        $key = $gameSystem['key'];
        /** @var string $name */
        $name = $gameSystem['name'];

        return new self($content, $key, $name, hash('sha256', self::canonicalJson($content)));
    }

    public function schemaVersion(): int
    {
        return self::SCHEMA_VERSION;
    }

    public function gameSystemKey(): string
    {
        return $this->gameSystemKey;
    }

    public function gameSystemName(): string
    {
        return $this->gameSystemName;
    }

    /**
     * The canonical content. "sheet" is an empty \stdClass so it encodes to {} in JSON.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return self::withSheetObject($this->content);
    }

    /**
     * sha256 (hex) of the canonical JSON.
     */
    public function hash(): string
    {
        return $this->hash;
    }

    /**
     * @param array<string, mixed> $content
     */
    private static function canonicalJson(array $content): string
    {
        return json_encode(self::withSheetObject($content), \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $content
     *
     * @return array<string, mixed>
     */
    private static function withSheetObject(array $content): array
    {
        $content['sheet'] = new \stdClass();

        return $content;
    }

    /**
     * @return array<string, mixed>
     */
    private static function gameSystem(mixed $value): array
    {
        $gameSystem = self::object($value, 'gameSystem', self::GAME_SYSTEM);
        self::key($gameSystem['key'], 'gameSystem.key');
        self::text($gameSystem['name'], 'gameSystem.name', 1, self::MAX_GAME_SYSTEM_NAME_LENGTH);
        if (isset($gameSystem['description'])) {
            self::text($gameSystem['description'], 'gameSystem.description', 0, self::MAX_DESCRIPTION_LENGTH);
        }

        return $gameSystem;
    }

    /**
     * @return array<string, mixed>
     */
    private static function oracles(mixed $value): array
    {
        $oracles = self::object($value, 'oracles', self::ORACLES);

        $tables = [];
        foreach (self::list($oracles['tables'], 'oracles.tables') as $index => $table) {
            $path = \sprintf('oracles.tables[%d]', $index);
            $table = self::object($table, $path, self::TABLE);
            $table['entries'] = self::objects($table['entries'], $path.'.entries', self::ENTRY);
            $tables[] = $table;
        }

        $tableKeys = [];
        if ([] !== $tables) {
            try {
                $tableKeys = OracleTableSet::fromArray($tables)->keys();
            } catch (InvalidOracleTable $invalid) {
                throw InvalidReleaseContent::at('oracles.tables', $invalid->getMessage(), $invalid);
            }
        }

        $oracleKeys = array_fill_keys($tableKeys, true);
        $likelihood = self::list($oracles['likelihood'], 'oracles.likelihood');
        if (\count($likelihood) > self::MAX_LIKELIHOOD_ORACLES) {
            throw InvalidReleaseContent::at('oracles.likelihood', \sprintf('at most %d likelihood oracles, %d given', self::MAX_LIKELIHOOD_ORACLES, \count($likelihood)));
        }

        foreach ($likelihood as $index => $oracle) {
            $path = \sprintf('oracles.likelihood[%d]', $index);
            $oracle = self::object($oracle, $path, self::LIKELIHOOD);
            $key = self::key($oracle['key'], $path.'.key');
            if (isset($oracleKeys[$key])) {
                throw InvalidReleaseContent::at($path.'.key', \sprintf('duplicate oracle key "%s"', $key));
            }

            $oracleKeys[$key] = true;
            self::text($oracle['name'], $path.'.name', 1, self::MAX_ORACLE_NAME_LENGTH);
            $oracle['levels'] = self::objects($oracle['levels'], $path.'.levels', self::LEVEL);
            if (isset($oracle['chaos'])) {
                $oracle['chaos'] = self::object($oracle['chaos'], $path.'.chaos', self::CHAOS);
            }

            try {
                LikelihoodOracle::fromArray(array_diff_key($oracle, ['key' => true, 'name' => true]));
            } catch (InvalidLikelihoodOracle $invalid) {
                throw InvalidReleaseContent::at($path, $invalid->getMessage(), $invalid);
            }

            $likelihood[$index] = $oracle;
        }

        return ['tables' => $tables, 'likelihood' => $likelihood];
    }

    /**
     * @return array<string, mixed>
     */
    private static function flow(mixed $value): array
    {
        $flow = self::object($value, 'flow', self::FLOW);
        $steps = self::list($flow['steps'], 'flow.steps');
        if (\count($steps) > self::MAX_FLOW_STEPS) {
            throw InvalidReleaseContent::at('flow.steps', \sprintf('at most %d steps, %d given', self::MAX_FLOW_STEPS, \count($steps)));
        }

        $stepKeys = [];
        foreach ($steps as $index => $step) {
            $path = \sprintf('flow.steps[%d]', $index);
            $step = self::object($step, $path, self::STEP);
            $key = self::key($step['key'], $path.'.key');
            if (isset($stepKeys[$key])) {
                throw InvalidReleaseContent::at($path.'.key', \sprintf('duplicate step key "%s"', $key));
            }

            $stepKeys[$key] = true;
            self::text($step['title'], $path.'.title', 1, self::MAX_STEP_TITLE_LENGTH);
            if (isset($step['prompt'])) {
                self::text($step['prompt'], $path.'.prompt', 0, self::MAX_STEP_PROMPT_LENGTH);
            }

            $steps[$index] = $step;
        }

        return ['steps' => $steps];
    }

    /**
     * Checks a JSON object against its fields and returns it in field order, without null values.
     *
     * @param array<string, bool> $fields name => required, in schema order
     *
     * @return array<string, mixed>
     */
    private static function object(mixed $value, string $path, array $fields): array
    {
        if (!\is_array($value) || ([] !== $value && array_is_list($value))) {
            throw InvalidReleaseContent::at($path, 'must be an object');
        }

        foreach (array_keys($value) as $name) {
            if (!isset($fields[$name])) {
                throw InvalidReleaseContent::at(self::child($path, (string) $name), 'unknown property');
            }
        }

        $object = [];
        foreach ($fields as $name => $required) {
            if ($required && !\array_key_exists($name, $value)) {
                throw InvalidReleaseContent::at(self::child($path, $name), 'required');
            }

            if (null !== ($value[$name] ?? null)) {
                $object[$name] = $value[$name];
            } elseif ($required) {
                throw InvalidReleaseContent::at(self::child($path, $name), 'must not be null');
            }
        }

        return $object;
    }

    /**
     * A JSON list of objects of the same kind, each checked and ordered as object() does.
     *
     * @param array<string, bool> $fields
     *
     * @return list<array<string, mixed>>
     */
    private static function objects(mixed $value, string $path, array $fields): array
    {
        $objects = [];
        foreach (self::list($value, $path) as $index => $item) {
            $objects[] = self::object($item, \sprintf('%s[%d]', $path, $index), $fields);
        }

        return $objects;
    }

    /**
     * @return list<mixed>
     */
    private static function list(mixed $value, string $path): array
    {
        if (!\is_array($value) || !array_is_list($value)) {
            throw InvalidReleaseContent::at($path, 'must be a list');
        }

        return $value;
    }

    private static function key(mixed $value, string $path): string
    {
        if (!\is_string($value)) {
            throw InvalidReleaseContent::at($path, 'must be a string');
        }

        if (!OracleTable::isValidKey($value)) {
            throw InvalidReleaseContent::at($path, \sprintf('must be 1 to %d characters among a-z, 0-9 and "-", "%s" given', OracleTable::MAX_KEY_LENGTH, $value));
        }

        return $value;
    }

    /**
     * At most $maxLength characters, and at least $minLength once trimmed (so a required text is
     * never blank). The string itself is kept as given.
     */
    private static function text(mixed $value, string $path, int $minLength, int $maxLength): void
    {
        if (!\is_string($value)) {
            throw InvalidReleaseContent::at($path, 'must be a string');
        }

        $length = mb_strlen($value);
        if ($length > $maxLength) {
            throw InvalidReleaseContent::at($path, \sprintf('must be at most %d characters, %d given', $maxLength, $length));
        }

        if (mb_strlen(trim($value)) < $minLength) {
            throw InvalidReleaseContent::at($path, 0 === $length ? 'must not be empty' : 'must not be blank');
        }
    }

    private static function child(string $path, string $name): string
    {
        return self::ROOT === $path ? $name : $path.'.'.$name;
    }

    private static function describe(mixed $value): string
    {
        return \is_scalar($value) ? var_export($value, true) : get_debug_type($value);
    }

    /**
     * JSON has one number type: decoders turn 1.0 into a float. Integer-valued floats become
     * integers everywhere so they pass the integer rules, as they do in JSON Schema; other floats
     * stay floats and fail them.
     *
     * @param array<mixed> $data
     *
     * @return array<mixed>
     */
    private static function normalizeNumbers(array $data): array
    {
        foreach ($data as $key => $value) {
            if (\is_array($value)) {
                $data[$key] = self::normalizeNumbers($value);
            } elseif (\is_float($value) && is_finite($value) && floor($value) === $value && abs($value) <= 2 ** 53) {
                $data[$key] = (int) $value;
            }
        }

        return $data;
    }
}
