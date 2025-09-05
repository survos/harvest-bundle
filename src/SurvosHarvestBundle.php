<?php
/** generated from /home/tac/sites/survos/vendor/survos/maker-bundle/templates/skeleton/bundle/src/Bundle.tpl.php */

namespace Survos\HarvestBundle;

use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;


class SurvosHarvestBundle extends AbstractBundle implements CompilerPassInterface
{

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        foreach ([
                     RowComponent::class,
                 ] as $svc) {
            $builder->register($svc)
                ->setAutowired(true)
                ->setAutoconfigured(true)
                ->setPublic(true);
        }

        $container->register(Harvester::class)
            ->setAutowired(true)->setAutoconfigured(true)
            ->setArgument('$pixieConfigs', '%survos_harvest.pixies%');

        $container->register(NormalizationEngine::class)->setAutowired(true)->setAutoconfigured(true);
        $container->register(WorklistWriter::class)->setAutowired(true)->setAutoconfigured(true);
        $container->register(Packager::class)->setAutowired(true)->setAutoconfigured(true);
        $container->register(RunRegistry::class)->setAutowired(true)->setAutoconfigured(true);


    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
            ->scalarNode('direction')->defaultValue('LR')->end()
            ->scalarNode('base_layout')->defaultValue('base.html.twig')->end()
            ->arrayNode('entities')
            ->scalarPrototype()
            ->end()->end()
            ->booleanNode('enabled')->defaultTrue()->end()
//            ->integerNode('min_sunshine')->defaultValue(3)->end()
            ->end();

    }

    public function process(ContainerBuilder $container)
    {

        // TODO: Implement process() method.
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        // Autoconfigure DTO classes annotated with #[Mapper] -> tag "pixie.dto"
        $container->registerAttributeForAutoconfiguration(
            MapperAttr::class,
            static function (Definition $definition, MapperAttr $attr, \ReflectionClass $ref): void {
                $definition->addTag('pixie.dto', [
                    'priority' => $attr->priority,
                    'when' => $attr->when,
                    'except' => $attr->except,
                    'cores' => $attr->cores,
                ]);
            }
        );

        // If you still want this class as a compiler pass for other reasons, keep it.
        // Otherwise, you can drop the addCompilerPass($this) call entirely.
        $container->addCompilerPass($this);
    }

}
