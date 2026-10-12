<?php

declare(strict_types=1);

namespace Survos\HarvestBundle\Tests;

use PHPUnit\Framework\TestCase;
use Survos\HarvestBundle\SurvosHarvestBundle;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class SurvosHarvestBundleTest extends TestCase
{
    public function testKitRegistersOnlyMappingsWithoutADatabaseConnection(): void
    {
        $bundle = new SurvosHarvestBundle();
        $container = new ContainerBuilder();
        $bundle->getContainerExtension()->prepend($container);
        $config = $container->getExtensionConfig('doctrine');
        self::assertCount(1, $config);
        self::assertArrayNotHasKey('dbal', $config[0]);
        self::assertSame('Survos\\HarvestBundle\\Entity', $config[0]['orm']['mappings']['SurvosHarvestBundle']['prefix']);
        self::assertDirectoryExists($config[0]['orm']['mappings']['SurvosHarvestBundle']['dir']);
    }

    public function testEnvironmentBooleanAndSitesAreForwardedWithoutResolvingSecrets(): void
    {
        $bundle = new SurvosHarvestBundle();
        $container = new ContainerBuilder();
        $container->registerExtension($bundle->getContainerExtension());
        $container->setParameter('env(HARVEST_TEST_SHARED)', '0');
        $container->loadFromExtension('survos_harvest', [
            'local_passthrough' => '%env(bool:HARVEST_TEST_SHARED)%',
            'folio_sets' => ['photos' => ['download' => false, 'criteria' => ['tags' => ['photos']]]],
            'sites' => ['photos' => ['hosts' => ['photos.example'], 'sets' => ['photos']]],
        ]);
        $bundle->getContainerExtension()->prepend($container);
        self::assertFalse($container->resolveEnvPlaceholders($container->getParameter('survos_harvest.local_passthrough'), true));
        $folio = $container->getExtensionConfig('survos_folio')[0];
        self::assertSame(['photos'], $folio['sites']['photos']['sets']);
        self::assertArrayNotHasKey('download', $folio['folio_sets']['photos']);
        self::assertSame('obj', $folio['folio_sets']['photos']['core']);
    }

    public function testBundleCompilesWithoutApplicationConfiguration(): void
    {
        $bundle = new SurvosHarvestBundle();
        $extension = $bundle->getContainerExtension();
        self::assertNotNull($extension);
        self::assertSame('survos_harvest', $extension->getAlias());

        $container = new ContainerBuilder();
        $bundle->build($container);
        $container->registerExtension($extension);
        $container->loadFromExtension($extension->getAlias(), []);
        $container->compile();

        self::assertTrue($container->isCompiled());
    }
}
