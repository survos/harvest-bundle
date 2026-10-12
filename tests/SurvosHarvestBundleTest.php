<?php

declare(strict_types=1);

namespace Survos\HarvestBundle\Tests;

use PHPUnit\Framework\TestCase;
use Survos\HarvestBundle\SurvosHarvestBundle;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class SurvosHarvestBundleTest extends TestCase
{
    public function testScaffoldCompilesWithoutApplicationConfiguration(): void
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
