<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260228105103 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE transaction_carte_alx DROP FOREIGN KEY FK_2BFA8707E58AF0C2');
        $this->addSql('ALTER TABLE transaction_carte_alx DROP FOREIGN KEY FK_2BFA8707FB88E14F');
        $this->addSql('ALTER TABLE transaction_carte_alx ADD CONSTRAINT FK_2BFA8707E58AF0C2 FOREIGN KEY (engin_id) REFERENCES engin (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE transaction_carte_alx ADD CONSTRAINT FK_2BFA8707FB88E14F FOREIGN KEY (utilisateur_id) REFERENCES utilisateur (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE transaction_carte_edenred DROP FOREIGN KEY FK_44B3E937E58AF0C2');
        $this->addSql('ALTER TABLE transaction_carte_edenred DROP FOREIGN KEY FK_44B3E937FB88E14F');
        $this->addSql('ALTER TABLE transaction_carte_edenred ADD CONSTRAINT FK_44B3E937E58AF0C2 FOREIGN KEY (engin_id) REFERENCES engin (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE transaction_carte_edenred ADD CONSTRAINT FK_44B3E937FB88E14F FOREIGN KEY (utilisateur_id) REFERENCES utilisateur (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE transaction_carte_total DROP FOREIGN KEY FK_F60980ACE58AF0C2');
        $this->addSql('ALTER TABLE transaction_carte_total DROP FOREIGN KEY FK_F60980ACFB88E14F');
        $this->addSql('ALTER TABLE transaction_carte_total ADD CONSTRAINT FK_F60980ACE58AF0C2 FOREIGN KEY (engin_id) REFERENCES engin (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE transaction_carte_total ADD CONSTRAINT FK_F60980ACFB88E14F FOREIGN KEY (utilisateur_id) REFERENCES utilisateur (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE transaction_carte_alx DROP FOREIGN KEY FK_2BFA8707E58AF0C2');
        $this->addSql('ALTER TABLE transaction_carte_alx DROP FOREIGN KEY FK_2BFA8707FB88E14F');
        $this->addSql('ALTER TABLE transaction_carte_alx ADD CONSTRAINT FK_2BFA8707E58AF0C2 FOREIGN KEY (engin_id) REFERENCES engin (id) ON UPDATE NO ACTION ON DELETE NO ACTION');
        $this->addSql('ALTER TABLE transaction_carte_alx ADD CONSTRAINT FK_2BFA8707FB88E14F FOREIGN KEY (utilisateur_id) REFERENCES utilisateur (id) ON UPDATE NO ACTION ON DELETE NO ACTION');
        $this->addSql('ALTER TABLE transaction_carte_edenred DROP FOREIGN KEY FK_44B3E937E58AF0C2');
        $this->addSql('ALTER TABLE transaction_carte_edenred DROP FOREIGN KEY FK_44B3E937FB88E14F');
        $this->addSql('ALTER TABLE transaction_carte_edenred ADD CONSTRAINT FK_44B3E937E58AF0C2 FOREIGN KEY (engin_id) REFERENCES engin (id) ON UPDATE NO ACTION ON DELETE NO ACTION');
        $this->addSql('ALTER TABLE transaction_carte_edenred ADD CONSTRAINT FK_44B3E937FB88E14F FOREIGN KEY (utilisateur_id) REFERENCES utilisateur (id) ON UPDATE NO ACTION ON DELETE NO ACTION');
        $this->addSql('ALTER TABLE transaction_carte_total DROP FOREIGN KEY FK_F60980ACE58AF0C2');
        $this->addSql('ALTER TABLE transaction_carte_total DROP FOREIGN KEY FK_F60980ACFB88E14F');
        $this->addSql('ALTER TABLE transaction_carte_total ADD CONSTRAINT FK_F60980ACE58AF0C2 FOREIGN KEY (engin_id) REFERENCES engin (id) ON UPDATE NO ACTION ON DELETE NO ACTION');
        $this->addSql('ALTER TABLE transaction_carte_total ADD CONSTRAINT FK_F60980ACFB88E14F FOREIGN KEY (utilisateur_id) REFERENCES utilisateur (id) ON UPDATE NO ACTION ON DELETE NO ACTION');
    }
}
