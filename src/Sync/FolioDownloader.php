<?php

declare(strict_types=1);

namespace Survos\HarvestBundle\Sync;

use Survos\FolioBundle\Catalog\FolioCatalogEntry;
use Survos\FolioBundle\Command\FolioPullCommand;
use Survos\FolioBundle\Service\DuckDbFolioPreparer;
use Survos\FolioBundle\Service\FolioService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

final readonly class FolioDownloader implements FolioAcquisition
{
    public function __construct(private FolioPullCommand $pull, private FolioService $folios, private bool $localPassthrough,
        private DuckDbFolioPreparer $duckdb = new DuckDbFolioPreparer(), private bool $prepareDuckdb = false, private bool $duckdbFts = false)
    {
    }

    public function acquire(FolioCatalogEntry $entry, bool $changed, SymfonyStyle $io): bool
    {
        if ($this->localPassthrough || null === $entry->downloadUrl || !$entry->compressed) {
            if (!$this->folios->exists($entry->datasetKey, $entry->locale)) {
                return false;
            }
        } elseif (Command::SUCCESS !== $this->pull->pullEntry($io, $entry, force: $changed)) {
            throw new \RuntimeException('Could not acquire '.$entry->datasetKey.'.');
        }

        if ($this->prepareDuckdb) {
            $result = $this->duckdb->prepare($this->folios->path($entry->datasetKey, locale: $entry->locale), fts: $this->duckdbFts);
            $io->text(sprintf('DuckDB %s: %s (%s records)', $result['reused'] ? 'current' : 'prepared', $result['path'], number_format($result['rows'])));
        }

        return true;
    }
}
