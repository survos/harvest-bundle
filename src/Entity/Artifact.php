<?php

declare(strict_types=1);

namespace Survos\HarvestBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Remote availability and local acquisition are independent facts. */
#[ORM\Entity]
#[ORM\Table(name: 'harvest_artifact')]
#[ORM\UniqueConstraint(columns: ['dataset_key', 'type', 'code'])]
final class Artifact
{
    #[ORM\Id, ORM\Column(length: 64)]
    public readonly string $id;

    #[ORM\Column(type: Types::TEXT)]
    public string $revision;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $acquiredRevision = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $downloadUrl = null;

    #[ORM\Column(type: Types::JSON)]
    public array $payload;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Dataset::class)]
        #[ORM\JoinColumn(name: 'dataset_key', referencedColumnName: 'dataset_key', nullable: false, onDelete: 'CASCADE')]
        public readonly Dataset $dataset,
        #[ORM\Column(length: 32)] public readonly string $type,
        #[ORM\Column(length: 128)] public readonly string $code,
    ) {
        $this->id = self::identity($dataset->datasetKey, $type, $code);
    }

    public static function identity(string $datasetKey, string $type, string $code): string
    {
        return hash('sha256', json_encode([$datasetKey, $type, $code], JSON_THROW_ON_ERROR));
    }
}
