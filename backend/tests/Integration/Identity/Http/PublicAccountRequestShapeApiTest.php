<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity\Http;

use App\Identity\Infrastructure\Http\PublicAccountController;
use App\Tests\Support\Identity\PublicAccountApiTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Request;

/**
 * The four public account endpoints fail closed on a request that is not the expected JSON, and
 * are open to anyone: a 400 or 415 (not a 401) proves `access_control` lets them through.
 */
#[CoversClass(PublicAccountController::class)]
final class PublicAccountRequestShapeApiTest extends PublicAccountApiTestCase
{
    /**
     * @return iterable<string, array{string, array<string, mixed>}>
     */
    public static function endpoints(): iterable
    {
        yield 'password reset request' => ['/api/auth/password-reset-requests', ['email' => 'ada@example.com']];
        yield 'password reset' => ['/api/auth/password-resets', ['token' => 'a-token', 'password' => 'correct horse']];
        yield 'invitation lookup' => ['/api/auth/invitations/lookup', ['token' => 'a-token']];
        yield 'invitation accept' => ['/api/auth/invitations/accept', ['token' => 'a-token', 'password' => 'correct horse']];
    }

    /**
     * @param array<string, mixed> $valid
     */
    #[Test]
    #[DataProvider('endpoints')]
    public function aBodyThatIsNotJsonIsUnsupported(string $path, array $valid): void
    {
        $this->client->request('POST', $path, $valid);

        self::assertResponseStatusCodeSame(415);
        self::assertJsonStringEqualsJsonString('{"error":"Send the request as JSON.","code":"not_json"}', $this->content());
    }

    /**
     * @param array<string, mixed> $valid
     */
    #[Test]
    #[DataProvider('endpoints')]
    public function malformedJsonIsABadRequest(string $path, array $valid): void
    {
        foreach (['{', '"a string"', '[]'] as $body) {
            $this->client->request('POST', $path, server: ['CONTENT_TYPE' => 'application/json'], content: $body);

            self::assertResponseStatusCodeSame(400, $body);
            $this->assertMalformedRequestBody($path);
        }
    }

    /**
     * @param array<string, mixed> $valid
     */
    #[Test]
    #[DataProvider('endpoints')]
    public function aMissingOrNonStringFieldIsABadRequest(string $path, array $valid): void
    {
        foreach (array_keys($valid) as $field) {
            $missing = array_diff_key($valid, [$field => true]);
            foreach ([$missing, [...$valid, $field => 42], [...$valid, $field => null], [...$valid, $field => ['x']]] as $body) {
                $this->post($path, $body);

                self::assertResponseStatusCodeSame(400, (string) json_encode($body));
                $this->assertMalformedRequestBody($path);
            }
        }
    }

    /**
     * The 400 body is exactly the endpoint's message plus the `malformed_request` code.
     */
    private function assertMalformedRequestBody(string $path): void
    {
        $messages = [
            '/api/auth/password-reset-requests' => 'Send a JSON object with a string "email", such as {"email": "ada@example.com"}.',
            '/api/auth/password-resets' => 'Send a JSON object with a string "token" and a string "password".',
            '/api/auth/invitations/lookup' => 'Send a JSON object with a string "token".',
            '/api/auth/invitations/accept' => 'Send a JSON object with a string "token" and a string "password".',
        ];

        self::assertJsonStringEqualsJsonString(
            (string) json_encode(['error' => $messages[$path], 'code' => 'malformed_request']),
            $this->content(),
        );
    }

    /**
     * Exactly these four paths are public: a longer or sibling path still needs a session.
     */
    #[Test]
    public function onlyTheFourExactPathsArePublic(): void
    {
        $accessMap = self::getContainer()->get('security.access_map');

        foreach (self::endpoints() as [$path]) {
            self::assertSame([['PUBLIC_ACCESS'], null], $accessMap->getPatterns(Request::create($path, 'POST')));
            self::assertSame([['IS_AUTHENTICATED'], null], $accessMap->getPatterns(Request::create($path.'/extra', 'POST')));
        }
        self::assertSame([['IS_AUTHENTICATED'], null], $accessMap->getPatterns(Request::create('/api/auth/invitations', 'POST')));
    }

    #[Test]
    public function onlyPostIsAllowed(): void
    {
        $this->client->request('GET', '/api/auth/password-resets');

        self::assertResponseStatusCodeSame(405);
    }
}
