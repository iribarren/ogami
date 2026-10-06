<?php

declare(strict_types=1);

namespace App\Studio\Infrastructure\Persistence\Doctrine;

use App\Studio\Domain\Release\GameSystemRelease;
use App\Studio\Domain\Release\GameSystemReleaseAlreadyExists;
use App\Studio\Domain\Release\GameSystemReleaseRepository;
use App\Studio\Domain\Release\ReleaseId;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineGameSystemReleaseRepository implements GameSystemReleaseRepository
{
    /** Name of the unique index on (game_system_key, version), see Release.GameSystemRelease.orm.xml. */
    public const string KEY_VERSION_UNIQUE_CONSTRAINT = 'uniq_studio_gamesystem_release_key_version';

    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Flushes right away so that a unique index violation surfaces here, inside the handler. The
     * entity manager is closed afterwards; the command bus transaction rolls back.
     *
     * @throws GameSystemReleaseAlreadyExists     when (GameSystem key, version) is taken, e.g. by a concurrent publish
     * @throws UniqueConstraintViolationException when the id is taken
     */
    public function add(GameSystemRelease $release): void
    {
        $this->entityManager->persist($release);

        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException $exception) {
            if (str_contains($exception->getMessage(), self::KEY_VERSION_UNIQUE_CONSTRAINT)) {
                throw GameSystemReleaseAlreadyExists::for($release->gameSystemKey(), $release->version(), $exception);
            }

            throw $exception;
        }
    }

    public function latestFor(string $gameSystemKey): ?GameSystemRelease
    {
        return $this->entityManager->getRepository(GameSystemRelease::class)
            ->findOneBy(['gameSystemKey' => $gameSystemKey], ['version' => 'DESC']);
    }

    /**
     * One query: each release whose version is the highest of its key (the unique index on
     * (game_system_key, version) serves the correlated subquery).
     */
    public function latestOfEachKey(): array
    {
        /** @var list<GameSystemRelease> $releases */
        $releases = $this->entityManager->createQuery(
            'SELECT r FROM '.GameSystemRelease::class.' r
             WHERE r.version = (
                 SELECT MAX(latest.version) FROM '.GameSystemRelease::class.' latest
                 WHERE latest.gameSystemKey = r.gameSystemKey
             )
             ORDER BY r.gameSystemKey ASC',
        )->getResult();

        return $releases;
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
