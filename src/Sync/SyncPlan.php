<?php

declare(strict_types=1);

namespace Survos\HarvestBundle\Sync;

use Survos\FolioBundle\Catalog\FolioCatalogEntry;

/** Prepared remote state; constructing this never writes local state. */
final readonly class SyncPlan
{
    /**
     * @param array<string, mixed> $checkpoint
     * @param array<string, FolioCatalogEntry> $entries Artifact identity => selected entry
     * @param array<string, bool> $downloads Artifact identity => whether any matching set requests download
     * @param array<string, array<string, bool>> $membership Set code => dataset key => download
     */
    public function __construct(
        public array $checkpoint,
        public array $entries,
        public array $downloads,
        public array $membership,
    ) {}
}
