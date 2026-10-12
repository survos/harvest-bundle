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

    #[ORM\Column(type: Types::JSON)]
    public array $payload;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    public \DateTimeImmutable $syncedAt;

    public function __construct(
        #[ORM\Id, ORM\Column(length: 255)] public readonly string $datasetKey,
    ) {}
}
