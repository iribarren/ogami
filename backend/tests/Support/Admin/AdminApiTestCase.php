<?php

declare(strict_types=1);

namespace App\Tests\Support\Admin;

use App\Tests\Support\Identity\PublicAccountApiTestCase;

/**
 * What the API tests of the Admin endpoints share: the public account test case (recording mail
 * transport, log recorder, clean rate limiter) plus signing in and sending JSON as the session.
 */
abstract class AdminApiTestCase extends PublicAccountApiTestCase
{
    protected const string UNKNOWN_ID = '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b';

    /**
     * Creates a user and signs them in.
     *
     * @param list<string> $roles
     *
     * @return string the user's id
     */
    protected function signInAs(array $roles = ['OWNER'], string $email = 'owner@example.com'): string
    {
        $id = $this->createUser($email, $roles);
        $this->login($email, self::PASSWORD);
        self::assertResponseStatusCodeSame(200);

        return $id;
    }

    /**
     * @param array<mixed>|null $body
     */
    protected function send(string $method, string $path, ?array $body = null): void
    {
        if (null === $body) {
            $this->client->request($method, $path);

            return;
        }

        $this->client->jsonRequest($method, $path, $body);
    }

    /**
     * @return array<mixed>
     */
    protected function json(): array
    {
        $decoded = json_decode($this->content(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    protected function field(string $key): string
    {
        $value = $this->json()[$key] ?? null;
        self::assertIsString($value, $key);

        return $value;
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function users(): array
    {
        $this->send('GET', '/api/admin/users');
        self::assertResponseStatusCodeSame(200);

        return $this->rows();
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function invitations(): array
    {
        $this->send('GET', '/api/admin/invitations');
        self::assertResponseStatusCodeSame(200);

        return $this->rows();
    }

    /**
     * @param list<string> $values
     *
     * @return list<string>
     */
    protected function sorted(array $values): array
    {
        sort($values);

        return $values;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(): array
    {
        $rows = [];
        foreach ($this->json() as $row) {
            self::assertIsArray($row);
            $rows[] = array_combine(array_map(strval(...), array_keys($row)), $row);
        }

        return $rows;
    }
}
