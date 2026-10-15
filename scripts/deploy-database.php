<?php

declare(strict_types=1);

namespace PhilipFreres\Deployment;

use App\Kernel;
use Doctrine\DBAL\Connection;
use Doctrine\Migrations\DependencyFactory;
use RuntimeException;
use Symfony\Component\Dotenv\Dotenv;
use Throwable;

/** Only these deliberately written messages may reach the deployment log. */
final class DeploymentCheckFailed extends RuntimeException
{
}

/** Escape an option value for both MySQL and MariaDB clients, never for a shell. */
function quoteMysqlOption(string $value): string
{
    if (str_contains($value, "\0")) {
        throw new DeploymentCheckFailed('Une option MySQL contient un caractère non pris en charge.');
    }

    return '"'.strtr($value, [
        '\\' => '\\\\', '"' => '\\"', "\n" => '\\n', "\r" => '\\r',
        "\t" => '\\t', "\x08" => '\\b',
    ]).'"';
}

/** @param array<string, mixed> $params */
function databaseName(array $params): string
{
    $name = $params['dbname'] ?? null;
    // The name is eventually an argv item: disallow option-like names and controls.
    if (!is_string($name) || !preg_match('/\A[A-Za-z0-9_][A-Za-z0-9_$-]{0,63}\z/D', $name)) {
        throw new DeploymentCheckFailed('Nom de base absent ou non compatible avec la sauvegarde sécurisée.');
    }

    return $name;
}

