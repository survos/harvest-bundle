<?php

declare(strict_types=1);

namespace Survos\HarvestBundle\Set;

use Survos\FolioBundle\Service\FolioDocumentStream;
use Survos\FolioBundle\Service\FolioDocumentStreamReport;
use Survos\FolioBundle\Set\ItemCriteria;
use Survos\HarvestBundle\Catalog\HarvestCatalog;

/** Local, filtered item documents for application-owned indexes and imports. */
final readonly class SetItemSource
{
    /** @param array<string,array> $sets Normalized survos_harvest.folio_sets configuration. */
    public function __construct(
        private HarvestCatalog $catalog,
        private FolioDocumentStream $stream,
        private array $sets,
    ) {
    }

    /**
     * Uses recorded membership; never syncs, fetches, or downloads.
     * Missing/unreadable local files are reported through $report, like FolioDocumentStream.
     * A document retains rowId, folioCode, coreCode and localId for source identity.
     *
     * @param list<string>|null $keep content fields to project after filtering; null keeps all
     *
     * @return \Generator<array<string,mixed>>
     */
    public function documents(
        string $setCode,
        ?array $keep = null,
        ?string $locale = null,
        ?FolioDocumentStreamReport $report = null,
    ): \Generator {
        $set = $this->sets[$setCode] ?? throw new \InvalidArgumentException(sprintf('Unknown Harvest folio set "%s".', $setCode));
        $criteria = $set['item_criteria'];
        $codes = [];
        foreach ($this->catalog->members($setCode) as $entry) {
            $codes[$entry->datasetKey] = $entry->datasetKey;
        }

        yield from $this->stream->documents(
            array_values($codes),
            core: $set['core'],
            keep: $keep,
            openLocale: $locale,
            report: $report,
            itemCriteria: new ItemCriteria($criteria['minImages'], $criteria['tags']),
        );
    }
}
