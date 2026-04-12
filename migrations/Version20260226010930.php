<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260226010930 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE engin (id INT AUTO_INCREMENT NOT NULL, entite_id INT NOT NULL, createur_id INT NOT NULL, nom VARCHAR(140) NOT NULL, type VARCHAR(255) NOT NULL, annee INT DEFAULT NULL, photo_couverture VARCHAR(255) DEFAULT NULL, date_creation DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_1FA4CE049BEA957A (entite_id), INDEX IDX_1FA4CE0473A201E5 (createur_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE engin_photo (id INT AUTO_INCREMENT NOT NULL, engin_id INT NOT NULL, createur_id INT NOT NULL, entite_id INT NOT NULL, filename VARCHAR(255) NOT NULL, alt VARCHAR(160) DEFAULT NULL, position SMALLINT UNSIGNED NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', date_creation DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_86481637E58AF0C2 (engin_id), INDEX IDX_8648163773A201E5 (createur_id), INDEX IDX_864816379BEA957A (entite_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE entite (id INT AUTO_INCREMENT NOT NULL, createur_id INT NOT NULL, couleur_principal VARCHAR(7) DEFAULT NULL, couleur_secondaire VARCHAR(7) DEFAULT NULL, couleur_tertiaire VARCHAR(7) DEFAULT NULL, couleur_quaternaire VARCHAR(7) DEFAULT NULL, logo VARCHAR(255) DEFAULT NULL, date_creation DATE NOT NULL COMMENT \'(DC2Type:date_immutable)\', adresse VARCHAR(255) DEFAULT NULL, complement VARCHAR(255) DEFAULT NULL, code_postal VARCHAR(255) DEFAULT NULL, ville VARCHAR(255) DEFAULT NULL, region VARCHAR(255) DEFAULT NULL, pays VARCHAR(255) DEFAULT NULL, departement VARCHAR(255) DEFAULT NULL, telephone VARCHAR(20) DEFAULT NULL, email VARCHAR(255) DEFAULT NULL, texte_accueil LONGTEXT DEFAULT NULL, description LONGTEXT DEFAULT NULL, logo_menu VARCHAR(255) DEFAULT NULL, public TINYINT(1) NOT NULL, nom VARCHAR(100) NOT NULL, siret VARCHAR(30) DEFAULT NULL, iban VARCHAR(255) DEFAULT NULL, banque VARCHAR(50) DEFAULT NULL, bic VARCHAR(30) DEFAULT NULL, numero_tva VARCHAR(50) DEFAULT NULL, numero_compte VARCHAR(30) DEFAULT NULL, code_banque VARCHAR(10) DEFAULT NULL, numero_declarant VARCHAR(14) DEFAULT NULL, forme_juridique VARCHAR(100) DEFAULT NULL, fonction VARCHAR(100) DEFAULT NULL, nom_representant VARCHAR(100) DEFAULT NULL, prenom_representant VARCHAR(100) DEFAULT NULL, last_activity_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', slug VARCHAR(80) DEFAULT NULL, is_active TINYINT(1) DEFAULT NULL, INDEX IDX_1A29182773A201E5 (createur_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE utilisateur (id INT AUTO_INCREMENT NOT NULL, createur_id INT DEFAULT NULL, entite_id INT DEFAULT NULL, email VARCHAR(180) NOT NULL, roles JSON NOT NULL, password VARCHAR(255) NOT NULL, is_verified TINYINT(1) NOT NULL, nom VARCHAR(100) NOT NULL, prenom VARCHAR(100) NOT NULL, telephone VARCHAR(20) DEFAULT NULL, couleur VARCHAR(7) DEFAULT NULL, photo VARCHAR(255) DEFAULT NULL, adresse VARCHAR(255) DEFAULT NULL, complement VARCHAR(255) DEFAULT NULL, code_postal VARCHAR(255) DEFAULT NULL, date_naissance DATE DEFAULT NULL COMMENT \'(DC2Type:date_immutable)\', ville VARCHAR(255) DEFAULT NULL, civilite VARCHAR(15) DEFAULT NULL, reset_token VARCHAR(255) DEFAULT NULL, reset_token_expires_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', abonnement VARCHAR(20) DEFAULT NULL, stripe_customer_id VARCHAR(255) DEFAULT NULL, stripe_subscription_id VARCHAR(255) DEFAULT NULL, numero_licence VARCHAR(100) DEFAULT NULL, region VARCHAR(255) DEFAULT NULL, pays VARCHAR(255) DEFAULT NULL, departement VARCHAR(255) DEFAULT NULL, desactiver_temporairement TINYINT(1) DEFAULT NULL, bannir TINYINT(1) DEFAULT NULL, unread_count INT DEFAULT NULL, consentement_rgpd TINYINT(1) DEFAULT NULL, date_consentement_rgpd DATE DEFAULT NULL COMMENT \'(DC2Type:date_immutable)\', newsletter TINYINT(1) DEFAULT NULL, mail_bienvenue TINYINT(1) DEFAULT NULL, niveau VARCHAR(100) DEFAULT NULL, mail_sortie TINYINT(1) DEFAULT NULL, societe VARCHAR(100) DEFAULT NULL, date_creation DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_1D1C63B373A201E5 (createur_id), INDEX IDX_1D1C63B39BEA957A (entite_id), UNIQUE INDEX UNIQ_IDENTIFIER_EMAIL (email), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE utilisateur_entite (id INT AUTO_INCREMENT NOT NULL, utilisateur_id INT NOT NULL, entite_id INT NOT NULL, createur_id INT NOT NULL, couleur VARCHAR(7) DEFAULT NULL, fonction VARCHAR(100) DEFAULT NULL, date_creation DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', roles JSON NOT NULL, status VARCHAR(20) DEFAULT \'active\' NOT NULL, INDEX IDX_7B8422ACFB88E14F (utilisateur_id), INDEX IDX_7B8422AC9BEA957A (entite_id), INDEX IDX_7B8422AC73A201E5 (createur_id), UNIQUE INDEX uniq_user_entite (utilisateur_id, entite_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE engin ADD CONSTRAINT FK_1FA4CE049BEA957A FOREIGN KEY (entite_id) REFERENCES entite (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE engin ADD CONSTRAINT FK_1FA4CE0473A201E5 FOREIGN KEY (createur_id) REFERENCES utilisateur (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE engin_photo ADD CONSTRAINT FK_86481637E58AF0C2 FOREIGN KEY (engin_id) REFERENCES engin (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE engin_photo ADD CONSTRAINT FK_8648163773A201E5 FOREIGN KEY (createur_id) REFERENCES utilisateur (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE engin_photo ADD CONSTRAINT FK_864816379BEA957A FOREIGN KEY (entite_id) REFERENCES entite (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE entite ADD CONSTRAINT FK_1A29182773A201E5 FOREIGN KEY (createur_id) REFERENCES utilisateur (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE utilisateur ADD CONSTRAINT FK_1D1C63B373A201E5 FOREIGN KEY (createur_id) REFERENCES utilisateur (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE utilisateur ADD CONSTRAINT FK_1D1C63B39BEA957A FOREIGN KEY (entite_id) REFERENCES entite (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE utilisateur_entite ADD CONSTRAINT FK_7B8422ACFB88E14F FOREIGN KEY (utilisateur_id) REFERENCES utilisateur (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE utilisateur_entite ADD CONSTRAINT FK_7B8422AC9BEA957A FOREIGN KEY (entite_id) REFERENCES entite (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE utilisateur_entite ADD CONSTRAINT FK_7B8422AC73A201E5 FOREIGN KEY (createur_id) REFERENCES utilisateur (id) ON DELETE RESTRICT');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE engin DROP FOREIGN KEY FK_1FA4CE049BEA957A');
        $this->addSql('ALTER TABLE engin DROP FOREIGN KEY FK_1FA4CE0473A201E5');
        $this->addSql('ALTER TABLE engin_photo DROP FOREIGN KEY FK_86481637E58AF0C2');
        $this->addSql('ALTER TABLE engin_photo DROP FOREIGN KEY FK_8648163773A201E5');
        $this->addSql('ALTER TABLE engin_photo DROP FOREIGN KEY FK_864816379BEA957A');
        $this->addSql('ALTER TABLE entite DROP FOREIGN KEY FK_1A29182773A201E5');
        $this->addSql('ALTER TABLE utilisateur DROP FOREIGN KEY FK_1D1C63B373A201E5');
        $this->addSql('ALTER TABLE utilisateur DROP FOREIGN KEY FK_1D1C63B39BEA957A');
        $this->addSql('ALTER TABLE utilisateur_entite DROP FOREIGN KEY FK_7B8422ACFB88E14F');
        $this->addSql('ALTER TABLE utilisateur_entite DROP FOREIGN KEY FK_7B8422AC9BEA957A');
        $this->addSql('ALTER TABLE utilisateur_entite DROP FOREIGN KEY FK_7B8422AC73A201E5');
        $this->addSql('DROP TABLE engin');
        $this->addSql('DROP TABLE engin_photo');
        $this->addSql('DROP TABLE entite');
        $this->addSql('DROP TABLE utilisateur');
        $this->addSql('DROP TABLE utilisateur_entite');
    }
}
