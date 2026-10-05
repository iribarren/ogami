<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

use App\Shared\Application\Health\HealthReport;
use OpenApi\Attributes as OA;

/**
 * JSON body of `GET /api/health`. Lives in Infrastructure so the OpenAPI
 * attributes stay out of the Application layer.
 */
#[OA\Schema(required: ['status', 'database'])]
final readonly class HealthResponse
{
    /**
     * @param 'ok'        $status
     * @param 'ok'|'down' $database
     */
    private function __construct(
        #[OA\Property(description: 'The application answered the request.', enum: ['ok'])]
        public string $status,
        #[OA\Property(description: 'Whether the database answers right now.', enum: ['ok', 'down'])]
        public string $database,
    ) {
    }

    public static function fromReport(HealthReport $report): self
    {
        $data = $report->toArray();

        return new self($data['status'], $data['database']);
    }
}
