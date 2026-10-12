<?php

declare(strict_types=1);

namespace Survos\HarvestBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** A consumer's local copy of Harvest's public dataset metadata. */
#[ORM\Entity]
#[ORM\Table(name: 'harvest_dataset')]
final class Dataset
{
    #[ORM\Column(length: 1024)]
    public string $source;

    #[ORM\Column(length: 128)]
    public string $provider;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $label = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $description = null;

    /**
     * Facts copied out of $payload into real columns so an application can sort, facet and
     * range-filter its catalog in DQL (zm's collection search) without keeping its own copy.
     */
    #[ORM\Column(name: 'row_count', nullable: true)]
    public ?int $rowCount = null;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON, options: ['default' => '[]'])]
    public array $tags = [];

    #[ORM\Column(name: 'content_type', length: 64, nullable: true)]
    public ?string $contentType = null;

    /** @var list<string> locales with a published translated folio variant (the source build is not listed) */
    #[ORM\Column(type: Types::JSON, options: ['default' => '[]'])]
    public array $locales = [];

    #[ORM\Column(type: Types::JSON)]
    public array $payload;

    #[ORM\Column(name: 'synced_at', type: Types::DATETIME_IMMUTABLE)]
    public \DateTimeImmutable $syncedAt;

    public function __construct(
        #[ORM\Id, ORM\Column(name: 'dataset_key', length: 255)] public readonly string $datasetKey,
    ) {}
}
