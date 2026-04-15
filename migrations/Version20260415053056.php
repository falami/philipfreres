<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260415053056 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remplace dechet_type par dechet et rattache chantier_dechet.type_dechet_id à dechet.id';
    }

    public function up(Schema $schema): void
    {
        $sm = $this->connection->createSchemaManager();

        // 1) Créer la table dechet seulement si elle n'existe pas déjà
        if (!$sm->tablesExist(['dechet'])) {
            $this->addSql("
                CREATE TABLE dechet (
                    id INT AUTO_INCREMENT NOT NULL,
                    entite_id INT NOT NULL,
                    createur_id INT NOT NULL,
                    nom VARCHAR(140) NOT NULL,
                    unite VARCHAR(20) NOT NULL,
                    date_creation DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                    INDEX IDX_53C0FC609BEA957A (entite_id),
                    INDEX IDX_53C0FC6073A201E5 (createur_id),
                    PRIMARY KEY(id)
                ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            ");

            $this->addSql('ALTER TABLE dechet ADD CONSTRAINT FK_53C0FC609BEA957A FOREIGN KEY (entite_id) REFERENCES entite (id) ON DELETE CASCADE');
            $this->addSql('ALTER TABLE dechet ADD CONSTRAINT FK_53C0FC6073A201E5 FOREIGN KEY (createur_id) REFERENCES utilisateur (id) ON DELETE RESTRICT');
        }

        // 2) Supprimer proprement dechet_type si elle existe encore
        if ($sm->tablesExist(['dechet_type'])) {
            foreach ($sm->listTableForeignKeys('dechet_type') as $fk) {
                $this->addSql(sprintf(
                    'ALTER TABLE dechet_type DROP FOREIGN KEY %s',
                    $fk->getName()
                ));
            }

            $this->addSql('DROP TABLE dechet_type');
        }

        // 3) Ajouter la FK sur chantier_dechet.type_dechet_id -> dechet.id si absente
        if ($sm->tablesExist(['chantier_dechet'])) {
            $fkExists = false;

            foreach ($sm->listTableForeignKeys('chantier_dechet') as $fk) {
                if (
                    $fk->getLocalColumns() === ['type_dechet_id']
                    && strtolower($fk->getForeignTableName()) === 'dechet'
                ) {
                    $fkExists = true;
                    break;
                }
            }

            if (!$fkExists) {
                $this->addSql('ALTER TABLE chantier_dechet ADD CONSTRAINT FK_850599FFB93D2352 FOREIGN KEY (type_dechet_id) REFERENCES dechet (id) ON DELETE RESTRICT');
            }
        }
    }

    public function down(Schema $schema): void
    {
        $sm = $this->connection->createSchemaManager();

        if ($sm->tablesExist(['chantier_dechet'])) {
            foreach ($sm->listTableForeignKeys('chantier_dechet') as $fk) {
                if (
                    $fk->getLocalColumns() === ['type_dechet_id']
                    && strtolower($fk->getForeignTableName()) === 'dechet'
                ) {
                    $this->addSql(sprintf(
                        'ALTER TABLE chantier_dechet DROP FOREIGN KEY %s',
                        $fk->getName()
                    ));
                    break;
                }
            }
        }

        if (!$sm->tablesExist(['dechet_type'])) {
            $this->addSql("
                CREATE TABLE dechet_type (
                    id INT AUTO_INCREMENT NOT NULL,
                    entite_id INT NOT NULL,
                    createur_id INT NOT NULL,
                    nom VARCHAR(140) NOT NULL,
                    unite VARCHAR(20) NOT NULL,
                    date_creation DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                    INDEX IDX_844061B773A201E5 (createur_id),
                    INDEX IDX_844061B79BEA957A (entite_id),
                    PRIMARY KEY(id)
                ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            ");

            $this->addSql('ALTER TABLE dechet_type ADD CONSTRAINT FK_844061B773A201E5 FOREIGN KEY (createur_id) REFERENCES utilisateur (id) ON DELETE NO ACTION');
            $this->addSql('ALTER TABLE dechet_type ADD CONSTRAINT FK_844061B79BEA957A FOREIGN KEY (entite_id) REFERENCES entite (id) ON DELETE CASCADE');
        }

        if ($sm->tablesExist(['dechet'])) {
            foreach ($sm->listTableForeignKeys('dechet') as $fk) {
                $this->addSql(sprintf(
                    'ALTER TABLE dechet DROP FOREIGN KEY %s',
                    $fk->getName()
                ));
            }

            $this->addSql('DROP TABLE dechet');
        }

        if ($sm->tablesExist(['chantier_dechet'])) {
            $fkExists = false;

            foreach ($sm->listTableForeignKeys('chantier_dechet') as $fk) {
                if (
                    $fk->getLocalColumns() === ['type_dechet_id']
                    && strtolower($fk->getForeignTableName()) === 'dechet_type'
                ) {
                    $fkExists = true;
                    break;
                }
            }

            if (!$fkExists) {
                $this->addSql('ALTER TABLE chantier_dechet ADD CONSTRAINT FK_850599FFB93D2352 FOREIGN KEY (type_dechet_id) REFERENCES dechet_type (id) ON DELETE NO ACTION');
            }
        }
    }
}
