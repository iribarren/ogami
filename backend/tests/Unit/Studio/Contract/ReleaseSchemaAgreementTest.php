<?php

declare(strict_types=1);

namespace App\Tests\Unit\Studio\Contract;

use App\Studio\Domain\Release\InvalidReleaseContent;
use App\Studio\Domain\Release\ReleaseContent;
use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Proves the JSON Schema file and the Studio domain agree on the same cases: valid files pass
 * both, structural invalid cases fail both, and semantic invalid cases pass the schema (it cannot
 * express them) but fail the domain. Every invalid case names why it fails: the domain message
 * prefix (the path) and, for structural cases, the schema error location (a JSON pointer).
 *
 * JSON Schema reports "required" and "additionalProperties" on the object that holds the
 * keyword, so for a missing or unknown property the schema location is the parent object while
 * the domain path names the property itself.
 */
#[CoversNothing]
final class ReleaseSchemaAgreementTest extends TestCase
{
    private const string SCHEMA_ID = 'https://ogami.app/contracts/gamesystem-release/v1.schema.json';
    private const string SCHEMA_FILE = __DIR__.'/../../../../contracts/gamesystem-release/v1.schema.json';
    private const string FIXTURES = __DIR__.'/../../../Fixtures/Studio/releases';

    /**
     * Structural cases: name => [domain message prefix, schema error location].
     */
    private const array STRUCTURAL = [
        'bad-game-system-key' => ['gameSystem.key: ', '/gameSystem/key'],
        'bad-likelihood-key' => ['oracles.likelihood[0].key: ', '/oracles/likelihood/0/key'],
        'bad-step-key' => ['flow.steps[0].key: ', '/flow/steps/0/key'],
        'bad-table-key' => ['oracles.tables: ', '/oracles/tables/0/key'],
        'chaos-min-below-bound' => ['oracles.likelihood[0]: ', '/oracles/likelihood/0/chaos/min'],
        'description-not-a-string' => ['gameSystem.description: must be a string', '/gameSystem/description'],
        'empty-game-system-name' => ['gameSystem.name: must not be empty', '/gameSystem/name'],
        'empty-levels' => ['oracles.likelihood[0]: ', '/oracles/likelihood/0/levels'],
        'empty-table-entries' => ['oracles.tables: ', '/oracles/tables/0/entries'],
        'entry-min-fractional' => ['oracles.tables: ', '/oracles/tables/0/entries/0/min'],
        'exceptional-percent-too-high' => ['oracles.likelihood[0]: ', '/oracles/likelihood/0/exceptionalPercent'],
        'game-system-name-not-a-string' => ['gameSystem.name: must be a string', '/gameSystem/name'],
        'game-system-not-an-object' => ['gameSystem: must be an object', '/gameSystem'],
        'level-label-empty' => ['oracles.likelihood[0]: ', '/oracles/likelihood/0/levels/0/label'],
        'likelihood-not-a-list' => ['oracles.likelihood: must be a list', '/oracles/likelihood'],
        'missing-flow-steps' => ['flow.steps: required', '/flow'],
        'missing-game-system-name' => ['gameSystem.name: required', '/gameSystem'],
        'missing-likelihood-name' => ['oracles.likelihood[0].name: required', '/oracles/likelihood/0'],
        'missing-oracles-likelihood' => ['oracles.likelihood: required', '/oracles'],
        'missing-schema-version' => ['schemaVersion: required', '/'],
        'missing-sheet' => ['sheet: required', '/'],
        'missing-step-title' => ['flow.steps[0].title: required', '/flow/steps/0'],
        'missing-table-entries' => ['oracles.tables[0].entries: required', '/oracles/tables/0'],
        'negative-level-target' => ['oracles.likelihood[0]: ', '/oracles/likelihood/0/levels/0/target'],
        'nested-table-key-bad-pattern' => ['oracles.tables: ', '/oracles/tables/0/entries/0/table'],
        'non-empty-checks' => ['checks: not supported in schema version 1', '/checks'],
        'non-empty-sheet' => ['sheet: not supported in schema version 1', '/sheet'],
        'schema-version-string' => ['schemaVersion: unsupported schema version', '/schemaVersion'],
        'sides-fractional' => ['oracles.likelihood[0]: ', '/oracles/likelihood/0/sides'],
        'sides-not-an-integer' => ['oracles.likelihood[0]: ', '/oracles/likelihood/0/sides'],
        'sides-out-of-range' => ['oracles.likelihood[0]: ', '/oracles/likelihood/0/sides'],
        'step-not-an-object' => ['flow.steps[0]: must be an object', '/flow/steps/0'],
        'steps-not-a-list' => ['flow.steps: must be a list', '/flow/steps'],
        'table-name-empty' => ['oracles.tables: ', '/oracles/tables/0/name'],
        'tables-not-a-list' => ['oracles.tables: must be a list', '/oracles/tables'],
        'top-level-a-list' => ['(root): must be an object', '/'],
        'unknown-chaos-property' => ['oracles.likelihood[0].chaos.step: unknown property', '/oracles/likelihood/0/chaos'],
        'unknown-entry-property' => ['oracles.tables[0].entries[0].note: unknown property', '/oracles/tables/0/entries/0'],
        'unknown-flow-property' => ['flow.start: unknown property', '/flow'],
        'unknown-game-system-property' => ['gameSystem.author: unknown property', '/gameSystem'],
        'unknown-level-property' => ['oracles.likelihood[0].levels[0].color: unknown property', '/oracles/likelihood/0/levels/0'],
        'unknown-likelihood-property' => ['oracles.likelihood[0].mode: unknown property', '/oracles/likelihood/0'],
        'unknown-oracles-property' => ['oracles.dice: unknown property', '/oracles'],
        'unknown-step-property' => ['flow.steps[0].oracle: unknown property', '/flow/steps/0'],
        'unknown-table-property' => ['oracles.tables[0].color: unknown property', '/oracles/tables/0'],
        'unknown-top-level-property' => ['extra: unknown property', '/'],
        'unsupported-schema-version' => ['schemaVersion: unsupported schema version', '/schemaVersion'],
        'weight-zero' => ['oracles.tables: ', '/oracles/tables/0/entries/0/weight'],
        'generated: too-many-tables' => ['oracles.tables: ', '/oracles/tables'],
        'generated: too-many-likelihood-oracles' => ['oracles.likelihood: at most 20 likelihood oracles', '/oracles/likelihood'],
        'generated: too-many-flow-steps' => ['flow.steps: at most 100 steps', '/flow/steps'],
        'generated: too-long-game-system-key' => ['gameSystem.key: ', '/gameSystem/key'],
        'generated: too-long-game-system-name' => ['gameSystem.name: must be at most 100 characters', '/gameSystem/name'],
        'generated: too-long-description' => ['gameSystem.description: must be at most 2000 characters', '/gameSystem/description'],
        'generated: too-long-likelihood-name' => ['oracles.likelihood[0].name: must be at most 500 characters', '/oracles/likelihood/0/name'],
        'generated: too-long-step-title' => ['flow.steps[0].title: must be at most 100 characters', '/flow/steps/0/title'],
        'generated: too-long-step-prompt' => ['flow.steps[0].prompt: must be at most 2000 characters', '/flow/steps/0/prompt'],
        'generated: entry-text-too-long' => ['oracles.tables: ', '/oracles/tables/0/entries/0/text'],
    ];

