<?php

declare(strict_types=1);

namespace Survos\HarvestBundle\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Survos\HarvestBundle\Entity\Artifact;
use Survos\HarvestBundle\Entity\Dataset;
use Survos\HarvestBundle\Entity\SetMembership;

/** Paged administration queries against the application's database only. */
final readonly class CatalogBrowser
{
    public const PAGE_SIZE = 25;

    public function __construct(private EntityManagerInterface $em) {}

    public function page(string $query, string $setCode, int $page): array
    {
        $builder = $this->em->createQueryBuilder()->from(Dataset::class, 'd');
        if ($query !== '') {
            // Bound parameters protect SQL; escape LIKE wildcards for literal text search.
            $builder->andWhere("LOWER(d.datasetKey) LIKE :q ESCAPE '!' OR LOWER(d.label) LIKE :q ESCAPE '!' OR LOWER(d.provider) LIKE :q ESCAPE '!'")
                ->setParameter('q', '%'.strtr(mb_strtolower($query), ['!' => '!!', '%' => '!%', '_' => '!_']).'%');
        }
        if ($setCode !== '') {
            $builder->innerJoin(SetMembership::class, 'm', 'WITH', 'm.dataset = d')->andWhere('m.setCode = :set')->setParameter('set', $setCode);
        }
        $total = (int) (clone $builder)->select('COUNT(d.datasetKey)')->getQuery()->getSingleScalarResult();
        $pages = max(1, (int) ceil($total / self::PAGE_SIZE));
        $page = min(max(1, $page), $pages);
        $items = $builder->select('d')->orderBy('d.datasetKey', 'ASC')->setFirstResult(($page - 1) * self::PAGE_SIZE)->setMaxResults(self::PAGE_SIZE)->getQuery()->getResult();

        return ['items' => $items, 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    public function summary(): array
    {
        $counts = [];
        foreach ($this->em->createQueryBuilder()->select('m.setCode, COUNT(m.id) AS members')->from(SetMembership::class, 'm')->groupBy('m.setCode')->getQuery()->getArrayResult() as $row) {
            $counts[$row['setCode']] = (int) $row['members'];
        }

        return [
            'datasets' => $this->em->getRepository(Dataset::class)->count([]),
            'artifacts' => $this->em->getRepository(Artifact::class)->count([]),
            'acquired' => (int) $this->em->createQueryBuilder()->select('COUNT(a.id)')->from(Artifact::class, 'a')->where('a.acquiredRevision IS NOT NULL')->getQuery()->getSingleScalarResult(),
            'sets' => $counts,
        ];
    }

    public function dataset(string $key): ?Dataset { return $this->em->find(Dataset::class, $key); }

    public function artifacts(Dataset $dataset): array
    {
        return $this->em->getRepository(Artifact::class)->findBy(['dataset' => $dataset], ['type' => 'ASC', 'code' => 'ASC']);
    }
}