/** @param array<string, mixed> $params */
function mysqlClientOptions(array $params): string
{
    if (!in_array($params['driver'] ?? '', ['pdo_mysql', 'mysqli'], true)
        || isset($params['driverClass']) || isset($params['primary']) || isset($params['replica'])) {
        throw new DeploymentCheckFailed('La sauvegarde exige une connexion MySQL/MariaDB simple.');
    }
    databaseName($params);

    // Do not silently lose TLS, client certificates, init commands or routing.
    // A custom connection requires an explicitly reviewed client configuration.
    foreach ($params as $key => $value) {
        if (($key === 'driverOptions' && $value !== [])
            || str_starts_with(strtolower((string) $key), 'ssl')) {
            throw new DeploymentCheckFailed('Options MySQL personnalisées ou TLS détectées : adapter et valider la configuration du client de sauvegarde avant déploiement.');
        }
    }

    $options = ['user' => (string) ($params['user'] ?? ''), 'password' => (string) ($params['password'] ?? '')];
    if ($options['user'] === '') {
        throw new DeploymentCheckFailed('Le compte MySQL de sauvegarde est absent.');
    }
    $socket = $params['unix_socket'] ?? '';
    if ($socket === '' && ($params['host'] ?? 'localhost') === 'localhost') {
        // php.ini and the mysql CLI can have different compiled-in defaults.
        // Resolve PHP's socket explicitly so the dump targets the same server.
        $socket = ini_get($params['driver'] === 'pdo_mysql' ? 'pdo_mysql.default_socket' : 'mysqli.default_socket');
        if ($socket === false || $socket === '') {
            throw new DeploymentCheckFailed('Socket MySQL implicite indéterminé : configurer unix_socket ou une adresse TCP explicite.');
        }
    }
    if ($socket !== '') {
        if (!is_string($socket) || !str_starts_with($socket, '/')) {
            throw new DeploymentCheckFailed('Le socket MySQL doit être un chemin absolu.');
        }
        $options['protocol'] = 'SOCKET';
        $options['socket'] = $socket;
    } else {
        $options['host'] = (string) ($params['host'] ?? 'localhost');
        $port = filter_var($params['port'] ?? 3306, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
        if ($port === false || $options['host'] === '') {
            throw new DeploymentCheckFailed('Hôte ou port MySQL invalide.');
        }
        $options['port'] = (string) $port;
        // PDO/MySQL itself uses a local socket for "localhost".
        if ($options['host'] !== 'localhost') {
            $options['protocol'] = 'TCP';
        }
    }
    if (isset($params['charset'])) {
        $options['default-character-set'] = (string) $params['charset'];
    }
    // Keep this shared option file compatible with both dump clients. In
    // particular, the interactive-client connect-timeout option is not portable
    // across dump clients and versions.

    $content = "[client]\n";
    foreach ($options as $name => $value) {
        $content .= $name.'='.quoteMysqlOption($value)."\n";
    }

    return $content;
}

function writePrivateOptions(string $path, string $contents): void
{
    $parent = realpath(dirname($path));
    if ($parent === false || !is_dir($parent) || (fileperms($parent) & 0077) !== 0) {
        throw new DeploymentCheckFailed('Le fichier MySQL doit être créé dans un répertoire privé (0700).');
    }
    if (function_exists('posix_geteuid') && fileowner($parent) !== posix_geteuid()) {
        throw new DeploymentCheckFailed('Le répertoire MySQL doit appartenir au compte de déploiement.');
    }

    $oldMask = umask(0077);
    $handle = false;
    $created = false;
    try {
        // Exclusive creation also rejects an existing symlink or file.
        $handle = @fopen($path, 'xb');
        if ($handle === false) {
            throw new DeploymentCheckFailed('Impossible de créer exclusivement le fichier MySQL privé.');
        }
        $created = true;
        if (!@chmod($path, 0600) || fwrite($handle, $contents) !== strlen($contents) || !fflush($handle)) {
            throw new DeploymentCheckFailed('Écriture du fichier MySQL privé incomplète.');
        }
        if (function_exists('fsync') && !fsync($handle)) {
            throw new DeploymentCheckFailed('Synchronisation du fichier MySQL privé impossible.');
        }
    } catch (Throwable $error) {
        if ($created) {
            @unlink($path);
        }
        throw $error;
    } finally {
        if (is_resource($handle)) {
            fclose($handle);
        }
        umask($oldMask);
    }
}

function preflightDatabase(Connection $connection): void
{
    $params = $connection->getParams();
    mysqlClientOptions($params);
    // Read-only is session-scoped. This process never runs a migration.
    $connection->executeStatement('SET SESSION TRANSACTION READ ONLY');
    $connection->beginTransaction();
    try {
        if ($connection->fetchOne('SELECT DATABASE()') !== databaseName($params)) {
            throw new DeploymentCheckFailed('La connexion MySQL ne sélectionne pas la base attendue.');
        }
        $engines = $connection->fetchFirstColumn(
            "SELECT DISTINCT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE'",
            [databaseName($params)],
        );
        if ($engines === []) {
            throw new DeploymentCheckFailed('La base ne contient aucune table : vérifier la cible avant déploiement.');
        }
        foreach ($engines as $engine) {
            if (strtoupper((string) $engine) !== 'INNODB') {
                throw new DeploymentCheckFailed('Une table non InnoDB empêche une sauvegarde transactionnelle cohérente.');
            }
        }
    } finally {
        $connection->rollBack();
    }
}

function assertProductionSecurity(string $secret, string $mailerDsn): void
{
    if (strlen(trim($secret)) < 32) {
        throw new DeploymentCheckFailed('APP_SECRET de production doit contenir au moins 32 caractères et rester stable entre déploiements.');
    }
    if (trim($mailerDsn) === '' || preg_match('~\bnull://~i', $mailerDsn)) {
        throw new DeploymentCheckFailed('MAILER_DSN de production doit configurer un vrai transport : les codes de connexion exigent un service e-mail actif.');
    }
}

function assertPrivateConfigurationExists(string $project): void
{
    foreach (['.env.local.php', '.env.local', '.env.prod.local'] as $name) {
        if (is_file($project.'/'.$name) && is_readable($project.'/'.$name)) {
            return;
        }
    }

    throw new DeploymentCheckFailed('Configuration locale de production absente : fournir .env.local.php, .env.local ou .env.prod.local hors du suivi Git.');
}

/** @param array<string, mixed> $params */
function configurationFingerprint(array $params, string $secret, string $mailerDsn): string
{
    assertProductionSecurity($secret, $mailerDsn);
    $canonicalize = static function (array $values) use (&$canonicalize): array {
        ksort($values);
        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $values[$key] = $canonicalize($value);
            }
        }

        return $values;
    };

    // serialize preserves arbitrary password bytes without JSON encoding losses.
    // A keyed digest prevents the output from being used to guess credentials.
    return hash_hmac('sha256', serialize($canonicalize([
        'database' => $params,
        'APP_SECRET' => $secret,
        'MAILER_DSN' => $mailerDsn,
        'APP_ENV' => 'prod',
        'APP_DEBUG' => '0',
    ])), $secret);
}

function databaseSize(Connection $connection): int
{
    $connection->executeStatement('SET SESSION TRANSACTION READ ONLY');
    $size = $connection->fetchOne(
        "SELECT COALESCE(SUM(DATA_LENGTH + INDEX_LENGTH), 0) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE'",
        [databaseName($connection->getParams())],
    );
    if (filter_var($size, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) === false) {
        throw new DeploymentCheckFailed('Impossible d’estimer la taille de la base pour la sauvegarde.');
    }

    return (int) $size;
}

/** @return list<string> */
function readMigrationAllowlist(string $path): array
{
    $lines = @file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        throw new DeploymentCheckFailed('Liste des migrations autorisées introuvable.');
    }
    $allowed = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (!preg_match('/\ADoctrineMigrations\\\\Version[0-9]{14}\z/D', $line) || in_array($line, $allowed, true)) {
            throw new DeploymentCheckFailed('Liste des migrations autorisées invalide ou contenant un doublon.');
        }
        $allowed[] = $line;
    }

    return $allowed;
}

