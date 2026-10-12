<?php

declare(strict_types=1);

namespace Survos\HarvestBundle\Tests;

use PHPUnit\Framework\TestCase;
use Survos\HarvestBundle\SurvosHarvestBundle;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Yaml\Yaml;

final class SurvosHarvestBundleTest extends TestCase
{
    public function testScaffoldCompilesWithTheShippedConfiguration(): void
    {
        $bundle = new SurvosHarvestBundle();
        $extension = $bundle->getContainerExtension();
        self::assertNotNull($extension);
        self::assertSame('survos_harvest', $extension->getAlias());

        $container = new ContainerBuilder();
        $bundle->build($container);
        $container->registerExtension($extension);
        $config = Yaml::parseFile(dirname(__DIR__).'/config/survos_harvest.yaml');
        $container->loadFromExtension($extension->getAlias(), $config[$extension->getAlias()]);
        $container->compile();

        self::assertTrue($container->isCompiled());
    }
}
