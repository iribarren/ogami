<?php

declare(strict_types=1);

namespace App\Tests\Support\Identity;

use Psr\Log\AbstractLogger;

/**
 * Replaces the `logger` service in the test environment and keeps every record.
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{string, string, array<mixed>}> level, message, context */
    public array $records = [];

    public function log(mixed $level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = [\is_string($level) ? $level : get_debug_type($level), (string) $message, $context];
    }
}
