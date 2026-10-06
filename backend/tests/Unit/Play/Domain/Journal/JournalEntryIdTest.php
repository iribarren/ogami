<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Domain\Journal;

use App\Play\Domain\Journal\InvalidJournalEntryId;
use App\Play\Domain\Journal\JournalEntryId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(JournalEntryId::class)]
#[CoversClass(InvalidJournalEntryId::class)]
final class JournalEntryIdTest extends TestCase
{
    #[Test]
    public function itKeepsItsValue(): void
    {
        $id = JournalEntryId::fromString('01890a5d-ac96-774b-bcce-b302099a9001');

        self::assertSame('01890a5d-ac96-774b-bcce-b302099a9001', $id->toString());
        self::assertTrue($id->equals(JournalEntryId::fromString('01890a5d-ac96-774b-bcce-b302099a9001')));
        self::assertFalse($id->equals(JournalEntryId::fromString('01890a5d-ac96-774b-bcce-b302099a9002')));
    }

    #[Test]
    public function aBlankIdIsRejected(): void
    {
        $this->expectException(InvalidJournalEntryId::class);
        $this->expectExceptionMessageIsOrContains('A journal entry id must not be blank.');

        JournalEntryId::fromString('  ');
    }
}
