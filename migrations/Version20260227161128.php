<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260227161128 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // 0) Drop FK produit_external_id -> type_produit (si existe)
        $fkToType = $this->connection->fetchOne("
        SELECT rc.CONSTRAINT_NAME
        FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS rc
        WHERE rc.CONSTRAINT_SCHEMA = DATABASE()
          AND rc.TABLE_NAME = 'produit_external_id'
          AND rc.REFERENCED_TABLE_NAME = 'type_produit'
        LIMIT 1
    ");
        if ($fkToType) {
            $this->addSql(sprintf('ALTER TABLE produit_external_id DROP FOREIGN KEY %s', $fkToType));
        }

        // 0bis) Drop FK produit_external_id -> produit (si existe déjà) pour éviter collisions
        $fkToProduit = $this->connection->fetchOne("
        SELECT rc.CONSTRAINT_NAME
        FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS rc
        WHERE rc.CONSTRAINT_SCHEMA = DATABASE()
          AND rc.TABLE_NAME = 'produit_external_id'
          AND rc.REFERENCED_TABLE_NAME = 'produit'
        LIMIT 1
    ");
        if ($fkToProduit) {
            $this->addSql(sprintf('ALTER TABLE produit_external_id DROP FOREIGN KEY %s', $fkToProduit));
        }

        // 1) Etats des tables
        $existsType = (int) $this->connection->fetchOne("
        SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'type_produit'
    ");
        $existsProduit = (int) $this->connection->fetchOne("
        SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'produit'
    ");

        // 2) Cas A: type_produit existe, produit n'existe pas -> rename
        if ($existsType === 1 && $existsProduit === 0) {
            $this->addSql('RENAME TABLE type_produit TO produit');
        }

        // 3) Cas B: type_produit ET produit existent -> on MERGE type_produit dans produit (en conservant les ids)
        if ($existsType === 1 && $existsProduit === 1) {
            // Important: on copie les ids, donc produit.id doit accepter l'insert explicite (MySQL ok)
            $this->addSql("
            INSERT INTO produit (id, entite_id, categorie_produit, sous_categorie_produit)
            SELECT tp.id, tp.entite_id, tp.categorie_produit, tp.sous_categorie_produit
            FROM type_produit tp
            ON DUPLICATE KEY UPDATE
              entite_id = VALUES(entite_id),
              categorie_produit = VALUES(categorie_produit),
              sous_categorie_produit = VALUES(sous_categorie_produit)
        ");

            // remettre l'AUTO_INCREMENT au max(id)+1
            $this->addSql("
            SET @next_ai := (SELECT COALESCE(MAX(id),0)+1 FROM produit);
        ");
            $this->addSql("SET @sql := CONCAT('ALTER TABLE produit AUTO_INCREMENT = ', @next_ai);");
            $this->addSql("PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;");

            // on supprime l'ancienne table
            $this->addSql('DROP TABLE type_produit');
        }

        // 4) Sécurité: si des produit_id existent dans produit_external_id mais pas dans produit => on crée des produits "placeholder"
        // ⚠️ adapte les valeurs si tes enums ne stockent pas "CARBURANT"/"GASOIL" en DB.
        $this->addSql("
        INSERT INTO produit (id, entite_id, categorie_produit, sous_categorie_produit)
        SELECT DISTINCT pei.produit_id, NULL, 'CARBURANT', 'GASOIL'
        FROM produit_external_id pei
        LEFT JOIN produit p ON p.id = pei.produit_id
        WHERE pei.produit_id IS NOT NULL
          AND p.id IS NULL
    ");

        // 5) Recréation FK produit_external_id -> produit
        $this->addSql("
        ALTER TABLE produit_external_id
        ADD CONSTRAINT FK_E16D9334F347EFB
        FOREIGN KEY (produit_id) REFERENCES produit (id) ON DELETE CASCADE
    ");
    }

    public function down(Schema $schema): void
    {
        // drop FK vers produit
        $fk = $this->connection->fetchOne("
      SELECT rc.CONSTRAINT_NAME
      FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS rc
      WHERE rc.CONSTRAINT_SCHEMA = DATABASE()
        AND rc.TABLE_NAME = 'produit_external_id'
        AND rc.REFERENCED_TABLE_NAME = 'produit'
      LIMIT 1
    ");
        if ($fk) {
            $this->addSql(sprintf('ALTER TABLE produit_external_id DROP FOREIGN KEY %s', $fk));
        }

        // rename produit -> type_produit
        $existsProduit = $this->connection->fetchOne("
      SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'produit'
    ");
        $existsType = $this->connection->fetchOne("
      SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'type_produit'
    ");

        if ((int)$existsProduit === 1 && (int)$existsType === 0) {
            $this->addSql('RENAME TABLE produit TO type_produit');
        }

        // recreate FK vers type_produit
        $fk2 = $this->connection->fetchOne("
      SELECT rc.CONSTRAINT_NAME
      FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS rc
      WHERE rc.CONSTRAINT_SCHEMA = DATABASE()
        AND rc.TABLE_NAME = 'produit_external_id'
        AND rc.REFERENCED_TABLE_NAME = 'type_produit'
      LIMIT 1
    ");

        if (!$fk2) {
            $this->addSql('ALTER TABLE produit_external_id ADD CONSTRAINT FK_E16D9334F347EFB FOREIGN KEY (produit_id) REFERENCES type_produit (id) ON DELETE CASCADE');
        }
    }
}
