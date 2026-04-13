<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260412085224 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE chantier_photo ADD latitude_avant NUMERIC(10, 7) DEFAULT NULL, ADD longitude_avant NUMERIC(10, 7) DEFAULT NULL, ADD adresse_apres VARCHAR(255) DEFAULT NULL, ADD latitude_apres NUMERIC(10, 7) DEFAULT NULL, ADD longitude_apres NUMERIC(10, 7) DEFAULT NULL, ADD source_localisation_apres VARCHAR(20) DEFAULT NULL, DROP latitude, DROP longitude, CHANGE adresse adresse_avant VARCHAR(255) DEFAULT NULL, CHANGE source_localisation source_localisation_avant VARCHAR(20) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE chantier_photo ADD adresse VARCHAR(255) DEFAULT NULL, ADD latitude NUMERIC(10, 7) DEFAULT NULL, ADD longitude NUMERIC(10, 7) DEFAULT NULL, ADD source_localisation VARCHAR(20) DEFAULT NULL, DROP adresse_avant, DROP latitude_avant, DROP longitude_avant, DROP source_localisation_avant, DROP adresse_apres, DROP latitude_apres, DROP longitude_apres, DROP source_localisation_apres');
    }
}
