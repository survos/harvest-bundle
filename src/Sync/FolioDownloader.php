<?php

declare(strict_types=1);

namespace Survos\HarvestBundle\Sync;

use Survos\FolioBundle\Catalog\FolioCatalogEntry;
use Survos\FolioBundle\Command\FolioPullCommand;
use Survos\FolioBundle\Service\FolioService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

final readonly class FolioDownloader implements FolioAcquisition
{
    public function __construct(private FolioPullCommand $pull, private FolioService $folios, private bool $localPassthrough) {}

    public function acquire(FolioCatalogEntry $entry, bool $changed, SymfonyStyle $io): bool
    {
        if ($this->localPassthrough || $entry->downloadUrl === null || !$entry->compressed) {
            return $this->folios->exists($entry->datasetKey, $entry->locale);
        }
        if ($this->pull->pullEntry($io, $entry, force: $changed) !== Command::SUCCESS) {
            throw new \RuntimeException('Could not acquire '.$entry->datasetKey.'.');
        }

        return true;
    }
}
