<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260521203740 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE transaction_carte_edenred ADD engin_locked TINYINT(1) DEFAULT 0 NOT NULL, ADD engin_match_source VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE transaction_carte_total ADD utilisateur_locked TINYINT(1) DEFAULT 0 NOT NULL, ADD utilisateur_match_source VARCHAR(20) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE transaction_carte_edenred DROP engin_locked, DROP engin_match_source');
        $this->addSql('ALTER TABLE transaction_carte_total DROP utilisateur_locked, DROP utilisateur_match_source');
    }
}
