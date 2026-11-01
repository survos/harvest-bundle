<?php

declare(strict_types=1);

namespace Survos\HarvestBundle\Catalog;

use Survos\FolioBundle\Service\DuckDbFolioConnection;
use Survos\FolioBundle\Service\DuckDbFolioLocatorInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Remote reads use only the consumer's synchronized metadata; never producer paths. */
final readonly class HarvestDuckDbFolioLocator implements DuckDbFolioLocatorInterface
{
    public function __construct(private HarvestCatalog $catalog, private RequestStack $requests,
        private string $defaultLocale)
    {
    }

    public function locate(string $folioCode): string
    {
        DuckDbFolioConnection::validate($folioCode);
        $snapshots = $this->catalog->remoteSnapshots($folioCode);
        if ([] === $snapshots) {
            throw new NotFoundHttpException('No published remote DuckDB snapshot. Run harvest:sync after publication.');
        }
        $request = $this->requests->getMainRequest();
        $session = $request?->hasSession() ? $request->getSession() : null;
        $sessionKey = 'dfolio_language.'.$folioCode;
        $locale = $request?->query->getString('language') ?: ($session?->get($sessionKey) ?? $this->defaultLocale);
        if (!isset($snapshots[$locale])) {
            throw new NotFoundHttpException('Language not published for this dataset: '.$locale);
        }
        $session?->set($sessionKey, $locale);

        return $snapshots[$locale]->uri;
    }
}
