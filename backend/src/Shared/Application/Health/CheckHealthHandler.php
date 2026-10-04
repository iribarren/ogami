<?php

declare(strict_types=1);

namespace App\Shared\Application\Health;

use App\Shared\Application\Bus\QueryHandler;

final readonly class CheckHealthHandler implements QueryHandler
{
    public function __construct(
        private DatabaseConnectivity $database,
    ) {
    }

    public function __invoke(CheckHealth $query): HealthReport
    {
        return new HealthReport($this->database->isReachable());
    }
}