/** @return list<string> */
function pendingMigrations(DependencyFactory $factory, string $allowlist): array
{
    $allowed = readMigrationAllowlist($allowlist);
    $executed = $factory->getMetadataStorage()->getExecutedMigrations();
    $pending = [];
    foreach ($factory->getMigrationPlanCalculator()->getMigrations()->getItems() as $migration) {
        $version = $migration->getVersion();
        if (!$executed->hasMigration($version)) {
            if (!in_array((string) $version, $allowed, true)) {
                $label = preg_match('/\ADoctrineMigrations\\\\Version[0-9]{14}\z/D', (string) $version)
                    ? ' ('.(string) $version.')' : '';
                throw new DeploymentCheckFailed('Migration en attente non autorisée'.$label.' : vérifier l’historique Doctrine et la liste avant déploiement.');
            }
            $pending[] = (string) $version;
        }
    }

    return $pending;
}

/** @param list<string> $arguments */
function run(array $arguments): int
{
    $kernel = null;
    // Never expose a SQL exception, DSN, password, or environment contents.
    ini_set('display_errors', '0');
    ini_set('log_errors', '0');
    try {
        $command = $arguments[1] ?? '';
        $expectedCount = match ($command) {
            'preflight', 'database-name', 'database-size', 'configuration-fingerprint' => 2,
            'dump-config', 'pending-migrations' => 3,
            default => throw new DeploymentCheckFailed('Commande attendue : preflight, database-name, database-size, configuration-fingerprint, dump-config <fichier>, pending-migrations <liste>.'),
        };
        if (count($arguments) !== $expectedCount) {
            throw new DeploymentCheckFailed('Nombre d’arguments du contrôle de déploiement incorrect.');
        }
        $project = realpath(getenv('PHILIP_DEPLOY_APP_DIR') ?: dirname(__DIR__));
        if ($project === false || !is_file($project.'/vendor/autoload.php')) {
            throw new DeploymentCheckFailed('Application ou dépendances PHP introuvables.');
        }
        require $project.'/vendor/autoload.php';
        (new Dotenv())->bootEnv($project.'/.env');
        if (($_SERVER['APP_ENV'] ?? '') !== 'prod' || ($_SERVER['APP_DEBUG'] ?? '') !== '0') {
            throw new DeploymentCheckFailed('Le déploiement exige APP_ENV=prod et APP_DEBUG=0.');
        }

        $kernel = new Kernel('prod', false);
        $kernel->boot();
        /** @var Connection $connection */
        $connection = $kernel->getContainer()->get('doctrine')->getConnection();
        $params = $connection->getParams();
        mysqlClientOptions($params);
        switch ($command) {
            case 'preflight':
                assertPrivateConfigurationExists($project);
                assertProductionSecurity(
                    (string) $kernel->getContainer()->getParameter('kernel.secret'),
                    (string) ($_SERVER['MAILER_DSN'] ?? $_ENV['MAILER_DSN'] ?? getenv('MAILER_DSN') ?: ''),
                );
                preflightDatabase($connection);
                break;
            case 'configuration-fingerprint':
                fwrite(STDOUT, configurationFingerprint(
                    $params,
                    (string) $kernel->getContainer()->getParameter('kernel.secret'),
                    (string) ($_SERVER['MAILER_DSN'] ?? $_ENV['MAILER_DSN'] ?? getenv('MAILER_DSN') ?: ''),
                )."\n");
                break;
            case 'database-size':
                fwrite(STDOUT, (string) databaseSize($connection)."\n");
                break;
            case 'dump-config':
                writePrivateOptions($arguments[2], mysqlClientOptions($params));
                break;
            case 'database-name':
                fwrite(STDOUT, databaseName($params)."\n");
                break;
            case 'pending-migrations':
                $connection->executeStatement('SET SESSION TRANSACTION READ ONLY');
                $factory = $kernel->getContainer()->get('app.deployment.migrations');
                $versions = pendingMigrations($factory, $arguments[2]);
                if ($versions !== []) {
                    fwrite(STDOUT, implode("\n", $versions)."\n");
                }
                break;
        }

        return 0;
    } catch (DeploymentCheckFailed $error) {
        fwrite(STDERR, '[déploiement] '.$error->getMessage()."\n");
    } catch (Throwable) {
        fwrite(STDERR, "[déploiement] Contrôle PHP/Doctrine impossible. Vérifier la configuration de production et la disponibilité de MySQL ; les détails sensibles ont été masqués.\n");
    } finally {
        try {
            $kernel?->shutdown();
        } catch (Throwable) {
            fwrite(STDERR, "[déploiement] Arrêt du contrôle Symfony incomplet ; détails sensibles masqués.\n");
            return 1;
        }
    }

    return 1;
}

if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    exit(run($argv));
}