    /**
     * Semantic cases: name => domain message prefix (the schema accepts them).
     */
    private const array SEMANTIC = [
        'chaos-not-ordered' => 'oracles.likelihood[0]: ',
        'duplicate-flow-step-key' => 'flow.steps[1].key: duplicate step key "begin"',
        'duplicate-key-across-tables-and-likelihood' => 'oracles.likelihood[0].key: duplicate oracle key "mood"',
        'duplicate-level-key' => 'oracles.likelihood[0]: ',
        'duplicate-likelihood-key' => 'oracles.likelihood[1].key: duplicate oracle key "fate"',
        'duplicate-table-key' => 'oracles.tables: ',
        'entry-without-text' => 'oracles.tables: ',
        'incomplete-range' => 'oracles.tables: ',
        'invalid-dice' => 'oracles.tables: ',
        'inverse-range' => 'oracles.tables: ',
        'level-target-above-sides' => 'oracles.likelihood[0]: ',
        'nested-cycle' => 'oracles.tables: ',
        'overlapping-ranges' => 'oracles.tables: ',
        'range-and-weight' => 'oracles.tables: ',
        'ranged-entry-in-weighted-table' => 'oracles.tables: ',
        'shift-per-point-above-sides' => 'oracles.likelihood[0]: ',
        'unknown-nested-table' => 'oracles.tables: ',
        'whitespace-only-game-system-name' => 'gameSystem.name: must not be blank',
        'whitespace-only-likelihood-name' => 'oracles.likelihood[0].name: must not be blank',
        'whitespace-only-step-title' => 'flow.steps[0].title: must not be blank',
    ];

