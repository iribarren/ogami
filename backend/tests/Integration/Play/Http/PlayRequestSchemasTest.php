<?php

declare(strict_types=1);

namespace App\Tests\Integration\Play\Http;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The SPA's typed client turns a property with a `default` into a required field, so an
 * optional request field must not document one (ADR 0005).
 */
#[CoversNothing]
final class PlayRequestSchemasTest extends WebTestCase
{
    /**
     * @return iterable<string, array{string, list<string>, list<string>}>
     */
    public static function playRequestSchemas(): iterable
    {
        yield 'create campaign' => ['CreateCampaignRequest', ['name', 'gameSystemKey'], []];
        yield 'start scene' => ['StartSceneRequest', ['title'], []];
        yield 'record note' => ['RecordNoteRequest', ['text'], []];
        yield 'record roll' => ['RecordRollRequest', ['expression'], []];
        yield 'record likelihood answer' => ['RecordLikelihoodAnswerRequest', ['likelihood'], ['chaosFactor', 'question']];
    }

    /**
     * @param list<string> $required
     * @param list<string> $optional
     */
    #[Test]
    #[DataProvider('playRequestSchemas')]
    public function optionalRequestFieldsStayOptionalInTheSpec(string $schema, array $required, array $optional): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/doc.json');
        self::assertResponseIsSuccessful();
        $spec = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($spec);

        $components = $spec['components'] ?? null;
        self::assertIsArray($components);
        $schemas = $components['schemas'] ?? null;
        self::assertIsArray($schemas);
        $definition = $schemas[$schema] ?? null;
        self::assertIsArray($definition, \sprintf('The spec has no "%s" schema.', $schema));
        self::assertSame($required, $definition['required'] ?? []);
        $properties = $definition['properties'] ?? null;
        self::assertIsArray($properties);
        self::assertSame([...$required, ...$optional], array_keys($properties));
        foreach ($optional as $name) {
            $property = $properties[$name];
            self::assertIsArray($property);
            self::assertArrayNotHasKey('default', $property, \sprintf('"%s.%s" documents a default, so the typed client makes it required.', $schema, $name));
        }
    }
}
