<?php

declare(strict_types=1);
namespace App\Tests\Integration;

use App\Entity\{Entite, Utilisateur, UtilisateurEntite};
use App\Service\Intescia\InsightStore;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class AdminNavigationTest extends KernelTestCase
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

    public function testAdminNavigationKeepsAllDestinationsAndLogoutProtection(): void
    {
        $page = $this->client->request('GET', '/fr/administrateur/'.$this->tenant.'/marches');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $sidebar = $page->filter('#pf-sidebar');
        foreach (['Consommations', 'Reporting chantier', 'Appels d’offres', 'Bases consommations', 'Contacts', 'Identifiants externes', 'Cartes'] as $label) {
            self::assertStringContainsString($label, $sidebar->text());
        }
        self::assertCount(1, $sidebar->filter('a[aria-current="page"]'));
        // Appels d’offres is a direct link: no unrelated section should open on arrival.
        self::assertCount(0, $sidebar->filter('details[open]'));
        self::assertCount(1, $page->filter('.pf-account form[method="post"] input[name="_csrf_token"]'));
        self::assertStringContainsString('/engin', $sidebar->filter('a')->reduce(fn ($node) => trim($node->text()) === 'Liste des engins')->attr('href'));
        self::assertStringContainsString('/materiel', $sidebar->filter('a')->reduce(fn ($node) => trim($node->text()) === 'Liste du matériel')->attr('href'));
    }

    public function testChantierPageAndMapFallbackWithoutGeoapifyKey(): void
    {
        $previous = [$_ENV['GEOAPIFY_API_KEY'] ?? null, $_SERVER['GEOAPIFY_API_KEY'] ?? null, getenv('GEOAPIFY_API_KEY')];
        unset($_ENV['GEOAPIFY_API_KEY'], $_SERVER['GEOAPIFY_API_KEY']);
        putenv('GEOAPIFY_API_KEY');
        try {
            $this->client->request('GET', '/fr/administrateur/'.$this->tenant.'/chantier');
            self::assertSame(200, $this->client->getResponse()->getStatusCode());
            $controller = self::getContainer()->get(\App\Controller\Administrateur\ChantierController::class);
            $map = new \ReflectionMethod($controller, 'buildStaticMapBase64');
            self::assertStringStartsWith('data:image/svg+xml;base64,', $map->invoke($controller, '43.61', '3.87'));
        } finally {
            foreach (['ENV', 'SERVER'] as $index => $scope) {
                if ($previous[$index] === null) unset($GLOBALS['_'.$scope]['GEOAPIFY_API_KEY']);
                else $GLOBALS['_'.$scope]['GEOAPIFY_API_KEY'] = $previous[$index];
            }
            if ($previous[2] !== false) putenv('GEOAPIFY_API_KEY='.$previous[2]);
        }
    }

    public function testChefKeepsReportingAndContactsWithoutAdminMenus(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $membership = $em->getRepository(UtilisateurEntite::class)->findOneBy(['utilisateur' => $this->user, 'entite' => $this->tenant]);
        $membership->setRoles([UtilisateurEntite::TENANT_CHEF]);
        $em->flush();
        $page = $this->client->request('GET', '/fr/administrateur/'.$this->tenant.'/chantier');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $sidebar = $page->filter('#pf-sidebar')->text();
        self::assertStringContainsString('Reporting chantier', $sidebar);
        self::assertStringContainsString('Contacts', $sidebar);
        foreach (['Consommations', 'Appels d’offres', 'Bases consommations', 'Ajouter un chantier', 'Employés'] as $label) {
            self::assertStringNotContainsString($label, $sidebar);
        }
        self::assertCount(0, $page->filter('.pf-settings'));
        self::assertCount(1, $page->filter('#pf-sidebar details[open]'));
        self::assertStringContainsString('Reporting chantier', $page->filter('#pf-sidebar details[open] > summary')->text());
    }
}
