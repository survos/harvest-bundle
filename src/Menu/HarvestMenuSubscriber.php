<?php

declare(strict_types=1);

namespace Survos\HarvestBundle\Menu;

use Survos\TablerBundle\Event\MenuEvent;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** Registered only when Tabler is installed and Harvest routes/menu are enabled. */
final readonly class HarvestMenuSubscriber
{
    public function __construct(private Security $security) {}

    #[AsEventListener(event: MenuEvent::ADMIN_NAVBAR_MENU)]
    public function onAdminNavbarMenu(MenuEvent $event): void
    {
        if (!$this->security->isGranted('ROLE_ADMIN')) { return; }
        $menu = $event->getMenu()->addChild('Harvest', ['route' => 'survos_harvest_admin']);
        $menu->setExtra('icon', 'harvest');
        $menu->addChild('Local catalog', ['route' => 'survos_harvest_admin']);
        $menu->addChild('Search Harvest API', ['route' => 'survos_harvest_search']);
        $menu->addChild('Folio-set help', ['route' => 'survos_harvest_help']);
    }
}
