<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Entite;
use App\Repository\FuelDashboardRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Executes the summary SQL on synthetic rows, without reading application tables. */
final class FuelDashboardRepositoryTest extends TestCase
{
    private Connection $engine;

    protected function setUp(): void
    {
        // Optional real MySQL validation: no database selected and no schema writes.
        $socket = getenv('DASHBOARD_TEST_MYSQL_SOCKET');
        if ($socket) {
            $this->engine = DriverManager::getConnection([
                'driver' => 'pdo_mysql', 'unix_socket' => $socket,
                'user' => getenv('DASHBOARD_TEST_MYSQL_USER') ?: '',
                'password' => getenv('DASHBOARD_TEST_MYSQL_PASSWORD') ?: '',
                'charset' => 'utf8mb4',
            ]);
        } else {
            $this->engine = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
            $this->engine->getNativeConnection()->sqliteCreateFunction(
                'DATE_FORMAT', static fn (string $date, string $format): string => substr($date, 0, 7), 2
            );
        }
    }

    protected function tearDown(): void
    {
        $this->engine->close();
        parent::tearDown();
    }

    #[DataProvider('filterCases')]
    public function testSummaryPreservesMetricsAndFiltersWithAtMostSevenQueries(array $filters, int $tenantId, array $selected): void
    {
        $rows = self::rows();
        $fixtureSql = implode(' UNION ALL ', array_map(function (array $row): string {
            $columns = [];
            foreach ($row as $name => $value) {
                $literal = match (true) {
                    $value === null => 'NULL',
                    is_int($value) => 'CAST('.$value.' AS SIGNED)',
                    is_float($value) => 'CAST('.$value.' AS DECIMAL(12,3))',
                    default => $this->engine->quote($value),
                };
                $columns[] = $literal.' AS '.$name;
            }
            return 'SELECT '.implode(', ', $columns);
        }, $rows));

        $db = $this->createMock(Connection::class);
        $repository = new FuelDashboardRepository($db);
        $union = (new \ReflectionMethod($repository, 'unionSql'))->invoke($repository);
        $queries = 0;
        foreach (['fetchAssociative', 'fetchAllAssociative'] as $method) {
            $db->method($method)->willReturnCallback(function (string $sql, array $params) use ($method, $union, $fixtureSql, &$queries) {
                ++$queries;
                $sql = str_replace($union, $fixtureSql, $sql, $replacements);
                self::assertSame(1, $replacements, 'Every summary query must use only the synthetic rows.');
                return $this->engine->$method($sql, $params);
            });
        }
        $tenant = new Entite();
        (new \ReflectionProperty($tenant, 'id'))->setValue($tenant, $tenantId);
        $result = $repository->summary($tenant, $filters + [
            'dateStart' => '2026-01-01 00:00:00', 'dateEnd' => '2026-12-31 23:59:59',
        ]);

        $expected = [
            'qty' => 0.0, 'amount_cents' => 0, 'cnt' => count($selected),
            'cnt_unmatched_engin' => 0, 'cnt_unmatched_employe' => 0,
            'total_gasoil' => 0.0, 'total_gnr' => 0.0, 'total_adblue' => 0.0, 'total_peage_cents' => 0,
        ];
        $employees = [];
        foreach ($selected as $id) {
            $row = $rows[$id - 1];
            $expected['qty'] += $row['qty'];
            $expected['amount_cents'] += $row['amount_cents'];
            $expected['cnt_unmatched_engin'] += (int) ($row['engin_id'] === null);
            $expected['cnt_unmatched_employe'] += (int) ($row['employe_id'] === null);
            if (in_array($row['produit_sous'], ['gasoil', 'gnr', 'adblue'], true)) {
                $expected['total_'.$row['produit_sous']] += $row['qty'];
            }
            if ($row['produit_sous'] === 'peage') {
                $expected['total_peage_cents'] += $row['amount_cents'];
            }
            $employee = $row['employe_id'] ?? 0;
            $employees[$employee] ??= ['alx' => 0.0, 'total' => 0.0, 'edenred' => 0.0, 'note' => 0.0];
            $employees[$employee][$row['provider']] += $row['qty'];
        }
        self::assertSame($expected, $result['kpis']);
        self::assertSame($selected === [] ? 6 : 7, $queries);
        $charts = $result['charts'];
        // The existing compatibility names both expose quantities; preserve this contract.
        self::assertSame($charts['byEmployeeProviderAmount'], $charts['byEmployeeProviderQty']);
        self::assertSame($charts['topEmployes'], $charts['byEmployee']);
        self::assertCount(count($employees), $charts['byEmployeeProviderQty']);
        foreach ($charts['byEmployeeProviderQty'] as $employee) {
            self::assertArrayHasKey((int) $employee['filter_id'], $employees);
            foreach ($employees[(int) $employee['filter_id']] as $provider => $quantity) {
                self::assertSame($quantity, (float) $employee[$provider]);
            }
        }
        if ($selected === []) {
            foreach ($charts as $chart) {
                self::assertSame([], $chart);
            }
        }
    }

