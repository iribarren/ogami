<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Shared\Application\Bus\Query;

/**
 * Shows the sheet of the GameSystem draft with $key owned by $ownerId: layout, fields, the
 * trackers a formula may read and the field keys of its latest release.
 *
 * @implements Query<GameSystemSheetView>
 */
final readonly class GetGameSystemSheet implements Query
{
    public function __construct(
        public string $ownerId,
        public string $key,
    ) {
    }
}
