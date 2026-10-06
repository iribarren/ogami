<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Domain\Campaign;

use App\Play\Domain\Campaign\CampaignId;
use App\Play\Domain\Campaign\InvalidCampaignId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(CampaignId::class)]
#[CoversClass(InvalidCampaignId::class)]
final class CampaignIdTest extends TestCase
{
    #[Test]
    public function itKeepsItsValue(): void
    {
        $id = CampaignId::fromString('01890a5d-ac96-774b-bcce-b302099a8057');

        self::assertSame('01890a5d-ac96-774b-bcce-b302099a8057', $id->toString());
        self::assertTrue($id->equals(CampaignId::fromString('01890a5d-ac96-774b-bcce-b302099a8057')));
        self::assertFalse($id->equals(CampaignId::fromString('01890a5d-ac96-774b-bcce-b302099a8058')));
    }

    #[Test]
    public function aBlankIdIsRejected(): void
    {
        $this->expectException(InvalidCampaignId::class);
        $this->expectExceptionMessageIsOrContains('A campaign id must not be blank.');

        CampaignId::fromString('  ');
    }
}
