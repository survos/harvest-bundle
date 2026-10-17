<?php

declare(strict_types=1);

namespace Survos\HarvestBundle\Sync;

use Survos\FolioBundle\Catalog\DatasetField as Field;
use Survos\FolioBundle\Catalog\DatasetPublicationClient;
use Survos\HarvestBundle\Catalog\HarvestCatalog;
use Survos\HarvestBundle\Event\FolioAcquiredEvent;
use Survos\HarvestBundle\Event\HarvestSyncedEvent;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Lock\LockFactory;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final readonly class HarvestSync
{
    public function __construct(
        private DatasetPublicationClient $client,
        private HarvestCatalog $catalog,
        private HarvestSelection $selection,
        private FolioAcquisition $acquisition,
        private LockFactory $locks,
        private ?EventDispatcherInterface $events = null,
    ) {
    }

    #[AsCommand('harvest:sync', 'Synchronize Harvest metadata and selected folios into this app')]
    public function sync(
        SymfonyStyle $io,
        #[Option('Preview remote changes and selection without writes or downloads')] bool $dry = false,
        #[Option('Fetch the complete Harvest catalog and replace this app\'s copy of it (purges the harvest_* tables first, in the same transaction)')] bool $full = false,
        #[Option('Download at most this many new or changed folios this run; the rest stay pending for the next run')] ?int $limit = null,
    ): int {
        if (null !== $limit && $limit < 0) {
            throw new \InvalidArgumentException('--limit must be zero or greater.');
        }
        $lock = $this->locks->createLock($this->catalog->lockKey(), ttl: null);
        try {
            // Dry runs neither acquire a lock nor create a lock table/file.
            if (!$dry && !$lock->acquire()) {
                throw new \RuntimeException('Another Harvest sync is running.');
            }
            $ready = $this->catalog->schemaReady();
            if (!$ready && !$dry) {
                throw new \RuntimeException('Harvest tables are missing. Generate and run the application Doctrine migration first.');
            }
            if (!$ready) {
                $io->note('Harvest tables are not installed; previewing an initial full sync. No schema changes will be made.');
            }
            $saved = $ready ? $this->catalog->checkpoint() : null;
            $previous = $ready ? $this->catalog->acquiredRevisions() : [];
            $plan = $this->selection->plan($this->client->changes($saved, $full), $this->client);
            $io->definitionList(['Source' => $this->client->source()], ['Datasets in catalog' => count($plan->checkpoint[Field::ITEMS])],
                ['Selected folio variants' => count($plan->entries)], ['Cursor' => $plan->checkpoint[Field::CURSOR]]);
            foreach ($plan->membership as $code => $members) {
                $io->text(sprintf('Set %s: %d datasets', $code, count($members)));
            }
            $rows = $acquired = $fresh = [];
            $downloaded = 0;
            foreach ($plan->entries as $id => $entry) {
                $changed = ($previous[$id] ?? null) !== $entry->revision;
                $action = !$plan->downloads[$id] ? 'track' : (null === $entry->downloadUrl || !$entry->compressed ? 'check local' : ($changed ? 'acquire' : 'verify local'));
                // A limit only holds back real downloads; verifying a file already on disk is cheap.
                // A deferred folio is simply not recorded as acquired, so the next run picks it up.
                if ('acquire' === $action) {
                    if (null !== $limit && $downloaded >= $limit) {
                        $action = 'deferred';
                    } else {
                        ++$downloaded;
                    }
                }
                $rows[] = [$entry->datasetKey, $entry->artifactCode(), $entry->rowCount, $action];
                if (!$dry && 'deferred' !== $action && $plan->downloads[$id] && $this->acquisition->acquire($entry, $changed, $io)) {
                    $acquired[$id] = $entry->revision;
                    if ($changed) {
                        $fresh[$id] = true;
                    }
                }
            }
            $io->table(['Dataset', 'Variant', 'Rows', 'Action'], $rows);
            if ($dry) {
                $io->success('Dry run complete. No database, checkpoint, catalog cache or folio files changed.');
            } else {
                $this->catalog->commit($plan, $acquired, function () use ($io, $plan, $full): void {
                    $this->events?->dispatch(new HarvestSyncedEvent($io, $plan, $full));
                }, purge: $full);
                $io->success('Harvest catalog, set membership and checkpoint committed to the application database.');
                // After the commit, so a listener's failure cannot undo the checkpoint.
                foreach ($fresh as $id => $_) {
                    try {
                        $this->events?->dispatch(new FolioAcquiredEvent($plan->entries[$id]));
                    } catch (\Throwable $e) {
                        $io->warning(sprintf('%s: after-acquire listener failed: %s', $plan->entries[$id]->datasetKey, $e->getMessage()));
                    }
                }
            }

            return Command::SUCCESS;
        } catch (\Throwable $error) {
            $io->error('Harvest sync incomplete; checkpoint unchanged. '.$error->getMessage());

            return Command::FAILURE;
        } finally {
            if (!$dry) {
                $lock->release();
            }
        }
    }

    #[AsCommand('harvest:sync:status', 'Show the application\'s last successful Harvest sync')]
    public function status(SymfonyStyle $io): int
    {
        $state = $this->catalog->schemaReady() ? $this->catalog->checkpoint() : null;
        if (null === $state) {
            $io->note('No completed Harvest sync.');
        } else {
            $io->definitionList(['Source' => $state[Field::SOURCE]], ['Cursor' => $state[Field::CURSOR]],
                ['Last successful sync' => $state[Field::LAST_SUCCESSFUL_SYNC_AT]]);
        }

        return Command::SUCCESS;
    }
}
