<?php

declare(strict_types=1);

namespace Survos\HarvestBundle\Event;

use Survos\HarvestBundle\Sync\SyncPlan;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Membership has been flushed inside the transaction; throwing rolls back the catalog and cursor.
 *
 * $full: this was harvest:sync --full, so $plan is the complete catalog of one Harvest and the
 * bundle's own tables were purged first. An application projection should drop whatever it holds
 * that is not in $plan, or switching HARVEST_SERVER leaves the other server's rows behind.
 */
final readonly class HarvestSyncedEvent
{
    public function __construct(public SymfonyStyle $io, public SyncPlan $plan, public bool $full = false) {}
}
