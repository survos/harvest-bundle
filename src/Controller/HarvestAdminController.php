<?php

declare(strict_types=1);

namespace Survos\HarvestBundle\Controller;

use Survos\FolioBundle\Catalog\DatasetField;
use Survos\FolioBundle\Catalog\DatasetPublicationClient;
use Survos\HarvestBundle\Admin\CatalogBrowser;
use Survos\HarvestBundle\Admin\HarvestSearch;
use Survos\HarvestBundle\Catalog\HarvestCatalog;
use Survos\HarvestBundle\Sync\HarvestSync;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Yaml\Yaml;

#[IsGranted('ROLE_ADMIN')]
final class HarvestAdminController extends AbstractController
{
    public const SYNC_TOKEN = 'harvest-admin-sync';

    public function __construct(
        private readonly HarvestCatalog $catalog,
        private readonly CatalogBrowser $browser,
        private readonly HarvestSearch $search,
        private readonly HarvestSync $sync,
        private readonly DatasetPublicationClient $client,
        private readonly array $sets,
        private readonly string $baseLayout,
    ) {
    }

    #[Route('', name: 'survos_harvest_admin', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $ready = $this->catalog->schemaReady();
        $query = mb_substr(trim($request->query->getString('q')), 0, 200);
        $set = $request->query->getString('set');
        if ('' !== $set && !array_key_exists($set, $this->sets)) {
            throw $this->createNotFoundException('Unknown folio set.');
        }

        return $this->view('index', [
            'ready' => $ready, 'query' => $query, 'selectedSet' => $set,
            'results' => $ready ? $this->browser->page($query, $set, $request->query->getInt('page', 1)) : null,
            'summary' => $ready ? $this->browser->summary() : null,
            'checkpoint' => $ready ? $this->catalog->checkpoint() : null,
        ]);
    }

    #[Route('/dataset', name: 'survos_harvest_dataset', methods: ['GET'])]
    public function dataset(Request $request): Response
    {
        if (!$this->catalog->schemaReady()) {
            return $this->redirectToRoute('survos_harvest_admin');
        }
        $dataset = $this->browser->dataset($request->query->getString('key')) ?? throw $this->createNotFoundException('Dataset is not in the local catalog.');

        return $this->view('dataset', ['dataset' => $dataset, 'artifacts' => $this->browser->artifacts($dataset)]);
    }

    #[Route('/sync', name: 'survos_harvest_sync', methods: ['POST'])]
    public function synchronize(Request $request): Response
    {
        if (!$this->isCsrfTokenValid(self::SYNC_TOKEN, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid sync token. Reload the catalog and try again.');
        }
        $output = new BufferedOutput(decorated: false);
        $input = new ArrayInput([]);
        $input->setInteractive(false);
        $dry = $request->request->getBoolean('dry');
        $code = $this->sync->sync(new SymfonyStyle($input, $output), dry: $dry, full: $request->request->getBoolean('full'));
        // Keep bounded plain text in the session; templates escape it, never render console HTML.
        $request->getSession()->set('survos_harvest.sync_result', [
            'success' => Command::SUCCESS === $code, 'dry' => $dry, 'output' => mb_substr($output->fetch(), -24000),
        ]);

        return $this->redirectToRoute('survos_harvest_admin', status: Response::HTTP_SEE_OTHER);
    }

    #[Route('/search', name: 'survos_harvest_search', methods: ['GET'])]
    public function search(Request $request): Response
    {
        $query = mb_substr(trim($request->query->getString('q')), 0, 200);
        $provider = mb_substr($request->query->getString('provider'), 0, 128);
        $results = null;
        $error = null;
        try {
            $results = $this->search->search($query, $provider, $request->query->getInt('page', 1), $request->query->getBoolean('refresh'));
        } catch (\Throwable) {
            // Remote exception text can contain deployment details. Never expose credentials.
            $error = 'Harvest could not be reached. Your local catalog is unchanged. Check the server and read token, then try again.';
        }

        return $this->view('search', ['query' => $query, 'provider' => $provider, 'results' => $results, 'error' => $error]);
    }

    #[Route('/help', name: 'survos_harvest_help', methods: ['GET'])]
    public function help(): Response
    {
        return $this->view('help', ['configuration' => Yaml::dump(['survos_harvest' => ['folio_sets' => $this->sets]], 6, 4)]);
    }

    private function view(string $template, array $values): Response
    {
        return $this->render('@SurvosHarvest/admin/'.$template.'.html.twig', $values + [
            'baseLayout' => $this->baseLayout, 'sets' => $this->sets, 'source' => $this->client->source(),
            'lastSyncField' => DatasetField::LAST_SUCCESSFUL_SYNC_AT,
        ]);
    }
}
