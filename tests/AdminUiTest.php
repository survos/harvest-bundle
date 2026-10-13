<?php

declare(strict_types=1);

namespace Survos\HarvestBundle\Tests;

use PHPUnit\Framework\TestCase;
use Survos\DataContracts\Metadata\PropertyKey;
use Survos\DataContracts\Vocabulary\ItemField;
use Survos\FolioBundle\Catalog\DatasetField as Field;
use Survos\HarvestBundle\Tests\Fixture\AdminKernel;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Security\Core\User\InMemoryUser;

final class AdminUiTest extends TestCase
{
    private AdminKernel $kernel;
    private KernelBrowser $browser;

    protected function setUp(): void
    {
        $this->kernel = new AdminKernel('test', true);
        $this->kernel->boot();
        $this->kernel->seed(self::dataset());
        $this->browser = new KernelBrowser($this->kernel);
        $this->browser->disableReboot();
        $this->browser->catchExceptions(false);
    }

    protected function tearDown(): void
    {
        $this->kernel->shutdown();
    }

    public function testAdminCatalogSearchHelpAndProtectedSync(): void
    {
        $this->browser->loginUser(new InMemoryUser('admin', 'unused', ['ROLE_ADMIN']));
        $crawler = $this->browser->request('GET', '/admin/harvest/');
        self::assertSame(200, $this->browser->getResponse()->getStatusCode());
        self::assertStringContainsString('Baltimore Heritage', $crawler->text());
        self::assertStringNotContainsString('hidden-test-token', $this->browser->getResponse()->getContent());
        $form = $crawler->selectButton('Preview sync')->form();
        $this->browser->submit($form);
        self::assertSame(303, $this->browser->getResponse()->getStatusCode());
        $crawler = $this->browser->followRedirect();
        self::assertStringContainsString('Preview complete', $crawler->text());
        $this->browser->submit($crawler->selectButton('Sync now')->form());
        self::assertSame(303, $this->browser->getResponse()->getStatusCode());
        self::assertStringContainsString('Synchronization complete', $this->browser->followRedirect()->text());
        $crawler = $this->browser->request('GET', '/admin/harvest/search?q=baltimore');
        self::assertSame(200, $this->browser->getResponse()->getStatusCode());
        self::assertStringContainsString('Baltimore Heritage', $crawler->text());
        self::assertStringContainsString('Include this folio', $crawler->text());
        $crawler = $this->browser->request('GET', '/admin/harvest/help');
        self::assertSame(200, $this->browser->getResponse()->getStatusCode());
        self::assertStringContainsString('download: false', $crawler->text());
        $this->browser->request('GET', '/admin/harvest/dataset?key=curatescape%2Fbaltimore');
        self::assertSame(200, $this->browser->getResponse()->getStatusCode());
    }

    public function testLocalSearchAndNoResults(): void
    {
        $this->browser->loginUser(new InMemoryUser('admin', 'unused', ['ROLE_ADMIN']));
        $crawler = $this->browser->request('GET', '/admin/harvest/?q=baltimore&set=tours');
        self::assertSame(200, $this->browser->getResponse()->getStatusCode());
        self::assertStringContainsString('Baltimore Heritage', $crawler->text());
        $crawler = $this->browser->request('GET', '/admin/harvest/?q=does-not-exist');
        self::assertStringContainsString('No datasets match', $crawler->text());
    }

    public function testAnonymousAndNonAdminRequestsCannotReachTheCatalog(): void
    {
        $this->browser->catchExceptions(true);
        $this->browser->request('GET', '/admin/harvest/');
        self::assertSame(401, $this->browser->getResponse()->getStatusCode());
        $this->browser->loginUser(new InMemoryUser('reader', 'unused', ['ROLE_USER']));
        $this->browser->request('GET', '/admin/harvest/');
        self::assertSame(403, $this->browser->getResponse()->getStatusCode());
    }

    public function testSyncRejectsGetAndInvalidCsrf(): void
    {
        $this->browser->catchExceptions(true);
        $this->browser->loginUser(new InMemoryUser('admin', 'unused', ['ROLE_ADMIN']));
        $this->browser->request('GET', '/admin/harvest/sync');
        self::assertSame(405, $this->browser->getResponse()->getStatusCode());
        $this->browser->request('POST', '/admin/harvest/sync', ['_token' => 'invalid']);
        self::assertSame(403, $this->browser->getResponse()->getStatusCode());
    }

    public static function dataset(): array
    {
        return [Field::DATASET_KEY => 'curatescape/baltimore', PropertyKey::LABEL => 'Baltimore Heritage',
            ItemField::DESCRIPTION => 'Explore the places and stories that shaped Baltimore.', Field::PROVIDER => 'curatescape',
            PropertyKey::TAGS => ['tours', 'history'], PropertyKey::CONTENT_TYPE => null, PropertyKey::ROW_COUNT => 547,
            Field::ARTIFACTS => [[ItemField::TYPE => Field::FOLIO_ARCHIVE_TYPE, Field::CODE => Field::DEFAULT_CODE,
                Field::REVISION => 'r1', Field::CHECKSUM => null, Field::SIZE_BYTES => 120000, Field::UPDATED_AT => null,
                Field::COMPRESSED => true, Field::DOWNLOAD_URL => '/api/datasets/curatescape/baltimore/artifacts/1/download?revision=r1']]];
    }
}
