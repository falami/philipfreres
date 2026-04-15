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
            $this->addSql(<<<'SQL'
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
            SQL);

            $this->addSql('ALTER TABLE dechet ADD CONSTRAINT FK_53C0FC609BEA957A FOREIGN KEY (entite_id) REFERENCES entite (id) ON DELETE CASCADE');
            $this->addSql('ALTER TABLE dechet ADD CONSTRAINT FK_53C0FC6073A201E5 FOREIGN KEY (createur_id) REFERENCES utilisateur (id) ON DELETE RESTRICT');
        }

        // 2) Sur chantier_dechet, supprimer d'abord toute FK existante sur type_dechet_id
        //    qu'elle pointe vers dechet_type ou dechet, pour repartir proprement
        if ($sm->tablesExist(['chantier_dechet'])) {
            foreach ($sm->listTableForeignKeys('chantier_dechet') as $fk) {
                $localColumns = array_map('strtolower', $fk->getLocalColumns());
                $foreignTable = strtolower($fk->getForeignTableName());

                if ($localColumns === ['type_dechet_id'] && in_array($foreignTable, ['dechet_type', 'dechet'], true)) {
                    $this->addSql(sprintf(
                        'ALTER TABLE chantier_dechet DROP FOREIGN KEY %s',
                        $fk->getName()
                    ));
                }
            }
        }

        // 3) Supprimer dechet_type si elle existe encore
        //    Inutile de supprimer ses FKs sortantes avant DROP TABLE, MySQL les enlève avec la table.
        if ($sm->tablesExist(['dechet_type'])) {
            $this->addSql('DROP TABLE dechet_type');
        }

        // 4) Recréer la FK chantier_dechet.type_dechet_id -> dechet.id
        if ($sm->tablesExist(['chantier_dechet'])) {
            $fkExists = false;

            foreach ($sm->listTableForeignKeys('chantier_dechet') as $fk) {
                $localColumns = array_map('strtolower', $fk->getLocalColumns());
                $foreignTable = strtolower($fk->getForeignTableName());

                if ($localColumns === ['type_dechet_id'] && $foreignTable === 'dechet') {
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

        // 1) Supprimer la FK chantier_dechet.type_dechet_id -> dechet.id
        if ($sm->tablesExist(['chantier_dechet'])) {
            foreach ($sm->listTableForeignKeys('chantier_dechet') as $fk) {
                $localColumns = array_map('strtolower', $fk->getLocalColumns());
                $foreignTable = strtolower($fk->getForeignTableName());

                if ($localColumns === ['type_dechet_id'] && $foreignTable === 'dechet') {
                    $this->addSql(sprintf(
                        'ALTER TABLE chantier_dechet DROP FOREIGN KEY %s',
                        $fk->getName()
                    ));
                }
            }
        }

        // 2) Recréer dechet_type si absente
        if (!$sm->tablesExist(['dechet_type'])) {
            $this->addSql(<<<'SQL'
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
            SQL);

            $this->addSql('ALTER TABLE dechet_type ADD CONSTRAINT FK_844061B773A201E5 FOREIGN KEY (createur_id) REFERENCES utilisateur (id) ON DELETE NO ACTION');
            $this->addSql('ALTER TABLE dechet_type ADD CONSTRAINT FK_844061B79BEA957A FOREIGN KEY (entite_id) REFERENCES entite (id) ON DELETE CASCADE');
        }

        // 3) Supprimer la table dechet
        if ($sm->tablesExist(['dechet'])) {
            // Comme pour le up, les FKs enfant doivent être supprimées avant.
            // Les FKs portées par dechet lui-même seront supprimées avec DROP TABLE.
            $this->addSql('DROP TABLE dechet');
        }

        // 4) Recréer la FK chantier_dechet.type_dechet_id -> dechet_type.id
        if ($sm->tablesExist(['chantier_dechet'])) {
            $fkExists = false;

            foreach ($sm->listTableForeignKeys('chantier_dechet') as $fk) {
                $localColumns = array_map('strtolower', $fk->getLocalColumns());
                $foreignTable = strtolower($fk->getForeignTableName());

                if ($localColumns === ['type_dechet_id'] && $foreignTable === 'dechet_type') {
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
