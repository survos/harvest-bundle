<?php

declare(strict_types=1);

namespace Survos\HarvestBundle\Service;

use Survos\HarvestBundle\Metadata\HarvestMetadata;

/** @deprecated Legacy provider metadata scaffold; use the persistent Harvest catalog entities. */
final class HarvestMetadataRegistry
{
    /** @var array<string,HarvestMetadata> */
    private array $byCode = [];

    /** @param iterable<HarvestMetadata> $all */
    public function __construct(iterable $all = [])
    {
        foreach ($all as $meta) {
            $this->byCode[$meta->code] = $meta;
        }
    }

    /** @return array<string,HarvestMetadata> */
    public function all(): array
    {
        return $this->byCode;
    }

    public function get(string $code): ?HarvestMetadata
    {
        return $this->byCode[$code] ?? null;
    }
}
