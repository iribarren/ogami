<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Studio\Domain\Release\ReleaseId;

/**
 * Port: hands out a new, unique ReleaseId. Callers generate the id before dispatching
 * PublishGameSystemRelease, because commands return nothing.
 */
interface ReleaseIdGenerator
{
    public function generate(): ReleaseId;
}
