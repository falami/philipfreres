<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260226134935 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE engin_external_id (id INT AUTO_INCREMENT NOT NULL, engin_id INT NOT NULL, provider VARCHAR(255) NOT NULL, value VARCHAR(255) NOT NULL, active TINYINT(1) DEFAULT 1 NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', disabled_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', note VARCHAR(255) DEFAULT NULL, INDEX IDX_E2DB7E4CE58AF0C2 (engin_id), INDEX idx_engin_ext_provider_value (provider, value), UNIQUE INDEX uniq_engin_provider_value (engin_id, provider, value), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE utilisateur_external_id (id INT AUTO_INCREMENT NOT NULL, utilisateur_id INT NOT NULL, provider VARCHAR(255) NOT NULL, value VARCHAR(255) NOT NULL, active TINYINT(1) DEFAULT 1 NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', disabled_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', note VARCHAR(255) DEFAULT NULL, INDEX IDX_6B93DA23FB88E14F (utilisateur_id), INDEX idx_user_ext_provider_value (provider, value), UNIQUE INDEX uniq_user_provider_value (utilisateur_id, provider, value), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE engin_external_id ADD CONSTRAINT FK_E2DB7E4CE58AF0C2 FOREIGN KEY (engin_id) REFERENCES engin (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE utilisateur_external_id ADD CONSTRAINT FK_6B93DA23FB88E14F FOREIGN KEY (utilisateur_id) REFERENCES utilisateur (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE engin_affectation DROP FOREIGN KEY FK_8973C82F9BEA957A');
        $this->addSql('ALTER TABLE engin_affectation DROP FOREIGN KEY FK_8973C82FE58AF0C2');
        $this->addSql('ALTER TABLE engin_affectation DROP FOREIGN KEY FK_8973C82FFB88E14F');
        $this->addSql('ALTER TABLE engin_photo DROP FOREIGN KEY FK_8648163773A201E5');
        $this->addSql('ALTER TABLE engin_photo DROP FOREIGN KEY FK_864816379BEA957A');
        $this->addSql('ALTER TABLE engin_photo DROP FOREIGN KEY FK_86481637E58AF0C2');
        $this->addSql('DROP TABLE engin_affectation');
        $this->addSql('DROP TABLE engin_photo');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE engin_affectation (id INT AUTO_INCREMENT NOT NULL, entite_id INT NOT NULL, engin_id INT NOT NULL, utilisateur_id INT NOT NULL, date_debut DATE NOT NULL COMMENT \'(DC2Type:date_immutable)\', date_fin DATE DEFAULT NULL COMMENT \'(DC2Type:date_immutable)\', note VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_unicode_ci`, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_8973C82F9BEA957A (entite_id), INDEX IDX_8973C82FE58AF0C2 (engin_id), INDEX IDX_8973C82FFB88E14F (utilisateur_id), INDEX idx_affect_entite_engin (entite_id, engin_id), INDEX idx_affect_entite_user (entite_id, utilisateur_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('CREATE TABLE engin_photo (id INT AUTO_INCREMENT NOT NULL, engin_id INT NOT NULL, createur_id INT NOT NULL, entite_id INT NOT NULL, filename VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_unicode_ci`, alt VARCHAR(160) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_unicode_ci`, position SMALLINT UNSIGNED NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', date_creation DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_86481637E58AF0C2 (engin_id), INDEX IDX_8648163773A201E5 (createur_id), INDEX IDX_864816379BEA957A (entite_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('ALTER TABLE engin_affectation ADD CONSTRAINT FK_8973C82F9BEA957A FOREIGN KEY (entite_id) REFERENCES entite (id) ON UPDATE NO ACTION ON DELETE CASCADE');
        $this->addSql('ALTER TABLE engin_affectation ADD CONSTRAINT FK_8973C82FE58AF0C2 FOREIGN KEY (engin_id) REFERENCES engin (id) ON UPDATE NO ACTION ON DELETE CASCADE');
        $this->addSql('ALTER TABLE engin_affectation ADD CONSTRAINT FK_8973C82FFB88E14F FOREIGN KEY (utilisateur_id) REFERENCES utilisateur (id) ON UPDATE NO ACTION ON DELETE CASCADE');
        $this->addSql('ALTER TABLE engin_photo ADD CONSTRAINT FK_8648163773A201E5 FOREIGN KEY (createur_id) REFERENCES utilisateur (id) ON UPDATE NO ACTION');
        $this->addSql('ALTER TABLE engin_photo ADD CONSTRAINT FK_864816379BEA957A FOREIGN KEY (entite_id) REFERENCES entite (id) ON UPDATE NO ACTION');
        $this->addSql('ALTER TABLE engin_photo ADD CONSTRAINT FK_86481637E58AF0C2 FOREIGN KEY (engin_id) REFERENCES engin (id) ON UPDATE NO ACTION ON DELETE CASCADE');
        $this->addSql('ALTER TABLE engin_external_id DROP FOREIGN KEY FK_E2DB7E4CE58AF0C2');
        $this->addSql('ALTER TABLE utilisateur_external_id DROP FOREIGN KEY FK_6B93DA23FB88E14F');
        $this->addSql('DROP TABLE engin_external_id');
        $this->addSql('DROP TABLE utilisateur_external_id');
    }
}
