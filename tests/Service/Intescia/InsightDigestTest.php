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

    public function testFutureTenantSelectionDryRunAndDeduplication(): void
    {
        $db=DriverManager::getConnection(['driver'=>'pdo_sqlite','memory'=>true]);
        $db->executeStatement('CREATE TABLE insight_connection (tenant_id INTEGER PRIMARY KEY, settings TEXT)');
        $db->executeStatement('CREATE TABLE insight_market (tenant_id INTEGER, title TEXT, deadline TEXT, active INTEGER)');
        $db->executeStatement('CREATE TABLE insight_digest_delivery (id TEXT PRIMARY KEY, tenant_id INTEGER, user_id INTEGER, scheduled_date TEXT, status TEXT, created_at TEXT, updated_at TEXT)');
        $store=new InsightStore($db, sys_get_temp_dir().'/insight-digest-test');
        $config=array_replace($store->config(1), ['authorized'=>true,'digest_enabled'=>true,'digest_start'=>'2026-10-21','digest_time'=>'08:00','recipients'=>[10,10,11,99], 'sync_ok'=>true, 'last_success'=>'2026-10-21 06:00:00']);
        $store->saveConfig(1,$config);
        foreach ([[1,'Marché <test>','2026-10-23 10:00:00',1],[1,'Expiré','2026-10-20 10:00:00',1],[2,'Autre société','2026-10-23 10:00:00',1],[1,'Inactif','2026-10-23 10:00:00',0]] as $row) { $db->insert('insight_market',array_combine(['tenant_id','title','deadline','active'],$row)); }
        $recipients=$this->createMock(InsightRecipients::class);
        $recipients->method('available')->willReturn([10=>['email'=>'one@example.test'],11=>['email'=>'two@example.test']]);
        $company=new Entite();$company->setNom('Philip Frères');
        $em=$this->createMock(EntityManagerInterface::class);$em->method('find')->willReturn($company);
        $transport=$this->createMock(TransportInterface::class);
        $transport->expects(self::exactly(2))->method('send')->willReturnCallback(function ($email) {
            self::assertCount(1,$email->getTo());
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
        self::assertSame(2,$service->run(1,true,$now)['eligible']);
        self::assertSame(0,(int)$db->fetchOne('SELECT COUNT(*) FROM insight_digest_delivery'));
        self::assertSame(['eligible'=>2,'sent'=>1,'uncertain'=>1],$service->run(1,false,$now));
        self::assertSame(0,$service->run(1,false,$now)['eligible']);
        $config['last_success']='2026-10-19 00:00:00';$store->saveConfig(1,$config);
        self::assertSame(0,$service->run(1,true,$now)['eligible']);
    }
}
