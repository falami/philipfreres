<?php

declare(strict_types=1);
namespace App\Tests\Integration;

use App\Entity\{Entite, Utilisateur, UtilisateurEntite};
use App\Service\Intescia\InsightStore;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class AdminFiltersTest extends KernelTestCase
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

    public function testSevenPagesRenderAndExplicitEmptySelectionsAreAccepted(): void
    {
        foreach (['chantier','calendrier','engin-utilisation','produits','materiel','dechet-type','mandataire'] as $path) {
            $this->client->request('GET', '/fr/administrateur/'.$this->tenant.'/'.$path);
            self::assertSame(200, $this->client->getResponse()->getStatusCode(), $path);
            self::assertStringContainsString('admin-filters.js', $this->client->getResponse()->getContent());
        }
        foreach (['chantier/ajax' => 'statutFilter', 'engin-utilisation/dt' => 'enginId', 'engin-utilisation/api/summary' => 'enginId'] as $path => $key) {
            $this->client->request('GET', '/fr/administrateur/'.$this->tenant.'/'.$path, [$key => '[]']);
            self::assertSame(200, $this->client->getResponse()->getStatusCode(), $path);
        }
        foreach (['produits' => 'categorieFilter', 'materiel' => 'categorieFilter', 'dechet-type' => 'uniteFilter', 'mandataire' => 'actifFilter'] as $path => $key) {
            foreach (['[]', '["1","2"]'] as $value) {
                $this->client->request('POST', '/fr/administrateur/'.$this->tenant.'/'.$path.'/ajax', [$key => $value]);
                self::assertSame(200, $this->client->getResponse()->getStatusCode(), $path);
                $data = json_decode($this->client->getResponse()->getContent(), true);
                self::assertSame(0, $data['recordsFiltered']);
            }
        }
    }
    public function testMultipleProductCategoriesAndNoneFilterActualRows(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $tenant = $em->find(Entite::class, $this->tenant);
        $other = $em->find(Entite::class, $this->other);
        $categories = array_slice(\App\Enum\CategorieProduit::cases(), 0, 3);
        foreach ($categories as $category) {
            $em->persist((new \App\Entity\Produit())->setEntite($tenant)->setCategorieProduit($category));
            $em->persist((new \App\Entity\Produit())->setEntite($other)->setCategorieProduit($category));
        }
        $em->flush();
        foreach (['all' => 3, '[]' => 0, json_encode([$categories[0]->value, $categories[1]->value]) => 2] as $selection => $expected) {
            $this->client->request('POST', '/fr/administrateur/'.$this->tenant.'/produits/ajax', ['categorieFilter' => $selection]);
            self::assertSame(200, $this->client->getResponse()->getStatusCode());
            $data = json_decode($this->client->getResponse()->getContent(), true);
            self::assertSame($expected, $data['recordsFiltered']);
            self::assertCount($expected, $data['data']);
        }
    }

    public function testEmployeeAndExternalIdentifierFilters(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->getConfiguration()->addCustomStringFunction('JSON_CONTAINS', FilterTestJsonContains::class);
        $em->getConnection()->getNativeConnection()->sqliteCreateFunction('JSON_CONTAINS', static fn ($json, $value) => (int) in_array(json_decode($value, true), json_decode($json, true), true), 2);
        foreach (['utilisateur', 'utilisateurs/external-ids'] as $path) {
            $this->client->request('GET', '/fr/administrateur/'.$this->tenant.'/'.$path);
            self::assertSame(200, $this->client->getResponse()->getStatusCode());
            self::assertStringContainsString('admin-filters.js', $this->client->getResponse()->getContent());
            self::assertStringContainsString('pf-filters', $this->client->getResponse()->getContent());
        }
        foreach (['[]' => 0, '["TENANT_ADMIN","TENANT_EMPLOYE"]' => 1, 'TENANT_EMPLOYE' => 0] as $selection => $count) {
            $this->client->request('POST', '/fr/administrateur/'.$this->tenant.'/utilisateur/ajax', ['rolesFilter' => $selection]);
            self::assertSame(200, $this->client->getResponse()->getStatusCode());
            self::assertSame($count, json_decode($this->client->getResponse()->getContent(), true)['recordsFiltered']);
        }
        foreach (\App\Enum\ExternalProvider::cases() as $provider) {
            $em->persist((new \App\Entity\UtilisateurExternalId($provider, 'test-'.$provider->value))->setUtilisateur($em->find(Utilisateur::class, $this->user->getId())));
        }
        $em->flush();
        foreach (['[]' => 0, '["alx","total"]' => 2, 'all' => 3] as $selection => $count) {
            $this->client->request('POST', '/fr/administrateur/'.$this->tenant.'/utilisateurs/external-ids/ajax', ['providerFilter' => $selection]);
            self::assertSame(200, $this->client->getResponse()->getStatusCode());
            self::assertSame($count, json_decode($this->client->getResponse()->getContent(), true)['recordsFiltered']);
        }
    }

    public function testEnginFilters(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        foreach ([$this->tenant, $this->other] as $tenantId) {
            foreach ([\App\Enum\EnginType::CAMION, \App\Enum\EnginType::PELLE, \App\Enum\EnginType::PORTEUR] as $i => $type) {
                $engin = (new \App\Entity\Engin())->setNom('Engin '.$i)->setType($type)->setEntite($em->find(Entite::class, $tenantId))->setCreateur($this->user);
                $em->persist($engin);
                if ($i < 2) {
                    $provider = $i === 0 ? \App\Enum\ExternalProvider::ALX : \App\Enum\ExternalProvider::TOTAL;
                    $em->persist((new \App\Entity\EnginExternalId($provider, 'engin-'.$tenantId.'-'.$i))->setEngin($engin));
                }
            }
        }
        $em->flush();
        $this->client->request('GET', '/fr/administrateur/'.$this->tenant.'/engin');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('admin-filters.js', $this->client->getResponse()->getContent());
        foreach ([
            [[], 3],
            [['typeFilter' => '[]'], 0],
            [['typeFilter' => '["camion","pelle"]'], 2],
            [['providerFilter' => '[]'], 0],
            [['providerFilter' => '["alx","total"]'], 2],
            [['providerFilter' => 'alx', 'typeFilter' => 'pelle'], 0],
            [['providerFilter' => 'alx'], 1],
            [['search' => ['value' => 'Engin 2']], 1],
        ] as [$filters, $expected]) {
            $this->client->request('POST', '/fr/administrateur/'.$this->tenant.'/engin/ajax', $filters);
            self::assertSame(200, $this->client->getResponse()->getStatusCode());
            $data = json_decode($this->client->getResponse()->getContent(), true);
            self::assertSame(3, $data['recordsTotal']);
            self::assertSame($expected, $data['recordsFiltered']);
            self::assertCount($expected, $data['data']);
        }
    }

}

/** Test-only SQL adapter; production continues to use MySQL JSON_CONTAINS. */
final class FilterTestJsonContains extends \Scienta\DoctrineJsonFunctions\Query\AST\Functions\Mysql\JsonContains
{
    protected function validatePlatform(\Doctrine\ORM\Query\SqlWalker $sqlWalker): void {}
}
