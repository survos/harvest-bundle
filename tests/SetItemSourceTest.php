<?php

declare(strict_types=1);

namespace Survos\HarvestBundle\Tests;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use Survos\DataContracts\Path\DataPaths;
use Survos\FolioBundle\Catalog\DatasetPublicationClient;
use Survos\FolioBundle\DBAL\FolioConnectionWrapper;
use Survos\FolioBundle\Service\FolioDocumentStream;
use Survos\FolioBundle\Service\FolioDocumentStreamReport;
use Survos\FolioBundle\Service\FolioMeiliDocumentBuilder;
use Survos\FolioBundle\Service\FolioSchemaManager;
use Survos\FolioBundle\Service\FolioService;
use Survos\FolioBundle\Set\ItemCriteria;
use Survos\HarvestBundle\Catalog\HarvestCatalog;
use Survos\HarvestBundle\Entity\Dataset;
use Survos\HarvestBundle\Entity\SetMembership;
use Survos\HarvestBundle\Set\SetItemSource;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;

final class SetItemSourceTest extends TestCase
{
    private string $root;
    private EntityManager $em;
    private EntityManager $folioEm;
    private FolioDocumentStream $stream;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/set-items-'.bin2hex(random_bytes(5));
        $paths = new DataPaths($this->root);
        $path = $paths->folioFile('test/photos', 'folio', true);
        $db = new \PDO('sqlite:'.$path);
        $db->exec('CREATE TABLE core (id TEXT PRIMARY KEY, code TEXT);
            CREATE TABLE item (id TEXT PRIMARY KEY, core_id TEXT, local_id TEXT, label TEXT, dto_type TEXT, dto_data TEXT, extras TEXT);
            CREATE TABLE page (row_id TEXT, seq INTEGER, url TEXT, type TEXT);
            INSERT INTO core VALUES ("objects","obj"),("people","per");');
        foreach ([
            ['a', 'objects', ['tags' => ['ai:describe'], 'title' => 'A'], ['source_tags' => ['legacy']]],
            ['b', 'objects', ['tags' => ['other'], 'title' => 'B'], []],
            ['c', 'objects', ['title' => 'C'], ['source_tags' => ['ai:describe']]],
            ['d', 'objects', ['tags' => ['ai:describe'], 'imageCount' => 99], []],
            ['e', 'people', ['tags' => ['ai:describe']], []],
        ] as [$id, $core, $data, $extras]) {
            $db->prepare('INSERT INTO item VALUES (?, ?, ?, ?, ?, ?, ?)')->execute(
                ['test/photos:'.$core.':'.$id, $core, $id, $id, 'test', json_encode($data), json_encode($extras)],
            );
        }
        foreach ([
            ['a', 'objects', 'photo'], ['a', 'objects', 'scan'], ['a', 'objects', 'audio'],
            ['b', 'objects', 'photo'], ['c', 'objects', 'document'],
            ['d', 'objects', 'video'], ['e', 'people', 'photo'],
        ] as $i => [$id, $core, $type]) {
            $db->prepare('INSERT INTO page VALUES (?, ?, ?, ?)')->execute(['test/photos:'.$core.':'.$id, $i, 'https://example.test/'.$i, $type]);
        }
        $db = null;
        $config = ORMSetup::createAttributeMetadataConfiguration([dirname(__DIR__).'/src/Entity'], true);
        $config->setNamingStrategy(new UnderscoreNamingStrategy());
        $config->enableNativeLazyObjects(true);
        $this->em = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]), $config);
        (new SchemaTool($this->em))->createSchema([$this->em->getClassMetadata(Dataset::class), $this->em->getClassMetadata(SetMembership::class)]);
        $this->folioEm = new EntityManager(DriverManager::getConnection([
            'driver' => 'pdo_sqlite', 'path' => $path, 'wrapperClass' => FolioConnectionWrapper::class,
        ]), $config);
        $folios = new FolioService($this->folioEm, new FolioSchemaManager(new ArrayAdapter()), $paths, readOnly: true);
        $this->stream = new FolioDocumentStream($folios, new FolioMeiliDocumentBuilder());
    }

    protected function tearDown(): void
    {
        $this->em->close();
        $this->folioEm->getConnection()->close();
        $this->folioEm->close();
        (new Filesystem())->remove($this->root);
    }

    public function testImageAndTagFiltersIntersectBeforeProjection(): void
    {
        $report = new FolioDocumentStreamReport();
        $docs = iterator_to_array($this->stream->documents(['test/photos'], keep: ['title'], report: $report,
            itemCriteria: new ItemCriteria(1, ['absent', 'ai:describe'])));
        self::assertSame(['a', 'c'], array_column($docs, 'localId'));
        self::assertArrayNotHasKey('tags', $docs[0]);
        self::assertSame('test/photos:objects:a', $docs[0]['rowId']);
        self::assertSame(2, $report->count);
        self::assertSame([], $report->failed);
        $two = iterator_to_array($this->stream->documents(['test/photos'], itemCriteria: new ItemCriteria(2)));
        self::assertSame(['a'], array_column($two, 'localId'));
        $unfiltered = iterator_to_array($this->stream->documents(['test/photos']));
        self::assertCount(4, $unfiltered);
        self::assertSame(['ai:describe'], $unfiltered[0]['tags'], 'Legacy source tags must not overwrite canonical AI tags.');
        self::assertSame([], iterator_to_array($this->stream->documents(['test/photos'], itemCriteria: new ItemCriteria(tags: ['AI:DESCRIBE']))));
    }

    public function testNamedSetUsesLocalMembershipAndReportsMissingFilesWithoutHttp(): void
    {
        $http = new MockHttpClient(static function (): never { throw new \LogicException('A set read must not fetch.'); });
        foreach (['test/photos', 'test/missing'] as $key) {
            $dataset = new Dataset($key);
            $dataset->source = 'test';
            $dataset->provider = 'test';
            $dataset->syncedAt = new \DateTimeImmutable();
            $dataset->payload = [
                'datasetKey' => $key, 'provider' => 'test', 'label' => $key, 'description' => null,
                'tags' => ['catalog-only'], 'contentType' => null, 'rowCount' => 4,
                'artifacts' => array_map(static fn (string $code): array => [
                    'type' => 'folio', 'code' => $code, 'downloadUrl' => null, 'sizeBytes' => 0,
                    'checksum' => null, 'updatedAt' => null, 'compressed' => false, 'revision' => '1',
                ], ['default', 'en']),
            ];
            $this->em->persist($dataset);
            $this->em->persist(new SetMembership('described', $dataset, false));
        }
        $this->em->flush();
        $source = new SetItemSource(new HarvestCatalog($this->em, new DatasetPublicationClient($http, 'https://example.test', '')),
            $this->stream, ['described' => ['core' => 'obj', 'item_criteria' => ['minImages' => 1, 'tags' => ['ai:describe']]]]);
        $report = new FolioDocumentStreamReport();
        $docs = iterator_to_array($source->documents('described', report: $report));
        self::assertSame(['a', 'c'], array_column($docs, 'localId'), 'Locale artifacts must not duplicate source items.');
        self::assertArrayHasKey('test/missing', $report->failed);
        self::assertSame(0, $http->getRequestsCount());
        $this->expectException(\InvalidArgumentException::class);
        iterator_to_array($source->documents('typo'));
    }

    public function testLegacyFolioWithoutPagesHasNoImages(): void
    {
        $this->folioEm->getConnection()->executeStatement('DROP TABLE page');
        self::assertCount(4, iterator_to_array($this->stream->documents(['test/photos'])));
        self::assertSame([], iterator_to_array($this->stream->documents(['test/photos'], itemCriteria: new ItemCriteria(1))));
    }
}
