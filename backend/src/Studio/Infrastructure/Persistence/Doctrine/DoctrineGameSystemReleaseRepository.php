<?php

declare(strict_types=1);

namespace App\Studio\Infrastructure\Persistence\Doctrine;

use App\Studio\Domain\Release\GameSystemRelease;
use App\Studio\Domain\Release\GameSystemReleaseRepository;
use App\Studio\Domain\Release\ReleaseId;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineGameSystemReleaseRepository implements GameSystemReleaseRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Flushes right away so that a unique index violation surfaces here, inside the handler.
     *
     * @throws UniqueConstraintViolationException when the id or (GameSystem key, version) is taken,
     *                                            e.g. by a concurrent publish of the same GameSystem
     */
    public function add(GameSystemRelease $release): void
    {
        $this->entityManager->persist($release);
        $this->entityManager->flush();
    }

    public function latestFor(string $gameSystemKey): ?GameSystemRelease
    {
        return $this->entityManager->getRepository(GameSystemRelease::class)
            ->findOneBy(['gameSystemKey' => $gameSystemKey], ['version' => 'DESC']);
    }

    public function get(string $gameSystemKey, int $version): ?GameSystemRelease
    {
        return $this->entityManager->getRepository(GameSystemRelease::class)
            ->findOneBy(['gameSystemKey' => $gameSystemKey, 'version' => $version]);
    }

    public function ofId(ReleaseId $id): ?GameSystemRelease
    {
        // The column is a UUID: anything else cannot match and would make PostgreSQL fail.
        if (!Uuid::isValid($id->toString())) {
            return null;
        }

        return $this->entityManager->find(GameSystemRelease::class, $id->toString());
    }
}
