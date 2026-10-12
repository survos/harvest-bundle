<?php

declare(strict_types=1);

namespace Survos\HarvestBundle\Tests;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\ORM\Tools\SchemaValidator;
use PHPUnit\Framework\TestCase;
use Survos\DataContracts\Metadata\PropertyKey;
use Survos\DataContracts\Vocabulary\ItemField;
use Survos\FolioBundle\Catalog\DatasetField as Field;
use Survos\FolioBundle\Catalog\DatasetPublicationClient;
use Survos\FolioBundle\Catalog\FolioCatalogEntry;
use Survos\HarvestBundle\Catalog\HarvestCatalog;
use Survos\HarvestBundle\Entity\Artifact;
use Survos\HarvestBundle\Entity\Dataset;
use Survos\HarvestBundle\Entity\HarvestSyncCheckpoint;
use Survos\HarvestBundle\Entity\SetMembership;
use Survos\HarvestBundle\Sync\FolioAcquisition;
use Survos\HarvestBundle\Sync\HarvestSync;
use Survos\HarvestBundle\Sync\HarvestSelection;
use Survos\HarvestBundle\Sync\SyncPlan;
use Survos\HarvestBundle\Sync\Selection;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

final class HarvestSyncTest extends TestCase
{
    private EntityManager $em;
    private HarvestCatalog $catalog;
    private DatasetPublicationClient $client;
    private array $remote;
    private string $cursor = 'epoch:1';
    private array $requests = [];
    private MockHttpClient $http;

