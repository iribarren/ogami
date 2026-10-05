<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity\Http;

use App\Identity\Application\CreateUser;
use App\Identity\Application\UserIdGenerator;
use App\Identity\Infrastructure\Http\AuthController;
use App\Identity\Infrastructure\Security\IdentityUserProvider;
use App\Identity\Infrastructure\Security\JsonAuthenticationFailureHandler;
use App\Identity\Infrastructure\Security\JsonAuthenticationSuccessHandler;
use App\Identity\Infrastructure\Security\JsonLogoutListener;
use App\Shared\Application\Bus\CommandBus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;

/**
 * Session cookie authentication over the JSON API (ADR 0006).
 */
#[CoversClass(AuthController::class)]
#[CoversClass(IdentityUserProvider::class)]
#[CoversClass(JsonAuthenticationSuccessHandler::class)]
#[CoversClass(JsonAuthenticationFailureHandler::class)]
#[CoversClass(JsonLogoutListener::class)]
final class AuthApiTest extends WebTestCase
{
    private const string PASSWORD = 'secret123';
    private const string INVALID_CREDENTIALS = '{"error":"Invalid credentials."}';
    private const string AUTHENTICATION_REQUIRED = '{"error":"Authentication required."}';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();

        // Login throttling state lives in a cache pool that outlives a test; start clean.
        self::getContainer()->get('cache.rate_limiter')->clear();
    }

    #[Test]
    public function aUserSignsInWithAJsonBodyAndGetsAnHttpOnlySessionCookie(): void
    {
        $id = $this->createUser('ada@example.com', ['GAME_MANAGER', 'OWNER']);

        $this->login('Ada@Example.com', self::PASSWORD);

        self::assertResponseStatusCodeSame(200);
        self::assertJsonStringEqualsJsonString(
            \sprintf('{"id":"%s","email":"ada@example.com","roles":["GAME_MANAGER","OWNER"]}', $id),
            $this->content(),
        );
        $cookies = $this->client->getResponse()->headers->getCookies();
        self::assertCount(1, $cookies);
        self::assertTrue($cookies[0]->isHttpOnly());
        self::assertSame(Cookie::SAMESITE_LAX, $cookies[0]->getSameSite());
    }

    #[Test]
    public function aWrongPasswordAndAnUnknownEmailGetTheSameAnswer(): void
    {
        $this->createUser('ada@example.com', ['SOLO_PLAYER']);

        $this->login('ada@example.com', 'wrong-password');
        self::assertResponseStatusCodeSame(401);
        $wrongPassword = $this->content();

        $this->login('nobody@example.com', self::PASSWORD);
        self::assertResponseStatusCodeSame(401);

        self::assertJsonStringEqualsJsonString(self::INVALID_CREDENTIALS, $wrongPassword);
        self::assertJsonStringEqualsJsonString(self::INVALID_CREDENTIALS, $this->content());
    }

    #[Test]
    public function aMalformedEmailIsJustInvalidCredentials(): void
    {
        $this->login('not-an-email', self::PASSWORD);

        self::assertResponseStatusCodeSame(401);
        self::assertJsonStringEqualsJsonString(self::INVALID_CREDENTIALS, $this->content());
    }

    #[Test]
    public function aFormEncodedLoginIsRejected(): void
    {
        $this->createUser('ada@example.com', ['SOLO_PLAYER']);

        $this->client->request('POST', '/api/auth/login', ['email' => 'ada@example.com', 'password' => self::PASSWORD]);

        self::assertResponseStatusCodeSame(415);
        self::assertJsonStringEqualsJsonString('{"error":"Send the credentials as JSON."}', $this->content());
        $this->client->request('GET', '/api/auth/me');
        self::assertResponseStatusCodeSame(401);
    }

    #[Test]
    public function theCurrentUserNeedsASession(): void
    {
        $this->client->request('GET', '/api/auth/me');

        self::assertResponseStatusCodeSame(401);
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        self::assertJsonStringEqualsJsonString(self::AUTHENTICATION_REQUIRED, $this->content());
    }

    #[Test]
    public function aSignedInUserReadsTheirOwnData(): void
    {
        $id = $this->createUser('ada@example.com', ['SOLO_PLAYER']);
        $this->login('ada@example.com', self::PASSWORD);

        $this->client->request('GET', '/api/auth/me');

        self::assertResponseIsSuccessful();
        self::assertJsonStringEqualsJsonString(
            \sprintf('{"id":"%s","email":"ada@example.com","roles":["SOLO_PLAYER"]}', $id),
            $this->content(),
        );
    }

    #[Test]
    public function signingOutEndsTheSession(): void
    {
        $this->createUser('ada@example.com', ['SOLO_PLAYER']);
        $this->login('ada@example.com', self::PASSWORD);

        $this->client->request('POST', '/api/auth/logout');

        self::assertResponseStatusCodeSame(204);
        self::assertSame('', $this->content());
        $this->client->request('GET', '/api/auth/me');
        self::assertResponseStatusCodeSame(401);
    }

    #[Test]
    public function signingOutTakesAPost(): void
    {
        $this->createUser('ada@example.com', ['SOLO_PLAYER']);
        $this->login('ada@example.com', self::PASSWORD);

        $this->client->request('GET', '/api/auth/logout');

        self::assertResponseStatusCodeSame(405);
        $this->client->request('GET', '/api/auth/me');
        self::assertResponseIsSuccessful();
    }

    #[Test]
    public function repeatedFailuresAreThrottled(): void
    {
        $this->createUser('ada@example.com', ['SOLO_PLAYER']);

        for ($attempt = 1; $attempt <= 5; ++$attempt) {
            $this->login('ada@example.com', 'wrong-password');
            self::assertResponseStatusCodeSame(401);
        }

        // Even the right password is refused until the window passes.
        $this->login('ada@example.com', self::PASSWORD);

        self::assertResponseStatusCodeSame(429);
        self::assertJsonStringEqualsJsonString('{"error":"Too many login attempts. Try again later."}', $this->content());
    }

    #[Test]
    public function theHealthCheckAndTheSpecStayPublic(): void
    {
        $this->client->request('GET', '/api/health');
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/api/doc.json');
        self::assertResponseIsSuccessful();
    }

    /**
     * Unknown /api paths 404 at routing before the firewall runs, so the
     * access map itself proves every other /api path needs a session.
     */
    #[Test]
    public function everyOtherApiPathRequiresASession(): void
    {
        $accessMap = self::getContainer()->get('security.access_map');

        self::assertSame([['IS_AUTHENTICATED'], null], $accessMap->getPatterns(Request::create('/api/campaigns')));
        self::assertSame([['IS_AUTHENTICATED'], null], $accessMap->getPatterns(Request::create('/api/auth/me')));
        self::assertSame([['PUBLIC_ACCESS'], null], $accessMap->getPatterns(Request::create('/api/auth/login', 'POST')));
        self::assertSame([null, null], $accessMap->getPatterns(Request::create('/play')));
    }

    /**
     * @param list<string> $roles
     */
    private function createUser(string $email, array $roles): string
    {
        $container = self::getContainer();
        $id = $container->get(UserIdGenerator::class)->generate()->toString();
        $container->get(CommandBus::class)->dispatch(new CreateUser($id, $email, self::PASSWORD, $roles));

        return $id;
    }

    private function login(string $email, string $password): void
    {
        $this->client->jsonRequest('POST', '/api/auth/login', ['email' => $email, 'password' => $password]);
    }

    private function content(): string
    {
        return (string) $this->client->getResponse()->getContent();
    }
}
