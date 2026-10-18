<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity;

use App\Identity\Application\PasswordResetIdAlreadyInUse;
use App\Identity\Domain\AccountToken;
use App\Identity\Domain\Email;
use App\Identity\Domain\PasswordReset;
use App\Identity\Domain\PasswordResetId;
use App\Identity\Domain\Role;
use App\Identity\Domain\User;
use App\Identity\Domain\UserId;
use App\Identity\Infrastructure\Persistence\Doctrine\DoctrinePasswordResetRepository;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

#[CoversClass(DoctrinePasswordResetRepository::class)]
final class DoctrinePasswordResetRepositoryTest extends KernelTestCase
{
    private const string FIRST_ID = '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b';
    private const string SECOND_ID = '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5c';
    private const string THIRD_ID = '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5e';
    private const string ADA_ID = '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5d';
    private const string BOB_ID = '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5f';

    private DoctrinePasswordResetRepository $resets;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->resets = new DoctrinePasswordResetRepository($this->entityManager);
        $this->user(self::ADA_ID, 'ada@example.com');
        $this->user(self::BOB_ID, 'bob@example.com');
    }

    #[Test]
    public function itRoundTripsAFreshAndAUsedReset(): void
    {
        // A non UTC offset and microseconds: the stored instant must survive unchanged.
        $fresh = $this->reset(self::FIRST_ID, self::ADA_ID, 'one', '2026-10-18T11:00:00.123456+02:00');
        $used = $this->reset(self::SECOND_ID, self::ADA_ID, 'two', '2026-10-18T09:00:00+00:00');
        $used->use(new \DateTimeImmutable('2026-10-18T09:30:00.5+00:00'));
        $this->resets->save($used);
        $this->entityManager->clear();

        foreach ([$fresh, $used] as $saved) {
            $loaded = $this->resets->ofId($saved->id());

            self::assertNotNull($loaded);
            self::assertNotSame($saved, $loaded);
            self::assertEquals($saved, $loaded);
        }

        self::assertSame('2026-10-18T10:00:00.123456+00:00', $this->resets->ofId(PasswordResetId::fromString(self::FIRST_ID))?->expiresAt()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.uP'));
    }

    #[Test]
    public function itFindsAResetByTokenHashWithALockAndFreshState(): void
    {
        $this->reset(self::FIRST_ID, self::ADA_ID, 'one', '2026-10-18T09:00:00+00:00');
        $managed = $this->resets->ofId(PasswordResetId::fromString(self::FIRST_ID));
        self::assertNotNull($managed);

        // What a concurrent reset that committed meanwhile leaves behind.
        $this->entityManager->getConnection()->executeStatement("UPDATE identity_password_reset SET used_at = '2026-10-18T09:10:00+00:00' WHERE id = ?", [self::FIRST_ID]);

        $found = $this->entityManager->wrapInTransaction(fn (): ?PasswordReset => $this->resets->ofTokenHashForUpdate(AccountToken::fromString('one')->hash()));

        self::assertSame($managed, $found, 'The managed entity is refreshed, not duplicated.');
        self::assertEquals(new \DateTimeImmutable('2026-10-18T09:10:00+00:00'), $found->usedAt());
        $this->entityManager->wrapInTransaction(function (): void {
            self::assertNull($this->resets->ofTokenHashForUpdate(AccountToken::fromString('unknown')->hash()));
        });
    }

    #[Test]
    public function itKeepsOnlyTheGivenResetOfTheUser(): void
    {
        $this->reset(self::FIRST_ID, self::ADA_ID, 'one', '2026-10-18T09:00:00+00:00');
        $this->reset(self::SECOND_ID, self::ADA_ID, 'two', '2026-10-18T09:00:00+00:00');
        $this->reset(self::THIRD_ID, self::BOB_ID, 'three', '2026-10-18T09:00:00+00:00');

        $this->resets->deleteForUserExcept(UserId::fromString(self::ADA_ID), PasswordResetId::fromString(self::SECOND_ID));
        $this->entityManager->clear();

        self::assertNull($this->resets->ofId(PasswordResetId::fromString(self::FIRST_ID)));
        self::assertNotNull($this->resets->ofId(PasswordResetId::fromString(self::SECOND_ID)));
        self::assertNotNull($this->resets->ofId(PasswordResetId::fromString(self::THIRD_ID)), 'Another user\'s reset was deleted.');
    }

    #[Test]
    public function aMalformedIdFindsNothing(): void
    {
        self::assertNull($this->resets->ofId(PasswordResetId::fromString('not-a-uuid')));
    }

    #[Test]
    public function theDatabaseRejectsADuplicateTokenHash(): void
    {
        $this->reset(self::FIRST_ID, self::ADA_ID, 'same', '2026-10-18T09:00:00+00:00');

        $this->expectException(UniqueConstraintViolationException::class);

        $this->reset(self::SECOND_ID, self::BOB_ID, 'same', '2026-10-18T09:00:00+00:00');
    }

    #[Test]
    public function theDatabaseRefusesAResetIdThatIsAlreadyInUse(): void
    {
        $this->reset(self::FIRST_ID, self::ADA_ID, 'one', '2026-10-18T09:00:00+00:00');
        $this->entityManager->clear();

        try {
            $this->reset(self::FIRST_ID, self::BOB_ID, 'two', '2026-10-18T09:00:00+00:00');
            self::fail('A second reset with the same id was saved.');
        } catch (PasswordResetIdAlreadyInUse $exception) {
            self::assertStringContainsString(self::FIRST_ID, $exception->getMessage());
        }
    }

    #[Test]
    public function theDatabaseRefusesAResetForAnUnknownUser(): void
    {
        $this->expectException(ForeignKeyConstraintViolationException::class);

        $this->reset(self::FIRST_ID, '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4aff', 'one', '2026-10-18T09:00:00+00:00');
    }

    private function reset(string $id, string $userId, string $token, string $now): PasswordReset
    {
        $reset = PasswordReset::request(
            PasswordResetId::fromString($id),
            UserId::fromString($userId),
            AccountToken::fromString($token),
            new \DateTimeImmutable($now),
        );
        $this->resets->save($reset);

        return $reset;
    }

    private function user(string $id, string $email): void
    {
        $this->entityManager->persist(User::register(UserId::fromString($id), Email::fromString($email), 'hash', [Role::SoloPlayer], new \DateTimeImmutable('2026-10-01T09:00:00+00:00')));
        $this->entityManager->flush();
    }
}
