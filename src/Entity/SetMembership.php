<?php

declare(strict_types=1);

namespace Survos\HarvestBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'harvest_set_membership')]
#[ORM\UniqueConstraint(columns: ['set_code', 'dataset_key'])]
final class SetMembership
{
    #[ORM\Id, ORM\Column(length: 64)]
    public readonly string $id;

    public function __construct(
        #[ORM\Column(name: 'set_code', length: 128)] public readonly string $setCode,
        #[ORM\ManyToOne(targetEntity: Dataset::class)]
        #[ORM\JoinColumn(name: 'dataset_key', referencedColumnName: 'dataset_key', nullable: false, onDelete: 'CASCADE')]
        public readonly Dataset $dataset,
        #[ORM\Column] public bool $download,
    ) {
        $this->id = self::identity($setCode, $dataset->datasetKey);
    }

    public static function identity(string $setCode, string $datasetKey): string
    {
        return hash('sha256', json_encode([$setCode, $datasetKey], JSON_THROW_ON_ERROR));
    }
}
