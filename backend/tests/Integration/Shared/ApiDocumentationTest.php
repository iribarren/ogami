<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The SPA's typed client is generated from this spec (ADR 0005), so the spec
 * must describe every endpoint the SPA calls.
 */
#[CoversNothing]
final class ApiDocumentationTest extends WebTestCase
{
    #[Test]
    public function itPublishesAnOpenApiSpecDescribingTheHealthEndpoint(): void
    {
        $client = self::createClient();

        $client->request('GET', '/api/doc.json');

        self::assertResponseIsSuccessful();
        $spec = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($spec);
        self::assertStringStartsWith('3.', $this->stringAt($spec, 'openapi'));
        self::assertSame('getHealth', $this->stringAt($spec, 'paths', '/api/health', 'get', 'operationId'));
        self::assertSame(
            '#/components/schemas/HealthResponse',
            $this->stringAt($spec, 'paths', '/api/health', 'get', 'responses', '200', 'content', 'application/json', 'schema', '$ref'),
        );
        self::assertSame(['ok', 'down'], $this->valueAt($spec, 'components', 'schemas', 'HealthResponse', 'properties', 'database', 'enum'));
        $paths = $this->valueAt($spec, 'paths');
        self::assertIsArray($paths);
        self::assertArrayNotHasKey('/api/doc.json', $paths);
    }

    /**
     * @param array<mixed> $data
     */
    private function valueAt(array $data, string ...$path): mixed
    {
        $value = $data;
        foreach ($path as $key) {
            self::assertIsArray($value, \sprintf('Expected an object before "%s"', $key));
            self::assertArrayHasKey($key, $value);
            $value = $value[$key];
        }

        return $value;
    }

    /**
     * @param array<mixed> $data
     */
    private function stringAt(array $data, string ...$path): string
    {
        $value = $this->valueAt($data, ...$path);
        self::assertIsString($value);

        return $value;
    }
}
