<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260326082957 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE note (id INT AUTO_INCREMENT NOT NULL, engin_id INT DEFAULT NULL, utilisateur_id INT DEFAULT NULL, entite_id INT NOT NULL, produit_id INT DEFAULT NULL, date_transaction DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', libelle VARCHAR(255) NOT NULL, commentaire LONGTEXT DEFAULT NULL, quantite NUMERIC(12, 3) DEFAULT NULL, prix_unitaire_eur NUMERIC(12, 4) DEFAULT NULL, taux_tva_percent NUMERIC(6, 2) DEFAULT NULL, montant_remise_eur NUMERIC(12, 2) DEFAULT NULL, montant_ht_eur NUMERIC(12, 2) DEFAULT NULL, montant_tva_eur NUMERIC(12, 2) DEFAULT NULL, montant_ttc_eur NUMERIC(12, 2) DEFAULT NULL, INDEX IDX_CFBDFA14E58AF0C2 (engin_id), INDEX IDX_CFBDFA14FB88E14F (utilisateur_id), INDEX IDX_CFBDFA149BEA957A (entite_id), INDEX IDX_CFBDFA14F347EFB (produit_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE note ADD CONSTRAINT FK_CFBDFA14E58AF0C2 FOREIGN KEY (engin_id) REFERENCES engin (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE note ADD CONSTRAINT FK_CFBDFA14FB88E14F FOREIGN KEY (utilisateur_id) REFERENCES utilisateur (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE note ADD CONSTRAINT FK_CFBDFA149BEA957A FOREIGN KEY (entite_id) REFERENCES entite (id)');
        $this->addSql('ALTER TABLE note ADD CONSTRAINT FK_CFBDFA14F347EFB FOREIGN KEY (produit_id) REFERENCES produit (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE utilisateur CHANGE email email VARCHAR(180) NOT NULL, CHANGE roles roles JSON NOT NULL, CHANGE password password VARCHAR(255) NOT NULL, CHANGE nom nom VARCHAR(100) NOT NULL, CHANGE prenom prenom VARCHAR(100) NOT NULL, CHANGE telephone telephone VARCHAR(20) DEFAULT NULL, CHANGE couleur couleur VARCHAR(7) DEFAULT NULL, CHANGE photo photo VARCHAR(255) DEFAULT NULL, CHANGE adresse adresse VARCHAR(255) DEFAULT NULL, CHANGE complement complement VARCHAR(255) DEFAULT NULL, CHANGE code_postal code_postal VARCHAR(255) DEFAULT NULL, CHANGE ville ville VARCHAR(255) DEFAULT NULL, CHANGE civilite civilite VARCHAR(15) DEFAULT NULL, CHANGE reset_token reset_token VARCHAR(255) DEFAULT NULL, CHANGE abonnement abonnement VARCHAR(20) DEFAULT NULL, CHANGE stripe_customer_id stripe_customer_id VARCHAR(255) DEFAULT NULL, CHANGE stripe_subscription_id stripe_subscription_id VARCHAR(255) DEFAULT NULL, CHANGE numero_licence numero_licence VARCHAR(100) DEFAULT NULL, CHANGE region region VARCHAR(255) DEFAULT NULL, CHANGE pays pays VARCHAR(255) DEFAULT NULL, CHANGE departement departement VARCHAR(255) DEFAULT NULL, CHANGE niveau niveau VARCHAR(100) DEFAULT NULL, CHANGE societe societe VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE utilisateur_entite CHANGE couleur couleur VARCHAR(7) DEFAULT NULL, CHANGE fonction fonction VARCHAR(100) DEFAULT NULL, CHANGE roles roles JSON NOT NULL, CHANGE status status VARCHAR(20) DEFAULT \'active\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE note DROP FOREIGN KEY FK_CFBDFA14E58AF0C2');
        $this->addSql('ALTER TABLE note DROP FOREIGN KEY FK_CFBDFA14FB88E14F');
        $this->addSql('ALTER TABLE note DROP FOREIGN KEY FK_CFBDFA149BEA957A');
        $this->addSql('ALTER TABLE note DROP FOREIGN KEY FK_CFBDFA14F347EFB');
        $this->addSql('DROP TABLE note');
        $this->addSql('ALTER TABLE utilisateur CHANGE email email VARCHAR(180) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_unicode_ci`, CHANGE roles roles LONGTEXT CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_bin`, CHANGE password password VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_unicode_ci`, CHANGE nom nom VARCHAR(100) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_unicode_ci`, CHANGE prenom prenom VARCHAR(100) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_unicode_ci`, CHANGE telephone telephone VARCHAR(20) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_unicode_ci`, CHANGE couleur couleur VARCHAR(7) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_unicode_ci`, CHANGE photo photo VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_unicode_ci`, CHANGE adresse adresse VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_unicode_ci`, CHANGE complement complement VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_unicode_ci`, CHANGE code_postal code_postal VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_unicode_ci`, CHANGE ville ville VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_unicode_ci`, CHANGE civilite civilite VARCHAR(15) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_unicode_ci`, CHANGE reset_token reset_token VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_unicode_ci`, CHANGE abonnement abonnement VARCHAR(20) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_unicode_ci`, CHANGE stripe_customer_id stripe_customer_id VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_unicode_ci`, CHANGE stripe_subscription_id stripe_subscription_id VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_unicode_ci`, CHANGE numero_licence numero_licence VARCHAR(100) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_unicode_ci`, CHANGE region region VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_unicode_ci`, CHANGE pays pays VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_unicode_ci`, CHANGE departement departement VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_unicode_ci`, CHANGE niveau niveau VARCHAR(100) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_unicode_ci`, CHANGE societe societe VARCHAR(100) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('ALTER TABLE utilisateur_entite CHANGE couleur couleur VARCHAR(7) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_unicode_ci`, CHANGE fonction fonction VARCHAR(100) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_unicode_ci`, CHANGE roles roles LONGTEXT CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_bin`, CHANGE status status VARCHAR(20) CHARACTER SET utf8mb4 DEFAULT \'active\' NOT NULL COLLATE `utf8mb4_unicode_ci`');
    }
}
