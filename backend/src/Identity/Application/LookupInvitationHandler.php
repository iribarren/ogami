<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Shared\Application\Bus\QueryHandler;

final readonly class LookupInvitationHandler implements QueryHandler
{
    public function __construct(
        private InvitationLoader $loader,
        private Clock $clock,
    ) {
    }

    /**
     * @throws InvalidAccountLink
     */
    public function __invoke(LookupInvitation $query): InvitationLookupView
    {
        return InvitationLookupView::fromInvitation($this->loader->usableForLookup($query->token, $this->clock->now()));
    }
}
