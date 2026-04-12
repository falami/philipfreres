<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260226023548 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE transaction_carte_total (id INT AUTO_INCREMENT NOT NULL, entite_id INT NOT NULL, compte_client VARCHAR(32) NOT NULL, raison_sociale VARCHAR(255) NOT NULL, compte_support VARCHAR(32) DEFAULT NULL, division VARCHAR(64) DEFAULT NULL, type_support VARCHAR(128) DEFAULT NULL, numero_carte VARCHAR(32) DEFAULT NULL, rang VARCHAR(32) DEFAULT NULL, evid VARCHAR(64) DEFAULT NULL, nom_personnalise_carte VARCHAR(255) DEFAULT NULL, information_complementaire VARCHAR(255) DEFAULT NULL, code_conducteur VARCHAR(64) DEFAULT NULL, immatriculation_vehicule VARCHAR(64) DEFAULT NULL, nom_collaborateur VARCHAR(120) DEFAULT NULL, prenom_collaborateur VARCHAR(120) DEFAULT NULL, kilometrage INT DEFAULT NULL, numero_transaction VARCHAR(64) DEFAULT NULL, date_transaction DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', heure_transaction TIME DEFAULT NULL COMMENT \'(DC2Type:time_immutable)\', pays VARCHAR(80) DEFAULT NULL, ville VARCHAR(120) DEFAULT NULL, code_postal VARCHAR(20) DEFAULT NULL, adresse VARCHAR(255) DEFAULT NULL, categorie_libelle_produit VARCHAR(180) DEFAULT NULL, produit VARCHAR(180) DEFAULT NULL, statut VARCHAR(120) DEFAULT NULL, numero_facture VARCHAR(64) DEFAULT NULL, quantite NUMERIC(12, 3) DEFAULT NULL, unite VARCHAR(24) DEFAULT NULL, prix_unitaire_eur NUMERIC(12, 4) DEFAULT NULL, taux_tva_percent NUMERIC(6, 2) DEFAULT NULL, montant_remise_eur NUMERIC(12, 2) DEFAULT NULL, montant_ht_eur NUMERIC(12, 2) DEFAULT NULL, montant_tva_eur NUMERIC(12, 2) DEFAULT NULL, montant_ttc_eur NUMERIC(12, 2) DEFAULT NULL, source_filename VARCHAR(255) DEFAULT NULL, source_row INT DEFAULT NULL, import_key VARCHAR(40) DEFAULT NULL, imported_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_F60980AC9BEA957A (entite_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE transaction_carte_total ADD CONSTRAINT FK_F60980AC9BEA957A FOREIGN KEY (entite_id) REFERENCES entite (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE transaction_carte_total DROP FOREIGN KEY FK_F60980AC9BEA957A');
        $this->addSql('DROP TABLE transaction_carte_total');
    }
}