    public static function filterCases(): iterable
    {
        yield 'all providers with nulls and signed quantities' => [[], 1, range(1, 9)];
        yield 'other tenant' => [[], 2, [10]];
        yield 'unknown tenant' => [[], 99, []];
        yield 'empty date range' => [['dateStart' => '2027-06-01', 'dateEnd' => '2027-06-30'], 1, []];
        yield 'inclusive timestamp boundary' => [['dateStart' => '2026-12-31 23:59:59'], 1, [9]];
        yield 'normalized providers' => [['providers' => [' ALX ', 'total', 'TOTAL']], 1, [1, 2, 5, 9]];
        yield 'edenred only' => [['providers' => ['EDENRED']], 1, [3, 6]];
        yield 'notes only' => [['providers' => ['NOTE']], 1, [4, 7, 8]];
        yield 'engine ids' => [['enginIds' => [11]], 1, [1, 3, 8, 9]];
        yield 'unmatched engines' => [['enginUncategorized' => true], 1, [4, 6, 7]];
        yield 'engine ids or null' => [['enginIds' => [11], 'enginUncategorized' => true], 1, [1, 3, 4, 6, 7, 8, 9]];
        yield 'employee ids' => [['employeIds' => [22]], 1, [2, 6]];
        yield 'unmatched employees' => [['employeUncategorized' => true], 1, [4, 7, 8]];
        yield 'product ids' => [['produitIds' => [31, 34]], 1, [1, 4, 9]];
        yield 'category' => [['categorieProduits' => ['carburant']], 1, [1, 2, 3, 5, 6, 9]];
        yield 'null or empty category' => [['categorieUncategorized' => true], 1, [7, 8]];
        yield 'subcategory' => [['sousCategorieProduits' => ['gasoil', 'peage']], 1, [1, 4, 9]];
        yield 'subcategory or uncategorized' => [['sousCategorieProduits' => ['gasoil'], 'sousUncategorized' => true], 1, [1, 7, 8, 9]];
        yield 'combined filters' => [['providers' => ['TOTAL'], 'employeIds' => [21], 'enginIds' => [12]], 1, [5]];
        foreach (['providersNone', 'enginNone', 'employeNone', 'categorieNone', 'sousCategorieNone'] as $none) {
            yield $none => [[$none => true], 1, []];
        }
    }

    private static function rows(): array
    {
        $columns = ['entite_id', 'provider', 'date_tx', 'qty', 'amount_cents', 'engin_id', 'employe_id', 'produit_id', 'produit_cat', 'produit_sous', 'label'];
        $data = [
            [1, 'alx', '2026-01-01 00:00:00', 10.125, 1800, 11, 21, 31, 'carburant', 'gasoil', 'Gasoil'],
            [1, 'total', '2026-02-01 12:00:00', 20.25, 4100, 12, 22, 32, 'carburant', 'gnr', 'GNR'],
            [1, 'edenred', '2026-02-02 12:00:00', 3.5, 600, 11, 21, 33, 'carburant', 'adblue', 'AdBlue'],
            [1, 'note', '2026-03-03 12:00:00', 0.0, 1250, null, null, 34, 'route', 'peage', 'Péage'],
            [1, 'total', '2026-04-04 12:00:00', 8.0, 1700, 12, 21, 35, 'carburant', 'essence', 'Sans plomb'],
            [1, 'edenred', '2026-05-05 12:00:00', 9.0, 1800, null, 22, 35, 'carburant', 'essence', 'SP95 - E10'],
            [1, 'note', '2026-06-06 12:00:00', 2.0, 99, null, null, null, null, null, 'Non rattaché'],
            [1, 'note', '2026-07-07 12:00:00', 1.25, 44, 11, null, 36, '', '', 'Sans catégorie'],
            [1, 'alx', '2026-12-31 23:59:59', -1.0, -180, 11, 21, 31, 'carburant', 'gasoil', 'Correction'],
            [2, 'alx', '2026-01-01 00:00:00', 999.0, 99999, 13, 23, 31, 'carburant', 'gasoil', 'Autre entité'],
            [1, 'alx', '2025-12-31 23:59:59', 777.0, 77777, 11, 21, 31, 'carburant', 'gasoil', 'Avant période'],
            [1, 'alx', '2027-01-01 00:00:00', 888.0, 88888, 11, 21, 31, 'carburant', 'gasoil', 'Après période'],
        ];
        return array_map(static function (array $values) use ($columns): array {
            $row = array_combine($columns, $values);
            $row['engin_label'] = $row['engin_id'] === null ? null : 'Engin '.$row['engin_id'];
            $row['employe_label'] = $row['employe_id'] === null ? ' ' : 'Employé '.$row['employe_id'];
            return $row;
        }, $data);
    }
}
