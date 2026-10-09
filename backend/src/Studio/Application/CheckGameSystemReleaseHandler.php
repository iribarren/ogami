<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Shared\Application\Bus\QueryHandler;
use App\Studio\Domain\Release\InvalidReleaseContent;
use App\Studio\Domain\Release\ReleaseContent;

final readonly class CheckGameSystemReleaseHandler implements QueryHandler
{
    /**
     * @throws InvalidReleaseContent naming the path of the first offending value
     */
    public function __invoke(CheckGameSystemRelease $query): GameSystemReleaseCheck
    {
        $content = ReleaseContent::fromArray($query->content);

        return new GameSystemReleaseCheck($content->gameSystemKey(), $content->schemaVersion(), $content->warnings());
    }
}
