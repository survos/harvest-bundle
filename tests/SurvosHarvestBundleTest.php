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
