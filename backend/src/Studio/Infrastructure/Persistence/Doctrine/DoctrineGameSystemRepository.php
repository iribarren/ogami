<?php

declare(strict_types=1);

namespace App\Studio\Infrastructure\Persistence\Doctrine;

use App\Studio\Domain\GameSystem\GameSystem;
use App\Studio\Domain\GameSystem\GameSystemKeyTaken;
use App\Studio\Domain\GameSystem\GameSystemKind;
use App\Studio\Domain\GameSystem\GameSystemModifiedConcurrently;
use App\Studio\Domain\GameSystem\GameSystemRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;

final readonly class DoctrineGameSystemRepository implements GameSystemRepository
{
    /** Name of the unique index on game_system_key, see GameSystem.GameSystem.orm.xml. */
    public const string KEY_UNIQUE_CONSTRAINT = 'uniq_studio_gamesystem_key';

    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Flushes right away so that a unique index violation surfaces here, inside the handler. The
     * entity manager is closed afterwards; the command bus transaction rolls back.
     *
     * @throws GameSystemKeyTaken                 when the key is taken
     * @throws UniqueConstraintViolationException when the id is taken
     */
    public function add(GameSystem $gameSystem): void
    {
        $this->entityManager->persist($gameSystem);

        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException $exception) {
            if (str_contains($exception->getMessage(), self::KEY_UNIQUE_CONSTRAINT)) {
                throw GameSystemKeyTaken::withKey($gameSystem->key(), $exception);
            }

            throw $exception;
        }
    }

    /**
     * The version column is an optimistic lock: the update only matches the version this copy was
     * loaded with, so a save in between (another request) makes it fail instead of being lost. The
     * entity manager is closed afterwards; the command bus transaction rolls back.
     *
     * @throws GameSystemModifiedConcurrently when another request saved the GameSystem since it was loaded
     * @throws \LogicException                when the GameSystem was not added or loaded through this repository
     */
    public function save(GameSystem $gameSystem): void
    {
        if (!$this->entityManager->contains($gameSystem)) {
            throw new \LogicException(\sprintf('GameSystem "%s" was not added or loaded through this repository.', $gameSystem->key()));
        }

        try {
            $this->entityManager->flush();
        } catch (OptimisticLockException $exception) {
            if ($exception->getEntity() !== $gameSystem) {
                throw $exception;
            }

            throw GameSystemModifiedConcurrently::withKey($gameSystem->key(), $exception);
        }
    }

    public function ofKey(string $key): ?GameSystem
    {
        return $this->entityManager->getRepository(GameSystem::class)->findOneBy(['key' => $key]);
    }

    public function ofOwner(string $ownerId): array
    {
        return $this->entityManager->getRepository(GameSystem::class)
            ->findBy(['ownerId' => $ownerId], ['updatedAt' => 'DESC', 'key' => 'ASC']);
    }

    public function libraryPacks(): array
    {
        return $this->entityManager->getRepository(GameSystem::class)->findBy(['kind' => GameSystemKind::LibraryPack]);
    }

    public function remove(GameSystem $gameSystem): void
    {
        $this->entityManager->remove($gameSystem);
        $this->entityManager->flush();
    }
}
