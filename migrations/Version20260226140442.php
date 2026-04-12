<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260226140442 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE transaction_carte_alx ADD provider VARCHAR(255) NOT NULL');
        $this->addSql('ALTER TABLE transaction_carte_edenred ADD provider VARCHAR(255) NOT NULL');
        $this->addSql('ALTER TABLE transaction_carte_total ADD provider VARCHAR(255) NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE transaction_carte_alx DROP provider');
        $this->addSql('ALTER TABLE transaction_carte_edenred DROP provider');
        $this->addSql('ALTER TABLE transaction_carte_total DROP provider');
    }
}
