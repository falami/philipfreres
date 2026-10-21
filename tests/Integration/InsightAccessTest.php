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
}