    /**
     * @return iterable<string, array{string}>
     */
    public static function validCases(): iterable
    {
        return self::fixtures('valid');
    }

    /**
     * @return iterable<string, array{string, string}> name => [name, JSON]
     */
    public static function structurallyInvalidCases(): iterable
    {
        foreach ([...self::fixtures('invalid/structural'), ...self::generatedStructuralCases()] as $name => [$json]) {
            yield $name => [$name, $json];
        }
    }

    /**
     * @return iterable<string, array{string, string}> name => [name, JSON]
     */
    public static function semanticallyInvalidCases(): iterable
    {
        foreach (self::fixtures('invalid/semantic') as $name => [$json]) {
            yield $name => [$name, $json];
        }
    }

    #[Test]
    #[DataProvider('validCases')]
    public function validCasesPassTheSchemaAndTheDomain(string $json): void
    {
        self::assertSame([], $this->schemaErrorLocations($json));

        $content = ReleaseContent::fromArray($this->decodeRelease($json));

        self::assertSame([], $this->schemaErrorLocations(json_encode($content->toArray(), \JSON_THROW_ON_ERROR)), 'The canonical form conforms to the schema.');
    }

    #[Test]
    #[DataProvider('structurallyInvalidCases')]
    public function structuralInvalidCasesFailTheSchemaAndTheDomainForTheExpectedReason(string $name, string $json): void
    {
        self::assertArrayHasKey($name, self::STRUCTURAL, \sprintf('Add the expected reason of "%s" to STRUCTURAL.', $name));
        [$domainPrefix, $schemaLocation] = self::STRUCTURAL[$name];

        self::assertContains($schemaLocation, $this->schemaErrorLocations($json), 'The schema rejects it at the expected location.');
        self::assertStringStartsWith($domainPrefix, $this->domainError($json));
    }

    #[Test]
    #[DataProvider('semanticallyInvalidCases')]
    public function semanticInvalidCasesPassTheSchemaButFailTheDomainForTheExpectedReason(string $name, string $json): void
    {
        self::assertArrayHasKey($name, self::SEMANTIC, \sprintf('Add the expected reason of "%s" to SEMANTIC.', $name));

        self::assertSame([], $this->schemaErrorLocations($json), 'The schema cannot express this rule.');
        self::assertStringStartsWith(self::SEMANTIC[$name], $this->domainError($json));
    }

    #[Test]
    public function everyExpectedReasonHasACase(): void
    {
        self::assertSame([], array_values(array_diff(array_keys(self::STRUCTURAL), array_keys([...self::structurallyInvalidCases()]))));
        self::assertSame([], array_values(array_diff(array_keys(self::SEMANTIC), array_keys([...self::semanticallyInvalidCases()]))));
    }

