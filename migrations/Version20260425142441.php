<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260425142441 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE chantier CHANGE date_debut_previsionnelle date_debut_previsionnelle DATETIME DEFAULT NULL, CHANGE date_fin_previsionnelle date_fin_previsionnelle DATETIME DEFAULT NULL, CHANGE date_debut_reelle date_debut_reelle DATETIME DEFAULT NULL, CHANGE date_fin_reelle date_fin_reelle DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE chantier CHANGE date_debut_previsionnelle date_debut_previsionnelle DATE DEFAULT NULL, CHANGE date_fin_previsionnelle date_fin_previsionnelle DATE DEFAULT NULL, CHANGE date_debut_reelle date_debut_reelle DATE DEFAULT NULL, CHANGE date_fin_reelle date_fin_reelle DATE DEFAULT NULL');
    }
}
