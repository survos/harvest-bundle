<?php

declare(strict_types=1);

namespace Survos\HarvestBundle\Sync;

use Survos\DataContracts\Metadata\PropertyKey;
use Survos\FolioBundle\Catalog\DatasetField as Field;
use Survos\FolioBundle\Catalog\DatasetPublicationClient;
use Survos\FolioBundle\Catalog\FolioCatalogEntry;
use Survos\FolioBundle\Set\FolioSetResolver;
use Survos\HarvestBundle\Entity\Artifact;

final readonly class Selection
{
    public function __construct(private array $sets) {}

    public function plan(array $checkpoint, DatasetPublicationClient $client): SyncPlan
    {
        $entries = $downloads = $membership = [];
        foreach ($this->sets as $setCode => $set) {
            $membership[$setCode] = [];
            $criteria = $set['criteria'];
            $named = $criteria[Field::FOLIOS];
            $selective = $criteria[PropertyKey::TAGS] !== [] || $criteria['tagsAll'] !== []
                || $criteria[Field::PROVIDER] !== [] || $criteria[PropertyKey::CONTENT_TYPE] !== [];
            foreach ($checkpoint[Field::ITEMS] as $dataset) {
                $key = $dataset[Field::DATASET_KEY];
                $candidate = [Field::PROVIDER => $dataset[Field::PROVIDER], PropertyKey::TAGS => $dataset[PropertyKey::TAGS],
                    PropertyKey::ROW_COUNT => $dataset[PropertyKey::ROW_COUNT], 'contentTypes' => [$dataset[PropertyKey::CONTENT_TYPE]]];
                if (!in_array($key, $named, true) && !($selective && FolioSetResolver::matches($criteria, $candidate))) {
                    continue;
                }
                $membership[$setCode][$key] = $set['download'];
                foreach (FolioCatalogEntry::fromDataset($dataset, $client) as $entry) {
                    $id = Artifact::identity($key, $entry->artifactType, $entry->artifactCode());
                    $entries[$id] = $entry;
                    $downloads[$id] = ($downloads[$id] ?? false) || $set['download'];
                }
            }
            $missing = array_diff($named, array_keys($checkpoint[Field::ITEMS]));
            if ($missing !== []) {
                throw new \UnexpectedValueException(sprintf('Set "%s" names datasets absent from Harvest: %s.', $setCode, implode(', ', $missing)));
            }
        }

        return new SyncPlan($checkpoint, $entries, $downloads, $membership);
    }
}
