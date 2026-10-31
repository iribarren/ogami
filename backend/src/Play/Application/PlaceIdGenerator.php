<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Play\Domain\Campaign\Lists\PlaceId;

/**
 * Port: hands out a new, unique PlaceId. Callers generate the id before dispatching AddPlace,
 * because commands return nothing, and then read the campaign for the Place by that id.
 */
interface PlaceIdGenerator
{
    public function generate(): PlaceId;
}
