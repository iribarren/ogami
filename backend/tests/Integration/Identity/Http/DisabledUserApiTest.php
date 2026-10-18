<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity\Http;

use App\Identity\Application\CreateUser;
use App\Identity\Application\DisableUser;
use App\Identity\Application\EnableUser;
use App\Identity\Application\UserIdGenerator;
use App\Identity\Infrastructure\Security\IdentityUserChecker;
use App\Identity\Infrastructure\Security\IdentityUserProvider;
use App\Identity\Infrastructure\Security\JsonAuthenticationFailureHandler;
use App\Shared\Application\Bus\CommandBus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * A disabled user cannot sign in and loses an open session (ADR 0034).
 */
#[CoversClass(IdentityUserChecker::class)]
#[CoversClass(IdentityUserProvider::class)]
#[CoversClass(JsonAuthenticationFailureHandler::class)]
final class DisabledUserApiTest extends WebTestCase
{
    private const string PASSWORD = 'secret123';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();

        // Login throttling state lives in a cache pool that outlives a test; start clean.
        self::getContainer()->get('cache.rate_limiter')->clear();
    }

    #[Test]
    public function aDisabledUserGetsTheSameAnswerAsAWrongPassword(): void
    {
        $id = $this->createUser('ada@example.com');
        $this->dispatch(new DisableUser($this->createUser('owner@example.com'), $id));

        $this->login('ada@example.com', 'wrong-password');
        self::assertResponseStatusCodeSame(401);
        $wrongPassword = $this->client->getResponse();

        // Even with the right password: the refusal comes after the password check.
        $this->login('ada@example.com', self::PASSWORD);
        $disabled = $this->client->getResponse();

        self::assertSame(401, $disabled->getStatusCode());
        self::assertSame($wrongPassword->getContent(), $disabled->getContent());
        self::assertSame('{"error":"Invalid credentials."}', $disabled->getContent());
        self::assertSame($wrongPassword->headers->get('Content-Type'), $disabled->headers->get('Content-Type'));
        $this->client->request('GET', '/api/auth/me');
        self::assertResponseStatusCodeSame(401);
    }

    #[Test]
    public function aSessionEndsOnTheNextRequestOnceTheAccountIsDisabled(): void
    {
        $id = $this->createUser('ada@example.com');
        $ownerId = $this->createUser('owner@example.com');
        $this->login('ada@example.com', self::PASSWORD);
        $this->client->request('GET', '/api/auth/me');
        self::assertResponseIsSuccessful();

        $this->dispatch(new DisableUser($ownerId, $id));

        $this->client->request('GET', '/api/auth/me');
        self::assertResponseStatusCodeSame(401);
    }

    #[Test]
    public function aReEnabledUserCanSignInAgain(): void
    {
        $id = $this->createUser('ada@example.com');
        $ownerId = $this->createUser('owner@example.com');
        $this->dispatch(new DisableUser($ownerId, $id));
        $this->login('ada@example.com', self::PASSWORD);
        self::assertResponseStatusCodeSame(401);

        $this->dispatch(new EnableUser($ownerId, $id));
        $this->login('ada@example.com', self::PASSWORD);

        self::assertResponseStatusCodeSame(200);
        $this->client->request('GET', '/api/auth/me');
        self::assertResponseIsSuccessful();
    }

    private function createUser(string $email): string
    {
        $container = self::getContainer();
        $id = $container->get(UserIdGenerator::class)->generate()->toString();
        $this->dispatch(new CreateUser($id, $email, self::PASSWORD, ['SOLO_PLAYER']));

        return $id;
    }

    private function dispatch(DisableUser|EnableUser|CreateUser $command): void
    {
        self::getContainer()->get(CommandBus::class)->dispatch($command);
    }

    private function login(string $email, string $password): void
    {
        $this->client->jsonRequest('POST', '/api/auth/login', ['email' => $email, 'password' => $password]);
    }
}
