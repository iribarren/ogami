<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use App\Play\Domain\Journal\FlowOutcomeContent;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * What the Flow decided on its own while one command ran, recorded right after the step's own
 * entry (or in the current scene): a list of notes discriminated by their "kind".
 */
#[OA\Schema(required: ['kind', 'notes'])]
final readonly class FlowOutcomeContentResponse
{
    /**
     * @param list<FlowNoteBandResponse|FlowNoteTrackerResponse|FlowNoteSwitchResponse|FlowNoteNextSceneResponse|FlowNotePhaseEndResponse> $notes
     */
    private function __construct(
        #[OA\Property(enum: [FlowOutcomeContent::KIND])]
        public string $kind,
        #[OA\Property(
            description: 'Every note, in the order the Flow decided; at least one.',
            type: 'array',
            items: new OA\Items(
                discriminator: new OA\Discriminator(propertyName: 'kind', mapping: [
                    'band' => '#/components/schemas/FlowNoteBandResponse',
                    'tracker' => '#/components/schemas/FlowNoteTrackerResponse',
                    'switch' => '#/components/schemas/FlowNoteSwitchResponse',
                    'nextScene' => '#/components/schemas/FlowNoteNextSceneResponse',
                    'phaseEnd' => '#/components/schemas/FlowNotePhaseEndResponse',
                ]),
                oneOf: [
                    new OA\Schema(ref: new Model(type: FlowNoteBandResponse::class)),
                    new OA\Schema(ref: new Model(type: FlowNoteTrackerResponse::class)),
                    new OA\Schema(ref: new Model(type: FlowNoteSwitchResponse::class)),
                    new OA\Schema(ref: new Model(type: FlowNoteNextSceneResponse::class)),
                    new OA\Schema(ref: new Model(type: FlowNotePhaseEndResponse::class)),
                ],
            ),
        )]
        public array $notes,
    ) {
    }

    public static function of(FlowOutcomeContent $content): self
    {
        return new self(FlowOutcomeContent::KIND, array_map(static fn (array $note): FlowNoteBandResponse|FlowNoteTrackerResponse|FlowNoteSwitchResponse|FlowNoteNextSceneResponse|FlowNotePhaseEndResponse => match ($note['kind']) {
            'band' => new FlowNoteBandResponse('band', $note['label']),
            'tracker' => new FlowNoteTrackerResponse('tracker', $note['key'], $note['name'], $note['from'], $note['to']),
            'switch' => new FlowNoteSwitchResponse('switch', $note['from'], $note['to']),
            'nextScene' => new FlowNoteNextSceneResponse('nextScene', $note['sceneType']),
            'phaseEnd' => new FlowNotePhaseEndResponse('phaseEnd', $note['phase']),
        }, $content->notes()));
    }
}
