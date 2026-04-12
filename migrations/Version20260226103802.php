<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260226103802 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE engin_affectation (id INT AUTO_INCREMENT NOT NULL, entite_id INT NOT NULL, engin_id INT NOT NULL, utilisateur_id INT NOT NULL, date_debut DATE NOT NULL COMMENT \'(DC2Type:date_immutable)\', date_fin DATE DEFAULT NULL COMMENT \'(DC2Type:date_immutable)\', note VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_8973C82F9BEA957A (entite_id), INDEX IDX_8973C82FE58AF0C2 (engin_id), INDEX IDX_8973C82FFB88E14F (utilisateur_id), INDEX idx_affect_entite_engin (entite_id, engin_id), INDEX idx_affect_entite_user (entite_id, utilisateur_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE engin_affectation ADD CONSTRAINT FK_8973C82F9BEA957A FOREIGN KEY (entite_id) REFERENCES entite (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE engin_affectation ADD CONSTRAINT FK_8973C82FE58AF0C2 FOREIGN KEY (engin_id) REFERENCES engin (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE engin_affectation ADD CONSTRAINT FK_8973C82FFB88E14F FOREIGN KEY (utilisateur_id) REFERENCES utilisateur (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE engin ADD immatriculation VARCHAR(64) DEFAULT NULL, ADD nom_alx VARCHAR(180) DEFAULT NULL, ADD nom_total VARCHAR(255) DEFAULT NULL, ADD nom_edenred VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE transaction_carte_alx ADD engin_id INT DEFAULT NULL, ADD utilisateur_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE transaction_carte_alx ADD CONSTRAINT FK_2BFA8707E58AF0C2 FOREIGN KEY (engin_id) REFERENCES engin (id)');
        $this->addSql('ALTER TABLE transaction_carte_alx ADD CONSTRAINT FK_2BFA8707FB88E14F FOREIGN KEY (utilisateur_id) REFERENCES utilisateur (id)');
        $this->addSql('CREATE INDEX IDX_2BFA8707E58AF0C2 ON transaction_carte_alx (engin_id)');
        $this->addSql('CREATE INDEX IDX_2BFA8707FB88E14F ON transaction_carte_alx (utilisateur_id)');
        $this->addSql('ALTER TABLE transaction_carte_edenred ADD engin_id INT DEFAULT NULL, ADD utilisateur_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE transaction_carte_edenred ADD CONSTRAINT FK_44B3E937E58AF0C2 FOREIGN KEY (engin_id) REFERENCES engin (id)');
        $this->addSql('ALTER TABLE transaction_carte_edenred ADD CONSTRAINT FK_44B3E937FB88E14F FOREIGN KEY (utilisateur_id) REFERENCES utilisateur (id)');
        $this->addSql('CREATE INDEX IDX_44B3E937E58AF0C2 ON transaction_carte_edenred (engin_id)');
        $this->addSql('CREATE INDEX IDX_44B3E937FB88E14F ON transaction_carte_edenred (utilisateur_id)');
        $this->addSql('ALTER TABLE transaction_carte_total ADD engin_id INT DEFAULT NULL, ADD utilisateur_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE transaction_carte_total ADD CONSTRAINT FK_F60980ACE58AF0C2 FOREIGN KEY (engin_id) REFERENCES engin (id)');
        $this->addSql('ALTER TABLE transaction_carte_total ADD CONSTRAINT FK_F60980ACFB88E14F FOREIGN KEY (utilisateur_id) REFERENCES utilisateur (id)');
        $this->addSql('CREATE INDEX IDX_F60980ACE58AF0C2 ON transaction_carte_total (engin_id)');
        $this->addSql('CREATE INDEX IDX_F60980ACFB88E14F ON transaction_carte_total (utilisateur_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE engin_affectation DROP FOREIGN KEY FK_8973C82F9BEA957A');
        $this->addSql('ALTER TABLE engin_affectation DROP FOREIGN KEY FK_8973C82FE58AF0C2');
        $this->addSql('ALTER TABLE engin_affectation DROP FOREIGN KEY FK_8973C82FFB88E14F');
        $this->addSql('DROP TABLE engin_affectation');
        $this->addSql('ALTER TABLE engin DROP immatriculation, DROP nom_alx, DROP nom_total, DROP nom_edenred');
        $this->addSql('ALTER TABLE transaction_carte_alx DROP FOREIGN KEY FK_2BFA8707E58AF0C2');
        $this->addSql('ALTER TABLE transaction_carte_alx DROP FOREIGN KEY FK_2BFA8707FB88E14F');
        $this->addSql('DROP INDEX IDX_2BFA8707E58AF0C2 ON transaction_carte_alx');
        $this->addSql('DROP INDEX IDX_2BFA8707FB88E14F ON transaction_carte_alx');
        $this->addSql('ALTER TABLE transaction_carte_alx DROP engin_id, DROP utilisateur_id');
        $this->addSql('ALTER TABLE transaction_carte_edenred DROP FOREIGN KEY FK_44B3E937E58AF0C2');
        $this->addSql('ALTER TABLE transaction_carte_edenred DROP FOREIGN KEY FK_44B3E937FB88E14F');
        $this->addSql('DROP INDEX IDX_44B3E937E58AF0C2 ON transaction_carte_edenred');
        $this->addSql('DROP INDEX IDX_44B3E937FB88E14F ON transaction_carte_edenred');
        $this->addSql('ALTER TABLE transaction_carte_edenred DROP engin_id, DROP utilisateur_id');
        $this->addSql('ALTER TABLE transaction_carte_total DROP FOREIGN KEY FK_F60980ACE58AF0C2');
        $this->addSql('ALTER TABLE transaction_carte_total DROP FOREIGN KEY FK_F60980ACFB88E14F');
        $this->addSql('DROP INDEX IDX_F60980ACE58AF0C2 ON transaction_carte_total');
        $this->addSql('DROP INDEX IDX_F60980ACFB88E14F ON transaction_carte_total');
        $this->addSql('ALTER TABLE transaction_carte_total DROP engin_id, DROP utilisateur_id');
    }
}
