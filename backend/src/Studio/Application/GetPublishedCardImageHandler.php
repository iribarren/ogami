<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Shared\Application\Bus\QueryHandler;
use App\Studio\Domain\Release\GameSystemRelease;
use App\Studio\Domain\Release\GameSystemReleaseRepository;

final readonly class GetPublishedCardImageHandler implements QueryHandler
{
    public function __construct(
        private GameSystemReleaseRepository $releases,
        private ReleaseCardImagePins $pins,
        private ImageStore $store,
    ) {
    }

    /**
     * @throws PublishedReleaseNotFound when the release does not exist
     * @throws CardImageNotFound        when this release did not pin an image with this hash
     */
    public function __invoke(GetPublishedCardImage $query): PublishedCardImage
    {
        $release = $this->releases->get($query->gameSystemKey, $query->version);
        if (!$release instanceof GameSystemRelease) {
            throw PublishedReleaseNotFound::for($query->gameSystemKey, $query->version);
        }

        $stored = \in_array($query->sha256, $this->pins->hashesOf($release->id()), true) ? $this->store->get($query->sha256) : null;
        if (!$stored instanceof StoredImage) {
            throw CardImageNotFound::withHash($query->sha256);
        }

        return new PublishedCardImage($stored->image->mediaType, $stored->bytes);
    }
}
