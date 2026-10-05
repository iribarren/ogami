<?php

declare(strict_types=1);

namespace App\Shared\Application\Health;

final readonly class HealthReport
{
    public function __construct(
        public bool $databaseReachable,
    ) {
    }

    /**
     * @return array{status: 'ok', database: 'ok'|'down'}
     */
    public function toArray(): array
    {
        return [
            // The application answered, so it is up; dependencies are reported separately.
            'status' => 'ok',
            'database' => $this->databaseReachable ? 'ok' : 'down',
        ];
    }
}
