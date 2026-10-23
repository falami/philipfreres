<?php

declare(strict_types=1);
namespace App\Tests\Service\Intescia;

use App\Entity\Entite;
use App\Service\Intescia\{InsightDigest, InsightStore, InsightRecipients};
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class InsightDigestTest extends TestCase
{
    public function testParisScheduleAcrossDstAndNoMissedDayCatchup(): void
    {
        $c = ['digest_enabled'=>true, 'digest_start'=>'2026-10-24', 'digest_time'=>'08:00', 'digest_interval'=>2];
        self::assertNull(InsightDigest::due($c, new \DateTimeImmutable('2026-10-24T05:59:00Z')));
        self::assertNotNull(InsightDigest::due($c, new \DateTimeImmutable('2026-10-24T06:00:00Z')));
        self::assertNull(InsightDigest::due($c, new \DateTimeImmutable('2026-10-25T12:00:00Z')));
        self::assertNull(InsightDigest::due($c, new \DateTimeImmutable('2026-10-26T06:59:00Z')));
        self::assertNotNull(InsightDigest::due($c, new \DateTimeImmutable('2026-10-26T07:00:00Z')));
        $c['digest_interval']=7;
        self::assertNotNull(InsightDigest::due($c, new \DateTimeImmutable('2026-10-31T07:00:00Z')));
        self::assertNull(InsightDigest::due($c, new \DateTimeImmutable('2026-11-01T07:00:00Z')));
    }

    public static function synchronizationStates(): array
    {
        return [
            'fresh' => [true, '2026-10-21 06:00:00', false],
            'API failure after successful import' => [false, '2026-10-21 06:00:00', true],
            'older than 24 hours' => [true, '2026-10-19 00:00:00', true],
            'partial data without completed import' => [false, null, true],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('synchronizationStates')]
    public function testFutureTenantSelectionDryRunAndDeduplication(bool $syncOk, ?string $lastSuccess, bool $cached): void
    {
        $db=DriverManager::getConnection(['driver'=>'pdo_sqlite','memory'=>true]);
        $db->executeStatement('CREATE TABLE insight_connection (tenant_id INTEGER PRIMARY KEY, settings TEXT)');
        $db->executeStatement('CREATE TABLE insight_market (tenant_id INTEGER, title TEXT, deadline TEXT, active INTEGER)');
        $db->executeStatement('CREATE TABLE insight_digest_delivery (id TEXT PRIMARY KEY, tenant_id INTEGER, user_id INTEGER, scheduled_date TEXT, status TEXT, created_at TEXT, updated_at TEXT)');
        $store=new InsightStore($db, sys_get_temp_dir().'/insight-digest-test');
        $config=array_replace($store->config(1), ['authorized'=>true,'digest_enabled'=>true,'digest_start'=>'2026-10-21','digest_time'=>'08:00','recipients'=>[10,10,11,99], 'sync_ok'=>$syncOk, 'last_success'=>$lastSuccess]);
        $store->saveConfig(1,$config);
        foreach ([[1,'Marché <test>','2026-10-23 10:00:00',1],[1,'Expiré','2026-10-20 10:00:00',1],[2,'Autre société','2026-10-23 10:00:00',1],[1,'Inactif','2026-10-23 10:00:00',0]] as $row) { $db->insert('insight_market',array_combine(['tenant_id','title','deadline','active'],$row)); }
        $recipients=$this->createMock(InsightRecipients::class);
        $recipients->method('available')->willReturn([10=>['email'=>'one@example.test'],11=>['email'=>'two@example.test']]);
        $company=new Entite();$company->setNom('Philip Frères');
        $em=$this->createMock(EntityManagerInterface::class);$em->method('find')->willReturn($company);
        $transport=$this->createMock(TransportInterface::class);
        $transport->expects(self::exactly(2))->method('send')->willReturnCallback(function ($email) use ($cached, $lastSuccess) {
            self::assertCount(1,$email->getTo());
            self::assertStringContainsString('Dernière synchronisation complète', $email->getHtmlBody());
            self::assertSame($cached, str_contains($email->getHtmlBody(), 'actualisation non confirmée'));
            self::assertSame($cached, str_contains($email->getTextBody(), 'actualisation non confirmée'));
            if ($lastSuccess === null) self::assertStringContainsString('aucune enregistrée', $email->getTextBody());
            self::assertStringContainsString('Marché &lt;test&gt;', $email->getHtmlBody());
            self::assertStringNotContainsString('Autre société', $email->getHtmlBody());
            self::assertStringNotContainsString('Expiré', $email->getHtmlBody());
            self::assertStringNotContainsString('Inactif', $email->getHtmlBody());
            if ($email->getTo()[0]->getAddress()==='two@example.test') { throw new \RuntimeException('SMTP timeout'); }
            return null;
        });
        $twig=new Environment(new FilesystemLoader(dirname(__DIR__,3).'/templates'),['autoescape'=>'html']);
        $service=new InsightDigest($store,$recipients,$transport,$twig,$em,'sender@example.test','https://app.example.test',dirname(__DIR__,3));
        $now=new \DateTimeImmutable('2026-10-21T07:00:00Z');
        $config['authorized'] = false; $store->saveConfig(1, $config);
        self::assertSame(0, $service->run(1, true, $now)['eligible']);
        $config['authorized'] = true; $config['digest_enabled'] = false; $store->saveConfig(1, $config);
        self::assertSame(0, $service->run(1, true, $now)['eligible']);
        $config['digest_enabled'] = true; $store->saveConfig(1, $config);
        self::assertSame(2,$service->run(1,true,$now)['eligible']);
        self::assertSame(0,(int)$db->fetchOne('SELECT COUNT(*) FROM insight_digest_delivery'));
        self::assertSame(['eligible'=>2,'sent'=>1,'uncertain'=>1],$service->run(1,false,$now));
        self::assertSame(0,$service->run(1,false,$now)['eligible']);
        $config['last_success']='2026-10-19 00:00:00';$store->saveConfig(1,$config);
        self::assertSame(0,$service->run(1,true,$now)['eligible']);
    }
}
