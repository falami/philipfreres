<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260227151647 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE produit_external_id (id INT AUTO_INCREMENT NOT NULL, produit_id INT NOT NULL, provider VARCHAR(255) NOT NULL, value VARCHAR(255) NOT NULL, active TINYINT(1) DEFAULT 1 NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', disabled_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', note VARCHAR(255) DEFAULT NULL, INDEX IDX_E16D9334F347EFB (produit_id), INDEX idx_typedep_ext_provider_value (provider, value), UNIQUE INDEX uniq_typedep_provider_value (produit_id, provider, value), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE type_produit (id INT AUTO_INCREMENT NOT NULL, entite_id INT DEFAULT NULL, libelle VARCHAR(100) NOT NULL, categorie_produit VARCHAR(255) NOT NULL, sous_categorie_produit VARCHAR(255) NOT NULL, INDEX IDX_18483D29BEA957A (entite_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE produit_external_id ADD CONSTRAINT FK_E16D9334F347EFB FOREIGN KEY (produit_id) REFERENCES type_produit (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE type_produit ADD CONSTRAINT FK_18483D29BEA957A FOREIGN KEY (entite_id) REFERENCES entite (id)');
        $this->addSql('ALTER TABLE engin DROP nom_alx, DROP nom_total, DROP nom_edenred');
        $this->addSql('ALTER TABLE transaction_carte_alx CHANGE provider provider VARCHAR(255) DEFAULT \'alx\' NOT NULL');
        $this->addSql('ALTER TABLE transaction_carte_edenred CHANGE provider provider VARCHAR(255) DEFAULT \'edenred\' NOT NULL');
        $this->addSql('ALTER TABLE transaction_carte_total CHANGE provider provider VARCHAR(255) DEFAULT \'total\' NOT NULL');
        $this->addSql('ALTER TABLE utilisateur CHANGE roles roles JSON NOT NULL');
        $this->addSql('ALTER TABLE utilisateur_entite CHANGE roles roles JSON NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE produit_external_id DROP FOREIGN KEY FK_E16D9334F347EFB');
        $this->addSql('ALTER TABLE type_produit DROP FOREIGN KEY FK_18483D29BEA957A');
        $this->addSql('DROP TABLE produit_external_id');
        $this->addSql('DROP TABLE type_produit');
        $this->addSql('ALTER TABLE engin ADD nom_alx VARCHAR(180) DEFAULT NULL, ADD nom_total VARCHAR(255) DEFAULT NULL, ADD nom_edenred VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE transaction_carte_alx CHANGE provider provider VARCHAR(255) NOT NULL');
        $this->addSql('ALTER TABLE transaction_carte_edenred CHANGE provider provider VARCHAR(255) NOT NULL');
        $this->addSql('ALTER TABLE transaction_carte_total CHANGE provider provider VARCHAR(255) NOT NULL');
        $this->addSql('ALTER TABLE utilisateur CHANGE roles roles LONGTEXT NOT NULL COLLATE `utf8mb4_bin`');
        $this->addSql('ALTER TABLE utilisateur_entite CHANGE roles roles LONGTEXT NOT NULL COLLATE `utf8mb4_bin`');
    }
}
