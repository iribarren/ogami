<?php

declare(strict_types=1);

namespace App\Tests\Support\Identity;

use App\Identity\Application\CreateUser;
use App\Identity\Application\UserIdGenerator;
use App\Identity\Infrastructure\Mail\SendAccountMailHandler;
use App\Shared\Application\Bus\CommandBus;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * What the API tests of the public account endpoints share: an anonymous client, a recording
 * mail transport behind the after-commit mail handler, a log recorder, and a clean rate limiter.
 */
abstract class PublicAccountApiTestCase extends WebTestCase
{
    protected const string PASSWORD = 'secret123';

    protected KernelBrowser $client;
    protected RecordingMailer $mailer;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        // The test swaps the mail handler in the container: keep it across requests.
        $this->client->disableReboot();

        // Rate limiter state lives in a cache pool that outlives a test; start clean.
        self::getContainer()->get('cache.rate_limiter')->clear();
        $this->mailer = new RecordingMailer();
        self::getContainer()->set(SendAccountMailHandler::class, new SendAccountMailHandler($this->mailer));
    }

    /**
     * @param string $messagePrefix the framework logs its own routing and bus messages: keep the application's
     *
     * @return list<array{string, string, array<mixed>}> the log records so far: level, message, context
     */
    protected function logs(string $messagePrefix = 'A password reset'): array
    {
        return array_values(array_filter(
            self::getContainer()->get('logger')->records,
            static fn (array $record): bool => str_starts_with($record[1], $messagePrefix),
        ));
    }

    /**
     * @param list<string> $roles
     */
    protected function createUser(string $email, array $roles = ['SOLO_PLAYER']): string
    {
        $container = self::getContainer();
        $id = $container->get(UserIdGenerator::class)->generate()->toString();
        $container->get(CommandBus::class)->dispatch(new CreateUser($id, $email, self::PASSWORD, $roles));

        return $id;
    }

    /**
     * @param array<string, mixed>  $body
     * @param array<string, string> $headers server parameters, e.g. HTTP_X_FORWARDED_FOR
     */
    protected function post(string $path, array $body, string $ip = '127.0.0.1', array $headers = []): void
    {
        $this->client->jsonRequest('POST', $path, $body, ['REMOTE_ADDR' => $ip, ...$headers]);
    }

    protected function login(string $email, string $password): void
    {
        $this->post('/api/auth/login', ['email' => $email, 'password' => $password]);
    }

    protected function content(): string
    {
        return (string) $this->client->getResponse()->getContent();
    }

    /**
     * The token in the link of the last email sent.
     */
    protected function lastToken(): string
    {
        $last = end($this->mailer->sent);
        preg_match('~#token=([A-Za-z0-9_-]{43})~', false === $last ? '' : (string) $last->getTextBody(), $matches);

        return $matches[1] ?? self::fail('No link in the email.');
    }
}
