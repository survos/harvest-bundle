<?php

declare(strict_types=1);

namespace Survos\HarvestBundle\Catalog;

use Survos\FolioBundle\Service\FolioService;
use Survos\FolioBundle\Set\RecordedMembershipProvider;

/** Compatibility for folio presentation services, backed by the consumer database. */
final readonly class RecordedMembership implements RecordedMembershipProvider
{
    public function __construct(private HarvestCatalog $catalog, private FolioService $folios)
    {
    }

    public function members(string $code): array
    {
        $members = [];
        foreach ($this->catalog->members($code) as $entry) {
            if (null !== $entry->locale) {
                continue;
            }
            $path = $this->folios->path($entry->datasetKey);
            $local = is_file($path) ? $path : null;
            $members[] = ['datasetKey' => $entry->datasetKey, 'label' => $entry->title,
                'provider' => $entry->provider, 'tags' => $entry->tags, 'rowCount' => $entry->rowCount,
                'contentTypes' => null === $entry->contentType ? [] : [$entry->contentType],
                'inCatalog' => true, 'downloadUrl' => $entry->downloadUrl, 'local' => $local, 'available' => null !== $local];
        }

        return $members;
    }
}
