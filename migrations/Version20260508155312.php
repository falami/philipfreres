<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260508155312 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE chantier_utilisateur_affecte (chantier_id INT NOT NULL, utilisateur_id INT NOT NULL, INDEX IDX_338668A6D0C0049D (chantier_id), INDEX IDX_338668A6FB88E14F (utilisateur_id), PRIMARY KEY(chantier_id, utilisateur_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE chantier_utilisateur_affecte ADD CONSTRAINT FK_338668A6D0C0049D FOREIGN KEY (chantier_id) REFERENCES chantier (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE chantier_utilisateur_affecte ADD CONSTRAINT FK_338668A6FB88E14F FOREIGN KEY (utilisateur_id) REFERENCES utilisateur (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE chantier_utilisateur_affecte DROP FOREIGN KEY FK_338668A6D0C0049D');
        $this->addSql('ALTER TABLE chantier_utilisateur_affecte DROP FOREIGN KEY FK_338668A6FB88E14F');
        $this->addSql('DROP TABLE chantier_utilisateur_affecte');
    }
}
