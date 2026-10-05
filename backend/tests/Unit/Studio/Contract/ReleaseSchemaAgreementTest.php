<?php

declare(strict_types=1);

namespace App\Tests\Unit\Studio\Contract;

use App\Studio\Domain\Release\InvalidReleaseContent;
use App\Studio\Domain\Release\ReleaseContent;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Proves the JSON Schema file and the Studio domain agree on the same fixtures:
 * valid files pass both, structural invalid files fail both, and semantic invalid
 * files pass the schema (it cannot express them) but fail the domain.
 */
#[CoversNothing]
final class ReleaseSchemaAgreementTest extends TestCase
{
    private const string SCHEMA_ID = 'https://ogami.app/contracts/gamesystem-release/v1.schema.json';
    private const string SCHEMA_FILE = __DIR__.'/../../../../contracts/gamesystem-release/v1.schema.json';
    private const string FIXTURES = __DIR__.'/../../../Fixtures/Studio/releases';

    /**
     * @return iterable<string, array{string}>
     */
    public static function validCases(): iterable
    {
        return self::fixtures('valid');
    }

    /**
     * Fixture files, plus cases generated in code that only exceed a size limit.
     *
     * @return iterable<string, array{string}>
     */
    public static function structurallyInvalidCases(): iterable
    {
        yield from self::fixtures('invalid/structural');

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

        foreach ($generated as $name => $overrides) {
            yield 'generated: '.$name => [json_encode([...self::minimalRelease(), ...$overrides], \JSON_THROW_ON_ERROR)];
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function semanticallyInvalidCases(): iterable
    {
        return self::fixtures('invalid/semantic');
    }

    #[Test]
    #[DataProvider('validCases')]
    public function validCasesPassTheSchemaAndTheDomain(string $json): void
    {
        self::assertSame([], $this->schemaErrors($json));

        $content = ReleaseContent::fromArray($this->decode($json));

        self::assertSame([], $this->schemaErrors(json_encode($content->toArray(), \JSON_THROW_ON_ERROR)), 'The canonical form conforms to the schema.');
    }

    #[Test]
    #[DataProvider('structurallyInvalidCases')]
    public function structuralInvalidCasesFailTheSchemaAndTheDomain(string $json): void
    {
        self::assertNotSame([], $this->schemaErrors($json), 'The schema rejects it.');

        $this->expectException(InvalidReleaseContent::class);

        ReleaseContent::fromArray($this->decode($json));
    }

    #[Test]
    #[DataProvider('semanticallyInvalidCases')]
    public function semanticInvalidCasesPassTheSchemaButFailTheDomain(string $json): void
    {
        self::assertSame([], $this->schemaErrors($json), 'The schema cannot express this rule.');

        $this->expectException(InvalidReleaseContent::class);

        ReleaseContent::fromArray($this->decode($json));
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
     * @return iterable<string, array{string}> fixture contents by file name
     */
    private static function fixtures(string $directory): iterable
    {
        $files = glob(self::FIXTURES.'/'.$directory.'/*.json') ?: [];
        self::assertNotSame([], $files, 'No fixtures in '.$directory);

        foreach ($files as $file) {
            $json = file_get_contents($file);
            self::assertIsString($json, 'Cannot read '.$file);

            yield basename($file, '.json') => [$json];
        }
    }

    /**
     * @return array<mixed>
     */
    private function decode(string $json): array
    {
        $data = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($data, 'Not a JSON object or list.');

        return $data;
    }

    /**
     * @return array<string, mixed> the schema errors by data path, empty when valid
     */
    private function schemaErrors(string $json): array
    {
        $validator = new Validator();
        $validator->setMaxErrors(5);
        $validator->resolver()?->registerFile(self::SCHEMA_ID, self::SCHEMA_FILE);

        $result = $validator->validate(json_decode($json, flags: \JSON_THROW_ON_ERROR), self::SCHEMA_ID);
        $error = $result->error();

        return $error instanceof \Opis\JsonSchema\Errors\ValidationError ? ['/'.implode('/', $error->data()->fullPath()) => $error->message()] : [];
    }
}