    protected function setUp(): void
    {
        $config = ORMSetup::createAttributeMetadataConfiguration([dirname(__DIR__).'/src/Entity'], true);
        $config->setNamingStrategy(new UnderscoreNamingStrategy());
        $config->enableNativeLazyObjects(true);
        $this->em = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]), $config);
        $this->remote = [self::dataset('curatescape/a'), self::dataset('museum/b')];
        $this->http = $http = new MockHttpClient(function (string $method, string $url): MockResponse {
            $this->requests[] = $url;
            $body = match (true) {
                str_contains($url, '/api/changes') => [Field::CURSOR => $this->cursor, Field::THROUGH => $this->cursor, Field::ITEMS => [], Field::HAS_MORE => false],
                str_ends_with($url, '/api/datasets') => [Field::ITEMS => $this->remote, Field::NEXT => null],
                default => throw new \LogicException('Unexpected HTTP call: '.$url),
            };
            return new MockResponse(json_encode($body, JSON_THROW_ON_ERROR));
        });
        $this->client = new DatasetPublicationClient($http, 'https://harvest.example', 'test-token');
        $this->catalog = new HarvestCatalog($this->em, $this->client);
    }

    protected function tearDown(): void { $this->em->close(); }

    public function testMappingsAndAtomicCatalogWithRecordedMembership(): void
    {
        $this->installSchema();
        self::assertSame([], (new SchemaValidator($this->em))->validateMapping());
        $acquire = $this->createMock(FolioAcquisition::class);
        $acquire->expects(self::once())->method('acquire')->willReturn(true);
        self::assertSame(0, $this->sync($acquire)->sync($this->io(), full: true));
        $this->em->clear();
        self::assertCount(2, $this->em->getRepository(Dataset::class)->findAll());
        self::assertCount(2, $this->em->getRepository(Artifact::class)->findAll());
        self::assertCount(1, $this->em->getRepository(SetMembership::class)->findAll());
        self::assertSame(['curatescape/a'], array_map(fn ($e) => $e->datasetKey, $this->catalog->members('tours')));
        self::assertSame('epoch:1', $this->catalog->checkpoint()[Field::CURSOR]);
        self::assertCount(1, $this->catalog->acquiredRevisions());
        self::assertCount(3, $this->requests, 'Reading membership makes no HTTP requests');
    }

    public function testMappingsDoNotDependOnApplicationNamingStrategy(): void
    {
        $config = ORMSetup::createAttributeMetadataConfiguration([dirname(__DIR__).'/src/Entity'], true);
        $config->enableNativeLazyObjects(true);
        $em = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]), $config);
        try {
            self::assertSame([], (new SchemaValidator($em))->validateMapping());
            (new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());
            self::assertTrue($em->getConnection()->createSchemaManager()->introspectTable('harvest_dataset')->hasColumn('dataset_key'));
        } finally {
            $em->close();
        }
    }

    public function testDryRunWithoutSchemaDoesNotCreateTablesOrDownload(): void
    {
        $acquire = $this->createMock(FolioAcquisition::class);
        $acquire->expects(self::never())->method('acquire');
        self::assertSame(0, $this->sync($acquire)->sync($this->io(), dry: true));
        self::assertSame([], $this->em->getConnection()->createSchemaManager()->listTableNames());
        self::assertCount(3, $this->requests);
    }

    public function testDryRunPreservesExistingRowsAndCheckpoint(): void
    {
        $this->installSchema();
        $acquire = $this->createMock(FolioAcquisition::class);
        $acquire->expects(self::once())->method('acquire')->willReturn(true);
        self::assertSame(0, $this->sync($acquire)->sync($this->io()));
        $before = $this->catalog->checkpoint();
        $this->remote = [];
        $this->cursor = 'epoch:2';
        $never = $this->createMock(FolioAcquisition::class);
        $never->expects(self::never())->method('acquire');
        self::assertSame(0, $this->sync($never)->sync($this->io(), dry: true, full: true));
        self::assertSame($before, $this->catalog->checkpoint());
        self::assertCount(2, $this->em->getRepository(Dataset::class)->findAll());
    }

    public function testFailedAcquisitionDoesNotAdvanceCatalogOrCheckpoint(): void
    {
        $this->installSchema();
        $acquire = $this->createMock(FolioAcquisition::class);
        $acquire->expects(self::once())->method('acquire')->willThrowException(new \RuntimeException('download failed'));
        self::assertSame(1, $this->sync($acquire)->sync($this->io()));
        self::assertNull($this->catalog->checkpoint());
        self::assertSame([], $this->em->getRepository(Dataset::class)->findAll());
    }

    public function testTrackOnlySetsAndRemoteRemoval(): void
    {
        $this->installSchema();
        $acquire = $this->createMock(FolioAcquisition::class);
        $acquire->expects(self::never())->method('acquire');
        $sync = $this->sync($acquire, download: false);
        self::assertSame(0, $sync->sync($this->io()));
        self::assertCount(1, $this->catalog->members('tours'));
        self::assertSame([], $this->catalog->acquiredRevisions());
        $this->remote = [];
        self::assertSame(0, $sync->sync($this->io(), full: true));
        $this->em->clear();
        self::assertSame([], $this->em->getRepository(Dataset::class)->findAll());
        self::assertSame([], $this->em->getRepository(Artifact::class)->findAll());
        self::assertSame([], $this->catalog->members('tours'));
    }

    public function testFullSyncAgainstAnotherHarvestLeavesNothingOfTheFirst(): void
    {
        $this->installSchema();
        $acquire = $this->createStub(FolioAcquisition::class);
        $acquire->method('acquire')->willReturn(true);
        self::assertSame(0, $this->sync($acquire)->sync($this->io(), full: true));
        self::assertCount(2, $this->em->getRepository(Dataset::class)->findAll());
        self::assertCount(1, $this->catalog->acquiredRevisions());

        // Point the same app at a different Harvest (harvest.wip -> harvest.survos.com).
        $this->client = new DatasetPublicationClient($this->http, 'https://harvest.other', 'test-token');
        $this->catalog = new HarvestCatalog($this->em, $this->client);
        $this->remote = [self::dataset('curatescape/c')];
        $never = $this->createStub(FolioAcquisition::class);
        $never->method('acquire')->willReturn(false);
        $events = new \Symfony\Component\EventDispatcher\EventDispatcher();
        $seen = null;
        $events->addListener(\Survos\HarvestBundle\Event\HarvestSyncedEvent::class,
            static function (\Survos\HarvestBundle\Event\HarvestSyncedEvent $event) use (&$seen): void { $seen = $event->full; });
        self::assertSame(0, $this->sync($never, events: $events)->sync($this->io(), full: true));
        self::assertTrue($seen, 'listeners are told this plan is the whole catalog');

        $this->em->clear();
        $datasets = $this->em->getRepository(Dataset::class)->findAll();
        self::assertSame(['curatescape/c'], array_map(static fn (Dataset $d): string => $d->datasetKey, $datasets));
        self::assertSame('https://harvest.other', $datasets[0]->source);
        self::assertCount(1, $this->em->getRepository(Artifact::class)->findAll());
        self::assertSame([], $this->catalog->acquiredRevisions(), 'the first Harvest\'s receipts must not survive');
        self::assertSame('https://harvest.other', $this->catalog->checkpoint()[Field::SOURCE]);
    }

    public function testRevisionChangesAndDeselectionPreserveAcquisitionHistory(): void
    {
        $this->installSchema();
        $acquire = $this->createMock(FolioAcquisition::class);
        $changes = [];
        $acquire->expects(self::exactly(3))->method('acquire')->willReturnCallback(
            static function (FolioCatalogEntry $entry, bool $changed) use (&$changes): bool {
                $changes[] = $changed;
                return true;
            },
        );
        $sync = $this->sync($acquire);
        self::assertSame(0, $sync->sync($this->io(), full: true));
        self::assertSame(0, $sync->sync($this->io(), full: true));
        $this->remote[0][Field::ARTIFACTS][0][Field::REVISION] = 'revision:2';
        self::assertSame(0, $sync->sync($this->io(), full: true));
        self::assertSame([true, false, true], $changes);
        self::assertSame(['revision:2'], array_values($this->catalog->acquiredRevisions()));

        $none = new HarvestSync($this->client, $this->catalog, new Selection([]),
            $this->createStub(FolioAcquisition::class), new LockFactory(new InMemoryStore()));
        self::assertSame(0, $none->sync($this->io(), full: true));
        self::assertSame([], $this->catalog->members('tours'));
        self::assertSame(['revision:2'], array_values($this->catalog->acquiredRevisions()));
    }

    public function testDatabaseFailureRollsBackMetadataAndCursorTogether(): void
    {
        $this->installSchema();
        $this->em->getConnection()->executeStatement(
            "CREATE TRIGGER reject_artifacts BEFORE INSERT ON harvest_artifact BEGIN SELECT RAISE(ABORT, 'test failure'); END",
        );
        self::assertSame(1, $this->sync($this->createStub(FolioAcquisition::class), download: false)->sync($this->io()));
        $db = $this->em->getConnection();
        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM harvest_dataset'));
        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM harvest_sync_checkpoint'));
    }

    public function testLockIsScopedToTheApplicationDatabase(): void
    {
        $catalogFor = function (array $params): HarvestCatalog {
            $config = ORMSetup::createAttributeMetadataConfiguration([dirname(__DIR__).'/src/Entity'], true);
            $config->enableNativeLazyObjects(true);
            return new HarvestCatalog(new EntityManager(DriverManager::getConnection($params), $config), $this->client);
        };
        $a = $catalogFor(['driver' => 'pdo_sqlite', 'path' => '/tmp/app-a.db']);
        self::assertSame($a->lockKey(), $catalogFor(['driver' => 'pdo_sqlite', 'path' => '/tmp/app-a.db'])->lockKey());
        self::assertNotSame($a->lockKey(), $catalogFor(['driver' => 'pdo_sqlite', 'path' => '/tmp/app-b.db'])->lockKey(),
            'Two apps on one machine must not exclude each other');
        self::assertStringNotContainsString('secret', $catalogFor(['driver' => 'pdo_sqlite', 'path' => '/tmp/app-a.db', 'password' => 'secret'])->lockKey());
    }

    public function testConcurrentSyncOfTheSameDatabaseIsRefused(): void
    {
        $this->installSchema();
        $locks = new LockFactory(new InMemoryStore());
        $held = $locks->createLock($this->catalog->lockKey());
        self::assertTrue($held->acquire());
        $sync = new HarvestSync($this->client, $this->catalog, new Selection([]), $this->createStub(FolioAcquisition::class), $locks);
        self::assertSame(1, $sync->sync($this->io()));
        self::assertSame([], $this->requests);
        $held->release();
    }

    public function testMissingSchemaFailsBeforeFetchingForRealSync(): void
    {
        self::assertSame(1, $this->sync($this->createStub(FolioAcquisition::class))->sync($this->io()));
        self::assertSame([], $this->requests);
    }

    public function testApplicationProjectionSeesMembershipAndFailureRollsBackEverything(): void
    {
        $this->installSchema();
        $events = new \Symfony\Component\EventDispatcher\EventDispatcher();
        $events->addListener(\Survos\HarvestBundle\Event\HarvestSyncedEvent::class, function (): void {
            self::assertCount(1, $this->catalog->members('tours'));
            self::assertSame('curatescape/a', $this->catalog->find('curatescape/a')->datasetKey);
            throw new \RuntimeException('Projection failed');
        });
        self::assertSame(1, $this->sync($this->createStub(FolioAcquisition::class), false, $events)->sync($this->io()));
        foreach (['harvest_dataset', 'harvest_artifact', 'harvest_set_membership', 'harvest_sync_checkpoint'] as $table) {
            self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM '.$table));
        }
    }

    public function testDryRunNeverDispatchesApplicationProjection(): void
    {
        $events = $this->createMock(\Symfony\Contracts\EventDispatcher\EventDispatcherInterface::class);
        $events->expects(self::never())->method('dispatch');
        self::assertSame(0, $this->sync($this->createStub(FolioAcquisition::class), false, $events)->sync($this->io(), dry: true));
    }

    public function testApplicationSelectionRunsAgainForEachSyncWithoutChangingRemoteCatalog(): void
    {
        $this->installSchema();
        $selection = $this->createMock(HarvestSelection::class);
        $selection->expects(self::exactly(2))->method('plan')->willReturnCallback(
            static fn (array $checkpoint, DatasetPublicationClient $client): SyncPlan => new SyncPlan($checkpoint, [], [], []),
        );
        $acquisition = $this->createMock(FolioAcquisition::class);
        $acquisition->expects(self::never())->method('acquire');
        $sync = new HarvestSync($this->client, $this->catalog, $selection, $acquisition, new LockFactory(new InMemoryStore()));
        self::assertSame(0, $sync->sync($this->io(), full: true));
        self::assertSame(0, $sync->sync($this->io(), full: true));
        self::assertCount(2, $this->em->getRepository(Dataset::class)->findAll());
        self::assertSame([], $this->catalog->acquiredRevisions());
    }

    private function sync(FolioAcquisition $acquire, bool $download = true, ?\Symfony\Contracts\EventDispatcher\EventDispatcherInterface $events = null): HarvestSync
    {
        $selection = new Selection(['tours' => ['download' => $download, 'criteria' => [
            Field::PROVIDER => ['curatescape'], PropertyKey::TAGS => [], 'tagsAll' => [],
            PropertyKey::CONTENT_TYPE => [], Field::FOLIOS => [], 'minRows' => 1,
        ]]]);
        return new HarvestSync($this->client, $this->catalog, $selection, $acquire, new LockFactory(new InMemoryStore()), $events);
    }

    private function installSchema(): void
    {
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
    }

    private function io(): SymfonyStyle { return new SymfonyStyle(new ArrayInput([]), new BufferedOutput()); }

    private static function dataset(string $key): array
    {
        return [Field::DATASET_KEY => $key, PropertyKey::LABEL => $key, ItemField::DESCRIPTION => null,
            Field::PROVIDER => explode('/', $key)[0], PropertyKey::TAGS => [], PropertyKey::CONTENT_TYPE => null,
            PropertyKey::ROW_COUNT => 2, Field::ARTIFACTS => [[ItemField::TYPE => Field::FOLIO_ARCHIVE_TYPE,
                Field::CODE => Field::DEFAULT_CODE, Field::REVISION => 'revision:1', Field::CHECKSUM => null,
                Field::SIZE_BYTES => 100, Field::UPDATED_AT => null, Field::COMPRESSED => true,
                Field::DOWNLOAD_URL => '/api/datasets/'.$key.'/artifacts/1/download?revision=revision:1',
            ]]];
    }
}
