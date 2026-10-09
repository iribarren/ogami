<?php

declare(strict_types=1);

namespace App\Tests\Unit\Studio\Contract;

use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Checks the schema version 2 file on its own: a few shape errors in the contract doc example are
 * rejected. ReleaseSchemaAgreementTest runs the example itself through the schema and the domain.
 */
#[CoversNothing]
final class ReleaseSchemaVersion2Test extends TestCase
{
    private const string SCHEMA_ID = 'https://ogami.app/contracts/gamesystem-release/v2.schema.json';
    private const string SCHEMA_FILE = __DIR__.'/../../../../contracts/gamesystem-release/v2.schema.json';
    private const string EXAMPLE = __DIR__.'/../../../Fixtures/Studio/releases/valid/v2-contract-doc-example.json';

    /**
     * @return iterable<string, array{\Closure(\stdClass): void}>
     */
    public static function shapeErrors(): iterable
    {
        yield 'schema version 1 flow' => [static function (\stdClass $release): void {
            $release->flow = (object) ['steps' => []];
        }];
        yield 'reserved step key' => [static function (\stdClass $release): void {
            self::playStep($release)->key = 'end';
        }];
        yield 'unknown effect kind' => [static function (\stdClass $release): void {
            self::playStep($release)->effects = [(object) ['kind' => 'createNpc']];
        }];
        yield 'missing flows' => [static function (\stdClass $release): void {
            unset($release->flows);
        }];
    }

    /**
     * @param \Closure(\stdClass): void $mutate
     */
    #[Test]
    #[DataProvider('shapeErrors')]
    public function shapeErrorsAreRejected(\Closure $mutate): void
    {
        $release = $this->example();
        $mutate($release);

        self::assertFalse($this->conforms($release));
    }

    private function conforms(\stdClass $release): bool
    {
        $validator = new Validator();
        $validator->resolver()?->registerFile(self::SCHEMA_ID, self::SCHEMA_FILE);

        return $validator->validate($release, self::SCHEMA_ID)->isValid();
    }

    /**
     * The first play step of the example's "infiltration" Scene Type.
     */
    private static function playStep(\stdClass $release): \stdClass
    {
        self::assertIsArray($release->sceneTypes);
        $sceneType = $release->sceneTypes[1];
        self::assertInstanceOf(\stdClass::class, $sceneType);
        self::assertIsArray($sceneType->play);
        $step = $sceneType->play[0];
        self::assertInstanceOf(\stdClass::class, $step);

        return $step;
    }

    private function example(): \stdClass
    {
        $release = json_decode((string) file_get_contents(self::EXAMPLE), flags: \JSON_THROW_ON_ERROR);
        self::assertInstanceOf(\stdClass::class, $release);

        return $release;
    }
}
