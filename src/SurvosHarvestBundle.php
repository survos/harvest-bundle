<?php

declare(strict_types=1);

namespace Survos\HarvestBundle;

use Doctrine\ORM\EntityManagerInterface;
use Survos\FolioBundle\Catalog\DatasetPublicationClient;
use Survos\FolioBundle\Set\RecordedMembershipProvider;
use Survos\HarvestBundle\Admin\CatalogBrowser;
use Survos\HarvestBundle\Admin\HarvestSearch;
use Survos\HarvestBundle\Catalog\HarvestCatalog;
use Survos\HarvestBundle\Catalog\RecordedMembership;
use Survos\HarvestBundle\Controller\HarvestAdminController;
use Survos\HarvestBundle\Menu\HarvestMenuSubscriber;
use Survos\HarvestBundle\Schedule\HarvestSchedule;
use Survos\HarvestBundle\Sync\FolioAcquisition;
use Survos\HarvestBundle\Sync\FolioDownloader;
use Survos\HarvestBundle\Sync\HarvestSelection;
use Survos\HarvestBundle\Sync\HarvestSync;
use Survos\HarvestBundle\Sync\Selection;
use Survos\Kit\AbstractSurvosBundle;
use Survos\Kit\SurvosKitBundle;
use Survos\Kit\Traits\HasConfigurableRoutes;
use Survos\Kit\Traits\HasDoctrineEntities;
use Survos\TablerBundle\Event\MenuEvent;
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
            ->integerNode('catalog_ttl')->min(0)->defaultValue(300)->end()
            ->booleanNode('schedule_enabled')->defaultTrue()->end()
            ->scalarNode('schedule_interval')->info('A RecurringMessage::every() interval, e.g. "2 minutes".')
                ->defaultValue('%env(default:survos_harvest.default_interval:HARVEST_SYNC_INTERVAL)%')->cannotBeEmpty()->end()
            ->arrayNode('sites')->normalizeKeys(false)->useAttributeAsKey('code')->arrayPrototype()->children()
                ->arrayNode('hosts')->isRequired()->requiresAtLeastOneElement()->scalarPrototype()->end()->end()
                ->arrayNode('sets')->isRequired()->requiresAtLeastOneElement()->scalarPrototype()->end()->end()
                ->booleanNode('restrict')->defaultFalse()->end()
            ->end()->end()->end()
            ->booleanNode('local_passthrough')->defaultFalse()->end()
            ->arrayNode('folio_sets')->normalizeKeys(false)->useAttributeAsKey('code')->arrayPrototype()->children()
                ->scalarNode('label')->defaultNull()->end()
                ->scalarNode('core')->defaultValue('obj')->end()
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
        $services->set(RecordedMembership::class);
        $services->alias(RecordedMembershipProvider::class, RecordedMembership::class);
        if ($config['schedule_enabled']) {
            $services->set(HarvestSchedule::class)->args(['$cache' => new Reference('cache.app'), '$interval' => $config['schedule_interval']]);
        }
        $services->set(CatalogBrowser::class);
        $services->set(HarvestSearch::class)->arg('$cache', new Reference('cache.app'));
        $services->set(HarvestAdminController::class)->args(['$sets' => $config['folio_sets'], '$baseLayout' => $config['base_layout']]);
        if ($config['routes_enabled'] && $config['admin_navbar_menu'] && class_exists(MenuEvent::class)) {
            $services->set(HarvestMenuSubscriber::class);
        }
        $services->set(Selection::class)->arg('$sets', $config['folio_sets']);
        $services->alias(HarvestSelection::class, Selection::class);
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
        $builder->setParameter('survos_harvest.default_server', 'https://harvest.survos.com');
        $builder->setParameter('survos_harvest.empty_token', '');
        $builder->setParameter('survos_harvest.default_interval', '2 minutes');
        $bag = $builder->getParameterBag();
        if ($bag instanceof \Symfony\Component\DependencyInjection\ParameterBag\EnvPlaceholderParameterBag) {
            \Symfony\Component\Config\Definition\BaseNode::setPlaceholderUniquePrefix($bag->getEnvPlaceholderUniquePrefix());
        }
        $configs = $bag->resolveValue($builder->getExtensionConfig('survos_harvest'));
        $config = (new Processor())->processConfiguration($this->getContainerExtension()->getConfiguration($configs, $builder), $configs);
        foreach (['server', 'read_token', 'local_passthrough'] as $key) {
            $builder->setParameter('survos_harvest.'.$key, $config[$key]);
        }
        $builder->prependExtensionConfig('survos_folio', [
            'folio_server' => '%survos_harvest.server%',
            'dataset_api' => ['enabled' => true, 'server' => '%survos_harvest.server%', 'token' => '%survos_harvest.read_token%'],
            'local_passthrough' => '%survos_harvest.local_passthrough%',
            'catalog_ttl' => $config['catalog_ttl'],
            'folio_sets' => array_map(static function (array $set): array {
                unset($set['download']);

                return $set;
            }, $config['folio_sets']),
            'sites' => $config['sites'],
        ]);
    }
}
