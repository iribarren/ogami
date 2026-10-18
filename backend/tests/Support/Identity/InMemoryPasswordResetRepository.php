<?php

declare(strict_types=1);

namespace App\Tests\Support\Identity;

use App\Identity\Domain\PasswordReset;
use App\Identity\Domain\PasswordResetId;
use App\Identity\Domain\PasswordResetRepository;
use App\Identity\Domain\UserId;

final class InMemoryPasswordResetRepository implements PasswordResetRepository
{
    /** @var array<string, PasswordReset> */
    private array $resets = [];

    public function save(PasswordReset $reset): void
    {
        $this->resets[$reset->id()->toString()] = $reset;
    }

    public function ofId(PasswordResetId $id): ?PasswordReset
    {
        return $this->resets[$id->toString()] ?? null;
    }

    public function ofTokenHashForUpdate(string $tokenHash): ?PasswordReset
    {
        foreach ($this->resets as $reset) {
            if ($reset->tokenHash() === $tokenHash) {
                return $reset;
            }
        }

        return null;
    }

    public function deleteForUserExcept(UserId $userId, PasswordResetId $keep): void
    {
        foreach ($this->resets as $key => $reset) {
            if ($reset->userId()->equals($userId) && !$reset->id()->equals($keep)) {
                unset($this->resets[$key]);
            }
        }
    }

    /**
     * @return list<PasswordReset>
     */
    public function all(): array
    {
        return array_values($this->resets);
    }
}
