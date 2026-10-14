<?php

declare(strict_types=1);

namespace Survos\HarvestBundle\Menu;

use Survos\MenuBundle\Attribute\AsMenuListener;
use Survos\MenuBundle\Event\MenuEvent;
use Survos\MenuBundle\MenuSlot;
use Symfony\Bundle\SecurityBundle\Security;

/** Registered only when survos/menu-bundle is installed and Harvest routes/menu are enabled. */
final readonly class HarvestMenuSubscriber
{
    public function __construct(private Security $security)
    {
    }

    #[AsMenuListener(MenuSlot::ADMIN_NAVBAR_MENU)]
    public function onAdminNavbarMenu(MenuEvent $event): void
    {
        if (!$this->security->isGranted('ROLE_ADMIN')) {
            return;
        }
        $menu = $event->getMenu()->addChild('Harvest', ['route' => 'survos_harvest_admin']);
        $menu->setExtra('icon', 'harvest');
        $menu->addChild('Local catalog', ['route' => 'survos_harvest_admin']);
        $menu->addChild('Search Harvest API', ['route' => 'survos_harvest_search']);
        $menu->addChild('Folio-set help', ['route' => 'survos_harvest_help']);
    }
}
