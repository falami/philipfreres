<?php

declare(strict_types=1);
namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261021090000 extends AbstractMigration
{
    public function getDescription(): string { return 'Add isolated Intescia connection, market cache and reminder ledger tables. No existing data is changed.'; }
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE insight_connection (tenant_id INT NOT NULL, settings JSON NOT NULL, credentials LONGTEXT DEFAULT NULL, PRIMARY KEY(tenant_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE insight_market (id VARCHAR(64) NOT NULL, tenant_id INT NOT NULL, remote_id VARCHAR(36) NOT NULL, title LONGTEXT NOT NULL, buyer LONGTEXT NOT NULL, deadline DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', active TINYINT(1) NOT NULL, seen VARCHAR(32) NOT NULL, payload JSON NOT NULL, updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX idx_insight_market_tenant (tenant_id), INDEX idx_insight_market_due (tenant_id, active, deadline), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE insight_contract_cache (id VARCHAR(64) NOT NULL, tenant_id INT NOT NULL, payload JSON NOT NULL, INDEX idx_insight_contract_cache_tenant (tenant_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE insight_delivery (id VARCHAR(64) NOT NULL, tenant_id INT NOT NULL, market_id VARCHAR(64) NOT NULL, user_id INT NOT NULL, deadline DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', status VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX idx_insight_delivery_tenant (tenant_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }
    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('The imported markets and delivery ledger must be retained. Disable the integration instead of deleting its tables.');
    }
}
