<?php

declare(strict_types=1);

namespace Survos\HarvestBundle\Admin;

use Survos\FolioBundle\Catalog\DatasetPublicationClient;
use Survos\FolioBundle\Catalog\FolioCatalogEntry;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/** Basic search over the authenticated API catalog; requires no Harvest browser login. */
final readonly class HarvestSearch
{
    public function __construct(private DatasetPublicationClient $client, private CacheInterface $cache)
    {
    }

    public function search(string $query, string $provider, int $page, bool $refresh = false): array
    {
        $key = 'harvest.admin.catalog.'.hash('sha256', $this->client->source());
        if ($refresh) {
            $this->cache->delete($key);
        }
        $snapshot = $this->cache->get($key, function (ItemInterface $item): array {
            $item->expiresAfter(300);

            return ['datasets' => $this->client->datasets(), 'fetchedAt' => time()];
        });
        $matches = $providers = [];
        $needle = mb_strtolower($query);
        foreach ($snapshot['datasets'] as $dataset) {
            foreach (FolioCatalogEntry::fromDataset($dataset, $this->client) as $entry) {
                $providers[$entry->provider] = true;
                if ('' !== $provider && $entry->provider !== $provider) {
                    continue;
                }
                $text = implode(' ', [$entry->datasetKey, $entry->title, $entry->description, $entry->provider, ...$entry->tags]);
                if ('' !== $needle && !str_contains(mb_strtolower($text), $needle)) {
                    continue;
                }
                $matches[] = $entry;
            }
        }
        usort($matches, static fn (FolioCatalogEntry $a, FolioCatalogEntry $b): int => strcasecmp($a->title, $b->title));
        $providers = array_keys($providers);
        sort($providers);
        $total = count($matches);
        $pages = max(1, (int) ceil($total / CatalogBrowser::PAGE_SIZE));
        $page = min(max(1, $page), $pages);

        return ['items' => array_slice($matches, ($page - 1) * CatalogBrowser::PAGE_SIZE, CatalogBrowser::PAGE_SIZE),
            'total' => $total, 'pages' => $pages, 'page' => $page, 'providers' => $providers, 'fetchedAt' => $snapshot['fetchedAt']];
    }
}