    /**
     * Cases that only exceed a size limit, built in code to keep the fixtures small.
     *
     * @return array<string, array{string}>
     */
    private static function generatedStructuralCases(): array
    {
        $table = static fn (int $i): array => ['key' => 't-'.$i, 'name' => 'Table', 'entries' => [['text' => 'x']]];
        $likelihood = static fn (int $i): array => ['key' => 'l-'.$i, 'name' => 'Fate', 'sides' => 6, 'levels' => [['key' => 'even', 'label' => 'Even', 'target' => 3]]];
        $step = static fn (int $i): array => ['key' => 's-'.$i, 'title' => 'Step'];

        $generated = [
            'too-many-tables' => ['oracles' => ['tables' => array_map($table, range(1, 51)), 'likelihood' => []]],
            'too-many-likelihood-oracles' => ['oracles' => ['tables' => [], 'likelihood' => array_map($likelihood, range(1, 21))]],
            'too-many-flow-steps' => ['flow' => ['steps' => array_map($step, range(1, 101))]],
            'too-long-game-system-key' => ['gameSystem' => ['key' => str_repeat('a', 65), 'name' => 'Minimal']],
            'too-long-game-system-name' => ['gameSystem' => ['key' => 'minimal', 'name' => str_repeat('n', 101)]],
            'too-long-description' => ['gameSystem' => ['key' => 'minimal', 'name' => 'Minimal', 'description' => str_repeat('d', 2001)]],
            'too-long-likelihood-name' => ['oracles' => ['tables' => [], 'likelihood' => [['name' => str_repeat('n', 501)] + $likelihood(1)]]],
            'too-long-step-title' => ['flow' => ['steps' => [['key' => 'begin', 'title' => str_repeat('t', 101)]]]],
            'too-long-step-prompt' => ['flow' => ['steps' => [['key' => 'begin', 'title' => 'Begin', 'prompt' => str_repeat('p', 2001)]]]],
            'entry-text-too-long' => ['oracles' => ['tables' => [['key' => 'mood', 'name' => 'Mood', 'entries' => [['text' => str_repeat('t', 501)]]]], 'likelihood' => []]],
        ];

        $cases = [];
        foreach ($generated as $name => $overrides) {
            $cases['generated: '.$name] = [json_encode([...self::minimalRelease(), ...$overrides], \JSON_THROW_ON_ERROR)];
        }

        return $cases;
    }

    /**
     * @return array<string, mixed>
     */
    private static function minimalRelease(): array
    {
        return [
            'schemaVersion' => 1,
            'gameSystem' => ['key' => 'minimal', 'name' => 'Minimal'],
            'oracles' => ['tables' => [], 'likelihood' => []],
            'flow' => ['steps' => []],
            'sheet' => new \stdClass(),
            'checks' => [],
        ];
    }

    /**
     * @return array<string, array{string}> fixture contents by file name
     */
    private static function fixtures(string $directory): array
    {
        $files = glob(self::FIXTURES.'/'.$directory.'/*.json') ?: [];
        self::assertNotSame([], $files, 'No fixtures in '.$directory);

        $fixtures = [];
        foreach ($files as $file) {
            $json = file_get_contents($file);
            self::assertIsString($json, 'Cannot read '.$file);
            $fixtures[basename($file, '.json')] = [$json];
        }

        return $fixtures;
    }

    /**
     * @return array<mixed>
     */
    private function decodeRelease(string $json): array
    {
        $data = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($data, 'Not a JSON object or list.');

        return $data;
    }

    private function domainError(string $json): string
    {
        try {
            ReleaseContent::fromArray($this->decodeRelease($json));
        } catch (InvalidReleaseContent $invalidReleaseContent) {
            return $invalidReleaseContent->getMessage();
        }

        self::fail('The domain accepts it.');
    }

    /**
     * @return list<string> the JSON pointers of the innermost schema errors, empty when valid
     */
    private function schemaErrorLocations(string $json): array
    {
        $validator = new Validator();
        $validator->setMaxErrors(5);
        $validator->resolver()?->registerFile(self::SCHEMA_ID, self::SCHEMA_FILE);

        $error = $validator->validate(json_decode($json, flags: \JSON_THROW_ON_ERROR), self::SCHEMA_ID)->error();

        return $error instanceof ValidationError ? array_values(array_unique(self::leafLocations($error))) : [];
    }

    /**
     * @return list<string>
     */
    private static function leafLocations(ValidationError $error): array
    {
        $locations = [];
        foreach ($error->subErrors() as $subError) {
            if ($subError instanceof ValidationError) {
                $locations = [...$locations, ...self::leafLocations($subError)];
            }
        }

        return [] === $locations ? ['/'.implode('/', $error->data()->fullPath())] : $locations;
    }
}
