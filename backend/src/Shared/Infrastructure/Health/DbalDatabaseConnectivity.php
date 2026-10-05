<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Health;

use App\Shared\Application\Health\DatabaseConnectivity;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;

final readonly class DbalDatabaseConnectivity implements DatabaseConnectivity
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function isReachable(): bool
    {
        try {
            $this->connection->executeQuery('SELECT 1');

            return true;
        } catch (DbalException) {
            return false;
        }
    }
}
