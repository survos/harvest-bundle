<?php

declare(strict_types=1);

namespace Survos\HarvestBundle\Catalog;

use Doctrine\ORM\EntityManagerInterface;
use Survos\DataContracts\Metadata\PropertyKey;
use Survos\DataContracts\Vocabulary\ItemField;
use Survos\FolioBundle\Catalog\DatasetField as Field;
use Survos\FolioBundle\Catalog\DatasetPublicationClient;
use Survos\FolioBundle\Catalog\FolioCatalogEntry;
use Survos\HarvestBundle\Entity\Artifact;
use Survos\HarvestBundle\Entity\Dataset;
use Survos\HarvestBundle\Entity\HarvestSyncCheckpoint;
use Survos\HarvestBundle\Entity\SetMembership;
use Survos\HarvestBundle\Sync\SyncPlan;

/** Queries only the consuming application's database; never Harvest's database. */
final readonly class HarvestCatalog
{
    public function __construct(private EntityManagerInterface $em, private DatasetPublicationClient $client) {}

    public function schemaReady(): bool
    {
        $tables = array_map(fn (string $class): string => $this->em->getClassMetadata($class)->getTableName(),
            [Dataset::class, Artifact::class, SetMembership::class, HarvestSyncCheckpoint::class]);

        return $this->em->getConnection()->createSchemaManager()->tablesExist($tables);
    }

    /**
     * The sync lock's key: one per application database, since the lock guards this catalog and
     * checkpoint, not Harvest. A fixed name made every consumer on one machine (flock in the shared
     * temp dir) exclude every other; instances of one app share a database and so still share it.
     */
    public function lockKey(): string
    {
        $params = $this->em->getConnection()->getParams();
        $identity = array_intersect_key($params, array_flip(['driver', 'host', 'port', 'dbname', 'path', 'url', 'memory', 'unix_socket']));
        ksort($identity);

        return 'survos_harvest.sync.'.hash('xxh128', serialize($identity));
    }

    public function checkpoint(): ?array
    {
        $row = $this->em->find(HarvestSyncCheckpoint::class, 'harvest');
        if ($row === null) {
            return null;
        }
        $this->em->refresh($row);

        return $row->checkpoint;
    }

    /** @return array<string, string> */
    public function acquiredRevisions(?string $source = null): array
    {
        $source ??= $this->client->source();
        $revisions = [];
        foreach ($this->em->getRepository(Artifact::class)->findAll() as $artifact) {
            $this->em->refresh($artifact);
            if ($artifact->acquiredRevision !== null && $artifact->dataset->source === $source) {
                $revisions[$artifact->id] = $artifact->acquiredRevision;
            }
        }

        return $revisions;
    }

    /** @return list<FolioCatalogEntry> Recorded membership, usable without any Harvest HTTP call. */
    public function members(string $setCode): array
    {
        $entries = [];
        foreach ($this->em->getRepository(SetMembership::class)->findBy(['setCode' => $setCode]) as $member) {
            foreach (FolioCatalogEntry::fromDataset($member->dataset->payload, $this->client) as $entry) {
                $entries[] = $entry;
            }
        }
        usort($entries, static fn (FolioCatalogEntry $a, FolioCatalogEntry $b): int => strcasecmp($a->title, $b->title));

        return $entries;
    }

    public function find(string $key): ?FolioCatalogEntry
    {
        $dataset = $this->em->find(Dataset::class, $key);
        if ($dataset === null) { return null; }
        foreach (FolioCatalogEntry::fromDataset($dataset->payload, $this->client) as $entry) {
            if ($entry->locale === null) { return $entry; }
        }
        return null;
    }

    public function isStale(): bool
    {
        return !$this->schemaReady() || $this->checkpoint() === null;
    }

    /**
     * Persist metadata, membership, acquisition receipts and cursor in one transaction.
     *
     * $purge (harvest:sync --full) empties every table this catalog owns first, inside the same
     * transaction, so the result mirrors exactly one Harvest -- switching HARVEST_SERVER between
     * harvest.wip and harvest.survos.com leaves nothing of the other behind. Acquisition receipts
     * from the SAME source are carried over, so a full resync does not re-download unchanged folios;
     * a different source's receipts are dropped, as they are on any source change.
     */
    public function commit(SyncPlan $plan, array $acquired, ?\Closure $afterMembership = null, bool $purge = false): void
    {
        $this->em->wrapInTransaction(function () use ($plan, $acquired, $afterMembership, $purge): void {
            if ($purge) {
                $acquired += $this->acquiredRevisions($plan->checkpoint[Field::SOURCE]);
                foreach ([SetMembership::class, Artifact::class, Dataset::class, HarvestSyncCheckpoint::class] as $class) {
                    $this->em->createQuery(sprintf('DELETE FROM %s t', $class))->execute();
                }
                // DQL deletes bypass the identity map; forget the rows so find() below sees the purge.
                $this->em->clear();
            }
            $now = new \DateTimeImmutable();
            $datasets = $artifacts = $members = [];
            foreach ($plan->checkpoint[Field::ITEMS] as $key => $payload) {
                $dataset = $this->em->find(Dataset::class, $key) ?? new Dataset($key);
                if (isset($dataset->source)) {
                    $this->em->refresh($dataset);
                }
                $sourceChanged = isset($dataset->source) && $dataset->source !== $plan->checkpoint[Field::SOURCE];
                $dataset->source = $plan->checkpoint[Field::SOURCE];
                $dataset->provider = $payload[Field::PROVIDER];
                $dataset->label = $payload[PropertyKey::LABEL];
                $dataset->description = $payload[ItemField::DESCRIPTION];
                $dataset->payload = $payload;
                $dataset->syncedAt = $now;
                $this->em->persist($dataset);
                $datasets[$key] = $dataset;
                foreach ($payload[Field::ARTIFACTS] as $remote) {
                    $id = Artifact::identity($key, $remote[ItemField::TYPE], $remote[Field::CODE]);
                    $artifact = $this->em->find(Artifact::class, $id) ?? new Artifact($dataset, $remote[ItemField::TYPE], $remote[Field::CODE]);
                    $artifact->revision = $remote[Field::REVISION];
                    $artifact->downloadUrl = $remote[Field::DOWNLOAD_URL] === null ? null : $this->client->downloadUrl($remote[Field::DOWNLOAD_URL]);
                    $artifact->payload = $remote;
                    if ($sourceChanged) {
                        $artifact->acquiredRevision = null;
                    }
                    if (isset($acquired[$id])) {
                        $artifact->acquiredRevision = $acquired[$id];
                    }
                    $this->em->persist($artifact);
                    $artifacts[$id] = true;
                }
            }
            foreach ($plan->membership as $setCode => $keys) {
                foreach ($keys as $key => $download) {
                    $id = SetMembership::identity($setCode, $key);
                    $member = $this->em->find(SetMembership::class, $id) ?? new SetMembership($setCode, $datasets[$key], $download);
                    $member->download = $download;
                    $this->em->persist($member);
                    $members[$id] = true;
                }
            }
            foreach ($this->em->getRepository(SetMembership::class)->findAll() as $row) {
                if (!isset($members[$row->id])) { $this->em->remove($row); }
            }
            foreach ($this->em->getRepository(Artifact::class)->findAll() as $row) {
                if (!isset($artifacts[$row->id])) { $this->em->remove($row); }
            }
            foreach ($this->em->getRepository(Dataset::class)->findAll() as $row) {
                if (!isset($datasets[$row->datasetKey])) { $this->em->remove($row); }
            }
            $this->em->flush();
            $afterMembership?->__invoke();
            $checkpoint = $this->em->find(HarvestSyncCheckpoint::class, 'harvest') ?? new HarvestSyncCheckpoint();
            $checkpoint->checkpoint = $plan->checkpoint;
            $checkpoint->checkpoint[Field::LAST_SUCCESSFUL_SYNC_AT] = $now->format(DATE_ATOM);
            $this->em->persist($checkpoint);
        });
    }
}
