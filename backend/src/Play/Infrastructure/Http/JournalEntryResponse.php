<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use App\Play\Application\JournalEntryView;
use App\Play\Domain\Journal\JournalEntryContents;
use App\Play\Domain\Journal\LikelihoodContent;
use App\Play\Domain\Journal\NoteContent;
use App\Play\Domain\Journal\OracleTableContent;
use App\Play\Domain\Journal\RollContent;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * One entry of a campaign's journal, with the session and scene it was recorded in. The content
 * is a union discriminated by its "kind", so the typed client can narrow it.
 */
#[OA\Schema(required: ['id', 'sessionNumber', 'sceneNumber', 'recordedAt', 'kind', 'content'])]
final readonly class JournalEntryResponse
{
    private function __construct(
        #[OA\Property(format: 'uuid')]
        public string $id,
        #[OA\Property(example: 1)]
        public int $sessionNumber,
        #[OA\Property(description: 'Numbered from 1 within the session.', example: 2)]
        public int $sceneNumber,
        #[OA\Property(format: 'date-time')]
        public string $recordedAt,
        #[OA\Property(description: 'The same as `content.kind`.', enum: [NoteContent::KIND, RollContent::KIND, OracleTableContent::KIND, LikelihoodContent::KIND])]
        public string $kind,
        #[OA\Property(discriminator: new OA\Discriminator(propertyName: 'kind', mapping: [
            NoteContent::KIND => '#/components/schemas/NoteContentResponse',
            RollContent::KIND => '#/components/schemas/RollContentResponse',
            OracleTableContent::KIND => '#/components/schemas/OracleTableContentResponse',
            LikelihoodContent::KIND => '#/components/schemas/LikelihoodContentResponse',
        ]), oneOf: [
            new OA\Schema(ref: new Model(type: NoteContentResponse::class)),
            new OA\Schema(ref: new Model(type: RollContentResponse::class)),
            new OA\Schema(ref: new Model(type: OracleTableContentResponse::class)),
            new OA\Schema(ref: new Model(type: LikelihoodContentResponse::class)),
        ])]
        public NoteContentResponse|RollContentResponse|OracleTableContentResponse|LikelihoodContentResponse $content,
    ) {
    }

    public static function fromView(JournalEntryView $view): self
    {
        // The view holds the content's canonical array; rebuilding it gives its typed shape back.
        $content = JournalEntryContents::fromArray($view->content);

        return new self(
            $view->id,
            $view->sessionNumber,
            $view->sceneNumber,
            $view->recordedAt->format(\DATE_ATOM),
            $view->kind,
            match (true) {
                $content instanceof NoteContent => NoteContentResponse::of($content),
                $content instanceof RollContent => RollContentResponse::of($content),
                $content instanceof OracleTableContent => OracleTableContentResponse::of($content),
                $content instanceof LikelihoodContent => LikelihoodContentResponse::of($content),
                default => throw new \LogicException(\sprintf('No response for journal entry content kind "%s".', $content->kind())),
            },
        );
    }
}
