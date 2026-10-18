<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\AccountToken;
use App\Identity\Domain\PasswordReset;
use App\Identity\Domain\PasswordResetRepository;
use App\Identity\Domain\User;
use App\Identity\Domain\UserRepository;
use App\Shared\Application\Bus\CommandHandler;

final readonly class ResetPasswordHandler implements CommandHandler
{
    public function __construct(
        private PasswordResetRepository $resets,
        private UserRepository $users,
        private PasswordHasher $hasher,
        private Clock $clock,
    ) {
    }

    /**
     * The new hash, the used reset and the deletion of the user's other resets are saved in
     * the command's transaction: all or none. The reset row is locked, so two uses of one link
     * cannot both win. The clock is read once.
     *
     * @throws WeakPassword
     * @throws InvalidAccountLink when the token is blank, unknown, used or expired, or its user is missing or disabled
     */
    public function __invoke(ResetPassword $command): void
    {
        PasswordPolicy::assertAcceptable($command->password);

        $now = $this->clock->now();
        try {
            $tokenHash = AccountToken::fromString($command->token)->hash();
        } catch (\InvalidArgumentException) {
            throw InvalidAccountLink::create();
        }

        $reset = $this->resets->ofTokenHashForUpdate($tokenHash);
        if (!$reset instanceof PasswordReset || !$reset->isUsableAt($now)) {
            throw InvalidAccountLink::create();
        }

        $user = $this->users->ofId($reset->userId());
        if (!$user instanceof User || $user->isDisabled()) {
            throw InvalidAccountLink::create();
        }

        $user->changePassword($this->hasher->hash($command->password));
        $this->users->save($user);
        $reset->use($now);
        $this->resets->save($reset);
        $this->resets->deleteForUserExcept($user->id(), $reset->id());
    }
}
