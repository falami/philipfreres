<?php

declare(strict_types=1);

namespace App\Tests\Deployment;

use Doctrine\DBAL\Connection;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Metadata\AvailableMigration;
use Doctrine\Migrations\Metadata\AvailableMigrationsList;
use Doctrine\Migrations\Metadata\ExecutedMigration;
use Doctrine\Migrations\Metadata\ExecutedMigrationsList;
use Doctrine\Migrations\Metadata\Storage\MetadataStorage;
use Doctrine\Migrations\Version\MigrationPlanCalculator;
use Doctrine\Migrations\Version\Version;
use PhilipFreres\Deployment\DeploymentCheckFailed;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function PhilipFreres\Deployment\databaseName;
use function PhilipFreres\Deployment\databaseSize;
use function PhilipFreres\Deployment\assertProductionSecurity;
use function PhilipFreres\Deployment\assertPrivateConfigurationExists;
use function PhilipFreres\Deployment\configurationFingerprint;
use function PhilipFreres\Deployment\mysqlClientOptions;
use function PhilipFreres\Deployment\pendingMigrations;
use function PhilipFreres\Deployment\preflightDatabase;
use function PhilipFreres\Deployment\quoteMysqlOption;
use function PhilipFreres\Deployment\readMigrationAllowlist;
use function PhilipFreres\Deployment\writePrivateOptions;

require_once dirname(__DIR__, 2).'/scripts/deploy-database.php';

