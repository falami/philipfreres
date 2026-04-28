<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260428110411 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Déplace les ressources, déchets et photos chantier vers les zones/sous-chantiers.';
    }

    public function up(Schema $schema): void
    {
        $sm = $this->connection->createSchemaManager();

        $chantierColumns = $sm->listTableColumns('chantier');
        $zoneColumns = $sm->listTableColumns('chantier_zone');

        $zoneAdds = [];

        if (!isset($zoneColumns['nature_prestation'])) {
            $zoneAdds[] = 'ADD nature_prestation LONGTEXT DEFAULT NULL';
        }
        if (!isset($zoneColumns['date_debut_previsionnelle'])) {
            $zoneAdds[] = 'ADD date_debut_previsionnelle DATETIME DEFAULT NULL';
        }
        if (!isset($zoneColumns['date_fin_previsionnelle'])) {
            $zoneAdds[] = 'ADD date_fin_previsionnelle DATETIME DEFAULT NULL';
        }
        if (!isset($zoneColumns['date_debut_reelle'])) {
            $zoneAdds[] = 'ADD date_debut_reelle DATETIME DEFAULT NULL';
        }
        if (!isset($zoneColumns['date_fin_reelle'])) {
            $zoneAdds[] = 'ADD date_fin_reelle DATETIME DEFAULT NULL';
        }
        if (!isset($zoneColumns['surface_traitee'])) {
            $zoneAdds[] = 'ADD surface_traitee NUMERIC(10, 2) DEFAULT NULL';
        }
        if (!isset($zoneColumns['lineaire_traite'])) {
            $zoneAdds[] = 'ADD lineaire_traite NUMERIC(10, 2) DEFAULT NULL';
        }
        if (!isset($zoneColumns['difficultes_rencontrees'])) {
            $zoneAdds[] = 'ADD difficultes_rencontrees LONGTEXT DEFAULT NULL';
        }

        if ($zoneAdds !== []) {
            $this->addSql('ALTER TABLE chantier_zone ' . implode(', ', $zoneAdds));
        }

        $hasNature = isset($chantierColumns['nature_prestation']);
        $hasSurface = isset($chantierColumns['surface_traitee']);
        $hasLineaire = isset($chantierColumns['lineaire_traite']);

        $this->addSql(sprintf(
            "
            INSERT INTO chantier_zone (
                chantier_id,
                nom,
                parcelle,
                ordre,
                nature_prestation,
                date_debut_previsionnelle,
                date_fin_previsionnelle,
                date_debut_reelle,
                date_fin_reelle,
                surface_traitee,
                lineaire_traite,
                difficultes_rencontrees
            )
            SELECT
                c.id,
                CONCAT('Parcelle principale - ', c.nom),
                NULL,
                1,
                %s,
                c.date_debut_previsionnelle,
                c.date_fin_previsionnelle,
                c.date_debut_reelle,
                c.date_fin_reelle,
                %s,
                %s,
                c.difficultes_rencontrees
            FROM chantier c
            WHERE NOT EXISTS (
                SELECT 1
                FROM chantier_zone z
                WHERE z.chantier_id = c.id
            )
        ",
            $hasNature ? 'c.nature_prestation' : 'NULL',
            $hasSurface ? 'c.surface_traitee' : 'NULL',
            $hasLineaire ? 'c.lineaire_traite' : 'NULL'
        ));

        $this->addSql("
            CREATE TEMPORARY TABLE tmp_chantier_zone_map AS
            SELECT 
                c.id AS chantier_id,
                MIN(z.id) AS zone_id
            FROM chantier c
            INNER JOIN chantier_zone z ON z.chantier_id = c.id
            GROUP BY c.id
        ");

        $this->migrateChildTable(
            table: 'chantier_dechet',
            oldFk: 'FK_850599FFD0C0049D',
            oldIndex: 'IDX_850599FFD0C0049D',
            newFk: 'FK_850599FF9F2C3FAB',
            newIndex: 'IDX_850599FF9F2C3FAB',
            renameSql: 'CHANGE poids_total quantite NUMERIC(10, 2) DEFAULT NULL'
        );

        $this->migrateChildTable(
            table: 'chantier_photo',
            oldFk: 'FK_771F3B8D0C0049D',
            oldIndex: 'IDX_771F3B8D0C0049D',
            newFk: 'FK_771F3B89F2C3FAB',
            newIndex: 'IDX_771F3B89F2C3FAB'
        );

        $this->migrateChildTable(
            table: 'chantier_ressource_engin',
            oldFk: 'FK_5C5526AED0C0049D',
            oldIndex: 'IDX_5C5526AED0C0049D',
            newFk: 'FK_5C5526AE9F2C3FAB',
            newIndex: 'IDX_5C5526AE9F2C3FAB'
        );

        $this->migrateChildTable(
            table: 'chantier_ressource_humaine',
            oldFk: 'FK_E77B3C9DD0C0049D',
            oldIndex: 'IDX_E77B3C9DD0C0049D',
            newFk: 'FK_E77B3C9D9F2C3FAB',
            newIndex: 'IDX_E77B3C9D9F2C3FAB'
        );

        $this->migrateChildTable(
            table: 'chantier_ressource_materiel',
            oldFk: 'FK_9020D30AD0C0049D',
            oldIndex: 'IDX_9020D30AD0C0049D',
            newFk: 'FK_9020D30A9F2C3FAB',
            newIndex: 'IDX_9020D30A9F2C3FAB'
        );

        $chantierColumns = $sm->listTableColumns('chantier');
        $drops = [];

        foreach (['nature_prestation', 'surface_traitee', 'lineaire_traite'] as $column) {
            if (isset($chantierColumns[$column])) {
                $drops[] = 'DROP ' . $column;
            }
        }

        if ($drops !== []) {
            $this->addSql('ALTER TABLE chantier ' . implode(', ', $drops));
        }
    }

    private function migrateChildTable(
        string $table,
        string $oldFk,
        string $oldIndex,
        string $newFk,
        string $newIndex,
        ?string $renameSql = null
    ): void {
        $columns = $this->connection->createSchemaManager()->listTableColumns($table);

        if (isset($columns['chantier_id'])) {
            $this->addSql(sprintf('ALTER TABLE %s DROP FOREIGN KEY %s', $table, $oldFk));
            $this->addSql(sprintf('DROP INDEX %s ON %s', $oldIndex, $table));

            if (!isset($columns['zone_id'])) {
                $this->addSql(sprintf('ALTER TABLE %s ADD zone_id INT DEFAULT NULL', $table));
            }

            $this->addSql(sprintf("
                UPDATE %s child
                INNER JOIN tmp_chantier_zone_map m ON m.chantier_id = child.chantier_id
                SET child.zone_id = m.zone_id
            ", $table));

            $this->addSql(sprintf('ALTER TABLE %s DROP chantier_id', $table));
        }

        if ($renameSql !== null) {
            $columns = $this->connection->createSchemaManager()->listTableColumns($table);

            if (isset($columns['poids_total']) && !isset($columns['quantite'])) {
                $this->addSql(sprintf('ALTER TABLE %s %s', $table, $renameSql));
            }
        }

        $this->addSql(sprintf('ALTER TABLE %s CHANGE zone_id zone_id INT NOT NULL', $table));
        $this->addSql(sprintf(
            'ALTER TABLE %s ADD CONSTRAINT %s FOREIGN KEY (zone_id) REFERENCES chantier_zone (id) ON DELETE CASCADE',
            $table,
            $newFk
        ));
        $this->addSql(sprintf('CREATE INDEX %s ON %s (zone_id)', $newIndex, $table));
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE chantier ADD nature_prestation LONGTEXT DEFAULT NULL, ADD surface_traitee NUMERIC(10, 2) DEFAULT NULL, ADD lineaire_traite NUMERIC(10, 2) DEFAULT NULL');

        $this->addSql('ALTER TABLE chantier_dechet DROP FOREIGN KEY FK_850599FF9F2C3FAB');
        $this->addSql('DROP INDEX IDX_850599FF9F2C3FAB ON chantier_dechet');
        $this->addSql('ALTER TABLE chantier_dechet CHANGE zone_id chantier_id INT NOT NULL, CHANGE quantite poids_total NUMERIC(10, 2) DEFAULT NULL');
        $this->addSql('ALTER TABLE chantier_dechet ADD CONSTRAINT FK_850599FFD0C0049D FOREIGN KEY (chantier_id) REFERENCES chantier (id) ON DELETE CASCADE');
        $this->addSql('CREATE INDEX IDX_850599FFD0C0049D ON chantier_dechet (chantier_id)');

        $this->addSql('ALTER TABLE chantier_photo DROP FOREIGN KEY FK_771F3B89F2C3FAB');
        $this->addSql('DROP INDEX IDX_771F3B89F2C3FAB ON chantier_photo');
        $this->addSql('ALTER TABLE chantier_photo CHANGE zone_id chantier_id INT NOT NULL');
        $this->addSql('ALTER TABLE chantier_photo ADD CONSTRAINT FK_771F3B8D0C0049D FOREIGN KEY (chantier_id) REFERENCES chantier (id) ON DELETE CASCADE');
        $this->addSql('CREATE INDEX IDX_771F3B8D0C0049D ON chantier_photo (chantier_id)');

        $this->addSql('ALTER TABLE chantier_ressource_engin DROP FOREIGN KEY FK_5C5526AE9F2C3FAB');
        $this->addSql('DROP INDEX IDX_5C5526AE9F2C3FAB ON chantier_ressource_engin');
        $this->addSql('ALTER TABLE chantier_ressource_engin CHANGE zone_id chantier_id INT NOT NULL');
        $this->addSql('ALTER TABLE chantier_ressource_engin ADD CONSTRAINT FK_5C5526AED0C0049D FOREIGN KEY (chantier_id) REFERENCES chantier (id) ON DELETE CASCADE');
        $this->addSql('CREATE INDEX IDX_5C5526AED0C0049D ON chantier_ressource_engin (chantier_id)');

        $this->addSql('ALTER TABLE chantier_ressource_humaine DROP FOREIGN KEY FK_E77B3C9D9F2C3FAB');
        $this->addSql('DROP INDEX IDX_E77B3C9D9F2C3FAB ON chantier_ressource_humaine');
        $this->addSql('ALTER TABLE chantier_ressource_humaine CHANGE zone_id chantier_id INT NOT NULL');
        $this->addSql('ALTER TABLE chantier_ressource_humaine ADD CONSTRAINT FK_E77B3C9DD0C0049D FOREIGN KEY (chantier_id) REFERENCES chantier (id) ON DELETE CASCADE');
        $this->addSql('CREATE INDEX IDX_E77B3C9DD0C0049D ON chantier_ressource_humaine (chantier_id)');

        $this->addSql('ALTER TABLE chantier_ressource_materiel DROP FOREIGN KEY FK_9020D30A9F2C3FAB');
        $this->addSql('DROP INDEX IDX_9020D30A9F2C3FAB ON chantier_ressource_materiel');
        $this->addSql('ALTER TABLE chantier_ressource_materiel CHANGE zone_id chantier_id INT NOT NULL');
        $this->addSql('ALTER TABLE chantier_ressource_materiel ADD CONSTRAINT FK_9020D30AD0C0049D FOREIGN KEY (chantier_id) REFERENCES chantier (id) ON DELETE CASCADE');
        $this->addSql('CREATE INDEX IDX_9020D30AD0C0049D ON chantier_ressource_materiel (chantier_id)');

        $this->addSql('ALTER TABLE chantier_zone DROP nature_prestation, DROP date_debut_previsionnelle, DROP date_fin_previsionnelle, DROP date_debut_reelle, DROP date_fin_reelle, DROP surface_traitee, DROP lineaire_traite, DROP difficultes_rencontrees');
    }
}
