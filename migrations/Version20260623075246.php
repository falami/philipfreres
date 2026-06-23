<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260623075246 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE mandataire (id INT AUTO_INCREMENT NOT NULL, entite_id INT NOT NULL, createur_id INT DEFAULT NULL, nom VARCHAR(180) NOT NULL, societe VARCHAR(180) DEFAULT NULL, email VARCHAR(180) DEFAULT NULL, telephone VARCHAR(30) DEFAULT NULL, adresse VARCHAR(255) DEFAULT NULL, code_postal VARCHAR(20) DEFAULT NULL, ville VARCHAR(120) DEFAULT NULL, commentaire LONGTEXT DEFAULT NULL, actif TINYINT(1) NOT NULL, date_creation DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_1DA4DCB49BEA957A (entite_id), INDEX IDX_1DA4DCB473A201E5 (createur_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE chantier_mandataire (chantier_id INT NOT NULL, mandataire_id INT NOT NULL, INDEX IDX_6FA67067D0C0049D (chantier_id), INDEX IDX_6FA6706758207E03 (mandataire_id), PRIMARY KEY(chantier_id, mandataire_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE mandataire ADD CONSTRAINT FK_1DA4DCB49BEA957A FOREIGN KEY (entite_id) REFERENCES entite (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE mandataire ADD CONSTRAINT FK_1DA4DCB473A201E5 FOREIGN KEY (createur_id) REFERENCES utilisateur (id) ON DELETE SET NULL');

        $this->addSql('ALTER TABLE chantier_mandataire ADD CONSTRAINT FK_6FA67067D0C0049D FOREIGN KEY (chantier_id) REFERENCES chantier (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE chantier_mandataire ADD CONSTRAINT FK_6FA6706758207E03 FOREIGN KEY (mandataire_id) REFERENCES mandataire (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE chantier_mandataire DROP FOREIGN KEY FK_6FA67067D0C0049D');
        $this->addSql('ALTER TABLE chantier_mandataire DROP FOREIGN KEY FK_6FA6706758207E03');
        $this->addSql('ALTER TABLE mandataire DROP FOREIGN KEY FK_1DA4DCB49BEA957A');
        $this->addSql('ALTER TABLE mandataire DROP FOREIGN KEY FK_1DA4DCB473A201E5');

        $this->addSql('DROP TABLE chantier_mandataire');
        $this->addSql('DROP TABLE mandataire');
    }
}
