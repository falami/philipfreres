<?php

declare(strict_types=1);
namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261023010000 extends AbstractMigration
{
    public function getDescription(): string { return 'Store tender preparation separately from imports, with optimistic revisions.'; }
    public function up(Schema $schema): void
    {
        $table = $schema->createTable('insight_preparation');
        $table->addColumn('market_id', 'string', ['length' => 64]);
        $table->addColumn('tenant_id', 'integer');
        $table->addColumn('revision', 'integer');
        $table->addColumn('data', 'json');
        $table->addColumn('updated_at', 'datetime_immutable');
        $table->setPrimaryKey(['market_id']);
        $table->addIndex(['tenant_id'], 'idx_insight_preparation_tenant');
    }
    public function down(Schema $schema): void { $this->throwIrreversibleMigrationException('Keep internal preparation and history.'); }
}
