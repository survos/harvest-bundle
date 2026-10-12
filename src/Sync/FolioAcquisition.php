<?php

declare(strict_types=1);

namespace Survos\HarvestBundle\Sync;

use Survos\FolioBundle\Catalog\FolioCatalogEntry;
use Symfony\Component\Console\Style\SymfonyStyle;

interface FolioAcquisition
{
    /** Whether the folio is present after applying the requested acquisition policy. */
    public function acquire(FolioCatalogEntry $entry, bool $changed, SymfonyStyle $io): bool;
}
