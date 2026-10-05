<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity;

use App\Identity\Application\PasswordHasher;
use App\Identity\Infrastructure\Security\SecurityUser;
use App\Identity\Infrastructure\Security\SymfonyPasswordHasher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;

#[CoversClass(SymfonyPasswordHasher::class)]
final class SymfonyPasswordHasherTest extends KernelTestCase
{
    #[Test]
    public function itProducesAHashTheSecurityUserHasherVerifies(): void
    {
        $container = self::getContainer();

        $hash = $container->get(PasswordHasher::class)->hash('s3cret');

        $verifier = $container->get(PasswordHasherFactoryInterface::class)->getPasswordHasher(SecurityUser::class);
        self::assertNotSame('s3cret', $hash);
        self::assertTrue($verifier->verify($hash, 's3cret'));
        self::assertFalse($verifier->verify($hash, 'wrong'));
    }
}
