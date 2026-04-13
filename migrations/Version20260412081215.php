<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260412081215 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE chantier (id INT AUTO_INCREMENT NOT NULL, entite_id INT NOT NULL, createur_id INT NOT NULL, nom VARCHAR(180) NOT NULL, adresse VARCHAR(255) DEFAULT NULL, complement VARCHAR(255) DEFAULT NULL, code_postal VARCHAR(20) DEFAULT NULL, ville VARCHAR(120) DEFAULT NULL, nature_prestation LONGTEXT DEFAULT NULL, statut VARCHAR(255) NOT NULL, date_debut_previsionnelle DATE DEFAULT NULL, date_fin_previsionnelle DATE DEFAULT NULL, date_debut_reelle DATE DEFAULT NULL, date_fin_reelle DATE DEFAULT NULL, surface_traitee NUMERIC(10, 2) DEFAULT NULL, lineaire_traite NUMERIC(10, 2) DEFAULT NULL, difficultes_rencontrees LONGTEXT DEFAULT NULL, date_creation DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_636F27F69BEA957A (entite_id), INDEX IDX_636F27F673A201E5 (createur_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE chantier_dechet (id INT AUTO_INCREMENT NOT NULL, chantier_id INT NOT NULL, type_dechet_id INT NOT NULL, poids_total NUMERIC(10, 2) DEFAULT NULL, INDEX IDX_850599FFD0C0049D (chantier_id), INDEX IDX_850599FFB93D2352 (type_dechet_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE chantier_photo (id INT AUTO_INCREMENT NOT NULL, chantier_id INT NOT NULL, titre VARCHAR(180) DEFAULT NULL, photo_avant VARCHAR(255) DEFAULT NULL, photo_apres VARCHAR(255) DEFAULT NULL, commentaire VARCHAR(255) DEFAULT NULL, ordre INT NOT NULL, INDEX IDX_771F3B8D0C0049D (chantier_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE chantier_ressource_engin (id INT AUTO_INCREMENT NOT NULL, chantier_id INT NOT NULL, engin_id INT NOT NULL, commentaire VARCHAR(255) DEFAULT NULL, INDEX IDX_5C5526AED0C0049D (chantier_id), INDEX IDX_5C5526AEE58AF0C2 (engin_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE chantier_ressource_humaine (id INT AUTO_INCREMENT NOT NULL, chantier_id INT NOT NULL, utilisateur_id INT NOT NULL, fonction VARCHAR(120) DEFAULT NULL, INDEX IDX_E77B3C9DD0C0049D (chantier_id), INDEX IDX_E77B3C9DFB88E14F (utilisateur_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE chantier_ressource_materiel (id INT AUTO_INCREMENT NOT NULL, chantier_id INT NOT NULL, materiel_id INT NOT NULL, quantite INT DEFAULT NULL, commentaire VARCHAR(255) DEFAULT NULL, INDEX IDX_9020D30AD0C0049D (chantier_id), INDEX IDX_9020D30A16880AAF (materiel_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE chantier_zone (id INT AUTO_INCREMENT NOT NULL, chantier_id INT NOT NULL, nom VARCHAR(180) NOT NULL, parcelle VARCHAR(180) DEFAULT NULL, ordre INT NOT NULL, INDEX IDX_A04361FD0C0049D (chantier_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE dechet_type (id INT AUTO_INCREMENT NOT NULL, entite_id INT NOT NULL, createur_id INT NOT NULL, nom VARCHAR(140) NOT NULL, unite VARCHAR(20) NOT NULL, date_creation DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_844061B79BEA957A (entite_id), INDEX IDX_844061B773A201E5 (createur_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE materiel (id INT AUTO_INCREMENT NOT NULL, entite_id INT NOT NULL, createur_id INT NOT NULL, nom VARCHAR(140) NOT NULL, type VARCHAR(100) DEFAULT NULL, reference VARCHAR(120) DEFAULT NULL, photo_couverture VARCHAR(255) DEFAULT NULL, date_creation DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_18D2B0919BEA957A (entite_id), INDEX IDX_18D2B09173A201E5 (createur_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE chantier ADD CONSTRAINT FK_636F27F69BEA957A FOREIGN KEY (entite_id) REFERENCES entite (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE chantier ADD CONSTRAINT FK_636F27F673A201E5 FOREIGN KEY (createur_id) REFERENCES utilisateur (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE chantier_dechet ADD CONSTRAINT FK_850599FFD0C0049D FOREIGN KEY (chantier_id) REFERENCES chantier (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE chantier_dechet ADD CONSTRAINT FK_850599FFB93D2352 FOREIGN KEY (type_dechet_id) REFERENCES dechet_type (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE chantier_photo ADD CONSTRAINT FK_771F3B8D0C0049D FOREIGN KEY (chantier_id) REFERENCES chantier (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE chantier_ressource_engin ADD CONSTRAINT FK_5C5526AED0C0049D FOREIGN KEY (chantier_id) REFERENCES chantier (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE chantier_ressource_engin ADD CONSTRAINT FK_5C5526AEE58AF0C2 FOREIGN KEY (engin_id) REFERENCES engin (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE chantier_ressource_humaine ADD CONSTRAINT FK_E77B3C9DD0C0049D FOREIGN KEY (chantier_id) REFERENCES chantier (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE chantier_ressource_humaine ADD CONSTRAINT FK_E77B3C9DFB88E14F FOREIGN KEY (utilisateur_id) REFERENCES utilisateur (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE chantier_ressource_materiel ADD CONSTRAINT FK_9020D30AD0C0049D FOREIGN KEY (chantier_id) REFERENCES chantier (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE chantier_ressource_materiel ADD CONSTRAINT FK_9020D30A16880AAF FOREIGN KEY (materiel_id) REFERENCES materiel (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE chantier_zone ADD CONSTRAINT FK_A04361FD0C0049D FOREIGN KEY (chantier_id) REFERENCES chantier (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE dechet_type ADD CONSTRAINT FK_844061B79BEA957A FOREIGN KEY (entite_id) REFERENCES entite (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE dechet_type ADD CONSTRAINT FK_844061B773A201E5 FOREIGN KEY (createur_id) REFERENCES utilisateur (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE materiel ADD CONSTRAINT FK_18D2B0919BEA957A FOREIGN KEY (entite_id) REFERENCES entite (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE materiel ADD CONSTRAINT FK_18D2B09173A201E5 FOREIGN KEY (createur_id) REFERENCES utilisateur (id) ON DELETE RESTRICT');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE chantier DROP FOREIGN KEY FK_636F27F69BEA957A');
        $this->addSql('ALTER TABLE chantier DROP FOREIGN KEY FK_636F27F673A201E5');
        $this->addSql('ALTER TABLE chantier_dechet DROP FOREIGN KEY FK_850599FFD0C0049D');
        $this->addSql('ALTER TABLE chantier_dechet DROP FOREIGN KEY FK_850599FFB93D2352');
        $this->addSql('ALTER TABLE chantier_photo DROP FOREIGN KEY FK_771F3B8D0C0049D');
        $this->addSql('ALTER TABLE chantier_ressource_engin DROP FOREIGN KEY FK_5C5526AED0C0049D');
        $this->addSql('ALTER TABLE chantier_ressource_engin DROP FOREIGN KEY FK_5C5526AEE58AF0C2');
        $this->addSql('ALTER TABLE chantier_ressource_humaine DROP FOREIGN KEY FK_E77B3C9DD0C0049D');
        $this->addSql('ALTER TABLE chantier_ressource_humaine DROP FOREIGN KEY FK_E77B3C9DFB88E14F');
        $this->addSql('ALTER TABLE chantier_ressource_materiel DROP FOREIGN KEY FK_9020D30AD0C0049D');
        $this->addSql('ALTER TABLE chantier_ressource_materiel DROP FOREIGN KEY FK_9020D30A16880AAF');
        $this->addSql('ALTER TABLE chantier_zone DROP FOREIGN KEY FK_A04361FD0C0049D');
        $this->addSql('ALTER TABLE dechet_type DROP FOREIGN KEY FK_844061B79BEA957A');
        $this->addSql('ALTER TABLE dechet_type DROP FOREIGN KEY FK_844061B773A201E5');
        $this->addSql('ALTER TABLE materiel DROP FOREIGN KEY FK_18D2B0919BEA957A');
        $this->addSql('ALTER TABLE materiel DROP FOREIGN KEY FK_18D2B09173A201E5');
        $this->addSql('DROP TABLE chantier');
        $this->addSql('DROP TABLE chantier_dechet');
        $this->addSql('DROP TABLE chantier_photo');
        $this->addSql('DROP TABLE chantier_ressource_engin');
        $this->addSql('DROP TABLE chantier_ressource_humaine');
        $this->addSql('DROP TABLE chantier_ressource_materiel');
        $this->addSql('DROP TABLE chantier_zone');
        $this->addSql('DROP TABLE dechet_type');
        $this->addSql('DROP TABLE materiel');
    }
}
