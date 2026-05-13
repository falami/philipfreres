<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260513135332 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE engin_usage_releve (id INT AUTO_INCREMENT NOT NULL, entite_id INT NOT NULL, engin_id INT NOT NULL, createur_id INT NOT NULL, date_releve DATE NOT NULL COMMENT \'(DC2Type:date_immutable)\', valeur NUMERIC(12, 2) NOT NULL, frais NUMERIC(12, 2) DEFAULT NULL, libelle VARCHAR(255) DEFAULT NULL, note LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_3352FE659BEA957A (entite_id), INDEX IDX_3352FE65E58AF0C2 (engin_id), INDEX IDX_3352FE6573A201E5 (createur_id), INDEX idx_usage_entite_date (entite_id, date_releve), INDEX idx_usage_engin_date (engin_id, date_releve), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE engin_usage_releve ADD CONSTRAINT FK_3352FE659BEA957A FOREIGN KEY (entite_id) REFERENCES entite (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE engin_usage_releve ADD CONSTRAINT FK_3352FE65E58AF0C2 FOREIGN KEY (engin_id) REFERENCES engin (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE engin_usage_releve ADD CONSTRAINT FK_3352FE6573A201E5 FOREIGN KEY (createur_id) REFERENCES utilisateur (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE engin ADD compteur_type VARCHAR(255) DEFAULT \'heure\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE engin_usage_releve DROP FOREIGN KEY FK_3352FE659BEA957A');
        $this->addSql('ALTER TABLE engin_usage_releve DROP FOREIGN KEY FK_3352FE65E58AF0C2');
        $this->addSql('ALTER TABLE engin_usage_releve DROP FOREIGN KEY FK_3352FE6573A201E5');
        $this->addSql('DROP TABLE engin_usage_releve');
        $this->addSql('ALTER TABLE engin DROP compteur_type');
    }
}
