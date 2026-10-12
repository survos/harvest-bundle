<?php

declare(strict_types=1);

namespace Survos\HarvestBundle\Sync;

use Survos\FolioBundle\Catalog\DatasetField as Field;
use Survos\FolioBundle\Catalog\DatasetPublicationClient;
use Survos\HarvestBundle\Catalog\HarvestCatalog;
use Survos\HarvestBundle\Event\HarvestSyncedEvent;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Lock\LockFactory;

final readonly class HarvestSync
{
    public function __construct(
        private DatasetPublicationClient $client,
        private HarvestCatalog $catalog,
        private HarvestSelection $selection,
        private FolioAcquisition $acquisition,
        private LockFactory $locks,
        private ?EventDispatcherInterface $events = null,
    ) {}

    #[AsCommand('harvest:sync', 'Synchronize Harvest metadata and selected folios into this app')]
    public function sync(
        SymfonyStyle $io,
        #[Option('Preview remote changes and selection without writes or downloads')] bool $dry = false,
        #[Option('Fetch the complete Harvest catalog before replaying changes')] bool $full = false,
    ): int {
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
            $rows = $acquired = [];
            foreach ($plan->entries as $id => $entry) {
                $changed = ($previous[$id] ?? null) !== $entry->revision;
                $action = !$plan->downloads[$id] ? 'track' : ($entry->downloadUrl === null || !$entry->compressed ? 'check local' : ($changed ? 'acquire' : 'verify local'));
                $rows[] = [$entry->datasetKey, $entry->artifactCode(), $entry->rowCount, $action];
                if (!$dry && $plan->downloads[$id] && $this->acquisition->acquire($entry, $changed, $io)) {
                    $acquired[$id] = $entry->revision;
                }
            }
            $io->table(['Dataset', 'Variant', 'Rows', 'Action'], $rows);
            if ($dry) {
                $io->success('Dry run complete. No database, checkpoint, catalog cache or folio files changed.');
            } else {
                $this->catalog->commit($plan, $acquired, function () use ($io, $plan): void {
                    $this->events?->dispatch(new HarvestSyncedEvent($io, $plan));
                });
                $io->success('Harvest catalog, set membership and checkpoint committed to the application database.');
            }

            return Command::SUCCESS;
        } catch (\Throwable $error) {
            $io->error('Harvest sync incomplete; checkpoint unchanged. '.$error->getMessage());

            return Command::FAILURE;
        } finally {
            if (!$dry) { $lock->release(); }
        }
    }

    #[AsCommand('harvest:sync:status', 'Show the application\'s last successful Harvest sync')]
    public function status(SymfonyStyle $io): int
    {
        $state = $this->catalog->schemaReady() ? $this->catalog->checkpoint() : null;
        if ($state === null) {
            $io->note('No completed Harvest sync.');
        } else {
            $io->definitionList(['Source' => $state[Field::SOURCE]], ['Cursor' => $state[Field::CURSOR]],
                ['Last successful sync' => $state[Field::LAST_SUCCESSFUL_SYNC_AT]]);
        }

        return Command::SUCCESS;
    }
}
