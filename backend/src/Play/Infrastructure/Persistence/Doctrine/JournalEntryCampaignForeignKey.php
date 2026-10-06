<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Persistence\Doctrine;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\TableEditor;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Doctrine\ORM\Tools\ToolEvents;

/**
 * Adds the foreign key from play_journal_entry.campaign_id to play_campaign to the schema Doctrine
 * generates from the mappings. JournalEntry holds its campaign id as a value, not an association,
 * so the mapping alone declares no foreign key; adding it here keeps migrations:diff and
 * schema:validate in line with the database.
 */
#[AsDoctrineListener(event: ToolEvents::postGenerateSchema)]
final readonly class JournalEntryCampaignForeignKey
{
    public const string NAME = 'fk_play_journal_entry_campaign';

    public function postGenerateSchema(GenerateSchemaEventArgs $event): void
    {
        $schema = $event->getSchema();
        if (!$schema->hasTable('play_journal_entry') || !$schema->hasTable('play_campaign')) {
            return;
        }

        $event->setSchema($schema->edit()->modifyTableByUnquotedName(
            'play_journal_entry',
            static function (TableEditor $table): void {
                $table->addForeignKeyConstraint(
                    ForeignKeyConstraint::editor()
                        ->setUnquotedName(self::NAME)
                        ->setUnquotedReferencingColumnNames('campaign_id')
                        ->setUnquotedReferencedTableName('play_campaign')
                        ->setUnquotedReferencedColumnNames('id')
                        ->create(),
                );
            },
        )->create());
    }
}
