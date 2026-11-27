<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Shared\Application\Bus\CommandHandler;
use App\Studio\Domain\GameSystem\GameSystemNotFound;
use App\Studio\Domain\Image\CardImage;
use App\Studio\Domain\Image\ImageRefused;

final readonly class UploadCardImageHandler implements CommandHandler
{
    public function __construct(
        private OwnedGameSystems $owned,
        private ImageNormalizer $normalizer,
        private CardImages $images,
    ) {
    }

    /**
     * @throws GameSystemNotFound when the user has no such GameSystem
     * @throws ImageRefused       when the upload breaks the rules; nothing is stored
     */
    public function __invoke(UploadCardImage $command): void
    {
        $gameSystem = $this->owned->get($command->ownerId, $command->key);
        CardImage::assertUploadSize(\strlen($command->upload));

        $image = $this->normalizer->normalize($command->upload);
        $this->images->replace($gameSystem->id(), $command->slot, $image);
        $command->receipt->store($image->image);
    }
}
