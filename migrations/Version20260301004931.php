<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260301004931 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE geo_address_cache (id INT AUTO_INCREMENT NOT NULL, entite_id INT NOT NULL, addr_hash VARCHAR(64) NOT NULL, address VARCHAR(500) NOT NULL, lat DOUBLE PRECISION DEFAULT NULL, lng DOUBLE PRECISION DEFAULT NULL, geocoded_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', provider VARCHAR(255) DEFAULT NULL, display_name VARCHAR(255) DEFAULT NULL, confidence INT DEFAULT NULL, INDEX idx_geo_entite (entite_id), UNIQUE INDEX uniq_geo_entite_hash (entite_id, addr_hash), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE geo_address_cache ADD CONSTRAINT FK_C73F29399BEA957A FOREIGN KEY (entite_id) REFERENCES entite (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE utilisateur CHANGE roles roles JSON NOT NULL');
        $this->addSql('ALTER TABLE utilisateur_entite CHANGE roles roles JSON NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE geo_address_cache DROP FOREIGN KEY FK_C73F29399BEA957A');
        $this->addSql('DROP TABLE geo_address_cache');
        $this->addSql('ALTER TABLE utilisateur CHANGE roles roles LONGTEXT NOT NULL COLLATE `utf8mb4_bin`');
        $this->addSql('ALTER TABLE utilisateur_entite CHANGE roles roles LONGTEXT NOT NULL COLLATE `utf8mb4_bin`');
    }
}
