<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Http;

use App\Identity\Application\AcceptInvitation;
use App\Identity\Application\EmailAlreadyInUse;
use App\Identity\Application\InvalidAccountLink;
use App\Identity\Application\LookupInvitation;
use App\Identity\Application\MailUnavailable;
use App\Identity\Application\PasswordResetIdGenerator;
use App\Identity\Application\RequestPasswordReset;
use App\Identity\Application\ResetPassword;
use App\Identity\Application\UserIdGenerator;
use App\Identity\Application\WeakPassword;
use App\Identity\Domain\Email;
use App\Identity\Domain\InvalidEmail;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Infrastructure\Http\ErrorResponse;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Exception\JsonException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The account endpoints that need no session: asking for a password reset link, choosing a new
 * password with it, and looking up and accepting an invitation. Tokens travel in the JSON body
 * only, never in a URL, and are never logged. Public in `access_control`.
 */
#[AsController]
#[OA\Tag(name: 'Auth')]
final readonly class PublicAccountController
{
    private const string NOT_JSON = 'Send the request as JSON.';
    private const string MALFORMED_EMAIL = 'Send a JSON object with a string "email", such as {"email": "ada@example.com"}.';
    private const string MALFORMED_PASSWORD = 'Send a JSON object with a string "token" and a string "password".';
    private const string MALFORMED_TOKEN = 'Send a JSON object with a string "token".';

    public function __construct(
        private CommandBus $commandBus,
        private QueryBus $queryBus,
        private PasswordResetIdGenerator $resetIds,
        private UserIdGenerator $userIds,
        private RateLimiterFactoryInterface $passwordResetEmailIpLimiter,
        private RateLimiterFactoryInterface $passwordResetIpLimiter,
        private LoggerInterface $logger,
    ) {
    }

    #[Route('/api/auth/password-reset-requests', name: 'api_auth_password_reset_requests', methods: ['POST'])]
    #[OA\Post(operationId: 'requestPasswordReset', summary: 'Ask for a link to choose a new password')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(required: ['email'], properties: [new OA\Property(property: 'email', type: 'string', format: 'email', example: 'ada@example.com')]))]
    #[OA\Response(response: 202, description: 'Accepted. Says nothing about whether the email has an account: unknown, disabled, rate-limited and failed-mail requests answer the same.')]
    #[OA\Response(response: 400, description: 'The JSON body is malformed or misses the email.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 415, description: 'The body is not JSON.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 422, description: 'The email is not a valid address.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    public function requestPasswordReset(Request $request): Response
    {
        $body = $this->jsonBody($request, self::MALFORMED_EMAIL);
        if ($body instanceof JsonResponse) {
            return $body;
        }

        $email = $body['email'] ?? null;
        if (!\is_string($email)) {
            return $this->error(self::MALFORMED_EMAIL, Response::HTTP_BAD_REQUEST);
        }

        // Validated first: a malformed email answers 422 whatever the limiters say.
        try {
            $address = Email::fromString($email)->toString();
        } catch (InvalidEmail $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Both limiters are always consumed, so flooding with fresh emails still fills the IP allowance.
        // The email allowance is per email and IP: keyed on the email alone, anyone could use up a victim's quota.
        $ip = $request->getClientIp() ?? 'unknown';
        $limited = array_keys(array_filter([
            'password_reset_email_ip' => !$this->passwordResetEmailIpLimiter->create($address.'|'.$ip)->consume()->isAccepted(),
            'password_reset_ip' => !$this->passwordResetIpLimiter->create($ip)->consume()->isAccepted(),
        ]));
        if ([] !== $limited) {
            // The limiter names only: no email, no IP, no token.
            $this->logger->info('A password reset request was rate limited.', ['limiters' => $limited]);

            return new Response('', Response::HTTP_ACCEPTED);
        }

        try {
            $this->commandBus->dispatch(new RequestPasswordReset($this->resetIds->generate()->toString(), $email));
        } catch (MailUnavailable $exception) {
            // A 503 only for existing accounts would reveal which emails have one. The reset stays stored.
            $this->logger->warning('A password reset email could not be sent.', ['exception' => $exception]);
        }

        return new Response('', Response::HTTP_ACCEPTED);
    }

    #[Route('/api/auth/password-resets', name: 'api_auth_password_resets', methods: ['POST'])]
    #[OA\Post(operationId: 'resetPassword', summary: 'Choose a new password with a reset link token')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(required: ['token', 'password'], properties: [
        new OA\Property(property: 'token', type: 'string'),
        new OA\Property(property: 'password', type: 'string', format: 'password'),
    ]))]
    #[OA\Response(response: 204, description: 'The password is changed; the link no longer works. Does not sign in.')]
    #[OA\Response(response: 400, description: 'The JSON body is malformed or misses a field.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 415, description: 'The body is not JSON.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 422, description: 'The password is too weak, or the link is invalid or has expired.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    public function resetPassword(Request $request): Response
    {
        $body = $this->jsonBody($request, self::MALFORMED_PASSWORD);
        if ($body instanceof JsonResponse) {
            return $body;
        }

        $token = $body['token'] ?? null;
        $password = $body['password'] ?? null;
        if (!\is_string($token) || !\is_string($password)) {
            return $this->error(self::MALFORMED_PASSWORD, Response::HTTP_BAD_REQUEST);
        }

        try {
            $this->commandBus->dispatch(new ResetPassword($token, $password));
        } catch (WeakPassword|InvalidAccountLink $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/api/auth/invitations/lookup', name: 'api_auth_invitations_lookup', methods: ['POST'])]
    #[OA\Post(operationId: 'lookupInvitation', summary: 'Read what an invitation link is for')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(required: ['token'], properties: [new OA\Property(property: 'token', type: 'string')]))]
    #[OA\Response(response: 200, description: 'The invited email, its roles and when the link expires.', content: new OA\JsonContent(ref: new Model(type: InvitationLookupResponse::class)))]
    #[OA\Response(response: 400, description: 'The JSON body is malformed or misses the token.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 415, description: 'The body is not JSON.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 422, description: 'The link is invalid or has expired.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    public function lookupInvitation(Request $request): JsonResponse
    {
        $body = $this->jsonBody($request, self::MALFORMED_TOKEN);
        if ($body instanceof JsonResponse) {
            return $body;
        }

        $token = $body['token'] ?? null;
        if (!\is_string($token)) {
            return $this->error(self::MALFORMED_TOKEN, Response::HTTP_BAD_REQUEST);
        }

        try {
            $view = $this->queryBus->ask(new LookupInvitation($token));
        } catch (InvalidAccountLink $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse(InvitationLookupResponse::fromView($view));
    }

    #[Route('/api/auth/invitations/accept', name: 'api_auth_invitations_accept', methods: ['POST'])]
    #[OA\Post(operationId: 'acceptInvitation', summary: 'Create the account of an invitation by choosing a password')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(required: ['token', 'password'], properties: [
        new OA\Property(property: 'token', type: 'string'),
        new OA\Property(property: 'password', type: 'string', format: 'password'),
    ]))]
    #[OA\Response(response: 204, description: 'The account is created. Does not sign in.')]
    #[OA\Response(response: 400, description: 'The JSON body is malformed or misses a field.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 409, description: 'An account with the invited email already exists.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 415, description: 'The body is not JSON.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    #[OA\Response(response: 422, description: 'The password is too weak, or the link is invalid or has expired.', content: new OA\JsonContent(ref: new Model(type: ErrorResponse::class)))]
    public function acceptInvitation(Request $request): Response
    {
        $body = $this->jsonBody($request, self::MALFORMED_PASSWORD);
        if ($body instanceof JsonResponse) {
            return $body;
        }

        $token = $body['token'] ?? null;
        $password = $body['password'] ?? null;
        if (!\is_string($token) || !\is_string($password)) {
            return $this->error(self::MALFORMED_PASSWORD, Response::HTTP_BAD_REQUEST);
        }

        try {
            $this->commandBus->dispatch(new AcceptInvitation($token, $password, $this->userIds->generate()->toString()));
        } catch (WeakPassword|InvalidAccountLink $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (EmailAlreadyInUse $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_CONFLICT);
        }

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * @param string $malformed the error when the JSON is malformed or not an object (400)
     *
     * @return array<mixed>|JsonResponse the decoded body, or the error response to send
     */
    private function jsonBody(Request $request, string $malformed): array|JsonResponse
    {
        if ('json' !== $request->getContentTypeFormat()) {
            return $this->error(self::NOT_JSON, Response::HTTP_UNSUPPORTED_MEDIA_TYPE);
        }

        try {
            return $request->toArray();
        } catch (JsonException) {
            return $this->error($malformed, Response::HTTP_BAD_REQUEST);
        }
    }

    private function error(string $message, int $status): JsonResponse
    {
        return new JsonResponse(ErrorResponse::withMessage($message), $status);
    }
}
