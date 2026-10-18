<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Persistence\Doctrine;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\TableEditor;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Doctrine\ORM\Tools\ToolEvents;

/**
 * Adds the foreign key from identity_password_reset.user_id to identity_user to the schema
 * Doctrine generates from the mappings. PasswordReset holds its user id as a value, not an
 * association, so the mapping alone declares no foreign key; adding it here keeps
 * migrations:diff and schema:validate in line with the database.
 */
#[AsDoctrineListener(event: ToolEvents::postGenerateSchema)]
final readonly class PasswordResetUserForeignKey
{
    public const string NAME = 'fk_identity_password_reset_user';

    public function postGenerateSchema(GenerateSchemaEventArgs $event): void
    {
        $schema = $event->getSchema();
        if (!$schema->hasTable('identity_password_reset') || !$schema->hasTable('identity_user')) {
            return;
        }

        $event->setSchema($schema->edit()->modifyTableByUnquotedName(
            'identity_password_reset',
            static function (TableEditor $table): void {
                $table->addForeignKeyConstraint(
                    ForeignKeyConstraint::editor()
                        ->setUnquotedName(self::NAME)
                        ->setUnquotedReferencingColumnNames('user_id')
                        ->setUnquotedReferencedTableName('identity_user')
                        ->setUnquotedReferencedColumnNames('id')
                        ->create(),
                );
            },
        )->create());
    }
}
