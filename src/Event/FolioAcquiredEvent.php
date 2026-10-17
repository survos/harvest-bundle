<?php

declare(strict_types=1);

namespace Survos\HarvestBundle\Event;

use Survos\FolioBundle\Catalog\FolioCatalogEntry;

/**
 * One folio was newly acquired or changed by harvest:sync. Dispatched after the sync's transaction
 * has committed, once per folio, so a listener can queue or run work for just that folio (index its
 * rows, warm its covers) without touching the rest of the catalog.
 */
final readonly class FolioAcquiredEvent
{
    public function __construct(public FolioCatalogEntry $entry)
    {
    }
}
