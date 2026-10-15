<?php

declare(strict_types=1);

namespace App\Tests\Deployment;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

final class DeploymentMaintenanceTest extends TestCase
{
    private string $directory;
    private const PROBE = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/philip-maintenance-test-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        mkdir($this->directory.'/scripts', 0700);
        mkdir($this->directory.'/var', 0700);
        copy(dirname(__DIR__, 2).'/scripts/deploy-maintenance.php', $this->directory.'/scripts/deploy-maintenance.php');
        // No Composer, Symfony kernel, web server, network or application database.
        // The real PHP process must stop before the simulated application boots.
        file_put_contents($this->directory.'/request.php', <<<'PHP'
<?php
declare(strict_types=1);
$completed = false;
http_response_code(200);
register_shutdown_function(static function () use (&$completed): void {
    fwrite(STDERR, '__DEPLOYMENT_RESULT__'.json_encode([
        'status' => http_response_code(),
        'application_booted' => $completed,
    ], JSON_THROW_ON_ERROR)."\n");
});
set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
$_SERVER = json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR);
require __DIR__.'/scripts/deploy-maintenance.php';
$completed = true;
echo 'APPLICATION_BOOTED';
PHP);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
    }

    public function testNoMarkerAllowsNormalRequestWithoutAnyProbe(): void
    {
        $result = $this->request('POST', '/fr/admin/something');
        self::assertSame(200, $result['status']);
        self::assertTrue($result['application_booted']);
        self::assertSame('APPLICATION_BOOTED', $result['body']);
    }

    public function testValidMarkerBlocksOrdinaryVisitorsWith503(): void
    {
        $this->writeMarker();
        $this->assertBlocked($this->request('GET', '/fr/login'));
    }

    #[DataProvider('corruptMarkers')]
    public function testCorruptMarkerFailsClosedEvenWithCorrectProbe(string $contents): void
    {
        file_put_contents($this->markerPath(), $contents);
        $this->assertBlocked($this->request('GET', '/fr/login', self::PROBE));
    }

    public static function corruptMarkers(): iterable
    {
        yield 'truncated JSON' => ['{"probe_hash":'];
        yield 'empty file' => [''];
        yield 'null JSON' => ['null'];
        yield 'missing hash' => ['{}'];
        yield 'hash array' => ['{"probe_hash":[]}'];
        yield 'hash number' => ['{"probe_hash":1234}'];
        yield 'hash too short' => ['{"probe_hash":"abcd"}'];
    }

    public function testCorrectProbeCanReadTheLoginDuringMaintenance(): void
    {
        $this->writeMarker();
        $result = $this->request('GET', '/fr/login', self::PROBE);
        self::assertSame(200, $result['status']);
        self::assertTrue($result['application_booted']);
        self::assertSame('APPLICATION_BOOTED', $result['body']);
    }

    public function testCorrectProbeCannotSubmitLoginDuringMaintenance(): void
    {
        $this->writeMarker();
        $this->assertBlocked($this->request('POST', '/fr/login', self::PROBE));
    }

    public function testWrongProbeCannotReadTheLoginDuringMaintenance(): void
    {
        $this->writeMarker();
        $this->assertBlocked($this->request('GET', '/fr/login', str_repeat('b', 64)));
    }

    #[DataProvider('privatePaths')]
    public function testCorrectProbeCannotOpenPrivatePaths(string $path): void
    {
        $this->writeMarker();
        $this->assertBlocked($this->request('GET', $path, self::PROBE));
    }

    public static function privatePaths(): iterable
    {
        yield ['/fr/admin'];
        yield ['/fr/workspace'];
        yield ['/2fa'];
        yield ['/fr/compte/securite'];
        yield ['/fr/login/../admin'];
        yield ['/fr/login-extra'];
    }

    public function testMarkerDirectoryFailsClosed(): void
    {
        mkdir($this->markerPath(), 0700);
        $this->assertBlocked($this->request('GET', '/fr/login', self::PROBE));
    }

    public function testBrokenMarkerSymlinkFailsClosed(): void
    {
        symlink($this->directory.'/missing-marker-target', $this->markerPath());
        $this->assertBlocked($this->request('GET', '/fr/login', self::PROBE));
    }

    private function markerPath(): string
    {
        return $this->directory.'/var/deployment-maintenance.json';
    }

    private function writeMarker(): void
    {
        file_put_contents($this->markerPath(), json_encode(['probe_hash' => hash('sha256', self::PROBE)], JSON_THROW_ON_ERROR));
    }

    /** @return array{status: int, application_booted: bool, body: string} */
    private function request(string $method, string $path, ?string $probe = null): array
    {
        $server = ['REQUEST_METHOD' => $method, 'REQUEST_URI' => $path];
        if ($probe !== null) {
            $server['HTTP_X_DEPLOYMENT_PROBE'] = $probe;
        }
        $process = new Process([PHP_BINARY, '-n', '-d', 'display_errors=stderr', $this->directory.'/request.php', json_encode($server, JSON_THROW_ON_ERROR)]);
        $process->setTimeout(10);
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertMatchesRegularExpression('/\A__DEPLOYMENT_RESULT__(\{[^\n]+\})\n\z/', $process->getErrorOutput());
        $result = json_decode(substr($process->getErrorOutput(), strlen('__DEPLOYMENT_RESULT__')), true, 512, JSON_THROW_ON_ERROR);
        $result['body'] = $process->getOutput();

        return $result;
    }

    private function assertBlocked(array $result): void
    {
        self::assertSame(503, $result['status']);
        self::assertFalse($result['application_booted']);
        self::assertStringNotContainsString('APPLICATION_BOOTED', $result['body']);
        self::assertStringNotContainsString(self::PROBE, $result['body']);
    }
}
