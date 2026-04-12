<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260226091606 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE transaction_carte_edenred (id INT AUTO_INCREMENT NOT NULL, entite_id INT NOT NULL, import_key VARCHAR(40) DEFAULT NULL, source_filename VARCHAR(255) DEFAULT NULL, source_row INT DEFAULT NULL, imported_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', enseigne VARCHAR(80) DEFAULT NULL, site_code_site VARCHAR(40) DEFAULT NULL, site_numero_terminal VARCHAR(40) DEFAULT NULL, site_libelle VARCHAR(255) DEFAULT NULL, site_libelle_court VARCHAR(255) DEFAULT NULL, site_type VARCHAR(80) DEFAULT NULL, client_reference VARCHAR(80) DEFAULT NULL, client_nom VARCHAR(255) DEFAULT NULL, carte_type VARCHAR(80) DEFAULT NULL, carte_numero VARCHAR(64) DEFAULT NULL, carte_validite VARCHAR(16) DEFAULT NULL, numero_tlc VARCHAR(64) DEFAULT NULL, date_telecollecte DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', type_transaction VARCHAR(120) DEFAULT NULL, numero_transaction VARCHAR(64) DEFAULT NULL, date_transaction DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', reference_transaction VARCHAR(120) DEFAULT NULL, code_devise VARCHAR(8) DEFAULT NULL, code_produit VARCHAR(32) DEFAULT NULL, produit VARCHAR(120) DEFAULT NULL, prix_unitaire NUMERIC(12, 4) DEFAULT NULL, quantite NUMERIC(12, 3) DEFAULT NULL, montant_ttc NUMERIC(12, 2) DEFAULT NULL, montant_ht NUMERIC(12, 2) DEFAULT NULL, code_vehicule VARCHAR(64) DEFAULT NULL, code_chauffeur VARCHAR(64) DEFAULT NULL, kilometrage VARCHAR(32) DEFAULT NULL, immatriculation VARCHAR(64) DEFAULT NULL, code_reponse VARCHAR(120) DEFAULT NULL, numero_opposition VARCHAR(64) DEFAULT NULL, numero_autorisation VARCHAR(64) DEFAULT NULL, motif_autorisation VARCHAR(255) DEFAULT NULL, mode_transaction VARCHAR(80) DEFAULT NULL, mode_vente VARCHAR(80) DEFAULT NULL, mode_validation VARCHAR(120) DEFAULT NULL, facturation_client VARCHAR(20) DEFAULT NULL, facturation_site VARCHAR(20) DEFAULT NULL, solde_apres NUMERIC(12, 2) DEFAULT NULL, numero_facture VARCHAR(64) DEFAULT NULL, avoir_gerant VARCHAR(120) DEFAULT NULL, INDEX IDX_44B3E9379BEA957A (entite_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE transaction_carte_edenred ADD CONSTRAINT FK_44B3E9379BEA957A FOREIGN KEY (entite_id) REFERENCES entite (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE transaction_carte_edenred DROP FOREIGN KEY FK_44B3E9379BEA957A');
        $this->addSql('DROP TABLE transaction_carte_edenred');
    }
}
