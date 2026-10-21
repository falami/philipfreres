<?php

declare(strict_types=1);
namespace App\Tests\Service\Intescia;

use App\Service\Intescia\{InsightStore, InsightVault, InsightClient, InsightImporter, InsightNormalizer, InsightRecipients, InsightAlerts, InsightException};
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class InsightIntegrationTest extends TestCase
{
    private InsightStore $store;
    private InsightVault $vault;
    private InsightClient $client;
    private const UUID = '11111111-1111-4111-8111-111111111111';
    private const SECOND = '22222222-2222-4222-8222-222222222222';

    protected function setUp(): void
    {
        $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $db->executeStatement('CREATE TABLE insight_connection (tenant_id INTEGER PRIMARY KEY, settings TEXT NOT NULL, credentials TEXT)');
        $db->executeStatement('CREATE TABLE insight_market (id TEXT PRIMARY KEY, tenant_id INTEGER, remote_id TEXT, title TEXT, buyer TEXT, deadline TEXT, active INTEGER, seen TEXT, payload TEXT, updated_at TEXT)');
        $db->executeStatement('CREATE TABLE insight_contract_cache (id TEXT PRIMARY KEY, tenant_id INTEGER, payload TEXT)');
        $db->executeStatement('CREATE TABLE insight_delivery (id TEXT PRIMARY KEY, tenant_id INTEGER, market_id TEXT, user_id INTEGER, deadline TEXT, status TEXT, created_at TEXT, updated_at TEXT)');
        $this->store = new InsightStore($db, sys_get_temp_dir().'/insight-tests-locks');
        $this->vault = new InsightVault(base64_encode(random_bytes(32)));
        $this->client = new InsightClient($this->store, $this->vault);
        $config = $this->store->config(1); $config['statuses'] = [self::UUID];
        $this->store->saveConfig(1, $config);
        $this->store->saveCredentials(1, $this->vault->seal(['client_id' => 'test', 'client_secret' => 'secret', 'access_token' => 'access', 'refresh_token' => 'refresh', 'expires_at' => time() + 3600]));
    }

    private function hit(string $id = self::UUID): array
    {
        return ['dealOpportunity' => ['id' => $id, 'dealOpportunityResultType' => 'CONTRACT', 'response' => '2030-06-02T14:00:00+02:00', 'translations' => [['language' => 'FR', 'object' => 'Travaux <script>unsafe</script>']], 'principal' => ['translations' => [['language' => 'FR', 'name' => 'Mairie']]], 'futureField' => ['unknown' => 42]], 'metadata' => ['extra' => 'kept']];
    }
    private function response(array $data, int $status = 200): MockResponse { return new MockResponse(json_encode($data), ['http_code' => $status]); }

    public function testVaultAuthenticatesAndDoesNotExposePlaintext(): void
    {
        $sealed = $this->vault->seal(['secret' => 'confidential']);
        self::assertStringNotContainsString('confidential', $sealed);
        self::assertSame(['secret' => 'confidential'], $this->vault->open($sealed));
        $other = new InsightVault(base64_encode(random_bytes(32)));
        $this->expectException(InsightException::class); $other->open($sealed);
    }

    public function testImportPaginatesPreservesUnknownFieldsAndReusesPaidDetails(): void
    {
        $calls = [];
        $this->client->setHttpClient(new MockHttpClient(function ($method, $url, $options) use (&$calls) {
            $calls[] = $url;
            if (str_contains($url, 'deal-opportunities')) {
                $body = json_decode($options['body'], true);
                self::assertSame([self::UUID], $body['collaborativeStatusesFilter']['collaborativeStatusIds']);
                self::assertContains('INTERLOCUTORS', $body['additionalFields']);
                $page = str_contains($url, 'page=1') ? 1 : 0;
                return $this->response(['content' => [$this->hit($page ? self::SECOND : self::UUID)], 'number' => $page, 'totalPages' => 2]);
            }
            if (str_contains($url, '/lots')) return $this->response(['content' => [['id' => 'lot', 'unknown' => true]], 'number' => 0, 'totalPages' => 1]);
            return $this->response(['contract' => ['id' => self::UUID, 'allFields' => ['keep' => 'yes']]]);
        }));
        $importer = new InsightImporter($this->store, $this->client);
        self::assertSame(['rows' => 2, 'calls' => 6], $importer->run(1));
        self::assertSame(['rows' => 2, 'calls' => 2], $importer->run(1));
        $row = $this->store->market(1, InsightStore::id(1, self::UUID));
        self::assertSame(42, $row['payload']['followed']['dealOpportunity']['futureField']['unknown']);
        self::assertSame('2030-06-02 12:00:00', $row['deadline']);
        self::assertNull($this->store->market(2, $row['id']));
        self::assertSame('yes', $this->store->cache(1, self::UUID)['detail']['contract']['allFields']['keep']);
        self::assertSame([], $this->store->cache(2, self::UUID));
    }

    public function testFailedImportDoesNotDeactivateExistingMarketsAndBudgetStopsCalls(): void
    {
        $this->store->saveMarket(1, InsightNormalizer::row($this->hit(), []), ['followed' => $this->hit(), 'contract_id' => self::UUID], 'old');
        $this->client->setHttpClient(new MockHttpClient([$this->response([], 402)]));
        try { (new InsightImporter($this->store, $this->client))->run(1); self::fail('Expected credit failure'); }
        catch (InsightException $e) { self::assertStringContainsString('Crédits', $e->getMessage()); }
        self::assertSame(1, (int) $this->store->market(1, InsightStore::id(1, self::UUID))['active']);
        $this->client->setHttpClient(new MockHttpClient([$this->response(['ok' => true])]));
        $this->client->budget(1); $this->client->request(1, 'GET', '/me');
        $this->expectException(InsightException::class); $this->client->request(1, 'GET', '/me');
    }

    public function testRefreshTokenRotationAndCrossHostRedirectRejection(): void
    {
        $this->client->setHttpClient(new MockHttpClient([
            $this->response([], 401), $this->response(['access_token' => 'new-access', 'refresh_token' => 'new-refresh', 'expires_in' => 300]), $this->response(['id' => 1]),
            new MockResponse('', ['http_code' => 308, 'response_headers' => ['location: https://attacker.invalid/contracts/'.self::UUID]]),
        ]));
        self::assertSame(['id' => 1], $this->client->request(1, 'GET', '/me'));
        self::assertSame('new-refresh', $this->vault->open($this->store->credentials(1))['refresh_token']);
        $this->expectException(InsightException::class); $this->client->request(1, 'GET', '/contracts/'.self::UUID);
    }

    public function testDeadlineNeverFallsBackToPublicationOrWrongLotAndCanBeCleared(): void
    {
        $hit = $this->hit(); unset($hit['dealOpportunity']['response']);
        $hit['dealOpportunity']['deadline'] = '2030-06-02T12:00:00Z';
        self::assertNull(InsightNormalizer::row($hit, [])['deadline']);
        $hit['dealOpportunity']['dealOpportunityResultType'] = 'LOT';
        self::assertNull(InsightNormalizer::row($hit, ['detail' => ['contract' => ['response' => '2030-06-02T12:00:00Z']]])['deadline']);
        $hit['dealOpportunity']['response'] = null;
        self::assertNull(InsightNormalizer::row($hit, ['lots' => [['id' => self::UUID, 'response' => '2030-06-02T12:00:00Z']]])['deadline']);
        self::assertNull(InsightNormalizer::deadline('2030-02-31T12:00:00Z'));
        self::assertSame('2030-06-02 10:00:00', InsightNormalizer::deadline('2030-06-02T12:00:00'));
    }

    public function testAlertWindowTenantRecipientsDryRunAndIdempotency(): void
    {
        $now = new \DateTimeImmutable('2030-06-01T12:00:00Z');
        $config = $this->store->config(1);
        $config = array_replace($config, ['alerts' => true, 'sync_ok' => true, 'last_success' => '2030-06-01 11:00:00', 'recipients' => [10, 11], 'lead_hours' => 48]);
        $this->store->saveConfig(1, $config);
        $this->store->saveMarket(1, InsightNormalizer::row($this->hit(), []), ['contract_id' => self::UUID], 'gen');
        $recipients = $this->createMock(InsightRecipients::class);
        $recipients->method('available')->with(1)->willReturn([10 => ['id' => 10, 'name' => 'Destinataire', 'email' => 'recipient@example.test']]);
        $transport = $this->createMock(TransportInterface::class);
        $transport->expects(self::once())->method('send')->willReturnCallback(function ($email) {
            self::assertSame('recipient@example.test', $email->getTo()[0]->getAddress());
            self::assertStringContainsString('02/06/2030', $email->getHtmlBody());
            self::assertStringNotContainsString('<script>', $email->getHtmlBody());
            return null;
        });
        $router = $this->createStub(UrlGeneratorInterface::class); $router->method('generate')->willReturn('/fr/administrateur/1/marches/fiche/test');
        $alerts = new InsightAlerts($this->store, $recipients, $transport, new Environment(new FilesystemLoader(dirname(__DIR__, 3).'/templates')), $router, 'no-reply@example.test', 'https://app.example.test');
        self::assertSame(['eligible' => 1, 'sent' => 0, 'uncertain' => 0], $alerts->run(1, true, $now));
        self::assertSame(0, (int) $this->store->db->fetchOne('SELECT COUNT(*) FROM insight_delivery'));
        self::assertSame(1, $alerts->run(1, false, $now)['sent']);
        self::assertSame(0, $alerts->run(1, false, $now)['eligible']);
        self::assertSame(0, $alerts->run(1, false, $now->modify('+2 days'))['eligible']);
    }


    public function testUncertainSmtpIsRecordedWithoutAutomaticResend(): void
    {
        $now = new \DateTimeImmutable('2030-06-01T12:00:00Z');
        $this->store->saveConfig(1, array_replace($this->store->config(1), ['alerts' => true, 'sync_ok' => true, 'last_success' => '2030-06-01 11:00:00', 'recipients' => [10]]));
        $this->store->saveMarket(1, InsightNormalizer::row($this->hit(), []), ['contract_id' => self::UUID], 'gen');
        $recipients = $this->createStub(InsightRecipients::class);
        $recipients->method('available')->willReturn([10 => ['id' => 10, 'name' => 'Test', 'email' => 'test@example.test']]);
        $transport = $this->createMock(TransportInterface::class);
        $transport->expects(self::once())->method('send')->willThrowException(new \RuntimeException('Synthetic timeout'));
        $router = $this->createStub(UrlGeneratorInterface::class); $router->method('generate')->willReturn('/market');
        $alerts = new InsightAlerts($this->store, $recipients, $transport, new Environment(new FilesystemLoader(dirname(__DIR__, 3).'/templates')), $router, 'no-reply@example.test', 'https://app.example.test');
        self::assertSame(1, $alerts->run(1, false, $now)['uncertain']);
        self::assertSame('uncertain', $this->store->db->fetchOne('SELECT status FROM insight_delivery'));
        self::assertSame(0, $alerts->run(1, false, $now)['eligible']);
    }

    public function testExplicitRefreshPreservesPreviousDetail(): void
    {
        $this->store->saveCache(1, self::UUID, ['detail' => ['contract' => ['id' => self::UUID, 'reference' => 'previous']], 'lots' => [], 'lots_complete' => true, 'refresh_detail' => true]);
        $this->client->setHttpClient(new MockHttpClient([
            $this->response(['content' => [$this->hit()], 'number' => 0, 'totalPages' => 1]),
            $this->response(['contract' => ['id' => self::UUID, 'reference' => 'new']]),
        ]));
        self::assertSame(2, (new InsightImporter($this->store, $this->client))->run(1)['calls']);
        $cache = $this->store->cache(1, self::UUID);
        self::assertSame('new', $cache['detail']['contract']['reference']);
        self::assertSame('previous', $cache['previous_details'][0]['data']['contract']['reference']);
        self::assertArrayNotHasKey('refresh_detail', $cache);
    }

    public function testAllFieldsRemainInspectableWithUnambiguousPaths(): void
    {
        self::assertSame([['/a~1b/x~0y', 'false'], ['/empty', '[]']], InsightNormalizer::flatten(['a/b' => ['x~y' => false], 'empty' => []]));
    }
}