final class DeployDatabaseTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/philip-deploy-test-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
    }

    protected function tearDown(): void
    {
        (new \Symfony\Component\Filesystem\Filesystem())->remove($this->directory);
    }

    public function testPasswordsCannotInjectAnOptionOrSection(): void
    {
        $password = " lead #semi; \"quoted\" 'single' \\trail\n[client]\nuser=attacker\r\t\x08 ";
        $contents = mysqlClientOptions($this->params(['password' => $password]));

        self::assertSame(1, substr_count($contents, "[client]\n"));
        self::assertSame(1, preg_match_all('/^user=/m', $contents));
        self::assertStringContainsString('password='.quoteMysqlOption($password)."\n", $contents);
        self::assertStringContainsString('\\n[client]\\nuser=attacker\\r\\t\\b', $contents);
        self::assertStringContainsString('\\"quoted\\"', $contents);
        self::assertStringContainsString('\\\\trail', $contents);
    }

    public function testNulPasswordIsRejected(): void
    {
        $this->expectException(DeploymentCheckFailed::class);
        mysqlClientOptions($this->params(['password' => "invalid\0password"]));
    }

    #[DataProvider('unsupportedConnections')]
    public function testCustomConnectionsCannotBeSilentlyDowngraded(array $overrides): void
    {
        $this->expectException(DeploymentCheckFailed::class);
        mysqlClientOptions($this->params($overrides));
    }

    public static function unsupportedConnections(): iterable
    {
        yield 'other driver' => [['driver' => 'pdo_pgsql']];
        yield 'custom wrapper driver' => [['driverClass' => 'CustomDriver']];
        yield 'replica' => [['replica' => [['host' => 'replica']]]];
        yield 'TLS PDO option' => [['driverOptions' => [1009 => '/cert.pem']]];
        yield 'TLS flag' => [['sslmode' => 'verify-full']];
        yield 'invalid port' => [['port' => '3306 --execute=bad']];
    }

    public function testUnixSocketUsesTheSameTransportAsDoctrine(): void
    {
        $options = mysqlClientOptions($this->params(['unix_socket' => '/run/mysql/mysql.sock']));
        self::assertStringContainsString('protocol="SOCKET"', $options);
        self::assertStringContainsString('socket="/run/mysql/mysql.sock"', $options);
        self::assertStringNotContainsString('host=', $options);
    }

    #[DataProvider('unsafeDatabaseNames')]
    public function testDatabaseNameCannotBecomeACommandOption(string $name): void
    {
        $this->expectException(DeploymentCheckFailed::class);
        databaseName(['dbname' => $name]);
    }

    public static function unsafeDatabaseNames(): iterable
    {
        yield ['--all-databases'];
        yield ["db\n--all-databases"];
        yield [''];
        yield [str_repeat('a', 65)];
    }

    public function testPrivateOptionsAre0600AndNeverOverwriteExistingFile(): void
    {
        $path = $this->directory.'/mysql.cnf';
        writePrivateOptions($path, 'first secret');
        self::assertSame(0600, fileperms($path) & 0777);
        try {
            writePrivateOptions($path, 'replacement');
            self::fail('Expected exclusive creation to refuse an existing file.');
        } catch (DeploymentCheckFailed) {
            self::assertSame('first secret', file_get_contents($path));
        }
    }

    public function testPrivateOptionsDoNotFollowSymlink(): void
    {
        $target = $this->directory.'/target';
        file_put_contents($target, 'untouched');
        symlink($target, $this->directory.'/link');
        try {
            writePrivateOptions($this->directory.'/link', 'new secret');
            self::fail('Expected symlink refusal.');
        } catch (DeploymentCheckFailed) {
            self::assertSame('untouched', file_get_contents($target));
        }
    }

    public function testGroupReadableDirectoryIsRejected(): void
    {
        chmod($this->directory, 0750);
        $this->expectException(DeploymentCheckFailed::class);
        writePrivateOptions($this->directory.'/mysql.cnf', 'secret');
    }

    public function testReadOnlyPreflightRejectsNonTransactionalTables(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getParams')->willReturn($this->params());
        $connection->expects(self::once())->method('executeStatement')->with('SET SESSION TRANSACTION READ ONLY');
        $connection->expects(self::once())->method('beginTransaction');
        $connection->expects(self::once())->method('rollBack');
        $connection->method('fetchOne')->with('SELECT DATABASE()')->willReturn('account_philip');
        $connection->method('fetchFirstColumn')->willReturn(['InnoDB', 'MyISAM']);
        $this->expectException(DeploymentCheckFailed::class);
        preflightDatabase($connection);
    }

    public function testReadOnlyPreflightAcceptsInnoDb(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getParams')->willReturn($this->params());
        $connection->expects(self::once())->method('executeStatement')->with('SET SESSION TRANSACTION READ ONLY');
        $connection->expects(self::once())->method('beginTransaction');
        $connection->expects(self::once())->method('rollBack');
        $connection->method('fetchOne')->with('SELECT DATABASE()')->willReturn('account_philip');
        $connection->method('fetchFirstColumn')->willReturn(['InnoDB']);
        preflightDatabase($connection);
    }

    public function testDatabaseSizeUsesOnlyTheSelectedDatabaseAndReadOnlySession(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getParams')->willReturn($this->params());
        $connection->expects(self::once())->method('executeStatement')->with('SET SESSION TRANSACTION READ ONLY');
        $connection->expects(self::once())->method('fetchOne')->with(
            self::stringContains('SUM(DATA_LENGTH + INDEX_LENGTH)'), ['account_philip'],
        )->willReturn('12345');
        self::assertSame(12345, databaseSize($connection));
    }

    #[DataProvider('unsafeSecurityConfiguration')]
    public function testProductionRequiresASecretAndAnEnabledMailTransport(string $secret, string $mailerDsn): void
    {
        $this->expectException(DeploymentCheckFailed::class);
        assertProductionSecurity($secret, $mailerDsn);
    }

    public static function unsafeSecurityConfiguration(): iterable
    {
        yield 'short secret' => ['short', 'smtp://example.test'];
        yield 'blank secret' => [str_repeat(' ', 32), 'smtp://example.test'];
        yield 'missing transport' => [str_repeat('a', 32), ''];
        yield 'null transport' => [str_repeat('a', 32), 'null://null'];
        yield 'null in failover' => [str_repeat('a', 32), 'failover(smtp://example.test null://null)'];
    }

    public function testConfigurationFingerprintDetectsSecretDatabaseOrMailerChanges(): void
    {
        $secret = str_repeat('a', 32);
        $mailer = 'smtp://example.test';
        $fingerprint = configurationFingerprint($this->params(), $secret, $mailer);
        self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/D', $fingerprint);
        self::assertSame($fingerprint, configurationFingerprint(array_reverse($this->params(), true), $secret, $mailer));
        self::assertNotSame($fingerprint, configurationFingerprint($this->params(['password' => 'other']), $secret, $mailer));
        self::assertNotSame($fingerprint, configurationFingerprint($this->params(), str_repeat('b', 32), $mailer));
        self::assertNotSame($fingerprint, configurationFingerprint($this->params(), $secret, 'smtp://changed.test'));
    }

    public function testTrackedBaseEnvironmentAloneDoesNotCountAsLocalConfiguration(): void
    {
        file_put_contents($this->directory.'/.env', 'synthetic tracked file');
        $this->expectException(DeploymentCheckFailed::class);
        assertPrivateConfigurationExists($this->directory);
    }

    public function testLocalProductionConfigurationIsRecognizedWithoutReadingItIntoOutput(): void
    {
        file_put_contents($this->directory.'/.env.prod.local', 'synthetic private file');
        assertPrivateConfigurationExists($this->directory);
        self::addToAssertionCount(1);
    }

    public function testMigrationAllowlistRejectsShellText(): void
    {
        $path = $this->directory.'/allowlist';
        file_put_contents($path, "DoctrineMigrations\\Version20261016010000; touch /tmp/no\n");
        $this->expectException(DeploymentCheckFailed::class);
        readMigrationAllowlist($path);
    }

    public function testPendingMigrationsRejectsAnyUnapprovedHistoricalGap(): void
    {
        $path = $this->directory.'/allowlist';
        file_put_contents($path, "DoctrineMigrations\\Version20261016010000\n");
        $factory = $this->migrationFactory(['DoctrineMigrations\\Version20250101000000', 'DoctrineMigrations\\Version20261016010000'], []);
        $this->expectException(DeploymentCheckFailed::class);
        pendingMigrations($factory, $path);
    }

    public function testOnlyAuthorizedUnexecutedMigrationsAreReturned(): void
    {
        $old = 'DoctrineMigrations\\Version20250101000000';
        $new = 'DoctrineMigrations\\Version20261016010000';
        $path = $this->directory.'/allowlist';
        file_put_contents($path, "# Reviewed release\n$new\n");
        self::assertSame([$new], pendingMigrations($this->migrationFactory([$old, $new], [$old]), $path));
    }

    private function params(array $overrides = []): array
    {
        return array_replace(['driver' => 'pdo_mysql', 'host' => '127.0.0.1', 'port' => 3306,
            'user' => 'test', 'password' => 'synthetic', 'dbname' => 'account_philip', 'charset' => 'utf8mb4'], $overrides);
    }

    private function migrationFactory(array $available, array $executed): DependencyFactory
    {
        $migration = $this->createMock(AbstractMigration::class);
        $calculator = $this->createMock(MigrationPlanCalculator::class);
        $calculator->method('getMigrations')->willReturn(new AvailableMigrationsList(array_map(
            static fn (string $name) => new AvailableMigration(new Version($name), $migration), $available,
        )));
        $metadata = $this->createMock(MetadataStorage::class);
        $metadata->method('getExecutedMigrations')->willReturn(new ExecutedMigrationsList(array_map(
            static fn (string $name) => new ExecutedMigration(new Version($name)), $executed,
        )));
        $factory = $this->createMock(DependencyFactory::class);
        $factory->method('getMetadataStorage')->willReturn($metadata);
        $factory->method('getMigrationPlanCalculator')->willReturn($calculator);

        return $factory;
    }
}
