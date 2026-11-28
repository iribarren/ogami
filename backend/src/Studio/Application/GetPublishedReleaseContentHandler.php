<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Shared\Application\Bus\QueryHandler;
use App\Studio\Domain\Image\CardImagePin;
use App\Studio\Domain\Release\GameSystemRelease;
use App\Studio\Domain\Release\GameSystemReleaseRepository;
use App\Studio\Domain\Release\InvalidReleaseContent;

final readonly class GetPublishedReleaseContentHandler implements QueryHandler
{
    public function __construct(
        private GameSystemReleaseRepository $releases,
        private ReleaseCardImagePins $pins,
    ) {
    }

    /**
     * @throws PublishedReleaseNotFound
     * @throws PublishedReleaseUnreadable when the release found no longer parses
     */
    public function __invoke(GetPublishedReleaseContent $query): PublishedReleaseContentView
    {
        $release = $this->releases->get($query->gameSystemKey, $query->version);
        if (!$release instanceof GameSystemRelease) {
            throw PublishedReleaseNotFound::for($query->gameSystemKey, $query->version);
        }

        try {
            $content = $release->content()->toArray();
        } catch (InvalidReleaseContent $exception) {
            throw new PublishedReleaseUnreadable($release->gameSystemKey(), $release->version(), $exception->path(), $exception->reason(), $exception);
        }

        return new PublishedReleaseContentView(
            $release->gameSystemKey(),
            $release->version(),
            $release->publishedAt(),
            $content,
            array_map(
                static fn (CardImagePin $pin): PublishedCardImageView => new PublishedCardImageView($pin->slot->value, $pin->image->sha256, $pin->image->mediaType, $pin->image->width, $pin->image->height),
                $this->pins->pinsOf($release->id()),
            ),
        );
    }
}
