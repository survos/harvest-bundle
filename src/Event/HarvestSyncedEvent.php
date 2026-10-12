<?php

declare(strict_types=1);

namespace Survos\HarvestBundle\Event;

use Survos\HarvestBundle\Sync\SyncPlan;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Membership has been flushed inside the transaction; throwing rolls back the catalog and cursor. */
final readonly class HarvestSyncedEvent
{
    public function __construct(public SymfonyStyle $io, public SyncPlan $plan) {}
}
