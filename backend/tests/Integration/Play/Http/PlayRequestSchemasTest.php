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
    /** @var array<mixed>|null the OpenAPI spec, built once per class: dumping it through the kernel takes over a second */
    private static ?array $spec = null;

    /**
     * @return iterable<string, array{string, list<string>, list<string>}>
     */
    public static function playRequestSchemas(): iterable
    {
        yield 'create campaign' => ['CreateCampaignRequest', ['name', 'gameSystemKey'], ['flowKey']];
        yield 'transfer campaign' => ['TransferCampaignRequest', ['name', 'characterIds'], ['flowKey']];
        yield 'start scene' => ['StartSceneRequest', [], ['title', 'sceneType']];
        yield 'switch Scene Type' => ['SwitchSceneTypeRequest', ['sceneType'], []];
        yield 'set tracker value' => ['SetTrackerValueRequest', ['value'], []];
        yield 'NPC' => ['NpcRequest', ['name'], ['agenda', 'tags', 'freeTags', 'weight']];
        yield 'Thread' => ['ThreadRequest', ['name'], ['tags', 'freeTags', 'weight']];
        yield 'record note' => ['RecordNoteRequest', ['text'], ['attachments']];
        yield 'record roll' => ['RecordRollRequest', ['expression'], []];
        yield 'complete FlowRun step' => ['CompleteFlowStepRequest', ['stepKey'], ['text', 'optionKey', 'likelihood', 'chaosFactor', 'pickedIds', 'characterId', 'difficulty', 'situational', 'checkKey', 'buy', 'sell']];
        yield 'edit scene cast' => ['EditSceneCastRequest', ['npcIds'], ['characterIds']];
        yield 'rename scene' => ['RenameSceneRequest', ['title'], []];
        yield 'edit session party' => ['EditSessionPartyRequest', ['characterIds'], []];
        yield 'skip FlowRun step' => ['SkipFlowStepRequest', ['stepKey'], []];
        yield 'pick Scene Type' => ['PickSceneTypeRequest', ['sceneType'], []];
        yield 'end FlowRun scene' => ['EndFlowSceneRequest', ['sceneNumber'], []];
        yield 'move on' => ['MoveOnRequest', ['phase'], []];
        yield 'record likelihood answer' => ['RecordLikelihoodAnswerRequest', ['likelihood'], ['chaosFactor', 'question']];
        yield 'perform check' => ['PerformCheckRequest', ['check', 'character'], ['difficulty', 'situational']];
    }

    /**
     * @param list<string> $required
     * @param list<string> $optional
     */
    #[Test]
    #[DataProvider('playRequestSchemas')]
    public function optionalRequestFieldsStayOptionalInTheSpec(string $schema, array $required, array $optional): void
    {
        $components = $this->spec()['components'] ?? null;
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

    /**
     * @return array<mixed>
     */
    private function spec(): array
    {
        if (null === self::$spec) {
            $client = self::createClient();
            $client->request('GET', '/api/doc.json');
            self::assertResponseIsSuccessful();
            $spec = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
            self::assertIsArray($spec);
            self::$spec = $spec;
        }

        return self::$spec;
    }
}
