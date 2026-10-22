<?php

declare(strict_types=1);
namespace App\Tests\Integration;

use App\Entity\{Entite, Utilisateur, UtilisateurEntite};
use App\Service\Intescia\InsightStore;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class InsightAccessTest extends KernelTestCase
{
    private KernelBrowser $client;
    private array $previous = [];
    private int $tenant;
    private int $other;
    private Utilisateur $user;

    protected function setUp(): void
    {
        foreach (['DATABASE_URL' => 'sqlite:///:memory:', 'MAILER_DSN' => 'null://null', 'APP_URL' => 'https://app.example.test', 'INTESCIA_ENCRYPTION_KEY' => base64_encode(random_bytes(32))] as $key => $value) {
            $this->previous[$key] = [$_ENV[$key] ?? null, $_SERVER[$key] ?? null]; $_ENV[$key] = $_SERVER[$key] = $value;
        }
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertSame('pdo_sqlite', $em->getConnection()->getParams()['driver']);
        (new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());
        $this->user = (new Utilisateur())->setEmail('insight-admin@example.test')->setPrenom('Camille')->setNom('Exemple')->setPassword('not-used');
        $em->persist($this->user); $em->flush();
        $entity = (new Entite())->setNom('Philip Frères · Démonstration')->setCreateur($this->user)->setPublic(false)->setIsActive(true);
        $other = (new Entite())->setNom('Autre entité')->setCreateur($this->user)->setPublic(false)->setIsActive(true);
        $em->persist($entity); $em->persist($other); $em->flush();
        $this->user->setEntite($entity);
        $em->persist((new UtilisateurEntite())->setUtilisateur($this->user)->setEntite($entity)->setCreateur($this->user)->setRoles([UtilisateurEntite::TENANT_ADMIN]));
        $em->flush(); $this->tenant = $entity->getId(); $this->other = $other->getId();
        $this->client = new KernelBrowser(self::$kernel); $this->client->disableReboot(); $this->client->loginUser($this->user);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach ($this->previous as $key => $values) foreach (['ENV', 'SERVER'] as $i => $scope) {
            if ($values[$i] === null) unset($GLOBALS['_'.$scope][$key]); else $GLOBALS['_'.$scope][$key] = $values[$i];
        }
    }

    public function testMailButtonsRequireCsrfAndDoNotConsumeScheduledDeliveries(): void
    {
        $base = '/fr/administrateur/'.$this->tenant.'/marches';
        $this->client->request('POST', $base.'/mail-test/digest');
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->client->request('POST', '/fr/administrateur/'.$this->other.'/marches/mail-test/digest');
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        $page = $this->client->request('GET', $base);
        $csrf = $page->filter('input[name="_token"]')->first()->attr('value');
        $this->client->request('POST', $base.'/mail-test/digest', ['_token'=>$csrf, 'email'=>'unwanted@example.test']);
        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        $this->client->followRedirect();
        self::assertStringContainsString('Mail de test transmis au serveur SMTP pour insight-admin@example.test', $this->client->getResponse()->getContent());
        $db = self::getContainer()->get(InsightStore::class)->db;
        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM insight_digest_delivery'));
        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM insight_delivery'));
        $this->client->request('POST', $base.'/mail-test/deadline', ['_token'=>$csrf]);
        $this->client->followRedirect();
        self::assertStringContainsString('Patientez une minute', $this->client->getResponse()->getContent());
    }

    public function testDeadlineTestMailCanRenderWithoutImportedMarket(): void
    {
        $base = '/fr/administrateur/'.$this->tenant.'/marches';
        $page = $this->client->request('GET', $base);
        $csrf = $page->filter('input[name="_token"]')->first()->attr('value');
        $this->client->request('POST', $base.'/mail-test/deadline', ['_token'=>$csrf]);
        $this->client->followRedirect();
        self::assertStringContainsString('Mail de test transmis au serveur SMTP', $this->client->getResponse()->getContent());
    }

    public function testAdminPageAndTableRenderWithoutCredentialsOrApi(): void
    {
        $this->client->request('GET', '/fr/administrateur/'.$this->tenant.'/marches');
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), substr($this->client->getResponse()->getContent(), 0, 500));
        self::assertStringContainsString('Les bonnes opportunités.', $this->client->getResponse()->getContent());
        self::assertStringContainsString('no-store', $this->client->getResponse()->headers->get('Cache-Control'));
        $this->client->request('GET', '/fr/administrateur/'.$this->tenant.'/marches/donnees');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame(0, json_decode($this->client->getResponse()->getContent(), true)['recordsTotal']);
    }

    public function testOtherTenantAndForgedOAuthAreDenied(): void
    {
        foreach (['', '/donnees', '/oauth/retour?state=forged&code=forged'] as $suffix) {
            $this->client->request('GET', '/fr/administrateur/'.$this->other.'/marches'.$suffix);
            self::assertSame(403, $this->client->getResponse()->getStatusCode());
        }
        $this->client->request('GET', '/fr/administrateur/'.$this->tenant.'/marches/oauth/retour?state=forged&code=forged');
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->client->request('POST', '/fr/administrateur/'.$this->tenant.'/marches/action/import');
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }


    public function testOAuthConnectionAndSettingsWithMockProvider(): void
    {
        $store = self::getContainer()->get(InsightStore::class);
        $api = self::getContainer()->get(\App\Service\Intescia\InsightClient::class);
        $status = '11111111-1111-4111-8111-111111111111';
        $api->setHttpClient(new \Symfony\Component\HttpClient\MockHttpClient([
            new \Symfony\Component\HttpClient\Response\MockResponse(json_encode(['access_token' => 'test-access', 'refresh_token' => 'test-refresh', 'expires_in' => 300])),
            new \Symfony\Component\HttpClient\Response\MockResponse(json_encode(['id' => 12, 'email' => 'doubletrade@example.test', 'fullName' => 'Compte de test'])),
            new \Symfony\Component\HttpClient\Response\MockResponse(json_encode([['id' => $status, 'name' => 'GO']])),
        ]));
        $page = $this->client->request('GET', '/fr/administrateur/'.$this->tenant.'/marches');
        $csrf = $page->filter('input[name="_token"]')->first()->attr('value');
        $this->client->request('POST', '/fr/administrateur/'.$this->tenant.'/marches/connexion', ['_token' => $csrf, 'client_id' => 'test-client', 'client_secret' => 'secret-never-displayed']);
        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        $location = $this->client->getResponse()->headers->get('Location');
        self::assertStringStartsWith('https://sso.intescia.com/', $location);
        parse_str(parse_url($location, PHP_URL_QUERY), $query);
        self::assertSame('S256', $query['code_challenge_method']);
        self::assertStringNotContainsString('secret-never-displayed', $store->credentials($this->tenant));
        $this->client->request('GET', '/fr/administrateur/'.$this->tenant.'/marches/oauth/retour', ['state' => $query['state'], 'code' => 'synthetic-code']);
        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        self::assertSame(12, $store->config($this->tenant)['account']['id']);
        $this->client->request('POST', '/fr/administrateur/'.$this->tenant.'/marches/parametres', ['_token' => $csrf, 'statuses' => [$status], 'recipients' => [$this->user->getId()], 'lead_hours' => 48, 'max_calls' => 100, 'alerts' => 'on', 'include_details' => 'on', 'include_lots' => 'on']);
        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        self::assertTrue($store->config($this->tenant)['alerts']);
        self::assertSame([$this->user->getId()], $store->config($this->tenant)['recipients']);
        $this->client->request('GET', '/fr/administrateur/'.$this->tenant.'/marches/oauth/retour', ['state' => $query['state'], 'code' => 'replayed-code']);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testSettingsRejectRecipientOutsideTenant(): void
    {
        $page = $this->client->request('GET', '/fr/administrateur/'.$this->tenant.'/marches');
        $csrf = $page->filter('input[name="_token"]')->first()->attr('value');
        $this->client->request('POST', '/fr/administrateur/'.$this->tenant.'/marches/parametres', ['_token' => $csrf, 'recipients' => [999999], 'lead_hours' => 48, 'max_calls' => 100]);
        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        self::assertSame([], self::getContainer()->get(InsightStore::class)->config($this->tenant)['recipients']);
        $this->client->followRedirect();
        self::assertStringContainsString('Un destinataire ne fait plus partie', $this->client->getResponse()->getContent());
    }

    public function testFullDetailsEscapeApiMarkupAndStayTenantScoped(): void
    {
        $store = self::getContainer()->get(InsightStore::class);
        $remote = '11111111-1111-4111-8111-111111111111';
        $store->saveMarket($this->tenant, ['remote_id' => $remote, 'title' => '<script>alert(1)</script>', 'buyer' => 'Mairie', 'deadline' => '2030-06-02 12:00:00'], ['contract_id' => $remote, 'extra' => '<img src=x onerror=alert(1)>'], 'test');
        $id = InsightStore::id($this->tenant, $remote);
        $this->client->request('GET', '/fr/administrateur/'.$this->tenant.'/marches/fiche/'.$id);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringNotContainsString('<script>alert(1)</script>', $this->client->getResponse()->getContent());
        self::assertStringContainsString('&lt;script&gt;', $this->client->getResponse()->getContent());
        $this->client->request('GET', '/fr/administrateur/'.$this->other.'/marches/fiche/'.$id.'?format=json');
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }
    public function testPreparationPersistsIsTenantScopedAndRejectsStaleRevision(): void
    {
        $store = self::getContainer()->get(InsightStore::class);
        $remote = '11111111-1111-4111-8111-111111111112';
        $id = InsightStore::id($this->tenant, $remote);
        $row = ['remote_id' => $remote, 'title' => 'Entretien des arbres', 'buyer' => 'Acheteur', 'deadline' => '2030-11-02 11:00:00'];
        $store->saveMarket($this->tenant, $row, ['contract_id' => $remote], 'test');
        $base = '/fr/administrateur/'.$this->tenant.'/marches/fiche/'.$id;
        $page = $this->client->request('GET', $base);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $csrf = $page->filter('#tender-preparation input[name="_token"]')->attr('value');
        $this->client->request('POST', $base.'/preparation', ['decision' => 'go']);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        $input = ['_token' => $csrf, 'source_version' => $page->filter('#tender-preparation input[name="source_version"]')->attr('value'), 'revision' => '0', 'decision' => 'partial', 'owner' => (string) $this->user->getId(), 'notes' => '<script>alert(1)</script>', 'scope' => 'Lot 01', 'tasks' => ['pricing' => ['done' => '1', 'due' => '2030-10-30']], 'costs' => [['label' => 'Équipe', 'quantity' => '2.5', 'cost' => '100', 'price' => '150']], 'documents' => [['title' => 'CCTP', 'url' => 'https://example.test/dce', 'status' => 'received']]];
        $this->client->request('POST', $base.'/preparation', $input);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());
        $saved = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame(1, $saved['revision']);
        self::assertEquals(125, $saved['totals']['margin']);
        $this->client->request('POST', $base.'/preparation', $input);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->client->request('POST', '/fr/administrateur/'.$this->other.'/marches/fiche/'.$id.'/preparation', $input);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        // A subsequent provider import must never overwrite internal preparation.
        $store->saveMarket($this->tenant, $row, ['contract_id' => $remote, 'extra' => 'new'], 'new-import');
        $this->client->request('GET', $base);
        $html = $this->client->getResponse()->getContent();
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('Lot 01', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        $store->db->update('insight_market', ['deadline' => '2030-11-04 11:00:00'], ['id' => $id]);
        $input['revision'] = '1';
        $this->client->request('POST', $base.'/preparation', $input);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('changé pendant', json_decode($this->client->getResponse()->getContent(), true)['message']);
        $store->db->update('insight_market', ['deadline' => $row['deadline']], ['id' => $id]);
        $input['revision'] = '1'; $input['owner'] = '999999';
        $this->client->request('POST', $base.'/preparation', $input);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        $input['owner'] = ''; $input['documents'][0]['url'] = 'javascript:alert(1)';
        $this->client->request('POST', $base.'/preparation', $input);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
    }

    public function testMissingPreparationMigrationDoesNotBreakMarketDetails(): void
    {
        $store = self::getContainer()->get(InsightStore::class);
        $remote = '11111111-1111-4111-8111-111111111113';
        $store->saveMarket($this->tenant, ['remote_id' => $remote, 'title' => 'Marché', 'buyer' => 'Acheteur', 'deadline' => null], ['contract_id' => $remote], 'test');
        $store->db->executeStatement('DROP TABLE insight_preparation');
        $this->client->request('GET', '/fr/administrateur/'.$this->tenant.'/marches/fiche/'.InsightStore::id($this->tenant, $remote));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('après la mise à jour de la base', $this->client->getResponse()->getContent());
    }

}
