<?php

declare(strict_types=1);

namespace App\Studio\Domain\Image;

/**
 * Where an image goes on a printed card (ADR 0053): the back of every card, or the frame of every front.
 */
enum CardImageSlot: string
{
    case CardBack = 'cardBack';
    case CardFront = 'cardFront';
}
