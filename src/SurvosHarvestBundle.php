<?php

declare(strict_types=1);

namespace Survos\HarvestBundle;

use Doctrine\ORM\EntityManagerInterface;
use Survos\FolioBundle\Catalog\DatasetPublicationClient;
use Survos\HarvestBundle\Catalog\HarvestCatalog;
use Survos\HarvestBundle\Admin\CatalogBrowser;
use Survos\HarvestBundle\Admin\HarvestSearch;
use Survos\HarvestBundle\Controller\HarvestAdminController;
use Survos\HarvestBundle\Menu\HarvestMenuSubscriber;
use Survos\TablerBundle\Event\MenuEvent;
use Survos\HarvestBundle\Sync\FolioAcquisition;
use Survos\HarvestBundle\Sync\FolioDownloader;
use Survos\HarvestBundle\Sync\HarvestSync;
use Survos\HarvestBundle\Sync\Selection;
use Survos\Kit\AbstractSurvosBundle;
use Survos\Kit\SurvosKitBundle;
use Survos\Kit\Traits\HasDoctrineEntities;
use Survos\Kit\Traits\HasConfigurableRoutes;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Kernel\RequiredBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;

#[RequiredBundle(SurvosKitBundle::class)]
// Symfony\Component\HttpKernel\Bundle\Bundle <-- Flex auto-registration marker (see Survos\Kit\AbstractSurvosBundle)
final class SurvosHarvestBundle extends AbstractSurvosBundle
{
    use HasDoctrineEntities;
    use HasConfigurableRoutes;

    public function configure(DefinitionConfigurator $definition): void
    {
        $this->addRouteOptions($definition->rootNode()->children(), '/admin/harvest');
        $definition->rootNode()->children()
            ->scalarNode('base_layout')->defaultValue('base.html.twig')->cannotBeEmpty()->end()
            ->booleanNode('admin_navbar_menu')->defaultTrue()->end()
            ->scalarNode('server')->defaultValue('%env(default:survos_harvest.default_server:HARVEST_SERVER)%')->cannotBeEmpty()->end()
            ->scalarNode('read_token')->defaultValue('%env(default:survos_harvest.empty_token:HARVEST_READ_TOKEN)%')->end()
            ->scalarNode('lock_factory')->defaultValue('lock.factory')->end()
            ->booleanNode('local_passthrough')->defaultFalse()->end()
            ->arrayNode('folio_sets')->normalizeKeys(false)->useAttributeAsKey('code')->arrayPrototype()->children()
                ->scalarNode('label')->defaultNull()->end()
                ->booleanNode('download')->info('False records membership without acquiring folio files.')->defaultTrue()->end()
                ->arrayNode('criteria')->isRequired()->children()
                    ->arrayNode('provider')->scalarPrototype()->end()->end()
                    ->arrayNode('tags')->scalarPrototype()->end()->end()
                    ->arrayNode('tagsAll')->scalarPrototype()->end()->end()
                    ->arrayNode('contentType')->scalarPrototype()->end()->end()
                    ->arrayNode('folios')->scalarPrototype()->end()->end()
                    ->integerNode('minRows')->min(0)->defaultValue(1)->end()
                ->end()->end()
            ->end()->end()->end()
        ->end();
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        parent::loadExtension($config, $container, $builder);
        $services = $container->services()->defaults()->autowire()->autoconfigure();
        $services->set(HarvestCatalog::class)->arg('$em', new Reference(EntityManagerInterface::class));
        $services->set(CatalogBrowser::class);
        $services->set(HarvestSearch::class)->arg('$cache', new Reference('cache.app'));
        $services->set(HarvestAdminController::class)->args(['$sets' => $config['folio_sets'], '$baseLayout' => $config['base_layout']]);
        if ($config['routes_enabled'] && $config['admin_navbar_menu'] && class_exists(MenuEvent::class)) {
            $services->set(HarvestMenuSubscriber::class);
        }
        $services->set(Selection::class)->arg('$sets', $config['folio_sets']);
        $services->set(FolioDownloader::class)->arg('$localPassthrough', $config['local_passthrough']);
        $services->alias(FolioAcquisition::class, FolioDownloader::class);
        $services->set(HarvestSync::class)->arg('$locks', new Reference($config['lock_factory']));
        // The transport is shared with folio-bundle's single-entry downloader.
        $services->set(DatasetPublicationClient::class)->args([
            '$server' => $config['server'], '$token' => $config['read_token'],
        ]);
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        parent::prependExtension($container, $builder);
        if ($builder->hasExtension('ux_icons')) {
            $builder->prependExtensionConfig('ux_icons', ['aliases' => ['harvest' => 'tabler:database-import']]);
        }
        $configs = $builder->getExtensionConfig('survos_harvest');
        $config = (new Processor())->processConfiguration($this->getContainerExtension()->getConfiguration($configs, $builder), $configs);
        $builder->setParameter('survos_harvest.default_server', 'https://harvest.survos.com');
        $builder->setParameter('survos_harvest.empty_token', '');
        foreach (['server', 'read_token', 'local_passthrough'] as $key) {
            $builder->setParameter('survos_harvest.'.$key, $config[$key]);
        }
        $builder->prependExtensionConfig('survos_folio', [
            'folio_server' => '%survos_harvest.server%',
            'dataset_api' => ['enabled' => true, 'server' => '%survos_harvest.server%', 'token' => '%survos_harvest.read_token%'],
            'local_passthrough' => '%survos_harvest.local_passthrough%',
        ]);
    }
}
