<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\GameSystem;

use App\Play\Application\CardImageBytes;
use App\Play\Application\CardImageNotFound;
use App\Play\Application\PublishedReleaseContents;
use App\Play\Application\ReleaseCardImageView;
use App\Play\Application\ReleaseContentView;
use App\Play\Domain\GameSystem\GameSystemReleaseNotFound;
use App\Play\Domain\GameSystem\InvalidGameSystemRelease;
use App\Shared\Application\Bus\QueryBus;
use App\Studio\Application\CardImageNotFound as StudioCardImageNotFound;
use App\Studio\Application\GetPublishedCardImage;
use App\Studio\Application\GetPublishedReleaseContent;
use App\Studio\Application\PublishedCardImageView;
use App\Studio\Application\PublishedReleaseNotFound;
use App\Studio\Application\PublishedReleaseUnreadable;

/**
 * Reads a published release's content and card images from Studio's Published Language
 * (GetPublishedReleaseContent and GetPublishedCardImage on the query bus) and translates them into
 * Play views and failures.
 */
final readonly class StudioPublishedReleaseContents implements PublishedReleaseContents
{
    public function __construct(
        private QueryBus $queries,
    ) {
    }

    public function content(string $gameSystemKey, int $version): ReleaseContentView
    {
        try {
            $view = $this->queries->ask(new GetPublishedReleaseContent($gameSystemKey, $version));
        } catch (PublishedReleaseNotFound $exception) {
            throw GameSystemReleaseNotFound::for($gameSystemKey, $version, $exception);
        } catch (PublishedReleaseUnreadable $exception) {
            throw InvalidGameSystemRelease::of($gameSystemKey, $exception->version, $exception->path, $exception->reason, $exception);
        }

        return new ReleaseContentView(
            $view->gameSystemKey,
            $view->version,
            $view->publishedAt,
            $view->content,
            array_map(static fn (PublishedCardImageView $image): ReleaseCardImageView => new ReleaseCardImageView($image->slot, $image->sha256, $image->mediaType, $image->width, $image->height), $view->cardImages),
        );
    }

    public function cardImage(string $gameSystemKey, int $version, string $sha256): CardImageBytes
    {
        try {
            $image = $this->queries->ask(new GetPublishedCardImage($gameSystemKey, $version, $sha256));
        } catch (PublishedReleaseNotFound $exception) {
            throw GameSystemReleaseNotFound::for($gameSystemKey, $version, $exception);
        } catch (StudioCardImageNotFound $exception) {
            throw CardImageNotFound::withHash($sha256, $exception);
        }

        return new CardImageBytes($image->mediaType, $image->bytes);
    }
}
