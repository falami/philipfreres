<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261016011000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Persist hashed, rotating and revocable remember-me tokens with an absolute 90-day lifetime.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE remembered_device (series VARCHAR(88) NOT NULL, username VARCHAR(180) NOT NULL, validator_hash VARCHAR(64) NOT NULL, sibling_validator_hashes JSON NOT NULL, previous_validator_hash VARCHAR(64) DEFAULT NULL, previous_sibling_validator_hashes JSON NOT NULL, previous_valid_until DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', credential_fingerprint VARCHAR(64) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', last_used DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX idx_remembered_device_username (username), INDEX idx_remembered_device_created (created_at), INDEX idx_remembered_device_last_used (last_used), PRIMARY KEY(series)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE remembered_device');
    }
}
