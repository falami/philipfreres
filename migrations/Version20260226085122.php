<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260226085122 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE transaction_carte_alx (id INT AUTO_INCREMENT NOT NULL, entite_id INT NOT NULL, journee DATE DEFAULT NULL COMMENT \'(DC2Type:date_immutable)\', horaire TIME DEFAULT NULL COMMENT \'(DC2Type:time_immutable)\', vehicule VARCHAR(180) DEFAULT NULL, code_veh VARCHAR(40) DEFAULT NULL, code_agent VARCHAR(40) DEFAULT NULL, agent VARCHAR(180) DEFAULT NULL, operation INT DEFAULT NULL, cuve INT DEFAULT NULL, quantite NUMERIC(12, 3) DEFAULT NULL, prix_unitaire NUMERIC(12, 4) DEFAULT NULL, compteur NUMERIC(12, 0) DEFAULT NULL, source_filename VARCHAR(255) DEFAULT NULL, source_row INT DEFAULT NULL, import_key VARCHAR(40) DEFAULT NULL, imported_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_2BFA87079BEA957A (entite_id), UNIQUE INDEX uniq_alx_entite_import_key (entite_id, import_key), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE transaction_carte_alx ADD CONSTRAINT FK_2BFA87079BEA957A FOREIGN KEY (entite_id) REFERENCES entite (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE transaction_carte_alx DROP FOREIGN KEY FK_2BFA87079BEA957A');
        $this->addSql('DROP TABLE transaction_carte_alx');
    }
}
