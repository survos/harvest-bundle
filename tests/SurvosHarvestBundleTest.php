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

    public function testItemCriteriaAreSeparateFromFolioSelection(): void
    {
        $bundle = new SurvosHarvestBundle();
        $container = new ContainerBuilder();
        $container->registerExtension($bundle->getContainerExtension());
        $container->loadFromExtension('survos_harvest', [
            'folio_sets' => [
                'filtered' => ['criteria' => ['provider' => ['nara']], 'item_criteria' => ['minImages' => 1, 'tags' => ['ai:handwriting:mistral']]],
                'all' => ['criteria' => ['provider' => ['nara']]],
            ],
        ]);
        $extension = $bundle->getContainerExtension();
        $config = (new \Symfony\Component\Config\Definition\Processor())->processConfiguration(
            $extension->getConfiguration([], $container), $container->getExtensionConfig('survos_harvest'),
        );
        self::assertSame(['minImages' => 0, 'tags' => []], $config['folio_sets']['all']['item_criteria']);
        self::assertSame(['ai:handwriting:mistral'], $config['folio_sets']['filtered']['item_criteria']['tags']);
        self::assertSame($config['folio_sets']['all']['criteria'], $config['folio_sets']['filtered']['criteria']);
        $extension->prepend($container);
        $forwarded = $container->getExtensionConfig('survos_folio')[0]['folio_sets']['filtered'];
        self::assertArrayNotHasKey('item_criteria', $forwarded);
        self::assertSame(['nara'], $forwarded['criteria']['provider']);
    }

    public function testNegativeImageMinimumIsRejected(): void
    {
        $bundle = new SurvosHarvestBundle();
        $container = new ContainerBuilder();
        $extension = $bundle->getContainerExtension();
        $this->expectException(\Symfony\Component\Config\Definition\Exception\InvalidConfigurationException::class);
        (new \Symfony\Component\Config\Definition\Processor())->processConfiguration(
            $extension->getConfiguration([], $container),
            [['folio_sets' => ['bad' => ['criteria' => [], 'item_criteria' => ['minImages' => -1]]]]],
        );
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
