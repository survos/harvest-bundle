<?php

declare(strict_types=1);

namespace Survos\HarvestBundle\Tests\Fixture;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Survos\FolioBundle\Catalog\DatasetField as Field;
use Survos\FolioBundle\Catalog\DatasetPublicationClient;
use Survos\HarvestBundle\Catalog\HarvestCatalog;
use Survos\HarvestBundle\SurvosHarvestBundle;
use Survos\HarvestBundle\Sync\FolioAcquisition;
use Survos\HarvestBundle\Sync\Selection;
use Survos\Kit\SurvosKitBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

final class AdminKernel extends Kernel
{
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new SecurityBundle();
        yield new TwigBundle();
        yield new SurvosKitBundle();
        yield new SurvosHarvestBundle();
    }

    public function getCacheDir(): string { return sys_get_temp_dir().'/harvest-admin-test-cache'; }
    public function getLogDir(): string { return $this->getCacheDir().'/logs'; }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', ['secret' => 'test-only', 'test' => true, 'csrf_protection' => true,
            'router' => ['utf8' => true], 'session' => ['storage_factory_id' => 'session.storage.factory.mock_file']]);
        $container->extension('twig', ['default_path' => __DIR__.'/templates', 'strict_variables' => true]);
        $container->extension('security', [
            'providers' => ['test' => ['memory' => ['users' => [
                'admin' => ['password' => 'unused', 'roles' => ['ROLE_ADMIN']],
                'reader' => ['password' => 'unused', 'roles' => ['ROLE_USER']],
            ]]]],
            'firewalls' => ['main' => ['pattern' => '^/', 'provider' => 'test', 'http_basic' => true]],
        ]);
        $container->extension('survos_harvest', ['server' => 'https://harvest.example', 'read_token' => 'hidden-test-token',
            'folio_sets' => ['tours' => ['label' => 'Tours', 'download' => false, 'criteria' => ['provider' => ['curatescape'], 'minRows' => 1]]]]);
        $services = $container->services();
        $services->set('logger', \Psr\Log\NullLogger::class);
        foreach ([EntityManagerInterface::class, DatasetPublicationClient::class, FolioAcquisition::class, 'lock.factory'] as $id) {
            $services->set($id)->synthetic()->public();
        }
        $services->set(HarvestCatalog::class)->autowire()->public();
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('@SurvosHarvestBundle/src/Controller/', 'attribute')->prefix('/admin/harvest');
    }

    public function seed(array $dataset): void
    {
        $config = ORMSetup::createAttributeMetadataConfiguration([dirname(__DIR__, 2).'/src/Entity'], true);
        $config->enableNativeLazyObjects(true);
        $config->setNamingStrategy(new UnderscoreNamingStrategy());
        $em = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]), $config);
        (new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());
        $http = new MockHttpClient(static function (string $method, string $url) use ($dataset): MockResponse {
            $body = str_contains($url, '/api/changes')
                ? [Field::CURSOR => 'epoch:1', Field::THROUGH => 'epoch:1', Field::ITEMS => [], Field::HAS_MORE => false]
                : [Field::ITEMS => [$dataset], Field::NEXT => null];
            return new MockResponse(json_encode($body, JSON_THROW_ON_ERROR));
        });
        $client = new DatasetPublicationClient($http, 'https://harvest.example', 'hidden-test-token');
        $container = $this->getContainer();
        $container->set(EntityManagerInterface::class, $em);
        $container->set(DatasetPublicationClient::class, $client);
        $container->set('lock.factory', new LockFactory(new InMemoryStore()));
        $container->set(FolioAcquisition::class, new class implements FolioAcquisition {
            public function acquire(\Survos\FolioBundle\Catalog\FolioCatalogEntry $entry, bool $changed, \Symfony\Component\Console\Style\SymfonyStyle $io): bool
            { throw new \LogicException('Track-only test must never download.'); }
        });
        $selection = new Selection(['tours' => ['download' => false, 'criteria' => ['provider' => ['curatescape'], 'tags' => [], 'tagsAll' => [], 'contentType' => [], 'folios' => [], 'minRows' => 1]]]);
        $catalog = $container->get(HarvestCatalog::class);
        $catalog->commit($selection->plan([Field::SOURCE => $client->source(), Field::VERSION => 1,
            Field::CURSOR => 'epoch:1', Field::ITEMS => [$dataset[Field::DATASET_KEY] => $dataset]], $client), []);
    }
}
