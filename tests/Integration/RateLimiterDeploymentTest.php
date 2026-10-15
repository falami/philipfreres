<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Kernel;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Http\SecurityRequestAttributes;
use Symfony\Component\Console\Tester\CommandTester;

/** Exercises the actual application configuration, with all writable paths isolated. */
final class RateLimiterDeploymentTest extends TestCase
{
    public function testQuotasSurviveCacheClearAndANewContainerBuild(): void
    {
        $directory = sys_get_temp_dir().'/philipfreres-rate-deployment-'.bin2hex(random_bytes(8));
        $previousEnvironment = [];
        foreach (['DATABASE_URL' => 'sqlite:///:memory:', 'MAILER_DSN' => 'null://null'] as $name => $value) {
            $previousEnvironment[$name] = [$_ENV[$name] ?? null, $_SERVER[$name] ?? null];
            $_ENV[$name] = $_SERVER[$name] = $value;
        }

        $kernel = new RateLimiterDeploymentKernel($directory, 'first');
        try {
            $kernel->boot();
            $container = $kernel->getContainer();
            $firstBuild = $container->getParameter('container.build_hash');
            $reset = $container->get('limiter.password_reset')->create('deployment-test');
            self::assertTrue($reset->consume(3)->isAccepted());
            self::assertFalse($reset->consume()->isAccepted());

            $request = Request::create('/fr/login', 'POST', server: ['REMOTE_ADDR' => '192.0.2.10']);
            $request->attributes->set(SecurityRequestAttributes::LAST_USERNAME, 'deploy@example.test');
            $login = $container->get('app.security.login_rate_limiter');
            for ($attempt = 0; $attempt < 5; ++$attempt) {
                self::assertTrue($login->consume($request)->isAccepted());
            }
            self::assertFalse($login->consume($request)->isAccepted());

            $command = new CommandTester((new Application($kernel))->find('cache:clear'));
            self::assertSame(0, $command->execute(['--no-warmup' => true]), $command->getDisplay());
            self::assertFalse($kernel->getContainer()->get('limiter.password_reset')->create('deployment-test')->consume()->isAccepted());
            self::assertFalse($kernel->getContainer()->get('app.security.login_rate_limiter')->consume($request)->isAccepted());

            $kernel->shutdown();
            $kernel = new RateLimiterDeploymentKernel($directory, 'second');
            $kernel->boot();
            $container = $kernel->getContainer();
            self::assertNotSame($firstBuild, $container->getParameter('container.build_hash'));
            self::assertFalse($container->get('limiter.password_reset')->create('deployment-test')->consume()->isAccepted());
            self::assertFalse($container->get('app.security.login_rate_limiter')->consume($request)->isAccepted());
            // A different account is still permitted: the preserved block is scoped correctly.
            $request->attributes->set(SecurityRequestAttributes::LAST_USERNAME, 'another@example.test');
            self::assertTrue($container->get('app.security.login_rate_limiter')->consume($request)->isAccepted());
        } finally {
            $kernel->shutdown();
            (new Filesystem())->remove($directory);
            foreach ($previousEnvironment as $name => $values) {
                foreach (['ENV', 'SERVER'] as $index => $scope) {
                    if ($values[$index] === null) {
                        unset($GLOBALS['_'.$scope][$name]);
                    } else {
                        $GLOBALS['_'.$scope][$name] = $values[$index];
                    }
                }
            }
        }
    }
}

final class RateLimiterDeploymentKernel extends Kernel
{
    public function __construct(private readonly string $directory, private readonly string $release)
    {
        parent::__construct('test', true);
    }

    public function getProjectDir(): string { return dirname(__DIR__, 2); }
    public function getCacheDir(): string { return $this->directory.'/cache/'.$this->release; }
    public function getBuildDir(): string { return $this->getCacheDir(); }
    public function getLogDir(): string { return $this->directory.'/logs'; }

    protected function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass(new class($this->directory, $this->release) implements CompilerPassInterface {
            public function __construct(private readonly string $directory, private readonly string $release) {}

            public function process(ContainerBuilder $container): void
            {
                // Keep production adapter and wiring; only redirect writes to this test's directory.
                $container->getDefinition('app.security.rate_limiter.cache')->replaceArgument(2, $this->directory.'/security/rate-limits');
                $container->getDefinition('app.security.login_rate_limiter')->setPublic(true);
                $container->getDefinition('limiter.password_reset')->setPublic(true);
                $container->setParameter('test.deployment.release', $this->release);
            }
        });
    }
}
