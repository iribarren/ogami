<?php

declare(strict_types=1);

namespace App\Studio\Domain\Release;

use App\Studio\Domain\Release\Version2\AuthoringWarnings;
use App\Studio\Domain\Release\Version2\ReleaseVersion2;

/**
 * The validated content of a GameSystem release, schema version 1 or 2: the Published Language
 * between Studio and Play (docs/contracts/gamesystem-release.md).
 *
 * fromArray() takes decoded JSON (objects as string-keyed arrays) and enforces every rule of the
 * contract of its schema version; oracle definitions are validated by Randomness, and the parts
 * schema version 2 adds by Version2\ReleaseVersion2. The content is kept in a canonical form: keys
 * in schema order, absent or null optionals omitted (in version 2 also empty optional lists),
 * integer-valued floats (1.0) as integers, strings as given. Its hash is the sha256 of that
 * canonical JSON, so the same content hashes the same whatever its key order or number spelling.
 */
final readonly class ReleaseContent
{
    /** The schema versions Studio validates and publishes; version 1 releases stay valid. */
    public const array SUPPORTED_SCHEMA_VERSIONS = [1, 2];
    public const int MAX_GAME_SYSTEM_NAME_LENGTH = 100;
    public const int MAX_DESCRIPTION_LENGTH = 2000;
    public const int MAX_ORACLE_NAME_LENGTH = ReleaseOracles::MAX_ORACLE_NAME_LENGTH;
    public const int MAX_LIKELIHOOD_ORACLES = ReleaseOracles::MAX_LIKELIHOOD_ORACLES;
    public const int MAX_FLOW_STEPS = 100;
    public const int MAX_STEP_TITLE_LENGTH = 100;
    public const int MAX_STEP_PROMPT_LENGTH = 2000;

    private const string ROOT = ReleaseFields::ROOT;

    /** Fields of each object the contract defines, in schema order: name => required. */
    private const array RELEASE = ['schemaVersion' => true, 'gameSystem' => true, 'oracles' => true, 'flow' => true, 'sheet' => true, 'checks' => true];
    private const array GAME_SYSTEM = ['key' => true, 'name' => true, 'description' => false];
    private const array FLOW = ['steps' => true];
    private const array STEP = ['key' => true, 'title' => true, 'prompt' => false];

    /**
     * @param array<string, mixed> $content  canonical content; "sheet" is kept as []
     * @param list<string>         $warnings authoring warnings, each starting with its path
     */
    private function __construct(
        private array $content,
        private int $schemaVersion,
        private string $gameSystemKey,
        private string $gameSystemName,
        private string $hash,
        private array $warnings,
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

        $version = $data['schemaVersion'];
        if (!\in_array($version, self::SUPPORTED_SCHEMA_VERSIONS, true)) {
            throw InvalidReleaseContent::at('schemaVersion', \sprintf('unsupported schema version %s, expected 1 or 2', self::describe($version)));
        }

        /** @var 1|2 $version */
        $release = self::object($data, self::ROOT, 1 === $version ? self::RELEASE : ReleaseVersion2::RELEASE);
        $gameSystem = self::gameSystem($release['gameSystem']);
        $parts = 1 === $version
            ? ['oracles' => ReleaseOracles::validate($release['oracles']), 'flow' => self::flow($release['flow'])]
            : ReleaseVersion2::validate($release);

        $sheet = $release['sheet'];
        if ([] !== $sheet && (!$sheet instanceof \stdClass || [] !== get_object_vars($sheet))) {
            throw InvalidReleaseContent::at('sheet', \sprintf('not supported in schema version %d, must be {}', $version));
        }

        if ([] !== $release['checks']) {
            throw InvalidReleaseContent::at('checks', \sprintf('not supported in schema version %d, must be []', $version));
        }

        $content = [
            'schemaVersion' => $version,
            'gameSystem' => $gameSystem,
            ...$parts,
            'sheet' => [],
            'checks' => [],
        ];
        /** @var string $key */
        $key = $gameSystem['key'];
        /** @var string $name */
        $name = $gameSystem['name'];

        /** @var list<array<string, mixed>> $sceneTypes */
        $sceneTypes = $content['sceneTypes'] ?? [];
        /** @var list<array<string, mixed>> $flows */
        $flows = $content['flows'] ?? [];
        $warnings = 2 === $version ? AuthoringWarnings::of($sceneTypes, $flows) : [];

        return new self($content, $version, $key, $name, hash('sha256', self::canonicalJson($content)), $warnings);
    }

    public function schemaVersion(): int
    {
        return $this->schemaVersion;
    }

    /**
     * Authoring warnings of this valid content (docs/contracts/gamesystem-release.md, "Authoring
     * warning"): publishing succeeds, the author is told. Computed from the content, never stored,
     * and not part of the hash.
     *
     * @return list<string> each starting with the path it is about
     */
    public function warnings(): array
    {
        return $this->warnings;
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
    private static function flow(mixed $value): array
    {
        $flow = self::object($value, 'flow', self::FLOW);
        $steps = ReleaseFields::list($flow['steps'], 'flow.steps');
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
     * @param array<string, bool> $fields
     *
     * @return array<string, mixed>
     */
    private static function object(mixed $value, string $path, array $fields): array
    {
        return ReleaseFields::object($value, $path, $fields);
    }

    private static function key(mixed $value, string $path): string
    {
        return ReleaseFields::key($value, $path);
    }

    private static function text(mixed $value, string $path, int $minLength, int $maxLength): void
    {
        ReleaseFields::text($value, $path, $minLength, $maxLength);
    }

    private static function describe(mixed $value): string
    {
        return ReleaseFields::describe($value);
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
